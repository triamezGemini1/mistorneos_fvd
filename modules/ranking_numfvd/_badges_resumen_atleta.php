<?php
/**
 * Badges de resumen del atleta (prefijo arriba, valor abajo).
 * Requiere: $atleta (array con tj, pj, pg, pp, total_efectividad, total_puntos, total_ptosrnk)
 */
declare(strict_types=1);

$badgesResumen = [
    ['label' => 'TJ', 'value' => (int) ($atleta['tj'] ?? 0), 'bg' => 'bg-primary'],
    ['label' => 'PJ', 'value' => (int) ($atleta['pj'] ?? 0), 'bg' => 'bg-secondary'],
    ['label' => 'PG', 'value' => (int) ($atleta['pg'] ?? 0), 'bg' => 'bg-success'],
    ['label' => 'PP', 'value' => (int) ($atleta['pp'] ?? 0), 'bg' => 'bg-danger'],
    ['label' => 'Efect. Σ', 'value' => (int) ($atleta['total_efectividad'] ?? 0), 'bg' => 'bg-info text-dark'],
    ['label' => 'Pts Σ', 'value' => (int) ($atleta['total_puntos'] ?? 0), 'bg' => 'bg-dark'],
    ['label' => 'Ptos. Rnk', 'value' => (int) ($atleta['total_ptosrnk'] ?? 0), 'bg' => 'bg-warning text-dark'],
];
?>
<div class="rnk-det-resumen d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
    <?php foreach ($badgesResumen as $badge): ?>
        <span class="rnk-stat-pill badge <?= htmlspecialchars($badge['bg']) ?>">
            <span class="rnk-stat-label"><?= htmlspecialchars($badge['label']) ?></span>
            <span class="rnk-stat-val"><?= (int) $badge['value'] ?></span>
        </span>
    <?php endforeach; ?>
</div>
