<?php
/**
 * API para obtener fotos de un torneo (público)
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/TournamentPhotoService.php';

header('Content-Type: application/json');

$pdo = DB::pdo();
$publicBase = rtrim(AppHelpers::getPublicUrl(), '/') . '/';

try {
    $torneo_id = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    if ($torneo_id <= 0) {
        throw new Exception('ID de torneo inválido');
    }

    $fotos = TournamentPhotoService::listarPublicas($pdo, $torneo_id, $publicBase);

    echo json_encode([
        'success' => true,
        'fotos' => $fotos,
        'total' => count($fotos),
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
