<?php
/**
 * Reportes segmentados. PDF/Excel vía public/export_*.php (modules/ no es público).
 */
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';

$torneo_id = (int)($torneo_id ?? 0);
$esEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
$generoRep = ResultadosReporteData::generoFiltroDesdeParametro(isset($_GET['genero']) ? (string) $_GET['genero'] : null);

$tg = static function (string $action, array $extra = []) use ($torneo_id): string {
    return AppHelpers::url('index.php', array_merge([
        'page' => 'torneo_gestion',
        'action' => $action,
        'torneo_id' => $torneo_id,
    ], $extra));
};

$urlPdf = static function (string $tipo) use ($torneo_id, $generoRep): string {
    return AppHelpers::url('index.php', [
        'page' => 'torneo_gestion',
        'action' => 'export_resultados_pdf',
        'torneo_id' => $torneo_id,
        'tipo' => $tipo,
        'genero' => $generoRep,
    ]);
};
$urlExcel = AppHelpers::url('index.php', [
    'page' => 'torneo_gestion',
    'action' => 'export_resultados_excel',
    'torneo_id' => $torneo_id,
    'genero' => $generoRep,
]);
$urlPrint = static function (string $tipo) use ($tg, $generoRep): string {
    return $tg('resultados_reportes_print', ['tipo' => $tipo, 'genero' => $generoRep]);
};
$urlPanel = $tg('panel');

$bloques = [
    ['tipo' => 'posiciones', 'titulo' => 'Clasificación general', 'desc' => 'Tabla de posiciones (individual).', 'action_origen' => 'posiciones', 'siempre' => true],
    ['tipo' => 'clubes_resumido', 'titulo' => 'Clubes — resumido', 'desc' => 'Solo tabla resumen por club (top pareclub).', 'action_origen' => 'resultados_por_club', 'siempre' => true],
    ['tipo' => 'clubes_detallado', 'titulo' => 'Clubes — detallado', 'desc' => 'Jugadores por club (sin tabla resumen).', 'action_origen' => 'resultados_por_club', 'siempre' => true],
    ['tipo' => 'general', 'titulo' => 'Clasificación con equipos', 'desc' => 'Misma vista que Resultados general (modalidad equipos).', 'action_origen' => 'resultados_general', 'siempre' => false],
    ['tipo' => 'equipos_resumido', 'titulo' => 'Equipos — resumido', 'desc' => 'Tabla de equipos.', 'action_origen' => 'resultados_equipos_resumido', 'siempre' => false],
    ['tipo' => 'equipos_detallado', 'titulo' => 'Equipos — detallado', 'desc' => 'Por equipo + jugadores.', 'action_origen' => 'resultados_equipos_detallado', 'siempre' => false],
    ['tipo' => 'consolidado', 'titulo' => 'Reporte consolidado', 'desc' => 'Rondas, equipos, clubes y clasificación.', 'action_origen' => 'resultados_reportes', 'siempre' => true],
];
?>

<link rel="stylesheet" href="assets/dist/output.css">

