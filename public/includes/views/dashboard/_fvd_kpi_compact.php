<?php

/**

 * Estadísticas dashboard: dos filas de badges con orden y colores corporativos.

 */

$fvd_show_atletas_cintillo = $fvd_show_atletas_cintillo ?? true;

$s = $stats ?? [];



$kpiAtletas = ['mod' => 'atletas', 'label' => 'Total atletas', 'hint' => 'Personas registradas en la plataforma', 'icon' => 'fa-users', 'value' => (int) ($kpi_atletas ?? 0)];

$kpiAsociaciones = ['mod' => 'asociaciones', 'label' => 'Asociaciones', 'hint' => 'Entidades territoriales afiliadas a la federación', 'icon' => 'fa-building', 'value' => (int) ($kpi_asociaciones ?? 0)];

$kpiTorneos = ['mod' => 'torneos', 'label' => 'Torneos activos', 'hint' => 'Competencias en curso con inscripción abierta o en juego', 'icon' => 'fa-trophy', 'value' => (int) ($kpi_torneos_activos ?? 0)];

$kpiEventos = ['mod' => 'eventos', 'label' => 'Próximos eventos', 'hint' => 'Torneos programados con fecha futura', 'icon' => 'fa-calendar-alt', 'value' => (int) ($kpi_proximos ?? 0)];



$filaSuperior = [

    ['mod' => 'activos', 'label' => 'Activos', 'hint' => 'Atletas con credencial vigente', 'icon' => 'fa-user-check', 'value' => (int) ($s['atletas_activos'] ?? 0)],

    ['mod' => 'h-activos', 'label' => 'Hombres activos', 'hint' => 'Varones con estatus activo', 'icon' => 'fa-mars', 'value' => (int) ($s['hombres_activos'] ?? 0)],

    ['mod' => 'm-activas', 'label' => 'Mujeres activas', 'hint' => 'Damas con estatus activo', 'icon' => 'fa-venus', 'value' => (int) ($s['mujeres_activos'] ?? 0)],

    $kpiAtletas,

    $kpiAsociaciones,

];



$filaInferior = [

    ['mod' => 'inactivos', 'label' => 'Inactivos', 'hint' => 'Atletas sin credencial vigente', 'icon' => 'fa-user-clock', 'value' => (int) ($s['atletas_inactivos'] ?? 0)],

    ['mod' => 'h-inactivos', 'label' => 'Hombres inactivos', 'hint' => 'Varones sin estatus activo', 'icon' => 'fa-mars', 'value' => (int) ($s['hombres_inactivos'] ?? 0)],

    ['mod' => 'm-inactivas', 'label' => 'Mujeres inactivas', 'hint' => 'Damas sin estatus activo', 'icon' => 'fa-venus', 'value' => (int) ($s['mujeres_inactivos'] ?? 0)],

    $kpiTorneos,

    $kpiEventos,

];



if (!$fvd_show_atletas_cintillo) {

    $filaSuperior = [$kpiAtletas, $kpiAsociaciones, $kpiTorneos, $kpiEventos];

    $filaInferior = [];

}



if (!function_exists('fvd_render_stat_btn')) {

    function fvd_render_stat_btn(array $item): void

    {

        $valueFmt = number_format($item['value']);

        $aria = $item['label'] . ': ' . $valueFmt . '. ' . $item['hint'];

        $mod = htmlspecialchars($item['mod'], ENT_QUOTES, 'UTF-8');

        ?>

        <div

            class="fvd-stat-card fvd-stat-card--<?= $mod ?>"

            role="status"

            aria-label="<?= htmlspecialchars($aria, ENT_QUOTES, 'UTF-8') ?>"

            title="<?= htmlspecialchars($item['hint'], ENT_QUOTES, 'UTF-8') ?>"

        >

            <div class="fvd-stat-card__head">

                <span class="fvd-stat-card__icon" aria-hidden="true">

                    <i class="fas <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>

                </span>

                <span class="fvd-stat-card__label"><?= htmlspecialchars($item['label']) ?></span>

            </div>

            <span class="fvd-stat-card__badge"><?= $valueFmt ?></span>

        </div>

        <?php

    }

}

?>

<section class="fvd-kpi-stats fvd-dashboard-stats__kpis" aria-label="Estadísticas generales">

    <div class="fvd-kpi-stats__grid fvd-kpi-stats__grid--row">

        <?php foreach ($filaSuperior as $item) {

            fvd_render_stat_btn($item);

        } ?>

    </div>

    <?php if ($filaInferior !== []): ?>

    <div class="fvd-kpi-stats__grid fvd-kpi-stats__grid--row fvd-kpi-stats__grid--row-bottom">

        <?php foreach ($filaInferior as $item) {

            fvd_render_stat_btn($item);

        } ?>

    </div>

    <?php endif; ?>

</section>

