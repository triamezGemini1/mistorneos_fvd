<?php
/**
 * Directorio de Clubes
 * CRUD sobre la tabla directorio_clubes. Lista visible por todos los admins;
 * create, store, edit, update y destroy solo para admin_general.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/admin_general_auth.php';
require_once __DIR__ . '/../public/simple_image_config.php';
require_once __DIR__ . '/../lib/Pagination.php';

$current_user = Auth::user();
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
$is_admin_gral = Auth::isAdminGeneral();

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;

// Restringir create, store, edit, update, destroy a admin_general
$crud_actions = ['new', 'save', 'edit', 'update', 'delete'];
if (in_array($action, $crud_actions, true)) {
    requireAdminGeneral();
}

// Procesar eliminación (GET o POST con method override DELETE)
$is_delete = ($action === 'delete' && $id);
if ($is_delete && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    CSRF::validate();
}
if ($is_delete) {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT id, nombre, logo FROM directorio_clubes WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new Exception('Registro no encontrado');
        }
        $stmt = $pdo->prepare("DELETE FROM directorio_clubes WHERE id = ?");
        $stmt->execute([$id]);
        if ($item['logo'] && file_exists(__DIR__ . '/../' . $item['logo'])) {
            @unlink(__DIR__ . '/../' . $item['logo']);
        }
        header('Location: ' . AppHelpers::dashboard('directorio_clubes') . '&success=' . urlencode('Registro eliminado del directorio.'));
        exit;
    } catch (Exception $e) {
        header('Location: ' . AppHelpers::dashboard('directorio_clubes') . '&error=' . urlencode($e->getMessage()));
        exit;
    }
}

$item = null;
$organizaciones_list = [];

if ($action === 'edit' || $action === 'view') {
    try {
        $stmt = DB::pdo()->prepare("SELECT * FROM directorio_clubes WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            $error_message = 'Registro no encontrado';
            $action = 'list';
        } else {
            $item['usuario_vinculado_nombre'] = null;
            if (!empty($item['id_usuario'])) {
                $st = DB::pdo()->prepare("SELECT nombre FROM usuarios WHERE id = ?");
                $st->execute([$item['id_usuario']]);
                $item['usuario_vinculado_nombre'] = $st->fetchColumn();
            }
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $action = 'list';
    }
}

$clubs_list = [];
$pagination = null;
if ($action === 'list') {
    try {
        $pdo = DB::pdo();
        $has_id_usuario = (bool) $pdo->query("SHOW COLUMNS FROM directorio_clubes LIKE 'id_usuario'")->fetch();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM directorio_clubes")->fetchColumn();
        $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
        $per_page = Pagination::DEFAULT_PER_PAGE;
        $pagination = new Pagination($total, $current_page, $per_page);
        $sel_usuario = $has_id_usuario ? ", dc.id_usuario, u.nombre AS usuario_vinculado_nombre" : "";
        $join_usuario = $has_id_usuario ? " LEFT JOIN usuarios u ON u.id = dc.id_usuario" : "";
        $stmt = $pdo->prepare("
            SELECT dc.id, dc.nombre, dc.direccion, dc.delegado, dc.telefono, dc.email, dc.logo, dc.estatus
            $sel_usuario
            FROM directorio_clubes dc
            $join_usuario
            ORDER BY dc.nombre ASC
            LIMIT " . $pagination->getLimit() . " OFFSET " . $pagination->getOffset()
        );
        $stmt->execute();
        $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = 'Error al cargar el directorio: ' . $e->getMessage();
    }
}
?>
<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/directorio-invitacion.css">
<div class="ds-directorio container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-shield-alt me-2"></i>Directorio de Clubes
                    </h1>
                    <p class="text-muted mb-0"><?= $action === 'list' ? 'Consulta de clubes en el directorio' : ($action === 'new' ? 'Nuevo registro' : ($action === 'edit' ? 'Editar registro' : 'Ver registro')) ?></p>
                </div>
                <div>
                    <?php if ($action === 'list' && $is_admin_gral): ?>
                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'export_excel'])) ?>" class="btn btn-success me-2" target="_blank" rel="noopener">
                            <i class="fas fa-file-excel me-2"></i>Exportar a Excel
                        </a>
                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'report_pdf'])) ?>" class="btn btn-danger me-2" target="_blank" rel="noopener">
                            <i class="fas fa-file-pdf me-2"></i>Imprimir PDF
                        </a>
                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'new'])) ?>" class="btn btn-primary">Agregar Club</a>
                    <?php elseif ($action !== 'list'): ?>
                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes')) ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Volver al listado
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            <?php endif; ?>

            <?php if ($action === 'list'): ?>
            <div class="card">
                <div class="card-body">
                    <?php if (empty($clubs_list)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>No hay registros en el directorio.
                            <?php if ($is_admin_gral): ?>
                                <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'new'])) ?>" class="alert-link">Crear el primero</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th class="dc-col-logo">Logo</th>
                                        <th class="dc-col-nombre">Nombre</th>
                                        <th class="dc-col-delegado">Delegado</th>
                                        <th class="dc-col-telefono">Teléfono</th>
                                        <th class="dc-col-estado text-center">Estado</th>
                                        <?php if (!empty($has_id_usuario)): ?><th class="dc-col-usuario text-center">Usuario invitaciones</th><?php endif; ?>
                                        <th class="dc-col-acciones text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clubs_list as $row): ?>
                                        <tr>
                                            <td class="align-middle dc-col-logo"><?= displayClubLogoTable($row) ?></td>
                                            <td class="align-middle small dc-col-nombre">
                                                <strong class="d-block text-truncate" title="<?= htmlspecialchars($row['nombre'] ?? '') ?>"><?= htmlspecialchars($row['nombre'] ?? '') ?></strong>
                                                <?php if (!empty($row['direccion'])): ?>
                                                    <small class="text-muted d-block text-truncate" title="<?= htmlspecialchars($row['direccion']) ?>"><?= htmlspecialchars($row['direccion']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="align-middle small text-truncate dc-col-delegado" title="<?= htmlspecialchars($row['delegado'] ?? '') ?>"><?= htmlspecialchars($row['delegado'] ?? '—') ?></td>
                                            <td class="align-middle small text-truncate dc-col-telefono" title="<?= htmlspecialchars($row['telefono'] ?? '') ?>"><?= htmlspecialchars($row['telefono'] ?? '—') ?></td>
                                            <td class="text-center align-middle dc-col-estado">
                                                <span class="badge bg-<?= $row['estatus'] ? 'success' : 'secondary' ?>">
                                                    <?= $row['estatus'] ? 'Activo' : 'Inactivo' ?>
                                                </span>
                                            </td>
                                            <?php if (!empty($has_id_usuario)): ?>
                                            <td class="text-center align-middle small dc-col-usuario">
                                                <?php if (!empty($row['id_usuario']) && !empty($row['usuario_vinculado_nombre'])): ?>
                                                    <span class="badge bg-success" title="ID: <?= (int)$row['id_usuario'] ?>"><?= htmlspecialchars($row['usuario_vinculado_nombre']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Sin usuario</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="align-middle dc-col-acciones">
                                                <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'view', 'id' => $row['id']])) ?>" class="btn btn-info btn-sm">Ver</a>
                                                <?php if ($is_admin_gral): ?>
                                                    <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'edit', 'id' => $row['id']])) ?>" class="btn btn-warning btn-sm">Editar</a>
                                                    <form action="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'delete', 'id' => $row['id']])) ?>" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar \'<?= htmlspecialchars(addslashes($row['nombre'] ?? '')) ?>\' del directorio?');">
                                                        <?= CSRF::input(); ?>
                                                        <input type="hidden" name="_method" value="DELETE">
                                                        <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($pagination): ?>
                            <div class="mt-3"><?= $pagination->render() ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($action === 'view' && $item): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i><?= htmlspecialchars($item['nombre']) ?></h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <p><strong>Delegado:</strong> <?= htmlspecialchars($item['delegado'] ?? '—') ?></p>
                            <p><strong>Teléfono:</strong> <?= htmlspecialchars($item['telefono'] ?? '—') ?></p>
                            <p><strong>Email:</strong> <?= htmlspecialchars($item['email'] ?? '—') ?></p>
                            <p><strong>Dirección:</strong> <?= htmlspecialchars($item['direccion'] ?? '—') ?></p>
                            <p><strong>Estado:</strong> <span class="badge bg-<?= $item['estatus'] ? 'success' : 'secondary' ?>"><?= $item['estatus'] ? 'Activo' : 'Inactivo' ?></span></p>
                            <?php if (isset($item['id_usuario']) && (int)$item['id_usuario'] > 0): ?>
                            <p><strong>Usuario (invitaciones):</strong> <?= htmlspecialchars($item['usuario_vinculado_nombre'] ?? 'ID ' . $item['id_usuario']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-center">
                            <?= displayClubLogoView($item) ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes')) ?>" class="btn btn-secondary">Volver</a>
                    <?php if ($is_admin_gral): ?>
                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => 'edit', 'id' => $item['id']])) ?>" class="btn btn-primary">Editar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (($action === 'new' || $action === 'edit') && ($action !== 'edit' || $item)): ?>
            <div class="card">
                <div class="card-header bg-<?= $action === 'edit' ? 'warning' : 'success' ?> text-white">
                    <h5 class="mb-0"><?= $action === 'edit' ? 'Editar' : 'Nuevo' ?> registro en el directorio</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes', ['action' => $action === 'edit' ? 'update' : 'save'])) ?>" enctype="multipart/form-data">
                        <?= CSRF::input(); ?>
                        <?php if ($action === 'edit'): ?>
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <?php endif; ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nombre" class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($action === 'edit' ? ($item['nombre'] ?? '') : '') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="delegado" class="form-label">Delegado</label>
                                    <input type="text" class="form-control" id="delegado" name="delegado" value="<?= htmlspecialchars($action === 'edit' ? ($item['delegado'] ?? '') : '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label for="telefono" class="form-label">Teléfono</label>
                                    <input type="tel" class="form-control" id="telefono" name="telefono" value="<?= htmlspecialchars($action === 'edit' ? ($item['telefono'] ?? '') : '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($action === 'edit' ? ($item['email'] ?? '') : '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="direccion" class="form-label">Dirección</label>
                                    <textarea class="form-control" id="direccion" name="direccion" rows="2"><?= htmlspecialchars($action === 'edit' ? ($item['direccion'] ?? '') : '') ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="logo" class="form-label">Logo</label>
                                    <input type="file" class="form-control" id="logo" name="logo" accept="image/*" data-preview-target="logo-preview">
                                    <small class="text-muted">JPG, PNG, GIF (máx. 5MB)</small>
                                    <div id="logo-preview" class="mt-2"></div>
                                    <?php if ($action === 'edit' && !empty($item['logo'])): ?>
                                        <div class="mt-2" id="logo-current"><?= displayClubLogoEdit($item) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <label for="estatus" class="form-label">Estado</label>
                                    <select class="form-select" id="estatus" name="estatus">
                                        <option value="1" <?= ($action === 'edit' ? (int)($item['estatus'] ?? 1) : 1) === 1 ? 'selected' : '' ?>>Activo</option>
                                        <option value="0" <?= ($action === 'edit' ? (int)($item['estatus'] ?? 1) : 0) === 0 ? 'selected' : '' ?>>Inactivo</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between mt-3 pt-3 border-top">
                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('directorio_clubes')) ?>" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-<?= $action === 'edit' ? 'warning' : 'success' ?>">
                                <i class="fas fa-save me-2"></i><?= $action === 'edit' ? 'Actualizar' : 'Guardar' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($clubs_list)): ?>
<script>
function showLogoModal(url, title) {
    var modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'logoModal';
    modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">' + (title || 'Logo') + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><img src="' + url + '" class="img-fluid" alt="Logo"></div></div></div>';
    document.body.appendChild(modal);
    var b = typeof bootstrap !== 'undefined' ? bootstrap : window.bootstrap;
    if (b && b.Modal) {
        var m = new b.Modal(modal);
        m.show();
        modal.addEventListener('hidden.bs.modal', function() { modal.remove(); });
    }
}
</script>
<?php endif; ?>
