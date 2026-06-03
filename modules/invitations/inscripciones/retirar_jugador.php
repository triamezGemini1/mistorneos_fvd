<?php
/**
 * Retirar Jugador del Torneo
 */

session_start();

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/db.php';

header('Content-Type: application/json');

try {
    $pdo = DB::pdo();
    
    $id = (int)$_POST['id'];
    $torneo_id = $_SESSION['torneo_id'];
    $club_id = $_SESSION['club_id'];
    
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        exit;
    }
    
    // Verificar que el jugador pertenece a este club y torneo
    $stmt = $pdo->prepare("
        SELECT * FROM inscripciones 
        WHERE id = ? AND torneo_id = ? AND club_id = ?
    ");
    $stmt->execute([$id, $torneo_id, $club_id]);
    $jugador = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$jugador) {
        echo json_encode([
            'success' => false, 
            'message' => 'Jugador no encontrado o no pertenece a este club/torneo'
        ]);
        exit;
    }
    
    // Eliminar inscripción
    $stmt = $pdo->prepare("DELETE FROM inscripciones WHERE id = ?");
    
    if ($stmt->execute([$id])) {
        echo json_encode([
            'success' => true, 
            'message' => '? Jugador retirado exitosamente'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Error al retirar jugador'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}










