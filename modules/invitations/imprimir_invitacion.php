<?php
/**
 * Invitación Imprimible (Guardar como PDF)
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';

if (!isset($_GET['id'])) {
    die("ID no proporcionado");
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
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            c.direccion as club_direccion,
            c.logo as club_logo,
            cr.nombre as club_responsable_nombre,
            cr.logo as club_responsable_logo
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        LEFT JOIN clubes cr ON t.club_responsable = cr.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inv) {
        die("Invitación no encontrada");
    }
    
    // Datos
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $telefono = !empty($inv['club_telefono']) ? $inv['club_telefono'] : 'N/A';
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // URL base del sistema
    $url_base = rtrim(FvdConfig::resolvePublicUrl(), '/') . '/';
    
    // Limpiar número para WhatsApp
    $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
    
    // Preparar logo del club responsable del torneo
    $logo_responsable_url = null;
    if (!empty($inv['club_responsable_logo'])) {
        $logo_responsable_url = $url_base . "upload/logos/" . $inv['club_responsable_logo'];
    }
    
    // Preparar logo del club invitado
    $logo_club_url = null;
    if (!empty($inv['club_logo'])) {
        $logo_club_url = $url_base . "upload/logos/" . $inv['club_logo'];
    }
    
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitación - <?php echo htmlspecialchars($inv['torneo_nombre']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            margin: -30px -30px 30px -30px;
            position: relative;
        }
        .header-logo {
            max-width: 150px;
            max-height: 150px;
            margin: 0 auto 15px auto;
            display: block;
            background: white;
            padding: 10px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .club-logo {
            max-width: 80px;
            max-height: 80px;
            margin-left: 15px;
            vertical-align: middle;
            background: white;
            padding: 5px;
            border-radius: 8px;
            border: 2px solid #dee2e6;
        }
        .club-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .club-header h3 {
            margin: 0;
            flex: 1;
        }
        .header h1 { font-size: 1.8rem; margin-bottom: 10px; }
        .organizacion-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-top: 10px;
        }
        .section {
            margin: 25px 0;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
        }
        .section-title {
            background: #f0f0f0;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 1.2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px 8px 0 0;
        }
        .field { margin: 12px 0; line-height: 1.6; }
        .field-label { 
            font-weight: bold; 
            color: #333; 
            display: inline-block;
            min-width: 150px;
        }
        .field-value { color: #666; }
        .credentials {
            background: #d4edda;
            padding: 25px;
            border-left: 5px solid #28a745;
            margin: 30px 0;
            border-radius: 5px;
        }
        .credentials h3 { color: #155724; margin-bottom: 15px; }
        .token {
            background: white;
            padding: 15px;
            border: 2px dashed #28a745;
            word-break: break-all;
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            margin: 15px 0;
        }
        .url-box {
            background: white;
            padding: 15px;
            border: 2px solid #007bff;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 0.95rem;
        }
        .instructions {
            background: #fff3cd;
            padding: 20px;
            border-left: 5px solid #ffc107;
            margin: 20px 0;
        }
        .instructions ol {
            margin-left: 20px;
            margin-top: 10px;
        }
        .instructions li {
            margin: 8px 0;
            line-height: 1.6;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 3px solid #667eea;
        }
        .footer h3 { color: #667eea; margin-bottom: 15px; }
        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .btn {
            padding: 12px 25px;
            margin: 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        .btn-pdf { background: #dc3545; color: white; }
        .btn-whatsapp { background: #25D366; color: white; }
        .btn-back { background: #6c757d; color: white; }
        
        @media print {
            body { background: white; }
            .no-print { display: none; }
            .container { box-shadow: none; }
        }
        
        @page {
            margin: 1cm;
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin: 20px 0;">
        <button onclick="window.print()" class="btn btn-pdf" style="margin: 5px;">?? Guardar como PDF</button>
        <button onclick="mostrarConfirmacionWhatsApp()" class="btn btn-whatsapp" style="margin: 5px;">?? Enviar por WhatsApp</button>
        <a href="index.php" class="btn btn-back" style="margin: 5px;">?? Volver</a>
    </div>

    <div class="container">
        <div class="header">
            <?php if ($logo_responsable_url): ?>
                <img src="<?php echo htmlspecialchars($logo_responsable_url); ?>" alt="Logo" class="header-logo">
            <?php endif; ?>
            
            <h1>?? INVITACIÓN A TORNEO</h1>
            <p style="font-size: 1.1rem; margin: 10px 0;">Invitación Oficial</p>
            
            <?php if (!empty($inv['club_responsable_nombre'])): ?>
                <div class="organizacion-badge">
                    Organiza: <?php echo htmlspecialchars($inv['club_responsable_nombre']); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <div class="section-title">?? INFORMACIÓN DEL TORNEO</div>
            <?php if (!empty($inv['club_responsable_nombre'])): ?>
            <div class="field">
                <span class="field-label">Organización:</span>
                <span class="field-value"><?php echo htmlspecialchars($inv['club_responsable_nombre']); ?></span>
            </div>
            <?php endif; ?>
            <div class="field">
                <span class="field-label">Nombre del Torneo:</span>
                <span class="field-value"><?php echo htmlspecialchars($inv['torneo_nombre']); ?></span>
            </div>
            <div class="field">
                <span class="field-label">Fecha del Torneo:</span>
                <span class="field-value"><?php echo $fecha_torneo; ?></span>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">?? CLUB INVITADO</div>
            <div class="club-header">
                <div>
                    <div class="field">
                        <span class="field-label">Club:</span>
                        <span class="field-value" style="font-size: 1.2rem; font-weight: bold;">
                            <?php echo htmlspecialchars($inv['club_nombre']); ?>
                        </span>
                    </div>
                </div>
                <?php if ($logo_club_url): ?>
                    <img src="<?php echo htmlspecialchars($logo_club_url); ?>" 
                         alt="Logo <?php echo htmlspecialchars($inv['club_nombre']); ?>" 
                         class="club-logo">
                <?php endif; ?>
            </div>
            <div class="field">
                <span class="field-label">Delegado:</span>
                <span class="field-value"><?php echo htmlspecialchars($delegado); ?></span>
            </div>
            <div class="field">
                <span class="field-label">Teléfono:</span>
                <span class="field-value"><?php echo htmlspecialchars($telefono); ?></span>
            </div>
            <?php if (!empty($inv['club_direccion'])): ?>
            <div class="field">
                <span class="field-label">Dirección:</span>
                <span class="field-value"><?php echo htmlspecialchars($inv['club_direccion']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <div class="section-title">?? VIGENCIA DE LA INVITACIÓN</div>
            <div class="field">
                <span class="field-label">Periodo de Acceso:</span>
                <span class="field-value"><?php echo $vigencia; ?></span>
            </div>
            <div class="field">
                <span class="field-label">Estado:</span>
                <span class="field-value" style="color: <?php echo $inv['estado'] === 'activa' ? 'green' : 'red'; ?>; font-weight: bold;">
                    <?php echo strtoupper($inv['estado']); ?>
                </span>
            </div>
        </div>
        
        <div class="credentials">
            <h3>?? CREDENCIALES PARA INSCRIPCIÓN DE JUGADORES</h3>
            <p style="color: #721c24; font-weight: bold; margin-bottom: 15px;">
                ?? INFORMACIÓN IMPORTANTE: Guarde estas credenciales en un lugar seguro.
            </p>
            
            <div style="margin: 20px 0;">
                <strong style="font-size: 1.1rem;">?? URL DE ACCESO:</strong>
                <div class="url-box">
                    <?php echo $url_base; ?>modules/invitations/inscripciones/login.php
                </div>
            </div>
            
            <div style="margin: 20px 0;">
                <strong style="font-size: 1.1rem;">?? TOKEN DE ACCESO (Su Clave Personal):</strong>
                <div class="token"><?php echo $inv['token']; ?></div>
            </div>
        </div>
        
        <div class="instructions">
            <h4 style="margin-bottom: 10px;">?? INSTRUCCIONES PARA INSCRIBIR JUGADORES:</h4>
            <ol>
                <li>Entre a la <strong>URL DE ACCESO</strong> (arriba) en su navegador</li>
                <li>Copie y pegue su <strong>TOKEN DE ACCESO</strong> en el formulario</li>
                <li>Haga clic en "Ingresar"</li>
                <li>Inscriba a sus jugadores ingresando su cédula</li>
                <li>Puede gestionar las inscripciones, actualizar datos y ver estadísticas</li>
            </ol>
            <p style="margin-top: 15px; color: #721c24; font-weight: bold;">
                ?? GUARDE ESTE TOKEN: Lo necesitará cada vez que acceda al sistema de inscripción.
            </p>
        </div>
        
        <div class="footer">
            <h3>?? INFORMACIÓN DE CONTACTO</h3>
            <p style="margin: 10px 0;">Sistema de Gestión de Torneos</p>
            <p style="margin: 20px 0; font-style: italic;">¡Esperamos contar con su participación!</p>
        </div>
    </div>

    <!-- Modal de Confirmación de WhatsApp -->
    <div id="modalConfirmacionWhatsApp" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
        <div style="background: white; border-radius: 15px; padding: 30px; max-width: 500px; margin: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="text-align: center; font-size: 4rem; margin-bottom: 20px;">??</div>
            <h2 style="text-align: center; color: #25D366; margin-bottom: 10px;">Enviar Invitación por WhatsApp</h2>
            <p style="text-align: center; color: #666; margin-bottom: 20px;">
                Se enviar• la invitación a:<br>
                <strong><?php echo htmlspecialchars($inv['club_nombre']); ?></strong>
            </p>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <div style="font-size: 0.9rem; color: #666;">
                    <strong>?? Teléfono:</strong> <?php echo htmlspecialchars($telefono_display); ?><br>
                    <strong>?? Delegado:</strong> <?php echo htmlspecialchars($delegado); ?><br>
                    <strong>?? Torneo:</strong> <?php echo htmlspecialchars($inv['torneo_nombre']); ?>
                </div>
            </div>
            
            <?php if ($telefono_limpio): ?>
                <div style="margin-bottom: 15px; padding: 10px; background: #e8f5e9; border-radius: 8px; border-left: 4px solid #28a745;">
                    <small style="color: #155724;">
                        ? El mensaje incluye el TOKEN de acceso para inscripciones.<br>
                        ? WhatsApp se abrió con el mensaje pre-cargado.<br>
                        ? Solo confirme el envío en WhatsApp.
                    </small>
                </div>
                
                <div style="text-align: center;">
                    <button onclick="confirmarEnvioWhatsApp()" class="btn btn-whatsapp" style="font-size: 1.1rem; padding: 12px 30px; margin: 5px;">
                        ? Confirmar y Enviar
                    </button>
                    <button onclick="cerrarModalWhatsApp()" class="btn btn-back" style="padding: 12px 30px; margin: 5px;">
                        ? Cancelar
                    </button>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 15px; padding: 10px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                    <small style="color: #856404;">
                        ?? El club no tiene teléfono configurado.<br>
                        No se puede enviar por WhatsApp.
                    </small>
                </div>
                
                <div style="text-align: center;">
                    <button onclick="cerrarModalWhatsApp()" class="btn btn-back" style="padding: 12px 30px;">
                        Cerrar
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function mostrarConfirmacionWhatsApp() {
            const modal = document.getElementById('modalConfirmacionWhatsApp');
            modal.style.display = 'flex';
        }
        
        function cerrarModalWhatsApp() {
            const modal = document.getElementById('modalConfirmacionWhatsApp');
            modal.style.display = 'none';
        }
        
        function confirmarEnvioWhatsApp() {
            // Redirigir a whatsapp.php que har• la redirección automática a WhatsApp
            window.location.href = 'whatsapp.php?id=<?php echo $id; ?>';
            cerrarModalWhatsApp();
            
            // Mostrar mensaje de confirmación
            alert('✓ WhatsApp se est• abriendo con el mensaje pre-cargado.\n\nConfirme el envío en la ventana de WhatsApp.');
        }
        
        // Cerrar modal si se hace clic fuera
        document.getElementById('modalConfirmacionWhatsApp').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalWhatsApp();
            }
        });
        
        // Cerrar modal con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModalWhatsApp();
            }
        });
        
        // Imprimir automáticamente si se solicita
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto') === '1') {
            setTimeout(() => window.print(), 500);
        }
    </script>
</body>
</html>



