<?php
/**
 * Cabecera del dashboard home: logo institucional + título estadísticas.
 */
if (!class_exists('AppHelpers', false)) {
    require_once dirname(__DIR__, 4) . '/lib/app_helpers.php';
}
if (!class_exists('FvdBranding', false) && is_file(dirname(__DIR__, 4) . '/lib/FvdBranding.php')) {
    require_once dirname(__DIR__, 4) . '/lib/FvdBranding.php';
}

$username = $username ?? htmlspecialchars($_SESSION['user']['username'] ?? ($current_user['username'] ?? ''));
$role_label = $role_label ?? ucfirst(str_replace('_', ' ', $_SESSION['user']['role'] ?? ($user_role ?? '')));
$dashboard_chip_class = $dashboard_chip_class ?? 'fvd-dash-btn--peach';
$dashboard_subtitle = $dashboard_subtitle ?? null;

// Logo estático bajo public/ (compatible con <base href> del layout)
$logoUrl = AppHelpers::getAppLogoHref(rtrim(AppHelpers::getPublicBaseHref(), '/'));
$logoAlt = class_exists('FvdBranding', false) ? FvdBranding::siglas() : 'Logo';

if ($dashboard_subtitle === null) {
    if (($user_role ?? '') === 'admin_general') {
        $dashboard_subtitle = ! empty($torneos_solo) ? 'Administración de torneos' : 'Resumen nacional';
    } elseif (($user_role ?? '') === 'admin_club') {
        $dashboard_subtitle = htmlspecialchars($entidad_nombre_actual ?? 'Asociación');
    } else {
        $dashboard_subtitle = htmlspecialchars($entidad_nombre_actual ?? 'Ámbito de torneo');
    }
}
?>
<header class="fvd-dashboard-stats__header">
    <div class="fvd-dashboard-stats__brand min-w-0">
        <img
            src="<?= htmlspecialchars($logoUrl) ?>"
            alt="<?= htmlspecialchars($logoAlt) ?>"
            class="fvd-dashboard-stats__logo"
            height="56"
            width="auto"
            loading="eager"
            fetchpriority="high"
        >
        <div class="fvd-dashboard-stats__intro min-w-0">
            <h1 class="fvd-dashboard-stats__title">
                <i class="fas fa-chart-line" aria-hidden="true"></i> Panel de control
            </h1>
            <p class="fvd-dashboard-stats__subtitle">
                <strong><?= $username ?></strong>
                · <?= $dashboard_subtitle ?>
            </p>
        </div>
    </div>
    <div class="fvd-dashboard-stats__actions">
        <span class="fvd-dashboard-stats__date"><?= date('d/m/Y') ?></span>
        <?php if (($user_role ?? '') === 'admin_general' && empty($torneos_solo)): ?>
        <a href="<?= htmlspecialchars(AppHelpers::dashboard('estadisticas_web')) ?>" class="fvd-dash-btn fvd-dash-btn--lavender fvd-dash-btn--chip text-decoration-none" title="Visitas públicas y del panel (Umami)">
            <i class="fas fa-globe-americas me-1" aria-hidden="true"></i>Estadísticas web
        </a>
        <?php endif; ?>
        <span class="fvd-dash-btn <?= htmlspecialchars($dashboard_chip_class) ?> fvd-dash-btn--chip"><?= htmlspecialchars($role_label) ?></span>
    </div>
</header>
