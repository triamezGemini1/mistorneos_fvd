<?php
/**
 * Nombre del atleta y asociación debajo.
 * Vars: $nombreAtleta, $asociacionAtleta (o $atleta['nombre'], $atleta['asociacion'])
 * Opcional: $nombreTag ('div'|'strong'), $nombreClass
 */
declare(strict_types=1);

$nombre = (string) ($nombreAtleta ?? $atleta['nombre'] ?? '');
$asoc = trim((string) ($asociacionAtleta ?? $atleta['asociacion'] ?? ''));
$tag = ($nombreTag ?? 'strong') === 'div' ? 'div' : 'strong';
$cls = trim((string) ($nombreClass ?? ''));
?>
<<?= $tag ?><?= $cls !== '' ? ' class="' . htmlspecialchars($cls) . '"' : '' ?>><?= htmlspecialchars($nombre) ?></<?= $tag ?>>
<?php if ($asoc !== ''): ?>
    <span class="rnk-atleta-asociacion text-muted small d-block"><?= htmlspecialchars($asoc) ?></span>
<?php endif; ?>
