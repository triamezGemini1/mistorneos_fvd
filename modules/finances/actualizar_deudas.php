<?php
/**
 * Actualizar deudas de clubs basado en inscritos
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

try {
    Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $torneo_id = (int)($input['torneo_id'] ?? 0);
    
    if ($torneo_id <= 0) {
        throw new Exception('Torneo ID inválido');
    }
    
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    
    // Obtener costo del torneo
    $stmt = $pdo->prepare("SELECT costo FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $costo = (float)$stmt->fetchColumn();
    
    // Obtener clubs con inscritos en este torneo
    $stmt = $pdo->prepare("
        SELECT id_club as club_id, COUNT(*) as total_inscritos
        FROM inscritos
        WHERE torneo_id = ? AND id_club IS NOT NULL
        GROUP BY id_club
    ");
    $stmt->execute([$torneo_id]);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $clubs_actualizados = 0;
    
    foreach ($clubs as $club) {
        $club_id = $club['club_id'];
        $total_inscritos = $club['total_inscritos'];
        $monto_inscritos = $total_inscritos * $costo;
        $monto_total = $monto_inscritos;
        
        // Obtener abono actual antes del upsert (evitar error 1093)
        $stmt_abono = $pdo->prepare("SELECT COALESCE(abono, 0) FROM deuda_clubes WHERE torneo_id = ? AND club_id = ?");
        $stmt_abono->execute([$torneo_id, $club_id]);
        $abono_actual = $stmt_abono->fetchColumn();
        if ($abono_actual === false) {
            $abono_actual = 0;
        }
        
        // Upsert en deuda_clubes
        $stmt = $pdo->prepare("
            INSERT INTO deuda_clubes (torneo_id, club_id, total_inscritos, monto_inscritos, monto_total, abono, fecha_actualizacion)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_inscritos = VALUES(total_inscritos),
                monto_inscritos = VALUES(monto_inscritos),
                monto_total = VALUES(monto_total),
                fecha_actualizacion = NOW()
        ");
        $stmt->execute([$torneo_id, $club_id, $total_inscritos, $monto_inscritos, $monto_total, $abono_actual]);
        
        $clubs_actualizados++;
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Deudas actualizadas exitosamente',
        'clubs_actualizados' => $clubs_actualizados
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

