<?php
/**
 * Gestión de torneos + listado (home dashboard), inmediatamente después de estadísticas.
 */
if (!class_exists('AppHelpers', false)) {
    require_once dirname(__DIR__, 4) . '/lib/app_helpers.php';
}

$fvd_torneos_tabla = $fvd_torneos_tabla ?? [];
$fvd_torneos_dias_ventana = (int) ($fvd_torneos_dias_ventana ?? 15);
$urlTorneos = htmlspecialchars(AppHelpers::dashboard('torneo_gestion', ['action' => 'index']), ENT_QUOTES, 'UTF-8');
?>
<section class="fvd-dashboard-torneos" aria-label="Gestión de torneos">
    <header class="fvd-dashboard-torneos__header">
        <div class="fvd-dashboard-torneos__heading">
            <h2 class="fvd-dashboard-torneos__title">
                <i class="fas fa-trophy" aria-hidden="true"></i> Gestión de torneos
            </h2>
            <p class="fvd-dashboard-torneos__hint">
                En curso y programados en los próximos <?= $fvd_torneos_dias_ventana ?> días
            </p>
        </div>
        <a href="<?= $urlTorneos ?>" class="fvd-dash-btn fvd-dash-btn--torneos fvd-dashboard-torneos__cta">
            <i class="fas fa-cogs" aria-hidden="true"></i>
            <span>Administración de torneos</span>
            <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
        </a>
    </header>

    <?php
    $fvd_torneos_titulo = 'Torneos activos y próximos';
    include __DIR__ . '/_fvd_torneos_table.php';
    ?>
</section>
