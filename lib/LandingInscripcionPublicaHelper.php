<?php
/**
 * Inscripción pública desde landing: tarjeta de confirmación y aviso a admin_general.
 */
declare(strict_types=1);

require_once __DIR__ . '/NotificationSender.php';

final class LandingInscripcionPublicaHelper
{
    private const SESSION_PREFIX = 'inscripcion_publica_tarjeta_';
    private const SESSION_WHATSAPP_ADMINS = 'landing_inscripcion_whatsapp_admin';

    public static function sessionKey(int $torneoId): string
    {
        return self::SESSION_PREFIX . $torneoId;
    }

    public static function guardarTarjeta(int $torneoId, array $data): void
    {
        $_SESSION[self::sessionKey($torneoId)] = [
            'torneo_id' => $torneoId,
            'saved_at' => time(),
            'data' => $data,
        ];
    }

    public static function leerTarjeta(int $torneoId, int $ttlSeconds = 604800): ?array
    {
        $snap = $_SESSION[self::sessionKey($torneoId)] ?? null;
        if (!is_array($snap) || (int)($snap['torneo_id'] ?? 0) !== $torneoId) {
            return null;
        }
        if ($ttlSeconds > 0 && (time() - (int)($snap['saved_at'] ?? 0)) > $ttlSeconds) {
            return null;
        }
        $data = $snap['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    public static function hayTarjetaGuardada(int $torneoId): bool
    {
        return self::leerTarjeta($torneoId) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function construirTarjeta(
        PDO $pdo,
        array $torneo,
        int $torneoId,
        int $idInscrito,
        int $idUsuario,
        string $nombreAtleta,
        string $nacionalidad,
        string $cedulaNum,
        ?string $username,
        ?string $passwordTemporal,
        bool $esUsuarioNuevo,
        int $entidadId = 0,
        int $numfvd = 0,
        bool $credencialesAutomaticas = false,
        bool $passwordIgualUsuario = false
    ): array {
        $nac = in_array(strtoupper($nacionalidad), ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V';
        $entNombre = '';
        if ($entidadId > 0) {
            try {
                $st = $pdo->prepare('SELECT nombre FROM entidad WHERE id = ? LIMIT 1');
                $st->execute([$entidadId]);
                $entNombre = (string)($st->fetchColumn() ?: '');
            } catch (Throwable $e) {
                $entNombre = '';
            }
        }

        if ($username === null || $username === '') {
            $st = $pdo->prepare('SELECT username, cedula, COALESCE(numfvd, 0) AS numfvd FROM usuarios WHERE id = ? LIMIT 1');
            $st->execute([$idUsuario]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $username = (string)($u['username'] ?? '');
            if ($numfvd <= 0) {
                $numfvd = (int)($u['numfvd'] ?? 0);
            }
        }

        if ($numfvd <= 0) {
            require_once __DIR__ . '/InscritosHelper.php';
            $numfvd = InscritosHelper::asegurarNumfvdInscrito($pdo, $torneoId, $idUsuario);
        }
        if ($numfvd <= 0) {
            require_once __DIR__ . '/InscritosHelper.php';
            $numfvd = InscritosHelper::numfvdDesdeUsuario($pdo, $idUsuario);
        }

        $modalidadMap = [0 => 'No definido', 1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
        $modalidadInt = (int)($torneo['modalidad'] ?? 0);
        $rawFecha = (string)($torneo['fechator'] ?? '');
        $ts = $rawFecha !== '' ? strtotime($rawFecha) : false;
        $fecha = $ts ? date('d/m/Y', $ts) : '—';
        $hora = '—';
        if ($rawFecha !== '' && strlen($rawFecha) > 10 && $ts) {
            $hora = date('H:i', $ts);
        }

        $basePublic = rtrim(
            class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'),
            '/'
        );
        $perfilUrl = $basePublic . '/entrar_credencial.php?id=' . $idUsuario;
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'size' => '160x160',
            'ecc' => 'M',
            'data' => $perfilUrl,
        ], '', '&', PHP_QUERY_RFC3986);

        $nombreTorneo = trim((string)($torneo['nombre'] ?? 'Torneo'));
        $nombreTorneo = preg_replace('/\bmasivos?\b/i', '', $nombreTorneo);
        $nombreTorneo = trim(preg_replace('/\s+/', ' ', $nombreTorneo));

        return [
            'id_inscripcion' => $idInscrito,
            'torneo_id' => $torneoId,
            'torneo_nombre' => $nombreTorneo !== '' ? $nombreTorneo : 'Torneo',
            'fecha' => $fecha,
            'hora' => $hora,
            'lugar' => trim((string)($torneo['lugar'] ?? '')),
            'modalidad' => $modalidadMap[$modalidadInt] ?? 'Individual',
            'rondas' => (int)($torneo['rondas'] ?? 0),
            'puntos' => (int)($torneo['puntos'] ?? 0),
            'tiempo' => (int)($torneo['tiempo'] ?? 0),
            'costo' => (float)($torneo['costo'] ?? 0),
            'user_id' => $idUsuario,
            'username' => (string)($username ?? ''),
            'cedula_mostrar' => $nac . $cedulaNum,
            'atleta_nombre' => $nombreAtleta,
            'entidad_nombre' => $entNombre,
            'perfil_url' => $perfilUrl,
            'qr_url' => $qrUrl,
            'portal_url' => $basePublic . '/user_portal.php',
            'password_temporal' => $passwordTemporal,
            'es_usuario_nuevo' => $esUsuarioNuevo,
            'numfvd' => $numfvd > 0 ? $numfvd : null,
            'credenciales_automaticas' => $credencialesAutomaticas,
            'password_igual_usuario' => $passwordIgualUsuario,
        ];
    }

    /**
     * Notifica a admin_general (campanita web + enlaces WhatsApp).
     *
     * @param array<string, mixed> $ctx
     * @return list<array{admin_id: int, admin_nombre: string, url: string}>
     */
    public static function notificarAdminGeneral(PDO $pdo, array $ctx): array
    {
        $links = [];
        $mensaje = self::mensajeWhatsappAdmin($ctx);
        $torneoId = (int)($ctx['torneo_id'] ?? 0);
        $baseUrl = rtrim((string)($ctx['base_url'] ?? app_base_url()), '/');
        $urlPanel = $baseUrl . '/index.php?page=registrants&torneo_id=' . $torneoId;

        try {
            $admins = self::listarAdminsGenerales($pdo);
            $hasDatosJson = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'")->rowCount() > 0;
            $msgWeb = 'Nueva inscripción pública: '
                . ($ctx['atleta_nombre'] ?? 'Atleta')
                . ' en «' . ($ctx['torneo_nombre'] ?? 'torneo') . '».';

            foreach ($admins as $admin) {
                $adminId = (int)($admin['id'] ?? 0);
                if ($adminId <= 0) {
                    continue;
                }
                if ($hasDatosJson) {
                    $pdo->prepare(
                        'INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino, datos_json) VALUES (?, ?, ?, ?, ?)'
                    )->execute([
                        $adminId,
                        'web',
                        $msgWeb,
                        $urlPanel,
                        json_encode([
                            'tipo' => 'inscripcion_landing',
                            'torneo_id' => $torneoId,
                            'inscripcion_id' => (int)($ctx['inscripcion_id'] ?? 0),
                            'cedula' => (string)($ctx['cedula_mostrar'] ?? ''),
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino) VALUES (?, ?, ?, ?)'
                    )->execute([$adminId, 'web', $msgWeb, $urlPanel]);
                }

                $tel = preg_replace('/[^0-9]/', '', (string)($admin['celular'] ?? ''));
                if (strlen($tel) >= 10) {
                    $links[] = [
                        'admin_id' => $adminId,
                        'admin_nombre' => (string)($admin['nombre'] ?? 'Admin general'),
                        'url' => NotificationSender::whatsappLink($tel, $mensaje),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('LandingInscripcionPublicaHelper::notificarAdminGeneral: ' . $e->getMessage());
        }

        $_SESSION[self::SESSION_WHATSAPP_ADMINS] = $links;

        return $links;
    }

    /**
     * @return list<array{id: int, nombre: string, celular: string}>
     */
    private static function listarAdminsGenerales(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, nombre, celular FROM usuarios
             WHERE role = 'admin_general'
               AND (status IN (0, 1, '0', '1', 'approved') OR status IS NULL)"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return $rows;
        }
        $stmt = $pdo->query("SELECT id, nombre, celular FROM usuarios WHERE role = 'admin_general'");

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function mensajeWhatsappAdmin(array $ctx): string
    {
        $m = "🔔 *NUEVA INSCRIPCIÓN DESDE EL PORTAL*\n\n";
        $m .= "━━━━━━━━━━━━━━━━━━\n";
        $m .= "👤 *Participante*\n";
        $m .= "━━━━━━━━━━━━━━━━━━\n\n";
        $m .= 'Nombre: *' . ($ctx['atleta_nombre'] ?? 'N/A') . "*\n";
        $m .= 'Cédula: *' . ($ctx['cedula_mostrar'] ?? '') . "*\n";
        if (!empty($ctx['celular'])) {
            $m .= 'Teléfono: ' . $ctx['celular'] . "\n";
        }
        if (!empty($ctx['email'])) {
            $m .= 'Email: ' . $ctx['email'] . "\n";
        }
        if (!empty($ctx['es_usuario_nuevo'])) {
            $m .= "\n⚠️ *Usuario nuevo creado en el sistema.*\n";
        } else {
            $m .= "\nℹ️ Usuario ya existía; inscripción agregada.\n";
        }
        $m .= "\n━━━━━━━━━━━━━━━━━━\n";
        $m .= "🏆 *Torneo*\n";
        $m .= "━━━━━━━━━━━━━━━━━━\n\n";
        $m .= 'Evento: *' . ($ctx['torneo_nombre'] ?? '') . "*\n";
        if (!empty($ctx['fecha_torneo'])) {
            $m .= 'Fecha: ' . $ctx['fecha_torneo'] . "\n";
        }
        if (!empty($ctx['lugar'])) {
            $m .= 'Lugar: ' . $ctx['lugar'] . "\n";
        }
        if (!empty($ctx['inscripcion_id'])) {
            $m .= 'ID inscripción: ' . (int)$ctx['inscripcion_id'] . "\n";
        }
        $m .= "\nRevise el panel de inscritos en la plataforma FVD.";

        return $m;
    }

    /**
     * @return list<array{admin_id: int, admin_nombre: string, url: string}>
     */
    public static function consumirEnlacesWhatsappAdmin(): array
    {
        $links = $_SESSION[self::SESSION_WHATSAPP_ADMINS] ?? [];
        unset($_SESSION[self::SESSION_WHATSAPP_ADMINS]);

        return is_array($links) ? $links : [];
    }
}
