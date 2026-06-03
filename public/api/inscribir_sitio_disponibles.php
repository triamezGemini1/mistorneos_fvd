<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/InscribirSitioDisponiblesService.php';

$input = array_merge($_GET, $_POST);
$torneoId = (int) ($input['torneo_id'] ?? 0);
$clubPost = (int) ($input['id_club'] ?? $input['club_id'] ?? 0);
$q = trim((string) ($input['q'] ?? $input['busqueda'] ?? ''));

if ($torneoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'torneo_id requerido']);
    exit;
}

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
if (!Auth::canAccessTournament($torneoId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permiso para este torneo']);
    exit;
}

$pdo = DB::pdo();
$clubId = AsociacionAdminHelper::clubFiltroInscripcionSitio($pdo, $clubPost > 0 ? $clubPost : null);
if ($clubId === null || $clubId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Seleccione la asociación', 'code' => 'club_requerido']);
    exit;
}

if ($q === '') {
    echo json_encode(['success' => true, 'club_id' => $clubId, 'total' => 0, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = InscribirSitioDisponiblesService::listar($pdo, $torneoId, $clubId, $q);
    echo json_encode([
        'success' => true,
        'club_id' => $clubId,
        'total' => $data['total'],
        'items' => $data['items'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('inscribir_sitio_disponibles: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al cargar disponibles']);
}
