<?php
/**
 * Env?o por WhatsApp con PDF - Compatible con Sistema de Routing
 */



// Cargar autoload ANTES de usar las clases
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Este archivo se incluye desde public/index.php
// No necesita require de bootstrap, auth, etc. porque ya est?n cargados

if (!isset($_GET['id'])) {
    die('ID no proporcionado');
}

$id = (int)$_GET['id'];
$pdf_url = '';
$pdf_generated = false;

try {
    $pdo = DB::pdo();
    
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
        die('Invitaci?n no encontrada');
    }
    
    if (empty($inv['club_telefono'])) {
        die('El club no tiene tel?fono configurado');
    }
    
    // URLs del sistema - DETECTAR AUTOM?TICAMENTE
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // Detectar si estamos en subcarpeta
    $script_name = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script_name)); // Sube dos niveles desde public/index.php
    if ($base_path === '/') {
        $base_path = '';
    }
    
    $url_sistema = $protocol . '://' . $host . $base_path . '/';
    $url_login = $url_sistema . 'modules/invitations/inscripciones/login.php';
    
    // Generar PDF usando Dompdf
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $organizacion = !empty($inv['organizacion_nombre']) ? $inv['organizacion_nombre'] : 'Organizaci?n';
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // HTML del PDF (versi?n compacta)
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body{font-family:Arial;margin:30px;color:#333}
    .header{text-align:center;background:#667eea;color:white;padding:20px;border-radius:8px;margin-bottom:20px}
    .section{margin:15px 0;padding:15px;border:2px solid #ddd;border-radius:5px;background:#f9f9f9}
    .section h2{color:#667eea;margin-top:0;font-size:18px;border-bottom:2px solid #667eea;padding-bottom:8px}
    .info{margin:8px 0;padding:8px;background:white;border-radius:4px}
    .label{font-weight:bold;color:#555}
    .token-box{background:#fff3cd;border:3px solid #ff6b6b;padding:15px;border-radius:8px;margin:15px 0;text-align:center}
    .token{font-family:monospace;font-size:14px;font-weight:bold;background:white;padding:12px;border-radius:4px;word-break:break-all;color:#d63031}
    </style></head><body>';
    
    $html .= '<div class="header"><h1>?? INVITACI?N A TORNEO</h1><p>' . htmlspecialchars($organizacion) . '</p></div>';
    
    $html .= '<div class="section"><h2>?? TORNEO</h2>';
    $html .= '<div class="info"><span class="label">Torneo:</span> ' . htmlspecialchars($inv['torneo_nombre']) . '</div>';
    $html .= '<div class="info"><span class="label">Fecha:</span> ' . htmlspecialchars($fecha_torneo) . '</div>';
    if (!empty($inv['torneo_lugar'])) {
        $html .= '<div class="info"><span class="label">Lugar:</span> ' . htmlspecialchars($inv['torneo_lugar']) . '</div>';
    }
    $html .= '</div>';
    
    $html .= '<div class="section"><h2>?? CLUB INVITADO</h2>';
    $html .= '<div class="info"><span class="label">Club:</span> ' . htmlspecialchars($inv['club_nombre']) . '</div>';
    $html .= '<div class="info"><span class="label">Delegado:</span> ' . htmlspecialchars($delegado) . '</div>';
    $html .= '<div class="info"><span class="label">Vigencia:</span> ' . htmlspecialchars($vigencia) . '</div>';
    $html .= '</div>';
    
    $html .= '<div class="token-box"><h3 style="color:#856404;margin-top:0">?? TOKEN DE ACCESO</h3>';
    $html .= '<p style="margin:8px 0">Copie este TOKEN para acceder al sistema:</p>';
    $html .= '<div class="token">' . htmlspecialchars($inv['token']) . '</div></div>';
    
    $html .= '<div class="section"><h2>?? INSTRUCCIONES</h2>';
    $html .= '<ol><li>Vaya a: ' . htmlspecialchars($url_login) . '</li>';
    $html .= '<li>Ingrese el TOKEN que aparece arriba</li>';
    $html .= '<li>Inscriba a sus jugadores</li></ol></div>';
    
    $html .= '</body></html>';
    
    // Generar PDF con Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Guardar PDF
    $pdf_dir = __DIR__ . '/../../upload/pdfs/';
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0755, true);
    }
    
    $pdf_filename = 'invitacion_' . $id . '_' . time() . '.pdf';
    $pdf_path = $pdf_dir . $pdf_filename;
    file_put_contents($pdf_path, $dompdf->output());
    
    $pdf_url = $url_sistema . 'upload/pdfs/' . $pdf_filename;
    $pdf_generated = true;
    
    // Mensaje corto para WhatsApp
    $mensaje = "• *INVITACI?N AL TORNEO*\n\n";
    $mensaje .= "*" . $inv['torneo_nombre'] . "*\n\n";
    $mensaje .= "• Fecha: " . $fecha_torneo . "\n";
    $mensaje .= "• Club: " . $inv['club_nombre'] . "\n\n";
    $mensaje .= "• *Descargue su invitaci?n completa aqu?:*\n";
    $mensaje .= $pdf_url . "\n\n";
    $mensaje .= "El documento incluye:\n";
    $mensaje .= "? TOKEN de acceso\n";
    $mensaje .= "? URL del sistema\n";
    $mensaje .= "? Instrucciones completas\n\n";
    $mensaje .= "_" . $organizacion . "_";
    
    // Limpiar tel?fono
    $telefono = preg_replace('/[^0-9]/', '', $inv['club_telefono']);
    if (!str_starts_with($telefono, '58')) {
        $telefono = '58' . $telefono;
    }
    
    // URL de WhatsApp
    $mensaje_encoded = urlencode($mensaje);
    $whatsapp_url = "https://wa.me/" . $telefono . "?text=" . $mensaje_encoded;
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Enviar por WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container-custom {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .success-icon {
            font-size: 60px;
            color: #25D366;
        }
        .btn-whatsapp {
            background: #25D366;
            color: white;
            padding: 15px 30px;
            font-size: 18px;
            border: none;
            border-radius: 8px;
        }
        .btn-whatsapp:hover {
            background: #128C7E;
            color: white;
        }
        .preview-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border: 2px solid #dee2e6;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <div class="text-center">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <h2 class="mt-3">?PDF Generado!</h2>
        </div>
        
        <div class="alert alert-success mt-4">
            <h5><i class="fas fa-file-pdf"></i> Invitaci?n Creada</h5>
            <p class="mb-0"><strong>Club:</strong> <?= htmlspecialchars($inv['club_nombre']) ?></p>
            <p class="mb-0"><strong>Torneo:</strong> <?= htmlspecialchars($inv['torneo_nombre']) ?></p>
            <p class="mb-0"><strong>Tel?fono:</strong> <?= htmlspecialchars($inv['club_telefono']) ?></p>
        </div>
        
        <div class="preview-box">
            <h6><strong>Vista previa del mensaje:</strong></h6>
            <pre style="white-space: pre-wrap; font-size: 12px; margin: 10px 0;"><?= htmlspecialchars($mensaje) ?></pre>
        </div>
        
        <div class="alert alert-info">
            <h6><i class="fas fa-info-circle"></i> Qu? incluye el PDF:</h6>
            <ul class="mb-0">
                <li>? Informaci?n completa del torneo</li>
                <li>? TOKEN de acceso (64 caracteres)</li>
                <li>? URL del sistema</li>
                <li>? Instrucciones paso a paso</li>
            </ul>
        </div>
        
        <div class="text-center mt-4">
            <a href="<?= htmlspecialchars($whatsapp_url) ?>" class="btn btn-whatsapp btn-lg mb-3 w-100">
                <i class="fab fa-whatsapp"></i> Abrir WhatsApp
            </a>
            
            <a href="<?= htmlspecialchars($pdf_url) ?>" class="btn btn-secondary mb-3 w-100">
                <i class="fas fa-file-pdf"></i> Ver PDF
            </a>
            
            <a href="index.php?page=invitations" class="btn btn-outline-secondary w-100">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
        
        <div class="alert alert-warning mt-4">
            <h6><i class="fas fa-exclamation-triangle"></i> Importante:</h6>
            <p class="mb-1">1. Se abrir? WhatsApp con un mensaje CORTO</p>
            <p class="mb-1">2. El mensaje incluye enlace al PDF con toda la informaci?n</p>
            <p class="mb-0">3. El delegado descarga el PDF y all? encuentra el TOKEN</p>
        </div>
    </div>
    
    <script>
        // Abrir WhatsApp autom?ticamente
        setTimeout(function() {
            window.location.href = '<?= addslashes($whatsapp_url) ?>';
        }, 1500);
    </script>
</body>
</html>
