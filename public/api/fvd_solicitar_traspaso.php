<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/FvdDelegadoMovimientoService.php';
require_once __DIR__ . '/../../lib/FvdMovimientoTorneoHelper.php';

if (!Auth::user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autenticado']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
CSRF::validateApi();

$userId = (int) ($data['user_id'] ?? 0);
$torneoId = (int) ($data['torneo_id'] ?? 0);
$dest = (int) ($data['club_destino_id'] ?? 0);
$pdo = DB::pdo();
$club = Auth::clubOperativoAsociacion();
if ($club === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin asociación']);
    exit;
}

try {
    FvdMovimientoTorneoHelper::assertTorneoEditable($pdo, $torneoId);
    $mid = FvdDelegadoMovimientoService::solicitarTraspaso($pdo, $userId, $torneoId, (int) $club['id'], $dest);
    echo json_encode(['ok' => true, 'message' => 'Solicitud de traspaso registrada (ref. #' . $mid . ').', 'movimiento_id' => $mid]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
