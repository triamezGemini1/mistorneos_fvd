<?php
/**
 * Reporte: estructura de mesas por ronda (NUMFVD · nombre · clasificación · stats).
 */
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
require_once __DIR__ . '/../../lib/ResultadosReportePaginacion.php';
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$action_param = $use_standalone ? '?' : '&';

$reporte = $reporte ?? [];
$torneo = $reporte['torneo'] ?? [];
$rondasDisponibles = $reporte['rondas_disponibles'] ?? [];
$rondaActual = (int) ($reporte['ronda_actual'] ?? 0);
$rondaData = $reporte['ronda'] ?? null;
$paginacion = $reporte['paginacion'] ?? ['pagina' => 1, 'por_pagina' => ResultadosReportePaginacion::PER_PAGE, 'total_mesas' => 0, 'total_paginas' => 0];
$leyenda = $reporte['leyenda'] ?? [];
$criterioClasif = $reporte['orden_clasificacion_criterio'] ?? '';
$modalidad_etiqueta = $reporte['modalidad_etiqueta'] ?? '';
$torneo_id = (int) ($torneo['id'] ?? $torneo_id ?? 0);
$pagina = (int) ($paginacion['pagina'] ?? 1);
$totalPaginas = (int) ($paginacion['total_paginas'] ?? 0);
$porPagina = ResultadosReportePaginacion::PER_PAGE;
$totalMesas = (int) ($paginacion['total_mesas'] ?? 0);
$asset_css = AppHelpers::url('assets/css/reporte-estructura-mesas.css');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_css, ENT_QUOTES, 'UTF-8'); ?>">

