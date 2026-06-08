<?php
/**
 * Resultados del Torneo por Equipos - Resumido
 * Muestra estadísticas resumidas agrupadas por equipo
 * Similar a resultados_por_club pero agrupado por codigo_equipo
 */

require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/Tournament/Handlers/TeamPerformanceHandler.php';
require_once __DIR__ . '/../../lib/Tournament/Services/PaginationService.php';
require_once __DIR__ . '/../../lib/ResultadosReportePaginacion.php';
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';

$items_por_pagina = ResultadosReportePaginacion::PER_PAGE;
$pagina_raw = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;

$pdo = DB::pdo();

try {
    $equipos = \Tournament\Handlers\TeamPerformanceHandler::getRankingPorEquipos((int) $torneo_id, 'puntos');
} catch (\Exception $e) {
    error_log('Error obteniendo resultados de equipos: ' . $e->getMessage());
    $equipos = [];
}

$total_equipos_ranking = count($equipos);
$p_pag = \Tournament\Services\PaginationService::getParams($total_equipos_ranking, $pagina_raw, $items_por_pagina);
$pagina_actual = $p_pag['page'];
$total_paginas = $p_pag['total_pages'];
$equipos = array_slice($equipos, $p_pag['offset'], $p_pag['per_page']);

// Obtener información del club responsable con logo
$club_responsable = null;
$club_logo_url = null;

