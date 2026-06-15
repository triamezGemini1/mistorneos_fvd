<?php
/**
 * Tabla de participación por torneo (parcial).
 * Requiere: $atleta, $modalidades, $fmtFecha, $leyendaStatsModalidad
 */
declare(strict_types=1);

$detalleTorneos = $atleta['detalle_torneos'] ?? [];
?>
<div class="table-responsive">
    <table class="table table-sm table-bordered mb-0 bg-white">
        <thead class="table-light">
            <tr>
                <th>Torneo</th>
                <th>Fecha</th>
                <th>Modalidad</th>
                <th class="text-center">Clasif</th>
                <th class="text-center">PG</th>
                <th class="text-center">PP</th>
                <th class="text-center">PJ</th>
                <th class="text-end">EFEC</th>
                <th class="text-end">Tot Pts</th>
                <th class="text-end">Ptos. Rnk</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detalleTorneos as $t): ?>
                <?php
                $participo = ! isset($t['participo']) || ! empty($t['participo']);
                $modT = (int) ($t['modalidad'] ?? 0);
                $trDetClass = $participo ? '' : 'table-secondary opacity-75';
                ?>
                <tr class="<?= $trDetClass ?>">
                    <td>
                        <?= htmlspecialchars((string) ($t['nombre'] ?? '')) ?>
                        <?php if (! $participo): ?>
                            <span class="badge bg-secondary ms-1">No participó</span>
                        <?php elseif ($modT === 3 && ! empty($t['codigo_equipo'])): ?>
                            <span class="text-muted small d-block">Eq. <?= htmlspecialchars((string) $t['codigo_equipo']) ?></span>
                        <?php endif; ?>
                        <?php
                        $gr = trim((string) ($t['campeonato_grupo'] ?? ''));
                        if ($gr !== ''): ?>
                            <span class="badge bg-info text-dark ms-1"><?= htmlspecialchars($gr) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $fmtFecha((string) ($t['fechator'] ?? '')) ?></td>
                    <td>
                        <?= htmlspecialchars($modalidades[$modT] ?? '—') ?>
                        <?php $ley = $leyendaStatsModalidad($modT); ?>
                        <?php if ($ley !== ''): ?>
                            <span class="text-muted small d-block"><?= htmlspecialchars($ley) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $participo && (int) ($t['clasif'] ?? 0) ? (int) $t['clasif'] : '—' ?></td>
                    <td class="text-center"><?= $participo ? (int) ($t['pg'] ?? 0) : '—' ?></td>
                    <td class="text-center"><?= $participo ? (int) ($t['pp'] ?? 0) : '—' ?></td>
                    <td class="text-center"><?= $participo ? (int) ($t['pj'] ?? 0) : '—' ?></td>
                    <td class="text-end"><?= $participo ? (int) ($t['efec'] ?? 0) : '—' ?></td>
                    <td class="text-end"><?= $participo ? (int) ($t['tot_pts'] ?? 0) : '—' ?></td>
                    <td class="text-end fw-semibold"><?= $participo ? (int) ($t['ptosrnk'] ?? 0) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
            <tr>
                <th colspan="3" class="text-end">Totales (torneos jugados)</th>
                <th class="text-center">—</th>
                <th class="text-center"><?= (int) ($atleta['pg'] ?? 0) ?></th>
                <th class="text-center"><?= (int) ($atleta['pp'] ?? 0) ?></th>
                <th class="text-center"><?= (int) ($atleta['pj'] ?? 0) ?></th>
                <th class="text-end"><?= (int) ($atleta['total_efectividad'] ?? 0) ?></th>
                <th class="text-end"><?= (int) ($atleta['total_puntos'] ?? 0) ?></th>
                <th class="text-end fw-semibold"><?= (int) ($atleta['total_ptosrnk'] ?? 0) ?></th>
            </tr>
        </tfoot>
    </table>
</div>
