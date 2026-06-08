<?php
/**
 * Actualizar Estadísticas (Desktop). Ejecuta core/logica_torneo.php actualizarEstadisticasInscritos().
 * GET/POST: torneo_id. Redirige a panel_torneo.php?torneo_id=X (Panel de Control del Torneo). No redirige a registro ni raíz.
 */
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/desktop_auth.php';

$torneo_id = (int)($_REQUEST['torneo_id'] ?? 0);
if ($torneo_id <= 0) {
    ob_end_clean();
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Torneo no válido']);
        exit;
    }
    header('Location: torneos.php?error=' . urlencode('Torneo no válido'));
    exit;
}

require_once __DIR__ . '/../../desktop/core/db_bridge.php';
require_once __DIR__ . '/db_local.php';
DB_Local::pdo();
require_once __DIR__ . '/../../desktop/core/logica_torneo.php';

$ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Redirección fija: mismo directorio → panel_torneo.php?torneo_id=X
$url_ok = 'panel_torneo.php?torneo_id=' . $torneo_id . '&msg=estadisticas_actualizadas';
$url_error = 'panel_torneo.php?torneo_id=' . $torneo_id . '&error=';

try {
    actualizarEstadisticasInscritos($torneo_id, true);
    if ($ajax) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'Estadísticas actualizadas']);
        exit;
    }
    ob_end_clean();
    header('Location: ' . $url_ok);
    exit;
} catch (Throwable $e) {
    if ($ajax) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    ob_end_clean();
    header('Location: ' . $url_error . urlencode($e->getMessage()));
    exit;
}
