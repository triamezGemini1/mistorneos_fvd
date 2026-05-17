<?php
/**
 * API admin: pago inscripción (switch), recordatorio, recibo.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/InscripcionPagoService.php';
require_once __DIR__ . '/../../lib/ReportePagoUsuarioService.php';
require_once __DIR__ . '/../../lib/InscritosHelper.php';

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
$inscripcionId = (int) ($_POST['inscripcion_id'] ?? 0);
$torneoId = (int) ($_POST['torneo_id'] ?? 0);

if ($inscripcionId <= 0 || $torneoId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Parámetros inválidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Auth::canAccessTournament($torneoId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin permiso para este torneo'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($accion === 'toggle_pago') {
        $pagado = (int) ($_POST['pagado'] ?? 0) === 1;
        $res = $pagado
            ? InscripcionPagoService::validarPagoInscripcion($pdo, $inscripcionId, $torneoId)
            : InscripcionPagoService::marcarPendienteInscripcion($pdo, $inscripcionId, $torneoId);
        $payload = ['ok' => $res['ok'], 'message' => $res['message'], 'pagado' => $pagado];
        if ($res['ok'] && $pagado) {
            $recibo = cargarReciboInscripcion($pdo, $inscripcionId, $torneoId);
            if ($recibo !== null) {
                $payload['recibo_html'] = $recibo['html'];
            }
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'recordatorio_pago') {
        $res = InscripcionPagoService::enviarRecordatorioPago($pdo, $inscripcionId, $torneoId);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'ver_recibo') {
        $recibo = cargarReciboInscripcion($pdo, $inscripcionId, $torneoId);
        if ($recibo === null) {
            echo json_encode(['ok' => false, 'message' => 'No hay datos para generar recibo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'recibo_html' => $recibo['html']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('inscripcion_admin: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error del servidor'], JSON_UNESCAPED_UNICODE);
}

/**
 * @return array{html: string}|null
 */
function cargarReciboInscripcion(PDO $pdo, int $inscripcionId, int $torneoId): ?array
{
    $st = $pdo->prepare('
        SELECT i.id, i.id_usuario, i.torneo_id, i.estatus,
               u.nombre, u.cedula, u.id AS user_id
        FROM inscritos i
        INNER JOIN usuarios u ON u.id = i.id_usuario
        WHERE i.id = ? AND i.torneo_id = ?
        LIMIT 1
    ');
    $st->execute([$inscripcionId, $torneoId]);
    $ins = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ins) {
        return null;
    }

    $idUsuario = (int) $ins['id_usuario'];
    $hasRpu = (bool) $pdo->query("SHOW TABLES LIKE 'reportes_pago_usuarios'")->fetchColumn();
    if ($hasRpu) {
        $stR = $pdo->prepare("
            SELECT id FROM reportes_pago_usuarios
            WHERE torneo_id = ? AND id_usuario = ?
            ORDER BY FIELD(estatus, 'confirmado', 'pendiente', 'rechazado'), created_at DESC
            LIMIT 1
        ");
        $stR->execute([$torneoId, $idUsuario]);
        $reporteId = (int) $stR->fetchColumn();
        if ($reporteId > 0) {
            $reporte = ReportePagoUsuarioService::cargarReporte($pdo, $reporteId);
            if ($reporte !== null && ReportePagoUsuarioService::puedeGestionar($reporte)) {
                $data = ReportePagoUsuarioService::buildReciboData($reporte);

                return ['html' => ReportePagoUsuarioService::renderReciboHtml($data)];
            }
        }
    }

    return ['html' => renderReciboInscripcionBasico($pdo, $ins, $torneoId)];
}

/**
 * @param array<string, mixed> $ins
 */
function renderReciboInscripcionBasico(PDO $pdo, array $ins, int $torneoId): string
{
    $st = $pdo->prepare('SELECT nombre, fechator, costo FROM tournaments WHERE id = ? LIMIT 1');
    $st->execute([$torneoId]);
    $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $nombre = htmlspecialchars((string) ($ins['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
    $cedula = htmlspecialchars((string) ($ins['cedula'] ?? ''), ENT_QUOTES, 'UTF-8');
    $torneo = htmlspecialchars((string) ($t['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
    $fecha = !empty($t['fechator']) ? date('d/m/Y', strtotime((string) $t['fechator'])) : '—';
    $costo = number_format((float) ($t['costo'] ?? 0), 2);
    $ahora = date('d/m/Y H:i');

    return <<<HTML
<div class="recibo-pago-tarjeta border border-success rounded-3 overflow-hidden bg-white" id="recibo-pago-print">
  <div class="bg-success text-white p-3 text-center">
    <h4 class="mb-1">Comprobante de inscripción pagada</h4>
    <p class="mb-0 small opacity-90">Validado {$ahora}</p>
  </div>
  <div class="p-4">
    <h5 class="text-center fw-bold text-success mb-3">{$torneo}</h5>
    <p class="text-center text-muted small mb-3">{$fecha}</p>
    <p class="mb-1"><strong>Atleta:</strong> {$nombre}</p>
    <p class="mb-1"><strong>Cédula:</strong> {$cedula}</p>
    <p class="mb-1"><strong>ID usuario:</strong> {$ins['user_id']}</p>
    <p class="mb-0"><strong>Costo torneo:</strong> <span class="text-success fs-5">\${$costo}</span></p>
  </div>
</div>
HTML;
}
