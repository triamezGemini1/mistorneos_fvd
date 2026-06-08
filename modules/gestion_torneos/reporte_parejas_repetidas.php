<?php
/**
 * Reporte: atletas que jugaron más de una vez como pareja (misma dupla), con ronda y contrarios.
 */
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
require_once __DIR__ . '/../../lib/ResumenJugadorNavigation.php';
require_once __DIR__ . '/../../lib/ResultadosReportePaginacion.php';

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$action_param = $use_standalone ? '?' : '&';

$reporte = $reporte ?? [];
$torneo = $reporte['torneo'] ?? [];
$grupos = $reporte['grupos'] ?? [];
$pagina_raw = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$pag_grupos = ResultadosReportePaginacion::paginarFilas($grupos, $pagina_raw);
$gruposPagina = $pag_grupos['filas'];
$paginaActual = $pag_grupos['pagina'];
$totalPaginas = $pag_grupos['total_paginas'];
$totalGrupos = $pag_grupos['total'];
$itemsPorPagina = $pag_grupos['por_pagina'];
$minVeces = (int) ($reporte['min_veces'] ?? 2);
$torneo_id = (int) ($torneo['id'] ?? $torneo_id ?? 0);
$asset_css = AppHelpers::url('assets/css/reporte-estructura-mesas.css');
$urlBase = $base_url . $action_param . 'action=reporte_parejas_repetidas&torneo_id=' . $torneo_id;
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_css, ENT_QUOTES, 'UTF-8'); ?>">

