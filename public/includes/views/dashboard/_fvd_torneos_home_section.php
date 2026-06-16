<?php
/**
 * Gestión de torneos + listado (home dashboard), inmediatamente después de estadísticas.
 */
if (!class_exists('AppHelpers', false)) {
    require_once dirname(__DIR__, 4) . '/lib/app_helpers.php';
}

$fvd_torneos_tabla = $fvd_torneos_tabla ?? [];
$fvd_torneos_dias_ventana = (int) ($fvd_torneos_dias_ventana ?? 15);
$institutionalHome = class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isEnabled();
$urlTorneos = htmlspecialchars(
    $institutionalHome
        ? AppHelpers::dashboard('tournaments', ['action' => 'list'])
        : AppHelpers::dashboard('torneo_gestion', ['action' => 'index']),
    ENT_QUOTES,
    'UTF-8'
);
$urlTorneosNuevo = htmlspecialchars(AppHelpers::dashboard('tournaments', ['action' => 'new']), ENT_QUOTES, 'UTF-8');
$sectionTitle = $institutionalHome ? 'Campeonatos programados' : 'Gestión de torneos';
$ctaLabel = $institutionalHome ? 'Administrar campeonatos' : 'Administración de torneos';
?>
<section class="fvd-dashboard-torneos" aria-label="<?= htmlspecialchars($sectionTitle) ?>">
    <header class="fvd-dashboard-torneos__header">
        <div class="fvd-dashboard-torneos__heading">
            <h2 class="fvd-dashboard-torneos__title">
                <i class="fas fa-trophy" aria-hidden="true"></i> <?= htmlspecialchars($sectionTitle) ?>
            </h2>
            <p class="fvd-dashboard-torneos__hint">
                En curso y programados en los próximos <?= $fvd_torneos_dias_ventana ?> días
                <?php if ($institutionalHome): ?> — crear y ajustar metadatos del evento (sin operación de mesas)<?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
        <a href="<?= $urlTorneos ?>" class="fvd-dash-btn fvd-dash-btn--torneos fvd-dashboard-torneos__cta">
            <i class="fas fa-cogs" aria-hidden="true"></i>
            <span><?= htmlspecialchars($ctaLabel) ?></span>
            <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
        </a>
        <?php if ($institutionalHome): ?>
        <a href="<?= $urlTorneosNuevo ?>" class="fvd-dash-btn fvd-dash-btn--torneos fvd-dashboard-torneos__cta">
            <i class="fas fa-plus" aria-hidden="true"></i>
            <span>Nuevo campeonato</span>
        </a>
        <?php endif; ?>
        </div>
    </header>

    <?php
    $fvd_torneos_titulo = 'Torneos activos y próximos';
    include __DIR__ . '/_fvd_torneos_table.php';
    ?>
</section>
