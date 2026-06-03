
<?php
/**
 * Widget de Consulta de Credencial con QR Code
 * 
 * Este widget muestra un enlace directo al formulario de consulta de credenciales
 * junto con un código QR para facilitar el acceso desde dispositivos móviles.
 */

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
                    <i class="fas fa-id-card text-primary me-2"></i>Portal P�blico para Jugadores
                </h6>
                <p class="text-muted small mb-3">
                    Los jugadores pueden consultar y descargar su credencial usando solo su número de cédula:
                </p>
                
                <div class="d-grid gap-2 mb-3">
                    <a href="<?= htmlspecialchars($consulta_url) ?>" 
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-external-link-alt me-2"></i>Abrir Portal de Consulta
                    </a>
                    
                    <button type="button" 
                            class="btn btn-outline-secondary btn-sm" 
                            onclick="copyConsultaUrl()">
                        <i class="fas fa-copy me-2"></i>Copiar Enlace
                    </button>
                </div>
                
                <div class="alert alert-light border p-2 mb-0" style="font-size: 0.75rem;">
                    <strong>Enlace directo:</strong><br>
                    <code id="consultaUrl" style="font-size: 0.7rem; word-break: break-all;"><?= htmlspecialchars($consulta_url) ?></code>
                </div>
            </div>
            
            <div class="col-md-5 text-center">
                <div class="p-3 bg-light rounded border">
                    <img src="<?= htmlspecialchars($qr_code_url) ?>" 
                         alt="QR Code - Consulta de Credenciales" 
                         class="img-fluid mb-2"
                         style="max-width: 180px;">
                    <p class="text-muted small mb-2">
                        <i class="fas fa-mobile-alt me-1"></i>Escanea con tu móvil
                    </p>
                    <button type="button" 
                            class="btn btn-sm btn-outline-info" 
                            onclick="downloadQR()">
                        <i class="fas fa-download me-1"></i>Descargar QR
                    </button>
                </div>
            </div>
        </div>
        
        <hr class="my-3">
        
        <div class="row g-2">
            <div class="col-md-4">
                <div class="text-center p-2 bg-light rounded">
                    <i class="fas fa-search text-primary fs-4"></i>
                    <p class="small mb-0 mt-1"><strong>1. Buscar</strong><br>Por cédula</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-2 bg-light rounded">
                    <i class="fas fa-info-circle text-success fs-4"></i>
                    <p class="small mb-0 mt-1"><strong>2. Consultar</strong><br>Info completa</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-2 bg-light rounded">
                    <i class="fas fa-download text-info fs-4"></i>
                    <p class="small mb-0 mt-1"><strong>3. Descargar</strong><br>Credencial PDF</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyConsultaUrl() {
    const urlText = document.getElementById('consultaUrl').textContent;
    
    // Usar la API moderna de Clipboard si est• disponible
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








