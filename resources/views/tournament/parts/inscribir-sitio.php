<?php
/**
 * Vista: Inscribir Jugador en Sitio
 * Formulario de búsqueda compacto (1 línea: nacionalidad, cédula, nombre, sexo).
 * Búsqueda: 1) inscritos 2) usuarios 3) BD externa 4) registro no registrado.
 * Listado Disponibles: solo club_id = 13.
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';

extract($view_data ?? []);

$torneo = $torneo ?? null;
$usuarios_disponibles = $usuarios_disponibles ?? [];
$usuarios_inscritos = $usuarios_inscritos ?? [];

if (empty($torneo) || !is_array($torneo) || !isset($torneo['id'])) {
    echo '<div class="alert alert-danger">Error: No se encontró el torneo o no se pudieron cargar los datos. <a href="' . htmlspecialchars($base_url) . '">Volver a Gestión de Torneos</a>.</div>';
    return;
}

require_once __DIR__ . '/../../lib/InscritosHelper.php';
// Base absoluta public/ para formularios y APIs (evitar que el navegador se pierda en subcarpetas)
$base_public_abs = (class_exists('AppHelpers') && method_exists('AppHelpers', 'getPublicUrl')) ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
if ($base_public_abs === '' && !empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_public_abs = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
}
$api_toggle_url = class_exists('AppHelpers')
    ? AppHelpers::url('tournament_admin_toggle_inscripcion.php')
    : rtrim($base_public_abs, '/') . '/tournament_admin_toggle_inscripcion.php';
$buscar_api_url = class_exists('AppHelpers')
    ? AppHelpers::api('inscribir_sitio_buscar.php')
    : rtrim($base_public_abs, '/') . '/api/inscribir_sitio_buscar.php';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($base_public_abs ? $base_public_abs . '/assets/css/design-system.css' : 'assets/css/design-system.css') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($base_public_abs ? $base_public_abs . '/assets/css/inscripcion.css' : 'assets/css/inscripcion.css') ?>">
<div class="ds-inscripcion container-fluid px-0 px-md-2" style="max-width: 100%;">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-user-plus text-success"></i> Inscribir Jugador en Sitio
                <small class="text-muted">- <?php echo htmlspecialchars($torneo['nombre']); ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
                    <li class="breadcrumb-item active">Inscribir en Sitio</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card w-100">
        <div class="card-header bg-success text-white py-2">
            <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Inscribir Jugador en Sitio</h5>
        </div>
        <div class="card-body px-2 px-md-3">
            <p class="small text-muted mb-2">Búsqueda: inscritos → usuarios → BD externa. Puede usar <strong>cédula</strong>, <strong>ID de usuario</strong> (p. ej. 6 dígitos) o <strong>fragmento de nombre</strong> (mín. 3 letras). Al salir del campo o Enter se ejecuta.</p>

            <!-- Una sola línea: Nacionalidad, Cédula, Club, y (al tener resultado) Nombre, Sexo, Inscribir, Otra búsqueda. Estatus siempre confirmado. -->
            <div class="insc-sitio-fila insc-sitio-una-linea mb-2" id="insc_sitio_linea_principal">
                <div class="insc-sitio-campo insc-sitio-nac">
                    <label class="form-label small mb-0">Nacionalidad</label>
                    <input type="text" id="select_nacionalidad_cedula" class="form-control form-control-sm" placeholder="V" value="V" maxlength="1" title="V, E, J o P" autocomplete="off">
                </div>
                <div class="insc-sitio-campo insc-sitio-cedula">
                    <label class="form-label small mb-0" for="input_cedula">Cédula / ID / nombre</label>
                    <input type="text" id="input_cedula" class="form-control form-control-sm" placeholder="Números, ID o nombre" maxlength="80" autocomplete="off" spellcheck="false">
                </div>
                <div class="insc-sitio-campo insc-sitio-club">
                    <label class="form-label small mb-0">Club</label>
                    <select id="select_club_cedula" class="form-select form-select-sm">
                        <option value="">-- Usar club del usuario --</option>
                        <?php foreach ($clubes_disponibles ?? [] as $club): ?>
                            <option value="<?= $club['id'] ?>"><?= htmlspecialchars($club['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="insc-sitio-campo insc-sitio-resultado d-none" id="wrap_acciones_cedula">
                    <label class="form-label small mb-0">Nombre</label>
                    <input type="text" id="input_nombre_line" class="form-control form-control-sm" placeholder="Se completa al buscar" readonly>
                </div>
                <div class="insc-sitio-campo insc-sitio-resultado d-none" id="wrap_sexo_cedula">
                    <label class="form-label small mb-0">Sexo</label>
                    <select id="select_sexo_line" class="form-select form-select-sm">
                        <option value="M">M</option>
                        <option value="F">F</option>
                        <option value="O">O</option>
                    </select>
                </div>
                <div class="insc-sitio-campo insc-sitio-resultado d-none d-flex align-items-end gap-1" id="wrap_btn_inscribir_cedula">
                    <button type="button" class="btn btn-success btn-sm" id="btn_inscribir_cedula"><i class="fas fa-save me-1"></i>Inscribir</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_otra_busqueda_cedula" title="Otra búsqueda"><i class="fas fa-redo"></i></button>
                </div>
            </div>

            <div id="mensaje_formulario_cedula" class="small mb-2 d-none" role="alert"></div>

            <!-- Formulario nuevo (no encontrado en usuarios ni BD externa) -->
            <div id="form_nuevo_usuario_inscribir" class="d-none card border-warning mt-2">
                <div class="card-header py-1 bg-warning text-dark small">Registrar e inscribir</div>
                <div class="card-body py-2">
                    <div class="insc-sitio-fila flex-wrap">
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">Nac.</label><select id="form_nac" class="form-select form-select-sm"><option value="V">V</option><option value="E">E</option><option value="J">J</option><option value="P">P</option></select></div>
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">Cédula</label><input type="text" id="form_cedula" class="form-control form-control-sm" placeholder="Solo números"></div>
                        <div class="insc-sitio-campo insc-sitio-campo-nombre"><label class="form-label small mb-0">Nombre <span class="text-danger">*</span></label><input type="text" id="form_nombre" class="form-control form-control-sm" required></div>
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">F. nac.</label><input type="date" id="form_fechnac" class="form-control form-control-sm"></div>
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">Sexo</label><select id="form_sexo" class="form-select form-select-sm"><option value="M">M</option><option value="F">F</option><option value="O">O</option></select></div>
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">Tel.</label><input type="text" id="form_telefono" class="form-control form-control-sm"></div>
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">Email</label><input type="email" id="form_email" class="form-control form-control-sm"></div>
                        <div class="insc-sitio-campo"><label class="form-label small mb-0">Club <span class="text-danger">*</span></label><select id="form_club" class="form-select form-select-sm"><option value="">-- Seleccione --</option><?php foreach ($clubes_disponibles ?? [] as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option><?php endforeach; ?></select></div>
                        <div class="insc-sitio-campo d-flex align-items-end">
                            <button type="button" class="btn btn-warning btn-sm" id="btn_registrar_inscribir"><i class="fas fa-user-plus me-1"></i>Registrar e inscribir</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm ms-1" id="btn_cancelar_form_nuevo">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Listados: Disponibles (club_id=13) e Inscritos -->
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header py-1 bg-primary text-white">
                            <span class="small">Atletas Disponibles (club 13)</span>
                            <span class="badge bg-light text-dark ms-1" id="count_disponibles"><?= count($usuarios_disponibles) ?></span>
                        </div>
                        <div class="card-body p-2" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light"><tr><th>Nombre</th><th>ID</th><th>Club</th></tr></thead>
                                <tbody id="tbody_disponibles">
                                    <?php foreach ($usuarios_disponibles as $u):
                                        $nom = !empty($u['nombre']) ? $u['nombre'] : $u['username'];
                                    ?>
                                        <tr class="table-row-hover" style="cursor:pointer" data-id="<?= $u['id'] ?>" data-nombre="<?= htmlspecialchars($nom) ?>" data-cedula="<?= htmlspecialchars($u['cedula'] ?? '') ?>" data-club-id="<?= $u['club_id'] ?? '' ?>">
                                            <td><strong><?= htmlspecialchars($nom) ?></strong></td>
                                            <td><code><?= $u['id'] ?></code></td>
                                            <td><?= !empty($u['club_nombre']) ? htmlspecialchars($u['club_nombre']) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header py-1 bg-success text-white">
                            <span class="small">Inscritos</span>
                            <span class="badge bg-light text-dark ms-1" id="count_inscritos"><?= count($usuarios_inscritos) ?></span>
                        </div>
                        <div class="card-body p-2" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light"><tr><th>Nombre</th><th>ID</th><th>Club</th></tr></thead>
                                <tbody id="tbody_inscritos">
                                    <?php foreach ($usuarios_inscritos as $i):
                                        $nom = !empty($i['nombre']) ? $i['nombre'] : $i['username'];
                                    ?>
                                        <tr class="table-row-hover" style="cursor:pointer" data-id="<?= $i['id_usuario'] ?>" data-nombre="<?= htmlspecialchars($nom) ?>" data-cedula="<?= htmlspecialchars($i['cedula'] ?? '') ?>" data-club-id="<?= $i['id_club'] ?? '' ?>">
                                            <td><strong><?= htmlspecialchars($nom) ?></strong></td>
                                            <td><code><?= $i['id_usuario'] ?></code></td>
                                            <td><?= !empty($i['club_nombre']) ? htmlspecialchars($i['club_nombre']) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.insc-sitio-fila { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.5rem; }
.insc-sitio-una-linea { width: 100%; }
.insc-sitio-campo { min-width: 0; }
.insc-sitio-campo .form-control, .insc-sitio-campo .form-select { width: 100%; }
/* Una línea: tamaños flexibles al espacio disponible */
.insc-sitio-nac { flex: 0 1 8%; min-width: 3rem; }
.insc-sitio-cedula { flex: 0 1 18%; min-width: 5rem; }
.insc-sitio-club { flex: 1 1 20%; min-width: 6rem; }
.insc-sitio-resultado { flex: 0 1 18%; min-width: 4rem; }
.insc-sitio-resultado#wrap_btn_inscribir_cedula { flex: 0 0 auto; }
.table-row-hover:hover { background-color: #e3f2fd !important; }
@media (min-width: 768px) {
  .ds-inscripcion .card-body { padding-left: 0.75rem; padding-right: 0.75rem; }
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
</style>

<script>
(function() {
    var BASE_PUBLIC = <?= json_encode($base_public_abs ? $base_public_abs . '/' : '') ?>;
    var TORNEOS_ID = <?= (int)$torneo['id'] ?>;
    var CSRF_TOKEN = '<?= htmlspecialchars(CSRF::token(), ENT_QUOTES) ?>';
    var API_URL = <?= json_encode($api_toggle_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var BUSCAR_API = <?= json_encode($buscar_api_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var isSearching = false;
    var usuarioEncontrado = null;

    function $(id) { return document.getElementById(id); }
    function msg(html, type) {
        var el = $('mensaje_formulario_cedula');
        if (!el) return;
        el.innerHTML = html;
        el.className = 'small mb-2 alert alert-' + (type || 'info');
        el.classList.remove('d-none');
    }
    function msgHide() {
        var el = $('mensaje_formulario_cedula');
        if (el) { el.classList.add('d-none'); el.innerHTML = ''; }
    }
    function fillLine(nombre, sexo) {
        var n = $('input_nombre_line');
        var s = $('select_sexo_line');
        if (n) n.value = nombre || '';
        if (s) s.value = (sexo || 'M').toUpperCase();
    }
    function fillFormNuevo(p) {
        p = p || {};
        if ($('form_nac')) $('form_nac').value = p.nacionalidad || 'V';
        if ($('form_cedula')) $('form_cedula').value = p.cedula || '';
        if ($('form_nombre')) $('form_nombre').value = p.nombre || '';
        if ($('form_fechnac')) $('form_fechnac').value = p.fechnac || '';
        if ($('form_sexo')) $('form_sexo').value = (p.sexo || 'M').toUpperCase();
        if ($('form_telefono')) $('form_telefono').value = p.telefono || p.celular || '';
        if ($('form_email')) $('form_email').value = p.email || '';
    }
    function showInscribir(show) {
        ['wrap_acciones_cedula', 'wrap_sexo_cedula', 'wrap_btn_inscribir_cedula'].forEach(function(id) {
            var w = $(id);
            if (w) w.classList.toggle('d-none', !show);
        });
    }
    function showFormNuevo(show) {
        var w = $('form_nuevo_usuario_inscribir');
        if (w) w.classList.toggle('d-none', !show);
    }
    function limpiarBusqueda() {
        if ($('input_cedula')) $('input_cedula').value = '';
        if ($('select_nacionalidad_cedula')) $('select_nacionalidad_cedula').value = 'V';
        fillLine('', 'M');
        msgHide();
        showInscribir(false);
        showFormNuevo(false);
        usuarioEncontrado = null;
    }

    function normalizarNacionalidad(val) {
        var v = (val || '').trim().toUpperCase();
        return ['V','E','J','P'].indexOf(v) >= 0 ? v : 'V';
    }
    function buscar() {
        if (isSearching) return;
        var nacEl = $('select_nacionalidad_cedula');
        var nac = normalizarNacionalidad(nacEl ? nacEl.value : '');
        if (nacEl) nacEl.value = nac;
        var raw = ($('input_cedula') && $('input_cedula').value) ? $('input_cedula').value.trim() : '';
        if (raw.length < 1) return;
        if (raw.length < 3 && /[a-zA-Z\u00C0-\u024F]/.test(raw)) {
            msg('Escriba al menos 3 letras para buscar por nombre.', 'warning');
            return;
        }
        if (raw.length < 3 && /^[0-9]+$/.test(raw)) {
            msg('Escriba al menos 3 dígitos (cédula o ID).', 'warning');
            return;
        }
        var ced = raw.replace(/\D/g, '');
        isSearching = true;
        msg('<i class="fas fa-spinner fa-spin me-1"></i>Buscando (inscritos → usuarios → BD externa)...', 'info');
        showInscribir(false);
        showFormNuevo(false);
        usuarioEncontrado = null;

        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = controller ? window.setTimeout(function() { controller.abort(); }, 20000) : null;
        var fetchOpts = { credentials: 'same-origin', cache: 'no-store' };
        if (controller) fetchOpts.signal = controller.signal;

        var qs = 'torneo_id=' + TORNEOS_ID + '&nacionalidad=' + encodeURIComponent(nac) + '&busqueda=' + encodeURIComponent(raw) + '&cedula=' + encodeURIComponent(ced);
        if (/^[0-9]{1,6}$/.test(raw)) {
            qs += '&user_id=' + encodeURIComponent(raw);
        }
        fetch(BUSCAR_API + '?' + qs, fetchOpts)
            .then(function(r) {
                if (timeoutId) window.clearTimeout(timeoutId);
                return r.text().then(function(text) {
                    var data;
                    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { accion: 'error', mensaje: r.status >= 400 ? ('Error del servidor (' + r.status + ')') : 'Respuesta no válida.' }; }
                    return data;
                });
            })
            .then(function(data) {
                if ((data.accion || data.status) === 'error') {
                    isSearching = false;
                    msg(data.mensaje || data.error || 'Error del servidor. Intente de nuevo.', 'danger');
                    return;
                }
                isSearching = false;
                var accion = (data.accion || data.status || '').toLowerCase();
                if (accion === 'ya_inscrito') {
                    msg(data.mensaje || 'Ya inscrito en este torneo.', 'warning');
                    limpiarBusqueda();
                    return;
                }
                if (accion === 'error') {
                    msg(data.mensaje || 'Error.', 'danger');
                    return;
                }
                if (accion === 'encontrado_usuario' && data.persona) {
                    var p = data.persona;
                    usuarioEncontrado = { id: p.id, nombre: p.nombre, username: p.username, cedula: p.cedula, club_id: p.club_id };
                    fillLine(p.nombre || '', p.sexo || 'M');
                    fillFormNuevo(p);
                    msg(data.mensaje || 'Encontrado en plataforma.', 'success');
                    showInscribir(true);
                    showFormNuevo(false);
                    return;
                }
                if (accion === 'encontrado_persona' && data.persona) {
                    var p = data.persona;
                    p.cedula = ced;
                    p.nacionalidad = nac;
                    fillLine(p.nombre || '', p.sexo || 'M');
                    fillFormNuevo(p);
                    msg(data.mensaje || 'Encontrado en BD externa. Complete y pulse Registrar e inscribir.', 'info');
                    showInscribir(false);
                    showFormNuevo(true);
                    usuarioEncontrado = null;
                    if ($('form_nombre')) $('form_nombre').focus();
                    return;
                }
                if (accion === 'nuevo' || accion === 'no_encontrado') {
                    fillFormNuevo({ nacionalidad: nac, cedula: ced });
                    msg(data.mensaje || 'No encontrado. Complete los datos y pulse Registrar e inscribir.', 'warning');
                    showInscribir(false);
                    showFormNuevo(true);
                    usuarioEncontrado = null;
                    if ($('form_nombre')) $('form_nombre').focus();
                    return;
                }
                msgHide();
            })
            .catch(function(err) {
                if (timeoutId) window.clearTimeout(timeoutId);
                isSearching = false;
                var isTimeout = err && err.name === 'AbortError';
                msg(isTimeout ? 'La búsqueda tardó demasiado. Intente de nuevo.' : 'Error de conexión. Compruebe la red o intente de nuevo.', 'danger');
            });
    }

    function showMessage(txt, type) {
        var c = document.querySelector('.card-body');
        if (!c) return;
        var d = document.createElement('div');
        d.className = 'alert alert-' + type + ' alert-dismissible fade show py-2';
        d.innerHTML = txt + ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        c.insertBefore(d, c.firstChild);
        setTimeout(function() { d.remove(); }, 3500);
    }

    var tbodyDisp = $('tbody_disponibles');
    var tbodyInsc = $('tbody_inscritos');

    function updateCounters() {
        var cd = $('count_disponibles');
        var ci = $('count_inscritos');
        if (cd && tbodyDisp) cd.textContent = tbodyDisp.children.length;
        if (ci && tbodyInsc) ci.textContent = tbodyInsc.children.length;
    }
    function agregarFilaInscrito(id, nombre, cedula, clubId) {
        if (!tbodyInsc) return;
        var tr = document.createElement('tr');
        tr.className = 'table-row-hover';
        tr.style.cursor = 'pointer';
        tr.dataset.id = id;
        tr.dataset.nombre = nombre;
        tr.dataset.cedula = cedula || '';
        tr.dataset.clubId = clubId || '';
        tr.innerHTML = '<td><strong>' + (nombre || '') + '</strong></td><td><code>' + id + '</code></td><td>—</td>';
        tbodyInsc.appendChild(tr);
    }

    function inscribirJugador(idUsuario, nombre, cedula, clubId, rowEl) {
        var fd = new FormData();
        fd.append('action', 'inscribir');
        fd.append('torneo_id', TORNEOS_ID);
        fd.append('id_usuario', idUsuario);
        if (clubId) fd.append('id_club', clubId);
        fd.append('estatus', '1');
        fd.append('csrf_token', CSRF_TOKEN);
        if (rowEl) { rowEl.style.opacity = '0.5'; rowEl.style.pointerEvents = 'none'; }
        fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) {
                var ct = r.headers.get('Content-Type') || '';
                if (!r.ok) { return r.text().then(function(t) { throw new Error(t || 'Error ' + r.status); }); }
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text().then(function(t) { throw new Error(t || 'Respuesta no JSON'); });
            })
            .then(function(data) {
                if (rowEl) { rowEl.style.opacity = '1'; rowEl.style.pointerEvents = 'auto'; }
                if (data.success) {
                    if (tbodyInsc && rowEl) {
                        var clon = rowEl.cloneNode(true);
                        tbodyInsc.appendChild(clon);
                        rowEl.remove();
                    }
                    updateCounters();
                    showMessage('Jugador inscrito.', 'success');
                } else {
                    showMessage(data.error || 'Error al inscribir', 'danger');
                }
            })
            .catch(function(err) {
                if (rowEl) { rowEl.style.opacity = '1'; rowEl.style.pointerEvents = 'auto'; }
                showMessage(err && err.message ? err.message : 'Error de conexión. Compruebe la red o que la sesión siga activa.', 'danger');
            });
    }
    function desinscribirJugador(idUsuario, nombre, cedula, clubId, rowEl) {
        if (!confirm('¿Desinscribir a ' + nombre + '?')) return;
        var fd = new FormData();
        fd.append('action', 'desinscribir');
        fd.append('torneo_id', TORNEOS_ID);
        fd.append('id_usuario', idUsuario);
        fd.append('csrf_token', CSRF_TOKEN);
        if (rowEl) { rowEl.style.opacity = '0.5'; rowEl.style.pointerEvents = 'none'; }
        fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) return r.text().then(function(t) { throw new Error(t || 'Error ' + r.status); });
                var ct = r.headers.get('Content-Type') || '';
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text().then(function(t) { throw new Error(t || 'Respuesta no JSON'); });
            })
            .then(function(data) {
                if (rowEl) { rowEl.style.opacity = '1'; rowEl.style.pointerEvents = 'auto'; }
                if (data.success) {
                    if (tbodyDisp && rowEl) {
                        var clon = rowEl.cloneNode(true);
                        tbodyDisp.appendChild(clon);
                        rowEl.remove();
                    }
                    updateCounters();
                    showMessage('Jugador desinscrito.', 'success');
                } else {
                    showMessage(data.error || 'Error', 'danger');
                }
            })
            .catch(function(err) {
                if (rowEl) { rowEl.style.opacity = '1'; rowEl.style.pointerEvents = 'auto'; }
                showMessage(err && err.message ? err.message : 'Error de conexión. Compruebe la sesión.', 'danger');
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var nacInput = $('select_nacionalidad_cedula');
        if (nacInput) {
            nacInput.addEventListener('blur', function() { nacInput.value = normalizarNacionalidad(nacInput.value); });
        }
        var inputCed = $('input_cedula');
        if (inputCed) {
            inputCed.addEventListener('blur', buscar);
            inputCed.addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); buscar(); } });
        }
        if ($('btn_otra_busqueda_cedula')) $('btn_otra_busqueda_cedula').addEventListener('click', limpiarBusqueda);

        if ($('btn_inscribir_cedula')) {
            $('btn_inscribir_cedula').addEventListener('click', function() {
                if (!usuarioEncontrado || !usuarioEncontrado.id) {
                    showMessage('Busque primero por cédula.', 'warning');
                    return;
                }
                var clubId = ($('select_club_cedula') && $('select_club_cedula').value) || '';
                var fd = new FormData();
                fd.append('action', 'inscribir');
                fd.append('torneo_id', TORNEOS_ID);
                fd.append('id_usuario', usuarioEncontrado.id);
                fd.append('id_club', clubId);
                fd.append('estatus', '1');
                fd.append('csrf_token', CSRF_TOKEN);
                fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) {
                        if (!r.ok) return r.text().then(function(t) { throw new Error(t || 'Error ' + r.status); });
                        var ct = r.headers.get('Content-Type') || '';
                        if (ct.indexOf('application/json') !== -1) return r.json();
                        return r.text().then(function(t) { throw new Error(t || 'Respuesta no JSON'); });
                    })
                    .then(function(data) {
                        if (data.success) {
                            agregarFilaInscrito(usuarioEncontrado.id, usuarioEncontrado.nombre || usuarioEncontrado.username || 'Usuario', ($('input_cedula') && $('input_cedula').value) || '', clubId);
                            limpiarBusqueda();
                            showMessage('Jugador inscrito.', 'success');
                            updateCounters();
                        } else {
                            showMessage(data.error || 'Error', 'danger');
                        }
                    })
                    .catch(function(err) { showMessage(err && err.message ? err.message : 'Error de conexión. Compruebe la sesión.', 'danger'); });
            });
        }

        if ($('btn_registrar_inscribir')) {
            $('btn_registrar_inscribir').addEventListener('click', function() {
                var nac = ($('form_nac') && $('form_nac').value) || 'V';
                var ced = ($('form_cedula') && $('form_cedula').value).replace(/\D/g, '');
                var nom = ($('form_nombre') && $('form_nombre').value) || '';
                if (ced.length < 4 || nom.length < 2) {
                    showMessage('Cédula (mín. 4 dígitos) y nombre obligatorios.', 'warning');
                    return;
                }
                var clubId = ($('form_club') && $('form_club').value) || '';
                if (!clubId) {
                    showMessage('Seleccione un club.', 'warning');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'registrar_inscribir');
                fd.append('torneo_id', TORNEOS_ID);
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('nacionalidad', nac);
                fd.append('cedula', ced);
                fd.append('nombre', nom.trim());
                fd.append('fechnac', ($('form_fechnac') && $('form_fechnac').value) || '');
                fd.append('sexo', ($('form_sexo') && $('form_sexo').value) || 'M');
                fd.append('telefono', ($('form_telefono') && $('form_telefono').value) || '');
                fd.append('email', ($('form_email') && $('form_email').value) || '');
                fd.append('id_club', clubId);
                fd.append('estatus', 1);
                fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) {
                        if (!r.ok) return r.text().then(function(t) { throw new Error(t || 'Error ' + r.status); });
                        var ct = r.headers.get('Content-Type') || '';
                        if (ct.indexOf('application/json') !== -1) return r.json();
                        return r.text().then(function(t) { throw new Error(t || 'Respuesta no JSON'); });
                    })
                    .then(function(data) {
                        if (data.success) {
                            var idUser = data.id_usuario || 0;
                            agregarFilaInscrito(idUser, nom, nac + ced, clubId);
                            limpiarBusqueda();
                            showMessage(data.message || 'Registrado e inscrito.', 'success');
                            updateCounters();
                        } else {
                            showMessage(data.error || 'Error', 'danger');
                        }
                    })
                    .catch(function(err) { showMessage(err && err.message ? err.message : 'Error de conexión. Compruebe la sesión.', 'danger'); });
            });
        }
        if ($('btn_cancelar_form_nuevo')) $('btn_cancelar_form_nuevo').addEventListener('click', function() { showFormNuevo(false); msgHide(); });

        if (tbodyDisp) {
            tbodyDisp.addEventListener('click', function(e) {
                var row = e.target.closest('tr');
                if (row && row.dataset.id) {
                    inscribirJugador(parseInt(row.dataset.id), row.dataset.nombre, row.dataset.cedula || '', row.dataset.clubId || '', row);
                }
            });
        }
        if (tbodyInsc) {
            tbodyInsc.addEventListener('click', function(e) {
                var row = e.target.closest('tr');
                if (row && row.dataset.id) {
                    desinscribirJugador(parseInt(row.dataset.id), row.dataset.nombre, row.dataset.cedula || '', row.dataset.clubId || '', row);
                }
            });
        }
    });
})();
</script>
