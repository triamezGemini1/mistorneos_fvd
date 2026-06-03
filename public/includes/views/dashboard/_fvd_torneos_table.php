<?php
/**
 * Tabla compacta de torneos (en curso / próximos).
 * Variables: $fvd_torneos_tabla (array), $fvd_torneos_titulo (string, opcional)
 */
$fvd_torneos_tabla = $fvd_torneos_tabla ?? [];
$fvd_torneos_titulo = $fvd_torneos_titulo ?? 'Torneos en curso y próximos';
require_once __DIR__ . '/_fvd_torneos_org_helper.php';
$uTorneo = static function (int $id): string {
    return htmlspecialchars(AppHelpers::dashboard('torneo_gestion', ['action' => 'panel', 'torneo_id' => $id]));
};
$uTodos = htmlspecialchars(AppHelpers::dashboard('torneo_gestion', ['action' => 'index']));
?>
<div class="fvd-torneos-card">
    <div class="fvd-torneos-card__head">
        <h3 class="fvd-torneos-card__head-title">
            <i class="fas fa-list me-1 text-amber-300" aria-hidden="true"></i><?= htmlspecialchars($fvd_torneos_titulo) ?>
        </h3>
        <a href="<?= $uTodos ?>" class="fvd-torneos-card__head-link">Ver todos <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
    <div class="overflow-x-auto">
        <table class="fvd-torneos-card__table text-start">
            <thead>
                <tr>
                    <th>Torneo</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <th class="text-center">Inscritos</th>
                    <th class="text-center">Ronda</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($fvd_torneos_tabla)): ?>
                <tr>
                    <td colspan="6" class="fvd-torneos-card__empty">No hay torneos en curso ni próximos en los próximos 15 días.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($fvd_torneos_tabla as $i => $t):
                    $estadoDash = (string) ($t['_dashboard_estado'] ?? 'por_realizar');
                    $esProceso = $estadoDash === 'en_proceso';
                ?>
                <tr class="<?= ($i % 2 === 1) ? 'bg-slate-50/80' : 'bg-white' ?>">
                    <td class="font-medium text-slate-800 max-w-[14rem] truncate" title="<?= htmlspecialchars($t['nombre'] ?? '') ?>">
                        <?= htmlspecialchars($t['nombre'] ?? '—') ?>
                        <?php
                        $orgNombre = trim((string) ($t['organizacion_nombre'] ?? ''));
                        if ($orgNombre !== '' && fvd_dashboard_mostrar_org_torneo($orgNombre)):
                        ?>
                        <span class="d-block small text-muted text-truncate"><?= htmlspecialchars($orgNombre) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fvd-torneo-estado-badge <?= $esProceso ? 'fvd-torneo-estado-badge--proceso' : 'fvd-torneo-estado-badge--proximo' ?>">
                            <?= $esProceso ? 'En curso' : 'Próximo' ?>
                        </span>
                    </td>
                    <td class="font-monospace text-slate-600 text-nowrap">
                        <?php
                        $ft = $t['fechator'] ?? '';
                        echo $ft !== '' ? htmlspecialchars(date('d/m/Y', strtotime((string) $ft))) : '—';
                        ?>
                    </td>
                    <td class="text-center font-monospace text-slate-700">
                        <?= (int)($t['inscritos_confirmados'] ?? 0) ?>/<?= (int)($t['total_inscritos'] ?? 0) ?>
                    </td>
                    <td class="text-center font-monospace text-slate-600">
                        <?= (int)($t['ultima_ronda'] ?? 0) ?>/<?= (int)($t['rondas'] ?? 0) ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= $uTorneo((int)($t['id'] ?? 0)) ?>" class="fvd-torneos-card__panel-link">
                            Panel <i class="fas fa-chevron-right ms-1"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
