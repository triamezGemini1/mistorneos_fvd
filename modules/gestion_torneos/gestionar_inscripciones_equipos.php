<?php
/**
 * Vista: Gestionar Inscripciones de Equipos
 * Muestra listado HTML de todos los equipos inscritos ordenados por club
 * Permite expandir/colapsar jugadores y retirar equipos
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';

// Modalidad 2 = Parejas (mismo flujo que equipos; 2 jugadores, nombre opcional)
$es_parejas = !empty($es_parejas);
$jugadores_por_equipo = isset($jugadores_por_equipo) ? (int)$jugadores_por_equipo : 4;
$etiqueta_equipo = $es_parejas ? 'Pareja' : 'Equipo';
$etiqueta_equipos = $es_parejas ? 'Parejas' : 'Equipos';

// Obtener CSRF token
require_once __DIR__ . '/../../config/csrf.php';
$csrf_token = class_exists('CSRF') ? CSRF::token() : '';
$sustitucion_api = AppHelpers::url('api/equipo_sustitucion.php');
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
    body {
        background-color: #f8f9fa;
    }
    .club-section {
        margin-bottom: 30px;
    }
    .club-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px 20px;
        border-radius: 8px 8px 0 0;
        font-weight: bold;
        font-size: 1.1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .equipo-row {
        background: white;
        border: 1px solid #dee2e6;
        border-top: none;
        padding: 12px 20px;
        transition: background-color 0.2s;
    }
    .equipo-row:hover {
        background-color: #f8f9fa;
    }
    .equipo-row:last-child {
        border-radius: 0 0 8px 8px;
    }
    .equipo-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }
    .equipo-main {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .equipo-numero {
        width: 35px;
        height: 35px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        flex-shrink: 0;
    }
    .equipo-details {
        flex: 1;
    }
    .equipo-codigo {
        font-family: monospace;
        font-size: 0.9rem;
        color: #6c757d;
        margin-right: 10px;
    }
    .equipo-nombre {
        font-weight: 600;
        color: #212529;
        font-size: 1rem;
    }
    .equipo-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .jugadores-toggle {
        cursor: pointer;
        color: #667eea;
        font-size: 0.9rem;
        user-select: none;
    }
    .jugadores-toggle:hover {
        color: #764ba2;
        text-decoration: underline;
    }
    .jugadores-toggle i {
        transition: transform 0.3s;
    }
    .jugadores-toggle.expanded i {
        transform: rotate(90deg);
    }
    .jugadores-list {
        display: none;
        padding: 15px 20px 15px 70px;
        background-color: #f8f9fa;
        border-top: 1px solid #e9ecef;
    }
    .jugadores-list.show {
        display: block;
    }
    .jugador-item {
        padding: 8px 0;
        border-bottom: 1px dotted #dee2e6;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .jugador-item:last-child {
        border-bottom: none;
    }
    .jugador-id {
        font-family: monospace;
        color: #6c757d;
        font-size: 0.85rem;
        min-width: 60px;
    }
    .jugador-cedula {
        font-family: monospace;
        color: #495057;
        font-size: 0.9rem;
        min-width: 100px;
    }
    .jugador-nombre {
        flex: 1;
        color: #212529;
    }
    .badge-equipo-completo {
        background-color: #28a745;
        color: white;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .badge-equipo-incompleto {
        background-color: #ffc107;
        color: #212529;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .badge-jugador-titular {
        background: #198754;
        color: #fff;
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 10px;
    }
    .badge-jugador-banca {
        background: #6c757d;
        color: #fff;
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 10px;
    }
</style>

<div class="container-fluid py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index">Gestión de Torneos</a></li>
            <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
            <li class="breadcrumb-item active">Gestionar Inscripciones</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2 class="h4 mb-1">
                        <i class="fas fa-clipboard-list text-primary me-2"></i>Gestionar Inscripciones de <?php echo $etiqueta_equipos; ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <i class="fas fa-trophy me-1"></i><?php echo htmlspecialchars($torneo['nombre']); ?>
                    </p>
                    <?php
                    $contadores_inscripcion = $contadores_inscripcion ?? ['inscritos_total' => 0, 'jugadores_confirmados' => 0, 'equipos_activos' => 0];
                    require __DIR__ . '/../../resources/views/partials/torneo_inscripcion_badges_bs5.php';
                    ?>
                </div>
                <div class="d-flex gap-2 mt-2 mt-md-0">
                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=inscribir_equipo_sitio&torneo_id=<?php echo $torneo['id']; ?>" 
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i>Inscribir <?php echo $etiqueta_equipo; ?>
                    </a>
                    <?php if (!$es_parejas): ?>
                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=carga_masiva_equipos_sitio&torneo_id=<?php echo $torneo['id']; ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-file-upload me-1"></i>Carga masiva
                    </a>
                    <?php else: ?>
                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=carga_masiva_parejas_sitio&torneo_id=<?php echo $torneo['id']; ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-file-upload me-1"></i>Carga masiva parejas
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>" 
                       class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Retornar al Panel
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$es_parejas): ?>
    <div class="alert alert-info small mb-4">
        <strong>Plantilla ampliada:</strong> puede inscribir más de <?= (int) $jugadores_por_equipo ?> jugadores por equipo.
        Los <strong>titulares</strong> (activos para mesas) son <?= (int) $jugadores_por_equipo ?>; los demás quedan en <strong>banca</strong>
        y pueden reemplazar a un titular en cualquier momento. El reemplazado conserva el código de equipo y sus estadísticas,
        pero deja de recibir mesas.
    </div>
    <?php endif; ?>

    <?php if (empty($equipos_por_club)): ?>
        <!-- Sin equipos -->
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-users-slash fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-muted mb-0">No hay <?php echo strtolower($etiqueta_equipos); ?> inscrit<?php echo $es_parejas ? 'as' : 'os'; ?></p>
                <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=inscribir_equipo_sitio&torneo_id=<?php echo $torneo['id']; ?>" 
                   class="btn btn-primary mt-3">
                    <i class="fas fa-plus me-1"></i>Inscribir <?php echo $es_parejas ? 'primera' : 'primer'; ?> <?php echo strtolower($etiqueta_equipo); ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Listado de Equipos por Club -->
        <?php 
        $contador_global = 0;
        foreach ($equipos_por_club as $club_info): 
            $club_nombre = htmlspecialchars($club_info['nombre']);
            $total_equipos_club = count($club_info['equipos']);
        ?>
            <div class="club-section">
                <!-- Encabezado del Club -->
                <div class="club-header">
                    <div>
                        <i class="fas fa-building me-2"></i>
                        <?php echo $club_nombre; ?>
                        <span class="badge bg-light text-dark ms-2"><?php echo $total_equipos_club; ?> <?php echo strtolower($etiqueta_equipos); ?></span>
                    </div>
                </div>

                <!-- Listado de Equipos del Club -->
                <?php foreach ($club_info['equipos'] as $index => $equipo): 
                    $contador_global++;
                    $equipo_id = (int)$equipo['id'];
                    $codigo_equipo = htmlspecialchars($equipo['codigo_equipo'] ?? '');
                    $nombre_equipo = htmlspecialchars($equipo['nombre_equipo'] ?? '');
                    $jugadores = $equipo['jugadores'] ?? [];
                    $pareja_display = '';
                    if ($es_parejas && !empty($jugadores)) {
                        $nombresPareja = array_values(array_filter(array_map(static function ($j) {
                            return trim((string)($j['nombre'] ?? ''));
                        }, $jugadores)));
                        if (!empty($nombresPareja)) {
                            $pareja_display = implode(' / ', array_slice($nombresPareja, 0, 2));
                        }
                    }
                    $total_jugadores = count($jugadores);
                    $titulares = (int)($equipo['titulares'] ?? $total_jugadores);
                    $suplentes = (int)($equipo['suplentes'] ?? 0);
                    $jugadores_requeridos = $jugadores_por_equipo;
                    $es_completo = $titulares >= $jugadores_requeridos;
                    $equipo_id_js = htmlspecialchars(json_encode($equipo_id));
                    $codigo_js = htmlspecialchars(json_encode($equipo['codigo_equipo'] ?? ''), ENT_QUOTES);
                ?>
                    <div class="equipo-row" id="equipo-row-<?php echo $equipo_id; ?>">
                        <div class="equipo-info">
                            <div class="equipo-main">
                                <div class="equipo-numero"><?php echo $contador_global; ?></div>
                                <div class="equipo-details">
                                    <div>
                                        <span class="equipo-codigo"><?php echo $codigo_equipo; ?></span>
                                        <span class="equipo-nombre"><?php echo $nombre_equipo; ?></span>
                                    </div>
                                    <?php if ($es_parejas && $pareja_display !== ''): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="fas fa-user-friends me-1"></i><?php echo htmlspecialchars($pareja_display); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="equipo-actions">
                                <?php if ($es_completo): ?>
                                    <span class="badge-equipo-completo">
                                        <i class="fas fa-check-circle me-1"></i>Titulares <?= $titulares; ?>/<?= $jugadores_requeridos; ?>
                                        <?php if ($suplentes > 0): ?><span class="ms-1">· Banca <?= $suplentes; ?></span><?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-equipo-incompleto">
                                        <i class="fas fa-exclamation-triangle me-1"></i>Titulares <?= $titulares; ?>/<?= $jugadores_requeridos; ?>
                                        (plantilla <?= $total_jugadores; ?>)
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!$es_parejas && $suplentes > 0 && $titulares >= $jugadores_requeridos): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="abrirSustitucion(<?= $equipo_id_js ?>, <?= $codigo_js ?>, '<?= htmlspecialchars($nombre_equipo, ENT_QUOTES); ?>')"
                                            title="Reemplazo titular ↔ banca">
                                        <i class="fas fa-people-arrows"></i> Sustituir
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (!empty($jugadores)): ?>
                                    <span class="jugadores-toggle" onclick="toggleJugadores(<?php echo $equipo_id; ?>)">
                                        <i class="fas fa-chevron-right me-1"></i>
                                        <span>Ver Jugadores</span>
                                    </span>
                                <?php endif; ?>
                                
                                <button class="btn btn-sm btn-danger" 
                                        onclick="retirarEquipo(<?php echo $equipo_id; ?>, '<?php echo htmlspecialchars($nombre_equipo, ENT_QUOTES); ?>')"
                                        title="Retirar <?php echo $etiqueta_equipo; ?>">
                                    <i class="fas fa-times"></i> Retirar
                                </button>
                            </div>
                        </div>
                        
                        <!-- Lista de Jugadores (oculta por defecto) -->
                        <?php if (!empty($jugadores)): ?>
                            <div class="jugadores-list" id="jugadores-<?php echo $equipo_id; ?>">
                                <div class="mb-2 fw-semibold text-muted" style="font-size: 0.9rem;">
                                    <i class="fas fa-users me-1"></i>Jugadores de la <?php echo $etiqueta_equipo; ?>:
                                </div>
                                <?php foreach ($jugadores as $jugador): 
                                    $esTitular = (int)($jugador['activo_mesa'] ?? 1) === 1;
                                ?>
                                    <div class="jugador-item">
                                        <span class="jugador-id">ID: <?php echo htmlspecialchars($jugador['id_usuario'] ?? '-'); ?></span>
                                        <span class="jugador-cedula"><?php echo htmlspecialchars($jugador['cedula'] ?? '-'); ?></span>
                                        <span class="jugador-nombre"><?php echo htmlspecialchars($jugador['nombre'] ?? 'Sin nombre'); ?></span>
                                        <?php if (!$es_parejas): ?>
                                        <span class="<?= $esTitular ? 'badge-jugador-titular' : 'badge-jugador-banca' ?>">
                                            <?= $esTitular ? 'Titular mesas' : 'Banca' ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
<script>
const SUSTITUCION_API = <?= json_encode($sustitucion_api, JSON_UNESCAPED_UNICODE) ?>;
const CSRF_SUST = <?= json_encode($csrf_token, JSON_UNESCAPED_UNICODE) ?>;
const TORNEO_ID_SUST = <?= (int) ($torneo['id'] ?? 0) ?>;

async function abrirSustitucion(equipoId, codigoEquipo, nombreEquipo) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_SUST);
    fd.append('action', 'listar_plantilla');
    fd.append('torneo_id', String(TORNEO_ID_SUST));
    fd.append('codigo_equipo', codigoEquipo);
    let plantilla = [];
    try {
        const r = await fetch(SUSTITUCION_API, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) throw new Error(j.error || 'Error al cargar plantilla');
        plantilla = j.plantilla || [];
    } catch (e) {
        Swal.fire('Error', e.message || 'No se pudo cargar la plantilla', 'error');
        return;
    }
    const titulares = plantilla.filter(function (p) { return parseInt(p.activo_mesa, 10) === 1; });
    const banca = plantilla.filter(function (p) { return parseInt(p.activo_mesa, 10) !== 1; });
    if (!titulares.length || !banca.length) {
        Swal.fire('Sin sustitución', 'Se necesita al menos un titular y un suplente en banca.', 'info');
        return;
    }
    const optsSale = titulares.map(function (p) {
        return '<option value="' + p.id_usuario + '">' + (p.nombre || p.cedula) + ' (titular)</option>';
    }).join('');
    const optsEntra = banca.map(function (p) {
        return '<option value="' + p.id_usuario + '">' + (p.nombre || p.cedula) + ' (banca)</option>';
    }).join('');
    const html = '<div class="text-start small">'
        + '<p><strong>' + codigoEquipo + '</strong> · ' + nombreEquipo + '</p>'
        + '<label class="form-label">Titular que sale (pasa a banca, conserva estadísticas)</label>'
        + '<select id="swal-sale" class="form-select mb-2">' + optsSale + '</select>'
        + '<label class="form-label">Suplente que entra (activo en mesas resto del torneo)</label>'
        + '<select id="swal-entra" class="form-select mb-2">' + optsEntra + '</select>'
        + '<label class="form-label">Observación (opcional)</label>'
        + '<input type="text" id="swal-obs" class="form-control" maxlength="200" placeholder="Motivo del cambio">'
        + '</div>';
    const result = await Swal.fire({
        title: 'Sustitución de jugador',
        html: html,
        showCancelButton: true,
        confirmButtonText: 'Aplicar sustitución',
        cancelButtonText: 'Cancelar',
        preConfirm: function () {
            return {
                sale: document.getElementById('swal-sale').value,
                entra: document.getElementById('swal-entra').value,
                obs: document.getElementById('swal-obs').value
            };
        }
    });
    if (!result.isConfirmed || !result.value) return;
    const fd2 = new FormData();
    fd2.append('csrf_token', CSRF_SUST);
    fd2.append('action', 'sustituir');
    fd2.append('torneo_id', String(TORNEO_ID_SUST));
    fd2.append('codigo_equipo', codigoEquipo);
    fd2.append('id_usuario_sale', result.value.sale);
    fd2.append('id_usuario_entra', result.value.entra);
    fd2.append('observacion', result.value.obs || '');
    try {
        const r2 = await fetch(SUSTITUCION_API, { method: 'POST', body: fd2, credentials: 'same-origin' });
        const j2 = await r2.json();
        if (!j2.success) throw new Error(j2.error || j2.message || 'Error');
        await Swal.fire('Listo', j2.message || 'Sustitución aplicada', 'success');
        location.reload();
    } catch (e) {
        Swal.fire('Error', e.message || 'No se pudo aplicar', 'error');
    }
}
</script>
<script>
function toggleJugadores(equipoId) {
    const lista = document.getElementById('jugadores-' + equipoId);
    const toggle = event.target.closest('.jugadores-toggle');
    
    if (lista && toggle) {
        const isExpanded = lista.classList.contains('show');
        
        if (isExpanded) {
            lista.classList.remove('show');
            toggle.classList.remove('expanded');
            toggle.querySelector('span').textContent = 'Ver Jugadores';
        } else {
            lista.classList.add('show');
            toggle.classList.add('expanded');
            toggle.querySelector('span').textContent = 'Ocultar Jugadores';
        }
    }
}

async function retirarEquipo(equipoId, nombreEquipo) {
    const result = await Swal.fire({
        title: '¿Retirar <?php echo strtolower($etiqueta_equipo); ?>?',
        html: `
            <div class="text-left">
                <p>¿Está seguro de retirar la <?php echo strtolower($etiqueta_equipo); ?> <strong>${nombreEquipo}</strong> del torneo?</p>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Advertencia:</strong> Esta acción elimina por completo:
                    <ul class="mt-2 mb-0">
                        <li>El registro del <?php echo strtolower($etiqueta_equipo); ?> en <strong>equipos</strong></li>
                        <li>Los <strong>inscritos</strong> de todos los integrantes en este torneo (dejan de figurar en el listado)</li>
                        <li>Asignaciones y resultados de mesas de esos jugadores en este torneo, si existían</li>
                    </ul>
                    <p class="mb-0 mt-2 small text-muted">No se borran usuarios del sistema; solo la inscripción al torneo. No se puede deshacer.</p>
                </div>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check me-1"></i> Sí, retirar <?php echo strtolower($etiqueta_equipo); ?>',
        cancelButtonText: '<i class="fas fa-times me-1"></i> Cancelar',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
    });
    
    if (result.isConfirmed) {
        try {
            // Mostrar loading
            Swal.fire({
                title: 'Retirando <?php echo strtolower($etiqueta_equipo); ?>...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Enviar petición para eliminar equipo
            const formData = new FormData();
            formData.append('equipo_id', equipoId);
            formData.append('csrf_token', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>');
            
            const response = await fetch('<?= rtrim(AppHelpers::getPublicPath(), '/') . '/api/eliminar_equipo.php' ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            const raw = await response.text();
            let data;
            try {
                data = raw ? JSON.parse(raw) : {};
            } catch (parseErr) {
                Swal.fire({
                    icon: 'error',
                    title: 'Respuesta no válida',
                    html: '<pre style="text-align:left;font-size:0.8rem;max-height:200px;overflow:auto;">' + (raw || '(vacío)').replace(/</g, '&lt;') + '</pre>',
                    confirmButtonText: 'Aceptar'
                });
                return;
            }
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: '<?php echo $etiqueta_equipo; ?> retirada',
                    text: data.message || 'Equipo e inscripciones eliminados del torneo',
                    confirmButtonText: 'Aceptar'
                }).then(() => {
                    // Remover la fila del equipo de la vista
                    const row = document.getElementById('equipo-row-' + equipoId);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => {
                            row.remove();
                            // Actualizar números de equipos si es necesario
                            actualizarNumerosEquipos();
                        }, 300);
                    } else {
                        // Si no se encuentra la fila, recargar la página
                        window.location.reload();
                    }
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error al retirar <?php echo strtolower($etiqueta_equipo); ?>',
                    text: data.message || data.error || 'No se pudo retirar la <?php echo strtolower($etiqueta_equipo); ?>',
                    confirmButtonText: 'Aceptar'
                });
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Ocurrió un error al intentar retirar la <?php echo strtolower($etiqueta_equipo); ?>. Por favor, intente nuevamente.',
                confirmButtonText: 'Aceptar'
            });
        }
    }
}

function actualizarNumerosEquipos() {
    // Actualizar números secuenciales de equipos después de eliminar uno
    const clubSections = document.querySelectorAll('.club-section');
    let contador = 0;
    
    clubSections.forEach(clubSection => {
        const equipoRows = clubSection.querySelectorAll('.equipo-row');
        equipoRows.forEach(row => {
            contador++;
            const numeroElement = row.querySelector('.equipo-numero');
            if (numeroElement) {
                numeroElement.textContent = contador;
            }
        });
    });
}
</script>
