<?php
/**
 * API para eliminar fotos de torneos
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/TournamentPhotoService.php';

header('Content-Type: application/json');

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $foto_id = isset($input['foto_id']) ? (int) $input['foto_id'] : 0;
    $torneo_id = isset($input['torneo_id']) ? (int) $input['torneo_id'] : 0;

    if ($foto_id <= 0 || $torneo_id <= 0) {
        throw new Exception('Parámetros inválidos');
    }
    if (!Auth::canAccessTournament($torneo_id)) {
        throw new Exception('No tiene permisos para acceder a este torneo');
    }

    if (!TournamentPhotoService::eliminar($pdo, $foto_id, $torneo_id)) {
        throw new Exception('Foto no encontrada');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Foto eliminada correctamente',
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
