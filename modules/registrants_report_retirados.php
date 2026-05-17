<?php
/**
 * Reporte de Jugadores Retirados
 * Similar al reporte de inscritos, pero solo muestra jugadores con estatus retirado (4)
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

$user = Auth::user();
if (!$user) {
    header('Location: ' . app_base_url() . '/public/login.php');
    exit;
}
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$user_role = $user['role'] ?? '';
$user_club_id = $user['club_id'] ?? null;
$is_admin_torneo = ($user_role === 'admin_torneo');
$is_admin_club = ($user_role === 'admin_club');

$filter_torneo = $_GET['filter_torneo'] ?? '';
$filter_clubs = $_GET['filter_clubs'] ?? [];
if (is_string($filter_clubs)) {
    $filter_clubs = [$filter_clubs];
}
$filter_sexo = $_GET['filter_sexo'] ?? '';
$search = $_GET['search'] ?? '';

$tournaments_filter = [];
$clubs_filter = [];

try {
    require_once __DIR__ . '/../lib/ClubHelper.php';

    if ($user_role === 'admin_general') {
        $stmt = DB::pdo()->query("SELECT id, nombre, fechator FROM tournaments ORDER BY fechator DESC");
        $tournaments_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($is_admin_torneo && $user_club_id) {
        $stmt = DB::pdo()->prepare("SELECT id, nombre, fechator FROM tournaments WHERE club_responsable = ? ORDER BY fechator DESC");
        $stmt->execute([$user_club_id]);
        $tournaments_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = DB::pdo()->query("SELECT id, nombre, fechator FROM tournaments ORDER BY fechator DESC");
        $tournaments_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($user_role === 'admin_general') {
        $stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
        $clubs_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($is_admin_torneo || $is_admin_club) {
        $clubs_filter = ClubHelper::getClubesSupervisedWithData($user_club_id);
    }
} catch (Exception $e) {
    error_log("Error al cargar filtros: " . $e->getMessage());
}

$torneo_info = null;
$club_info = null;
if (!empty($filter_torneo)) {
    $stmt = DB::pdo()->prepare("SELECT nombre, fechator FROM tournaments WHERE id = ?");
    $stmt->execute([(int)$filter_torneo]);
    $torneo_info = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!empty($filter_clubs) && count($filter_clubs) === 1) {
    $stmt = DB::pdo()->prepare("SELECT nombre FROM clubes WHERE id = ?");
    $stmt->execute([(int)$filter_clubs[0]]);
    $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

$torneo_stats = null;
$resumen_por_club = [];
$retirados_list = [];

if (!empty($filter_torneo)) {
    try {
        $where_clause = "r.torneo_id = ? AND (r.estatus = 4 OR r.estatus = 'retirado')";
        $params = [(int)$filter_torneo];

        if (!empty($filter_clubs) && is_array($filter_clubs)) {
            $placeholders = str_repeat('?,', count($filter_clubs) - 1) . '?';
            $where_clause .= " AND r.id_club IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $filter_clubs));
        }
        if ($filter_sexo && in_array($filter_sexo, ['M', 'F'])) {
            $where_clause .= " AND (u.sexo = ? OR u.sexo = ?)";
            $params[] = $filter_sexo;
            $params[] = ($filter_sexo === 'M' ? 1 : 2);
        }
        if ($search) {
            $where_clause .= " AND (u.nombre LIKE ? OR u.cedula LIKE ? OR u.username LIKE ?)";
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $stmt = DB::pdo()->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN u.sexo = 1 OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 2 OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            WHERE {$where_clause}
        ");
        $stmt->execute($params);
        $torneo_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($torneo_stats) {
            $torneo_stats['total'] = (int)($torneo_stats['total'] ?? 0);
            $torneo_stats['hombres'] = (int)($torneo_stats['hombres'] ?? 0);
            $torneo_stats['mujeres'] = (int)($torneo_stats['mujeres'] ?? 0);
        }

        $stmt = DB::pdo()->prepare("
            SELECT 
                c.id,
                c.nombre as club_nombre,
                COUNT(r.id) as total_inscritos,
                SUM(CASE WHEN u.sexo = 1 OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 2 OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres
            FROM clubes c
            INNER JOIN inscritos r ON c.id = r.id_club AND r.torneo_id = ? AND (r.estatus = 4 OR r.estatus = 'retirado')
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            WHERE c.estatus = 1
            GROUP BY c.id, c.nombre
            HAVING total_inscritos > 0
            ORDER BY total_inscritos DESC, c.nombre ASC
        ");
        $stmt->execute([(int)$filter_torneo]);
        $resumen_por_club = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = DB::pdo()->prepare("
            SELECT r.id, r.id_usuario, r.id_club, u.nombre, u.username, u.cedula, u.sexo, c.nombre as club_nombre
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            LEFT JOIN clubes c ON r.id_club = c.id
            WHERE {$where_clause}
            ORDER BY COALESCE(c.nombre, 'zzz') ASC, u.nombre ASC
        ");
        $stmt->execute($params);
        $retirados_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error al cargar retirados: " . $e->getMessage());
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-user-minus me-2 text-warning"></i>Reporte de Jugadores Retirados
                    </h1>
                    <p class="text-muted mb-0">Lista de jugadores que se retiraron de un torneo</p>
                </div>
                <div>
                    <a href="index.php?page=registrants" class="btn btn-secondary me-2">
                        <i class="fas fa-arrow-left me-2"></i>Volver a Inscritos
                    </a>
                    <a href="index.php?page=registrants_report<?= !empty($filter_torneo) ? '&filter_torneo=' . (int)$filter_torneo : '' ?>" class="btn btn-primary">
                        <i class="fas fa-users me-2"></i>Reporte de Inscritos
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="card-title mb-0">
            <i class="fas fa-filter me-2"></i>Filtros y Reportes
        </h5>
    </div>
    <div class="card-body">
        <form method="GET" action="index.php" id="filterForm">
            <input type="hidden" name="page" value="registrants_report_retirados">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-trophy me-1"></i>Torneo</label>
                    <select name="filter_torneo" class="form-select" id="filterTorneo" onchange="this.form.submit()">
                        <option value="">-- Seleccione un Torneo --</option>
                        <?php foreach ($tournaments_filter as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $filter_torneo == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['nombre']) ?> - <?= date('d/m/Y', strtotime($t['fechator'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-building me-1"></i>Club(es)</label>
                    <select name="filter_clubs[]" class="form-select" id="filterClubs" multiple size="4">
                        <?php foreach ($clubs_filter as $club): ?>
                            <option value="<?= $club['id'] ?>" <?= in_array($club['id'], $filter_clubs) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($club['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Ctrl (Cmd en Mac) para seleccionar múltiples clubes</small>
                </div>

                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-venus-mars me-1"></i>Sexo</label>
                    <select name="filter_sexo" class="form-select">
                        <option value="">-- Todos --</option>
                        <option value="M" <?= $filter_sexo === 'M' ? 'selected' : '' ?>>Masculino</option>
                        <option value="F" <?= $filter_sexo === 'F' ? 'selected' : '' ?>>Femenino</option>
                    </select>
                </div>

                <div class="col-md-9">
                    <label class="form-label"><i class="fas fa-search me-1"></i>Buscar</label>
                    <input type="text" name="search" class="form-control app-search-blur-input" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por nombre, cédula o username (mín. 3; al salir del campo)…" autocomplete="off" minlength="3">
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-warning text-dark">
                            <i class="fas fa-filter me-2"></i>Aplicar Filtros
                        </button>
                        <a href="index.php?page=registrants_report_retirados" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Limpiar Filtros
                        </a>
                        <div class="vr"></div>
                        <button type="button" class="btn btn-success" onclick="exportarExcel()" id="btnExportarExcel" <?= empty($filter_torneo) ? 'disabled' : '' ?>>
                            <i class="fas fa-file-excel me-2"></i>Exportar Excel
                        </button>
                        <button type="button" class="btn btn-danger" onclick="exportarPDF()" id="btnExportarPDF" <?= empty($filter_torneo) ? 'disabled' : '' ?>>
                            <i class="fas fa-file-pdf me-2"></i>Exportar PDF
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($torneo_info || $club_info): ?>
<div class="card mb-4">
    <div class="card-body text-center">
        <?php if ($torneo_info): ?>
            <h2 class="mb-2"><?= htmlspecialchars($torneo_info['nombre']) ?></h2>
            <?php if ($torneo_info['fechator']): ?>
                <p class="text-muted mb-1">Fecha: <?= date('d/m/Y', strtotime($torneo_info['fechator'])) ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($club_info): ?>
            <h4 class="text-muted mt-2 mb-0"><?= htmlspecialchars($club_info['nombre']) ?></h4>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($filter_torneo) && $torneo_stats): ?>
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h3 class="text-warning mb-0"><?= number_format($torneo_stats['total']) ?></h3>
                <p class="text-muted mb-0">Total Retirados</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body text-center">
                <h3 class="text-info mb-0"><?= number_format($torneo_stats['hombres']) ?></h3>
                <p class="text-muted mb-0">Hombres</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h3 class="text-danger mb-0"><?= number_format($torneo_stats['mujeres']) ?></h3>
                <p class="text-muted mb-0">Mujeres</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($filter_torneo) && !empty($resumen_por_club)): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="card-title mb-0">
            <i class="fas fa-chart-bar me-2"></i>Resumen por Club
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Club</th>
                        <th class="text-center">Total Retirados</th>
                        <th class="text-center">Hombres</th>
                        <th class="text-center">Mujeres</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resumen_por_club as $club): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($club['club_nombre']) ?></strong></td>
                            <td class="text-center"><span class="badge bg-warning text-dark"><?= number_format($club['total_inscritos']) ?></span></td>
                            <td class="text-center"><span class="badge bg-info"><?= number_format($club['hombres']) ?></span></td>
                            <td class="text-center"><span class="badge bg-danger"><?= number_format($club['mujeres']) ?></span></td>
                            <td class="text-center">
                                <button onclick="exportarClubPDF(<?= $club['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Exportar PDF">
                                    <i class="fas fa-file-pdf me-1"></i>PDF
                                </button>
                                <button onclick="exportarClubExcel(<?= $club['id'] ?>)" class="btn btn-sm btn-outline-success" title="Exportar Excel">
                                    <i class="fas fa-file-excel me-1"></i>Excel
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($filter_torneo)): ?>
<div class="card">
    <div class="card-header bg-secondary text-white">
        <h5 class="card-title mb-0">
            <i class="fas fa-list me-2"></i>Listado de Jugadores Retirados
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($retirados_list)): ?>
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>No hay jugadores retirados con los filtros aplicados.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Username</th>
                            <th>Cédula</th>
                            <th>Club</th>
                            <th class="text-center">Sexo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($retirados_list as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['nombre'] ?? 'N/A') ?></strong></td>
                                <td><code><?= htmlspecialchars($r['username'] ?? '') ?></code></td>
                                <td><?= htmlspecialchars($r['cedula'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['club_nombre'] ?? 'Sin club') ?></td>
                                <td class="text-center">
                                    <?php
                                    $sexo = $r['sexo'] ?? '';
                                    echo ($sexo === 'M' || $sexo == 1) ? '<span class="badge bg-info">M</span>' : (($sexo === 'F' || $sexo == 2) ? '<span class="badge bg-danger">F</span>' : '<span class="badge bg-secondary">-</span>');
                                    ?>
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
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>Seleccione un torneo para ver el reporte de jugadores retirados.
</div>
<?php endif; ?>

<script>
function exportarPDF() {
    const params = new URLSearchParams();
    params.append('tipo', 'retirados');
    <?php if (!empty($filter_torneo)): ?>params.append('torneo_id', '<?= (int)$filter_torneo ?>');<?php endif; ?>
    <?php if (!empty($filter_clubs)): ?>
    <?php foreach ($filter_clubs as $cid): ?>params.append('club_id[]', '<?= (int)$cid ?>');<?php endforeach; ?>
    <?php endif; ?>
    <?php if ($filter_sexo): ?>params.append('sexo', '<?= htmlspecialchars($filter_sexo) ?>');<?php endif; ?>
    <?php if ($search): ?>params.append('q', '<?= htmlspecialchars($search) ?>');<?php endif; ?>
    window.location.href = 'modules/registrants/report_pdf_retirados.php?' + params.toString();
}
function exportarExcel() {
    const params = new URLSearchParams();
    params.append('tipo', 'retirados');
    <?php if (!empty($filter_torneo)): ?>params.append('torneo_id', '<?= (int)$filter_torneo ?>');<?php endif; ?>
    <?php if (!empty($filter_clubs)): ?>
    <?php foreach ($filter_clubs as $cid): ?>params.append('club_id[]', '<?= (int)$cid ?>');<?php endforeach; ?>
    <?php endif; ?>
    <?php if ($filter_sexo): ?>params.append('sexo', '<?= htmlspecialchars($filter_sexo) ?>');<?php endif; ?>
    <?php if ($search): ?>params.append('q', '<?= htmlspecialchars($search) ?>');<?php endif; ?>
    window.location.href = 'modules/registrants/export_excel_retirados.php?' + params.toString();
}
function exportarClubPDF(club_id) {
    const params = new URLSearchParams();
    params.append('tipo', 'retirados');
    params.append('torneo_id', '<?= (int)$filter_torneo ?>');
    params.append('club_id', club_id);
    window.location.href = 'modules/registrants/report_pdf_retirados.php?' + params.toString();
}
function exportarClubExcel(club_id) {
    const params = new URLSearchParams();
    params.append('tipo', 'retirados');
    params.append('torneo_id', '<?= (int)$filter_torneo ?>');
    params.append('club_id[]', club_id);
    window.location.href = 'modules/registrants/export_excel_retirados.php?' + params.toString();
}
</script>
