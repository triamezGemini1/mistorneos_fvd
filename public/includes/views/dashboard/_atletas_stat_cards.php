<?php
$s = $stats ?? [];

$atletaItems = [
    ['mod' => 'activos', 'label' => 'Activos', 'hint' => 'Atletas con credencial vigente', 'icon' => 'fa-user-check', 'value' => (int) ($s['atletas_activos'] ?? 0)],
    ['mod' => 'inactivos', 'label' => 'Inactivos', 'hint' => 'Atletas sin credencial vigente', 'icon' => 'fa-user-clock', 'value' => (int) ($s['atletas_inactivos'] ?? 0)],
    ['mod' => 'h-activos', 'label' => 'Hombres activos', 'hint' => 'Varones con estatus activo', 'icon' => 'fa-mars', 'value' => (int) ($s['hombres_activos'] ?? 0)],
    ['mod' => 'm-activas', 'label' => 'Mujeres activas', 'hint' => 'Damas con estatus activo', 'icon' => 'fa-venus', 'value' => (int) ($s['mujeres_activos'] ?? 0)],
    ['mod' => 'h-inactivos', 'label' => 'Hombres inactivos', 'hint' => 'Varones sin estatus activo', 'icon' => 'fa-mars', 'value' => (int) ($s['hombres_inactivos'] ?? 0)],
    ['mod' => 'm-inactivas', 'label' => 'Mujeres inactivas', 'hint' => 'Damas sin estatus activo', 'icon' => 'fa-venus', 'value' => (int) ($s['mujeres_inactivos'] ?? 0)],
];
?>
<div class="fvd-kpi-grid fvd-kpi-grid--atletas">
    <?php foreach ($atletaItems as $item):
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
    <?php endforeach; ?>
</div>
