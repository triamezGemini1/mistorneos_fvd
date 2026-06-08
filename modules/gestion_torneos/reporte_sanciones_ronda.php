<?php
/**
 * Reporte: tarjetas disciplinarias por jugador (NUMFVD), secuencia M-R-T (mesa · ronda · tarjeta).
 */
if (! class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../../lib/ResultadosReportePaginacion.php';

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$action_param = $use_standalone ? '?' : '&';

$reporte = $reporte ?? [];
$torneo = $reporte['torneo'] ?? [];
$filas = $reporte['filas'] ?? [];
$pagina_raw = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$pag_sanc = ResultadosReportePaginacion::paginarFilas($filas, $pagina_raw);
$filasPagina = $pag_sanc['filas'];
$paginaActual = $pag_sanc['pagina'];
$totalPaginas = $pag_sanc['total_paginas'];
$totalFilas = $pag_sanc['total'];
$itemsPorPagina = $pag_sanc['por_pagina'];
$rondasDisponibles = $reporte['rondas_disponibles'] ?? [];
$rondaFiltro = (int) ($reporte['ronda_filtro'] ?? 0);
$torneo_id = (int) ($torneo['id'] ?? $torneo_id ?? 0);
$asset_css = AppHelpers::url('assets/css/reporte-estructura-mesas.css');

$badgeTarjeta = static function (string $letra): string {
    switch ($letra) {
        case 'A':
            $cls = 'background:#fef9c3;color:#854d0e;border:1px solid #facc15;';
            break;
        case 'R':
            $cls = 'background:#fee2e2;color:#991b1b;border:1px solid #f87171;';
            break;
        case 'N':
            $cls = 'background:#1e293b;color:#f8fafc;border:1px solid #0f172a;';
            break;
        default:
            $cls = 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
            break;
    }

    return '<span class="inline-flex min-w-[1.25rem] justify-center rounded px-1.5 py-0.5 text-xs font-bold" style="' . $cls . '">'
        . htmlspecialchars($letra, ENT_QUOTES, 'UTF-8') . '</span>';
};

$renderSanciones = static function (array $sanciones) use ($badgeTarjeta): string {
    if ($sanciones === []) {
        return '—';
    }
    $out = [];
    foreach ($sanciones as $s) {
        $mesa = (int) ($s['mesa'] ?? 0);
        $ronda = (int) ($s['ronda'] ?? 0);
        $letra = (string) ($s['tarjeta_letra'] ?? '');
        $out[] = '<span class="inline-flex items-center gap-0.5 mr-2 whitespace-nowrap font-mono text-xs">'
            . '<span class="font-semibold text-slate-700">' . $mesa . '</span>'
            . '<span class="text-slate-400">-</span>'
            . '<span class="font-semibold text-slate-700">' . $ronda . '</span>'
            . '<span class="text-slate-400">-</span>'
            . $badgeTarjeta($letra)
            . '</span>';
    }

    return implode('<span class="text-slate-300 mr-1">,</span>', $out);
};
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_css, ENT_QUOTES, 'UTF-8'); ?>">