<div class="rem-reporte w-full max-w-6xl mx-auto px-1 pb-8">
    <header class="mb-4 rem-no-print">
        <h1 class="text-xl font-semibold text-slate-800 flex flex-wrap items-center gap-2">
            <i class="fas fa-sitemap text-primary-500"></i>
            Estructura de mesas por ronda
        </h1>
        <p class="text-sm text-slate-600 mt-1">
            <?php echo htmlspecialchars((string) ($torneo['nombre'] ?? 'Torneo')); ?>
            · <?php echo htmlspecialchars($modalidad_etiqueta); ?>
        </p>
        <nav class="text-xs text-slate-500 mt-2" aria-label="breadcrumb">
            <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo $torneo_id; ?>" class="text-primary-600 hover:underline">Panel</a>
            <span class="mx-1">/</span>
            <span class="text-slate-700">Reporte mesas</span>
        </nav>
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo $torneo_id; ?>"
               class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                <i class="fas fa-arrow-left"></i> Panel
            </a>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-1 rounded-md bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </header>

    <?php if ($rondasDisponibles === []): ?>
        <div class="rounded-md border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
            Aún no hay rondas generadas para este torneo.
        </div>
    <?php else: ?>

    <form method="get" action="<?php echo htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8'); ?>" class="rem-filtros rem-no-print mb-4">
        <?php if (!$use_standalone): ?>
            <input type="hidden" name="page" value="torneo_gestion">
        <?php else: ?>
            <input type="hidden" name="torneo_id" value="<?php echo $torneo_id; ?>">
        <?php endif; ?>
        <input type="hidden" name="action" value="reporte_estructura_mesas">
        <?php if (!$use_standalone): ?>
        <input type="hidden" name="torneo_id" value="<?php echo $torneo_id; ?>">
        <?php endif; ?>
        <div class="flex flex-wrap items-end gap-3">
            <label class="block text-xs font-semibold text-slate-600">
                Ronda
                <select name="ronda" class="mt-1 block rounded-md border border-slate-300 px-2 py-1.5 text-sm min-w-[6rem]" onchange="this.form.pagina.value=1; this.form.submit()">
                    <?php foreach ($rondasDisponibles as $nr): ?>
                        <option value="<?php echo (int) $nr; ?>" <?php echo (int) $nr === $rondaActual ? 'selected' : ''; ?>>
                            Ronda <?php echo (int) $nr; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="pagina" value="<?php echo $pagina; ?>">
            <button type="submit" class="rounded-md bg-slate-700 text-white px-3 py-1.5 text-xs font-medium hover:bg-slate-800">
                <i class="fas fa-sync-alt"></i> Actualizar
            </button>
        </div>
    </form>

    <section class="rem-leyenda text-slate-700 mb-4">
        <p class="font-semibold text-slate-800 mb-1 m-0">Clasificación para asignación</p>
        <p class="text-xs m-0 mb-2"><?php echo htmlspecialchars($criterioClasif); ?></p>
        <dl class="m-0 text-xs">
            <dt><span class="rem-badge rem-badge-pareja">P×n</span> Pareja previa</dt>
            <dd class="mb-1 ms-0"><?php echo htmlspecialchars((string) ($leyenda['pareja'] ?? '')); ?></dd>
            <dt><span class="rem-badge rem-badge-enfrenta">vs×n</span> Enfrentamiento repetido</dt>
            <dd class="mb-1 ms-0"><?php echo htmlspecialchars((string) ($leyenda['enfrenta'] ?? '')); ?></dd>
            <dt><span class="rem-orden">#n</span> Orden · <span class="rem-stats">G/E/P</span> Stats</dt>
            <dd class="ms-0"><?php echo htmlspecialchars((string) ($leyenda['clasif'] ?? '')); ?></dd>
        </dl>
    </section>

    <?php if ($rondaData !== null): ?>
        <section class="rem-ronda-block">
            <div class="rem-ronda-head">
                <h2>R<?php echo (int) $rondaData['numero']; ?>
                    <span class="font-normal opacity-90">— <?php echo (int) $rondaData['total_mesas']; ?> mesa(s)</span>
                    <span class="font-normal opacity-75 text-sm"> · pág. <?php echo $pagina; ?>/<?php echo max(1, $totalPaginas); ?></span>
                </h2>
                <p class="rem-procedimiento m-0">
                    <i class="fas fa-cog me-1"></i>
                    <?php echo htmlspecialchars((string) ($rondaData['procedimiento'] ?? '')); ?>
                </p>
            </div>

            <?php if (!empty($rondaData['clasificacion_preview'])): ?>
            <div class="rem-clasif-preview rem-no-print">
                <p class="text-xs font-semibold text-slate-600 m-0 mb-1">Top clasificación (al generar esta ronda)</p>
                <p class="text-xs text-slate-500 m-0">
                    <?php
                    $tops = [];
                    foreach ($rondaData['clasificacion_preview'] as $p) {
                        $tops[] = '#' . $p['orden'] . ' ' . $p['numfvd_txt'] . ' (' . $p['stats_txt'] . ')';
                    }
                    echo htmlspecialchars(implode(' · ', $tops));
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="border border-t-0 border-slate-200 rounded-b-lg p-3 bg-slate-50/50">
                <?php foreach ($rondaData['mesas'] as $mesa): ?>
                    <article class="rem-mesa-card <?php echo !empty($mesa['alerta_lider_mesa1']) ? 'rem-mesa-card--alerta' : ''; ?>">
                        <div class="rem-mesa-title">
                            R<?php echo (int) $rondaData['numero']; ?> <?php echo htmlspecialchars((string) ($mesa['etiqueta'] ?? 'M')); ?>
                            <span class="rem-orden-cabeza" title="Orden clasificación del jugador en A">A=#<?php echo (int) ($mesa['orden_cabeza_a'] ?? 0); ?></span>
                            <?php if (!empty($mesa['alerta_lider_mesa1'])): ?>
                                <span class="rem-alerta-mesa1" title="El #1 de clasificación no está en cabeza A">⚠ No es #1 clasif.</span>
                            <?php endif; ?>
                        </div>
                        <div class="rem-mesa-grid">
                            <div class="rem-pareja-col">
                                <div class="rem-pareja-label">A · C (a favor)</div>
                                <?php foreach ($mesa['pareja_ac'] as $jugador): ?>
                                    <?php include __DIR__ . '/_reporte_estructura_jugador.php'; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="rem-vs">VS</div>
                            <div class="rem-pareja-col">
                                <div class="rem-pareja-label">B · D (a favor)</div>
                                <?php foreach ($mesa['pareja_bd'] as $jugador): ?>
                                    <?php include __DIR__ . '/_reporte_estructura_jugador.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if (!empty($rondaData['bye'])): ?>
                    <div class="rem-bye">
                        <strong>BYE:</strong>
                        <?php
                        $byeTxt = [];
                        foreach ($rondaData['bye'] as $b) {
                            $byeTxt[] = trim(
                                (string) ($b['numfvd_txt'] ?? '—') . ' '
                                . (string) ($b['nombre'] ?? '') . ' '
                                . (string) ($b['stats_txt'] ?? '')
                            );
                        }
                        echo htmlspecialchars(implode(' · ', $byeTxt));
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalMesas > 0): ?>
                <?php
                echo ResultadosReportePaginacion::renderForTorneoReport(
                    (int) $pagina,
                    (int) max(1, $totalPaginas),
                    (int) $totalMesas,
                    (int) $porPagina,
                    $base_url,
                    $use_standalone,
                    [
                        'action' => 'reporte_estructura_mesas',
                        'torneo_id' => $torneo_id,
                        'ronda' => $rondaActual,
                    ],
                    'mesas'
                );
                ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php endif; ?>

    <footer class="text-xs text-slate-400 mt-6 rem-no-print">
        Generado <?php echo date('d/m/Y H:i'); ?> — torneo #<?php echo $torneo_id; ?>.
    </footer>
</div>
