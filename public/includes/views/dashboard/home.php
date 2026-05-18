<?php
/**
 * Vista principal del Dashboard
 * Solo presenta datos; las variables vienen del router (DashboardData::loadAll)
 */
$stats = $stats ?? [];
$admin_club_stats = $admin_club_stats ?? [];
$torneos_linea_acl = $torneos_linea_acl ?? ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
$torneos_linea_ag = $torneos_linea_ag ?? ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
$athletes_by_club = $athletes_by_club ?? [];

$username = htmlspecialchars($_SESSION['user']['username'] ?? ($current_user['username'] ?? ''));
$role_label = ucfirst(str_replace('_', ' ', $_SESSION['user']['role'] ?? ($user_role ?? '')));

if ($user_role === 'admin_general') {
    $tl = $torneos_linea_ag;
    $kpi_atletas = (int)($stats['atletas_activos'] ?? 0) + (int)($stats['atletas_inactivos'] ?? 0);
    $kpi_torneos_activos = count($tl['en_proceso'] ?? []);
    $kpi_asociaciones = (int)($stats['total_entidades'] ?? 0);
    $kpi_proximos = count($tl['por_realizar'] ?? []);
    $fvd_torneos_tabla = array_merge($tl['en_proceso'] ?? [], $tl['por_realizar'] ?? []);
    $fvd_show_atletas_cintillo = true;
} elseif ($user_role === 'admin_club') {
    $acl = $admin_club_stats ?? [];
    $pr_acl = $torneos_linea_acl['por_realizar'] ?? [];
    $ep_acl = $torneos_linea_acl['en_proceso'] ?? [];
    $kpi_atletas = (int)($stats['atletas_activos'] ?? 0) + (int)($stats['atletas_inactivos'] ?? 0);
    $kpi_torneos_activos = count($ep_acl);
    $kpi_asociaciones = (int)($acl['total_clubes'] ?? $stats['clubs'] ?? 0);
    $kpi_proximos = count($pr_acl);
    $fvd_torneos_tabla = array_merge($ep_acl, $pr_acl);
    $fvd_show_atletas_cintillo = true;
} else {
    $kpi_atletas = (int)($stats['registrants'] ?? 0);
    $kpi_torneos_activos = (int)($stats['active_tournaments'] ?? 0);
    $kpi_asociaciones = (int)($stats['clubs'] ?? 0);
    $kpi_proximos = (int)($stats['tournaments'] ?? 0);
    $fvd_torneos_tabla = [];
    $fvd_show_atletas_cintillo = false;
}
?>
<div class="w-full max-w-full p-3 md:p-4 fade-in">
    <header class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div class="min-w-0">
            <h1 class="text-lg md:text-xl font-bold text-blue-900 tracking-tight leading-tight">
                <i class="fas fa-chart-line me-2 text-amber-500"></i>Dashboard
            </h1>
            <p class="text-xs text-slate-500 mt-0.5 truncate">
                <strong class="text-slate-700"><?= $username ?></strong>
                <?php if ($user_role === 'admin_general'): ?>
                    · Estadísticas generales
                <?php elseif ($user_role === 'admin_club'): ?>
                    · <?= htmlspecialchars($entidad_nombre_actual ?? 'No definida') ?>
                <?php else: ?>
                    · <?= htmlspecialchars($entidad_nombre_actual ?? 'Ámbito de torneo') ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0 text-xs">
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas')) ?>" class="inline-flex items-center rounded bg-amber-400 px-2.5 py-1 font-semibold text-blue-900 hover:bg-amber-300 transition-colors">
                <i class="fas fa-bell me-1"></i>Notif.
            </a>
            <span class="font-mono font-semibold text-blue-900"><?= date('d/m/Y') ?></span>
            <span class="rounded bg-blue-900 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-400"><?= htmlspecialchars($role_label) ?></span>
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

    <?php if (!empty($_GET['debug']) && $_GET['debug'] === '1'): ?>
    <div class="alert alert-info text-sm mb-2">
        <pre class="text-xs mb-0"><?php print_r($stats); ?></pre>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/_fvd_kpi_compact.php'; ?>

    <?php if (in_array($user_role, ['admin_general', 'admin_club'], true)): ?>
        <?php
        $fvd_torneos_titulo = ($user_role === 'admin_general')
            ? 'Torneos nacionales en curso y próximos'
            : 'Torneos de la asociación en curso y próximos';
        include __DIR__ . '/_fvd_torneos_table.php';
        ?>
    <?php endif; ?>

    <?php include __DIR__ . '/_fvd_support_credit.php'; ?>
</div>
