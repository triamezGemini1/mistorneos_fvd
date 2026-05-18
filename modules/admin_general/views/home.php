<?php
/**
 * Vista: Dashboard Home para Admin General
 */
require_once __DIR__ . '/../../../lib/app_helpers.php';
$stats = $stats ?? [];
$kpi_atletas = (int)($stats['atletas_activos'] ?? 0) + (int)($stats['atletas_inactivos'] ?? 0);
$kpi_asociaciones = (int)($stats['total_entidades'] ?? 0);
$kpi_torneos_activos = (int)($stats['torneos_activos'] ?? 0);
$kpi_proximos = (int)($stats['torneos_por_realizar'] ?? 0);
$fvd_show_atletas_cintillo = true;
$views_dashboard = dirname(__DIR__, 3) . '/public/includes/views/dashboard';
?>
<div class="w-full max-w-full p-3 md:p-4 fade-in">
    <header class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div class="min-w-0">
            <h1 class="text-lg md:text-xl font-bold text-blue-900 tracking-tight leading-tight">
                <i class="fas fa-chart-line me-2 text-amber-500"></i>Dashboard FVD
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                <strong class="text-slate-700"><?= htmlspecialchars($current_user['username'] ?? '') ?></strong>
                · Estadísticas nacionales
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0 text-xs">
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('importacion_torneo_externo')) ?>" class="inline-flex items-center rounded border border-slate-300 bg-white px-2.5 py-1 text-slate-700 hover:border-amber-400 transition-colors">
                <i class="fas fa-file-import me-1"></i>Import.
            </a>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas')) ?>" class="inline-flex items-center rounded bg-amber-400 px-2.5 py-1 font-semibold text-blue-900 hover:bg-amber-300 transition-colors">
                <i class="fas fa-bell me-1"></i>Notif.
            </a>
            <span class="font-mono font-semibold text-blue-900"><?= date('d/m/Y') ?></span>
            <span class="rounded bg-blue-900 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-400">Admin General</span>
        </div>
    </header>

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

    <?php include __DIR__ . '/_panel_operativo.php'; ?>

    <?php include $views_dashboard . '/_fvd_support_credit.php'; ?>
</div>
