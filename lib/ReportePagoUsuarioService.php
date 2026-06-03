<?php

declare(strict_types=1);

require_once __DIR__ . '/InscripcionPagoService.php';
require_once __DIR__ . '/InscripcionTorneoNotifier.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/NotificationManager.php';
require_once __DIR__ . '/ReciboPagoQrHelper.php';
require_once __DIR__ . '/../config/auth.php';

/**
 * Gestión de reportes_pago_usuarios: confirmar, recibo, notificaciones.
 */
final class ReportePagoUsuarioService
{
    /**
     * @return array<string, mixed>|null
     */
    public static function cargarReporte(PDO $pdo, int $reporteId): ?array
    {
        if ($reporteId <= 0) {
            return null;
        }
        $hasEntidad = self::tablaExiste($pdo, 'entidad');
        $entidadJoin = $hasEntidad
            ? 'LEFT JOIN entidad e ON e.id = u.entidad'
            : '';
        $entidadSelect = $hasEntidad
            ? ', e.nombre AS entidad_nombre, u.entidad AS entidad_id'
            : ', u.entidad AS entidad_id, NULL AS entidad_nombre';

        $sql = "
            SELECT
                rpu.*,
                u.id AS usuario_id,
                u.nombre AS usuario_nombre,
                u.cedula AS usuario_cedula,
                u.username AS usuario_username,
                u.celular AS usuario_celular,
                u.email AS usuario_email,
                u.telegram_chat_id,
                t.nombre AS torneo_nombre,
                t.fechator AS torneo_fecha,
                t.lugar AS torneo_lugar,
                t.costo AS torneo_costo,
                t.modalidad AS torneo_modalidad,
                t.rondas AS torneo_rondas,
                t.puntos AS torneo_puntos,
                t.tiempo AS torneo_tiempo,
                t.club_responsable,
                cb.banco AS cuenta_banco,
                cb.numero_cuenta AS cuenta_numero,
                cb.tipo_cuenta AS cuenta_tipo,
                cb.telefono_afiliado AS cuenta_telefono,
                cb.nombre_propietario AS cuenta_propietario
                {$entidadSelect}
            FROM reportes_pago_usuarios rpu
            INNER JOIN usuarios u ON rpu.id_usuario = u.id
            INNER JOIN tournaments t ON rpu.torneo_id = t.id
            LEFT JOIN cuentas_bancarias cb ON t.cuenta_id = cb.id
            {$entidadJoin}
            WHERE rpu.id = ?
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$reporteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function puedeGestionar(array $reporte): bool
    {
        $torneoId = (int) ($reporte['torneo_id'] ?? 0);

        return $torneoId > 0 && Auth::canAccessTournament($torneoId);
    }

    /**
     * @return array{ok:bool, message:string, recibo?:array<string,mixed>}
     */
    public static function establecerConfirmado(PDO $pdo, int $reporteId, bool $confirmado, bool $notificar = true): array
    {
        $reporte = self::cargarReporte($pdo, $reporteId);
        if ($reporte === null) {
            return ['ok' => false, 'message' => 'Reporte no encontrado.'];
        }
        if (!self::puedeGestionar($reporte)) {
            return ['ok' => false, 'message' => 'No tiene permiso para gestionar este reporte.'];
        }

        $torneoId = (int) $reporte['torneo_id'];
        $idUsuario = (int) $reporte['id_usuario'];
        $inscritoId = (int) ($reporte['inscrito_id'] ?? 0);

        if ($inscritoId <= 0 && $idUsuario > 0) {
            $stI = $pdo->prepare('SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND ' . InscritosHelper::SQL_WHERE_NO_RETIRADO . ' LIMIT 1');
            $stI->execute([$idUsuario, $torneoId]);
            $inscritoId = (int) ($stI->fetchColumn() ?: 0);
        }

        if ($confirmado) {
            if ($inscritoId > 0) {
                $res = InscripcionPagoService::validarPagoInscripcion($pdo, $inscritoId, $torneoId);
            } else {
                $st = $pdo->prepare("UPDATE reportes_pago_usuarios SET estatus = 'confirmado', updated_at = NOW() WHERE id = ?");
                $st->execute([$reporteId]);
                $res = ['ok' => true, 'message' => 'Reporte confirmado.'];
                if ($notificar && $idUsuario > 0) {
                    try {
                        $stC = $pdo->prepare('SELECT id_club FROM inscritos WHERE id_usuario = ? AND torneo_id = ? LIMIT 1');
                        $stC->execute([$idUsuario, $torneoId]);
                        $idClub = (int) ($stC->fetchColumn() ?: 0);
                        InscripcionTorneoNotifier::notificarPagoValidado($pdo, $idUsuario, $torneoId, $idClub, 0);
                    } catch (Throwable $e) {
                        error_log('ReportePagoUsuarioService notify: ' . $e->getMessage());
                    }
                }
            }
            if (!$res['ok']) {
                return $res;
            }
            $reporte = self::cargarReporte($pdo, $reporteId) ?? $reporte;
            $reporte['estatus'] = 'confirmado';

            return [
                'ok' => true,
                'message' => $res['message'],
                'recibo' => self::buildReciboData($reporte),
            ];
        }

        if ($inscritoId > 0) {
            $res = InscripcionPagoService::marcarPendienteInscripcion($pdo, $inscritoId, $torneoId);
        } else {
            $res = ['ok' => true, 'message' => 'Marcado como pendiente.'];
        }
        $st = $pdo->prepare("UPDATE reportes_pago_usuarios SET estatus = 'pendiente', updated_at = NOW() WHERE id = ?");
        $st->execute([$reporteId]);

        return $res;
    }

    /**
     * @param 'ambos'|'web'|'telegram'|'recordatorio' $canal
     * @return array{ok:bool, message:string, whatsapp_url?:string}
     */
    public static function enviarNotificacion(PDO $pdo, int $reporteId, string $canal): array
    {
        $reporte = self::cargarReporte($pdo, $reporteId);
        if ($reporte === null) {
            return ['ok' => false, 'message' => 'Reporte no encontrado.'];
        }
        if (!self::puedeGestionar($reporte)) {
            return ['ok' => false, 'message' => 'Sin permiso.'];
        }

        $torneoId = (int) $reporte['torneo_id'];
        $idUsuario = (int) $reporte['id_usuario'];
        $inscritoId = (int) ($reporte['inscrito_id'] ?? 0);
        if ($inscritoId <= 0) {
            $stI = $pdo->prepare('SELECT id, id_club FROM inscritos WHERE id_usuario = ? AND torneo_id = ? LIMIT 1');
            $stI->execute([$idUsuario, $torneoId]);
            $ins = $stI->fetch(PDO::FETCH_ASSOC);
            $inscritoId = (int) ($ins['id'] ?? 0);
            $idClub = (int) ($ins['id_club'] ?? 0);
        } else {
            $stC = $pdo->prepare('SELECT id_club FROM inscritos WHERE id = ?');
            $stC->execute([$inscritoId]);
            $idClub = (int) ($stC->fetchColumn() ?: 0);
        }

        if ($canal === 'recordatorio') {
            return InscripcionPagoService::enviarRecordatorioPago($pdo, $inscritoId, $torneoId);
        }

        $esConfirmado = ($reporte['estatus'] ?? '') === 'confirmado';
        if ($esConfirmado) {
            $payload = self::buildReciboData($reporte);
            $mensaje = self::mensajeReciboPlano($payload);
            $datosJson = [
                'tipo' => 'recibo_pago_validado',
                'reporte_id' => $reporteId,
                'torneo' => $payload['torneo_nombre'],
                'monto' => $payload['monto'],
                'entidad' => $payload['entidad_nombre'],
            ];
        } else {
            $payload = InscripcionTorneoNotifier::construirDatosRecordatorioPago($pdo, $idUsuario, $torneoId, $idClub, $inscritoId);
            if ($payload === null) {
                return ['ok' => false, 'message' => 'No se pudo armar el mensaje.'];
            }
            $mensaje = $payload['mensaje'];
            $datosJson = $payload['datos_json'];
        }

        $urlDestino = class_exists('AppHelpers')
            ? AppHelpers::url('index.php', ['page' => 'user_notificaciones'])
            : '#';

        $item = [
            'id' => $idUsuario,
            'mensaje' => $mensaje,
            'url_destino' => $urlDestino,
            'datos_json' => $datosJson,
            'telegram_chat_id' => trim((string) ($reporte['telegram_chat_id'] ?? '')) ?: null,
        ];

        $nm = new NotificationManager($pdo);
        if ($canal === 'web') {
            self::encolarSoloCanal($pdo, $item, 'web');
            return ['ok' => true, 'message' => 'Notificación web encolada.'];
        }
        if ($canal === 'telegram') {
            if (empty($item['telegram_chat_id'])) {
                return ['ok' => false, 'message' => 'El atleta no tiene Telegram vinculado.'];
            }
            self::encolarSoloCanal($pdo, $item, 'telegram');
            return ['ok' => true, 'message' => 'Notificación Telegram encolada.'];
        }

        $nm->programarMasivoPersonalizado([$item]);
        return ['ok' => true, 'message' => 'Notificación web y Telegram encoladas.'];
    }

    /**
     * @param array<string, mixed> $reporte
     * @return array<string, mixed>
     */
    public static function buildReciboData(array $reporte): array
    {
        $fechaTor = !empty($reporte['torneo_fecha'])
            ? date('d/m/Y', strtotime((string) $reporte['torneo_fecha']))
            : '—';
        $horaTor = '—';
        if (!empty($reporte['torneo_fecha']) && strlen((string) $reporte['torneo_fecha']) > 10) {
            $horaTor = date('H:i', strtotime((string) $reporte['torneo_fecha']));
        }
        $modalidadMap = [0 => 'No definido', 1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
        $mod = (int) ($reporte['torneo_modalidad'] ?? 0);

        return [
            'reporte_id' => (int) ($reporte['id'] ?? 0),
            'torneo_id' => (int) ($reporte['torneo_id'] ?? 0),
            'inscripcion_id' => (int) ($reporte['inscrito_id'] ?? 0),
            'torneo_nombre' => (string) ($reporte['torneo_nombre'] ?? ''),
            'fecha_torneo' => $fechaTor,
            'hora_torneo' => $horaTor,
            'lugar' => trim((string) ($reporte['torneo_lugar'] ?? '')),
            'modalidad' => $modalidadMap[$mod] ?? '—',
            'rondas' => (int) ($reporte['torneo_rondas'] ?? 0),
            'puntos' => (int) ($reporte['torneo_puntos'] ?? 0),
            'tiempo' => (int) ($reporte['torneo_tiempo'] ?? 0),
            'atleta_nombre' => (string) ($reporte['usuario_nombre'] ?? ''),
            'cedula' => (string) ($reporte['usuario_cedula'] ?? ''),
            'username' => (string) ($reporte['usuario_username'] ?? ''),
            'user_id' => (int) ($reporte['usuario_id'] ?? 0),
            'entidad_nombre' => trim((string) ($reporte['entidad_nombre'] ?? '')) ?: '—',
            'entidad_id' => (int) ($reporte['entidad_id'] ?? 0),
            'monto' => number_format((float) ($reporte['monto'] ?? 0), 2),
            'monto_raw' => (float) ($reporte['monto'] ?? 0),
            'tipo_pago' => ucfirst((string) ($reporte['tipo_pago'] ?? '')),
            'banco' => (string) ($reporte['banco'] ?? ''),
            'referencia' => (string) ($reporte['referencia'] ?? ''),
            'fecha_pago' => !empty($reporte['fecha']) ? date('d/m/Y', strtotime((string) $reporte['fecha'])) : '—',
            'hora_pago' => (string) ($reporte['hora'] ?? ''),
            'estatus' => (string) ($reporte['estatus'] ?? ''),
            'cantidad_inscritos' => (int) ($reporte['cantidad_inscritos'] ?? 1),
            'validado_en' => date('d/m/Y H:i'),
        ];
    }

    /**
     * @param array<string, mixed> $recibo
     */
    public static function renderReciboHtml(array $recibo): string
    {
        $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $lugar = $recibo['lugar'] !== '' ? $esc($recibo['lugar']) : '—';
        $ref = $recibo['referencia'] !== '' ? $esc($recibo['referencia']) : '—';
        $banco = $recibo['banco'] !== '' ? $esc($recibo['banco']) : '—';
        $qrPersonal = ReciboPagoQrHelper::bloqueHtmlQrPersonal(
            (int) ($recibo['torneo_id'] ?? 0),
            (int) ($recibo['user_id'] ?? 0)
        );

        return <<<HTML
<div class="recibo-pago-tarjeta border border-success rounded-3 overflow-hidden bg-white" id="recibo-pago-print">
  <div class="bg-success text-white text-center py-4 px-3">
    <div class="mb-2"><i class="fas fa-receipt fa-2x"></i></div>
    <h4 class="mb-1 fw-bold">Recibo de pago validado</h4>
    <p class="mb-0 small opacity-90">Reporte #{$esc($recibo['reporte_id'])} · {$esc($recibo['validado_en'])}</p>
  </div>
  <div class="p-4">
    <h5 class="text-center fw-bold text-success mb-3">{$esc($recibo['torneo_nombre'])}</h5>
    <p class="text-center text-muted small mb-3">
      {$esc($recibo['fecha_torneo'])} · {$lugar} · {$esc($recibo['hora_torneo'])}
    </p>
    <p class="text-center small mb-4">
      {$esc($recibo['modalidad'])} · {$esc((string)$recibo['rondas'])} rondas · {$esc((string)$recibo['tiempo'])} min · {$esc((string)$recibo['puntos'])} pts
    </p>
    <hr>
    <p class="mb-1"><strong>Atleta:</strong> {$esc($recibo['atleta_nombre'])}</p>
    <p class="mb-1"><strong>Cédula:</strong> {$esc($recibo['cedula'])}</p>
    <p class="mb-1"><strong>ID usuario:</strong> {$esc((string)$recibo['user_id'])}</p>
    <p class="mb-1"><strong>Entidad / asociación:</strong> {$esc($recibo['entidad_nombre'])}</p>
    <hr>
    <p class="mb-1"><strong>Monto reportado:</strong> <span class="text-success fs-5">\${$esc($recibo['monto'])}</span></p>
    <p class="mb-1"><strong>Tipo:</strong> {$esc($recibo['tipo_pago'])} · <strong>Banco:</strong> {$banco}</p>
    <p class="mb-1"><strong>Referencia:</strong> {$ref}</p>
    <p class="mb-0"><strong>Fecha pago:</strong> {$esc($recibo['fecha_pago'])} {$esc($recibo['hora_pago'])}</p>
    {$qrPersonal}
  </div>
  <div class="bg-light text-center py-2 small text-muted">
    Comprobante para validación · FVD
  </div>
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $recibo
     */
    public static function mensajeReciboPlano(array $recibo): string
    {
        $msg = "✅ *RECIBO DE PAGO VALIDADO*\n\n";
        $msg .= '🏆 *' . ($recibo['torneo_nombre'] ?? '') . "*\n";
        $msg .= '📅 ' . ($recibo['fecha_torneo'] ?? '') . "\n";
        if (($recibo['lugar'] ?? '') !== '') {
            $msg .= '📍 ' . $recibo['lugar'] . "\n";
        }
        $msg .= "\n👤 *" . ($recibo['atleta_nombre'] ?? '') . "*\n";
        $msg .= '🆔 Cédula: ' . ($recibo['cedula'] ?? '') . "\n";
        $msg .= '🏢 Entidad: ' . ($recibo['entidad_nombre'] ?? '') . "\n\n";
        $msg .= '💰 *Monto: $' . ($recibo['monto'] ?? '0.00') . "*\n";
        $msg .= 'Ref: ' . (($recibo['referencia'] ?? '') !== '' ? $recibo['referencia'] : '—') . "\n\n";
        $msg .= 'Su inscripción quedó *confirmada*. Presente este comprobante si se solicita.';

        return $msg;
    }

    /**
     * @param array{id:int, mensaje:string, url_destino:string, datos_json:mixed, telegram_chat_id:?string} $item
     */
    private static function encolarSoloCanal(PDO $pdo, array $item, string $canal): void
    {
        $hasDatosJson = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'")->rowCount() > 0;
        $uid = (int) $item['id'];
        $mensaje = (string) $item['mensaje'];
        $url = (string) $item['url_destino'];
        $datosJson = isset($item['datos_json'])
            ? (is_string($item['datos_json']) ? $item['datos_json'] : json_encode($item['datos_json'], JSON_UNESCAPED_UNICODE))
            : null;

        if ($canal === 'telegram' && empty($item['telegram_chat_id'])) {
            return;
        }

        if ($hasDatosJson) {
            $st = $pdo->prepare('INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino, datos_json) VALUES (?, ?, ?, ?, ?)');
            $st->execute([$uid, $canal, $mensaje, $url, $canal === 'web' ? $datosJson : null]);
        } else {
            $st = $pdo->prepare('INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino) VALUES (?, ?, ?, ?)');
            $st->execute([$uid, $canal, $mensaje, $url]);
        }
    }

    private static function tablaExiste(PDO $pdo, string $tabla): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$tabla]);

        return (bool) $st->fetchColumn();
    }
}
