<?php

if (!defined('APP_BOOTSTRAPPED')) { require __DIR__ . '/../../config/bootstrap.php'; }

$page_title = 'Administraci�n de Usuarios';
$current_user = Auth::user();
?>

<style>
    .role-badge {
        font-size: 0.8em;
    }
    .status-badge {
        font-size: 0.8em;
    }
    .table-actions {
        white-space: nowrap;
    }
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
    .btn-group .btn {
        border-radius: 0;
    }
    .btn-group .btn:first-child {
        border-top-left-radius: 0.375rem;
        border-bottom-left-radius: 0.375rem;
    }
    .btn-group .btn:last-child {
        border-top-right-radius: 0.375rem;
        border-bottom-right-radius: 0.375rem;
    }
    .hover-shadow {
        transition: all 0.3s;
    }
    .hover-shadow:hover {
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }
</style>

<?php
// Cargar estadísticas para widgets
require_once __DIR__ . '/../../lib/StatisticsHelper.php';
$stats = StatisticsHelper::generateStatistics();

// Mapa de entidad para mostrar etiquetas
$entidad_map = [];
if (!empty($entidades_options)) {
    foreach ($entidades_options as $ent) {
        $codigo = $ent['codigo'] ?? null;
        if ($codigo !== null) {
            $entidad_map[(string)$codigo] = $ent['nombre'] ?? $codigo;
        }
    }
}

