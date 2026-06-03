<?php
/**
 * Generar PDF de Invitación
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

Auth::requireRole(['admin_general','admin_torneo']);

if (!isset($_GET['id'])) {
    die('ID no proporcionado');
}

$id = (int)$_GET['id'];

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
        die('Invitación no encontrada');
    }
    
    // URLs
    $url_sistema = rtrim(FvdConfig::resolvePublicUrl(), '/') . '/';
    $url_login = $url_sistema . 'modules/invitations/inscripciones/login.php';
    
    // Datos
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $organizacion = !empty($inv['organizacion_nombre']) ? $inv['organizacion_nombre'] : 'Organización';
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // HTML del PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 40px;
                color: #333;
            }
            .header {
                text-align: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 30px;
                border-radius: 10px;
                margin-bottom: 30px;
            }
            .header h1 {
                margin: 0;
                font-size: 28px;
            }
            .section {
                margin: 25px 0;
                padding: 20px;
                border: 2px solid #dee2e6;
                border-radius: 8px;
                background: #f8f9fa;
            }
            .section h2 {
                color: #667eea;
                margin-top: 0;
                font-size: 20px;
                border-bottom: 2px solid #667eea;
                padding-bottom: 10px;
            }
            .info-row {
                margin: 10px 0;
                padding: 8px;
                background: white;
                border-radius: 5px;
            }
            .label {
                font-weight: bold;
                color: #555;
            }
            .token-box {
                background: #fff3cd;
                border: 3px solid #ff6b6b;
                padding: 20px;
                border-radius: 10px;
                margin: 20px 0;
                text-align: center;
            }
            .token-box h3 {
                color: #856404;
                margin-top: 0;
            }
            .token {
                font-family: "Courier New", monospace;
                font-size: 16px;
                font-weight: bold;
                background: white;
                padding: 15px;
                border-radius: 5px;
                word-break: break-all;
                color: #d63031;
            }
            .instructions {
                background: #d1ecf1;
                border: 2px solid #0c5460;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
            .instructions h3 {
                color: #0c5460;
                margin-top: 0;
            }
            .instructions ol {
                margin: 10px 0;
                padding-left: 20px;
            }
            .instructions li {
                margin: 8px 0;
                line-height: 1.6;
            }
            .url-box {
                background: #e7f3ff;
                border: 2px solid #004085;
                padding: 15px;
                border-radius: 8px;
                margin: 15px 0;
            }
            .url {
                font-family: "Courier New", monospace;
                color: #0056b3;
                word-break: break-all;
            }
            .footer {
                text-align: center;
                margin-top: 40px;
                padding: 20px;
                border-top: 2px solid #dee2e6;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>?? INVITACIÓN A TORNEO</h1>
            <p style="margin: 10px 0 0 0; font-size: 18px;">' . htmlspecialchars($organizacion) . '</p>
        </div>
        
        <div class="section">
            <h2>?? INFORMACIÓN DEL TORNEO</h2>
            <div class="info-row">
                <span class="label">Organización Responsable:</span> ' . htmlspecialchars($organizacion) . '
            </div>
            <div class="info-row">
                <span class="label">Nombre del Torneo:</span> ' . htmlspecialchars($inv['torneo_nombre']) . '
            </div>
            <div class="info-row">
                <span class="label">Fecha del Torneo:</span> ' . htmlspecialchars($fecha_torneo) . '
            </div>';
    
    if (!empty($inv['torneo_lugar'])) {
        $html .= '
            <div class="info-row">
                <span class="label">Lugar:</span> ' . htmlspecialchars($inv['torneo_lugar']) . '
            </div>';
    }
    
    $html .= '
        </div>
        
        <div class="section">
            <h2>?? CLUB INVITADO</h2>
            <div class="info-row">
                <span class="label">Nombre del Club:</span> ' . htmlspecialchars($inv['club_nombre']) . '
            </div>
            <div class="info-row">
                <span class="label">Delegado:</span> ' . htmlspecialchars($delegado) . '
            </div>
            <div class="info-row">
                <span class="label">Teléfono:</span> ' . htmlspecialchars($inv['club_telefono']) . '
            </div>';
    
    if (!empty($inv['club_email'])) {
        $html .= '
            <div class="info-row">
                <span class="label">Email:</span> ' . htmlspecialchars($inv['club_email']) . '
            </div>';
    }
    
    if (!empty($inv['club_direccion'])) {
        $html .= '
            <div class="info-row">
                <span class="label">Dirección:</span> ' . htmlspecialchars($inv['club_direccion']) . '
            </div>';
    }
    
    $html .= '
        </div>
        
        <div class="section">
            <h2>?? VIGENCIA DE LA INVITACIÓN</h2>
            <div class="info-row">
                <span class="label">Periodo de Acceso:</span> ' . htmlspecialchars($vigencia) . '
            </div>
            <div class="info-row">
                <span class="label">Estado:</span> ' . htmlspecialchars(strtoupper($inv['estado'])) . '
            </div>
        </div>
        
        <div class="token-box">
            <h3>?? TOKEN DE ACCESO</h3>
            <p style="margin: 10px 0; font-size: 14px;">?? GUARDE ESTE TOKEN - Lo necesitará para inscribir jugadores</p>
            <div class="token">' . htmlspecialchars($inv['token']) . '</div>
        </div>
        
        <div class="url-box">
            <h3 style="color: #004085; margin-top: 0;">?? URL DE ACCESO</h3>
            <div class="url">' . htmlspecialchars($url_login) . '</div>
        </div>
        
        <div class="instructions">
            <h3>?? INSTRUCCIONES PARA INSCRIBIR JUGADORES</h3>
            <ol>
                <li><strong>Copie el TOKEN</strong> que aparece arriba (todo el texto)</li>
                <li><strong>Abra la URL de acceso</strong> en su navegador</li>
                <li><strong>Pegue su TOKEN</strong> en el formulario de login</li>
                <li><strong>Una vez dentro,</strong> podrá inscribir a sus jugadores por cédula</li>
            </ol>
            <p style="margin: 15px 0 0 0; font-weight: bold; color: #856404;">
                ?? IMPORTANTE: Guarde este documento. Necesitar• el TOKEN cada vez que acceda al sistema.
            </p>
        </div>
        
        <div class="footer">
            <p><strong>Contacto:</strong> ' . htmlspecialchars($organizacion) . '</p>
            <p style="margin: 5px 0;">¡Esperamos contar con su participación!</p>
            <p style="margin: 15px 0 0 0; font-size: 12px; color: #999;">
                Documento generado el ' . date('d/m/Y H:i:s') . '
            </p>
        </div>
    </body>
    </html>';
    
    // Configurar Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', true);
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
    
    // Retornar el PDF al navegador
    $dompdf->stream($pdf_filename, array('Attachment' => 0));
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}







