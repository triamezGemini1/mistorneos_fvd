<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

Auth::requireRole(['admin_general']);

$admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;
$entidad_id = isset($_GET['entidad_id']) ? (int)$_GET['entidad_id'] : 0;
$error_message = null;
$admin_data = null;
$clubes = [];
$admin_stats = [];
$current_tab = $_GET['tab'] ?? 'clubes'; // 'clubes' o 'torneos'

try {
    // Obtener datos del administrador de organización
    $stmt = DB::pdo()->prepare("
        SELECT 
            u.id as admin_id,
            u.username as admin_username,
            u.nombre as admin_nombre,
            u.email as admin_email,
            u.club_id as club_principal_id,
            u.entidad,
            c.nombre as club_principal_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            c.direccion as club_direccion
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.id = ? AND u.role = 'admin_club'
    ");
    $stmt->execute([$admin_id]);
    $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin_data) {
        throw new Exception('Administrador de organización no encontrado');
    }
    
    // Obtener clubes por admin_club_id (todos iguales, sin distinción)
    $clubes_ids = ClubHelper::getClubesByAdminClubId($admin_id);
    if (empty($clubes_ids) && !empty($admin_data['club_principal_id'])) {
        $clubes_ids = ClubHelper::getClubesSupervised($admin_data['club_principal_id']);
    }
    
    // Obtener organización del admin
    $org_id = null;
    $stmt_org = DB::pdo()->prepare("SELECT id FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 LIMIT 1");
    $stmt_org->execute([$admin_id]);
    $org_id = $stmt_org->fetchColumn();
    
    if (!empty($clubes_ids)) {
        $placeholders = implode(',', array_fill(0, count($clubes_ids), '?'));
        
        $sql_torneos_sub = $org_id ? "(SELECT COUNT(*) FROM tournaments t WHERE t.club_responsable = " . (int)$org_id . " AND t.estatus = 1)" : "0";
        $sql_clubes = "
            SELECT 
                c.id,
                c.nombre,
                c.delegado,
                c.telefono,
                c.direccion,
                c.estatus,
                COUNT(DISTINCT u.id) as total_afiliados,
                SUM(CASE WHEN u.sexo = 'M' OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 'F' OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres,
                $sql_torneos_sub as total_torneos
            FROM clubes c
            LEFT JOIN usuarios u ON " . ClubHelper::sqlJoinUsuariosAfiliadosOnClub(DB::pdo(), 'c') . "
            WHERE c.id IN ($placeholders)
            GROUP BY c.id, c.nombre, c.delegado, c.telefono, c.direccion, c.estatus
            ORDER BY c.nombre ASC
        ";
        
        $stmt = DB::pdo()->prepare($sql_clubes);
        $stmt->execute($clubes_ids);
        $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Calcular estadísticas totales
    $admin_stats = [
        'total_clubes' => count($clubes),
        'total_afiliados' => array_sum(array_column($clubes, 'total_afiliados')),
        'hombres' => array_sum(array_column($clubes, 'hombres')),
        'mujeres' => array_sum(array_column($clubes, 'mujeres')),
        'total_torneos' => array_sum(array_column($clubes, 'total_torneos'))
    ];
    
} catch (Exception $e) {
    $error_message = "Error al cargar datos: " . $e->getMessage();
    error_log("admin_clubs/detail.php error: " . $e->getMessage());
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="<?= $entidad_id ? 'index.php?page=clubs&action=list&amp;entidad_id=' . (int)$entidad_id . '#asociacion-' . (int)$entidad_id : 'index.php?page=home' ?>" 
                       class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i>Volver
                    </a>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-user-shield me-2"></i><?= htmlspecialchars($admin_data['admin_nombre'] ?? $admin_data['admin_username'] ?? 'Administrador de Organización') ?>
                    </h1>
                    <p class="text-muted mb-0">
                        <strong><?= count($clubes) ?></strong> club<?= count($clubes) !== 1 ? 'es' : '' ?> asignado<?= count($clubes) !== 1 ? 's' : '' ?>
                    </p>
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

<!-- Estadísticas del Administrador de Organización -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center bg-primary text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($admin_stats['total_clubes']) ?></h2>
                <small>Clubes</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-info text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($admin_stats['total_afiliados']) ?></h2>
                <small>Total Afiliados</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-success text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($admin_stats['hombres']) ?></h2>
                <small>Hombres</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-danger text-white">
            <div class="card-body">
                <h2 class="mb-0"><?= number_format($admin_stats['mujeres']) ?></h2>
                <small>Mujeres</small>
            </div>
        </div>
    </div>
</div>

<!-- Tabs para Clubes y Torneos -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $current_tab === 'clubes' ? 'active' : '' ?>" 
                onclick="window.location.href='index.php?page=admin_clubs&action=detail&admin_id=<?= $admin_id ?>&entidad_id=<?= $entidad_id ?>&tab=clubes'">
            <i class="fas fa-building me-2"></i>Clubes (<?= count($clubes) ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $current_tab === 'torneos' ? 'active' : '' ?>" 
                onclick="window.location.href='index.php?page=admin_clubs&action=detail&admin_id=<?= $admin_id ?>&entidad_id=<?= $entidad_id ?>&tab=torneos'">
            <i class="fas fa-trophy me-2"></i>Torneos (<?= $admin_stats['total_torneos'] ?>)
        </button>
    </li>
</ul>

<!-- Contenido de Tabs -->
<?php if ($current_tab === 'clubes'): ?>
    <!-- Tab Clubes -->
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="fas fa-building me-2"></i>Clubes Supervisados</h5>
        </div>
        <div class="card-body">
            <?php if (empty($clubes)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Este administrador no tiene clubes asignados</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Club</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Afiliados</th>
                                <th class="text-center">Hombres</th>
                                <th class="text-center">Mujeres</th>
                                <th class="text-center">Torneos</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clubes as $club): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($club['nombre']) ?></strong>
                                        <?php if ($club['delegado']): ?>
                                            <br>
                                            <small class="text-muted"><i class="fas fa-user"></i> <?= htmlspecialchars($club['delegado']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $club['estatus'] ? 'success' : 'secondary' ?>">
                                            <?= $club['estatus'] ? 'Activo' : 'Inactivo' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= number_format((int)($club['total_afiliados'] ?? 0)) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= number_format((int)($club['hombres'] ?? 0)) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= number_format((int)($club['mujeres'] ?? 0)) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning"><?= number_format((int)($club['total_torneos'] ?? 0)) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <a href="index.php?page=clubs&action=detail&id=<?= $club['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
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

<?php else: ?>
    <!-- Tab Torneos -->
    <?php
    // Obtener torneos de la organización del admin (club_responsable = org_id)
    $torneos = [];
    if ($org_id) {
        $has_cod_org = false;
        try {
            $has_cod_org = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $has_cod_org = false;
        }
        $org_join = $has_cod_org
            ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
            : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";
        $sql_torneos = "
            SELECT 
                t.id,
                t.nombre,
                t.fechator,
                t.costo,
                t.clase,
                t.modalidad,
                t.estatus,
                o.nombre as organizacion_nombre,
                (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = t.id) as total_inscritos
            FROM tournaments t
            {$org_join}
            WHERE " . ($has_cod_org ? "(t.club_responsable = ? OR t.club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1))" : "t.club_responsable = ?") . "
            ORDER BY t.fechator DESC, t.nombre ASC
        ";
        $stmt = DB::pdo()->prepare($sql_torneos);
        $stmt->execute($has_cod_org ? [$org_id, $org_id] : [$org_id]);
        $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    ?>
    
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Torneos Realizados</h5>
        </div>
        <div class="card-body">
            <?php if (empty($torneos)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-trophy fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Este administrador no ha realizado torneos aún</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Torneo</th>
                                <th>Fecha</th>
                                <th>Organización</th>
                                <th class="text-center">Clase</th>
                                <th class="text-center">Modalidad</th>
                                <th class="text-center">Inscritos</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($torneos as $torneo): ?>
                                <?php
                                $es_futuro = strtotime($torneo['fechator']) >= strtotime('today');
                                $es_pasado = strtotime($torneo['fechator']) < strtotime('today');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($torneo['nombre']) ?></strong>
                                        <?php if ($torneo['costo']): ?>
                                            <br>
                                            <small class="text-muted">$<?= number_format($torneo['costo'], 2) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($torneo['fechator'])) ?></td>
                                    <td><?= htmlspecialchars($torneo['organizacion_nombre'] ?? 'N/A') ?></td>
                                    <td class="text-center">
                                        <?php if ($torneo['clase']): ?>
                                            <span class="badge bg-info"><?= htmlspecialchars($torneo['clase']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($torneo['modalidad']): ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($torneo['modalidad']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= number_format((int)($torneo['total_inscritos'] ?? 0)) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!$torneo['estatus']): ?>
                                            <span class="badge bg-secondary">Inactivo</span>
                                        <?php elseif ($es_futuro): ?>
                                            <span class="badge bg-success">Próximo</span>
                                        <?php elseif ($es_pasado): ?>
                                            <span class="badge bg-warning">Finalizado</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Hoy</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="panel_torneo.php?torneo_id=<?= $torneo['id'] ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-cog me-1"></i>Panel
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
<?php endif; ?>
