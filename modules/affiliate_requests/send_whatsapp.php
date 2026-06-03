<?php
/**
 * Enviar Notificación de Afiliación Aprobada por WhatsApp
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_general']);

try {
    $solicitud_id = (int)($_GET['id'] ?? 0);
    
    if ($solicitud_id <= 0) {
        throw new Exception('ID de solicitud inválido');
    }
    
    $pdo = DB::pdo();
    
    // Obtener información de la solicitud
    $stmt = $pdo->prepare("SELECT * FROM solicitudes_afiliacion WHERE id = ?");
    $stmt->execute([$solicitud_id]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }
    
    // Formatear teléfono
    $telefono = preg_replace('/[^0-9]/', '', $solicitud['celular'] ?? '');
    
    if ($telefono && $telefono[0] == '0') {
        $telefono = substr($telefono, 1);
    }
    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
        $telefono = '58' . $telefono;
    }
    
    // Generar mensaje según estado
    if ($solicitud['estatus'] === 'aprobada') {
        $mensaje = "🎉 *¡FELICITACIONES!*\n\n";
        $mensaje .= "Hola *" . $solicitud['nombre'] . "*\n\n";
        $mensaje .= "Tu solicitud de afiliación a *La Estación del Dominó* ha sido *APROBADA* ✅\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
        $mensaje .= "📋 *DATOS DE ACCESO*\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "👤 *Usuario:* " . $solicitud['username'] . "\n";
        $mensaje .= "🔐 *Contraseña:* La que definiste al registrarte\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
        $mensaje .= "🏢 *TU CLUB*\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "📍 *Nombre:* " . $solicitud['club_nombre'] . "\n";
        if ($solicitud['club_ubicacion']) {
            $mensaje .= "📌 *Ubicación:* " . $solicitud['club_ubicacion'] . "\n";
        }
        $mensaje .= "\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
        $mensaje .= "✨ *AHORA PUEDES:*\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "✅ Gestionar tu club\n";
        $mensaje .= "✅ Crear y organizar torneos\n";
        $mensaje .= "✅ Invitar jugadores\n";
        $mensaje .= "✅ Ver estadísticas y reportes\n";
        $mensaje .= "✅ Crear clubes asociados\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "🌐 *Ingresa al sistema:*\n";
        $mensaje .= AppHelpers::url('login.php') . "\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
        $mensaje .= "📖 *MANUAL DE USUARIO*\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "📚 Consulta el manual completo con todas las funcionalidades:\n";
        $app_url = $_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd';
        $manual_url = rtrim($app_url, '/') . '/manuales_web/manual_usuario.php';
        $mensaje .= $manual_url . "\n\n";
        $mensaje .= "⚠️ *Nota:* El manual solo está disponible para usuarios registrados. Debes iniciar sesión para acceder.\n\n";
        $mensaje .= "El manual incluye guías paso a paso para:\n";
        $mensaje .= "✅ Crear y gestionar torneos\n";
        $mensaje .= "✅ Invitar jugadores\n";
        $mensaje .= "✅ Gestionar inscripciones\n";
        $mensaje .= "✅ Administrar resultados\n";
        $mensaje .= "✅ Y mucho más...\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "¡Bienvenido al proyecto! 🎲\n\n";
        $mensaje .= "_La Estación del Dominó_";
    } elseif ($solicitud['estatus'] === 'rechazada') {
        $mensaje = "📋 *ACTUALIZACIÓN DE SOLICITUD*\n\n";
        $mensaje .= "Hola *" . $solicitud['nombre'] . "*\n\n";
        $mensaje .= "Lamentamos informarte que tu solicitud de afiliación no ha sido aprobada en esta ocasión.\n\n";
        if ($solicitud['notas_admin']) {
            $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
            $mensaje .= "📝 *Motivo:*\n";
            $mensaje .= $solicitud['notas_admin'] . "\n";
            $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
        }
        $mensaje .= "Puedes volver a enviar una solicitud cuando lo consideres conveniente.\n\n";
        $mensaje .= "_La Estación del Dominó_";
    } else {
        $mensaje = "📋 *SOLICITUD DE AFILIACIÓN*\n\n";
        $mensaje .= "Hola *" . $solicitud['nombre'] . "*\n\n";
        $mensaje .= "Tu solicitud está siendo revisada.\n";
        $mensaje .= "Te notificaremos cuando tengamos una respuesta.\n\n";
        $mensaje .= "_La Estación del Dominó_";
    }
    
    $mensaje_encoded = urlencode($mensaje);
    
    if ($telefono && strlen($telefono) >= 10) {
        $whatsapp_url = "https://api.whatsapp.com/send?phone={$telefono}&text={$mensaje_encoded}";
    } else {
        $whatsapp_url = "https://api.whatsapp.com/send?text={$mensaje_encoded}";
    }
    
} catch (Exception $e) {
    die('Error: ' . htmlspecialchars($e->getMessage()));
}

$status_colors = [
    'pendiente' => ['bg' => '#ffc107', 'text' => 'dark'],
    'aprobada' => ['bg' => '#28a745', 'text' => 'white'],
    'rechazada' => ['bg' => '#dc3545', 'text' => 'white']
];
$color = $status_colors[$solicitud['estatus']] ?? $status_colors['pendiente'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Enviar Notificación - Afiliación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .mensaje-preview {
            background: #DCF8C6;
            border-radius: 10px;
            padding: 15px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
            position: relative;
        }
        .mensaje-preview::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 10px;
            border: 10px solid transparent;
            border-right-color: #DCF8C6;
            border-left: 0;
        }
        .whatsapp-bubble {
            background: #ECE5DD;
            border-radius: 15px;
            padding: 20px;
        }
    </style>
</head>
<body>

<div class="container-card">
    <div class="header">
        <h2 class="mb-0"><i class="fab fa-whatsapp me-2"></i>Notificación de Afiliación</h2>
        <p class="mb-0">Envío por WhatsApp</p>
    </div>
    
    <div class="p-4">
        <!-- Información del Solicitante -->
        <div class="card mb-4">
            <div class="card-header" style="background: <?= $color['bg'] ?>; color: <?= $color['text'] ?>;">
                <i class="fas fa-user me-2"></i>Información del Solicitante
                <span class="badge bg-<?= $color['text'] === 'white' ? 'light text-dark' : 'dark' ?> float-end">
                    <?= ucfirst($solicitud['estatus']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><i class="fas fa-user me-2"></i>Nombre:</strong> <?= htmlspecialchars($solicitud['nombre']) ?></p>
                        <p><strong><i class="fas fa-id-card me-2"></i>Cédula:</strong> <?= htmlspecialchars($solicitud['nacionalidad'] . '-' . $solicitud['cedula']) ?></p>
                        <p><strong><i class="fas fa-phone me-2"></i>Celular:</strong> <?= htmlspecialchars($solicitud['celular'] ?? 'No especificado') ?></p>
                        <p><strong><i class="fas fa-envelope me-2"></i>Email:</strong> <?= htmlspecialchars($solicitud['email'] ?? 'No especificado') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><i class="fas fa-building me-2"></i>Club:</strong> <?= htmlspecialchars($solicitud['club_nombre']) ?></p>
                        <p><strong><i class="fas fa-map-marker-alt me-2"></i>Ubicación:</strong> <?= htmlspecialchars($solicitud['club_ubicacion'] ?? 'No especificada') ?></p>
                        <p><strong><i class="fas fa-user-shield me-2"></i>Usuario:</strong> <code><?= htmlspecialchars($solicitud['username']) ?></code></p>
                        <p><strong><i class="fas fa-calendar me-2"></i>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($solicitud['created_at'])) ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Vista Previa del Mensaje -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <i class="fas fa-eye me-2"></i>Vista Previa del Mensaje
            </div>
            <div class="card-body whatsapp-bubble">
                <div class="mensaje-preview"><?= htmlspecialchars($mensaje) ?></div>
            </div>
        </div>
        
        <!-- Opciones de Envío -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <i class="fab fa-whatsapp me-2"></i>Enviar Notificación
            </div>
            <div class="card-body">
                <?php if (!empty($telefono)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-phone me-2"></i>
                    <strong>Envío Directo al:</strong> +<?= htmlspecialchars($telefono) ?>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Sin teléfono registrado</strong> - Deberá seleccionar el contacto manualmente
                </div>
                <?php endif; ?>
                
                <div class="d-grid gap-2">
                    <a href="<?= htmlspecialchars($whatsapp_url) ?>" 
                       class="btn btn-success btn-lg">
                        <i class="fab fa-whatsapp me-2"></i>
                        Enviar por WhatsApp
                    </a>
                    
                    <button class="btn btn-outline-secondary" type="button" onclick="copiarMensaje()">
                        <i class="fas fa-copy me-2"></i>Copiar Mensaje
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Botones de Acción -->
        <div class="d-flex gap-2 justify-content-between">
            <a href="../../public/index.php?page=affiliate_requests" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Solicitudes
            </a>
        </div>
    </div>
</div>

<textarea id="mensajeOculto" style="position: absolute; left: -9999px;"><?= htmlspecialchars($mensaje) ?></textarea>

<script>
function copiarMensaje() {
    const textarea = document.getElementById('mensajeOculto');
    textarea.select();
    document.execCommand('copy');
    alert('✅ Mensaje copiado al portapapeles');
}
</script>

</body>
</html>


