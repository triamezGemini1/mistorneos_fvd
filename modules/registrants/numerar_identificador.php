<?php
/**
 * Numerar consecutivamente la columna IDENTIFICADOR en la BD
 * Club responsable queda al final
 */



header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    // Verificar autenticación
    Auth::requireRole(['admin_general', 'admin_torneo']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('M�todo no permitido');
    }
    
    // Obtener filtros
    $torneo_id = !empty($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : null;
    $club_ids = !empty($_GET['club_ids']) ? $_GET['club_ids'] : [];
    
    if (!$torneo_id) {
        throw new Exception('Debe seleccionar un torneo obligatoriamente para numerar');
    }
    
    // Obtener datos del torneo y validar que no est• finalizado
    $stmt = DB::pdo()->prepare("
        SELECT club_responsable, fechator, 
               CASE WHEN fechator < CURDATE() THEN 1 ELSE 0 END as pasado
        FROM tournaments 
        WHERE id = ?
    ");
    $stmt->execute([$torneo_id]);
    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo_data) {
        throw new Exception('Torneo no encontrado');
    }
    
    if ($torneo_data['pasado'] == 1) {
        throw new Exception('No se puede numerar un torneo finalizado. Solo se permite numerar torneos activos o futuros.');
    }
    
    $club_responsable_id = $torneo_data['club_responsable'];
    
    // Construir query con filtros
    $where = ["r.torneo_id = ?"];
    $params = [$torneo_id];
    
    if (!empty($club_ids) && is_array($club_ids)) {
        $placeholders = str_repeat('?,', count($club_ids) - 1) . '?';
        $where[] = "r.club_id IN ($placeholders)";
        foreach ($club_ids as $club_id) {
            $params[] = (int)$club_id;
        }
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where);
    
    // Consultar registros ordenados (responsable al final)
    $stmt = DB::pdo()->prepare("
        SELECT r.id
        FROM inscripciones r
        LEFT JOIN tournaments t ON r.torneo_id = t.id
        LEFT JOIN clubes c ON r.club_id = c.id
        $where_clause
        ORDER BY 
            CASE 
                WHEN c.id = t.club_responsable THEN 1
                ELSE 0
            END ASC,
            c.nombre ASC,
            r.nombre ASC
    ");
    $stmt->execute($params);
    $registrants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($registrants)) {
        throw new Exception('No hay registros para numerar con los filtros seleccionados');
    }
    
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    
    // Actualizar identificador consecutivo
    $numero = 1;
    $update_stmt = $pdo->prepare("UPDATE registrants SET identificador = ? WHERE id = ?");
    
    foreach ($inscripciones AS $registrant_id) {
        $update_stmt->execute([$numero, $registrant_id]);
        $numero++;
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Numeración asignada exitosamente',
        'registros_actualizados' => count($registrants)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}












