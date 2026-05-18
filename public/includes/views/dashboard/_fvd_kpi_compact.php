<?php
/**
 * Estadísticas dashboard: dos columnas (KPIs 2×2 | desglose atletas 3×2).
 * Layout en fvd-identidad.css (no depende de Tailwind).
 */
$fvd_show_atletas_cintillo = $fvd_show_atletas_cintillo ?? true;

$kpiGeneralItems = [
    ['mod' => 'atletas', 'label' => 'Total atletas', 'hint' => 'Personas registradas en la plataforma FVD', 'icon' => 'fa-users', 'value' => (int) ($kpi_atletas ?? 0)],
    ['mod' => 'torneos', 'label' => 'Torneos activos', 'hint' => 'Competencias en curso con inscripción abierta o en juego', 'icon' => 'fa-trophy', 'value' => (int) ($kpi_torneos_activos ?? 0)],
    ['mod' => 'asociaciones', 'label' => 'Asociaciones', 'hint' => 'Entidades territoriales afiliadas a la federación', 'icon' => 'fa-building', 'value' => (int) ($kpi_asociaciones ?? 0)],
    ['mod' => 'eventos', 'label' => 'Próximos eventos', 'hint' => 'Torneos programados con fecha futura', 'icon' => 'fa-calendar-alt', 'value' => (int) ($kpi_proximos ?? 0)],
];

$renderKpiCell = static function (array $item): void {
    $valueFmt = number_format($item['value']);
    $aria = $item['label'] . ': ' . $valueFmt . '. ' . $item['hint'];
    $mod = htmlspecialchars($item['mod'], ENT_QUOTES, 'UTF-8');
    ?>
    <div
        class="fvd-kpi-cell fvd-kpi-cell--<?= $mod ?>"
        role="group"
        aria-label="<?= htmlspecialchars($aria, ENT_QUOTES, 'UTF-8') ?>"
        title="<?= htmlspecialchars($item['hint'], ENT_QUOTES, 'UTF-8') ?>"
    >
        <div class="fvd-kpi-cell__head">
            <span class="fvd-kpi-cell__icon-wrap" aria-hidden="true">
                <i class="fas <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?> fvd-kpi-cell__icon"></i>
            </span>
            <span class="fvd-kpi-cell__label"><?= htmlspecialchars($item['label']) ?></span>
        </div>
        <p class="fvd-kpi-cell__value"><?= $valueFmt ?></p>
    </div>
    <?php
};
?>
<section class="fvd-kpi-operativo" aria-label="Resumen operativo FVD">
    <div class="fvd-kpi-operativo__columns<?= $fvd_show_atletas_cintillo ? '' : ' fvd-kpi-operativo__columns--single' ?>">
        <div class="fvd-kpi-panel fvd-kpi-panel--general" aria-label="Indicadores generales">
            <p class="fvd-kpi-panel__title">Indicadores generales</p>
            <div class="fvd-kpi-grid fvd-kpi-grid--general">
                <?php foreach ($kpiGeneralItems as $item) {
                    $renderKpiCell($item);
                } ?>
            </div>
        </div>

        <?php if ($fvd_show_atletas_cintillo): ?>
        <div class="fvd-kpi-panel fvd-kpi-panel--atletas" aria-label="Cartera de atletas">
            <p class="fvd-kpi-panel__title">Cartera de atletas</p>
            <?php include __DIR__ . '/_atletas_stat_cards.php'; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
