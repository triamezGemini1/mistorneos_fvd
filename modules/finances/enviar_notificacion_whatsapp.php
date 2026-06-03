<?php
/**
 * Enviar Notificación de Deuda por WhatsApp
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

try {
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    $club_id = (int)($_GET['club_id'] ?? 0);
    
    if ($torneo_id <= 0 || $club_id <= 0) {
        throw new Exception('Parámetros inválidos');
    }
    
    $pdo = DB::pdo();
    
    // Obtener información completa
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.costo as torneo_costo
        FROM deuda_clubes d
        INNER JOIN clubes c ON d.club_id = c.id
        INNER JOIN tournaments t ON d.torneo_id = t.id
        WHERE d.torneo_id = ? AND d.club_id = ?
    ");
    $stmt->execute([$torneo_id, $club_id]);
    $deuda = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deuda) {
        throw new Exception('Deuda no encontrada');
    }
    
    $pendiente = (float)$deuda['monto_total'] - (float)$deuda['abono'];
    $telefono = preg_replace('/[^0-9]/', '', $deuda['club_telefono'] ?? '');
    
    // Formatear teléfono
    if ($telefono && $telefono[0] == '0') {
        $telefono = substr($telefono, 1);
    }
    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
        $telefono = '58' . $telefono;
    }
    
    // Generar mensaje
    $mensaje = "NOTIFICACI�N DE DEUDA PENDIENTE\n\n";
    $mensaje .= "Estimado/a " . ($deuda['club_delegado'] ?? 'Delegado') . "\n";
    $mensaje .= "Club: " . $deuda['club_nombre'] . "\n\n";
    $mensaje .= "==================\n\n";
    $mensaje .= "TORNEO: " . $deuda['torneo_nombre'] . "\n";
    $mensaje .= "FECHA: " . date('d/m/Y', strtotime($deuda['torneo_fecha'])) . "\n\n";
    $mensaje .= "==================\n\n";
    $mensaje .= "DETALLE DE DEUDA:\n";
    $mensaje .= "Jugadores Inscritos: " . $deuda['total_inscritos'] . "\n";
    $mensaje .= "Costo por Jugador: $" . number_format((float)$deuda['torneo_costo'], 2) . "\n";
    $mensaje .= "Deuda Total: $" . number_format((float)$deuda['monto_total'], 2) . "\n";
    $mensaje .= "Pagado: $" . number_format((float)$deuda['abono'], 2) . "\n";
    $mensaje .= "PENDIENTE: $" . number_format((float)$pendiente, 2) . "\n\n";
    $mensaje .= "==================\n\n";
    $mensaje .= "Por favor, realice el pago a la brevedad posible.\n\n";
    $mensaje .= "Para coordinar el pago, contacte al organizador del torneo.\n\n";
    $mensaje .= "Gracias por su atención.\n\n";
    $mensaje .= "_Serviclubes LED_";
    
    $mensaje_encoded = urlencode($mensaje);
    
    if ($telefono && strlen($telefono) >= 10) {
        $whatsapp_url = "https://api.whatsapp.com/send?phone={$telefono}&text={$mensaje_encoded}";
    } else {
        $whatsapp_url = "https://api.whatsapp.com/send?text={$mensaje_encoded}";
    }
    
} catch (Exception $e) {
    die('Error: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Enviar Notificación de Deuda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
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
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .mensaje-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body>

<div class="container-card">
    <div class="header">
        <h2 class="mb-0"><i class="fab fa-whatsapp me-2"></i>Notificación de Deuda</h2>
        <p class="mb-0">Envío por WhatsApp</p>
    </div>
    
    <div class="p-4">
        <!-- Información del Club -->
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <i class="fas fa-building me-2"></i>Información del Club
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Club:</strong> <?= htmlspecialchars($deuda['club_nombre']) ?></p>
                        <p><strong>Delegado:</strong> <?= htmlspecialchars($deuda['club_delegado'] ?? 'No especificado') ?></p>
                        <p><strong>Teléfono:</strong> <?= htmlspecialchars($deuda['club_telefono'] ?? 'No especificado') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Torneo:</strong> <?= htmlspecialchars($deuda['torneo_nombre']) ?></p>
                        <p><strong>Inscritos:</strong> <?= $deuda['total_inscritos'] ?></p>
                        <p><strong>Deuda Pendiente:</strong> <span class="text-danger fs-5">$<?= number_format((float)$pendiente, 2) ?></span></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Vista Previa del Mensaje -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="fas fa-eye me-2"></i>Vista Previa del Mensaje
            </div>
            <div class="card-body">
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
                    <strong>Sin teléfono registrado</strong> - Deber• seleccionar el contacto manualmente
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
            <a href="../../public/index.php?page=finances&torneo_id=<?= $torneo_id ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Finanzas
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
    alert('✓ Mensaje copiado al portapapeles');
}

// Auto-abrir WhatsApp después de 1 segundo
setTimeout(() => {
    if (confirm('¿Desea abrir WhatsApp ahora para enviar la notificación?')) {
        window.location.href = '<?= $whatsapp_url ?>';
    }
}, 1000);
</script>

</body>
</html>