<div class="rem-reporte w-full max-w-6xl mx-auto px-1 pb-8">
    <header class="mb-4 rem-no-print">
        <h1 class="text-xl font-semibold text-slate-800 flex flex-wrap items-center gap-2">
            <i class="fas fa-user-friends text-rose-600"></i>
            Parejas repetidas (mismo compañero)
        </h1>
        <p class="text-sm text-slate-600 mt-1">
            <?php echo htmlspecialchars((string) ($torneo['nombre'] ?? 'Torneo')); ?>
        </p>
        <p class="text-xs text-slate-500 mt-2">
            Fuente: <strong>historial_parejas</strong> (llave menor–mayor y <strong>mesa</strong> por registro).
            Si la misma llave se repite <?php echo $minVeces; ?> o más veces, se listan ronda, mesa, pareja y rivales.
        </p>
        <nav class="text-xs text-slate-500 mt-2" aria-label="breadcrumb">
            <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo $torneo_id; ?>" class="text-primary-600 hover:underline">Panel</a>
            <span class="mx-1">/</span>
            <a href="<?php echo $base_url . $action_param; ?>action=resultados_reportes&torneo_id=<?php echo $torneo_id; ?>" class="text-primary-600 hover:underline">Reportes</a>
            <span class="mx-1">/</span>
            <span class="text-slate-700">Parejas repetidas</span>
        </nav>
        <div class="flex flex-wrap gap-2 mt-3 items-end">
            <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo $torneo_id; ?>"
               class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                <i class="fas fa-arrow-left"></i> Panel
            </a>
            <a href="<?php echo $base_url . $action_param; ?>action=reporte_estructura_mesas&torneo_id=<?php echo $torneo_id; ?>"
               class="inline-flex items-center gap-1 rounded-md border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-800 hover:bg-indigo-100">
                <i class="fas fa-sitemap"></i> Estructura mesas
            </a>
            <form method="get" action="<?php echo htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-end gap-2">
                <?php if (!$use_standalone): ?>
                    <input type="hidden" name="page" value="torneo_gestion">
                <?php endif; ?>
                <input type="hidden" name="action" value="reporte_parejas_repetidas">
                <input type="hidden" name="torneo_id" value="<?php echo $torneo_id; ?>">
                <label class="text-xs font-semibold text-slate-600">
                    Mín. veces juntos
                    <input type="number" name="min_veces" min="2" max="20" value="<?php echo $minVeces; ?>"
                           class="mt-1 block w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                </label>
                <button type="submit" class="rounded-md bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600">Filtrar</button>
            </form>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-1 rounded-md bg-slate-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </header>

    <?php if (!empty($reporte['sin_historial'])): ?>
        <div class="rounded-md border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <?php echo htmlspecialchars((string) ($reporte['mensaje'] ?? 'Sin datos en historial_parejas.')); ?>
        </div>
    <?php elseif (!empty($reporte['sin_repeticiones'])): ?>
        <div class="rounded-md border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            <i class="fas fa-check-circle me-1"></i>
            <?php
            $msgOk = trim((string) ($reporte['mensaje'] ?? ''));
            echo htmlspecialchars($msgOk !== '' ? $msgOk : 'No hay llaves repetidas en historial_parejas con el mínimo indicado.');
            ?>
        </div>
    <?php else: ?>
        <p class="text-sm text-slate-700 mb-3 rem-no-print">
            <strong><?php echo (int) ($reporte['total_grupos'] ?? $totalGrupos); ?></strong> dupla(s) con repetición
            · página <?php echo $paginaActual; ?>/<?php echo max(1, $totalPaginas); ?>
        </p>

        <?php foreach ($gruposPagina as $g): ?>
            <section class="rem-ronda-block mb-5 border border-slate-200 rounded-lg overflow-hidden bg-white shadow-sm">
                <div class="rem-ronda-head px-4 py-3" style="background: linear-gradient(90deg, #9f1239 0%, #e11d48 100%);">
                    <h2 class="text-base font-bold text-white m-0 flex flex-wrap items-center gap-2">
                        <span><?php echo htmlspecialchars((string) ($g['jugador_a']['numfvd_txt'] ?? '')); ?>
                            <?php echo htmlspecialchars((string) ($g['jugador_a']['nombre'] ?? '')); ?></span>
                        <span class="opacity-80">+</span>
                        <span><?php echo htmlspecialchars((string) ($g['jugador_b']['numfvd_txt'] ?? '')); ?>
                            <?php echo htmlspecialchars((string) ($g['jugador_b']['nombre'] ?? '')); ?></span>
                        <span class="ms-auto text-sm font-semibold bg-white/20 rounded px-2 py-0.5">
                            Llave <?php echo htmlspecialchars((string) ($g['llave'] ?? '')); ?>
                            · <?php echo (int) ($g['veces'] ?? 0); ?> incidencias
                        </span>
                    </h2>
                </div>
                <div class="p-0 overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-slate-100 text-slate-700">
                                <th class="px-3 py-2 text-left border-b">Ronda</th>
                                <th class="px-3 py-2 text-center border-b">Mesa</th>
                                <th class="px-3 py-2 text-left border-b">Pareja (llave)</th>
                                <th class="px-3 py-2 text-left border-b">Enfrentaron a (pareja rival)</th>
                                <th class="px-3 py-2 text-left border-b rem-no-print">Resumen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($g['ocurrencias'] as $idx => $occ):
                                $stripe = ($idx % 2 === 1) ? 'bg-slate-50' : '';
                            ?>
                            <tr class="<?php echo $stripe; ?> border-b border-slate-100">
                                <td class="px-3 py-2 font-semibold"><?php echo (int) ($occ['ronda'] ?? 0); ?></td>
                                <td class="px-3 py-2 text-center">
                                    <?php
                                    $mesaOcc = (int) ($occ['mesa'] ?? 0);
                                    $rondaOcc = (int) ($occ['ronda'] ?? 0);
                                    if ($mesaOcc > 0 && $rondaOcc > 0 && $torneo_id > 0):
                                        $urlMesa = $base_url . $action_param . 'action=reporte_estructura_mesas&torneo_id='
                                            . $torneo_id . '&ronda=' . $rondaOcc;
                                    ?>
                                        <a href="<?php echo htmlspecialchars($urlMesa, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="text-indigo-700 hover:underline font-semibold" title="Ver estructura de la ronda">
                                            <?php echo $mesaOcc; ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $mesaOcc > 0 ? $mesaOcc : '—'; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="font-mono text-slate-600"><?php echo htmlspecialchars((string) ($occ['llave'] ?? $g['llave'] ?? '')); ?></span><br>
                                    <?php echo htmlspecialchars((string) ($occ['pareja_txt'] ?? $g['etiqueta_pareja'] ?? '')); ?>
                                </td>
                                <td class="px-3 py-2">
                                    <?php foreach ($occ['contrarios'] ?? [] as $riv): ?>
                                        <div class="mb-1">
                                            <?php
                                            $idRiv = (int) ($riv['id'] ?? 0);
                                            $nomRiv = (string) ($riv['nombre'] ?? '—');
                                            if ($idRiv > 0 && $torneo_id > 0) {
                                                echo ResumenJugadorNavigation::enlaceNombre(
                                                    $riv['numfvd_txt'] . ' ' . $nomRiv,
                                                    $torneo_id,
                                                    $idRiv,
                                                    'reporte_parejas_repetidas',
                                                    'text-indigo-700 hover:underline font-medium',
                                                    ['min_veces' => $minVeces]
                                                );
                                            } else {
                                                echo htmlspecialchars($nomRiv);
                                            }
                                            ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($occ['contrarios'])): ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-600"><?php echo htmlspecialchars((string) ($occ['linea_mesa'] ?? '')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if ($totalGrupos > 0): ?>
            <?php
            echo ResultadosReportePaginacion::renderForTorneoReport(
                (int) $paginaActual,
                (int) $totalPaginas,
                (int) $totalGrupos,
                (int) $itemsPorPagina,
                $base_url,
                $use_standalone,
                [
                    'action' => 'reporte_parejas_repetidas',
                    'torneo_id' => $torneo_id,
                    'min_veces' => $minVeces,
                ],
                'duplas'
            );
            ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
