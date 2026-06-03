<?php
/**
 * Portal Público - Página independiente para consulta de credenciales
 * Accesible para todos los roles autenticados
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

// Determinar la URL base del sitio
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

// Construir la URL completa al formulario de consulta
$consulta_url = AppHelpers::url('consulta_credencial.php');

// URL de la API de QR Code (usando QR Server API - no requiere instalación)
$qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
    'size' => '200x200',
    'data' => $consulta_url,
    'format' => 'png',
    'margin' => 10,
    'qzone' => 1
]);
?>

<div class="fade-in">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-id-card me-2"></i>Portal Público</h1>
            <p class="text-muted mb-0">Consulta de Credenciales para Jugadores</p>
        </div>
    </div>

    <!-- Card Principal -->
    <div class="card border-info shadow-sm">
        <div class="card-header bg-gradient text-white" style="background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%);">
            <h5 class="mb-0">
                <i class="fas fa-qrcode me-2"></i>Consulta de Credenciales
            </h5>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-id-card text-primary me-2"></i>Portal Público para Jugadores
                    </h6>
                    <p class="text-muted mb-3">
                        Los jugadores pueden consultar y descargar su credencial usando solo su número de cédula. 
                        Este portal es accesible públicamente sin necesidad de autenticación.
                    </p>
                    
                    <div class="d-grid gap-2 mb-3">
                        <a href="<?= htmlspecialchars($consulta_url) ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-external-link-alt me-2"></i>Abrir Portal de Consulta
                        </a>
                        
                        <button type="button" 
                                class="btn btn-outline-secondary" 
                                onclick="copyConsultaUrl()">
                            <i class="fas fa-copy me-2"></i>Copiar Enlace
                        </button>
                    </div>
                    
                    <div class="alert alert-light border p-3 mb-0">
                        <strong>Enlace directo:</strong><br>
                        <code id="consultaUrl" style="font-size: 0.85rem; word-break: break-all;"><?= htmlspecialchars($consulta_url) ?></code>
                    </div>
                </div>
                
                <div class="col-md-5 text-center">
                    <div class="p-4 bg-light rounded border">
                        <img src="<?= htmlspecialchars($qr_code_url) ?>" 
                             alt="QR Code - Consulta de Credenciales" 
                             class="img-fluid mb-3"
                             style="max-width: 200px;">
                        <p class="text-muted mb-3">
                            <i class="fas fa-mobile-alt me-1"></i>Escanea con tu móvil
                        </p>
                        <button type="button" 
                                class="btn btn-outline-info" 
                                onclick="downloadQR()">
                            <i class="fas fa-download me-1"></i>Descargar QR
                        </button>
                    </div>
                </div>
            </div>
            
            <hr class="my-4">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-center p-3 bg-light rounded border">
                        <i class="fas fa-search text-primary fs-1 mb-2"></i>
                        <p class="mb-0"><strong>1. Buscar</strong><br><small class="text-muted">Por cédula</small></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 bg-light rounded border">
                        <i class="fas fa-info-circle text-success fs-1 mb-2"></i>
                        <p class="mb-0"><strong>2. Consultar</strong><br><small class="text-muted">Info completa</small></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 bg-light rounded border">
                        <i class="fas fa-download text-info fs-1 mb-2"></i>
                        <p class="mb-0"><strong>3. Descargar</strong><br><small class="text-muted">Credencial PDF</small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyConsultaUrl() {
    const urlText = document.getElementById('consultaUrl').textContent;
    
    // Usar la API moderna de Clipboard si está disponible
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(urlText).then(() => {
            showCopyNotification('Enlace copiado al portapapeles');
        }).catch(err => {
            fallbackCopyTextToClipboard(urlText);
        });
    } else {
        fallbackCopyTextToClipboard(urlText);
    }
}

function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showCopyNotification('Enlace copiado al portapapeles');
    } catch (err) {
        showCopyNotification('Error al copiar. Selecciona manualmente el enlace.', 'danger');
    }
    
    document.body.removeChild(textArea);
}

function showCopyNotification(message, type = 'success') {
    // Crear notificación temporal
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alert.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alert);
    
    // Auto-remover después de 3 segundos
    setTimeout(() => {
        alert.remove();
    }, 3000);
}

function downloadQR() {
    const qrUrl = '<?= $qr_code_url ?>';
    const link = document.createElement('a');
    link.href = qrUrl;
    link.download = 'qr_consulta_credenciales.png';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showCopyNotification('Descargando código QR...');
}
</script>