<div class="rem-reporte w-full max-w-5xl mx-auto px-1 pb-8">
    <header class="mb-4 rem-no-print">
        <h1 class="text-xl font-semibold text-slate-800 flex flex-wrap items-center gap-2">
            <i class="fas fa-id-card text-amber-600"></i>
            Sanciones por ronda (tarjetas)
        </h1>
        <p class="text-sm text-slate-600 mt-1">
            <?php echo htmlspecialchars((string) ($torneo['nombre'] ?? 'Torneo')); ?>
        </p>
        <p class="text-xs text-slate-500 mt-2">
            Una fila por jugador (NUMFVD). Secuencia cronológica <strong>M-R-T</strong> (mesa · ronda · tarjeta),
            por ejemplo: 12-1-A, 5-3-R, 8-5-N
            (<strong>A</strong> amarilla · <strong>R</strong> roja · <strong>N</strong> negra).
            No incluye sanciones de 40 pts sin tarjeta ni tarjetas copiadas por error en mesas con forfait o tarjeta roja/negra del rival.
        </p>
        <nav class="text-xs text-slate-500 mt-2" aria-label="breadcrumb">
            <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo $torneo_id; ?>" class="text-primary-600 hover:underline">Panel</a>
            <span class="mx-1">/</span>
            <a href="<?php echo $base_url . $action_param; ?>action=resultados_reportes&torneo_id=<?php echo $torneo_id; ?>" class="text-primary-600 hover:underline">Reportes</a>
            <span class="mx-1">/</span>
            <span class="text-slate-700">Sanciones por ronda</span>
        </nav>
        <div class="flex flex-wrap gap-2 mt-3 items-end">
            <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo $torneo_id; ?>"
               class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                <i class="fas fa-arrow-left"></i> Panel
            </a>
            <form method="get" action="<?php echo htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-end gap-2">
                <?php if (! $use_standalone): ?>
                    <input type="hidden" name="page" value="torneo_gestion">
                <?php endif; ?>
                <input type="hidden" name="action" value="reporte_sanciones_ronda">
                <input type="hidden" name="torneo_id" value="<?php echo $torneo_id; ?>">
                <label class="text-xs font-semibold text-slate-600">
                    Ronda
                    <select name="ronda" class="mt-1 block rounded-md border border-slate-300 px-2 py-1 text-sm">
                        <option value="0"<?php echo $rondaFiltro === 0 ? ' selected' : ''; ?>>Todas</option>
                        <?php foreach ($rondasDisponibles as $r): ?>
                            <option value="<?php echo (int) $r; ?>"<?php echo $rondaFiltro === (int) $r ? ' selected' : ''; ?>>
                                Ronda <?php echo (int) $r; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="rounded-md bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600">Filtrar</button>
            </form>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-1 rounded-md bg-slate-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </header>

    <?php if ($reporte['sin_tarjetas'] ?? true): ?>
        <div class="rounded-md border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            <i class="fas fa-check-circle me-1"></i>
            No hay tarjetas registradas<?php echo $rondaFiltro > 0 ? ' en la ronda ' . $rondaFiltro : ' en este torneo'; ?>.
        </div>
    <?php else: ?>
        <p class="text-xs text-slate-500 mb-2 rem-no-print">
            <?php echo $totalFilas; ?> jugador(es) con tarjeta
            <?php if ($rondaFiltro > 0): ?>
                · filtro: ronda <?php echo $rondaFiltro; ?>
            <?php endif; ?>
            · página <?php echo $paginaActual; ?>/<?php echo max(1, $totalPaginas); ?>
        </p>
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-700">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold w-28">NUMFVD</th>
                        <th class="px-3 py-2 text-left font-semibold">Nombre</th>
                        <th class="px-3 py-2 text-left font-semibold">Tarjetas (M-R-T)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($filasPagina as $fila): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono text-slate-800 align-top"><?php echo htmlspecialchars((string) ($fila['numfvd'] ?? '—')); ?></td>
                            <td class="px-3 py-2 text-slate-800 align-top"><?php echo htmlspecialchars((string) ($fila['nombre'] ?? '')); ?></td>
                            <td class="px-3 py-2 align-top">
                                <div class="flex flex-wrap items-center gap-y-1">
                                    <?php echo $renderSanciones((array) ($fila['sanciones'] ?? [])); ?>
                                </div>
                                <span class="sr-only"><?php echo htmlspecialchars((string) ($fila['sanciones_texto'] ?? '')); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalFilas > 0): ?>
            <?php
            $pagParamsSanc = [
                'action' => 'reporte_sanciones_ronda',
                'torneo_id' => $torneo_id,
            ];
            if ($rondaFiltro > 0) {
                $pagParamsSanc['ronda'] = $rondaFiltro;
            }
            echo ResultadosReportePaginacion::renderForTorneoReport(
                (int) $paginaActual,
                (int) $totalPaginas,
                (int) $totalFilas,
                (int) $itemsPorPagina,
                $base_url,
                $use_standalone,
                $pagParamsSanc,
                'jugadores'
            );
            ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