<div class="min-h-screen bg-gradient-to-br from-slate-700 via-slate-800 to-slate-900 p-6">
    <div class="mb-4">
        <a href="<?= htmlspecialchars($urlPanel) ?>" class="inline-flex items-center px-5 py-2.5 bg-amber-200 hover:bg-amber-300 text-black font-bold rounded-lg border border-gray-800">
            <i class="fas fa-arrow-left mr-2"></i> Volver al panel
        </a>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-5xl mx-auto mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-file-alt text-amber-600 mr-2"></i> Reportes de resultados</h1>
        <p class="text-gray-700 font-medium"><?= htmlspecialchars($torneo['nombre'] ?? '') ?></p>
        <p class="text-sm text-gray-600 mt-2">Impresión y PDF en formato <strong class="text-black">Letter</strong>. Cada bloque es un reporte independiente.</p>
        <?php
        $urlGenRep = static function (string $g) use ($tg): string {
            return $tg('resultados_reportes', ['genero' => $g]);
        };
        $urlGeneroFn = $urlGenRep;
        $generoActual = $generoRep;
        include __DIR__ . '/../../public/includes/partials/fvd_reporte_genero_iconos.php';
        ?>
        <div class="mt-4 p-4 bg-green-50 border border-green-300 rounded-lg">
            <strong class="text-black">Excel (varias hojas):</strong>
            <a href="<?= htmlspecialchars($urlExcel) ?>" class="ml-2 text-black font-bold underline">Descargar Excel</a>
        </div>
        <div class="mt-4 p-4 bg-amber-50 border-2 border-amber-600 rounded-lg">
            <strong class="text-black"><i class="fas fa-layer-group mr-1"></i> Todos los reportes de clasificación y ranking</strong>
            <p class="text-sm text-gray-800 mt-2 mb-3">Un solo documento con posiciones, clubes, <?php if ($esEquipos): ?>equipos, <?php endif; ?>consolidado, etc. (mismo orden que el índice).</p>
            <div class="flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars($urlPrint('todos')) ?>" target="_blank" rel="noopener" class="inline-flex items-center px-4 py-2 bg-blue-200 text-black font-bold rounded-lg border border-blue-800 text-sm">
                    <i class="fas fa-print mr-2"></i> Imprimir / vista (todos)
                </a>
                <a href="<?= htmlspecialchars($urlPdf('todos')) ?>" class="inline-flex items-center px-4 py-2 bg-red-200 text-black font-bold rounded-lg border border-red-800 text-sm" target="_blank" rel="noopener">
                    <i class="fas fa-file-pdf mr-2"></i> Descargar PDF (todos)
                </a>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-lg p-6 max-w-5xl mx-auto mb-4 border-2 border-amber-300">
        <h2 class="text-lg font-bold text-gray-900 mb-1"><i class="fas fa-id-card text-amber-600 mr-2"></i>Sanciones por ronda (tarjetas)</h2>
        <p class="text-sm text-gray-600 mb-4">Listado de jugadores con tarjeta amarilla, roja o negra, indicando la ronda en que ocurrió (A / R / N).</p>
        <a href="<?= htmlspecialchars($tg('reporte_sanciones_ronda')) ?>" class="inline-flex items-center px-4 py-2 bg-amber-200 text-black font-bold rounded-lg border border-amber-700 text-sm">
            <i class="fas fa-table mr-2"></i> Ver reporte
        </a>
    </div>

    <?php foreach ($bloques as $b):
        if (!$b['siempre'] && !$esEquipos && in_array($b['tipo'], ['general', 'equipos_resumido', 'equipos_detallado'], true)) {
            continue;
        }
        $origen = $tg($b['action_origen']);
        $idx = $tg('resultados_reportes', ['genero' => $generoRep]);
    ?>
    <div class="bg-white rounded-xl shadow-lg p-6 max-w-5xl mx-auto mb-4 border-2 border-gray-200">
        <h2 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars($b['titulo']) ?></h2>
        <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($b['desc']) ?></p>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="<?= htmlspecialchars($origen) ?>" class="inline-flex items-center px-4 py-2 bg-gray-200 text-black font-bold rounded-lg border border-gray-700 text-sm">Volver al origen</a>
            <a href="<?= htmlspecialchars($urlPdf($b['tipo'])) ?>" class="inline-flex items-center px-4 py-2 bg-red-200 text-black font-bold rounded-lg border border-red-600 text-sm" target="_blank" rel="noopener">PDF</a>
            <a href="<?= htmlspecialchars($urlPrint($b['tipo'])) ?>" target="_blank" rel="noopener" class="inline-flex items-center px-4 py-2 bg-blue-200 text-black font-bold rounded-lg border border-blue-700 text-sm">Imprimir / vista</a>
            <a href="<?= htmlspecialchars($idx) ?>" class="inline-flex items-center px-4 py-2 bg-amber-100 text-black font-bold rounded-lg border border-amber-700 text-sm">Índice reportes</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
