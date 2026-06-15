<?php
/**
 * Sección «Ranking oficial» + «Podios asociaciones» para la landing.
 * Requiere: $base_url. Opcional: $podios_asociaciones (si no, se calcula aquí).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/RankingCategoriaFvdHelper.php';

if (! isset($podios_asociaciones) || ! is_array($podios_asociaciones)) {
    require_once __DIR__ . '/../../config/db_config.php';
    require_once __DIR__ . '/../../lib/PodiosAsociacionesLandingService.php';
    try {
        $podios_asociaciones = (new PodiosAsociacionesLandingService(DB::pdo()))->construirResumen();
    } catch (Throwable $e) {
        $podios_asociaciones = ['criterio' => '', 'resumen' => [], 'detalle' => []];
    }
}

$podios_resumen = $podios_asociaciones['resumen'] ?? [];
$podios_criterio = (string) ($podios_asociaciones['criterio'] ?? '');

$urlAbsM = RankingCategoriaFvdHelper::urlRanking($base_url, RankingCategoriaFvdHelper::ABSOLUTO, 'M');
$urlAbsF = RankingCategoriaFvdHelper::urlRanking($base_url, RankingCategoriaFvdHelper::ABSOLUTO, 'F');
$subs = [
    ['titulo' => 'Sub 12', 'slug' => RankingCategoriaFvdHelper::SUB12],
    ['titulo' => 'Sub 15', 'slug' => RankingCategoriaFvdHelper::SUB15],
    ['titulo' => 'Sub 18', 'slug' => RankingCategoriaFvdHelper::SUB18],
];

$fmtFechaPodio = static function (?string $f): string {
    if ($f === null || $f === '') {
        return '—';
    }
    $t = strtotime($f);

    return $t ? date('d/m/Y', $t) : '—';
};
?>
<section id="ranking-oficial" class="py-10 md:py-16 bg-gradient-to-b from-slate-50 to-white border-b border-slate-200/80">
    <div class="fvd-landing-container">
        <div class="text-center mb-8 md:mb-10">
            <p class="fvd-section-label mb-3 justify-center"><i class="fas fa-medal" aria-hidden="true"></i> Clasificación nacional</p>
            <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3">Ranking oficial</h2>
            <p class="text-base md:text-lg text-slate-600 max-w-3xl mx-auto">Consulta el acumulado por categoría y sexo en torneos con ranking activado de la FVD.</p>
        </div>

        <div class="max-w-5xl mx-auto space-y-6 md:space-y-8">
            <!-- Categoría libre -->
            <div class="fvd-card overflow-hidden border border-blue-100/80 shadow-md">
                <div class="px-5 py-4 md:px-6 md:py-5 bg-gradient-to-r from-blue-900 to-blue-800">
                    <h3 class="text-lg md:text-xl font-bold text-white m-0"><i class="fas fa-crown mr-2 text-amber-400" aria-hidden="true"></i>Categoría libre</h3>
                </div>
                <div class="p-4 md:p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <a href="<?= htmlspecialchars($urlAbsM) ?>"
                       class="group flex flex-col items-center justify-center rounded-xl border-2 border-blue-100 bg-white px-6 py-8 text-center shadow-sm transition-all hover:border-amber-400/60 hover:shadow-lg hover:-translate-y-0.5">
                        <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-blue-50 text-blue-700 mb-3 group-hover:bg-amber-50 group-hover:text-amber-700 transition-colors">
                            <i class="fas fa-mars text-2xl" aria-hidden="true"></i>
                        </span>
                        <span class="text-lg font-bold text-blue-900">Masculino</span>
                        <span class="text-sm text-slate-500 mt-1">Ranking absoluto</span>
                    </a>
                    <a href="<?= htmlspecialchars($urlAbsF) ?>"
                       class="group flex flex-col items-center justify-center rounded-xl border-2 border-blue-100 bg-white px-6 py-8 text-center shadow-sm transition-all hover:border-amber-400/60 hover:shadow-lg hover:-translate-y-0.5">
                        <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-pink-50 text-pink-700 mb-3 group-hover:bg-amber-50 group-hover:text-amber-700 transition-colors">
                            <i class="fas fa-venus text-2xl" aria-hidden="true"></i>
                        </span>
                        <span class="text-lg font-bold text-blue-900">Femenino</span>
                        <span class="text-sm text-slate-500 mt-1">Ranking absoluto</span>
                    </a>
                </div>
            </div>

            <!-- Sub 12 / 15 / 18 -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-5">
                <?php foreach ($subs as $sub): ?>
                    <div class="fvd-card overflow-hidden border border-slate-200/90 shadow-md flex flex-col h-full">
                        <div class="px-4 py-3 md:py-4 bg-slate-100 border-b border-slate-200">
                            <h3 class="text-base md:text-lg font-bold text-blue-900 m-0 text-center"><?= htmlspecialchars($sub['titulo']) ?></h3>
                        </div>
                        <div class="p-4 flex flex-col gap-3 flex-1">
                            <a href="<?= htmlspecialchars(RankingCategoriaFvdHelper::urlRanking($base_url, $sub['slug'], 'M')) ?>"
                               class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 font-semibold text-sm bg-blue-900 text-white hover:bg-blue-800 transition-colors shadow-sm">
                                <i class="fas fa-mars" aria-hidden="true"></i> Masculino
                            </a>
                            <a href="<?= htmlspecialchars(RankingCategoriaFvdHelper::urlRanking($base_url, $sub['slug'], 'F')) ?>"
                               class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 font-semibold text-sm border-2 border-blue-900 text-blue-900 bg-white hover:bg-blue-50 transition-colors">
                                <i class="fas fa-venus" aria-hidden="true"></i> Femenino
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Podios asociaciones -->
            <div class="fvd-card fvd-podios overflow-hidden shadow-lg">
                <div class="fvd-podios__head px-5 py-4 md:px-6 md:py-5">
                    <h3 class="fvd-podios__title m-0"><i class="fas fa-trophy mr-2" aria-hidden="true"></i>Podios asociaciones</h3>
                    <?php if ($podios_criterio !== ''): ?>
                        <p class="fvd-podios__criterio mb-0 mt-2"><?= htmlspecialchars($podios_criterio) ?></p>
                    <?php endif; ?>
                </div>
                <div class="fvd-podios__body p-4 md:p-6">
                    <?php if ($podios_resumen === []): ?>
                        <div class="text-center py-10 fvd-podios__empty">
                            <i class="fas fa-medal text-4xl mb-3 opacity-80" aria-hidden="true"></i>
                            <p class="mb-0">Aún no hay podios registrados en torneos finalizados.</p>
                        </div>
                    <?php else: ?>
                        <p class="fvd-podios__hint mb-3"><i class="fas fa-hand-pointer mr-1" aria-hidden="true"></i>Seleccione una asociación para desplegar el desglose por torneo.</p>
                        <div class="fvd-podios__table-wrap overflow-x-auto">
                            <table id="podios-resumen-tabla">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Asociación</th>
                                        <th class="text-center"><i class="fas fa-medal" aria-hidden="true"></i> Oro</th>
                                        <th class="text-center">Plata</th>
                                        <th class="text-center">Bronce</th>
                                        <th class="text-right">Puntos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($podios_resumen as $idx => $row): ?>
                                        <?php
                                        $eid = (int) ($row['entidad_id'] ?? 0);
                                        $torneos = $row['por_torneo'] ?? [];
                                        $totales = [
                                            'oro' => (int) ($row['oro'] ?? 0),
                                            'plata' => (int) ($row['plata'] ?? 0),
                                            'bronce' => (int) ($row['bronce'] ?? 0),
                                            'total_puntos' => (int) ($row['total_puntos'] ?? 0),
                                        ];
                                        $fmtFecha = $fmtFechaPodio;
                                        ?>
                                        <tr role="button" tabindex="0"
                                            class="podio-resumen-row"
                                            data-podio-entidad="<?= $eid ?>"
                                            onclick="window.fvdTogglePodioDesglose && window.fvdTogglePodioDesglose(<?= $eid ?>)"
                                            onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.fvdTogglePodioDesglose&&window.fvdTogglePodioDesglose(<?= $eid ?>);}">
                                            <td><?= (int) $idx + 1 ?></td>
                                            <td>
                                                <i class="fas fa-chevron-right fvd-podios-chevron podio-chevron mr-2 transition-transform" id="podio-chevron-<?= $eid ?>" aria-hidden="true"></i>
                                                <?= htmlspecialchars((string) ($row['asociacion'] ?? '')) ?>
                                            </td>
                                            <td class="text-center"><?= (int) ($row['oro'] ?? 0) ?></td>
                                            <td class="text-center"><?= (int) ($row['plata'] ?? 0) ?></td>
                                            <td class="text-center"><?= (int) ($row['bronce'] ?? 0) ?></td>
                                            <td class="text-right"><?= (int) ($row['total_puntos'] ?? 0) ?></td>
                                        </tr>
                                        <tr id="podio-desglose-row-<?= $eid ?>" class="podio-desglose-row hidden">
                                            <td colspan="6" class="fvd-podios-desglose-cell">
                                                <?php require __DIR__ . '/landing_podios_desglose_tabla.php'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <script>
                        (function () {
                            var abierta = null;
                            window.fvdTogglePodioDesglose = function (entidadId) {
                                var filaDetalle = document.getElementById('podio-desglose-row-' + entidadId);
                                if (!filaDetalle) return;
                                if (abierta === entidadId) {
                                    filaDetalle.classList.add('hidden');
                                    abierta = null;
                                } else {
                                    if (abierta !== null) {
                                        var prev = document.getElementById('podio-desglose-row-' + abierta);
                                        if (prev) prev.classList.add('hidden');
                                        var prevChev = document.getElementById('podio-chevron-' + abierta);
                                        if (prevChev) prevChev.classList.remove('rotate-90');
                                    }
                                    filaDetalle.classList.remove('hidden');
                                    abierta = entidadId;
                                }
                                document.querySelectorAll('.podio-resumen-row').forEach(function (tr) {
                                    var id = parseInt(tr.getAttribute('data-podio-entidad'), 10);
                                    tr.classList.toggle('fvd-podios-row--active', id === abierta);
                                });
                                document.querySelectorAll('.podio-chevron').forEach(function (ch) {
                                    ch.classList.remove('rotate-90');
                                });
                                if (abierta !== null) {
                                    var chev = document.getElementById('podio-chevron-' + abierta);
                                    if (chev) chev.classList.add('rotate-90');
                                    filaDetalle.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                                }
                            };
                        })();
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
