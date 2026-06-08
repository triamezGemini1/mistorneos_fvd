<?php
/**
 * Resultados del Torneo por Equipos - Detallado
 * Muestra resultados detallados agrupados por equipo con rompe control
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
    $equipos = \Tournament\Handlers\TeamPerformanceHandler::getRankingPorEquipos((int) $torneo_id, 'detallado');
} catch (\Exception $e) {
    error_log('Error obteniendo resultados detallados de equipos: ' . $e->getMessage());
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

// Función helper para obtener texto de tarjeta
function getTarjetaTexto($tarjeta) {
    switch ((int)$tarjeta) {
        case 1: return '🟨 Amarilla';
        case 3: return '🟥 Roja';
        case 4: return '⬛ Negra';
        default: return 'Sin tarjeta';
    }
}
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
                        <i class="fas fa-list-ul text-purple-600 mr-2"></i>
                        Resultados por Equipos - Detallado
                    </h1>
                    <h2 class="text-xl text-gray-600"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></h2>
                    <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                        <span><i class="fas fa-calendar-alt mr-1"></i> <?php echo date('d/m/Y', strtotime($torneo['fechator'] ?? 'now')); ?></span>
                        <span><i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($club_responsable['nombre'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-right flex flex-wrap gap-2 justify-end">
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'export_resultados_pdf', 'torneo_id' => $torneo_id, 'tipo' => 'equipos_detallado'])); ?>"
                   class="px-4 py-3 bg-amber-200 text-black font-bold rounded-lg border border-black text-sm">PDF Letter</a>
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes_print', 'torneo_id' => $torneo_id, 'tipo' => 'equipos_detallado'])); ?>" target="_blank" rel="noopener"
                   class="px-4 py-3 bg-slate-200 text-black font-bold rounded-lg border border-black text-sm">Vista impresión</a>
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes', 'torneo_id' => $torneo_id])); ?>"
                   class="px-4 py-3 bg-green-200 text-black font-bold rounded-lg border border-black text-sm">Todos los reportes</a>
                <button onclick="window.print()" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold">
                    <i class="fas fa-print mr-2"></i> Imprimir página
                </button>
            </div>
        </div>
    </div>
    
    <!-- Vista Detallada con Rompe Control por Equipo -->
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-4">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-white">
                    <i class="fas fa-list-ul mr-2"></i> Resultados Detallados por Equipo
                </h3>
                <div class="flex gap-2">
                    <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=resultados_equipos_resumido&torneo_id=<?php echo $torneo_id; ?>&vista=resumen" 
                       class="px-4 py-2 rounded-lg bg-purple-500 text-white hover:bg-purple-400 font-semibold transition-all">
                        Resumen
                    </a>
                    <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=resultados_equipos_detallado&torneo_id=<?php echo $torneo_id; ?>&vista=detallada" 
                       class="px-4 py-2 rounded-lg bg-white text-purple-600 font-semibold transition-all">
                        Detallado
                    </a>
                </div>
            </div>
        </div>
        
        <div class="p-6">
            <?php 
            foreach ($equipos as $equipo): 
                $posicion_display = $equipo['posicion'] > 0 ? $equipo['posicion'] : '-';
            ?>
                <!-- Rompe Control por Equipo -->
                <div class="mb-8 border-l-4 border-purple-600 bg-gradient-to-r from-purple-50 to-indigo-50 rounded-lg p-4 shadow-md" style="page-break-inside: avoid;">
                    <!-- Subtítulos del Equipo (PRIMERO) -->
                    <div class="mb-4 bg-purple-200 rounded-lg p-4 border-b-2 border-purple-400">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <div class="flex items-center gap-3 text-base">
                                <span class="bg-purple-600 text-white px-3 py-1 rounded font-bold">
                                    Pos. <?php echo $equipo['posicion'] > 0 ? $equipo['posicion'] : '-'; ?>
                                </span>
                                <span class="font-mono font-bold text-purple-900 text-lg"><?php echo htmlspecialchars($equipo['codigo_equipo']); ?></span>
                                <span class="text-purple-700">-</span>
                                <span class="font-bold text-purple-900 text-lg"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></span>
                                <span class="text-purple-700">-</span>
                                <span class="text-purple-800"><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($equipo['club_nombre']); ?></span>
                            </div>
                        </div>
                        
                        <!-- Estadísticas del equipo -->
                        <div class="mt-3 bg-purple-100 rounded p-3 border border-purple-300">
                            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2 text-sm">
                                <div class="text-green-700 font-semibold">
                                    <span class="font-bold">G:</span> <?php echo $equipo['ganados']; ?>
                                </div>
                                <div class="text-red-700 font-semibold">
                                    <span class="font-bold">P:</span> <?php echo $equipo['perdidos']; ?>
                                </div>
                                <div class="text-blue-700 font-semibold">
                                    <span class="font-bold">Efect:</span> <?php echo $equipo['efectividad']; ?>
                                </div>
                                <div class="text-purple-700 font-semibold">
                                    <span class="font-bold">Pts:</span> <?php echo $equipo['puntos']; ?>
                                </div>
                                <div class="text-indigo-700 font-semibold">
                                    <?php 
                                    $total_ptosrnk_equipo = 0;
                                    foreach ($equipo['jugadores'] as $j) {
                                        $total_ptosrnk_equipo += (int)($j['ptosrnk'] ?? 0);
                                    }
                                    echo '<span class="font-bold">Pts. Rnk:</span> ' . $total_ptosrnk_equipo;
                                    ?>
                                </div>
                                <div class="text-gray-700 font-semibold">
                                    <span class="font-bold">GFF:</span> <?php echo $equipo['gff']; ?>
                                </div>
                                <div class="text-gray-700 font-semibold">
                                    <span class="font-bold">Sanc:</span> <?php echo $equipo['sancion']; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tabla con headers de columna (DESPUÉS de los subtítulos) -->
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-purple-600 text-white">
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">Pos. Torneo</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold"><?php echo htmlspecialchars(ResultadosReporteData::etiquetaColumnaIdentificador(false)); ?></th>
                                    <th class="border border-purple-700 px-3 py-2 text-left font-bold">Jugador</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">G</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">P</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">Efec.</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">Puntos</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">Pts. Rnk.</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">GFF</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">Sanc.</th>
                                    <th class="border border-purple-700 px-3 py-2 text-center font-bold">Tarj.</th>
                                </tr>
                            </thead>
                            <tbody>
                                
                                <!-- Filas de jugadores del equipo obtenidos desde inscritos usando codigo_equipo -->
                                <?php 
                                // Calcular subtotales ANTES del loop sumando los valores de todos los jugadores
                                // NOTA: gff no existe en inscritos, solo se usa el valor de la tabla equipos
                                $subtotal_ganados = 0;
                                $subtotal_perdidos = 0;
                                $subtotal_efectividad = 0;
                                $subtotal_puntos = 0;
                                $subtotal_ptosrnk = 0;
                                $subtotal_sancion = 0;
                                
                                // Calcular subtotales primero (solo campos que existen en inscritos)
                                foreach ($equipo['jugadores'] as $j) {
                                    $subtotal_ganados += (int)($j['ganados'] ?? 0);
                                    $subtotal_perdidos += (int)($j['perdidos'] ?? 0);
                                    $subtotal_efectividad += (int)($j['efectividad'] ?? 0);
                                    $subtotal_puntos += (int)($j['puntos'] ?? 0);
                                    $subtotal_ptosrnk += (int)($j['ptosrnk'] ?? 0);
                                    $subtotal_sancion += (int)($j['sancion'] ?? 0);
                                }
                                
                                // gff solo existe en tabla equipos, no se suma de jugadores
                                $subtotal_gff = $equipo['gff'] ?? 0;
                                
                                // Los jugadores ya vienen ordenados por clasificación dentro del equipo (ganados DESC, efectividad DESC, puntos DESC)
                                // Mostrar posición en el torneo (no dentro del equipo)
                                $jdet = 0;
                                foreach ($equipo['jugadores'] as $jugador): 
                                    $jdet++;
                                    $posicion_torneo = (int)($jugador['posicion'] ?? 0);
                                    $nombre_jugador = htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A');
                                    $id_usuario = (int)($jugador['id_usuario'] ?? 0);
                                    
                                    // Obtener base URL para el link
                                    $base_url_link = $base_url_return;
                                    $action_param = $use_standalone ? '?' : '&';
                                ?>
                                    <tr class="hover:bg-purple-50 border-b border-purple-100 <?= ($jdet % 2 === 0) ? 'bg-violet-50/60' : '' ?>">
                                        <td class="border border-purple-200 px-3 py-2 text-center font-semibold">
                                            <?php echo $posicion_torneo > 0 ? $posicion_torneo : '-'; ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center">
                                            <code><?php echo htmlspecialchars(ResultadosReporteData::textoIdentificadorFila($jugador, false)); ?></code>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-gray-800">
                                            <?php 
                                            if ($id_usuario > 0) {
                                                $url_resumen = $base_url_link . $action_param . 'action=resumen_individual&torneo_id=' . $torneo_id . '&inscrito_id=' . $id_usuario . '&from=resultados_equipos_detallado';
                                                echo '<a href="' . htmlspecialchars($url_resumen) . '" class="text-purple-600 hover:text-purple-800 hover:underline font-semibold">' . $nombre_jugador . ' <i class="fas fa-external-link-alt text-xs"></i></a>';
                                            } else {
                                                echo $nombre_jugador;
                                            }
                                            ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center font-semibold text-green-600">
                                            <?php echo (int)($jugador['ganados'] ?? 0); ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center font-semibold text-red-600">
                                            <?php echo (int)($jugador['perdidos'] ?? 0); ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center font-semibold text-blue-600">
                                            <?php echo (int)($jugador['efectividad'] ?? 0); ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center font-bold text-purple-600">
                                            <?php echo (int)($jugador['puntos'] ?? 0); ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center font-bold text-indigo-600">
                                            <?php echo (int)($jugador['ptosrnk'] ?? 0); ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center">
                                            <?php echo '-'; // gff no existe en inscritos, solo en equipos ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center">
                                            <?php echo (int)($jugador['sancion'] ?? 0); ?>
                                        </td>
                                        <td class="border border-purple-200 px-3 py-2 text-center text-xs">
                                            <?php echo getTarjetaTexto($jugador['tarjeta'] ?? 0); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- Fila de resumen total del equipo: subtotal calculado sumando jugadores (debe coincidir con tabla equipos) -->
                                <tr class="bg-purple-300 font-bold border-t-2 border-purple-500">
                                    <td class="border border-purple-400 px-3 py-2 text-center text-purple-900" colspan="3">
                                        RESUMEN TOTAL EQUIPO (Suma jugadores)
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-green-800">
                                        <?php echo $subtotal_ganados; ?>
                                        <?php if ($subtotal_ganados != $equipo['ganados']): ?>
                                            <span class="text-red-600 text-xs" title="Tabla equipos: <?php echo $equipo['ganados']; ?>">⚠</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-red-800">
                                        <?php echo $subtotal_perdidos; ?>
                                        <?php if ($subtotal_perdidos != $equipo['perdidos']): ?>
                                            <span class="text-red-600 text-xs" title="Tabla equipos: <?php echo $equipo['perdidos']; ?>">⚠</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-blue-800">
                                        <?php echo $subtotal_efectividad; ?>
                                        <?php if ($subtotal_efectividad != $equipo['efectividad']): ?>
                                            <span class="text-red-600 text-xs" title="Tabla equipos: <?php echo $equipo['efectividad']; ?>">⚠</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-purple-900">
                                        <?php echo $subtotal_puntos; ?>
                                        <?php if ($subtotal_puntos != $equipo['puntos']): ?>
                                            <span class="text-red-600 text-xs" title="Tabla equipos: <?php echo $equipo['puntos']; ?>">⚠</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-indigo-800">
                                        <?php echo $subtotal_ptosrnk; ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-purple-900">
                                        <?php echo $equipo['gff']; // gff solo existe en tabla equipos ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-purple-900">
                                        <?php echo $subtotal_sancion; ?>
                                        <?php if ($subtotal_sancion != $equipo['sancion']): ?>
                                            <span class="text-red-600 text-xs" title="Tabla equipos: <?php echo $equipo['sancion']; ?>">⚠</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="border border-purple-400 px-3 py-2 text-center text-purple-900">
                                        -
                                    </td>
                                </tr>
                                
                                <!-- Fila adicional mostrando valores de tabla equipos para comparación -->
                                <tr class="bg-purple-100 font-semibold border-t border-purple-300 text-xs">
                                    <td class="border border-purple-300 px-3 py-1 text-center text-purple-700" colspan="3">
                                        Valores Tabla Equipos:
                                    </td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-green-700"><?php echo $equipo['ganados']; ?></td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-red-700"><?php echo $equipo['perdidos']; ?></td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-blue-700"><?php echo $equipo['efectividad']; ?></td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-purple-700"><?php echo $equipo['puntos']; ?></td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-indigo-700">-</td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-purple-700"><?php echo $equipo['gff']; ?></td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-purple-700"><?php echo $equipo['sancion']; ?></td>
                                    <td class="border border-purple-300 px-3 py-1 text-center text-purple-700">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php if (empty($equipo['jugadores'])): ?>
                        <div class="text-center py-4 text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-2"></i>
                            Este equipo no tiene jugadores registrados
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <?php if ($total_equipos_ranking === 0): ?>
                <div class="bg-gray-50 rounded-lg p-8 text-center">
                    <i class="fas fa-info-circle text-4xl text-gray-400 mb-4"></i>
                    <p class="text-lg text-gray-600">No hay equipos registrados en este torneo</p>
                </div>
            <?php elseif ($total_equipos_ranking > 0): ?>
                <?php
                echo ResultadosReportePaginacion::renderForTorneoReport(
                    (int) $pagina_actual,
                    (int) $total_paginas,
                    (int) $total_equipos_ranking,
                    (int) $items_por_pagina,
                    $base_url_return,
                    $use_standalone,
                    ['action' => 'resultados_equipos_detallado', 'torneo_id' => $torneo_id],
                    'equipos'
                );
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
@media print {
    body { margin: 0; padding: 0; }
    .bg-gradient-to-br { background: white !important; }
    .mb-4, .mb-6, .mb-8 { margin-bottom: 1rem !important; }
    .p-6 { padding: 1rem !important; }
    button, a[onclick], a[href*="action="] { display: none !important; }
    /* Rompe control por equipo */
    div[style*="page-break-inside: avoid"] {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    /* Asegurar que cada equipo empiece en nueva página si no cabe */
    .border-l-4.border-purple-600 {
        page-break-before: auto;
        page-break-after: auto;
    }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; }
}
</style>


