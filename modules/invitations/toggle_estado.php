<?php
/**
 * Cambiar Estado de Invitación
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

Auth::requireRole(['admin_general','admin_torneo']);

if (!isset($_GET['id']) || !isset($_GET['action'])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_GET['id'];
$action = $_GET['action'];

// Determinar nuevo estado
$estados_map = ['activar' => 'activa', 'expirar' => 'expirada', 'cancelar' => 'cancelada'];
$mensajes_map = ['activar' => 'Invitación activada', 'expirar' => 'Invitación marcada como expirada', 'cancelar' => 'Invitación cancelada'];
$nuevo_estado = $estados_map[$action] ?? null;

if (!$nuevo_estado) {
    header("Location: index.php?error=" . urlencode("Acción inválida"));
    exit;
}

try {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("UPDATE invitations SET estado = ? WHERE id = ?");
    
    if ($stmt->execute([$nuevo_estado, $id])) {
        $msg = $mensajes_map[$action] ?? 'Estado actualizado';
        header("Location: index.php?msg=" . urlencode($msg));
    } else {
        header("Location: index.php?error=" . urlencode("Error al cambiar estado"));
    }
    
} catch (PDOException $e) {
    header("Location: index.php?error=" . urlencode($e->getMessage()));
}
exit;










