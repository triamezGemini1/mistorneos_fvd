<?php
/**
 * Enviar Invitación por WhatsApp para Afiliarse a un Club
 * Genera un mensaje con link de registro directo al club
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_club']);

try {
    $club_id = (int)($_GET['club_id'] ?? 0);
    $phone = trim($_GET['phone'] ?? '');
    
    if ($club_id <= 0) {
        throw new Exception('ID de club inválido');
    }
    
    if (empty($phone)) {
        throw new Exception('Número de teléfono requerido');
    }
    
    $current_user = Auth::user();
    
    // Verificar que el club pertenece a la organización del admin_club
    if ($current_user['club_id'] != $club_id) {
        $stmt = DB::pdo()->prepare("
            SELECT COUNT(*) FROM clubes 
            WHERE id = ? AND organizacion_id = (SELECT id FROM organizaciones WHERE admin_user_id = ? LIMIT 1)
        ");
        $stmt->execute([$club_id, $current_user['id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new Exception('No tienes permiso para invitar a este club');
        }
    }
    
    $pdo = DB::pdo();
    
    // Obtener información del club
    $stmt = $pdo->prepare("SELECT * FROM clubes WHERE id = ? AND estatus = 1");
    $stmt->execute([$club_id]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$club) {
        throw new Exception('Club no encontrado o inactivo');
    }
    
    // Obtener información del admin
    $admin_nombre = $current_user['nombre'] ?? $current_user['username'] ?? 'Administrador';
    
    // Formatear teléfono
    $telefono = preg_replace('/[^0-9]/', '', $phone);
    if ($telefono && $telefono[0] == '0') {
        $telefono = substr($telefono, 1);
    }
    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
        $telefono = '58' . $telefono;
    }
    
    if (strlen($telefono) < 10) {
        throw new Exception('Número de teléfono inválido');
    }
    
    // Generar URL de registro con club_id
    $app_url = $_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd';
    $register_url = AppHelpers::url("register_by_club.php?club_id=") . $club_id;
    
    // Generar mensaje de invitación
    $mensaje = "🎉 *¡INVITACIÓN A AFILIARTE!*\n\n";
    $mensaje .= "Hola, soy *" . $admin_nombre . "*\n\n";
    $mensaje .= "Te invito a formar parte de nuestro club de dominó.\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "🏢 *INFORMACIÓN DEL CLUB*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "📍 *Nombre:* " . $club['nombre'] . "\n";
    if ($club['delegado']) {
        $mensaje .= "👤 *Delegado:* " . $club['delegado'] . "\n";
    }
    if ($club['telefono']) {
        $mensaje .= "📞 *Teléfono:* " . $club['telefono'] . "\n";
    }
    if ($club['direccion']) {
        $mensaje .= "📍 *Dirección:* " . $club['direccion'] . "\n";
    }
    $mensaje .= "\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "✨ *BENEFICIOS DE AFILIARTE*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "✅ Participar en torneos organizados\n";
    $mensaje .= "✅ Acceso a estadísticas y resultados\n";
    $mensaje .= "✅ Formar parte de nuestra comunidad\n";
    $mensaje .= "✅ Invitar a más amigos\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "🔗 *REGÍSTRATE AQUÍ*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "Haz clic en el siguiente enlace para completar tu registro:\n\n";
    $mensaje .= $register_url . "\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "¡Esperamos contar contigo! 🎲\n\n";
    $mensaje .= "_La Estación del Dominó_";
    
    // Generar URL de WhatsApp
    $mensaje_encoded = urlencode($mensaje);
    $whatsapp_url = "https://wa.me/{$telefono}?text={$mensaje_encoded}";
    
    // Redirigir automáticamente a WhatsApp Web
    header("Location: " . $whatsapp_url);
    exit;
    
} catch (Exception $e) {
    // Si hay error, mostrar mensaje y redirigir de vuelta
    $_SESSION['error_message'] = 'Error al enviar invitación: ' . $e->getMessage();
    header("Location: ?page=home");
    exit;
}
?>





