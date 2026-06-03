<?php
$s = $stats ?? [];

/** Fila superior: activos → hombres activos → mujeres activas */
$atletaFilaSuperior = [
    ['mod' => 'activos', 'label' => 'Activos', 'hint' => 'Atletas con credencial vigente', 'icon' => 'fa-user-check', 'value' => (int) ($s['atletas_activos'] ?? 0)],
    ['mod' => 'h-activos', 'label' => 'Hombres activos', 'hint' => 'Varones con estatus activo', 'icon' => 'fa-mars', 'value' => (int) ($s['hombres_activos'] ?? 0)],
    ['mod' => 'm-activas', 'label' => 'Mujeres activas', 'hint' => 'Damas con estatus activo', 'icon' => 'fa-venus', 'value' => (int) ($s['mujeres_activos'] ?? 0)],
];

$atletaFilaInferior = [
    ['mod' => 'inactivos', 'label' => 'Inactivos', 'hint' => 'Atletas sin credencial vigente', 'icon' => 'fa-user-clock', 'value' => (int) ($s['atletas_inactivos'] ?? 0)],
    ['mod' => 'h-inactivos', 'label' => 'Hombres inactivos', 'hint' => 'Varones sin estatus activo', 'icon' => 'fa-mars', 'value' => (int) ($s['hombres_inactivos'] ?? 0)],
    ['mod' => 'm-inactivas', 'label' => 'Mujeres inactivas', 'hint' => 'Damas sin estatus activo', 'icon' => 'fa-venus', 'value' => (int) ($s['mujeres_inactivos'] ?? 0)],
];
?>
<div class="fvd-kpi-grid fvd-kpi-grid--atletas fvd-kpi-grid--atletas-top">
    <?php foreach ($atletaFilaSuperior as $item) {
        fvd_render_stat_btn($item);
    } ?>
</div>
<div class="fvd-kpi-grid fvd-kpi-grid--atletas fvd-kpi-grid--atletas-bottom">
    <?php foreach ($atletaFilaInferior as $item) {
        fvd_render_stat_btn($item);
    } ?>
</div>
