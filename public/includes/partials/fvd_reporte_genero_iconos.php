<?php
/**
 * Selector de género con iconos (Todos / Masculino / Femenino).
 *
 * @var callable(string): string $urlGeneroFn
 * @var string $generoActual T|M|F
 */
$generoActual = strtoupper(trim((string) ($generoActual ?? 'T')));
$urlGeneroFn = $urlGeneroFn ?? static fn (string $g): string => '#';
$iconBtn = static function (string $g) use ($generoActual): string {
    return 'fvd-reporte-genero-iconos__btn' . ($generoActual === $g ? ' is-active' : '');
};
?>
<div class="fvd-reporte-genero-iconos" role="group" aria-label="Clasificación por género">
    <a href="<?= htmlspecialchars($urlGeneroFn('T')) ?>"
       class="<?= $iconBtn('T') ?>"
       title="Todos los géneros"
       aria-label="Todos los géneros"
       <?= $generoActual === 'T' ? 'aria-current="true"' : '' ?>>
        <i class="fas fa-users" aria-hidden="true"></i>
    </a>
    <a href="<?= htmlspecialchars($urlGeneroFn('M')) ?>"
       class="<?= $iconBtn('M') ?>"
       title="Masculino"
       aria-label="Masculino"
       <?= $generoActual === 'M' ? 'aria-current="true"' : '' ?>>
        <i class="fas fa-mars" aria-hidden="true"></i>
    </a>
    <a href="<?= htmlspecialchars($urlGeneroFn('F')) ?>"
       class="<?= $iconBtn('F') ?>"
       title="Femenino"
       aria-label="Femenino"
       <?= $generoActual === 'F' ? 'aria-current="true"' : '' ?>>
        <i class="fas fa-venus" aria-hidden="true"></i>
    </a>
</div>
