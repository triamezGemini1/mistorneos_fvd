<?php
/**
 * Acceso principal al panel de administración de torneos (dashboard estadísticas).
 */
$urlTorneos = htmlspecialchars(AppHelpers::dashboard('torneo_gestion', ['action' => 'index']), ENT_QUOTES, 'UTF-8');
?>
<section class="fvd-dashboard-acceso" aria-label="Administración de torneos">
    <a href="<?= $urlTorneos ?>" class="fvd-dash-btn fvd-dash-btn--torneos fvd-dash-btn--hero">
        <span class="fvd-dash-btn__icon" aria-hidden="true"><i class="fas fa-trophy"></i></span>
        <span class="fvd-dash-btn__text">
            <span class="fvd-dash-btn__title">Administración de torneos</span>
            <span class="fvd-dash-btn__hint">Inscripciones, mesas, resultados, reportes y panel de control</span>
        </span>
        <span class="fvd-dash-btn__arrow" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
    </a>
</section>
