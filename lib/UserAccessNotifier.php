<?php

declare(strict_types=1);

require_once __DIR__ . '/NotificationSender.php';
require_once __DIR__ . '/NotificationManager.php';
require_once __DIR__ . '/ClubHelper.php';

/**
 * Notifica a un usuario sus datos de acceso (WhatsApp, web push/campanita, Telegram).
 */
final class UserAccessNotifier
{
    private const ROLE_LABELS = [
        'admin_general' => 'Admin General',
        'admin_torneo' => 'Admin Torneo',
        'admin_club' => 'Admin Organización',
        'usuario' => 'Usuario',
        'operador' => 'Operador',
    ];

    /**
     * @param array<string, mixed> $actor Usuario en sesión (admin)
     * @return array{ok: bool, error?: string, redirect?: string}
     */
    public static function dispatch(PDO $pdo, int $targetUserId, string $canal, array $actor): array
    {
        $canal = strtolower(trim($canal));
        if (!in_array($canal, ['whatsapp', 'web', 'telegram'], true)) {
            return ['ok' => false, 'error' => 'Canal de notificación no válido'];
        }

        $target = self::fetchUser($pdo, $targetUserId);
        if ($target === null) {
            return ['ok' => false, 'error' => 'Usuario no encontrado'];
        }

        if (!self::actorCanNotify($pdo, $actor, $target)) {
            return ['ok' => false, 'error' => 'No tiene permiso para notificar a este usuario'];
        }

        $passwordUrl = self::createPasswordResetUrl($pdo, $targetUserId);
        $loginUrl = class_exists('AppHelpers')
            ? AppHelpers::url('login.php')
            : '/public/login.php';
        $mensaje = self::buildMessage($target, $passwordUrl, $loginUrl);

        switch ($canal) {
            case 'whatsapp':
                return self::sendWhatsApp($target, $mensaje);
            case 'web':
                return self::sendWeb($pdo, $target, $mensaje, $passwordUrl);
            case 'telegram':
                return self::sendTelegram($target, $mensaje);
            default:
                return ['ok' => false, 'error' => 'Canal no soportado'];
        }
    }

    /** @return array<string, mixed>|null */
    public static function fetchUser(PDO $pdo, int $userId): ?array
    {
        $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
        $tgCol = $hasTg ? ', u.telegram_chat_id' : '';
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nombre, u.username, u.cedula, u.email, u.celular, u.role, u.status, u.club_id,
                    c.nombre AS club_nombre{$tgCol}
             FROM usuarios u
             LEFT JOIN clubes c ON u.club_id = c.id
             WHERE u.id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $target
     */
    public static function actorCanNotify(PDO $pdo, array $actor, array $target): bool
    {
        $role = (string) ($actor['role'] ?? '');
        if ($role === 'admin_general') {
            return true;
        }
        if ($role !== 'admin_club') {
            return false;
        }
        $actorClub = (int) ($actor['club_id'] ?? 0);
        if ($actorClub <= 0) {
            return false;
        }
        $targetClub = (int) ($target['club_id'] ?? 0);
        if ($targetClub <= 0) {
            return false;
        }
        $supervised = ClubHelper::getClubesSupervised($actorClub);

        return in_array($targetClub, $supervised, true);
    }

    public static function createPasswordResetUrl(PDO $pdo, int $userId): string
    {
        $hasTokenCol = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'recovery_token'")->rowCount() > 0;
        if (!$hasTokenCol) {
            return class_exists('AppHelpers')
                ? AppHelpers::dashboard('users/change_password')
                : 'index.php?page=users/change_password';
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE usuarios SET recovery_token = ? WHERE id = ?')->execute([$token, $userId]);

        return class_exists('AppHelpers')
            ? AppHelpers::url('reset_password.php', ['token' => $token])
            : '/public/reset_password.php?token=' . urlencode($token);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function buildMessage(array $user, string $passwordUrl, string $loginUrl): string
    {
        $nombre = trim((string) ($user['nombre'] ?? $user['username'] ?? 'Usuario'));
        $username = (string) ($user['username'] ?? '—');
        $cedula = trim((string) ($user['cedula'] ?? ''));
        $cedulaTxt = $cedula !== '' ? $cedula : '—';
        $id = (int) ($user['id'] ?? 0);
        $rol = self::ROLE_LABELS[(string) ($user['role'] ?? '')] ?? (string) ($user['role'] ?? '—');
        $club = trim((string) ($user['club_nombre'] ?? ''));
        $appName = 'La Estación del Dominó';

        $lines = [
            "📋 *DATOS DE ACCESO - {$appName}*",
            '',
            "Hola *{$nombre}*",
            '',
            '━━━━━━━━━━━━━━━━━━',
            '👤 *INFORMACIÓN DE SU CUENTA*',
            '━━━━━━━━━━━━━━━━━━',
            '',
            "• Nombre: {$nombre}",
            "• Usuario: *{$username}*",
            "• Cédula: {$cedulaTxt}",
            "• ID usuario: {$id}",
            "• Rol: {$rol}",
        ];
        if ($club !== '') {
            $lines[] = "• Club: {$club}";
        }
        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '🔐 *CAMBIAR CONTRASEÑA*';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '';
        $lines[] = 'Use este enlace para establecer o cambiar su clave de acceso:';
        $lines[] = $passwordUrl;
        $lines[] = '';
        $lines[] = '🌐 *INICIAR SESIÓN*';
        $lines[] = $loginUrl;
        $lines[] = '';
        $lines[] = "_{$appName}_";

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{ok: bool, error?: string, redirect?: string}
     */
    private static function sendWhatsApp(array $user, string $mensaje): array
    {
        $telefono = trim((string) ($user['celular'] ?? ''));
        if ($telefono === '') {
            return ['ok' => false, 'error' => 'El usuario no tiene celular registrado para WhatsApp'];
        }

        return [
            'ok' => true,
            'redirect' => NotificationSender::whatsappLink($telefono, $mensaje),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{ok: bool, error?: string}
     */
    private static function sendWeb(PDO $pdo, array $user, string $mensaje, string $passwordUrl): array
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return ['ok' => false, 'error' => 'Usuario inválido'];
        }

        $nm = new NotificationManager($pdo);
        $nm->programarMasivoPersonalizado([
            [
                'id' => $uid,
                'telegram_chat_id' => null,
                'mensaje' => $mensaje,
                'url_destino' => $passwordUrl,
            ],
        ]);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{ok: bool, error?: string}
     */
    private static function sendTelegram(array $user, string $mensaje): array
    {
        $chatId = trim((string) ($user['telegram_chat_id'] ?? ''));
        if ($chatId === '') {
            return [
                'ok' => false,
                'error' => 'El usuario no tiene Telegram vinculado (Chat ID). Puede configurarlo en su perfil.',
            ];
        }

        $result = NotificationSender::sendTelegram($chatId, $mensaje);
        if (empty($result['ok'])) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'No se pudo enviar por Telegram')];
        }

        return ['ok' => true];
    }
}
