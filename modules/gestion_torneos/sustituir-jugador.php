<?php
/**
 * Vista: Sustituir jugador retirado (torneo iniciado)
 * Solo para admin_general, admin_torneo, admin_club. Solo modalidad individual/parejas.
 */
declare(strict_types=1);

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';

extract($view_data ?? []);

if (!isset($torneo) || !isset($retirados)) {
    echo '<div class="alert alert-danger">Error: No se pudieron cargar los datos necesarios.</div>';
    return;
}

require_once __DIR__ . '/../../lib/InscritosHelper.php';

$url_inscripciones = 'index.php?page=registrants&torneo_id=' . (int)$torneo['id'];
$url_panel = $base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . (int)$torneo['id'];
?>
<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/inscripcion.css">
<div class="ds-inscripcion container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-user-exchange text-warning"></i> Sustituir jugador retirado
                <small class="text-muted">- <?= htmlspecialchars($torneo['nombre']) ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= $base_url ?>">Gestión de Torneos</a></li>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($url_panel) ?>"><?= htmlspecialchars($torneo['nombre']) ?></a></li>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($url_inscripciones) ?>">Gestionar Inscripciones</a></li>
                    <li class="breadcrumb-item active">Sustituir jugador</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <a href="<?= htmlspecialchars($url_inscripciones) ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i> Volver a Inscripciones
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <div class="alert alert-info mb-4">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Sustitución de jugadores retirados.</strong> Para evitar 3 BYE, puede agregar un sustituto que ocupará el lugar del jugador retirado en las siguientes rondas.
    </div>

    <?php if (!empty($retirados)): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0">
                <i class="fas fa-user-minus me-2"></i>Jugadores retirados (<?= count($retirados) ?>)
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Username</th>
                            <th>Club</th>
                            <th>Cédula</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($retirados as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['nombre_completo'] ?? $r['username'] ?? 'N/A') ?></strong></td>
                                <td><code><?= htmlspecialchars($r['username'] ?? '') ?></code></td>
                                <td><?= htmlspecialchars($r['nombre_club'] ?? 'Sin club') ?></td>
                                <td><?= htmlspecialchars($r['cedula'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">
                <i class="fas fa-user-plus me-2"></i>Agregar sustituto
            </h5>
        </div>
        <div class="card-body">
            <ul class="nav nav-tabs nav-tabs-sustituir mb-4" id="sustitucionTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="territorio-tab" data-bs-toggle="tab" data-bs-target="#territorio" type="button" role="tab">
                        <i class="fas fa-users me-2"></i>Atletas de Mi Territorio
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="cedula-tab" data-bs-toggle="tab" data-bs-target="#cedula" type="button" role="tab">
                        <i class="fas fa-id-card me-2"></i>Buscar por Cédula/ID
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="sustitucionTabsContent">
                <div class="tab-pane fade show active" id="territorio" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">
                                        <i class="fas fa-list me-2"></i>Atletas Disponibles
                                        <span class="badge bg-light text-dark ms-2" id="count_disponibles"><?= count($usuarios_disponibles ?? []) ?></span>
                                    </h6>
                                </div>
                                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                    <?php if (empty($usuarios_disponibles)): ?>
                                        <p class="text-muted mb-0">No hay atletas disponibles en su territorio que no estén ya inscritos.</p>
                                    <?php else: ?>
                                        <table class="table table-hover table-sm mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Nombre</th>
                                                    <th>ID</th>
                                                    <th>Club</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbody_disponibles">
                                                <?php foreach ($usuarios_disponibles ?? [] as $u):
                                                    $nombre_completo = !empty($u['nombre']) ? $u['nombre'] : $u['username'];
                                                ?>
                                                    <tr class="table-row-hover" style="cursor: pointer;"
                                                        data-id="<?= (int)$u['id'] ?>"
                                                        data-nombre="<?= htmlspecialchars($nombre_completo) ?>"
                                                        data-cedula="<?= htmlspecialchars($u['cedula'] ?? '') ?>"
                                                        data-club-id="<?= $u['club_id'] ?? '' ?>">
                                                        <td><strong><?= htmlspecialchars($nombre_completo) ?></strong></td>
                                                        <td><code><?= $u['id'] ?></code></td>
                                                        <td><?= !empty($u['club_nombre']) ? htmlspecialchars($u['club_nombre']) : '<span class="text-muted">N/A</span>' ?></td>
                                                        <td><button type="button" class="btn btn-success btn-sm btn-sustituir" data-row="this">Sustituir</button></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="cedula" role="tabpanel">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="alert alert-light border mb-3">
                                <strong><i class="fas fa-info-circle me-2 text-primary"></i>Cómo buscar por cédula:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Ingrese solo los <strong>dígitos</strong> de la cédula (ej: <code>12345678</code>)</li>
                                    <li>O el <strong>ID de usuario</strong> si lo conoce (ej: <code>42</code>)</li>
                                    <li>También acepta formato con nacionalidad: <code>V12345678</code> o <code>E12345678</code></li>
                                    <li>Escriba la cédula o ID y salga del campo para buscar; luego pulse <strong>Sustituir</strong> cuando aparezca el resultado</li>
                                </ul>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Cédula / ID de Usuario <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" id="input_cedula" class="form-control" placeholder="Ej: 12345678 o ID (mín. 3; busca al salir del campo)" data-app-search-persona="1">
                                            <button type="button" class="btn btn-info app-search-submit-hidden" id="btn_buscar_cedula" tabindex="-1" aria-hidden="true">
                                                <i class="fas fa-search me-2"></i>Buscar
                                            </button>
                                            <button type="button" class="btn btn-success" id="btn_sustituir_cedula" disabled>
                                                <i class="fas fa-user-plus me-2"></i>Sustituir
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Club</label>
                                        <select id="select_club_cedula" class="form-select">
                                            <option value="">-- Usar club del usuario encontrado --</option>
                                            <?php foreach ($clubes_disponibles ?? [] as $club): ?>
                                                <option value="<?= $club['id'] ?>"><?= htmlspecialchars($club['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="resultado_busqueda" style="display: none;">
                                        <div class="card border-info">
                                            <div class="card-body">
                                                <h6 class="card-title">Resultado de la búsqueda</h6>
                                                <div id="info_usuario_encontrado"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Pestañas con buen contraste: fondo claro y texto oscuro */
.nav-tabs-sustituir.nav-tabs {
    border-bottom: 2px solid #dee2e6;
}
.nav-tabs-sustituir .nav-link {
    background-color: #e9ecef;
    color: #212529;
    border: 1px solid #dee2e6;
    border-bottom: none;
    margin-bottom: -2px;
    font-weight: 600;
}
.nav-tabs-sustituir .nav-link:hover {
    background-color: #dee2e6;
    color: #0d6efd;
    border-color: #dee2e6 #dee2e6 #e9ecef;
}
.nav-tabs-sustituir .nav-link.active {
    background-color: #fff;
    color: #0d6efd;
    border-color: #dee2e6 #dee2e6 #fff;
    border-bottom: 2px solid #fff;
}
.table-row-hover:hover { background-color: #e3f2fd !important; }
</style>

<script>
const TORNEOS_ID = <?= (int)$torneo['id'] ?>;
const CSRF_TOKEN = '<?= htmlspecialchars(CSRF::token(), ENT_QUOTES) ?>';
const API_URL = 'tournament_admin_toggle_inscripcion.php';
const SEARCH_API_URL = 'api/search_usuario_inscripcion_sitio.php';
const URL_INSCRIPCIONES = '<?= htmlspecialchars($url_inscripciones) ?>';

document.addEventListener('DOMContentLoaded', function() {
    const tbodyDisponibles = document.getElementById('tbody_disponibles');

    if (tbodyDisponibles) {
        tbodyDisponibles.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-sustituir');
            const row = e.target.closest('tr');
            if (btn && row && row.dataset.id) {
                sustituirJugador(
                    parseInt(row.dataset.id),
                    row.dataset.nombre || '',
                    row.dataset.cedula || '',
                    row.dataset.clubId || '',
                    row
                );
            }
        });
    }

    function sustituirJugador(idUsuario, nombre, cedula, clubId, rowElement) {
        if (!idUsuario || !TORNEOS_ID) {
            showMessage('Error: Faltan datos necesarios', 'danger');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'inscribir');
        formData.append('torneo_id', TORNEOS_ID);
        formData.append('id_usuario', idUsuario);
        if (clubId) formData.append('id_club', clubId);
        formData.append('estatus', '1');
        formData.append('csrf_token', CSRF_TOKEN);

        if (rowElement) {
            rowElement.style.opacity = '0.5';
            rowElement.style.pointerEvents = 'none';
        }

        fetch(API_URL, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (rowElement) {
                    rowElement.style.opacity = '1';
                    rowElement.style.pointerEvents = 'auto';
                }
                if (data.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Sustituto agregado',
                            text: 'El jugador ha sido inscrito como sustituto correctamente.',
                            confirmButtonColor: '#10b981'
                        }).then(() => {
                            window.location.href = URL_INSCRIPCIONES;
                        });
                    } else {
                        window.location.href = URL_INSCRIPCIONES;
                    }
                } else {
                    showMessage(data.error || 'Error al agregar sustituto', 'danger');
                }
            })
            .catch(err => {
                if (rowElement) {
                    rowElement.style.opacity = '1';
                    rowElement.style.pointerEvents = 'auto';
                }
                showMessage('Error: ' + err.message, 'danger');
            });
    }

    function showMessage(msg, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = msg + ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        const cardBody = document.querySelector('.card-body');
        if (cardBody) {
            cardBody.insertBefore(alertDiv, cardBody.firstChild);
            setTimeout(() => alertDiv.remove(), 5000);
        }
    }

    let usuarioEncontrado = null;
    const btnBuscar = document.getElementById('btn_buscar_cedula');
    const btnSustituir = document.getElementById('btn_sustituir_cedula');
    const inputCedula = document.getElementById('input_cedula');
    const resultadoBusqueda = document.getElementById('resultado_busqueda');
    const infoUsuario = document.getElementById('info_usuario_encontrado');

    function buscarPorCedula() {
        const valor = inputCedula.value.trim();
        if (!valor) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Campo vacío', text: 'Ingrese cédula o ID', confirmButtonColor: '#667eea' });
            }
            return;
        }
        const esId = /^\d+$/.test(valor);
        if (esId) {
            buscarPorId(parseInt(valor));
        } else {
            const num = (valor || '').replace(/\D/g, '');
            if (!num) {
                infoUsuario.innerHTML = '<div class="alert alert-danger">Ingrese un número de cédula válido.</div>';
                resultadoBusqueda.style.display = 'block';
                btnSustituir.disabled = true;
                return;
            }
            infoUsuario.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            resultadoBusqueda.style.display = 'block';
            btnSustituir.disabled = true;
            fetch(SEARCH_API_URL + '?cedula=' + encodeURIComponent(num))
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data?.existe_usuario && data.data?.usuario_existente) {
                        usuarioEncontrado = data.data.usuario_existente;
                        infoUsuario.innerHTML = '<div class="alert alert-success"><strong>Usuario encontrado:</strong> ' +
                            (usuarioEncontrado.nombre || usuarioEncontrado.username) + ' (ID: ' + usuarioEncontrado.id + ')</div>';
                        btnSustituir.disabled = false;
                    } else {
                        usuarioEncontrado = null;
                        infoUsuario.innerHTML = '<div class="alert alert-warning">No hay usuario con esa cédula.</div>';
                    }
                })
                .catch(() => {
                    usuarioEncontrado = null;
                    infoUsuario.innerHTML = '<div class="alert alert-danger">Error en la búsqueda.</div>';
                });
        }
    }

    function buscarPorId(id) {
        infoUsuario.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
        resultadoBusqueda.style.display = 'block';
        btnSustituir.disabled = true;
        fetch(SEARCH_API_URL + '?user_id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data?.existe_usuario && data.data?.usuario_existente) {
                    usuarioEncontrado = data.data.usuario_existente;
                    infoUsuario.innerHTML = '<div class="alert alert-success"><strong>Usuario encontrado:</strong> ' +
                        (usuarioEncontrado.nombre || usuarioEncontrado.username) + ' (ID: ' + usuarioEncontrado.id + ')</div>';
                    btnSustituir.disabled = false;
                } else {
                    usuarioEncontrado = null;
                    infoUsuario.innerHTML = '<div class="alert alert-warning">No hay usuario con ese ID.</div>';
                }
            })
            .catch(() => {
                usuarioEncontrado = null;
                infoUsuario.innerHTML = '<div class="alert alert-danger">Error en la búsqueda.</div>';
            });
    }

    if (btnBuscar) btnBuscar.addEventListener('click', buscarPorCedula);
    if (inputCedula) {
        inputCedula.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); buscarPorCedula(); }
        });
    }

    if (btnSustituir) {
        btnSustituir.addEventListener('click', function() {
            if (!usuarioEncontrado?.id) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Busque un usuario primero', confirmButtonColor: '#667eea' });
                }
                return;
            }
            const clubId = document.getElementById('select_club_cedula')?.value || '';
            sustituirJugador(usuarioEncontrado.id, usuarioEncontrado.nombre || usuarioEncontrado.username, usuarioEncontrado.cedula || '', clubId, null);
        });
    }
});
</script>
