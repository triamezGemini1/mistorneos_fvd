<?php
/**
 * Obtener pagos de un club por AJAX
 */



header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
    
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    $club_id = (int)($_GET['club_id'] ?? 0);
    
    if ($torneo_id <= 0 || $club_id <= 0) {
        throw new Exception('Parámetros inválidos');
    }
    
    $pdo = DB::pdo();
    
    // Obtener pagos
    $stmt = $pdo->prepare("
        SELECT id, secuencia, fecha, tipo_pago, monto_total as monto, referencia, banco, observaciones
        FROM relacion_pagos
        WHERE torneo_id = ? AND club_id = ?
        ORDER BY fecha DESC, secuencia DESC
    ");
    $stmt->execute([$torneo_id, $club_id]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular total pagado
    $total = array_sum(array_column($pagos, 'monto'));
    
    echo json_encode([
        'success' => true,
        'pagos' => $pagos,
        'total_pagado' => $total
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}














