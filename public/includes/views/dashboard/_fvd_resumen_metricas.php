<?php
/**
 * Tabla de métricas institucionales (sin torneos).
 * Variable: $fvd_metricas_filas = [['label' => '', 'valor' => n], ...]
 */
$fvd_metricas_filas = $fvd_metricas_filas ?? [];
$fvd_metricas_titulo = $fvd_metricas_titulo ?? 'Resumen del sistema';
?>
<section class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="bg-blue-900 text-white px-3 py-2">
        <h2 class="text-sm font-semibold tracking-wide"><i class="fas fa-chart-bar me-2 text-amber-400"></i><?= htmlspecialchars($fvd_metricas_titulo) ?></h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[20rem] text-sm">
            <tbody>
                <?php foreach ($fvd_metricas_filas as $i => $fila): ?>
                <tr class="<?= ($i % 2 === 1) ? 'bg-slate-50/80' : 'bg-white' ?> border-b border-slate-100 last:border-0">
                    <td class="px-3 py-1.5 text-slate-600"><?= htmlspecialchars($fila['label'] ?? '') ?></td>
                    <td class="px-3 py-1.5 text-right font-mono font-semibold text-slate-900"><?= number_format((int)($fila['valor'] ?? 0)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
