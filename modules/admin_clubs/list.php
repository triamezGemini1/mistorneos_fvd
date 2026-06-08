<?php
/**
 * Vista de Clubes Segmentados por Administrador de organización
 * Solo accesible para admin_general
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$view = $_GET['view'] ?? 'list';
$admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null;
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}

// Obtener todos los administradores de club con sus estadísticas usando StatisticsHelper
require_once __DIR__ . '/../../lib/StatisticsHelper.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

$admins_stats = StatisticsHelper::generateStatistics();
$admins = $admins_stats['admins_by_club'] ?? [];

// Enriquecer con información adicional (torneos por organización)
foreach ($admins as &$admin) {
    $stmt = $pdo->prepare("SELECT " . ($has_cod_org ? "COALESCE(NULLIF(cod_org,0), id)" : "id") . " FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 LIMIT 1");
    $stmt->execute([(int)($admin['admin_id'] ?? 0)]);
    $org_id = $stmt->fetchColumn();
    if ($org_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable = ?");
        $stmt->execute([$org_id]);
        $admin['total_torneos'] = (int)$stmt->fetchColumn();
    } else {
        $admin['total_torneos'] = 0;
    }
}

// Si se solicita ver detalle de un admin
$admin_detail = null;
$admin_clubes = [];
$admin_torneos = [];
$admin_usuarios = [];
$admin_stats = [];

if ($view === 'detail' && $admin_id) {
    $stmt = $pdo->prepare("
        SELECT u.*, c.nombre as club_principal_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.id = ? AND u.role = 'admin_club'
    ");
    $stmt->execute([$admin_id]);
    $admin_detail = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $club_ids = ClubHelper::getClubesByAdminClubId($admin_id);
    if (empty($club_ids) && !empty($admin_detail['club_id'])) {
        $club_ids = ClubHelper::getClubesSupervised($admin_detail['club_id']);
    }
    
    $org_id = null;
    $stmt_org = $pdo->prepare("SELECT " . ($has_cod_org ? "COALESCE(NULLIF(cod_org,0), id)" : "id") . " FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 LIMIT 1");
    $stmt_org->execute([$admin_id]);
    $org_id = $stmt_org->fetchColumn();
    
    if ($admin_detail && !empty($club_ids)) {
        $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
        $org_sub = $org_id ? "(SELECT COUNT(*) FROM tournaments WHERE club_responsable = " . (int)$org_id . ")" : "0";
        $org_insc = $org_id ? "(SELECT COUNT(*) FROM inscritos i JOIN tournaments t ON i.torneo_id = t.id WHERE t.club_responsable = " . (int)$org_id . ")" : "0";
        
        // Clubes del admin (todos iguales, sin distinción)
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   $org_sub as torneos_count,
                   $org_insc as inscritos_count,
                   (SELECT COUNT(*) FROM usuarios WHERE entidad = c.id) as total_afiliados,
                   (SELECT COUNT(*) FROM usuarios WHERE entidad = c.id AND sexo = 'M') as hombres,
                   (SELECT COUNT(*) FROM usuarios WHERE entidad = c.id AND sexo = 'F') as mujeres
            FROM clubes c
            WHERE c.id IN ($placeholders)
            ORDER BY c.nombre ASC
        ");
        $stmt->execute($club_ids);
        $admin_clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Torneos del admin (por organización)
        if ($org_id) {
            $stmt = $pdo->prepare("
                SELECT t.*, o.nombre as organizacion_nombre,
                       (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id) as total_inscritos,
                       (SELECT COUNT(*) FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id WHERE i.torneo_id = t.id AND u.sexo = 'M') as hombres_inscritos,
                       (SELECT COUNT(*) FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id WHERE i.torneo_id = t.id AND u.sexo = 'F') as mujeres_inscritos
                FROM tournaments t
                LEFT JOIN organizaciones o ON " . ($has_cod_org
                    ? "(t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
                    : "t.club_responsable = o.id") . "
                WHERE t.club_responsable = ?
                ORDER BY t.fechator DESC
            ");
            $stmt->execute([$org_id]);
            $admin_torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $admin_torneos = [];
        }
        
        // Usuarios creados por este admin (admin_torneo y operador de sus clubes)
        $codigosAsoc = ClubHelper::codigosAsociacionDesdeClubIds($pdo, $club_ids);
        if ($codigosAsoc === []) {
            $codigosAsoc = $club_ids;
        }
        $phCod = implode(',', array_fill(0, count($codigosAsoc), '?'));
        $joinClubEnt = ClubHelper::sqlJoinClubesOnUsuariosEntidad($pdo, 'u', 'c');
        $stmt = $pdo->prepare("
            SELECT u.*, c.nombre as club_nombre
            FROM usuarios u
            LEFT JOIN clubes c ON {$joinClubEnt}
            WHERE u.entidad IN ($phCod) AND u.id != ?
            ORDER BY u.created_at DESC
        ");
        $stmt->execute(array_merge($codigosAsoc, [$admin_id]));
        $admin_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estadísticas
        $admin_stats = [
            'clubes' => count($admin_clubes),
            'torneos_activos' => 0,
            'torneos_pasados' => 0,
            'total_inscritos' => 0,
            'ingresos' => 0
        ];
        
        foreach ($admin_torneos as $t) {
            $admin_stats['total_inscritos'] += $t['total_inscritos'];
            if (strtotime($t['fechator']) >= strtotime('today')) {
                $admin_stats['torneos_activos']++;
            } else {
                $admin_stats['torneos_pasados']++;
            }
        }
        
        // Ingresos (pagos completados)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount), 0) as total
            FROM payments p
            JOIN tournaments t ON p.torneo_id = t.id
            WHERE t.club_responsable IN ($placeholders) AND p.status = 'completed'
        ");
        $stmt->execute($club_ids);
        $admin_stats['ingresos'] = $stmt->fetchColumn() ?: 0;
    } else {
        $admin_stats = [
            'clubes' => 0,
            'torneos_activos' => 0,
            'torneos_pasados' => 0,
            'total_inscritos' => 0,
            'ingresos' => 0
        ];
    }
}
?>

<div class="fade-in">
    <?php if ($view === 'detail' && $admin_detail): ?>
    <!-- VISTA DETALLE DE ADMINISTRADOR -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="?page=home" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="fas fa-arrow-left me-1"></i>Volver al Dashboard
            </a>
            <h2 class="mb-0">
                <i class="fas fa-user-tie me-2"></i><?= htmlspecialchars($admin_detail['nombre']) ?>
            </h2>
            <small class="text-muted">Administrador de Organización</small>
        </div>
        <div>
            <span class="badge bg-<?= $admin_detail['status'] ? 'success' : 'secondary' ?> fs-6 me-2">
                <?= $admin_detail['status'] ? 'Activo' : 'Inactivo' ?>
            </span>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('admin_clubs', ['action' => 'send_notification', 'admin_id' => $admin_id])) ?>" 
               class="btn btn-success btn-sm" 
               title="Enviar notificación por WhatsApp"
>
                <i class="fab fa-whatsapp me-1"></i>Enviar WhatsApp
            </a>
        </div>
    </div>
    
    <!-- Información del Admin -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-id-card me-2"></i>Información Personal
                </div>
                <div class="card-body">
                    <p><strong>Cédula:</strong> <?= htmlspecialchars($admin_detail['cedula'] ?? 'N/A') ?></p>
                    <p><strong>Usuario:</strong> <code><?= htmlspecialchars($admin_detail['username']) ?></code></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($admin_detail['email'] ?? 'N/A') ?></p>
                    <p><strong>Celular:</strong> <?= htmlspecialchars($admin_detail['celular'] ?? 'N/A') ?></p>
                    <p><strong>Registrado:</strong> <?= date('d/m/Y', strtotime($admin_detail['created_at'])) ?></p>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="card text-center bg-primary text-white">
                        <div class="card-body">
                            <h2 class="mb-0"><?= $admin_stats['clubes'] ?></h2>
                            <small>Clubes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-success text-white">
                        <div class="card-body">
                            <h2 class="mb-0"><?= $admin_stats['torneos_activos'] ?></h2>
                            <small>Torneos Activos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-info text-white">
                        <div class="card-body">
                            <h2 class="mb-0"><?= $admin_stats['total_inscritos'] ?></h2>
                            <small>Inscritos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-warning text-dark">
                        <div class="card-body">
                            <h2 class="mb-0">$<?= number_format($admin_stats['ingresos'], 0) ?></h2>
                            <small>Ingresos</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Clubes del Admin -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <i class="fas fa-building me-2"></i>Clubes Gestionados (<?= count($admin_clubes) ?>)
        </div>
        <div class="card-body">
            <?php if (empty($admin_clubes)): ?>
                <p class="text-muted text-center py-3">No tiene clubes asignados</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><i class="fas fa-building me-2"></i>Club</th>
                                <th><i class="fas fa-user me-2"></i>Responsable</th>
                                <th class="text-center"><i class="fas fa-users me-2"></i>Total Afiliados</th>
                                <th class="text-center"><i class="fas fa-mars me-2 text-primary"></i>Hombres</th>
                                <th class="text-center"><i class="fas fa-venus me-2 text-danger"></i>Mujeres</th>
                                <th class="text-center"><i class="fas fa-trophy me-2"></i>Torneos</th>
                                <th class="text-center"><i class="fas fa-cog me-2"></i>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admin_clubes as $club): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($club['nombre']) ?></strong>
                                        <?php if ($club['telefono']): ?>
                                            <br><small class="text-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($club['telefono']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($club['delegado'] ?? 'No asignado') ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary fs-6"><?= number_format($club['total_afiliados'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= number_format($club['hombres'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= number_format($club['mujeres'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= $club['torneos_count'] ?? 0 ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <a href="index.php?page=clubs&action=detail&id=<?= $club['id'] ?>&from=admin_clubs&admin_id=<?= $admin_id ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Ver Todos los Afiliados">
                                                <i class="fas fa-users me-1"></i>Todos
                                            </a>
                                            <a href="index.php?page=clubs&action=detail&id=<?= $club['id'] ?>&genero=M&from=admin_clubs&admin_id=<?= $admin_id ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Ver Hombres">
                                                <i class="fas fa-mars me-1"></i>H
                                            </a>
                                            <a href="index.php?page=clubs&action=detail&id=<?= $club['id'] ?>&genero=F&from=admin_clubs&admin_id=<?= $admin_id ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Ver Mujeres">
                                                <i class="fas fa-venus me-1"></i>M
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Torneos del Admin -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <i class="fas fa-trophy me-2"></i>Torneos Recientes (<?= count($admin_torneos) ?>)
        </div>
        <div class="card-body">
            <?php if (empty($admin_torneos)): ?>
                <p class="text-muted text-center py-3">No ha creado torneos</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><i class="fas fa-calendar me-2"></i>Fecha</th>
                                <th><i class="fas fa-trophy me-2"></i>Nombre</th>
                                <th><i class="fas fa-building me-2"></i>Club</th>
                                <th class="text-center"><i class="fas fa-dollar-sign me-2"></i>Costo</th>
                                <th class="text-center"><i class="fas fa-tag me-2"></i>Tipo</th>
                                <th class="text-center"><i class="fas fa-users me-2"></i>Modalidad</th>
                                <th class="text-center"><i class="fas fa-user-plus me-2"></i>Total Inscritos</th>
                                <th class="text-center"><i class="fas fa-mars me-2 text-primary"></i>Hombres</th>
                                <th class="text-center"><i class="fas fa-venus me-2 text-danger"></i>Mujeres</th>
                                <th class="text-center"><i class="fas fa-cog me-2"></i>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admin_torneos as $torneo): ?>
                                <?php 
                                $es_futuro = strtotime($torneo['fechator']) >= strtotime('today');
                                // Obtener labels para clase y modalidad
                                $clase_labels = [1 => 'Torneo', 2 => 'Campeonato'];
                                $modalidad_labels = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
                                $clase = is_numeric($torneo['clase']) ? (int)$torneo['clase'] : ($torneo['clase'] === 'torneo' ? 1 : 2);
                                $modalidad = is_numeric($torneo['modalidad']) ? (int)$torneo['modalidad'] : ($torneo['modalidad'] === 'individual' ? 1 : ($torneo['modalidad'] === 'parejas' ? 2 : 3));
                                ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-calendar-alt me-1 text-muted"></i>
                                        <?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($torneo['nombre']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= htmlspecialchars($torneo['organizacion_nombre'] ?? 'N/A') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success">$<?= number_format((float)($torneo['costo'] ?? 0), 2) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= $clase_labels[$clase] ?? 'N/A' ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning text-dark"><?= $modalidad_labels[$modalidad] ?? 'N/A' ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary fs-6"><?= number_format($torneo['total_inscritos'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= number_format($torneo['hombres_inscritos'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= number_format($torneo['mujeres_inscritos'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <a href="panel_torneo.php?torneo_id=<?= $torneo['id'] ?>" 
                                           class="btn btn-sm btn-success" title="Administrar Torneo y Ver Resultados">
                                            <i class="fas fa-cog me-1"></i>Administrar
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
    <!-- VISTA LISTA DE ADMINISTRADORES -->
    <div class="fade-in">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-users-cog me-2"></i>Administradores de Organización</h1>
                <p class="text-muted mb-0">Gestión y estadísticas de administradores de club</p>
            </div>
            <span class="badge bg-primary fs-6"><?= count($admins) ?> administradores</span>
        </div>
        
        <?php if (empty($admins)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-user-tie fa-4x text-muted mb-3"></i>
                    <p class="text-muted">No hay administradores de club registrados</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-table me-2"></i>Listado de Administradores de Organización</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="fas fa-user-tie me-2"></i>Nombre Admin. Organización</th>
                                    <th class="text-center"><i class="fas fa-building me-2"></i>Clubes</th>
                                    <th class="text-center"><i class="fas fa-users me-2"></i>Usuarios Registrados</th>
                                    <th class="text-center"><i class="fas fa-mars me-2 text-primary"></i>Hombres</th>
                                    <th class="text-center"><i class="fas fa-venus me-2 text-danger"></i>Mujeres</th>
                                    <th class="text-center"><i class="fas fa-trophy me-2"></i>Torneos</th>
                                    <th class="text-center"><i class="fas fa-cog me-2"></i>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($admin['admin_nombre'] ?? $admin['admin_username'] ?? 'N/A') ?></strong>
                                            <br><small class="text-muted">@<?= htmlspecialchars($admin['admin_username'] ?? 'N/A') ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info fs-6"><?= number_format($admin['supervised_clubs_count'] ?? 0) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary fs-6"><?= number_format($admin['total_users'] ?? 0) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= number_format($admin['users_by_gender']['hombres'] ?? 0) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= number_format($admin['users_by_gender']['mujeres'] ?? 0) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= number_format($admin['total_torneos'] ?? 0) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <a href="?page=admin_clubs&view=detail&admin_id=<?= $admin['admin_id'] ?>" 
                                                   class="btn btn-sm btn-primary" title="Ver detalles">
                                                    <i class="fas fa-eye me-1"></i>Detalles
                                                </a>
                                                <a href="<?= htmlspecialchars(AppHelpers::dashboard('admin_clubs', ['action' => 'send_notification', 'admin_id' => $admin['admin_id']])) ?>" 
                                                   class="btn btn-sm btn-success" 
                                                   title="Enviar notificación por WhatsApp"
>
                                                    <i class="fab fa-whatsapp me-1"></i>WhatsApp
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>


