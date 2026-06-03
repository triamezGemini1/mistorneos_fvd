<?php
/**
 * Envío Directo por WhatsApp - Sin Página Intermedia
 * Genera JSON con el mensaje y URL para envío automático
 * Adaptado del sistema invitorfvd para mistorneos
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

// Solo aceptar peticiones AJAX
header('Content-Type: application/json; charset=utf-8');

// Verificar autenticación
Auth::requireRole(['admin_general','admin_torneo']);

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID no proporcionado']);
    exit;
}

$id = (int)$_GET['id'];

try {
    $pdo = DB::pdo();
    
    // Obtener datos completos de la invitación
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.club_responsable,
            t.lugar as torneo_lugar,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            c.email as club_email,
            c.direccion as club_direccion,
            org.nombre as organizacion_nombre,
            org.delegado as organizacion_delegado
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        LEFT JOIN clubes org ON t.club_responsable = org.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inv) {
        echo json_encode(['error' => 'Invitación no encontrada']);
        exit;
    }
    
    // Verificar que el club tenga teléfono
    if (empty($inv['club_telefono'])) {
        echo json_encode([
            'error' => 'El club no tiene teléfono configurado',
            'club_id' => $inv['club_id'],
            'club_nombre' => $inv['club_nombre']
        ]);
        exit;
    }
    
    // URLs del sistema
    $url_sistema = rtrim(FvdConfig::resolvePublicUrl(), '/') . '/';
    $url_login = $url_sistema . 'modules/invitations/inscripciones/login.php';
    
    // Datos
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $telefono = $inv['club_telefono'];
    $organizacion = !empty($inv['organizacion_nombre']) ? $inv['organizacion_nombre'] : 'Organización';
    
    // Formatear fechas
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // Construir mensaje de WhatsApp
    $separador = "??????????????????????";
    
    $mensaje = "• *INVITACIÓN A TORNEO - " . strtoupper($organizacion) . "*\n\n";
    $mensaje .= $separador . "\n\n";
    
    // INFORMACIÓN DEL TORNEO
    $mensaje .= "• *INFORMACIÓN DEL TORNEO*\n\n";
    $mensaje .= "• *Organización Responsable:* " . $organizacion . "\n";
    $mensaje .= "• *Nombre del Torneo:* " . $inv['torneo_nombre'] . "\n";
    $mensaje .= "• *Fecha del Torneo:* " . $fecha_torneo . "\n";
    
    if (!empty($inv['torneo_lugar'])) {
        $mensaje .= "• *Lugar:* " . $inv['torneo_lugar'] . "\n";
    }
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // CLUB INVITADO
    $mensaje .= "• *CLUB INVITADO*\n\n";
    $mensaje .= "• *Nombre Club:* " . $inv['club_nombre'] . "\n";
    $mensaje .= "• *Delegado:* " . $delegado . "\n";
    $mensaje .= "• *Teléfono:* " . $telefono . "\n";
    
    if (!empty($inv['club_email'])) {
        $mensaje .= "• *Email:* " . $inv['club_email'] . "\n";
    }
    
    if (!empty($inv['club_direccion'])) {
        $mensaje .= "• *Dirección:* " . $inv['club_direccion'] . "\n";
    }
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // VIGENCIA DE LA INVITACIÓN
    $mensaje .= "• *VIGENCIA DE LA INVITACIÓN*\n\n";
    $mensaje .= "• *Periodo de Acceso:* " . $vigencia . "\n";
    $mensaje .= "• *Estado:* " . strtoupper($inv['estado']) . "\n";
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // ***** CREDENCIALES DE ACCESO *****
    $mensaje .= "• *CREDENCIALES PARA INSCRIPCIÓN DE JUGADORES*\n\n";
    $mensaje .= "• ?? ?? *¡INFORMACIÓN IMPORTANTE!* ⚠ ⚠ ⚠\n\n";
    $mensaje .= "Para inscribir a sus jugadores, utilice:\n\n";
    
    $mensaje .= "• *URL DE ACCESO:*\n";
    $mensaje .= $url_login . "\n\n";
    
    $mensaje .= "• *TOKEN DE ACCESO (Su Clave Personal):*\n";
    $mensaje .= "*" . $inv['token'] . "*\n\n";
    
    $mensaje .= "• *INSTRUCCIONES:*\n";
    $mensaje .= "1?? Copie el TOKEN completo (arriba)\n";
    $mensaje .= "2?? Entre a la URL de acceso\n";
    $mensaje .= "3?? Pegue su TOKEN en el formulario\n";
    $mensaje .= "4?? Inscriba a sus jugadores por cédula\n\n";
    
    $mensaje .= "• *GUARDE ESTE TOKEN - Lo necesitará cada vez que acceda*\n";
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // CONTACTO
    $mensaje .= "• *CONTACTO " . strtoupper($organizacion) . "*\n\n";
    $mensaje .= "¡Esperamos contar con su participación!\n\n";
    $mensaje .= "_" . $organizacion . "_";
    
    // Codificar mensaje para URL
    $mensaje_encoded = urlencode($mensaje);
    
    // Limpiar número de teléfono (solo números)
    $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
    
    // Agregar código de país si no lo tiene (Venezuela +58)
    if (!str_starts_with($telefono_limpio, '58')) {
        $telefono_limpio = '58' . $telefono_limpio;
    }
    
    // Generar URL de WhatsApp
    $whatsapp_url = "https://api.whatsapp.com/send?phone={$telefono_limpio}&text={$mensaje_encoded}";
    
    // Registrar envío en log
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'invitation_id' => $id,
        'club_nombre' => $inv['club_nombre'],
        'telefono' => $telefono_limpio,
        'torneo' => $inv['torneo_nombre'],
        'status' => 'GENERATED'
    ];
    
    $log_file = __DIR__ . '/../../logs/whatsapp_log.txt';
    $log_line = json_encode($log_entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    
    // Retornar JSON con toda la información
    echo json_encode([
        'success' => true,
        'mensaje' => $mensaje,
        'whatsapp_url' => $whatsapp_url,
        'telefono' => $telefono,
        'telefono_formateado' => $telefono_limpio,
        'delegado' => $delegado,
        'torneo' => $inv['torneo_nombre'],
        'club' => $inv['club_nombre'],
        'token' => $inv['token'],
        'url_login' => $url_login
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