if (!empty($torneo['club_responsable'])) {
    $stmt = $pdo->prepare("
        SELECT id, nombre, logo, delegado
        FROM clubes
        WHERE id = ?
    ");
    $stmt->execute([$torneo['club_responsable']]);
    $club_responsable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($club_responsable && !empty($club_responsable['logo'])) {
        $base_url = AppHelpers::getBaseUrl();
        $club_logo_url = AppHelpers::imageUrl($club_responsable['logo']);
    }
}

// Función helper para obtener URL del logo del club
function getClubLogoUrl($logo) {
    if (empty($logo)) return null;
    return AppHelpers::imageUrl($logo);
}

// Determinar vista
$vista = $_GET['vista'] ?? 'resumen';
?>

<!-- Tailwind CSS (compilado localmente para mejor rendimiento) -->
<link rel="stylesheet" href="assets/dist/output.css">

<?php
// Obtener base URL para el botón de retorno
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url_return = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
?>

<div class="min-h-screen bg-gradient-to-br from-purple-600 via-purple-700 to-indigo-800 p-6">
    <!-- Botón de retorno al panel -->
    <div class="mb-4">
        <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo_id; ?>" 
           class="inline-flex items-center px-6 py-3 bg-gray-800 hover:bg-gray-900 text-white rounded-lg shadow-lg transition-all transform hover:scale-105 font-bold">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver al Panel de Control
        </a>
    </div>
    
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-2xl p-6 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <?php if ($club_logo_url): ?>
                    <img src="<?php echo htmlspecialchars($club_logo_url); ?>" 
                         alt="<?php echo htmlspecialchars($club_responsable['nombre'] ?? ''); ?>" 
                         class="w-20 h-20 object-contain rounded-lg">
                <?php endif; ?>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">
                        <i class="fas fa-users text-purple-600 mr-2"></i>
                        Resultados por Equipos - Resumido
                    </h1>
                    <h2 class="text-xl text-gray-600"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></h2>
                    <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                        <span><i class="fas fa-calendar-alt mr-1"></i> <?php echo date('d/m/Y', strtotime($torneo['fechator'] ?? 'now')); ?></span>
                        <span><i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($club_responsable['nombre'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-right flex flex-wrap gap-2 justify-end">
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'export_resultados_pdf', 'torneo_id' => $torneo_id, 'tipo' => 'equipos_resumido'])); ?>"
                   class="px-4 py-3 bg-amber-200 hover:bg-amber-300 text-black font-bold rounded-lg border border-gray-800 text-sm">PDF Letter</a>
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes_print', 'torneo_id' => $torneo_id, 'tipo' => 'equipos_resumido'])); ?>" target="_blank" rel="noopener"
                   class="px-4 py-3 bg-slate-200 hover:bg-slate-300 text-black font-bold rounded-lg border border-gray-800 text-sm">Vista impresión</a>
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes', 'torneo_id' => $torneo_id])); ?>"
                   class="px-4 py-3 bg-green-200 text-black font-bold rounded-lg border border-gray-800 text-sm">Todos los reportes</a>
                <button onclick="window.print()" 
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-lg font-bold">
                    <i class="fas fa-print mr-2"></i> Imprimir página
                </button>
            </div>
        </div>
    </div>
    
    <!-- Vista Resumida -->
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-4">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-white">
                    <i class="fas fa-list mr-2"></i> Resumen por Equipos
                </h3>
                <div class="flex gap-2">
                    <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=resultados_equipos_resumido&torneo_id=<?php echo $torneo_id; ?>&vista=resumen" 
                       class="px-4 py-2 rounded-lg <?php echo $vista === 'resumen' ? 'bg-white text-purple-600' : 'bg-purple-500 text-white hover:bg-purple-400'; ?> font-semibold transition-all">
                        Resumen
                    </a>
                    <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=resultados_equipos_detallado&torneo_id=<?php echo $torneo_id; ?>&vista=detallada" 
                       class="px-4 py-2 rounded-lg <?php echo $vista === 'detallada' ? 'bg-white text-purple-600' : 'bg-purple-500 text-white hover:bg-purple-400'; ?> font-semibold transition-all">
                        Detallado
                    </a>
                </div>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700">Pos.</th>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700">Código</th>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700">Equipo</th>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700"><?php echo htmlspecialchars(\ResultadosReporteData::etiquetaAsociacion()); ?></th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Jug.</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">G</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">P</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Efect.</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Puntos</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Sanc.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $eq_row = 0;
                    foreach ($equipos as $equipo): 
                        $eq_row++;
                        $posicion_display = $equipo['posicion'] > 0 ? $equipo['posicion'] : '-';
                        $stripeEq = ($eq_row % 2 === 0) ? 'bg-slate-50' : '';
                    ?>
                        <tr class="hover:bg-gray-50 <?= $stripeEq ?>">
                            <td class="border border-gray-300 px-4 py-3 font-bold text-gray-800"><?php echo $posicion_display; ?></td>
                            <td class="border border-gray-300 px-4 py-3 font-mono text-gray-700"><?php echo htmlspecialchars($equipo['codigo_equipo']); ?></td>
                            <td class="border border-gray-300 px-4 py-3 font-semibold text-gray-800">
                                <?php echo htmlspecialchars($equipo['nombre_equipo']); ?>
                                <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=equipos_detalle&torneo_id=<?php echo $torneo_id; ?>&equipo_codigo=<?php echo urlencode($equipo['codigo_equipo']); ?>" 
                                   class="ml-2 text-purple-600 hover:text-purple-800 hover:underline text-sm"
                                   title="Ver detalle del equipo">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-gray-700"><?php echo htmlspecialchars($equipo['club_nombre']); ?></td>
                            <td class="border border-gray-300 px-4 py-3 text-center text-gray-700"><?php echo $equipo['total_jugadores']; ?></td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-semibold text-green-600"><?php echo $equipo['ganados']; ?></td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-semibold text-red-600"><?php echo $equipo['perdidos']; ?></td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-semibold text-blue-600"><?php echo $equipo['efectividad']; ?></td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-bold text-purple-600"><?php echo $equipo['puntos']; ?></td>
                            <td class="border border-gray-300 px-4 py-3 text-center text-gray-600"><?php echo $equipo['sancion']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($equipos)): ?>
                        <tr>
                            <td colspan="10" class="border border-gray-300 px-4 py-8 text-center text-gray-500">
                                <i class="fas fa-info-circle mr-2"></i>
                                No hay equipos registrados en este torneo
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginador -->
        <?php
        if ($total_equipos_ranking > 0) {
            echo ResultadosReportePaginacion::renderForTorneoReport(
                (int) $pagina_actual,
                (int) $total_paginas,
                (int) $total_equipos_ranking,
                (int) $items_por_pagina,
                $base_url_return,
                $use_standalone,
                ['action' => 'resultados_equipos_resumido', 'torneo_id' => $torneo_id],
                'equipos'
            );
        }
        ?>
    </div>
</div>

<style>
@media print {
    body { margin: 0; padding: 0; }
    .bg-gradient-to-br { background: white !important; }
    .mb-4, .mb-6 { margin-bottom: 1rem !important; }
    .p-6 { padding: 1rem !important; }
    button, a[onclick] { display: none !important; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
}
</style>


