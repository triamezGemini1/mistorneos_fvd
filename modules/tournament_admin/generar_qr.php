<?php
/**
 * Generar Códigos QR para Acceso Público al Torneo
 * Permite generar códigos QR para que los atletas accedan a:
 * - Incidencias de cada ronda
 * - Asignación de mesas individual
 * - Resumen del atleta
 * - Listado general del evento
 */

$pdo = DB::pdo();
require_once __DIR__ . '/../../config/csrf.php';
$csrf_generar_qr = CSRF::token();
$base_url = app_base_url();
$script = $_SERVER['SCRIPT_NAME'] ?? 'index.php';
$base_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($script);
$url_panel = rtrim($base_url, '/') . '/' . basename($script) . '?page=torneo_gestion&action=panel&torneo_id=' . (int)$torneo_id;

// URL pública: consulta de mesa por ronda (QR del torneo + ID de jugador; no expone el perfil completo)
$public_base = rtrim(AppHelpers::getPublicUrl(), '/');
$info_torneo_mesas_url = $public_base . '/info_torneo_mesas.php?torneo_id=' . (int) $torneo_id;
$torneo_info_url = AppHelpers::url('torneo_info.php', ['torneo_id' => $torneo_id]);

// URLs específicas para cada sección
$urls = [
    'info_torneo_mesas' => $info_torneo_mesas_url,
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
<style>
@media print {
    body * { visibility: hidden; }
    #qr-torneo-print-area, #qr-torneo-print-area * { visibility: visible; }
    #qr-torneo-print-area { position: absolute; left: 0; top: 0; width: 100%; }
    .no-print { display: none !important; }
    .admin-menu, .navbar, .btn, .breadcrumb { display: none !important; }
}
</style>
<div class="mb-3 no-print d-flex align-items-center gap-2">
    <a href="<?= htmlspecialchars($url_panel) ?>" class="btn btn-primary">
        <i class="fas fa-arrow-left me-1"></i>Volver al panel
    </a>
    <button type="button" class="btn btn-outline-secondary" onclick="window.print();" title="Imprimir códigos QR">
        <i class="fas fa-print me-1"></i>Imprimir
    </button>
</div>
<div id="qr-torneo-print-area" class="card">
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Información:</strong> Los códigos QR permiten a los atletas inscritos acceder directamente a la información del torneo desde sus dispositivos móviles.
        </div>
        
        <div class="row g-4">
            <!-- QR: consulta de mesa (ID de jugador; sin perfil completo) -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-success">
                    <div class="card-header bg-success text-white text-center">
                        <h6 class="mb-0">
                            <i class="fas fa-chess-board me-2"></i>Consulta de mesa (jugadores)
                        </h6>
                    </div>
                    <div class="card-body text-center">
                        <img src="<?= htmlspecialchars(generarQRUrl($urls['info_torneo_mesas'], 200)) ?>" 
                             alt="QR consulta de mesa por ID de jugador" 
                             class="img-fluid mb-3 border rounded p-2 bg-white">
                        <p class="small text-muted mb-2">
                            <strong>Recomendado (genérico).</strong> Tras escanear, el jugador ingresa su <strong>ID de jugador</strong> y la <strong>ronda</strong>. Para un enlace <strong>corto por jugador</strong> use el generador personal abajo (código firmado, sin cédula).
                        </p>
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($urls['info_torneo_mesas']) ?>" 
                               class="btn btn-sm btn-success">
                                <i class="fas fa-external-link-alt me-1"></i>Ver Página
                            </a>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-success"
                                    onclick="descargarQR('<?= htmlspecialchars(generarQRUrl($urls['info_torneo_mesas'], 500)) ?>', 'qr_consulta_mesa_torneo_<?= $torneo_id ?>.png')">
                                <i class="fas fa-download me-1"></i>Descargar QR
                            </button>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-secondary"
                                    onclick="copiarEnlace('<?= htmlspecialchars($urls['info_torneo_mesas']) ?>')">
                                <i class="fas fa-copy me-1"></i>Copiar Enlace
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- QR General -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-primary">
                    <div class="card-header bg-primary text-white text-center">
                        <h6 class="mb-0">
                            <i class="fas fa-list me-2"></i>Listado general
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
        
        <!-- QR personal: token corto (ID jugador) → página móvil mesa + resumen + clasificación -->
        <div class="card mt-4" id="qr-personal-jugador">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0">
                    <i class="fas fa-user me-2"></i>QR personal por ID de jugador (enlace corto)
                </h6>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    El jugador escanea y entra directo a su <strong>mesa</strong> (nombre resaltado), con botones de <strong>resumen</strong>, <strong>clasificación</strong> (individual o equipos) y <strong>actualizar</strong> sin salir. El código del enlace es corto (no usa cédula). Configure <code>APP_KEY</code> en producción para que los enlaces no puedan falsificarse.
                </p>
                <form id="formQRPersonalizado" class="row g-3">
                    <input type="hidden" name="csrf_token" id="csrf_qr_personal" value="<?= htmlspecialchars($csrf_generar_qr, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-md-8">
                        <label for="id_usuario_qr" class="form-label">ID de jugador (usuarios / inscritos)</label>
                        <input type="number" min="1" step="1" class="form-control" id="id_usuario_qr" name="id_usuario" placeholder="Ej: 1234" required>
                        <small class="text-muted">Debe estar inscrito en este torneo</small>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100" id="btnGenQrPersonal">
                            <i class="fas fa-qrcode me-2"></i>Generar QR
                        </button>
                    </div>
                </form>
                
                <div id="qrPersonalizadoContainer" class="mt-4" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-success text-white text-center">
                                    <h6 class="mb-0">QR personal</h6>
                                </div>
                                <div class="card-body text-center">
                                    <img id="qrPersonalizadoImg" src="" alt="QR" class="img-fluid mb-3 border rounded p-2 bg-white">
                                    <p class="small text-break text-muted" id="qrPersonalUrlText"></p>
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-sm btn-success" onclick="descargarQRPersonalizado()">
                                            <i class="fas fa-download me-1"></i>Descargar QR
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copiarEnlacePersonalizado()">
                                            <i class="fas fa-copy me-1"></i>Copiar enlace
                                        </button>
                                        <a id="linkVistaJugador" href="" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                                            <i class="fas fa-mobile-alt me-1"></i>Abrir vista móvil
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Imprimir en lote: tarjetas personales (solo datos, sin QR) -->
        <div class="card mt-4 no-print">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-id-card me-2"></i>Tarjetas personales (identificación)</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">Imprima en lote una tarjeta 4×4 cm por jugador confirmado con: nombre, cédula e ID del torneo (sin QR). También disponible en el menú: <strong>Identificación de jugadores</strong>.</p>
                <a href="index.php?page=tournament_admin&torneo_id=<?= (int)$torneo_id ?>&action=imprimir_qr_lote" 
                   class="btn btn-success" target="_blank" rel="noopener">
                    <i class="fas fa-print me-1"></i>Imprimir tarjetas personales (solo datos)
                </a>
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
                <li>Para acceso personalizado, genere un QR con el <strong>ID de jugador</strong> (enlace corto firmado)</li>
            </ol>
        </div>
    </div>
</div>

<script>
let qrPersonalizadoUrl = '';
let qrPersonalizadoLink = '';

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

// Formulario QR personal (ID jugador → token en servidor)
document.getElementById('formQRPersonalizado').addEventListener('submit', function(e) {
    e.preventDefault();
    const uid = parseInt(document.getElementById('id_usuario_qr').value, 10);
    if (!uid || uid < 1) {
        mostrarNotificacion('Indique un ID de jugador válido', 'warning');
        return;
    }
    const torneoId = <?= (int) $torneo_id ?>;
    const csrf = document.getElementById('csrf_qr_personal').value;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('torneo_id', String(torneoId));
    fd.append('id_usuario', String(uid));
    const apiUrl = 'index.php?page=tournament_admin&torneo_id=' + torneoId + '&action=api_torneo_jugador_qr_token';
    const btn = document.getElementById('btnGenQrPersonal');
    btn.disabled = true;
    fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.ok || !data.url) {
                mostrarNotificacion((data && data.message) ? data.message : 'No se pudo generar el enlace', 'danger');
                return;
            }
            qrPersonalizadoLink = data.url;
            qrPersonalizadoUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' + encodeURIComponent(data.url) + '&format=png&margin=10&qzone=1';
            document.getElementById('qrPersonalizadoImg').src = qrPersonalizadoUrl;
            document.getElementById('linkVistaJugador').href = data.url;
            var elTxt = document.getElementById('qrPersonalUrlText');
            if (elTxt) elTxt.textContent = data.url;
            document.getElementById('qrPersonalizadoContainer').style.display = 'block';
            document.getElementById('qrPersonalizadoContainer').scrollIntoView({ behavior: 'smooth' });
            mostrarNotificacion('QR generado', 'success');
        })
        .catch(function() { mostrarNotificacion('Error de red al generar el QR', 'danger'); })
        .finally(function() { btn.disabled = false; });
});

function descargarQRPersonalizado() {
    if (qrPersonalizadoUrl) {
        const uid = document.getElementById('id_usuario_qr').value.trim();
        descargarQR(qrPersonalizadoUrl, `qr_jugador_${uid}_torneo_<?= $torneo_id ?>.png`);
    }
}

function copiarEnlacePersonalizado() {
    if (qrPersonalizadoLink) {
        copiarEnlace(qrPersonalizadoLink);
    }
}
</script>




