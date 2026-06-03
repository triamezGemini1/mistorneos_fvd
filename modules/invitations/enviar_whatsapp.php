<?php
/**
 * Enviar Invitación por WhatsApp
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

Auth::requireRole(['admin_general','admin_torneo']);

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_GET['id'];
$auto_envio = isset($_GET['auto']) && $_GET['auto'] == '1';

try {
    $pdo = DB::pdo();
    
    // Obtener datos completos de la invitación
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.club_responsable,
            t.invitacion as torneo_invitacion,
            t.afiche as torneo_afiche,
            t.lugar as torneo_lugar,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            c.direccion as club_direccion,
            c.estatus as club_estado,
            cr.nombre as club_responsable_nombre
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
    
    $url_sistema = rtrim(FvdConfig::resolvePublicUrl(), '/') . '/';
    
    $url_invitacion = null;
    $url_afiche = null;
    
    if (!empty($inv['torneo_invitacion'])) {
        // Usar view_file.php para visualizar el PDF
        $url_invitacion = $url_sistema . "view_file.php?file=" . urlencode($inv['torneo_invitacion']);
    }
    
    if (!empty($inv['torneo_afiche'])) {
        // Usar view_file.php para visualizar el afiche
        $url_afiche = $url_sistema . "view_file.php?file=" . urlencode($inv['torneo_afiche']);
    }
    
    // Datos
    $delegado = !empty($inv['club_delegado']) ? $inv['club_delegado'] : $inv['club_nombre'];
    $telefono = !empty($inv['club_telefono']) ? trim($inv['club_telefono']) : '';
    $telefono_display = !empty($telefono) ? $telefono : 'No configurado';
    $estado_club = $inv['club_estado'] == 1 ? 'Activo' : 'Inactivo';
    $organizacion = !empty($inv['club_responsable_nombre']) ? $inv['club_responsable_nombre'] : 'Organización';
    
    // Formatear fechas
    $fecha_torneo = date('d/m/Y', strtotime($inv['torneo_fecha']));
    $vigencia = date('d/m/Y', strtotime($inv['acceso1'])) . ' al ' . date('d/m/Y', strtotime($inv['acceso2']));
    
    // Construir mensaje de WhatsApp con el formato específico
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
    $mensaje .= "• *Delegado Estado:* " . $estado_club . "\n";
    $mensaje .= "• *Teléfono:* " . $telefono_display . "\n";
    
    if (!empty($inv['club_direccion'])) {
        $mensaje .= "• *Dirección:* " . $inv['club_direccion'] . "\n";
    }
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // VIGENCIA DE LA INVITACIÓN
    $mensaje .= "• *VIGENCIA DE LA INVITACIÓN*\n\n";
    $mensaje .= "• *Periodo de Acceso:* " . $vigencia . "\n";
    $mensaje .= "• *Estado:* " . strtoupper($inv['estado']) . "\n";
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // ***** CREDENCIALES DE ACCESO - SECCIÓN MÁS DESTACADA *****
    $mensaje .= "• *CREDENCIALES PARA INSCRIPCIÓN DE JUGADORES*\n\n";
    $mensaje .= "• ?? ?? *¡INFORMACIÓN IMPORTANTE!* ⚠ ⚠ ⚠\n\n";
    $mensaje .= "Para inscribir a sus jugadores, utilice:\n\n";
    
    $mensaje .= "• *URL DE ACCESO:*\n";
    $mensaje .= $url_sistema . "modules/invitations/inscripciones/login.php\n\n";
    
    $mensaje .= "• *TOKEN DE ACCESO (Su Clave Personal):*\n";
    $mensaje .= $inv['token'] . "\n\n";
    
    $mensaje .= "• *INSTRUCCIONES:*\n";
    $mensaje .= "1?? Copie el TOKEN completo (arriba)\n";
    $mensaje .= "2?? Entre a la URL de acceso\n";
    $mensaje .= "3?? Pegue su TOKEN en el formulario\n";
    $mensaje .= "4?? Inscriba a sus jugadores por cédula\n\n";
    
    $mensaje .= "• *GUARDE ESTE TOKEN - Lo necesitará cada vez que acceda*\n";
    
    $mensaje .= "\n" . $separador . "\n\n";
    
    // DOCUMENTOS DEL TORNEO (si existen)
    if ($url_invitacion || $url_afiche) {
        $mensaje .= "• *DOCUMENTOS DEL TORNEO*\n\n";
        
        if ($url_invitacion) {
            $mensaje .= "• *Invitación (Documento):* " . $url_invitacion . "\n";
        }
        
        if ($url_afiche) {
            $mensaje .= "• *Afiche (Poster):* " . $url_afiche . "\n";
        }
        
        $mensaje .= "\n" . $separador . "\n\n";
    }
    
    $mensaje .= $separador . "\n\n";
    
    // CONTACTO
    $mensaje .= "• *CONTACTO " . strtoupper($organizacion) . "*\n\n";
    $mensaje .= "¡Esperamos contar con su participación!\n\n";
    $mensaje .= "_" . $organizacion . "_";
    
    // Codificar mensaje para URL
    $mensaje_encoded = urlencode($mensaje);
    
    // Limpiar número de teléfono (quitar caracteres especiales)
    $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
    
    // Verificar si hay teléfono válido
    $tiene_telefono = !empty($telefono_limpio) && strlen($telefono_limpio) >= 10;
    
    // Generar enlace de WhatsApp
    if ($tiene_telefono) {
        $whatsapp_url = "https://api.whatsapp.com/send?phone={$telefono_limpio}&text={$mensaje_encoded}";
    } else {
        $whatsapp_url = "#";
    }
    
} catch (PDOException $e) {
    die("? Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar Invitación por WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        body {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container-custom {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .header {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
        }
        .mensaje-preview {
            background: #2a2a2a;
            color: #fff;
            padding: 20px;
            border-radius: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 500px;
            overflow-y: auto;
            margin: 20px 0;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #25D366;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .btn-whatsapp {
            background: #25D366;
            color: white;
            font-size: 1.3rem;
            padding: 15px 40px;
            border: none;
        }
        .btn-whatsapp:hover {
            background: #128C7E;
            color: white;
        }
        .btn-copy {
            background: #17a2b8;
            color: white;
            font-size: 1.1rem;
            padding: 10px 30px;
        }
        .btn-copy:hover {
            background: #138496;
            color: white;
        }
        .token-box {
            background: #fff3cd;
            border: 2px dashed #ff6b6b;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
        .token-box code {
            font-size: 0.9rem;
            word-break: break-all;
            background: white;
            padding: 10px;
            display: block;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <div class="header">
            <h1>?? Enviar Invitación por WhatsApp</h1>
            <p class="mb-0">Revise el mensaje y envíelo</p>
            <small style="opacity: 0.7;">Versión: <?= date('Y-m-d H:i:s') ?></small>
        </div>

        <?php if (!$tiene_telefono): ?>
            <div class="alert alert-danger">
                <h4>? Teléfono No Configurado</h4>
                <p class="mb-0">
                    <strong>No se puede enviar por WhatsApp porque el club no tiene teléfono configurado.</strong><br>
                    Por favor, edite el club "<?= htmlspecialchars($inv['club_nombre']) ?>" y agregue un número de teléfono válido.
                </p>
                <a href="../clubs/edit.php?id=<?= $inv['club_id'] ?>" class="btn btn-sm btn-warning mt-2">
                    ?? Editar Club
                </a>
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <h4>? Mensaje Generado Correctamente</h4>
                <p class="mb-0">El mensaje ha sido creado con los datos de la invitación.</p>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <h5>?? Información de Envío</h5>
            <p class="mb-1"><strong>Torneo:</strong> <?php echo htmlspecialchars($inv['torneo_nombre']); ?></p>
            <p class="mb-1"><strong>Club:</strong> <?php echo htmlspecialchars($inv['club_nombre']); ?></p>
            <p class="mb-1"><strong>Delegado:</strong> <?php echo htmlspecialchars($delegado); ?></p>
            <p class="mb-1">
                <strong>Teléfono:</strong> 
                <?php if ($tiene_telefono): ?>
                    <span class="text-success">? <?php echo htmlspecialchars($telefono); ?></span>
                <?php else: ?>
                    <span class="text-danger">? No configurado</span>
                <?php endif; ?>
            </p>
            <?php if ($url_invitacion): ?>
                <p class="mb-1">
                    <strong>PDF Invitación:</strong> 
                    <a href="<?= htmlspecialchars($url_invitacion) ?>" class="text-primary">Ver PDF</a>
                </p>
            <?php endif; ?>
            <?php if ($url_afiche): ?>
                <p class="mb-0">
                    <strong>Afiche:</strong> 
                    <a href="<?= htmlspecialchars($url_afiche) ?>" class="text-primary">Ver Afiche</a>
                </p>
            <?php endif; ?>
        </div>

        <div class="token-box">
            <h5>?? TOKEN DE ACCESO</h5>
            <p class="mb-2">Este token se incluir• en el mensaje:</p>
            <code><?php echo htmlspecialchars($inv['token']); ?></code>
        </div>

        <h4>?? Vista Previa del Mensaje</h4>
        <div class="mensaje-preview" id="mensaje"><?php echo htmlspecialchars($mensaje); ?></div>

        <div class="alert alert-info">
            <h5>?? Instrucciones:</h5>
            <ol class="mb-0">
                <li>Haga clic en <strong>"• Abrir WhatsApp"</strong> para enviar el mensaje</li>
                <li>O copie el mensaje usando <strong>"• Copiar Mensaje"</strong></li>
                <li>WhatsApp Web se abrió con el mensaje pre-cargado</li>
                <li>Verifique que el número sea correcto y haga clic en "Enviar"</li>
            </ol>
        </div>

        <div class="text-center mt-4">
            <?php if ($tiene_telefono): ?>
                <a href="<?php echo htmlspecialchars($whatsapp_url); ?>" 
                   id="btnWhatsApp"
                   class="btn btn-whatsapp btn-lg"
                   onclick="intentarAbrirWhatsApp(event)">
                    ?? Abrir WhatsApp
                </a>
                
                <button onclick="copiarEnlaceWhatsApp()" class="btn btn-lg" style="background: #128C7E; color: white;">
                    ?? Copiar Enlace de WhatsApp
                </button>
            <?php else: ?>
                <button class="btn btn-secondary btn-lg" disabled title="Configure un teléfono para el club">
                    ?? WhatsApp No Disponible
                </button>
            <?php endif; ?>
            
            <button onclick="copiarMensaje()" class="btn btn-copy btn-lg">
                ?? Copiar Mensaje
            </button>
            
            <a href="index.php" class="btn btn-secondary btn-lg">
                ?? Volver
            </a>
        </div>

        <div class="alert alert-warning mt-4">
            <h5>?? Importante:</h5>
            <ul class="mb-0">
                <li>Asegúrese de que el número de teléfono sea correcto</li>
                <li>El mensaje incluye el TOKEN completo para acceso</li>
                <li>Verifique que toda la información sea correcta antes de enviar</li>
            </ul>
        </div>
        
        <?php if ($tiene_telefono): ?>
        <div class="alert alert-info mt-3" id="alertaAlternativa" style="display: none;">
            <h5>?? ¿No se abrió WhatsApp?</h5>
            <p class="mb-3"><strong>Su navegador puede estar bloqueando pop-ups.</strong> Use una de estas alternativas:</p>
            
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-white">
                        <h6 class="text-primary">?? Opción 1 (Más Fácil)</h6>
                        <p class="small">Copie el mensaje y envíelo manualmente por WhatsApp.</p>
                        <button onclick="copiarMensaje()" class="btn btn-sm btn-primary w-100">
                            ?? Copiar Mensaje
                        </button>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-white">
                        <h6 class="text-success">?? Opción 2 (Recomendada)</h6>
                        <p class="small">Copie el enlace y ábralo en una nueva pestaña.</p>
                        <button onclick="copiarEnlaceWhatsApp()" class="btn btn-sm btn-success w-100">
                            ?? Copiar Enlace
                        </button>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-white">
                        <h6 class="text-warning">?? Opción 3</h6>
                        <p class="small">Habilite los pop-ups y recargue la página.</p>
                        <button onclick="location.reload()" class="btn btn-sm btn-warning w-100">
                            ?? Recargar Página
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <details>
                    <summary class="btn btn-sm btn-outline-secondary">?? Ver URL Completa de WhatsApp</summary>
                    <div class="p-3 bg-light border rounded mt-2">
                        <input type="text" class="form-control" id="urlWhatsapp" value="<?= htmlspecialchars($whatsapp_url) ?>" readonly onclick="this.select()">
                        <button onclick="copiarUrlWhatsapp()" class="btn btn-sm btn-primary mt-2">
                            ?? Copiar URL
                        </button>
                    </div>
                </details>
            </div>
            
            <div class="alert alert-light mt-3">
                <strong>?? Para evitar esto en el futuro:</strong><br>
                <small>
                    • <strong>Chrome:</strong> Clic en el icono ?? ? "Ventanas emergentes" ? "Permitir"<br>
                    • <strong>Firefox:</strong> Clic en el icono ?? ? Desactivar "Bloquear ventanas emergentes"<br>
                    • <strong>Edge:</strong> Clic en el icono ?? ? "Permisos" ? "Ventanas emergentes" ? "Permitir"
                </small>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        function copiarMensaje() {
            const mensaje = document.getElementById('mensaje').textContent;
            
            // Crear elemento temporal
            const textarea = document.createElement('textarea');
            textarea.value = mensaje;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            
            // Seleccionar y copiar
            textarea.select();
            textarea.setSelectionRange(0, 99999);
            
            try {
                document.execCommand('copy');
                
                // Mostrar confirmación
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '? ¡Copiado!';
                btn.classList.remove('btn-copy');
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-copy');
                }, 2000);
                
            } catch (err) {
                alert('Error al copiar. Por favor, seleccione y copie manualmente.');
            }
            
            document.body.removeChild(textarea);
        }
        
        function copiarEnlaceWhatsApp() {
            const url = document.getElementById('urlWhatsapp').value;
            
            navigator.clipboard.writeText(url).then(() => {
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '? ¡Enlace Copiado!';
                btn.style.background = '#28a745';
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.style.background = '#128C7E';
                }, 2000);
                
                // Mostrar instrucciones
                alert('✓ Enlace copiado!\n\nAhora:\n1. Abra una nueva pestaña en su navegador\n2. Pegue el enlace (Ctrl+V)\n3. Presione Enter\n4. WhatsApp Web se abrirá directamente');
            }).catch(() => {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = url;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                
                alert('✓ Enlace copiado!\n\nAhora:\n1. Abra una nueva pestaña en su navegador\n2. Pegue el enlace (Ctrl+V)\n3. Presione Enter\n4. WhatsApp Web se abrirá directamente');
            });
        }
        
        function copiarUrlWhatsapp() {
            const input = document.getElementById('urlWhatsapp');
            input.select();
            document.execCommand('copy');
            
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '? Copiado';
            
            setTimeout(() => {
                btn.innerHTML = originalText;
            }, 2000);
        }
        
        function intentarAbrirWhatsApp(event) {
            // Detectar si el pop-up fue bloqueado
            const urlWhatsApp = '<?php echo addslashes($whatsapp_url); ?>';
            window.location.href = urlWhatsApp;
            
            // Verificar si la ventana se abrió
            setTimeout(() => {
                if (!ventana || ventana.closed || typeof ventana.closed == 'undefined') {
                    // Pop-up bloqueado - mostrar alternativas inmediatamente
                    mostrarAlternativaInmediata();
                } else {
                    // Se abrió correctamente - mostrar alternativas por si acaso
                    setTimeout(() => {
                        const alerta = document.getElementById('alertaAlternativa');
                        if (alerta && !alerta.classList.contains('alert-success')) {
                            alerta.style.display = 'block';
                            alerta.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    }, 3000);
                }
            }, 100);
            
            // Prevenir la navegación por defecto
            event.preventDefault();
            return false;
        }
        
        function mostrarAlternativaInmediata() {
            const alerta = document.getElementById('alertaAlternativa');
            if (alerta) {
                // Cambiar a estilo de advertencia más prominente
                alerta.classList.remove('alert-info');
                alerta.classList.add('alert-warning');
                
                // Agregar mensaje más directo
                const titulo = alerta.querySelector('h5');
                if (titulo) {
                    titulo.innerHTML = '• Pop-up Bloqueado - Use una de estas alternativas:';
                }
                
                alerta.style.display = 'block';
                alerta.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // A�adir animación de atención
                alerta.style.animation = 'pulse 0.5s';
                setTimeout(() => {
                    alerta.style.animation = '';
                }, 500);
            }
        }
        
        function mostrarAlternativa() {
            // Función legacy - ahora usa la detección automática
            mostrarAlternativaInmediata();
        }
        
        <?php if ($auto_envio && $tiene_telefono): ?>
        // ENVÍO AUTOMÁTICO - Sin intervención del operador
        window.addEventListener('DOMContentLoaded', function() {
            // Mostrar mensaje de procesamiento
            const container = document.querySelector('.container-custom');
            container.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-success mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <h3 class="text-success">?? Abriendo WhatsApp...</h3>
                    <p class="text-muted">El mensaje se está enviando automáticamente</p>
                    <p class="small">Esta ventana se cerrar• automáticamente</p>
                </div>
            `;
            
            // Abrir WhatsApp inmediatamente
            setTimeout(function() {
                window.location.href = '<?= addslashes($whatsapp_url) ?>';
                
                // Intentar cerrar la ventana después de un momento
                setTimeout(function() {
                    // Solo funciona si la ventana fue abierta por JavaScript
                    window.close();
                    
                    // Si no se puede cerrar, mostrar mensaje de éxito
                    setTimeout(function() {
                        container.innerHTML = `
                            <div class="text-center py-5">
                                <div class="mb-4">
                                    <div style="font-size: 5rem;">?</div>
                                </div>
                                <h3 class="text-success">¡WhatsApp Abierto!</h3>
                                <p class="text-muted mb-4">El mensaje se carg• correctamente en WhatsApp</p>
                                <p class="mb-4">Solo confirme el envío en WhatsApp Web</p>
                                <a href="<?= AppHelpers::dashboard('invitations') ?>" class="btn btn-primary btn-lg">
                                    ? Volver al Listado
                                </a>
                            </div>
                        `;
                    }, 1000);
                }, 500);
            }, 500);
        });
        <?php endif; ?>
    </script>
    
    <style>
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
    </style>
</body>
</html>

