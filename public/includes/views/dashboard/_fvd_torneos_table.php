<?php
/**
 * Tabla compacta de torneos (en curso / próximos).
 * Variables: $fvd_torneos_tabla (array), $fvd_torneos_titulo (string, opcional)
 */
$fvd_torneos_tabla = $fvd_torneos_tabla ?? [];
$fvd_torneos_titulo = $fvd_torneos_titulo ?? 'Torneos en curso y próximos';
$uTorneo = static function (int $id): string {
    return htmlspecialchars(AppHelpers::dashboard('torneo_gestion', ['action' => 'panel', 'torneo_id' => $id]));
};
?>
<section class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="bg-blue-900 text-white px-3 py-2 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold tracking-wide"><i class="fas fa-trophy me-2 text-amber-400"></i><?= htmlspecialchars($fvd_torneos_titulo) ?></h2>
        <a href="<?= htmlspecialchars(AppHelpers::dashboard('torneo_gestion', ['action' => 'index'])) ?>" class="text-xs text-amber-300 hover:text-amber-200 font-medium">Ver todos <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[32rem] text-sm text-left">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-2 font-semibold">Torneo</th>
                    <th class="px-3 py-2 font-semibold whitespace-nowrap">Fecha</th>
                    <th class="px-3 py-2 font-semibold text-center">Inscritos</th>
                    <th class="px-3 py-2 font-semibold text-center">Ronda</th>
                    <th class="px-3 py-2 font-semibold"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($fvd_torneos_tabla)): ?>
                <tr>
                    <td colspan="5" class="px-3 py-6 text-center text-slate-500 text-sm">No hay torneos en curso ni próximos en este momento.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($fvd_torneos_tabla as $i => $t): ?>
                <tr class="<?= ($i % 2 === 1) ? 'bg-slate-50/80' : 'bg-white' ?> border-b border-slate-100 last:border-0">
                    <td class="px-3 py-1.5 font-medium text-slate-800 max-w-[14rem] truncate" title="<?= htmlspecialchars($t['nombre'] ?? '') ?>">
                        <?= htmlspecialchars($t['nombre'] ?? '—') ?>
                        <?php if (!empty($t['organizacion_nombre'])): ?>
                        <span class="block text-[10px] font-normal text-slate-500 truncate"><?= htmlspecialchars($t['organizacion_nombre']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-1.5 font-mono text-slate-600 whitespace-nowrap">
                        <?php
                        $ft = $t['fechator'] ?? '';
                        echo $ft !== '' ? htmlspecialchars(date('d/m/Y', strtotime((string) $ft))) : '—';
                        ?>
                    </td>
                    <td class="px-3 py-1.5 text-center font-mono text-slate-700">
                        <?= (int)($t['inscritos_confirmados'] ?? 0) ?>/<?= (int)($t['total_inscritos'] ?? 0) ?>
                    </td>
                    <td class="px-3 py-1.5 text-center font-mono text-slate-600">
                        <?= (int)($t['ultima_ronda'] ?? 0) ?>/<?= (int)($t['rondas'] ?? 0) ?>
                    </td>
                    <td class="px-3 py-1.5 text-right">
                        <a href="<?= $uTorneo((int)($t['id'] ?? 0)) ?>" class="inline-flex items-center text-xs font-medium text-amber-600 hover:text-amber-500">Panel <i class="fas fa-chevron-right ms-1 text-[10px]"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
