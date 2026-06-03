<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdConfig.php';
require_once __DIR__ . '/NotificationManager.php';
require_once __DIR__ . '/app_helpers.php';

/**
 * Avisa a cada delegado de asociación (club) cuando la federación crea un torneo.
 */
final class TournamentCreatedNotifier
{
    /**
     * Encola notificaciones web (+ Telegram si aplica) para delegados de clubes bajo la FVD.
     *
     * @param int $excludeUserId No notificar al creador del torneo (p. ej. mismo delegado en pruebas)
     */
    public static function notifyAssociationDelegates(PDO $pdo, int $tournamentId, int $excludeUserId = 0): void
    {
        if (!FvdConfig::adminModuleEnabled() || $tournamentId <= 0) {
            return;
        }

        $st = $pdo->prepare('SELECT nombre, fechator FROM tournaments WHERE id = ? LIMIT 1');
        $st->execute([$tournamentId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }

        $nombre = trim((string) ($t['nombre'] ?? ''));
        $fechaFmt = '';
        $rawFecha = $t['fechator'] ?? null;
        if ($rawFecha) {
            try {
                $fechaFmt = (new DateTimeImmutable((string) $rawFecha))->format('d/m/Y');
            } catch (Throwable $e) {
                $fechaFmt = (string) $rawFecha;
            }
        }

        $delegados = self::fetchDelegadoRecipients($pdo);
        if ($excludeUserId > 0) {
            $delegados = array_values(array_filter(
                $delegados,
                static fn (array $row): bool => (int) ($row['id'] ?? 0) !== $excludeUserId
            ));
        }
        if ($delegados === []) {
            return;
        }

        $url = AppHelpers::dashboard('asociacion_panel', ['torneo_nuevo' => $tournamentId]);
        $msg = 'Nuevo torneo de la federación: «' . $nombre . '»'
            . ($fechaFmt !== '' ? ' — Fecha: ' . $fechaFmt . '.' : '.')
            . ' Puede iniciar inscripciones, afiliación, traspasos y carnets desde el panel de su asociación.';

        $nm = new NotificationManager($pdo);
        $nm->programarMasivo($delegados, $msg, $url);
    }

    /**
     * @return list<array{id:int, telegram_chat_id?: string|null}>
     */
    private static function fetchDelegadoRecipients(PDO $pdo): array
    {
        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM clubes LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasCodOrg = false;
        }

        $orgId = FvdConfig::ORGANIZACION_ID;

        if ($hasCodOrg) {
            $sql = "
                SELECT DISTINCT u.id, u.telegram_chat_id
                FROM clubes c
                INNER JOIN usuarios u ON u.id = c.delegado_user_id
                WHERE c.estatus = 1
                  AND c.delegado_user_id IS NOT NULL AND c.delegado_user_id > 0
                  AND u.status = 0
                  AND c.cod_org = ?
            ";
            $st = $pdo->prepare($sql);
            $st->execute([$orgId]);
        } else {
            $sql = "
                SELECT DISTINCT u.id, u.telegram_chat_id
                FROM clubes c
                INNER JOIN usuarios u ON u.id = c.delegado_user_id
                WHERE c.estatus = 1
                  AND c.delegado_user_id IS NOT NULL AND c.delegado_user_id > 0
                  AND u.status = 0
            ";
            $st = $pdo->prepare($sql);
            $st->execute();
        }

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $tg = isset($r['telegram_chat_id']) ? trim((string) $r['telegram_chat_id']) : '';
            $out[] = [
                'id' => $id,
                'telegram_chat_id' => $tg !== '' ? $tg : null,
            ];
        }

        return $out;
    }
}
