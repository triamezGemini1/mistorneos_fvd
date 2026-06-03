<?php
/**
 * Generar Códigos QR Generales para Acceso Público al Torneo
 * Permite generar códigos QR generales para acceso público a:
 * - Incidencias de cada ronda
 * - Listado general del evento
 */

$pdo = DB::pdo();
$base_url = app_base_url();

// URL base para la información del torneo
$torneo_info_url = AppHelpers::url('torneo_info.php', ['torneo_id' => $torneo_id]);

// URLs específicas para cada sección
$urls = [
    'general' => $torneo_info_url . '&seccion=general',
    'incidencias' => $torneo_info_url . '&seccion=incidencias',
    'listado' => $torneo_info_url . '&seccion=listado'
];

// Función para generar URL de QR
function generarQRUrl($data, $size = 300) {
    return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
        'size' => $size . 'x' . $size,
        'data' => $data,
        'format' => 'png',
        'margin' => 10,
        'qzone' => 1
    ]);
}
?>
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-qrcode me-2"></i>Generar Códigos QR Generales
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Información:</strong> Los códigos QR generales permiten a cualquier persona acceder a la información pública del torneo desde sus dispositivos móviles.
        </div>
        
        <div class="row g-4">
            <!-- QR General -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-primary">
                    <div class="card-header bg-primary text-white text-center">
                        <h6 class="mb-0">
                            <i class="fas fa-list me-2"></i>Acceso General
                        </h6>
                    </div>
                    <div class="card-body text-center">
                        <img src="<?= htmlspecialchars(generarQRUrl($urls['general'], 200)) ?>" 
                             alt="QR Acceso General" 
                             class="img-fluid mb-3 border rounded p-2 bg-white">
                        <p class="small text-muted mb-2">
                            Listado general, incidencias y más información
                        </p>
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($urls['general']) ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-external-link-alt me-1"></i>Ver Página
                            </a>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-primary"
                                    onclick="descargarQR('<?= htmlspecialchars(generarQRUrl($urls['general'], 500)) ?>', 'qr_acceso_general_torneo_<?= $torneo_id ?>.png')">
                                <i class="fas fa-download me-1"></i>Descargar QR
                            </button>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-secondary"
                                    onclick="copiarEnlace('<?= htmlspecialchars($urls['general']) ?>')">
                                <i class="fas fa-copy me-1"></i>Copiar Enlace
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- QR Incidencias -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-warning">
                    <div class="card-header bg-warning text-dark text-center">
                        <h6 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>Incidencias por Ronda
                        </h6>
                    </div>
                    <div class="card-body text-center">
                        <img src="<?= htmlspecialchars(generarQRUrl($urls['incidencias'], 200)) ?>" 
                             alt="QR Incidencias" 
                             class="img-fluid mb-3 border rounded p-2 bg-white">
                        <p class="small text-muted mb-2">
                            Estado de partidas por ronda y mesa
                        </p>
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($urls['incidencias']) ?>" 
                               class="btn btn-sm btn-warning">
                                <i class="fas fa-external-link-alt me-1"></i>Ver Página
                            </a>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-warning"
                                    onclick="descargarQR('<?= htmlspecialchars(generarQRUrl($urls['incidencias'], 500)) ?>', 'qr_incidencias_torneo_<?= $torneo_id ?>.png')">
                                <i class="fas fa-download me-1"></i>Descargar QR
                            </button>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-secondary"
                                    onclick="copiarEnlace('<?= htmlspecialchars($urls['incidencias']) ?>')">
                                <i class="fas fa-copy me-1"></i>Copiar Enlace
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- QR Listado -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-info">
                    <div class="card-header bg-info text-white text-center">
                        <h6 class="mb-0">
                            <i class="fas fa-users me-2"></i>Listado General
                        </h6>
                    </div>
                    <div class="card-body text-center">
                        <img src="<?= htmlspecialchars(generarQRUrl($urls['listado'], 200)) ?>" 
                             alt="QR Listado" 
                             class="img-fluid mb-3 border rounded p-2 bg-white">
                        <p class="small text-muted mb-2">
                            Listado completo de participantes
                        </p>
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($urls['listado']) ?>" 
                               class="btn btn-sm btn-info">
                                <i class="fas fa-external-link-alt me-1"></i>Ver Página
                            </a>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-info"
                                    onclick="descargarQR('<?= htmlspecialchars(generarQRUrl($urls['listado'], 500)) ?>', 'qr_listado_torneo_<?= $torneo_id ?>.png')">
                                <i class="fas fa-download me-1"></i>Descargar QR
                            </button>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-secondary"
                                    onclick="copiarEnlace('<?= htmlspecialchars($urls['listado']) ?>')">
                                <i class="fas fa-copy me-1"></i>Copiar Enlace
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Instrucciones -->
        <div class="alert alert-light mt-4">
            <h6 class="alert-heading">
                <i class="fas fa-lightbulb me-2"></i>Instrucciones de Uso
            </h6>
            <ol class="mb-0">
                <li>Descargue o imprima los códigos QR que necesite</li>
                <li>Coloque los códigos QR en lugares visibles durante el evento</li>
                <li>Los atletas pueden escanear el código con su teléfono para acceder a la información</li>
                <li>Estos códigos QR son de acceso público, no requieren cédula</li>
            </ol>
        </div>
    </div>
</div>

<script>
function descargarQR(qrUrl, filename) {
    const link = document.createElement('a');
    link.href = qrUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    mostrarNotificacion('QR descargado exitosamente', 'success');
}

function copiarEnlace(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            mostrarNotificacion('Enlace copiado al portapapeles', 'success');
        }).catch(() => {
            fallbackCopiar(url);
        });
    } else {
        fallbackCopiar(url);
    }
}

function fallbackCopiar(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        mostrarNotificacion('Enlace copiado al portapapeles', 'success');
    } catch (err) {
        mostrarNotificacion('Error al copiar. Seleccione manualmente el enlace.', 'danger');
    }
    document.body.removeChild(textArea);
}

function mostrarNotificacion(mensaje, tipo) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${tipo} alert-dismissible fade show position-fixed`;
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alert.innerHTML = `
        <i class="fas fa-${tipo === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>${mensaje}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alert);
    setTimeout(() => alert.remove(), 3000);
}
</script>




