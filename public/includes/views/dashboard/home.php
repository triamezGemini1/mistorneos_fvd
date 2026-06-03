<?php
/**
 * Vista principal del Dashboard
 * Solo presenta datos; las variables vienen del router (DashboardData::loadAll)
 */
require_once dirname(__DIR__, 4) . '/lib/DashboardData.php';

$stats = $stats ?? [];
$admin_club_stats = $admin_club_stats ?? [];
$torneos_linea_acl = $torneos_linea_acl ?? ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
$torneos_linea_ag = $torneos_linea_ag ?? ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
$athletes_by_club = $athletes_by_club ?? [];

$username = htmlspecialchars($_SESSION['user']['username'] ?? ($current_user['username'] ?? ''));
$role_label = ucfirst(str_replace('_', ' ', $_SESSION['user']['role'] ?? ($user_role ?? '')));
$fvd_torneos_dias_ventana = 15;

if ($user_role === 'admin_general') {
    $tl = $torneos_linea_ag;
    $kpi_atletas = (int)($stats['atletas_activos'] ?? 0) + (int)($stats['atletas_inactivos'] ?? 0);
    $kpi_torneos_activos = count($tl['en_proceso'] ?? []);
    $kpi_asociaciones = (int)($stats['total_entidades'] ?? 0);
    $kpi_proximos = count($tl['por_realizar'] ?? []);
    $fvd_show_atletas_cintillo = true;
    $fvd_torneos_tabla = DashboardData::filtrarTorneosHomeDashboard(
        $tl['en_proceso'] ?? [],
        $tl['por_realizar'] ?? [],
        $fvd_torneos_dias_ventana
    );
} elseif ($user_role === 'admin_club') {
    $acl = $admin_club_stats ?? [];
    $pr_acl = $torneos_linea_acl['por_realizar'] ?? [];
    $ep_acl = $torneos_linea_acl['en_proceso'] ?? [];
    $kpi_atletas = (int)($stats['atletas_activos'] ?? 0) + (int)($stats['atletas_inactivos'] ?? 0);
    $kpi_torneos_activos = count($ep_acl);
    $kpi_asociaciones = (int)($acl['total_clubes'] ?? $stats['clubs'] ?? 0);
    $kpi_proximos = count($pr_acl);
    $fvd_show_atletas_cintillo = true;
    $fvd_torneos_tabla = DashboardData::filtrarTorneosHomeDashboard($ep_acl, $pr_acl, $fvd_torneos_dias_ventana);
} else {
    $tl = $torneos_linea_acl;
    $kpi_atletas = (int)($stats['registrants'] ?? 0);
    $kpi_torneos_activos = count($tl['en_proceso'] ?? []) ?: (int)($stats['active_tournaments'] ?? 0);
    $kpi_asociaciones = (int)($stats['clubs'] ?? 0);
    $kpi_proximos = count($tl['por_realizar'] ?? []) ?: (int)($stats['tournaments'] ?? 0);
    $fvd_show_atletas_cintillo = false;
    $fvd_torneos_tabla = DashboardData::filtrarTorneosHomeDashboard(
        $tl['en_proceso'] ?? [],
        $tl['por_realizar'] ?? [],
        $fvd_torneos_dias_ventana
    );
}
?>
<div class="fvd-dashboard-stats w-full max-w-full p-3 md:p-4 fade-in">
    <?php include __DIR__ . '/_fvd_dashboard_header.php'; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show text-sm py-2 mb-2" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show text-sm py-2 mb-2" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['debug']) && $_GET['debug'] === '1'): ?>
    <div class="alert alert-info text-sm mb-2">
        <pre class="text-xs mb-0"><?php print_r($stats); ?></pre>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/_fvd_kpi_compact.php'; ?>

    <?php include __DIR__ . '/_fvd_torneos_home_section.php'; ?>
</div>
