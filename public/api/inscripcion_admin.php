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
require_once __DIR__ . '/../../lib/Tournament/Handlers/RegistrationHandler.php';
require_once __DIR__ . '/../../lib/ReciboPagoQrHelper.php';
require_once __DIR__ . '/../../lib/ReciboInscripcionRenderer.php';

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
    if ($accion === 'toggle_estatus' || $accion === 'toggle_pago') {
        $estado = trim((string) ($_POST['estado'] ?? ''));
        if ($estado === '' && $accion === 'toggle_pago') {
            $estado = (int) ($_POST['pagado'] ?? 0) === 1 ? 'confirmado' : 'pendiente';
        }
        if (!in_array($estado, ['pendiente', 'confirmado', 'retirado'], true)) {
            echo json_encode(['ok' => false, 'message' => 'Estatus no válido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $confirmacionDoble = (int) ($_POST['confirmacion_doble'] ?? 0) === 1;
        $res = InscripcionPagoService::establecerEstatusInscripcion(
            $pdo,
            $inscripcionId,
            $torneoId,
            $estado,
            $confirmacionDoble
        );
        $payload = [
            'ok' => $res['ok'],
            'message' => $res['message'],
            'estado' => $estado,
            'pagado' => $estado === 'confirmado',
        ];
        if ($res['ok'] && $estado === 'confirmado') {
            try {
                $recibo = cargarReciboInscripcion($pdo, $inscripcionId, $torneoId);
                if ($recibo !== null && ($recibo['html'] ?? '') !== '') {
                    $payload['recibo_html'] = $recibo['html'];
                } else {
                    $payload['recibo_warning'] = 'Pago confirmado, pero no se pudo generar el recibo. Use el botón Confirmado para reintentar.';
                }
            } catch (Throwable $reciboEx) {
                error_log('inscripcion_admin recibo: ' . $reciboEx->getMessage());
                $payload['recibo_warning'] = 'Pago confirmado. Error al generar recibo: ' . $reciboEx->getMessage();
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
        $stE = $pdo->prepare('SELECT estatus FROM inscritos WHERE id = ? AND torneo_id = ? LIMIT 1');
        $stE->execute([$inscripcionId, $torneoId]);
        $estatusRec = $stE->fetchColumn();
        if ($estatusRec !== false && !InscritosHelper::esConfirmado($estatusRec)) {
            echo json_encode([
                'ok' => false,
                'message' => 'El recibo solo está disponible cuando la inscripción está confirmada (pago validado).',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $recibo = cargarReciboInscripcion($pdo, $inscripcionId, $torneoId);
        if ($recibo === null) {
            echo json_encode(['ok' => false, 'message' => 'No hay datos para generar recibo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'recibo_html' => $recibo['html']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'eliminar_inscripcion') {
        if (Auth::isAdminTorneo() && !Auth::canModifyTournament($torneoId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'No tiene permiso para eliminar inscripciones de este torneo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $res = InscripcionPagoService::eliminarInscripcionRetirada($pdo, $inscripcionId, $torneoId);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'desinscribir' || $accion === 'quitar_inscripcion') {
        if (Auth::isAdminTorneo() && !Auth::canModifyTournament($torneoId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'No tiene permiso para quitar inscripciones de este torneo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $res = InscripcionPagoService::quitarInscripcionActiva($pdo, $inscripcionId, $torneoId);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'cambiar_asociacion') {
        $nuevoClubId = (int) ($_POST['id_club'] ?? 0);
        $stU = $pdo->prepare('SELECT id_usuario, estatus FROM inscritos WHERE id = ? AND torneo_id = ? LIMIT 1');
        $stU->execute([$inscripcionId, $torneoId]);
        $rowIns = $stU->fetch(PDO::FETCH_ASSOC);
        $idUsuario = (int) ($rowIns['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Inscripción no encontrada'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $confirmacionDoble = (int) ($_POST['confirmacion_doble'] ?? 0) === 1;
        if (InscritosHelper::esConfirmado($rowIns['estatus'] ?? 0) && !$confirmacionDoble) {
            echo json_encode([
                'ok' => false,
                'message' => 'Requiere confirmación doble: el recibo de pago ya fue emitido.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $out = \Tournament\Handlers\RegistrationHandler::apiCambiarClubInscrito(
            $pdo,
            $torneoId,
            $idUsuario,
            $nuevoClubId,
            (int) (Auth::id() ?? 0),
            true
        );
        echo json_encode([
            'ok' => !empty($out['success']),
            'message' => $out['message'] ?? ($out['error'] ?? 'Error'),
            'club_id' => $out['club_id'] ?? $nuevoClubId,
            'club_nombre' => $out['club_nombre'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('inscripcion_admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Error del servidor: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
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

    return ReciboInscripcionRenderer::htmlDesdeInscripcion($pdo, $inscripcionId, $torneoId);
}

/**
 * @param array<string, mixed> $ins
 * @deprecated Usar ReciboInscripcionRenderer
 */
function renderReciboInscripcionBasico(PDO $pdo, array $ins, int $torneoId): string
{
    $recibo = ReciboInscripcionRenderer::htmlDesdeInscripcion($pdo, (int) ($ins['id'] ?? 0), $torneoId);

    return $recibo['html'] ?? '';
}
