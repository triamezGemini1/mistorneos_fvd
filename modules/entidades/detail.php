<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/OrganizacionDashboardStats.php';

Auth::requireRole(['admin_general']);

$entidad_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = null;
$entidad_nombre = null;
$admin_clubs = [];
$entidad_stats = [
    'total_admin_clubs' => 0,
    'total_afiliados' => 0,
    'hombres' => 0,
    'mujeres' => 0,
    'total_torneos' => 0
];

try {
    // Cargar nombre de la entidad
    $entidad_map = [];
    $pdo = DB::pdo();
    $flags = OrganizacionDashboardStats::columnFlags($pdo);
    $has_usuario_cod_org = ($flags['has_usuario_cod_org'] ?? false)
        && OrganizacionDashboardStats::tableHasColumn($pdo, 'usuarios', 'cod_org');

    $has_cod_org = false;
    try {
        $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {
        $has_cod_org = false;
    }

    try {
        $club_misma_org_sql = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'cx', 'o');
    } catch (Throwable $ignored) {
        $club_misma_org_sql = 'cx.cod_org = o.id';
    }

    /** Usuarios vinculados a la organización o (sin columna usuarios.cod_org: por club con misma federación). */
    if ($has_usuario_cod_org) {
        $ux_org_scope = 'ux.cod_org = o.id AND ux.entidad = ?';
    } else {
        try {
            $club_uc_match = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'uc', 'o');
        } catch (Throwable $ignored) {
            $club_uc_match = 'uc.cod_org = o.id';
        }
        $ux_org_scope = "ux.entidad = ? AND EXISTS (
            SELECT 1 FROM clubes uc WHERE uc.id = ux.club_id AND uc.estatus = 1 AND ({$club_uc_match})
        )";
    }
    $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
    $codeCol = null;
    $nameCol = null;
    foreach ($cols as $c) {
        $f = strtolower($c['Field'] ?? $c['field'] ?? '');
        if (!$codeCol && in_array($f, ['codigo','cod_entidad','id','code'], true)) {
            $codeCol = $f;
        }
        if (!$nameCol && in_array($f, ['nombre','descripcion','entidad','nombre_entidad'], true)) {
            $nameCol = $f;
        }
    }
    
    if ($codeCol && $nameCol) {
        $stmt = $pdo->prepare("SELECT {$nameCol} AS nombre FROM entidad WHERE {$codeCol} = ?");
        $stmt->execute([$entidad_id]);
        $entidad_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $entidad_nombre = $entidad_data['nombre'] ?? "Entidad {$entidad_id}";
    } else {
        $entidad_nombre = "Entidad {$entidad_id}";
    }
    
    // Obtener organizaciones de la entidad y sus estadísticas usando solo organizacion_id + entidad.
    $sql = "
        SELECT 
            o.id as org_id,
            o.nombre as club_principal_nombre,
            u.id as admin_id,
            u.username as admin_username,
            u.nombre as admin_nombre,
            u.email as admin_email,
            o.telefono as club_telefono,
            o.responsable as club_delegado,
            (SELECT COUNT(*) FROM clubes cx WHERE cx.estatus = 1 AND cx.entidad = ? AND ({$club_misma_org_sql})) as supervised_clubs_count,
            (SELECT COUNT(*) FROM usuarios ux WHERE {$ux_org_scope}) as total_afiliados,
            (SELECT COUNT(*) FROM usuarios ux WHERE {$ux_org_scope} AND UPPER(COALESCE(ux.sexo,'M')) = 'M') as hombres,
            (SELECT COUNT(*) FROM usuarios ux WHERE {$ux_org_scope} AND UPPER(COALESCE(ux.sexo,'M')) = 'F') as mujeres,
            (SELECT COUNT(*) FROM tournaments t WHERE " . ($has_cod_org ? "(t.club_responsable = o.id OR t.club_responsable = o.cod_org)" : "t.club_responsable = o.id") . " AND t.estatus = 1) as total_torneos
        FROM organizaciones o
        LEFT JOIN usuarios u ON u.id = o.admin_user_id
        WHERE o.entidad = ?
        ORDER BY o.nombre ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entidad_id, $entidad_id, $entidad_id, $entidad_id, $entidad_id]);
    $admin_clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas totales de la entidad
    $entidad_stats = [
        'total_admin_clubs' => count($admin_clubs),
        'total_afiliados' => array_sum(array_column($admin_clubs, 'total_afiliados')),
        'hombres' => array_sum(array_column($admin_clubs, 'hombres')),
        'mujeres' => array_sum(array_column($admin_clubs, 'mujeres')),
        'total_torneos' => array_sum(array_column($admin_clubs, 'total_torneos'))
    ];
    
} catch (Exception $e) {
    $error_message = "Error al cargar datos: " . $e->getMessage();
    error_log("entidades/detail.php error: " . $e->getMessage());
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="<?= htmlspecialchars(AppHelpers::dashboard('entidades')) ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i>Entidades
                    </a>
                    <a href="index.php?page=organizaciones&entidad_id=<?= (int)$entidad_id ?>" class="btn btn-outline-primary btn-sm mb-2 ms-1">
                        <i class="fas fa-building me-1"></i>Organizaciones de esta entidad
                    </a>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-map-marked-alt me-2"></i><?= htmlspecialchars($entidad_nombre) ?>
                    </h1>
                    <p class="text-muted mb-0">Administradores de Club y Estadísticas</p>
                </div>
            </div>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Estadísticas de la Entidad -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center bg-primary text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($entidad_stats['total_admin_clubs']) ?></h2>
                <small>Admin. organizaciones</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-info text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($entidad_stats['total_afiliados']) ?></h2>
                <small>Total Afiliados</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-success text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($entidad_stats['hombres']) ?></h2>
                <small>Hombres</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-danger text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($entidad_stats['mujeres']) ?></h2>
                <small>Mujeres</small>
            </div>
        </div>
    </div>
</div>

<!-- Lista de Admin. organizaciones -->
<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-user-shield me-2"></i>Administradores de Club</h5>
    </div>
    <div class="card-body">
        <?php if (empty($admin_clubs)): ?>
            <div class="text-center py-4">
                <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                <p class="text-muted">Esta entidad no tiene administradores de club registrados</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Admin</th>
                            <th>Organización</th>
                            <th class="text-center">Clubes</th>
                            <th class="text-center">Afiliados</th>
                            <th class="text-center">Hombres</th>
                            <th class="text-center">Mujeres</th>
                            <th class="text-center">Torneos</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admin_clubs as $admin): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($admin['admin_nombre'] ?? $admin['admin_username']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($admin['admin_username']) ?></small>
                                    <?php if ($admin['admin_email']): ?>
                                        <br>
                                        <small class="text-muted"><i class="fas fa-envelope"></i> <?= htmlspecialchars($admin['admin_email']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($admin['club_principal_nombre'] ?? 'Sin organización') ?></strong>
                                    <?php if ($admin['club_delegado']): ?>
                                        <br>
                                        <small class="text-muted"><i class="fas fa-user"></i> <?= htmlspecialchars($admin['club_delegado']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($admin['club_telefono']): ?>
                                        <br>
                                        <small class="text-muted"><i class="fas fa-phone"></i> <?= htmlspecialchars($admin['club_telefono']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= number_format((int)($admin['supervised_clubs_count'] ?? 0) + 1) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= number_format((int)($admin['total_afiliados'] ?? 0)) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= number_format((int)($admin['hombres'] ?? 0)) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger"><?= number_format((int)($admin['mujeres'] ?? 0)) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= number_format((int)($admin['total_torneos'] ?? 0)) ?></span>
                                </td>
                                <td class="text-center">
                                    <a href="index.php?page=admin_clubs&action=detail&admin_id=<?= $admin['admin_id'] ?>&entidad_id=<?= $entidad_id ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye me-1"></i>Ver Detalle
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