// Widget de estadísticas de usuarios
if (!empty($stats) && !isset($stats['error'])):
?>
<div class="row g-4 mb-4">
    <?php if (Auth::isAdminGeneral()): ?>
        <div class="col-md-2">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <h3 class="text-primary mb-0"><?= number_format($stats['total_users'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Total Usuarios</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-success">
                <div class="card-body text-center">
                    <h3 class="text-success mb-0"><?= number_format($stats['total_active_users'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Usuarios Activos</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-info">
                <div class="card-body text-center">
                    <h3 class="text-info mb-0"><?= number_format($stats['total_clubs'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Clubes Activos</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <h3 class="text-warning mb-0"><?= number_format($stats['total_admin_clubs'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Admin. de organización</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-secondary">
                <div class="card-body text-center">
                    <h3 class="text-secondary mb-0"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Admin. Torneo</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-dark">
                <div class="card-body text-center">
                    <h3 class="text-dark mb-0"><?= number_format($stats['total_operadores'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Operadores</p>
                </div>
            </div>
        </div>
    <?php elseif ($current_user['role'] === 'admin_club' && !empty($stats['supervised_clubs'])): ?>
        <div class="col-md-2">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <h3 class="text-primary mb-0"><?= number_format($stats['total_afiliados'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Total Afiliados</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-success">
                <div class="card-body text-center">
                    <h3 class="text-success mb-0"><?= number_format($stats['afiliados_by_gender']['hombres'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Hombres</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-danger">
                <div class="card-body text-center">
                    <h3 class="text-danger mb-0"><?= number_format($stats['afiliados_by_gender']['mujeres'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Mujeres</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-secondary">
                <div class="card-body text-center">
                    <h3 class="text-secondary mb-0"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Admin. Torneo</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-dark">
                <div class="card-body text-center">
                    <h3 class="text-dark mb-0"><?= number_format($stats['total_operadores'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Operadores</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">
        <i class="fas fa-user-cog"></i> Administraci�n de Usuarios
    </h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="fas fa-plus"></i> Nuevo Usuario
        </button>
    </div>
</div>
<!-- Pestañas -->
<ul class="nav nav-tabs" id="userTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $action !== 'requests' ? 'active' : '' ?>" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="true">
            <i class="fas fa-users"></i> Usuarios
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $action === 'requests' ? 'active' : '' ?>" id="requests-tab" data-bs-toggle="tab" data-bs-target="#requests" type="button" role="tab" aria-controls="requests" aria-selected="false">
            <i class="fas fa-user-plus"></i> Solicitudes de Registro
        </button>
    </li>
</ul>

<div class="tab-content" id="userTabsContent">
    <!-- Pestaña de Usuarios -->
    <div class="tab-pane fade <?= $action !== 'requests' ? 'show active' : '' ?>" id="users" role="tabpanel" aria-labelledby="users-tab">
<!-- Mensajes -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
$is_admin_list = $users_result && isset($users_result['is_admin_list']) && $users_result['is_admin_list'];
$is_club_list = $users_result && isset($users_result['is_club_list']) && $users_result['is_club_list'];
$is_admin_general = Auth::isAdminGeneral();
$is_admin_club = $current_user['role'] === 'admin_club';
?>

<?php if ($is_admin_general && $admin_id && !$club_id && isset($users_result['is_club_list']) && $users_result['is_club_list']): ?>
    <?php
    $admin_info = $users_result['admin_info'] ?? null;
    $total_afiliados = $users_result['total_afiliados'] ?? 0;
    $total_clubes = $users_result['total_clubes'] ?? 0;
    $total_torneos = $users_result['total_torneos'] ?? 0;
    ?>
    
    <!-- Información del Administrador -->
    <div class="card mb-4 border-primary shadow-sm">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-user-shield me-2"></i>Información del Administrador de organización
                </h5>
                <a href="?action=list" class="btn btn-sm btn-light">
                    <i class="fas fa-arrow-left"></i> Volver a Administradores
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php if ($admin_info): ?>
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Datos Personales</h6>
                        <table class="table table-borderless">
                            <tr>
                                <td class="text-muted" style="width: 40%;"><strong>Nombre:</strong></td>
                                <td><?= htmlspecialchars($admin_info['nombre']) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><strong>Usuario:</strong></td>
                                <td><?= htmlspecialchars($admin_info['username']) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><strong>ID Usuario:</strong></td>
                                <td><?= htmlspecialchars($admin_info['id'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><strong>Email:</strong></td>
                                <td><?= htmlspecialchars($admin_info['email'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><strong>Teléfono:</strong></td>
                                <td><?= htmlspecialchars($admin_info['celular'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><strong>Estado:</strong></td>
                                <td>
                                    <span class="badge bg-<?= (int)($admin_info['status'] ?? 1) === 0 ? 'success' : 'warning' ?>">
                                        <?= (int)($admin_info['status'] ?? 1) === 0 ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3"><i class="fas fa-building me-2"></i>Club</h6>
                        <?php if (!empty($admin_info['club_nombre'])): ?>
                        <table class="table table-borderless">
                            <tr>
                                <td class="text-muted" style="width: 40%;"><strong>Nombre:</strong></td>
                                <td><?= htmlspecialchars($admin_info['club_nombre']) ?></td>
                            </tr>
                            <?php if ($admin_info['delegado']): ?>
                            <tr>
                                <td class="text-muted"><strong>Delegado:</strong></td>
                                <td><?= htmlspecialchars($admin_info['delegado']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($admin_info['club_telefono']): ?>
                            <tr>
                                <td class="text-muted"><strong>Teléfono:</strong></td>
                                <td><?= htmlspecialchars($admin_info['club_telefono']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($admin_info['club_direccion']): ?>
                            <tr>
                                <td class="text-muted"><strong>Dirección:</strong></td>
                                <td><?= htmlspecialchars($admin_info['club_direccion']) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        <?php else: ?>
                            <div class="text-muted">Sin club asignado</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <!-- Estadísticas -->
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body">
                                <h3 class="text-info mb-0"><?= number_format($total_clubes) ?></h3>
                                <p class="text-muted mb-0">Clubes Supervisados</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body">
                                <h3 class="text-success mb-0"><?= number_format($total_afiliados) ?></h3>
                                <p class="text-muted mb-0">Total Afiliados</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body">
                                <h3 class="text-warning mb-0"><?= number_format($total_torneos) ?></h3>
                                <p class="text-muted mb-0">Torneos Activos</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <!-- Opciones de Navegación -->
                <div class="d-flex gap-2 flex-wrap justify-content-center">
                    <a href="?admin_id=<?= $admin_id ?>&view=clubes" class="btn btn-info btn-lg">
                        <i class="fas fa-building me-2"></i>Ver Clubes Supervisados
                    </a>
                    <a href="?admin_id=<?= $admin_id ?>&club_id=all" class="btn btn-success btn-lg">
                        <i class="fas fa-users me-2"></i>Ver Todos los Afiliados
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isset($_GET['view']) && $_GET['view'] === 'clubes'): ?>
    <!-- Vista de Clubes del Administrador -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-building"></i> Clubes Supervisados
                </h5>
                <a href="?admin_id=<?= $admin_id ?>" class="btn btn-sm btn-light">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">Seleccione un club para ver los afiliados registrados en él, o use el botón "Ver Todos los Afiliados" para ver todos.</p>
            <?php if (empty($users)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No hay clubes supervisados</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($users as $club): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 border-info hover-shadow" style="transition: all 0.3s; cursor: pointer;" 
                                 onclick="window.location.href='?admin_id=<?= $admin_id ?>&club_id=<?= $club['id'] ?>'">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 50px; height: 50px; font-size: 1.5rem;">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <div class="ms-3">
                                            <h6 class="mb-0"><?= htmlspecialchars($club['nombre']) ?></h6>
                                            <?php if ($club['delegado']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($club['delegado']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="row text-center">
                                        <div class="col-12">
                                            <strong class="text-info"><?= $club['total_usuarios'] ?? 0 ?></strong>
                                            <br><small class="text-muted">Afiliados</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-light text-center">
                                    <small class="text-info">
                                        <i class="fas fa-arrow-right"></i> Ver afiliados
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php elseif ($is_admin_club && !$club_id): ?>
    <!-- Vista de Clubes para admin_club -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-building"></i> Clubes Supervisados
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">Seleccione un club para ver los usuarios registrados en él.</p>
            <?php if (empty($users)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No hay clubes supervisados</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($users as $club): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 border-info hover-shadow" style="transition: all 0.3s; cursor: pointer;" 
                                 onclick="window.location.href='?club_id=<?= $club['id'] ?>'">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 50px; height: 50px; font-size: 1.5rem;">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <div class="ms-3">
                                            <h6 class="mb-0">
                                                <?= htmlspecialchars($club['nombre']) ?>
                                            </h6>
                                            <?php if ($club['delegado']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($club['delegado']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="border-end">
                                                <strong class="text-info"><?= $club['total_usuarios'] ?? 0 ?></strong>
                                                <br><small class="text-muted">Usuarios</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <strong class="text-success"><?= htmlspecialchars($club['telefono'] ?: '-') ?></strong>
                                            <br><small class="text-muted">Teléfono</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-light text-center">
                                    <small class="text-info">
                                        <i class="fas fa-arrow-right"></i> Ver usuarios
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Vista de Usuarios -->
    <?php if ($is_admin_general && $club_id && $club_id !== 'all' && !$admin_id): ?>
        <div class="card mb-3 border-primary">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <?php 
                    $pdo = DB::pdo();
                    $stmt = $pdo->prepare("SELECT nombre, delegado FROM clubes WHERE id = ?");
                    $stmt->execute([$club_id]);
                    $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <div>
                        <i class="fas fa-building me-2 text-primary"></i>
                        <strong><?= htmlspecialchars($club_info['nombre'] ?? 'Club') ?></strong>
                        <?php if (!empty($club_info['delegado'])): ?>
                            <span class="text-muted ms-2">— <?= htmlspecialchars($club_info['delegado']) ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="<?= htmlspecialchars(AppHelpers::dashboard('users')) ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Clubes
                    </a>
                </div>
            </div>
        </div>
    <?php elseif ($is_admin_general && $admin_id && $club_id && $club_id !== 'all'): ?>
        <div class="card mb-3 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            <i class="fas fa-user-shield text-primary"></i> 
                            Administrador: 
                            <?php
                            $pdo = DB::pdo();
                            $stmt = $pdo->prepare("SELECT u.nombre, c.nombre as club_nombre FROM usuarios u LEFT JOIN clubes c ON u.club_id = c.id WHERE u.id = ?");
                            $stmt->execute([$admin_id]);
                            $admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($admin_info):
                            ?>
                                <strong><?= htmlspecialchars($admin_info['nombre']) ?></strong>
                                <?php if (!empty($admin_info['club_nombre'])): ?>
                                    - <span class="text-muted"><?= htmlspecialchars($admin_info['club_nombre']) ?></span>
                                <?php else: ?>
                                    - <span class="text-muted">Sin club asignado</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <span class="mx-2">→</span>
                            <i class="fas fa-building text-info"></i> 
                            Club: 
                            <?php
                            $stmt = $pdo->prepare("SELECT nombre, delegado FROM clubes WHERE id = ?");
                            $stmt->execute([$club_id]);
                            $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($club_info):
                            ?>
                                <strong><?= htmlspecialchars($club_info['nombre']) ?></strong>
                                <?php if ($club_info['delegado']): ?>
                                    - <span class="text-muted"><?= htmlspecialchars($club_info['delegado']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <a href="?admin_id=<?= $admin_id ?>&view=clubes" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Clubes
                    </a>
                </div>
            </div>
        </div>
    <?php elseif ($is_admin_general && $admin_id && $club_id === 'all'): ?>
        <div class="card mb-3 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            <i class="fas fa-user-shield text-primary"></i> 
                            Administrador: 
                            <?php
                            $pdo = DB::pdo();
                            $stmt = $pdo->prepare("SELECT u.nombre, c.nombre as club_nombre FROM usuarios u LEFT JOIN clubes c ON u.club_id = c.id WHERE u.id = ?");
                            $stmt->execute([$admin_id]);
                            $admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($admin_info):
                            ?>
                                <strong><?= htmlspecialchars($admin_info['nombre']) ?></strong>
                                <?php if (!empty($admin_info['club_nombre'])): ?>
                                    - <span class="text-muted"><?= htmlspecialchars($admin_info['club_nombre']) ?></span>
                                <?php else: ?>
                                    - <span class="text-muted">Sin club asignado</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <span class="mx-2">→</span>
                            <i class="fas fa-users text-success"></i> 
                            <strong>Todos los Afiliados</strong>
                        </h6>
                    </div>
                    <a href="<?= htmlspecialchars(AppHelpers::dashboard('users')) ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Administradores
                    </a>
                </div>
            </div>
        </div>
    <?php elseif ($is_admin_club && $club_id): ?>
        <div class="card mb-3 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            <i class="fas fa-building text-info"></i> 
                            Club: 
                            <?php
                            $pdo = DB::pdo();
                            $stmt = $pdo->prepare("SELECT nombre, delegado FROM clubes WHERE id = ?");
                            $stmt->execute([$club_id]);
                            $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($club_info):
                            ?>
                                <strong><?= htmlspecialchars($club_info['nombre']) ?></strong>
                                <?php if ($club_info['delegado']): ?>
                                    - <span class="text-muted"><?= htmlspecialchars($club_info['delegado']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <a href="?action=list" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Clubes
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Barra de búsqueda -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="users">
                <?php if ($admin_id): ?>
                    <input type="hidden" name="admin_id" value="<?= $admin_id ?>">
                <?php endif; ?>
                <?php if ($club_id): ?>
                    <input type="hidden" name="club_id" value="<?= $club_id === 'all' ? 'all' : $club_id ?>">
                <?php endif; ?>
                <div class="col-md-10">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Buscar por ID, cédula, correo, nombre o usuario..." 
                               value="<?= htmlspecialchars($search ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                </div>
                <?php if ($search): ?>
                    <div class="col-12">
                        <a href="?page=users<?= $admin_id ? '&admin_id=' . $admin_id : '' ?><?= $club_id ? '&club_id=' . ($club_id === 'all' ? 'all' : $club_id) : '' ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times"></i> Limpiar búsqueda
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Tabla de usuarios -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list"></i> Lista de Usuarios
                <?php if ($search): ?>
                    <span class="badge bg-info">Búsqueda: "<?= htmlspecialchars($search) ?>"</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($users)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <p class="text-muted">
                        <?php if ($search): ?>
                            No se encontraron usuarios con la búsqueda "<?= htmlspecialchars($search) ?>"
                        <?php else: ?>
                            No hay usuarios registrados
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>Club</th>  
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['id']) ?></td>
                                <td><?= htmlspecialchars($u['nombre'] ?? '-') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($u['username']) ?></strong>
                                    <?php if ($u['id'] === $current_user['id']): ?>
                                        <span class="badge bg-info">Tú</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($u['email'] ?: '-') ?></td>
                                <td>
                                    <?php if ($u['club_nombre']): ?>
                                        <?= htmlspecialchars($u['club_nombre']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $role_colors = [
                                        'admin_general' => 'danger',
                                        'admin_torneo' => 'warning',
                                        'admin_club' => 'info',
                                        'usuario' => 'secondary',
                                        'operador' => 'dark'
                                    ];
                                    $role_labels = [
                                        'admin_general' => 'Admin General',
                                        'admin_torneo' => 'Admin Torneo',
                                        'admin_club' => 'Admin Organización',
                                        'usuario' => 'Usuario',
                                        'operador' => 'Operador'
                                    ];
                                    ?>
                                    <span class="badge bg-<?= $role_colors[$u['role']] ?? 'secondary' ?> role-badge">
                                        <?= $role_labels[$u['role']] ?? htmlspecialchars($u['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['status']): ?>
                                        <span class="badge bg-success status-badge">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary status-badge">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="editUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>', '<?= htmlspecialchars($u['email']) ?>', '<?= $u['role'] ?>', <?= $u['club_id'] ?: 'null' ?>, <?= isset($u['entidad']) ? (int)$u['entidad'] : 0 ?>)"
                                                title="Editar usuario">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                onclick="changePassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')"
                                                title="Cambiar contrase�a">
                                            <i class="fas fa-key"></i>
                                        </button>

                                        <?php
                                        $notify_return = 'index.php?page=users&action=list';
                                        if (!empty($_GET['search'])) {
                                            $notify_return .= '&search=' . rawurlencode((string) $_GET['search']);
                                        }
                                        if (!empty($_GET['club_id'])) {
                                            $notify_return .= '&club_id=' . rawurlencode((string) $_GET['club_id']);
                                        }
                                        $notify_base = class_exists('AppHelpers')
                                            ? AppHelpers::dashboard('users', [
                                                'action' => 'send_access_notification',
                                                'user_id' => (int) $u['id'],
                                                'return' => $notify_return,
                                            ])
                                            : '?page=users&action=send_access_notification&user_id=' . (int) $u['id'] . '&return=' . rawurlencode($notify_return);
                                        $has_celular = !empty(trim((string) ($u['celular'] ?? '')));
                                        $role_labels_notify = [
                                            'admin_general' => 'Admin General',
                                            'admin_torneo' => 'Admin Torneo',
                                            'admin_club' => 'Admin Organización',
                                            'usuario' => 'Usuario',
                                            'operador' => 'Operador',
                                        ];
                                        ?>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle"
                                                    data-bs-toggle="dropdown" aria-expanded="false"
                                                    title="Enviar datos de acceso y enlace para cambiar clave">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><h6 class="dropdown-header">Notificar a <?= htmlspecialchars($u['username']) ?></h6></li>
                                                <li>
                                                    <a class="dropdown-item <?= $has_celular ? '' : 'disabled' ?>"
                                                       href="<?= $has_celular ? htmlspecialchars($notify_base . '&canal=whatsapp') : '#' ?>"
                                                       <?= $has_celular ? '' : 'tabindex="-1" aria-disabled="true"' ?>>
                                                        <i class="fab fa-whatsapp text-success me-2"></i>WhatsApp
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="<?= htmlspecialchars($notify_base . '&canal=web') ?>">
                                                        <i class="fas fa-bell text-primary me-2"></i>Notificación web
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="<?= htmlspecialchars($notify_base . '&canal=telegram') ?>">
                                                        <i class="fab fa-telegram-plane text-info me-2"></i>Telegram
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item"
                                                            onclick="previewAccessNotify(<?= htmlspecialchars(json_encode([
                                                                'nombre' => $u['nombre'] ?? '',
                                                                'username' => $u['username'] ?? '',
                                                                'cedula' => $u['cedula'] ?? '',
                                                                'id' => (int) $u['id'],
                                                                'role' => $role_labels_notify[$u['role'] ?? ''] ?? ($u['role'] ?? ''),
                                                            ]), ENT_QUOTES, 'UTF-8') ?>)">
                                                        <i class="fas fa-eye text-secondary me-2"></i>Vista previa
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                        
                                        <?php if ($u['id'] !== $current_user['id']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-<?= $u['status'] ? 'warning' : 'success' ?>" 
                                                    onclick="toggleStatus(<?= $u['id'] ?>, <?= $u['status'] ? 'false' : 'true' ?>)"
                                                    title="<?= $u['status'] ? 'Desactivar' : 'Activar' ?> usuario">
                                                <i class="fas fa-<?= $u['status'] ? 'ban' : 'check' ?>"></i>
                                            </button>
                                            
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')"
                                                    title="Eliminar usuario">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginaci�n -->
            <?php if (isset($pagination)): ?>
                <?= $pagination->render() ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modal Crear Usuario -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="createUserForm">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="fechnac" id="fechnac">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus"></i> Nuevo Usuario
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Sección de búsqueda: cédula o usuario (para Admin Torneo = tomar de afiliados) -->
                    <div class="card mb-3 border-primary">
                        <div class="card-header bg-light">
                            <i class="fas fa-search"></i> Buscar por Cédula o Usuario (afiliado)
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-2">Registrar un Admin Torneo: busque por cédula o nombre de usuario. Si existe en la plataforma, asígnelo; si no existe, debe registrarlo primero.</p>
                            <div class="mb-2">
                                <label class="form-label">Buscar por</label>
                                <div class="d-flex gap-3">
                                    <label class="form-check"><input type="radio" class="form-check-input" name="buscar_por_tipo" value="cedula" id="buscar_por_cedula" checked> Cédula</label>
                                    <label class="form-check"><input type="radio" class="form-check-input" name="buscar_por_tipo" value="usuario" id="buscar_por_usuario"> Usuario (nombre de usuario)</label>
                                </div>
                            </div>
                            <div id="busqueda_por_cedula_row" class="row">
                                <div class="col-md-3">
                                    <label class="form-label">Nacionalidad</label>
                                    <select class="form-select" id="nacionalidad">
                                        <option value="V" selected>V</option>
                                        <option value="E">E</option>
                                        <option value="J">J</option>
                                        <option value="P">P</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Cédula</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="cedula_busqueda" name="cedula" placeholder="Ingrese la cédula">
                                        <button type="button" class="btn btn-primary" onclick="buscarPersona()" title="Buscar persona"><i class="fas fa-search"></i> Buscar</button>
                                    </div>
                                </div>
                            </div>
                            <div id="busqueda_por_usuario_row" class="row" style="display:none;">
                                <div class="col-md-8">
                                    <label class="form-label">Nombre de usuario</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="usuario_busqueda" placeholder="Nombre de usuario del afiliado">
                                        <button type="button" class="btn btn-primary" onclick="buscarPersona()" title="Buscar usuario"><i class="fas fa-search"></i> Buscar</button>
                                    </div>
                                </div>
                            </div>
                            <div id="busqueda_resultado" class="mt-2"></div>
                        </div>
                    </div>

                    <!-- Datos personales -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nombre" class="form-label">Nombre Completo *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="celular" class="form-label">Celular</label>
                            <input type="text" class="form-control" id="celular" name="celular">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars($form_data['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Nombre de Usuario *</label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?= htmlspecialchars($form_data['username'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="entidad" class="form-label">Entidad (Ubicación) *</label>
                            <select class="form-select" id="entidad" name="entidad" required>
                                <option value="">-- Seleccione --</option>
                                <?php if (!empty($entidades_options)): ?>
                                    <?php foreach ($entidades_options as $ent): ?>
                                        <option value="<?= htmlspecialchars($ent['codigo']) ?>" <?= (($form_data['entidad'] ?? '') == $ent['codigo']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ent['nombre'] ?? $ent['codigo']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No hay entidades disponibles</option>
                                <?php endif; ?>
                            </select>
                            <small class="form-text text-muted">Se guardará en usuarios.entidad</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Contraseña *</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="role" class="form-label">Rol *</label>
                            <select class="form-select" id="role" name="role" required onchange="toggleClubField('create')">
                                <?php if (Auth::isAdminGeneral()): ?>
                                    <option value="usuario" <?= ($form_data['role'] ?? '') === 'usuario' ? 'selected' : '' ?>>Usuario</option>
                                    <option value="admin_club" <?= ($form_data['role'] ?? '') === 'admin_club' ? 'selected' : '' ?>>Admin Organización</option>
                                    <option value="admin_torneo" <?= ($form_data['role'] ?? '') === 'admin_torneo' ? 'selected' : '' ?>>Admin Torneo</option>
                                    <option value="admin_general" <?= ($form_data['role'] ?? '') === 'admin_general' ? 'selected' : '' ?>>Admin General</option>
                                <?php else: ?>
                                    <!-- admin_club solo puede crear admin_torneo y operador -->
                                    <option value="admin_torneo" <?= ($form_data['role'] ?? '') === 'admin_torneo' ? 'selected' : '' ?>>Admin Torneo</option>
                                    <option value="operador" <?= ($form_data['role'] ?? '') === 'operador' ? 'selected' : '' ?>>Operador</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <?php if (Auth::isAdminGeneral()): ?>
                    <div class="mb-3" id="club_field_create">
                        <label for="club_id" class="form-label">
                            Club Asignado
                            <span class="text-danger" id="club_required_create">*</span>
                        </label>
                        <select class="form-select" id="club_id" name="club_id">
                            <option value="">-- Sin asignar --</option>
                            <?php
                            try {
                                $clubs_stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
                                $clubs_list = $clubs_stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($clubs_list as $club) {
                                    $selected = (($form_data['club_id'] ?? '') == $club['id']) ? 'selected' : '';
                                    echo '<option value="' . $club['id'] . '" ' . $selected . '>' . htmlspecialchars($club['nombre']) . '</option>';
                                }
                            } catch (Exception $e) {
                                // Silencio
                            }
                            ?>
                        </select>
                        <small class="form-text text-muted" id="club_help_create">
                            Requerido para Admin Torneo.
                        </small>
                    </div>
                    <?php else: ?>
                    <!-- admin_club: el club se asigna automáticamente -->
                    <input type="hidden" name="club_id" id="club_id" value="<?= (int)$current_user['club_id'] ?>">
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Crear Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Usuario -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit"></i> Editar Usuario
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">Nombre de Usuario *</label>
                        <input type="text" class="form-control" id="edit_username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email">
                    </div>

                    <div class="mb-3">
                        <label for="edit_entidad" class="form-label">Entidad (Ubicación) *</label>
                        <select class="form-select" id="edit_entidad" name="entidad" required>
                            <option value="">-- Seleccione --</option>
                            <?php if (!empty($entidades_options)): ?>
                                <?php foreach ($entidades_options as $ent): ?>
                                    <option value="<?= htmlspecialchars($ent['codigo']) ?>">
                                        <?= htmlspecialchars($ent['nombre'] ?? $ent['codigo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No hay entidades disponibles</option>
                            <?php endif; ?>
                        </select>
                        <small class="form-text text-muted">Se guardará en usuarios.entidad</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_password" class="form-label">Nueva Contrase�a (dejar vac�o para mantener la actual)</label>
                        <input type="password" class="form-control" id="edit_password" name="password">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_role" class="form-label">Rol *</label>
                        <select class="form-select" id="edit_role" name="role" required onchange="toggleClubField('edit')">
                            <?php if (Auth::isAdminGeneral()): ?>
                                <option value="usuario">Usuario</option>
                                <option value="admin_club">Admin Organización</option>
                                <option value="admin_torneo">Admin Torneo</option>
                                <option value="admin_general">Admin General</option>
                            <?php else: ?>
                                <option value="admin_torneo">Admin Torneo</option>
                                <option value="operador">Operador</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="club_field_edit">
                        <label for="edit_club_id" class="form-label">
                            Club Asignado
                            <span class="text-danger" id="club_required_edit">*</span>
                        </label>
                        <select class="form-select" id="edit_club_id" name="club_id">
                            <option value="">-- Sin asignar --</option>
                            <?php
                            try {
                                $clubs_stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
                                $clubs_list = $clubs_stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($clubs_list as $club) {
                                    echo '<option value="' . $club['id'] . '">' . htmlspecialchars($club['nombre']) . '</option>';
                                }
                            } catch (Exception $e) {
                                // Silencio
                            }
                            ?>
                        </select>
                        <small class="form-text text-muted" id="club_help_edit">
                            Requerido para Admin Torneo.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Actualizar Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Confirmar Eliminaci�n -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle"></i> Confirmar Eliminaci�n
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>�Est� seguro de que desea eliminar el usuario <strong id="delete_username"></strong>?</p>
                <p class="text-danger"><small>Esta acci�n no se puede deshacer.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Vista previa notificación de acceso -->
<div class="modal fade" id="accessNotifyPreviewModal" tabindex="-1" aria-labelledby="accessNotifyPreviewLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="accessNotifyPreviewLabel"><i class="fas fa-paper-plane me-2"></i>Datos del mensaje</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="accessNotifyPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirmar Cambio de Estado -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-toggle-on"></i> Cambiar Estado
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="toggle_status_message"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" id="toggle_user_id">
                    <button type="submit" class="btn btn-warning" id="toggle_confirm_btn">
                        <i class="fas fa-check"></i> Confirmar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Cambiar Contrase�a -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="user_id" id="change_password_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key"></i> Cambiar Contrase�a
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Cambiando contrase�a para: <strong id="change_password_username"></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Nueva Contrase�a *</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                        <div class="form-text">M�nimo 6 caracteres</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirmar Contrase�a *</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Cambiar Contrase�a
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pestaña de Solicitudes de Registro -->
<div class="tab-pane fade <?= $action === 'requests' ? 'show active' : '' ?>" id="requests" role="tabpanel" aria-labelledby="requests-tab">
    <!-- Mensajes -->
    <?php if ($success_message && $action === 'requests'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors) && $action === 'requests'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabla de solicitudes -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-plus"></i> Solicitudes de Registro Pendientes
            </h5>
        </div>
        <div class="card-body">
            <?php
            $requests = $requests_result ? $requests_result['data'] : [];
            if (empty($requests)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No hay solicitudes de registro pendientes</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>ID Usuario</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Club</th>
                                <th>Rol</th>
                                <th>Fecha Solicitud</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $r): ?>
                                <tr>
                                    <td><?= $r['id'] ?></td>
                                    <td><?= htmlspecialchars($r['nombre']) ?></td>
                                    <td><?= htmlspecialchars($r['id']) ?></td>
                                    <td><?= htmlspecialchars($r['email']) ?></td>
                                    <td><?= htmlspecialchars($r['username']) ?></td>
                                    <td>
                                        <?php if ($r['club_nombre']): ?>
                                            <?= htmlspecialchars($r['club_nombre']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $role_labels = [
                                            'usuario' => 'Usuario',
                                            'admin_club' => 'Admin Organización'
                                        ];
                                        ?>
                                        <span class="badge bg-info role-badge">
                                            <?= $role_labels[$r['role']] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td class="table-actions">
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-success"
                                                    onclick="approveRequest(<?= $r['id'] ?>, '<?= htmlspecialchars($r['nombre']) ?>')"
                                                    title="Aprobar solicitud">
                                                <i class="fas fa-check"></i>
                                            </button>

                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="rejectRequest(<?= $r['id'] ?>, '<?= htmlspecialchars($r['nombre']) ?>')"
                                                    title="Rechazar solicitud">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <?php if (isset($pagination)): ?>
                    <?= $pagination->render() ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Aprobar Solicitud -->
<div class="modal fade" id="approveRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success">
                    <i class="fas fa-check-circle"></i> Aprobar Solicitud
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>¿Está seguro de que desea aprobar la solicitud de registro de <strong id="approve_request_name"></strong>?</p>
                <p class="text-success"><small>Se creará un nuevo usuario con los datos proporcionados.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" action="?action=requests" style="display: inline;">
                    <input type="hidden" name="action" value="approve_request">
                    <input type="hidden" name="request_id" id="approve_request_id">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Aprobar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Rechazar Solicitud -->
<div class="modal fade" id="rejectRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?action=requests">
                <input type="hidden" name="action" value="reject_request">
                <input type="hidden" name="request_id" id="reject_request_id">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-times-circle"></i> Rechazar Solicitud
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro de que desea rechazar la solicitud de registro de <strong id="reject_request_name"></strong>?</p>
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Razón del rechazo (opcional)</label>
                        <textarea class="form-control" id="rejection_reason" name="reason" rows="3" placeholder="Explique por qué se rechaza la solicitud..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i> Rechazar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function getBaseUrlForApi() {
    let baseUrl = window.location.pathname;
    if (baseUrl.includes('/public/')) baseUrl = baseUrl.split('/public/')[0];
    else if (baseUrl.includes('/mistorneos_fvd/')) baseUrl = '/mistorneos_fvd';
    else {
        const pathParts = baseUrl.split('/').filter(p => p);
        if (pathParts.length > 0) baseUrl = '/' + pathParts[0];
        else baseUrl = '';
    }
    return baseUrl;
}

function buscarPersona() {
    const buscarPorCedula = document.getElementById('buscar_por_cedula').checked;
    const resultadoDiv = document.getElementById('busqueda_resultado');
    const roleSelect = document.getElementById('role');
    const role = roleSelect ? roleSelect.value : '';
    const isAdminTorneo = role === 'admin_torneo';
    const clubIdEl = document.getElementById('club_id');
    const clubId = clubIdEl && clubIdEl.value ? clubIdEl.value : '';

    let apiUrl;
    if (buscarPorCedula) {
        const cedula = document.getElementById('cedula_busqueda').value.trim();
        const nacionalidad = document.getElementById('nacionalidad').value;
        if (!cedula) {
            resultadoDiv.innerHTML = '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle"></i> Ingrese una cédula para buscar</div>';
            return;
        }
        apiUrl = getBaseUrlForApi() + '/api/search_user_persona.php?cedula=' + encodeURIComponent(cedula) + '&nacionalidad=' + encodeURIComponent(nacionalidad);
    } else {
        const usuario = document.getElementById('usuario_busqueda').value.trim();
        if (!usuario) {
            resultadoDiv.innerHTML = '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle"></i> Ingrese un nombre de usuario para buscar</div>';
            return;
        }
        apiUrl = getBaseUrlForApi() + '/api/search_user_persona.php?buscar_por=usuario&usuario=' + encodeURIComponent(usuario);
        if (clubId) apiUrl += '&club_id=' + encodeURIComponent(clubId);
    }

    resultadoDiv.innerHTML = '<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';

    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                resultadoDiv.innerHTML = '<div class="alert alert-danger py-2">' + (data.error || 'Error') + '</div>';
                return;
            }
            const d = data.data;
            if (d.encontrado && d.existe_usuario && d.usuario_existente) {
                const u = d.usuario_existente;
                if (isAdminTorneo) {
                    resultadoDiv.innerHTML = `
                        <div class="alert alert-success py-2">
                            <i class="fas fa-check-circle"></i> ${d.mensaje}
                            <br><strong>${u.nombre}</strong> (${u.username})
                            <form method="POST" action="" class="mt-2">
                                <input type="hidden" name="action" value="assign_admin_torneo">
                                <input type="hidden" name="user_id" value="${u.id}">
                                <input type="hidden" name="club_id" value="${clubId || (u.club_id || '')}">
                                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-user-tag"></i> Asignar como Admin Torneo</button>
                            </form>
                        </div>`;
                } else {
                    resultadoDiv.innerHTML = `
                        <div class="alert alert-warning py-2">
                            <i class="fas fa-exclamation-circle"></i> ${d.mensaje}
                            <br><small>Usuario: ${u.username} - ${u.nombre}</small>
                        </div>`;
                }
                return;
            }
            if (d.encontrado && d.en_solicitudes && d.solicitud) {
                const s = d.solicitud;
                const persona = d.persona || s;
                document.getElementById('nombre').value = s.nombre || '';
                document.getElementById('celular').value = s.celular || '';
                document.getElementById('email').value = s.email || '';
                document.getElementById('username').value = s.username || '';
                document.getElementById('cedula_busqueda').value = s.cedula || '';
                if (document.getElementById('fechnac')) document.getElementById('fechnac').value = (s.fechnac || persona.fechnac) || '';
                resultadoDiv.innerHTML = `
                    <div class="alert alert-info py-2">
                        <i class="fas fa-info-circle"></i> ${d.mensaje}
                        <br>Datos precargados desde solicitud. Complete contraseña, club y guarde para <strong>registrarlo y asignar como Admin Torneo</strong>.
                    </div>`;
                if (roleSelect) roleSelect.value = 'admin_torneo';
                toggleClubField('create');
                return;
            }
            if (d.encontrado && d.persona) {
                const persona = d.persona;
                document.getElementById('nombre').value = persona.nombre || '';
                document.getElementById('celular').value = persona.celular || '';
                document.getElementById('email').value = persona.email || '';
                if (document.getElementById('fechnac')) document.getElementById('fechnac').value = persona.fechnac || '';
                if (!document.getElementById('username').value) {
                    const nameParts = (persona.nombre || '').toLowerCase().split(' ');
                    if (nameParts.length >= 2) document.getElementById('username').value = nameParts[0] + '.' + nameParts[nameParts.length - 1];
                }
                resultadoDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle"></i> Persona encontrada: <strong>' + (persona.nombre || '') + '</strong></div>';
                return;
            }
            resultadoDiv.innerHTML = '<div class="alert alert-info py-2"><i class="fas fa-info-circle"></i> ' + (d.mensaje || 'No encontrado. Debe registrarse primero en la plataforma.') + '</div>';
        })
        .catch(error => {
            console.error('Error:', error);
            resultadoDiv.innerHTML = '<div class="alert alert-danger py-2"><i class="fas fa-times-circle"></i> Error al buscar. Intente nuevamente.</div>';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const buscarPorCedula = document.getElementById('buscar_por_cedula');
    const buscarPorUsuario = document.getElementById('buscar_por_usuario');
    const rowCedula = document.getElementById('busqueda_por_cedula_row');
    const rowUsuario = document.getElementById('busqueda_por_usuario_row');
    if (buscarPorCedula && buscarPorUsuario) {
        function toggleBusquedaRows() {
            if (buscarPorCedula.checked) {
                if (rowCedula) rowCedula.style.display = '';
                if (rowUsuario) rowUsuario.style.display = 'none';
            } else {
                if (rowCedula) rowCedula.style.display = 'none';
                if (rowUsuario) rowUsuario.style.display = '';
            }
            document.getElementById('busqueda_resultado').innerHTML = '';
        }
        buscarPorCedula.addEventListener('change', toggleBusquedaRows);
        buscarPorUsuario.addEventListener('change', toggleBusquedaRows);
    }
    const cedulaInput = document.getElementById('cedula_busqueda');
    if (cedulaInput) {
        cedulaInput.addEventListener('blur', function() {
            if (document.getElementById('buscar_por_cedula').checked && this.value.trim().length >= 6) buscarPersona();
        });
        cedulaInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); buscarPersona(); }
        });
    }
    const usuarioInput = document.getElementById('usuario_busqueda');
    if (usuarioInput) {
        usuarioInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); buscarPersona(); }
        });
    }
});

// Función para mostrar/ocultar el campo de club según el rol
function toggleClubField(formType) {
    const roleSelect = document.getElementById(formType === 'create' ? 'role' : 'edit_role');
    const clubField = document.getElementById('club_field_' + formType);
    const clubSelect = document.getElementById(formType === 'create' ? 'club_id' : 'edit_club_id');
    const clubRequired = document.getElementById('club_required_' + formType);
    const clubHelp = document.getElementById('club_help_' + formType);
    
    const role = roleSelect.value;
    
    // Admin General y Usuario no necesitan club
    if (role === 'admin_general' || role === 'usuario') {
        clubField.style.display = 'none';
        clubSelect.value = '';
        clubSelect.removeAttribute('required');
        clubRequired.style.display = 'none';
    } else {
        // Admin Torneo puede requerir club
        clubField.style.display = 'block';
        clubRequired.style.display = 'inline';
        
        if (role === 'admin_torneo') {
            clubSelect.setAttribute('required', 'required');
            clubHelp.innerHTML = '<strong class="text-danger">Obligatorio para Admin Torneo.</strong> Los torneos solo mostrar�n los del club asignado.';
        } else if (role === 'admin_club') {
            clubSelect.removeAttribute('required');
            clubRequired.style.display = 'none';
            clubHelp.innerHTML = 'Opcional para Admin Organización. Podr� crear sus clubes desde su panel.';
        } else {
            clubSelect.removeAttribute('required');
            clubRequired.style.display = 'none';
            clubHelp.innerHTML = 'Opcional para este rol.';
        }
    }
}

// Inicializar el estado del campo club al cargar
document.addEventListener('DOMContentLoaded', function() {
    toggleClubField('create');
});

function editUser(id, username, email, role, club_id, entidad) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_club_id').value = club_id || '';
    document.getElementById('edit_entidad').value = entidad || '';
    
    // Actualizar visibilidad del campo club seg�n el rol
    toggleClubField('edit');
    
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function deleteUser(id, username) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('delete_username').textContent = username;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
    modal.show();
}

function toggleStatus(id, newStatus) {
    document.getElementById('toggle_user_id').value = id;
    
    const message = document.getElementById('toggle_status_message');
    const btn = document.getElementById('toggle_confirm_btn');
    
    if (newStatus === 'true') {
        message.textContent = '�Est� seguro de que desea activar este usuario?';
        btn.className = 'btn btn-success';
        btn.innerHTML = '<i class="fas fa-check"></i> Activar';
    } else {
        message.textContent = '�Est� seguro de que desea desactivar este usuario?';
        btn.className = 'btn btn-warning';
        btn.innerHTML = '<i class="fas fa-ban"></i> Desactivar';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('toggleStatusModal'));
    modal.show();
}

function previewAccessNotify(data) {
    var body = document.getElementById('accessNotifyPreviewBody');
    if (!body) return;
    var lines = [
        'Nombre: ' + (data.nombre || '—'),
        'Usuario: ' + (data.username || '—'),
        'Cédula: ' + (data.cedula || '—'),
        'ID usuario: ' + (data.id || '—'),
        'Rol: ' + (data.role || '—'),
        '',
        'El mensaje incluirá un enlace personalizado para cambiar la contraseña y la URL de inicio de sesión.'
    ];
    body.innerHTML = '<pre class="mb-0 small" style="white-space:pre-wrap;">' + lines.map(function (l) {
        return l.replace(/</g, '&lt;');
    }).join('\n') + '</pre>';
    var modal = new bootstrap.Modal(document.getElementById('accessNotifyPreviewModal'));
    modal.show();
}

function changePassword(id, username) {
    document.getElementById('change_password_user_id').value = id;
    document.getElementById('change_password_username').textContent = username;
    
    // Limpiar campos
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    modal.show();
}

function approveRequest(id, name) {
    document.getElementById('approve_request_id').value = id;
    document.getElementById('approve_request_name').textContent = name;
    
    const modal = new bootstrap.Modal(document.getElementById('approveRequestModal'));
    modal.show();
}

function rejectRequest(id, name) {
    document.getElementById('reject_request_id').value = id;
    document.getElementById('reject_request_name').textContent = name;
    
    // Limpiar campo de razón
    document.getElementById('rejection_reason').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('rejectRequestModal'));
    modal.show();
}

// Manejar cambio de pestañas
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('#userTabs .nav-link');
    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            const target = this.getAttribute('data-bs-target');
            if (target === '#requests') {
                window.location.href = '?action=requests';
            } else {
                window.location.href = '?action=list';
            }
        });
    });
});
</script>

