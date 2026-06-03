<?php
/**
 * Numerar consecutivamente jugadores POR CLUB
 * Cada club tendrá su propia numeración: 1, 2, 3...
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
    
    // Obtener parámetros
    $torneo_id = !empty($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : null;
    $club_id = !empty($_GET['club_id']) ? (int)$_GET['club_id'] : null;
    $numerar_todos = isset($_GET['numerar_todos']) && $_GET['numerar_todos'] === '1';
    
    if (!$torneo_id) {
        throw new Exception('Debe seleccionar un torneo obligatoriamente para numerar');
    }
    
    $pdo = DB::pdo();
    
    // Validar que el torneo no est• finalizado
    $stmt = $pdo->prepare("
        SELECT fechator, 
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
    $total_actualizados = 0;
    $clubes_procesados = [];
    
    // Si se especifica un club, numerar solo ese club
    if ($club_id) {
        $clubs_to_process = [$club_id];
    } 
    // Si se marca "numerar todos", obtener todos los clubs del torneo
    elseif ($numerar_todos) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT club_id 
            FROM inscripciones 
            WHERE torneo_id = ? AND club_id IS NOT NULL
            ORDER BY club_id
        ");
        $stmt->execute([$torneo_id]);
        $clubs_to_process = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } 
    else {
        throw new Exception('Debe especificar un club o marcar "numerar todos"');
    }
    
    if (empty($clubs_to_process)) {
        throw new Exception('No se encontraron clubs para numerar en este torneo');
    }
    
    $pdo->beginTransaction();
    
    // Numerar cada club independientemente
    foreach ($clubs_to_process as $current_club_id) {
        // Obtener nombre del club
        $stmt = $pdo->prepare("SELECT nombre FROM clubes WHERE id = ?");
        $stmt->execute([$current_club_id]);
        $club_name = $stmt->fetchColumn();
        
        // Obtener jugadores de este club en el torneo, ordenados por nombre
        $stmt = $pdo->prepare("
            SELECT id 
            FROM inscripciones 
            WHERE torneo_id = ? AND club_id = ?
            ORDER BY nombre ASC
        ");
        $stmt->execute([$torneo_id, $current_club_id]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($jugadores)) {
            // Asignar números consecutivos: 1, 2, 3...
            $numero = 1;
            $update_stmt = $pdo->prepare("UPDATE registrants SET identificador = ? WHERE id = ?");
            
            foreach ($jugadores as $jugador_id) {
                $update_stmt->execute([$numero, $jugador_id]);
                $numero++;
            }
            
            $total_actualizados += count($jugadores);
            $clubes_procesados[] = [
                'club_id' => $current_club_id,
                'club_nombre' => $club_name ?: 'Club Desconocido',
                'jugadores_numerados' => count($jugadores)
            ];
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Numeración por club completada exitosamente',
        'total_jugadores_actualizados' => $total_actualizados,
        'clubes_procesados' => count($clubes_procesados),
        'detalle_clubes' => $clubes_procesados
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






