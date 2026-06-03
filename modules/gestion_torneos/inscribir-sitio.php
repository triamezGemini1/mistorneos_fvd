<?php
/**
 * Vista: Inscribir Jugador en Sitio
 * Formulario de búsqueda compacto (1 línea: nacionalidad, cédula, nombre, sexo).
 * Búsqueda: 1) inscritos 2) usuarios 3) BD externa 4) registro no registrado.
 * Listado Disponibles: atletas del ámbito del torneo. Club = clubes.id (código de asociación).
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
extract($view_data ?? []);

$torneo = $torneo ?? null;
$usuarios_disponibles = $usuarios_disponibles ?? [];
$usuarios_inscritos = $usuarios_inscritos ?? [];
$inscripcion_operativo_asoc = !empty($inscripcion_operativo_asoc);
$club_forzado_id = (int) ($club_forzado_id ?? 0);
$club_forzado_nombre = (string) ($club_forzado_nombre ?? '');

if (empty($torneo) || !is_array($torneo) || !isset($torneo['id'])) {
    echo '<div class="alert alert-danger">Error: No se encontró el torneo o no se pudieron cargar los datos. <a href="' . htmlspecialchars($base_url) . '">Volver a Gestión de Torneos</a>.</div>';
    return;
}
$tid_torneo_nav = (int) $torneo['id'];
$url_panel_torneo = class_exists('AppHelpers')
    ? AppHelpers::urlPanelTorneoReturn($tid_torneo_nav)
    : ($base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $tid_torneo_nav);

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
<link rel="stylesheet" href="<?= htmlspecialchars($base_public_abs ? $base_public_abs . '/assets/css/inscripcion.css' : 'assets/css/inscripcion.css') ?>">
<style>
.insc-sitio-bloqueado { opacity: 0.92; background-color: #f8f9fa !important; }
.insc-sitio-bloqueado:hover { background-color: #f1f3f5 !important; }
</style>
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
                    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($url_panel_torneo); ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
                    <li class="breadcrumb-item active">Inscribir en Sitio</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <a href="<?php echo htmlspecialchars($url_panel_torneo); ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left mr-2"></i> Retornar al Panel
            </a>
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
            <p class="small text-muted mb-2">Busque por <strong>cédula</strong>, <strong>ID</strong> o <strong>nombre</strong> (mín. 3 caracteres). No hace falta elegir asociación antes: al encontrar al atleta se completa el selector y puede cambiarla al inscribir. Clic en <strong>Disponibles</strong> para inscribir.</p>

            <div class="insc-sitio-fila insc-sitio-una-linea mb-2" id="insc_sitio_linea_principal">
                <div class="insc-sitio-campo insc-sitio-nac">
                    <label class="form-label small mb-0">Nacionalidad</label>
                    <input type="text" id="select_nacionalidad_cedula" class="form-control form-control-sm" placeholder="V" value="V" maxlength="1" title="V, E, J o P" autocomplete="off">
                </div>
                <div class="insc-sitio-campo insc-sitio-cedula flex-grow-1">
                    <label class="form-label small mb-0" for="input_cedula">Buscar atleta</label>
                    <input type="text" id="input_cedula" class="form-control form-control-sm" placeholder="Cédula, ID o nombre" maxlength="80" autocomplete="off" spellcheck="false">
                </div>
                <div class="insc-sitio-campo insc-sitio-club">
                    <label class="form-label small mb-0">Asociación</label>
                    <?php if ($inscripcion_operativo_asoc && $club_forzado_id > 0): ?>
                        <input type="hidden" id="select_club_cedula" value="<?= $club_forzado_id ?>">
                        <div class="form-control form-control-sm bg-light" readonly><?= htmlspecialchars($club_forzado_nombre) ?></div>
                    <?php else: ?>
                    <select id="select_club_cedula" class="form-select form-select-sm" title="Se completa al buscar; puede cambiarla antes de inscribir">
                        <option value="">-- Asociación (al inscribir) --</option>
                        <?php foreach ($clubes_disponibles ?? [] as $club): ?>
                            <option value="<?= (int) $club['id'] ?>"><?= (int) $club['id'] ?> — <?= htmlspecialchars($club['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="insc-sitio-campo d-flex align-items-end">
                    <button type="button" class="btn btn-primary btn-sm" id="btn_buscar_atleta"><i class="fas fa-search me-1"></i>Buscar</button>
                </div>
            </div>

            <div id="mensaje_formulario_cedula" class="small mb-2 d-none" role="alert"></div>

            <!-- Listados: Disponibles (club_id=13) e Inscritos -->
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header py-1 bg-primary text-white d-flex flex-wrap align-items-center gap-2">
                            <span class="small">Atletas disponibles<?= $inscripcion_operativo_asoc && $club_forzado_nombre !== '' ? ' · ' . htmlspecialchars($club_forzado_nombre) : '' ?></span>
                            <span class="badge bg-light text-dark" id="count_disponibles">0</span>
                        </div>
                        <div class="card-body p-2" style="max-height: 320px; overflow-y: auto;">
                            <div id="disponibles_loading" class="small text-muted py-2 d-none"><i class="fas fa-spinner fa-spin me-1"></i>Buscando…</div>
                            <div id="disponibles_empty" class="small text-muted py-2">Seleccione una asociación o use el buscador. Aquí aparecen atletas no inscritos (incluye retirados).</div>
                            <table class="table table-sm table-hover mb-0 d-none" id="tabla_disponibles">
                                <thead class="table-light"><tr><th>Nombre</th><th>ID</th><th>Cédula</th><th>Asoc.</th></tr></thead>
                                <tbody id="tbody_disponibles"></tbody>
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
                                <thead class="table-light"><tr><th>Nombre</th><th>ID</th><th>Asociación</th><th class="text-center">Estatus</th><th class="text-center">Retirar</th></tr></thead>
                                <tbody id="tbody_inscritos">
                                    <?php foreach ($usuarios_inscritos as $i):
                                        $nom = !empty($i['nombre']) ? $i['nombre'] : $i['username'];
                                        $cidInsc = (int) ($i['id_club'] ?? 0);
                                        $estInsc = $i['estatus'] ?? 0;
                                        $esConfInsc = InscritosHelper::esConfirmado($estInsc);
                                    ?>
                                        <tr class="table-row-hover insc-sitio-row-inscrito<?= $esConfInsc ? ' insc-sitio-bloqueado' : '' ?>"
                                            data-id="<?= $i['id_usuario'] ?>"
                                            data-confirmado="<?= $esConfInsc ? '1' : '0' ?>"
                                            data-nombre="<?= htmlspecialchars($nom) ?>"
                                            data-cedula="<?= htmlspecialchars($i['cedula'] ?? '') ?>"
                                            data-club-id="<?= $cidInsc ?>">
                                            <td><strong><?= htmlspecialchars($nom) ?></strong></td>
                                            <td><code><?= $i['id_usuario'] ?></code></td>
                                            <td class="insc-club-cell">
                                                <?php if ($inscripcion_operativo_asoc && $club_forzado_id > 0): ?>
                                                    <?= htmlspecialchars($club_forzado_id . ' — ' . $club_forzado_nombre) ?>
                                                <?php else: ?>
                                                    <select class="form-select form-select-sm club-change-select" data-user-id="<?= (int) $i['id_usuario'] ?>" title="Cambiar asociación"<?= $esConfInsc ? ' disabled' : '' ?>>
                                                        <?php foreach ($clubes_disponibles ?? [] as $c):
                                                            $cidOpt = (int) $c['id'];
                                                        ?>
                                                            <option value="<?= $cidOpt ?>"<?= $cidInsc === $cidOpt ? ' selected' : '' ?>><?= $cidOpt ?> — <?= htmlspecialchars($c['nombre']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $esConfInsc ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                    <?= $esConfInsc ? 'Confirmado' : 'Pagar' ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!$esConfInsc): ?>
                                                <button type="button" class="btn btn-sm btn-outline-dark js-retirar-inscrito-sitio"
                                                        data-id="<?= (int) $i['id_usuario'] ?>"
                                                        title="Retirar del torneo y liberar en disponibles">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                                <?php else: ?>
                                                <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
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

<script>
(function() {
    var BASE_PUBLIC = <?= json_encode($base_public_abs ? $base_public_abs . '/' : '') ?>;
    var TORNEOS_ID = <?= (int)$torneo['id'] ?>;
    var CLUB_FORZADO_ID = <?= $club_forzado_id > 0 ? (int)$club_forzado_id : 'null' ?>;
    var PERMITE_CAMBIAR_CLUB = <?= $inscripcion_operativo_asoc ? 'false' : 'true' ?>;
    var CLUBES_LIST = <?= json_encode(array_values(array_map(static function ($c) {
        return ['id' => (int) ($c['id'] ?? 0), 'nombre' => (string) ($c['nombre'] ?? '')];
    }, $clubes_disponibles ?? [])), JSON_UNESCAPED_UNICODE) ?>;
    var CSRF_TOKEN = '<?= htmlspecialchars(CSRF::token(), ENT_QUOTES) ?>';
    var API_URL = <?= json_encode($api_toggle_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var BUSCAR_API = <?= json_encode($buscar_api_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var isSearching = false;
    var panelClubManual = false;
    var buscarTimer = null;

    function $(id) { return document.getElementById(id); }
    function inscSitioRetirarBtnHtml(userId, bloqueado) {
        if (bloqueado) {
            return '<span class="text-muted small">—</span>';
        }
        return '<button type="button" class="btn btn-sm btn-outline-dark js-retirar-inscrito-sitio" data-id="' + userId + '" title="Retirar del torneo y liberar en disponibles"><i class="fas fa-user-slash"></i></button>';
    }
    function fetchJson(url, options) {
        return fetch(url, options || { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) {
                var ct = r.headers.get('Content-Type') || '';
                if (!r.ok) {
                    return r.text().then(function(t) {
                        throw new Error(t.replace(/<[^>]+>/g, '').trim() || ('Error ' + r.status));
                    });
                }
                if (ct.indexOf('application/json') === -1) {
                    return r.text().then(function(t) {
                        throw new Error(t.replace(/<[^>]+>/g, '').trim() || 'Respuesta no JSON del servidor');
                    });
                }
                return r.json();
            });
    }
    function clubSeleccionPanel() {
        if (CLUB_FORZADO_ID) return String(CLUB_FORZADO_ID);
        var el = $('select_club_cedula');
        return (el && el.value) ? String(el.value) : '';
    }
    function clubIdInscripcion(fallback) {
        if (CLUB_FORZADO_ID) return String(CLUB_FORZADO_ID);
        var el = $('select_club_cedula');
        if (panelClubManual && el && el.value) return String(el.value);
        if (fallback) return String(fallback);
        if (el && el.value) return String(el.value);
        return '';
    }
    function asegurarOpcionClub(selectEl, clubId, clubNombre) {
        if (!selectEl || !clubId) return;
        var v = String(clubId);
        if (!selectEl.querySelector('option[value="' + v + '"]')) {
            var opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v + ' — ' + (clubNombre || ('Asociación ' + v));
            selectEl.appendChild(opt);
        }
    }
    function setClubSeleccionado(clubId, clubNombre) {
        if (CLUB_FORZADO_ID || !clubId) return;
        var sel = $('select_club_cedula');
        if (!sel) return;
        asegurarOpcionClub(sel, String(clubId), clubNombre);
        sel.value = String(clubId);
    }
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function setDisponiblesUi(estado, mensaje) {
        var loading = $('disponibles_loading');
        var empty = $('disponibles_empty');
        var tabla = $('tabla_disponibles');
        if (loading) loading.classList.toggle('d-none', estado !== 'loading');
        if (empty) {
            empty.classList.toggle('d-none', estado === 'loading' || estado === 'ok');
            if (mensaje) empty.textContent = mensaje;
        }
        if (tabla) tabla.classList.toggle('d-none', estado !== 'ok');
    }
    function agregarFilaDisponible(it) {
        if (!tbodyDisp || !it || !it.id) return;
        var existente = tbodyDisp.querySelector('tr[data-id="' + String(it.id) + '"]');
        if (existente) return;
        var tr = document.createElement('tr');
        tr.className = 'table-row-hover';
        tr.style.cursor = 'pointer';
        tr.title = 'Clic para inscribir';
        tr.dataset.id = String(it.id);
        tr.dataset.nombre = it.nombre || '';
        tr.dataset.cedula = it.cedula || '';
        tr.dataset.clubId = String(it.club_id || '');
        tr.dataset.clubNombre = String(it.club_nombre || '');
        var asocTxt = it.club_id ? (it.club_id + (it.club_nombre ? ' — ' + it.club_nombre : '')) : '—';
        tr.innerHTML = '<td><strong>' + escHtml(it.nombre) + '</strong></td>'
            + '<td><code>' + escHtml(it.id) + '</code></td>'
            + '<td>' + escHtml(it.cedula || '—') + '</td>'
            + '<td class="small">' + escHtml(asocTxt) + '</td>';
        tbodyDisp.appendChild(tr);
        setDisponiblesUi('ok');
        updateCounters();
    }
    function renderDisponibles(items) {
        if (!tbodyDisp) return;
        tbodyDisp.innerHTML = '';
        (items || []).forEach(function(it) {
            var tr = document.createElement('tr');
            tr.className = 'table-row-hover';
            tr.style.cursor = 'pointer';
            tr.title = 'Clic para inscribir';
            tr.dataset.id = String(it.id);
            tr.dataset.nombre = it.nombre || '';
            tr.dataset.cedula = it.cedula || '';
            tr.dataset.clubId = String(it.club_id || '');
            tr.dataset.clubNombre = String(it.club_nombre || '');
            var asocTxt = it.club_id ? (it.club_id + (it.club_nombre ? ' — ' + it.club_nombre : '')) : '—';
            tr.innerHTML = '<td><strong>' + escHtml(it.nombre) + '</strong></td>'
                + '<td><code>' + escHtml(it.id) + '</code></td>'
                + '<td>' + escHtml(it.cedula || '—') + '</td>'
                + '<td class="small">' + escHtml(asocTxt) + '</td>';
            tbodyDisp.appendChild(tr);
        });
    }
    function limpiarDisponibles(msg) {
        if (tbodyDisp) tbodyDisp.innerHTML = '';
        setDisponiblesUi('vacio', msg || 'Use el buscador superior.');
        updateCounters();
    }
    function clubSelectHtml(selectedId, userId) {
        if (!PERMITE_CAMBIAR_CLUB) {
            var cid = parseInt(selectedId, 10) || 0;
            var nom = '';
            CLUBES_LIST.forEach(function(c) { if (String(c.id) === String(cid)) nom = c.nombre; });
            return cid > 0 ? (cid + ' — ' + nom) : '—';
        }
        var sel = '<select class="form-select form-select-sm club-change-select" data-user-id="' + userId + '" title="Cambiar asociación">';
        CLUBES_LIST.forEach(function(c) {
            var v = String(c.id);
            sel += '<option value="' + v + '"' + (String(selectedId) === v ? ' selected' : '') + '>' + v + ' — ' + (c.nombre || '') + '</option>';
        });
        return sel + '</select>';
    }
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
    function limpiarBusqueda() {
        if ($('input_cedula')) $('input_cedula').value = '';
        if ($('select_nacionalidad_cedula')) $('select_nacionalidad_cedula').value = 'V';
        msgHide();
        limpiarDisponibles('Seleccione una asociación o use el buscador.');
    }

    function cargarDisponiblesPorClub() {
        var clubOpt = clubSeleccionPanel();
        if (!clubOpt) {
            limpiarDisponibles('Seleccione una asociación para ver atletas disponibles.');
            return;
        }
        isSearching = true;
        setDisponiblesUi('loading');
        var qs = 'modo=disponibles&torneo_id=' + TORNEOS_ID + '&id_club=' + encodeURIComponent(clubOpt);
        fetchJson(BUSCAR_API + '?' + qs)
            .then(function(data) {
                isSearching = false;
                if (!data.success) {
                    limpiarDisponibles(data.error || 'No se pudo cargar la lista.');
                    return;
                }
                if (!data.items || !data.items.length) {
                    limpiarDisponibles(data.mensaje || 'Sin atletas disponibles.');
                    return;
                }
                renderDisponibles(data.items);
                setDisponiblesUi('ok');
                updateCounters();
            })
            .catch(function(err) {
                isSearching = false;
                limpiarDisponibles(err && err.message ? err.message : 'Error de conexión.');
            });
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
        panelClubManual = false;
        var clubOpt = clubSeleccionPanel();
        isSearching = true;
        msg('<i class="fas fa-spinner fa-spin me-1"></i>Buscando…', 'info');
        setDisponiblesUi('loading');

        var qs = 'torneo_id=' + TORNEOS_ID + '&nacionalidad=' + encodeURIComponent(nac) + '&busqueda=' + encodeURIComponent(raw);
        if (clubOpt && panelClubManual) qs += '&id_club=' + encodeURIComponent(clubOpt);
        fetchJson(BUSCAR_API + '?' + qs)
            .then(function(data) {
                isSearching = false;
                if (!data.success) {
                    msg(data.error || 'Error en la búsqueda.', 'danger');
                    limpiarDisponibles(data.error || 'Error en la búsqueda.');
                    return;
                }
                if (data.ya_inscrito) {
                    msg(data.mensaje || 'Ya está inscrito en este torneo.', 'warning');
                    limpiarDisponibles('El atleta ya figura como inscrito activo.');
                    return;
                }
                if (!data.items || !data.items.length) {
                    msg(data.mensaje || 'Sin resultados disponibles para inscribir.', 'info');
                    limpiarDisponibles(data.mensaje || 'Sin resultados.');
                    return;
                }
                var primero = data.items[0];
                if (primero && primero.club_id && !panelClubManual) {
                    setClubSeleccionado(primero.club_id, primero.club_nombre || '');
                }
                renderDisponibles(data.items);
                setDisponiblesUi('ok');
                msg((data.mensaje || 'Seleccione un atleta en la lista para inscribir.') + ' Puede cambiar la asociación en el selector si lo solicita el interesado.', 'success');
                updateCounters();
            })
            .catch(function() {
                isSearching = false;
                msg('Error de conexión.', 'danger');
                limpiarDisponibles('Error de conexión.');
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
        if (cd && tbodyDisp) {
            cd.textContent = tbodyDisp.querySelectorAll('tr[data-id]').length;
        }
        if (ci && tbodyInsc) ci.textContent = tbodyInsc.children.length;
    }
    function agregarFilaInscrito(id, nombre, cedula, clubId) {
        if (!tbodyInsc) return;
        var tr = document.createElement('tr');
        tr.className = 'table-row-hover insc-sitio-row-inscrito';
        tr.dataset.id = id;
        tr.dataset.confirmado = '0';
        tr.dataset.nombre = nombre;
        tr.dataset.cedula = cedula || '';
        tr.dataset.clubId = clubId || '';
        tr.innerHTML = '<td><strong>' + escHtml(nombre || '') + '</strong></td><td><code>' + id + '</code></td><td class="insc-club-cell">' + clubSelectHtml(clubId, id) + '</td><td class="text-center"><span class="badge bg-warning text-dark">Pagar</span></td><td class="text-center">' + inscSitioRetirarBtnHtml(id, false) + '</td>';
        tbodyInsc.appendChild(tr);
    }

    function cambiarClubInscrito(idUsuario, nuevoClubId, selectEl) {
        if (!PERMITE_CAMBIAR_CLUB || !nuevoClubId) return;
        var prev = selectEl ? selectEl.dataset.prevClub || selectEl.value : '';
        if (selectEl) selectEl.dataset.prevClub = selectEl.value;
        var fd = new FormData();
        fd.append('action', 'cambiar_club');
        fd.append('torneo_id', TORNEOS_ID);
        fd.append('id_usuario', idUsuario);
        fd.append('id_club', nuevoClubId);
        fd.append('csrf_token', CSRF_TOKEN);
        if (selectEl) selectEl.disabled = true;
        fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) return r.text().then(function(t) { throw new Error(t || 'Error ' + r.status); });
                return r.json();
            })
            .then(function(data) {
                if (selectEl) selectEl.disabled = false;
                if (data.success) {
                    var row = selectEl ? selectEl.closest('tr') : null;
                    if (row) row.dataset.clubId = String(nuevoClubId);
                    if (selectEl) selectEl.dataset.prevClub = String(nuevoClubId);
                    showMessage(data.message || 'Asociación actualizada.', 'success');
                } else {
                    if (selectEl && prev) selectEl.value = prev;
                    showMessage(data.error || 'No se pudo cambiar la asociación.', 'danger');
                }
            })
            .catch(function(err) {
                if (selectEl) {
                    selectEl.disabled = false;
                    if (prev) selectEl.value = prev;
                }
                showMessage(err && err.message ? err.message : 'Error de conexión.', 'danger');
            });
    }

    function inscribirJugador(idUsuario, nombre, cedula, clubId, rowEl) {
        var fd = new FormData();
        fd.append('action', 'inscribir');
        fd.append('torneo_id', TORNEOS_ID);
        fd.append('id_usuario', idUsuario);
        var cid = clubIdInscripcion(clubId || '');
        if (!cid) {
            showMessage('Seleccione o confirme la asociación en el panel antes de inscribir.', 'warning');
            return;
        }
        fd.append('id_club', cid);
        fd.append('estatus', '0');
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
                    if (rowEl) rowEl.remove();
                    agregarFilaInscrito(idUsuario, nombre, cedula, cid);
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
        if (rowEl && rowEl.dataset.confirmado === '1') {
            showMessage('No se puede desinscribir: el recibo de pago ya fue emitido. Modifique desde Administración de inscritos.', 'warning');
            return;
        }
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
                    var cid = clubId || rowEl.dataset.clubId || '';
                    var cnom = '';
                    CLUBES_LIST.forEach(function(c) { if (String(c.id) === String(cid)) cnom = c.nombre; });
                    if (rowEl) {
                        agregarFilaDisponible({
                            id: idUsuario,
                            nombre: nombre,
                            cedula: cedula,
                            club_id: parseInt(cid, 10) || 0,
                            club_nombre: rowEl.dataset.clubNombre || cnom
                        });
                        if (cid && !panelClubManual) {
                            setClubSeleccionado(cid, rowEl.dataset.clubNombre || cnom);
                        }
                        rowEl.remove();
                    }
                    updateCounters();
                    showMessage(data.message || 'Jugador desinscrito. Aparece en disponibles para reinscribir.', 'success');
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
        var selClubPanel = $('select_club_cedula');
        if (selClubPanel) {
            if (selClubPanel.tagName === 'SELECT') {
                selClubPanel.addEventListener('change', function() {
                    panelClubManual = true;
                    cargarDisponiblesPorClub();
                });
            }
            if (CLUB_FORZADO_ID || selClubPanel.value) {
                cargarDisponiblesPorClub();
            }
        } else if (CLUB_FORZADO_ID) {
            cargarDisponiblesPorClub();
        }
        var nacInput = $('select_nacionalidad_cedula');
        if (nacInput) {
            nacInput.addEventListener('blur', function() { nacInput.value = normalizarNacionalidad(nacInput.value); });
        }
        var inputCed = $('input_cedula');
        if (inputCed) {
            inputCed.addEventListener('input', function() {
                if (buscarTimer) window.clearTimeout(buscarTimer);
                var v = inputCed.value.trim();
                if (v.length < 3) return;
                buscarTimer = window.setTimeout(buscar, 400);
            });
            inputCed.addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); buscar(); } });
        }
        if ($('btn_buscar_atleta')) $('btn_buscar_atleta').addEventListener('click', buscar);

        if (tbodyDisp) {
            tbodyDisp.addEventListener('click', function(e) {
                var row = e.target.closest('tr[data-id]');
                if (row && row.dataset.id) {
                    if (row.dataset.clubId && !panelClubManual) {
                        setClubSeleccionado(row.dataset.clubId, row.dataset.clubNombre || '');
                    }
                    inscribirJugador(parseInt(row.dataset.id, 10), row.dataset.nombre, row.dataset.cedula || '', clubIdInscripcion(row.dataset.clubId || ''), row);
                }
            });
        }
        if (tbodyInsc) {
            tbodyInsc.addEventListener('change', function(e) {
                var sel = e.target.closest('.club-change-select');
                if (!sel) return;
                e.stopPropagation();
                var uid = parseInt(sel.dataset.userId || '0', 10);
                var nuevo = sel.value || '';
                if (!uid || !nuevo || nuevo === (sel.dataset.prevClub || '')) return;
                if (!confirm('¿Cambiar la asociación de este jugador a ' + sel.options[sel.selectedIndex].text + '?')) {
                    if (sel.dataset.prevClub) sel.value = sel.dataset.prevClub;
                    return;
                }
                cambiarClubInscrito(uid, nuevo, sel);
            });
            tbodyInsc.querySelectorAll('.club-change-select').forEach(function(sel) {
                sel.dataset.prevClub = sel.value;
            });
            tbodyInsc.addEventListener('click', function(e) {
                var retBtn = e.target.closest('.js-retirar-inscrito-sitio');
                if (retBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var row = retBtn.closest('tr');
                    if (!row || !row.dataset.id) return;
                    if (row.dataset.confirmado === '1') {
                        showMessage('Inscripción confirmada (recibo emitido). No se puede retirar aquí.', 'warning');
                        return;
                    }
                    desinscribirJugador(parseInt(row.dataset.id, 10), row.dataset.nombre, row.dataset.cedula || '', row.dataset.clubId || '', row);
                    return;
                }
                if (e.target.closest('.club-change-select') || e.target.closest('.insc-club-cell')) return;
            });
        }
    });
})();
</script>
