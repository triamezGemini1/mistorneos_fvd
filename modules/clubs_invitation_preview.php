<?php
/**
 * Vista Previa de Invitaci?n con Opciones de Env?o por WhatsApp
 * Generada desde el m?dulo de Clubes
 */



require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Verificar autenticaci?n
Auth::requireRole(['admin_general', 'admin_torneo']);

if (!isset($_GET['id'])) {
    die('ID de invitaci?n no proporcionado');
}

$invitation_id = (int)$_GET['id'];
$pdf_url = '';
$pdf_generated = false;

try {
    $pdo = DB::pdo();
    
    // Obtener datos completos de la invitaci?n
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.club_responsable,
            t.lugar as torneo_lugar,
            t.costo as torneo_costo,
            t.modalidad as torneo_modalidad,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            c.email as club_email,
            c.direccion as club_direccion,
            c.logo as club_logo,
            org.nombre as organizacion_nombre,
            org.logo as organizacion_logo
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        LEFT JOIN clubes org ON t.club_responsable = org.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invitation_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inv) {
        die('Invitaci?n no encontrada');
    }
    
    // Construir URLs del sistema
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script_name = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script_name));
    if ($base_path === '/') {
        $base_path = '';
    }
    
    $url_sistema = $protocol . '://' . $host . $base_path . '/';
    
    // Buscar el PDF generado
    $pdf_pattern = __DIR__ . '/../upload/pdfs/invitacion_' . $inv['torneo_id'] . '_' . $inv['club_id'] . '_*.pdf';
    $pdf_files = glob($pdf_pattern);
    
    if (!empty($pdf_files)) {
        // Ordenar por fecha de modificaci?n (m?s reciente primero)
        usort($pdf_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $pdf_filename = basename($pdf_files[0]);
        $pdf_url = $url_sistema . 'upload/pdfs/' . $pdf_filename;
        $pdf_generated = true;
    }
    
    // Preparar datos para el mensaje de WhatsApp
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $organizacion = !empty($inv['organizacion_nombre']) ? $inv['organizacion_nombre'] : 'Organizaci?n';
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // Mensaje para WhatsApp
    $separador = "??????????????????????";
    
    $mensaje = "• *INVITACI?N A TORNEO*\n\n";
    $mensaje .= $separador . "\n\n";
    $mensaje .= "*" . $inv['torneo_nombre'] . "*\n\n";
    $mensaje .= "• *Fecha:* " . $fecha_torneo . "\n";
    $mensaje .= "• *Club Invitado:* " . $inv['club_nombre'] . "\n";
    $mensaje .= "• *Delegado:* " . $delegado . "\n";
    $mensaje .= "? *Vigencia:* " . $vigencia . "\n\n";
    $mensaje .= $separador . "\n\n";
    $mensaje .= "• *Descargue su invitaci?n completa con toda la informaci?n aqu?:*\n";
    $mensaje .= $pdf_url . "\n\n";
    $mensaje .= "El documento PDF incluye:\n";
    $mensaje .= "? Logos de ambos clubes\n";
    $mensaje .= "? Informaci?n completa del torneo\n";
    $mensaje .= "? Credenciales de acceso al sistema\n";
    $mensaje .= "? Archivos adjuntos (normas, afiches)\n";
    $mensaje .= "? Datos de contacto\n\n";
    $mensaje .= "_" . $organizacion . "_";
    
    // Limpiar y formatear tel?fono
    $telefono = '';
    $whatsapp_url = '';
    $tiene_telefono = false;
    
    if (!empty($inv['club_telefono'])) {
        $telefono = preg_replace('/[^0-9]/', '', $inv['club_telefono']);
        if (!str_starts_with($telefono, '58')) {
            $telefono = '58' . $telefono;
        }
        $mensaje_encoded = urlencode($mensaje);
        $whatsapp_url = "https://wa.me/" . $telefono . "?text=" . $mensaje_encoded;
        $tiene_telefono = true;
    }
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitaci?n Generada - WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            max-width: 800px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .success-icon {
            font-size: 80px;
            color: #28a745;
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        .btn-whatsapp {
            background: #25D366;
            color: white;
            padding: 18px 35px;
            font-size: 20px;
            border: none;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .btn-whatsapp:hover {
            background: #128C7E;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(37, 211, 102, 0.4);
        }
        .preview-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            border: 2px solid #dee2e6;
            max-height: 300px;
            overflow-y: auto;
        }
        .info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
        }
        .logo-container {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        .logo-item {
            text-align: center;
        }
        .logo-item img {
            max-width: 120px;
            max-height: 120px;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .logo-item .logo-label {
            margin-top: 10px;
            font-weight: bold;
            color: #495057;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <div class="text-center">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <h2 class="mt-3 mb-1">?Invitaci?n Generada Exitosamente!</h2>
            <p class="text-muted">PDF creado con logos de ambos clubes</p>
        </div>
        
        <?php if ($inv['club_logo'] || $inv['organizacion_logo']): ?>
        <div class="logo-container">
            <?php if ($inv['organizacion_logo']): ?>
            <div class="logo-item">
                <img src="../<?= htmlspecialchars($inv['organizacion_logo']) ?>" alt="Club Organizador">
                <div class="logo-label">Club Organizador</div>
            </div>
            <?php endif; ?>
            
            <?php if ($inv['club_logo']): ?>
            <div class="logo-item">
                <img src="../<?= htmlspecialchars($inv['club_logo']) ?>" alt="Club Invitado">
                <div class="logo-label">Club Invitado</div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="info-card">
            <h5><i class="fas fa-trophy me-2"></i><?= htmlspecialchars($inv['torneo_nombre']) ?></h5>
            <div class="row mt-3">
                <div class="col-md-6">
                    <p class="mb-1"><strong>?? Fecha:</strong> <?= $fecha_torneo ?></p>
                    <p class="mb-1"><strong>?? Club:</strong> <?= htmlspecialchars($inv['club_nombre']) ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>?? Delegado:</strong> <?= htmlspecialchars($delegado) ?></p>
                    <p class="mb-1"><strong>?? Tel?fono:</strong> <?= htmlspecialchars($inv['club_telefono'] ?: 'No configurado') ?></p>
                </div>
            </div>
        </div>
        
        <?php if ($pdf_generated): ?>
        <div class="alert alert-success">
            <h6><i class="fas fa-file-pdf me-2"></i>PDF Generado Correctamente</h6>
            <p class="mb-0">El PDF incluye los logos del club organizador y del club invitado</p>
        </div>
        
        <div class="preview-box">
            <h6><strong>Vista previa del mensaje de WhatsApp:</strong></h6>
            <pre style="white-space: pre-wrap; font-size: 13px; margin: 10px 0; line-height: 1.6;"><?= htmlspecialchars($mensaje) ?></pre>
        </div>
        
        <div class="alert alert-info">
            <h6><i class="fas fa-info-circle me-2"></i>Qu? incluye el PDF:</h6>
            <ul class="mb-0">
                <li>? Logo del club organizador (encabezado izquierdo)</li>
                <li>? Logo del club invitado (encabezado derecho)</li>
                <li>? Informaci?n completa del torneo</li>
                <li>? Credenciales de acceso al sistema</li>
                <li>? Archivos adjuntos (invitaci?n, normas, afiche)</li>
                <li>? Datos de contacto del organizador</li>
            </ul>
        </div>
        
        <div class="text-center mt-4">
            <?php if ($tiene_telefono): ?>
            <a href="<?= htmlspecialchars($whatsapp_url) ?>" class="btn btn-whatsapp btn-lg mb-3 w-100">
                <i class="fab fa-whatsapp fa-lg me-2"></i>Enviar por WhatsApp
            </a>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                El club no tiene tel?fono configurado. No se puede enviar por WhatsApp.
            </div>
            <?php endif; ?>
            
            <a href="<?= htmlspecialchars($pdf_url) ?>" class="btn btn-primary mb-3 w-100">
                <i class="fas fa-file-pdf me-2"></i>Ver PDF Completo
            </a>
            
            <a href="<?= htmlspecialchars($pdf_url) ?>" class="btn btn-secondary mb-3 w-100" download>
                <i class="fas fa-download me-2"></i>Descargar PDF
            </a>
            
            <button onclick="window.close()" class="btn btn-outline-secondary w-100">
                <i class="fas fa-times me-2"></i>Cerrar Ventana
            </button>
        </div>
        
        <?php if ($tiene_telefono): ?>
        <div class="alert alert-warning mt-4">
            <h6><i class="fas fa-exclamation-triangle me-2"></i>Importante:</h6>
            <p class="mb-1">1. Al hacer clic en "Enviar por WhatsApp" se abrir? la aplicaci?n</p>
            <p class="mb-1">2. El mensaje incluye un enlace directo al PDF</p>
            <p class="mb-0">3. El delegado podr? descargar el PDF con todos los detalles</p>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="alert alert-danger">
            <h6><i class="fas fa-exclamation-triangle me-2"></i>Error</h6>
            <p class="mb-0">No se pudo encontrar el PDF generado. Por favor, intente nuevamente.</p>
        </div>
        
        <div class="text-center mt-3">
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="fas fa-times me-2"></i>Cerrar Ventana
            </button>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>

