<?php
/**
 * Envío ULTRA SIMPLE de WhatsApp - Un solo archivo
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_general','admin_torneo']);

if (!isset($_GET['id'])) {
    die('ID no proporcionado');
}

$id = (int)$_GET['id'];

try {
    $pdo = DB::pdo();
    
    // Obtener datos de la invitación
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
            org.nombre as organizacion_nombre
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        LEFT JOIN clubes org ON t.club_responsable = org.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inv) {
        die('Invitación no encontrada');
    }
    
    if (empty($inv['club_telefono'])) {
        die('El club no tiene teléfono configurado');
    }
    
    // URLs
    $url_sistema = rtrim(FvdConfig::resolvePublicUrl(), '/') . '/';
    $url_login = $url_sistema . 'modules/invitations/inscripciones/login.php';
    
    // Datos
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $telefono = $inv['club_telefono'];
    $organizacion = !empty($inv['organizacion_nombre']) ? $inv['organizacion_nombre'] : 'Organización';
    
    // Formatear fechas
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // Construir mensaje
    $separador = "??????????????????????";
    
    $mensaje = "• *INVITACIÓN A TORNEO - " . strtoupper($organizacion) . "*\n\n";
    $mensaje .= $separador . "\n\n";
    $mensaje .= "• *INFORMACIÓN DEL TORNEO*\n\n";
    $mensaje .= "• *Organización:* " . $organizacion . "\n";
    $mensaje .= "• *Torneo:* " . $inv['torneo_nombre'] . "\n";
    $mensaje .= "• *Fecha:* " . $fecha_torneo . "\n";
    if (!empty($inv['torneo_lugar'])) {
        $mensaje .= "• *Lugar:* " . $inv['torneo_lugar'] . "\n";
    }
    $mensaje .= "\n" . $separador . "\n\n";
    $mensaje .= "• *CLUB INVITADO*\n\n";
    $mensaje .= "• *Club:* " . $inv['club_nombre'] . "\n";
    $mensaje .= "• *Delegado:* " . $delegado . "\n";
    $mensaje .= "• *Teléfono:* " . $telefono . "\n";
    $mensaje .= "\n" . $separador . "\n\n";
    $mensaje .= "• *VIGENCIA*\n\n";
    $mensaje .= "• *Periodo:* " . $vigencia . "\n";
    $mensaje .= "• *Estado:* " . strtoupper($inv['estado']) . "\n";
    $mensaje .= "\n" . $separador . "\n\n";
    $mensaje .= "• *CREDENCIALES DE ACCESO*\n\n";
    $mensaje .= "• *¡INFORMACIÓN IMPORTANTE!* ??\n\n";
    $mensaje .= "• *URL:*\n" . $url_login . "\n\n";
    $mensaje .= "• *TOKEN:*\n*" . $inv['token'] . "*\n\n";
    $mensaje .= "• *INSTRUCCIONES:*\n";
    $mensaje .= "1?? Copie el TOKEN\n";
    $mensaje .= "2?? Entre a la URL\n";
    $mensaje .= "3?? Pegue su TOKEN\n";
    $mensaje .= "4?? Inscriba jugadores\n\n";
    $mensaje .= "• *GUARDE ESTE TOKEN*\n";
    $mensaje .= "\n" . $separador . "\n\n";
    $mensaje .= "• *CONTACTO " . strtoupper($organizacion) . "*\n\n";
    $mensaje .= "¡Esperamos su participación!\n\n";
    $mensaje .= "_" . $organizacion . "_";
    
    // Limpiar teléfono
    $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
    if (!str_starts_with($telefono_limpio, '58')) {
        $telefono_limpio = '58' . $telefono_limpio;
    }
    
    // URL de WhatsApp
    $mensaje_encoded = urlencode($mensaje);
    $whatsapp_url = "https://api.whatsapp.com/send?phone={$telefono_limpio}&text={$mensaje_encoded}";
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Enviando WhatsApp...</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
        }
        .container {
            background: white;
            color: #333;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            margin: 0 auto;
        }
        .success { color: #25D366; font-size: 48px; }
        .btn {
            background: #25D366;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin: 10px;
            font-size: 16px;
        }
        .btn:hover { background: #128C7E; }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">?</div>
        <h2>�Mensaje Listo!</h2>
        <p><strong>Club:</strong> <?= htmlspecialchars($inv['club_nombre']) ?></p>
        <p><strong>Teléfono:</strong> <?= htmlspecialchars($telefono) ?></p>
        
        <a href="<?= htmlspecialchars($whatsapp_url) ?>" class="btn">
            ?? Abrir WhatsApp
        </a>
        
        <br><br>
        
        <a href="index.php" class="btn btn-secondary">
            ? Volver
        </a>
        
        <div style="margin-top: 20px; padding: 20px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">
            <h4 style="color: #856404; margin-top: 0;">?? IMPORTANTE - �LTIMO PASO:</h4>
            <ol style="color: #856404; text-align: left; margin: 10px 0;">
                <li>WhatsApp se abrió con el mensaje <strong>PRE-CARGADO</strong></li>
                <li>Ve a la ventana/pestaña de WhatsApp</li>
                <li><strong>Haz clic en el botón "Enviar" ? de WhatsApp</strong></li>
            </ol>
            <p style="color: #856404; margin: 10px 0;">
                <strong>Nota:</strong> WhatsApp NO permite envío automático por seguridad.<br>
                <strong>DEBES hacer clic manualmente</strong> en "Enviar" en WhatsApp.
            </p>
        </div>
    </div>
    
    <script>
        // Abrir WhatsApp automáticamente después de 1 segundo
        setTimeout(function() {
            window.location.href = '<?= addslashes($whatsapp_url) ?>';
        }, 1000);
    </script>
</body>
</html>

