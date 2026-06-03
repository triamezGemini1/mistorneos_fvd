<?php
/** @var array<string, mixed> $jugador */
require_once __DIR__ . '/../../lib/ResumenJugadorNavigation.php';

$clases = ['rem-jugador'];
if (!empty($jugador['marca_pareja'])) {
    $clases[] = 'rem--pareja';
}
if (!empty($jugador['tiene_enfrenta_naranja'])) {
    $clases[] = 'rem--enfrenta';
}
if ((int) ($jugador['secuencia'] ?? 0) === 1) {
    $clases[] = 'rem-jugador--cabeza';
}
$torneoIdRem = (int) ($torneo_id ?? ($jugador['torneo_id'] ?? 0));
$idUsuarioRem = (int) ($jugador['id_usuario'] ?? 0);
$nombreCorto = (string) ($jugador['nombre_corto'] ?? $jugador['nombre'] ?? '');
?>
<div class="<?php echo htmlspecialchars(implode(' ', $clases), ENT_QUOTES, 'UTF-8'); ?>">
    <span class="rem-orden" title="Orden en clasificación al generar la ronda"><?php echo htmlspecialchars((string) ($jugador['orden_clasificacion_txt'] ?? '—')); ?></span>
    <span class="rem-nf"><?php echo htmlspecialchars((string) ($jugador['numfvd_txt'] ?? '—')); ?></span>
    <span class="rem-nombre">
        <?php
        if ($torneoIdRem > 0 && $idUsuarioRem > 0) {
            echo ResumenJugadorNavigation::enlaceNombre(
                $nombreCorto,
                $torneoIdRem,
                $idUsuarioRem,
                'reporte_estructura_mesas',
                'rem-nombre-link text-inherit hover:underline'
            );
        } else {
            echo htmlspecialchars($nombreCorto);
        }
        ?>
    </span>
    <span class="rem-stats" title="Ganados · Efectividad · Puntos"><?php echo htmlspecialchars((string) ($jugador['stats_txt'] ?? '')); ?></span>
    <?php if (!empty($jugador['marca_pareja']) && (int) ($jugador['veces_pareja_antes'] ?? 0) > 0): ?>
        <span class="rem-badge rem-badge-pareja" title="Veces como pareja antes de esta ronda">P×<?php echo (int) $jugador['veces_pareja_antes']; ?></span>
    <?php endif; ?>
    <?php foreach ($jugador['enfrentamientos_repetidos'] ?? [] as $enf): ?>
        <span class="rem-badge rem-badge-enfrenta" title="Enfrentamientos con <?php echo htmlspecialchars((string) ($enf['nombre_corto'] ?? '')); ?>">
            vs×<?php echo (int) ($enf['veces_total'] ?? 0); ?>
        </span>
    <?php endforeach; ?>
</div>
