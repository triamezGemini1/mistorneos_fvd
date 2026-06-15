<?php
/**
 * API para subir fotos de torneos
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/TournamentPhotoService.php';

header('Content-Type: application/json');

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();

try {
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('No se ha seleccionado ningún archivo');
    }

    $torneo_id = isset($_POST['torneo_id']) ? (int) $_POST['torneo_id'] : 0;
    if ($torneo_id <= 0) {
        throw new Exception('ID de torneo inválido');
    }
    if (!Auth::canAccessTournament($torneo_id)) {
        throw new Exception('No tiene permisos para acceder a este torneo');
    }

    $result = TournamentPhotoService::subir($pdo, $torneo_id, $_FILES['foto'], Auth::id());
    if (!($result['success'] ?? false)) {
        throw new Exception((string) ($result['error'] ?? 'Error al subir la foto'));
    }

    $opt = $result['optimized'] ?? [];
    $msg = 'Foto subida y optimizada correctamente';
    if (isset($opt['savings_percent']) && (float) $opt['savings_percent'] > 0) {
        $msg .= ' (reducción ~' . (float) $opt['savings_percent'] . '%)';
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'foto_id' => $result['foto_id'] ?? null,
        'url' => $result['url'] ?? '',
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
