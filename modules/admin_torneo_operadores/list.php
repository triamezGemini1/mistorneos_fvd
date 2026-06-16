<?php
$page_title = 'Administrador de Torneo y Operadores';
$dashboard_url = function_exists('AppHelpers::dashboard') ? AppHelpers::dashboard('admin_torneo_operadores') : 'index.php?page=admin_torneo_operadores';
$current_tab = $tab ?? 'admin_torneo';
$return_torneo_id = isset($return_torneo_id) ? (int) $return_torneo_id : (int) ($_GET['return_torneo_id'] ?? 0);
$url_volver_panel = ($return_torneo_id > 0 && class_exists('AppHelpers'))
    ? AppHelpers::torneoGestionUrl('panel', $return_torneo_id)
    : '';
$return_torneo_qs = $return_torneo_id > 0 ? '&return_torneo_id=' . $return_torneo_id : '';
?>
<style>
/* Tabs: Administrador de Torneo | Operadores — siempre visibles; activo verde, inactivo morado, texto blanco */
.page-admin-torneo-operadores .nav-tabs {
    border-bottom: none;
}
.page-admin-torneo-operadores .nav-tabs .nav-link {
    background-color: #6f42c1;
    color: #fff !important;
    border: 1px solid #5a32a3;
    margin-right: 0.25rem;
}
.page-admin-torneo-operadores .nav-tabs .nav-link:hover {
    background-color: #5a32a3;
    color: #fff !important;
    border-color: #5a32a3;
}
.page-admin-torneo-operadores .nav-tabs .nav-link.active {
    background-color: #198754;
    color: #fff !important;
    border-color: #198754;
}
.page-admin-torneo-operadores .nav-tabs .nav-link.active:hover {
    background-color: #157347;
    color: #fff !important;
    border-color: #157347;
}
</style>
<div class="container-fluid page-admin-torneo-operadores">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-user-cog text-primary me-2"></i><?= htmlspecialchars($page_title) ?></h1>
        <?php if ($url_volver_panel !== ''): ?>
            <a href="<?= htmlspecialchars($url_volver_panel) ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Volver al panel del torneo
            </a>
        <?php endif; ?>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if (!$is_admin_club && !empty($clubes_options)): ?>
        <div class="card mb-3">
            <div class="card-body py-2">
                <label class="me-2">Asociación:</label>
                <select class="form-select form-select-sm d-inline-block w-auto" onchange="window.location.href='<?= htmlspecialchars($dashboard_url) ?>&club_id='+this.value+'&tab=<?= htmlspecialchars($current_tab) ?><?= $return_torneo_qs ?>'">
                    <option value="0" <?= ($club_id <= 0) ? 'selected' : '' ?>>Todas las asociaciones</option>
                    <?php foreach ($clubes_options as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($club_id === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($club_id <= 0): ?>
                    <span class="text-muted small ms-2">Ámbito nacional: operadores de cualquier asociación.</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Dos menús superiores (tabs) -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $current_tab === 'admin_torneo' ? 'active' : '' ?>" href="<?= htmlspecialchars($dashboard_url) ?>&tab=admin_torneo<?= $club_id > 0 ? '&club_id='.(int)$club_id : '&club_id=0' ?><?= $return_torneo_qs ?>">
                <i class="fas fa-user-tie me-1"></i> Administrador de Torneo
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current_tab === 'operadores' ? 'active' : '' ?>" href="<?= htmlspecialchars($dashboard_url) ?>&tab=operadores<?= $club_id > 0 ? '&club_id='.(int)$club_id : '&club_id=0' ?><?= $return_torneo_qs ?>">
                <i class="fas fa-users-cog me-1"></i> Operadores de Torneo
            </a>
        </li>
    </ul>

    <?php 
    $tiene_clubes_para_listar = $is_admin_club ? !empty($club_ids) : true;
    if (!$tiene_clubes_para_listar): ?>
        <div class="alert alert-info"><?= $is_admin_club ? 'No hay asociaciones en su organización. Regístrelas en Asociaciones de la organización.' : 'No hay asociaciones registradas.' ?></div>
    <?php else: ?>

    <!-- Bloque: Administrador de Torneo -->
    <div id="block-admin-torneo" class="tab-pane-block <?= $current_tab === 'admin_torneo' ? '' : 'd-none' ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Administradores de Torneo</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalRegistrarAdminTorneo">
                    <i class="fas fa-plus me-1"></i> Registrar nuevo Admin Torneo
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($admin_torneo_list)): ?>
                    <div class="p-4 text-center text-muted">No hay administradores de torneo registrados para este club.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nombre</th>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <?php if (!$is_admin_club): ?><th>Club</th><?php endif; ?>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admin_torneo_list as $u): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['nombre']) ?></td>
                                        <td><?= htmlspecialchars($u['username']) ?></td>
                                        <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                                        <?php if (!$is_admin_club): ?><td><?= htmlspecialchars($u['club_nombre'] ?? '—') ?></td><?php endif; ?>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCambiarRol" data-user-id="<?= (int)$u['id'] ?>" data-user-name="<?= htmlspecialchars($u['nombre']) ?>" data-current-role="admin_torneo">
                                                <i class="fas fa-exchange-alt me-1"></i> Cambiar rol
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bloque: Operadores -->
    <div id="block-operadores" class="tab-pane-block <?= $current_tab === 'operadores' ? '' : 'd-none' ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-users-cog me-2"></i>Operadores de Torneo</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalRegistrarOperador">
                    <i class="fas fa-plus me-1"></i> Registrar nuevo Operador
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($operadores_list)): ?>
                    <div class="p-4 text-center text-muted">No hay operadores registrados para este club.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nombre</th>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <?php if (!$is_admin_club): ?><th>Club</th><?php endif; ?>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($operadores_list as $u): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['nombre']) ?></td>
                                        <td><?= htmlspecialchars($u['username']) ?></td>
                                        <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                                        <?php if (!$is_admin_club): ?><td><?= htmlspecialchars($u['club_nombre'] ?? '—') ?></td><?php endif; ?>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCambiarRol" data-user-id="<?= (int)$u['id'] ?>" data-user-name="<?= htmlspecialchars($u['nombre']) ?>" data-current-role="operador">
                                                <i class="fas fa-exchange-alt me-1"></i> Cambiar rol
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Modal Cambiar rol -->
<div class="modal fade" id="modalCambiarRol" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?page=users">
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="return_to" value="admin_torneo_operadores">
                <input type="hidden" name="return_tab" value="<?= htmlspecialchars($current_tab) ?>">
                <input type="hidden" name="return_club_id" value="<?= (int)($club_id ?? 0) ?>">
                <?php if ($return_torneo_id > 0): ?><input type="hidden" name="return_torneo_id" value="<?= (int) $return_torneo_id ?>"><?php endif; ?>
                <input type="hidden" name="user_id" id="changeRoleUserId">
                <?php if ($is_admin_club && $club_id): ?><input type="hidden" name="club_id" value="<?= (int)$club_id ?>"><?php endif; ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Cambiar rol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Usuario: <strong id="changeRoleUserName"></strong></p>
                    <div class="mb-3">
                        <label for="changeRoleNewRole" class="form-label">Nuevo rol</label>
                        <select class="form-select" name="new_role" id="changeRoleNewRole" required>
                            <option value="admin_torneo">Administrador de Torneo</option>
                            <option value="operador">Operador de Torneo</option>
                            <option value="usuario">Usuario (quitar de la lista)</option>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="changeRoleClubWrap">
                        <label for="changeRoleClubId" class="form-label">Club</label>
                        <select class="form-select" name="club_id" id="changeRoleClubId">
                            <?php if ($is_admin_club && $club_id): ?>
                                <option value="<?= (int)$club_id ?>">Mi club</option>
                            <?php else: ?>
                                <?php foreach ($clubes_options ?? [] as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cambiar rol</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Registrar nuevo Admin Torneo -->
<div class="modal fade" id="modalRegistrarAdminTorneo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Registrar nuevo Admin Torneo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-0">Puede <strong>buscar un usuario existente</strong> (por cédula o nombre de usuario) para asignarle el rol, o <strong>registrar un nuevo usuario</strong> y asignarlo directamente como Admin Torneo.</p>
                <?php $modal_role = 'admin_torneo'; include __DIR__ . '/_form_registro_rol.php'; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Registrar nuevo Operador -->
<div class="modal fade" id="modalRegistrarOperador" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Registrar nuevo Operador</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-0">Puede <strong>buscar un usuario existente</strong> (por cédula o nombre de usuario) para asignarle el rol, o <strong>registrar un nuevo usuario</strong> y asignarlo directamente como Operador.</p>
                <?php $modal_role = 'operador'; include __DIR__ . '/_form_registro_rol.php'; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var m = document.getElementById('modalCambiarRol');
    if (m) {
        m.addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            if (btn) {
                document.getElementById('changeRoleUserId').value = btn.getAttribute('data-user-id') || '';
                document.getElementById('changeRoleUserName').textContent = btn.getAttribute('data-user-name') || '';
                var currentRole = btn.getAttribute('data-current-role') || '';
                var sel = document.getElementById('changeRoleNewRole');
                if (sel) sel.value = currentRole === 'admin_torneo' ? 'operador' : (currentRole === 'operador' ? 'admin_torneo' : 'admin_torneo');
                var clubWrap = document.getElementById('changeRoleClubWrap');
                if (clubWrap) clubWrap.classList.toggle('d-none', sel.value !== 'admin_torneo' && sel.value !== 'operador');
            }
        });
        document.getElementById('changeRoleNewRole').addEventListener('change', function() {
            document.getElementById('changeRoleClubWrap').classList.toggle('d-none', this.value !== 'admin_torneo' && this.value !== 'operador');
        });
    }
    document.querySelectorAll('.nav-tabs a').forEach(function(a) {
        a.addEventListener('click', function() { return true; });
    });
    var tab = '<?= $current_tab ?>';
    var b1 = document.getElementById('block-admin-torneo');
    var b2 = document.getElementById('block-operadores');
    if (b1) b1.classList.toggle('d-none', tab !== 'admin_torneo');
    if (b2) b2.classList.toggle('d-none', tab !== 'operadores');
})();
</script>
