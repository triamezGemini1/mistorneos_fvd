<?php
/**
 * API admin: confirmar/pendiente y notificaciones de reportes de pago.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/ReportePagoUsuarioService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

try {
    CSRF::validate();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = DB::pdo();
$accion = trim((string) ($_POST['accion'] ?? ''));
$reporteId = (int) ($_POST['reporte_id'] ?? 0);

if ($reporteId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ID de reporte inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($accion === 'toggle_confirmado') {
        $confirmado = (int) ($_POST['confirmado'] ?? 0) === 1;
        $notificar = (int) ($_POST['notificar'] ?? 1) === 1;
        $res = ReportePagoUsuarioService::establecerConfirmado($pdo, $reporteId, $confirmado, $notificar);
        $payload = ['ok' => $res['ok'], 'message' => $res['message']];
        if ($res['ok'] && $confirmado && !empty($res['recibo'])) {
            $payload['recibo_html'] = ReportePagoUsuarioService::renderReciboHtml($res['recibo']);
            $payload['recibo'] = $res['recibo'];
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'notificar') {
        $canal = trim((string) ($_POST['canal'] ?? 'ambos'));
        if (!in_array($canal, ['ambos', 'web', 'telegram', 'recordatorio'], true)) {
            $canal = 'ambos';
        }
        $res = ReportePagoUsuarioService::enviarNotificacion($pdo, $reporteId, $canal);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'ver_recibo') {
        $reporte = ReportePagoUsuarioService::cargarReporte($pdo, $reporteId);
        if ($reporte === null || !ReportePagoUsuarioService::puedeGestionar($reporte)) {
            echo json_encode(['ok' => false, 'message' => 'Reporte no disponible'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $recibo = ReportePagoUsuarioService::buildReciboData($reporte);
        echo json_encode([
            'ok' => true,
            'recibo_html' => ReportePagoUsuarioService::renderReciboHtml($recibo),
            'recibo' => $recibo,
            'reporte' => $reporte,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('reporte_pago_admin: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error del servidor'], JSON_UNESCAPED_UNICODE);
}
