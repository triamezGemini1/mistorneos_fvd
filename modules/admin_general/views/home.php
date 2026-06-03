<?php

/**

 * Vista: Dashboard Home para Admin General — estadísticas + gestión de torneos.

 */

require_once __DIR__ . '/../../../lib/app_helpers.php';

require_once __DIR__ . '/../../../lib/DashboardData.php';



$stats = $stats ?? [];

$user_role = 'admin_general';

$kpi_atletas = (int)($stats['atletas_activos'] ?? 0) + (int)($stats['atletas_inactivos'] ?? 0);

$kpi_asociaciones = (int)($stats['total_entidades'] ?? 0);

$kpi_torneos_activos = (int)($stats['torneos_activos'] ?? 0);

$kpi_proximos = (int)($stats['torneos_por_realizar'] ?? 0);

$fvd_show_atletas_cintillo = true;

$fvd_torneos_dias_ventana = 15;

$dashboard_chip_class = 'fvd-dash-btn--lavender';

$role_label = 'Admin General';

$views_dashboard = dirname(__DIR__, 3) . '/public/includes/views/dashboard';



$torneosLinea = DashboardData::torneosLineaParaHome('admin_general', $current_user ?? []);

if ($kpi_torneos_activos <= 0) {

    $kpi_torneos_activos = count($torneosLinea['en_proceso'] ?? []);

}

if ($kpi_proximos <= 0) {

    $kpi_proximos = count($torneosLinea['por_realizar'] ?? []);

}

$fvd_torneos_tabla = DashboardData::filtrarTorneosHomeDashboard(

    $torneosLinea['en_proceso'] ?? [],

    $torneosLinea['por_realizar'] ?? [],

    $fvd_torneos_dias_ventana

);

?>

<div class="fvd-dashboard-stats w-full max-w-full p-3 md:p-4 fade-in">

    <?php include $views_dashboard . '/_fvd_dashboard_header.php'; ?>



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



    <?php include $views_dashboard . '/_fvd_kpi_compact.php'; ?>



    <?php include $views_dashboard . '/_fvd_torneos_home_section.php'; ?>

</div>

