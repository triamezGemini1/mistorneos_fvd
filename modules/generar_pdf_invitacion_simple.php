<?php
/**
 * Generador Simple de PDF de Invitación
 * Versión optimizada para 1 página con archivos adjuntos
 */


require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Verificar autenticación
Auth::requireRole(['admin_general', 'admin_torneo']);

try {
    // Obtener ID de invitación
    $invitation_id = (int)($_GET['id'] ?? 0);
    
    if ($invitation_id <= 0) {
        throw new Exception('ID de invitación inválido');
    }
    
    // Obtener datos completos de la invitación
    $stmt = DB::pdo()->prepare("
        SELECT 
            i.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.costo as torneo_costo,
            t.clase as torneo_clase,
            t.modalidad as torneo_modalidad,
            t.invitacion as torneo_invitacion,
            t.normas as torneo_normas,
            t.afiche as torneo_afiche,
            ci.nombre as club_invitado_nombre,
            ci.delegado as club_invitado_delegado,
            ci.telefono as club_invitado_telefono,
            ci.logo as club_invitado_logo,
            cr.nombre as club_responsable_nombre,
            cr.delegado as club_responsable_delegado,
            cr.telefono as club_responsable_telefono,
            cr.logo as club_responsable_logo
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes ci ON i.club_id = ci.id
        LEFT JOIN clubes cr ON t.club_responsable = cr.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invitation_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inv) {
        throw new Exception('Invitación no encontrada');
    }
    
    // Generar URL de acceso directo
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // Obtener la ruta base del proyecto
    // Desde modules/generar_pdf_invitacion_simple.php necesitamos llegar a /
    $script_dir = dirname($_SERVER['SCRIPT_NAME']); // /mistorneos/modules
    $project_root = dirname($script_dir); // /mistorneos
    
    $login_url = $protocol . '://' . $host . $project_root . '/modules/invitations/inscripciones/login.php?token=' . $inv['token'];
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Función helper para labels
function getClaseLabel($clase) {
    $labels = [0 => 'No definido', 1 => 'Torneo', 2 => 'Campeonato'];
    return $labels[(int)$clase] ?? 'No definido';
}

function getModalidadLabel($modalidad) {
    $labels = [0 => 'No definido', 1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
    return $labels[(int)$modalidad] ?? 'No definido';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitación - <?= htmlspecialchars($inv['torneo_nombre']) ?></title>
    <style>
        @page { 
            margin: 1cm; 
            size: letter;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.2;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 16pt;
            margin: 5px 0;
        }
        .header p {
            font-size: 10pt;
            margin: 3px 0;
        }
        .logos {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 10px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            height: 80px;
        }
        .logo-box {
            text-align: center;
            flex: 1;
        }
        .logo-box img {
            max-width: 60px;
            max-height: 60px;
            object-fit: contain;
        }
        .logo-box p {
            margin-top: 3px;
            font-size: 7pt;
            font-weight: bold;
            color: #666;
        }
        .content {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .column {
            flex: 1;
        }
        .info-box {
            background: white;
            padding: 8px;
            margin-bottom: 8px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
        .info-box h3 {
            color: #667eea;
            font-size: 10pt;
            margin-bottom: 5px;
            border-bottom: 1px solid #667eea;
            padding-bottom: 3px;
        }
        .info-row {
            display: flex;
            margin-bottom: 4px;
            font-size: 8pt;
        }
        .info-label {
            font-weight: bold;
            width: 90px;
            color: #495057;
        }
        .info-value {
            flex: 1;
            color: #212529;
        }
        .token-box {
            background: #fff3cd;
            border: 2px dashed #ffc107;
            padding: 8px;
            margin: 8px 0;
            border-radius: 5px;
            text-align: center;
        }
        .token-box h4 {
            font-size: 9pt;
            color: #856404;
            margin-bottom: 4px;
        }
        .token {
            font-family: 'Courier New', monospace;
            font-size: 7pt;
            font-weight: bold;
            color: #856404;
            background: white;
            padding: 5px;
            border-radius: 3px;
            word-break: break-all;
        }
        .access-box {
            background: #d1ecf1;
            border: 1px solid #0c5460;
            padding: 8px;
            border-radius: 5px;
            margin-bottom: 8px;
        }
        .access-box h4 {
            font-size: 9pt;
            color: #0c5460;
            margin-bottom: 4px;
        }
        .url {
            font-family: 'Courier New', monospace;
            font-size: 7pt;
            background: white;
            padding: 4px;
            border-radius: 3px;
            word-wrap: break-word;
            word-break: break-all;
            overflow-wrap: break-word;
            white-space: pre-wrap;
        }
        .url a {
            color: #0c5460;
            text-decoration: underline;
            word-break: break-all;
        }
        .archivos-box {
            background: #e7f3ff;
            border: 1px solid #667eea;
            padding: 8px;
            border-radius: 5px;
            margin-bottom: 8px;
        }
        .archivos-box h4 {
            font-size: 9pt;
            color: #667eea;
            margin-bottom: 5px;
        }
        .archivo-item {
            display: flex;
            align-items: center;
            padding: 4px;
            background: white;
            border-radius: 3px;
            margin-bottom: 3px;
            font-size: 7pt;
        }
        .archivo-item i {
            margin-right: 5px;
            color: #667eea;
        }
        .instrucciones {
            background: #f8f9fa;
            padding: 8px;
            border-radius: 5px;
            font-size: 7pt;
        }
        .instrucciones ol {
            padding-left: 15px;
            margin: 0;
        }
        .instrucciones li {
            margin-bottom: 2px;
        }
        .footer {
            text-align: center;
            margin-top: 8px;
            padding: 6px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 7pt;
            color: #666;
        }
        @media print {
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Compacto -->
        <div class="header">
            <h1>?? INVITACIÓN A TORNEO</h1>
            <p><?= htmlspecialchars($inv['torneo_nombre']) ?> - <?= date('d/m/Y', strtotime($inv['torneo_fecha'])) ?></p>
        </div>
        
        <!-- Logos Compactos -->
        <div class="logos">
            <div class="logo-box">
                <?php if ($inv['club_responsable_logo']): ?>
                    <img src="../<?= htmlspecialchars($inv['club_responsable_logo']) ?>" alt="Organizador">
                <?php else: ?>
                    <div style="font-size: 24pt; color: #ccc;">??</div>
                <?php endif; ?>
                <p>Organizador<br><?= htmlspecialchars($inv['club_responsable_nombre'] ?? 'N/A') ?></p>
            </div>
            <div style="font-size: 20pt; color: #667eea;">?</div>
            <div class="logo-box">
                <?php if ($inv['club_invitado_logo']): ?>
                    <img src="../<?= htmlspecialchars($inv['club_invitado_logo']) ?>" alt="Invitado">
                <?php else: ?>
                    <div style="font-size: 24pt; color: #ccc;">??</div>
                <?php endif; ?>
                <p>Invitado<br><?= htmlspecialchars($inv['club_invitado_nombre']) ?></p>
            </div>
        </div>
        
        <!-- Contenido en 2 Columnas -->
        <div class="content">
            <!-- Columna Izquierda -->
            <div class="column">
                <!-- Info del Torneo -->
                <div class="info-box">
                    <h3>?? Torneo</h3>
                    <div class="info-row">
                        <div class="info-label">?? Fecha:</div>
                        <div class="info-value"><?= date('d/m/Y', strtotime($inv['torneo_fecha'])) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">?? Clase:</div>
                        <div class="info-value"><?= getClaseLabel($inv['torneo_clase']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">?? Modalidad:</div>
                        <div class="info-value"><?= getModalidadLabel($inv['torneo_modalidad']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">?? Costo:</div>
                        <div class="info-value">$<?= number_format((float)($inv['torneo_costo'] ?? 0), 2) ?></div>
                    </div>
                </div>
                
                <!-- Club Invitado -->
                <div class="info-box">
                    <h3>?? Club Invitado</h3>
                    <div class="info-row">
                        <div class="info-label">?? Delegado:</div>
                        <div class="info-value"><?= htmlspecialchars($inv['club_invitado_delegado'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">?? Teléfono:</div>
                        <div class="info-value"><?= htmlspecialchars($inv['club_invitado_telefono'] ?? 'N/A') ?></div>
                    </div>
                </div>
                
                <!-- Archivos del Torneo -->
                <div class="archivos-box">
                    <h4>?? Archivos Adjuntos</h4>
                    <?php if ($inv['torneo_invitacion']): ?>
                    <div class="archivo-item">
                        <i>??</i>
                        <strong>Invitación:</strong>
                        <span style="margin-left: 5px; font-size: 6pt; color: #666;">
                            <?= basename($inv['torneo_invitacion']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($inv['torneo_normas']): ?>
                    <div class="archivo-item">
                        <i>??</i>
                        <strong>Normas:</strong>
                        <span style="margin-left: 5px; font-size: 6pt; color: #666;">
                            <?= basename($inv['torneo_normas']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($inv['torneo_afiche']): ?>
                    <div class="archivo-item">
                        <i>???</i>
                        <strong>Afiche:</strong>
                        <span style="margin-left: 5px; font-size: 6pt; color: #666;">
                            <?= basename($inv['torneo_afiche']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$inv['torneo_invitacion'] && !$inv['torneo_normas'] && !$inv['torneo_afiche']): ?>
                    <p style="font-size: 7pt; color: #999; text-align: center;">Sin archivos adjuntos</p>
                    <?php endif; ?>
                    
                    <p style="font-size: 6pt; margin-top: 5px; color: #666;">
                        ?? Estos archivos est�n disponibles en el portal de inscripciones
                    </p>
                </div>
            </div>
            
            <!-- Columna Derecha -->
            <div class="column">
                <!-- Token -->
                <div class="token-box">
                    <h4>?? TOKEN DE ACCESO</h4>
                    <div class="token"><?= htmlspecialchars($inv['token']) ?></div>
                    <p style="font-size: 6pt; margin-top: 4px;">
                        ? Válido: <?= date('d/m', strtotime($inv['acceso1'])) ?> al <?= date('d/m/Y', strtotime($inv['acceso2'])) ?>
                    </p>
                </div>
                
                <!-- Link de Acceso -->
                <div class="access-box">
                    <h4>?? Acceso Directo</h4>
                    <div class="url">
                        <a href="<?= htmlspecialchars($login_url) ?>" style="word-break: break-all; display: block;">
                            <?= htmlspecialchars($login_url) ?>
                        </a>
                    </div>
                    <p style="font-size: 6pt; margin-top: 4px; color: #0c5460;">
                        ?? <strong>IMPORTANTE:</strong> Seleccione TODO el link, copielo y p�guelo en su navegador
                    </p>
                    <p style="font-size: 6pt; margin-top: 2px; color: #0c5460;">
                        ?? O escanee el código QR si est• disponible
                    </p>
                </div>
                
                <!-- Instrucciones Compactas -->
                <div class="instrucciones">
                    <strong style="font-size: 8pt; color: #667eea;">?? Instrucciones:</strong>
                    <ol style="margin-top: 4px;">
                        <li>Acceda con el enlace o ingrese el token</li>
                        <li>Inscriba jugadores hasta el <?= date('d/m/Y', strtotime($inv['torneo_fecha'])) ?></li>
                        <li>Descargue reporte de sus inscritos</li>
                        <li>Consulte archivos adjuntos en el portal</li>
                    </ol>
                </div>
                
                <!-- Contacto Organizador Compacto -->
                <?php if ($inv['club_responsable_nombre']): ?>
                <div class="info-box" style="background: #e7f3ff; border-color: #667eea; margin-top: 8px;">
                    <h3 style="font-size: 9pt;">?? Contacto Organizador</h3>
                    <div class="info-row">
                        <div class="info-label">Club:</div>
                        <div class="info-value"><?= htmlspecialchars($inv['club_responsable_nombre']) ?></div>
                    </div>
                    <?php if ($inv['club_responsable_delegado']): ?>
                    <div class="info-row">
                        <div class="info-label">Delegado:</div>
                        <div class="info-value"><?= htmlspecialchars($inv['club_responsable_delegado']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($inv['club_responsable_telefono']): ?>
                    <div class="info-row">
                        <div class="info-label">Tel:</div>
                        <div class="info-value"><?= htmlspecialchars($inv['club_responsable_telefono']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Footer Compacto -->
        <div class="footer">
            <strong>Serviclubes LED</strong> | 
            Generado: <?= date('d/m/Y H:i') ?> | 
            ID: <?= $inv['id'] ?>
        </div>
    </div>
    
    <script>
        // Auto-abrir di�logo de impresi�n
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 300);
        };
    </script>
</body>
</html>
