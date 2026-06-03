<?php
/**
 * Acceso público a invitaciones sin requerir autenticación de administrador
 * Este archivo permite acceder a los links de invitación sin sesión de administrador
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

// Obtener parámetros de la URL
$token = $_GET['token'] ?? '';
$torneo_id = $_GET['torneo'] ?? '';
$club_id = $_GET['club'] ?? '';

// Validar que tenemos al menos torneo_id y club_id
if (empty($torneo_id) || empty($club_id)) {
    http_response_code(400);
    die('Parámetros de invitación inválidos');
}

try {
    // Verificar que la invitación existe y está activa
    $stmt = DB::pdo()->prepare("
        SELECT i.*, c.nombre as club_nombre, t.nombre as torneo_nombre
        FROM invitations i
        JOIN clubes c ON c.id = i.club_id
        JOIN tournaments t ON t.id = i.torneo_id
        WHERE i.torneo_id = ? AND i.club_id = ? AND i.estado = 'activa'
    ");
    $stmt->execute([$torneo_id, $club_id]);
    $invitation = $stmt->fetch();
    
    if (!$invitation) {
        http_response_code(404);
        die('Invitación no encontrada o inactiva');
    }
    
    // Verificar vigencia de la invitación
    $today = date('Y-m-d');
    if ($today < $invitation['acceso1'] || $today > $invitation['acceso2']) {
        die('La invitación no está vigente. Válida del ' . $invitation['acceso1'] . ' al ' . $invitation['acceso2']);
    }
    
    // Verificar que el torneo existe y está activo
    $stmt = DB::pdo()->prepare("SELECT * FROM tournaments WHERE id = ? AND estatus = 1");
    $stmt->execute([$torneo_id]);
    $tournament = $stmt->fetch();
    
    if (!$tournament) {
        http_response_code(404);
        die('Torneo no encontrado o inactivo');
    }
    
    // Verificar que el club existe y está activo
    $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id = ? AND estatus = 1");
    $stmt->execute([$club_id]);
    $club = $stmt->fetch();
    
    if (!$club) {
        http_response_code(404);
        die('Club no encontrado o inactivo');
    }
    
    // Limpiar cualquier sesión previa para evitar conflictos
    Auth::logout();
    
    // Redirigir al formulario de registro de invitación standalone
    $standalone_url = AppHelpers::url("invitation_register_standalone.php?torneo=") . urlencode($torneo_id) . "&club=" . urlencode($club_id);
    
    // Si hay token, agregarlo también
    if (!empty($token)) {
        $standalone_url .= "&token=" . urlencode($token);
    }
    
    header('Location: ' . $standalone_url);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    die('Error interno del servidor');
}

// La función app_base_url() ya está definida en bootstrap.php
?>
