<?php
/**
 * Página de Podios del Torneo
 * Muestra los podios usando la misma metodología del reporte de posiciones
 */

require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/PartiresulEstatusSql.php';
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';

$wRegPr1Pod = \PartiresulEstatusSql::whereRegistradoUno('pr1');
$wFf0Pr1Pod = \PartiresulEstatusSql::whereFfCero('pr1');
$wFfOppPod = \PartiresulEstatusSql::whereFfUno('pr_oponente');
$wFfCompPod = \PartiresulEstatusSql::whereFfUno('pr_companero');
$wRegPrTarPod = \PartiresulEstatusSql::whereRegistradoUno('pr');

// club_responsable en tournaments es el ID de la organización (organizaciones), no de clubes
$club_responsable = null;
$club_logo_url = null;

if (!empty($torneo['club_responsable'])) {
    $stmt = $pdo->prepare("
        SELECT id, nombre, logo
        FROM organizaciones
        WHERE id = ?
    ");
    $stmt->execute([$torneo['club_responsable']]);
    $club_responsable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($club_responsable && !empty($club_responsable['logo'])) {
        $base_url = AppHelpers::getBaseUrl();
        $club_logo_url = AppHelpers::imageUrl($club_responsable['logo']);
    }
}

// Obtener TODOS los resultados usando la misma metodología del reporte de posiciones
$todos_resultados = [];
$podio = [];

try {
    // Usar la misma consulta SQL que obtenerDatosPosiciones
    $sql = "SELECT 
                i.*,
                u.nombre as nombre_completo,
                u.username,
                u.sexo,
                u.cedula,
                u.photo_path,
                c.nombre as club_nombre,
                c.nombre as nombre_club,
                c.id as club_id,
                (
                    SELECT COUNT(DISTINCT pr1.partida, pr1.mesa)
                    FROM `partiresul` pr1
                    LEFT JOIN `partiresul` pr_oponente ON pr1.id_torneo = pr_oponente.id_torneo 
                        AND pr1.partida = pr_oponente.partida 
                        AND pr1.mesa = pr_oponente.mesa
                        AND pr_oponente.id_usuario != pr1.id_usuario
                        AND (
                            (pr1.secuencia IN (1, 2) AND pr_oponente.secuencia IN (3, 4)) OR
                            (pr1.secuencia IN (3, 4) AND pr_oponente.secuencia IN (1, 2))
                        )
                    LEFT JOIN `partiresul` pr_companero ON pr1.id_torneo = pr_companero.id_torneo 
                        AND pr1.partida = pr_companero.partida 
                        AND pr1.mesa = pr_companero.mesa
                        AND pr_companero.id_usuario != pr1.id_usuario
                        AND (
                            (pr1.secuencia IN (1, 2) AND pr_companero.secuencia IN (1, 2) AND pr_companero.secuencia != pr1.secuencia) OR
                            (pr1.secuencia IN (3, 4) AND pr_companero.secuencia IN (3, 4) AND pr_companero.secuencia != pr1.secuencia)
                        )
                    WHERE pr1.id_usuario = i.id_usuario
                        AND pr1.id_torneo = ?
                        AND {$wRegPr1Pod}
                        AND {$wFf0Pr1Pod}
                        AND pr1.resultado1 = 200
                        AND pr1.efectividad = 100
                        AND pr1.resultado1 > pr1.resultado2
                        AND (
                            ({$wFfOppPod}) OR ({$wFfCompPod})
                        )
                ) as ganadas_por_forfait,
                COALESCE(
                    (SELECT MAX(pr.tarjeta) FROM partiresul pr
                     WHERE pr.id_torneo = i.torneo_id AND pr.id_usuario = i.id_usuario AND ({$wRegPrTarPod})),
                    i.tarjeta,
                    0
                ) AS tarjeta
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ?
            ORDER BY (CASE WHEN (i.estatus = 4 OR i.estatus = 'retirado') THEN 1 ELSE 0 END) ASC,
                     i.posicion ASC, i.ganados DESC, i.efectividad DESC, i.puntos DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $torneo_id]);
    $posiciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/../../lib/NumfvdHelper.php';
    $posiciones = NumfvdHelper::enriquecerFilas($posiciones);
    $genero_podio = ResultadosReporteData::generoFiltroDesdeParametro($_GET['genero'] ?? null);
    $modalidad_pod = (int) ($torneo['modalidad'] ?? 0);
    $posiciones = ResultadosReporteData::aplicarRankingPorGenero($posiciones, $genero_podio, $modalidad_pod);

    // Procesar todos los resultados
    foreach ($posiciones as $pos) {
        $posicion_actual = (int)($pos['posicion'] ?? 0);
        
        // Construir URL de foto del jugador
        $foto_url = null;
        if (!empty($pos['photo_path'])) {
            $base_url = AppHelpers::getBaseUrl();
            if (strpos($pos['photo_path'], 'upload/') === 0) {
                $foto_url = $base_url . '/' . $pos['photo_path'];
            } else {
                $foto_url = $base_url . '/uploads/photos/' . basename($pos['photo_path']);
            }
        }
        
        $jugador_data = [
            'posicion' => $posicion_actual,
            'id_usuario' => (int)$pos['id_usuario'],
            'numfvd' => $pos['numfvd'] ?? null,
            'cedula' => $pos['cedula'] ?? '',
            'nombre' => $pos['nombre_completo'] ?? $pos['nombre'] ?? 'N/A',
            'foto_url' => $foto_url,
            'club_nombre' => $pos['club_nombre'] ?? ResultadosReporteData::etiquetaSinAsociacion(),
            'club_id' => (int)($pos['club_id'] ?? 0),
            'ganados' => (int)($pos['ganados'] ?? 0),
            'perdidos' => (int)($pos['perdidos'] ?? 0),
            'efectividad' => (int)($pos['efectividad'] ?? 0),
            'puntos' => (int)($pos['puntos'] ?? 0),
            'ptosrnk' => (int)($pos['ptosrnk'] ?? 0),
            'gff' => (int)($pos['ganadas_por_forfait'] ?? 0),
            'zapato' => (int)($pos['zapato'] ?? 0),
            'chancletas' => (int)($pos['chancletas'] ?? 0),
            'sancion' => (int)($pos['sancion'] ?? 0),
            'tarjeta' => (int)($pos['tarjeta'] ?? 0)
        ];
        
        // Agregar a todos los resultados
        $todos_resultados[] = $jugador_data;
        
        // Agregar a podio si está en los 3 primeros
        if ($jugador_data['posicion'] > 0 && $jugador_data['posicion'] <= 3) {
            $podio[] = $jugador_data;
        }
    }
} catch (Exception $e) {
    error_log("Error obteniendo resultados: " . $e->getMessage());
}

// Función helper para obtener color de fondo según posición
function getPodioBgClass($posicion) {
    switch ($posicion) {
        case 1:
            return 'bg-gradient-to-r from-yellow-400 via-yellow-500 to-yellow-600'; // Dorado
        case 2:
            return 'bg-gradient-to-r from-gray-300 via-gray-400 to-gray-500'; // Plateado
        case 3:
            return 'bg-gradient-to-r from-orange-600 via-orange-700 to-orange-800'; // Bronce
        default:
            return 'bg-gray-200';
    }
}

// Función helper para obtener texto de tarjeta
function getTarjetaTexto($tarjeta) {
    switch ($tarjeta) {
        case 1:
            return '🟨 Amarilla';
        case 3:
            return '🟥 Roja';
        case 4:
            return '⬛ Negra';
        default:
            return 'Sin tarjeta';
    }
}
?>

<!-- Tailwind CSS (compilado localmente para mejor rendimiento) -->
<link rel="stylesheet" href="assets/dist/output.css">

<?php
// Obtener base URL para el botón de retorno
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
?>

<div class="min-h-screen bg-gradient-to-br from-purple-600 via-purple-700 to-indigo-800 p-6">
    <!-- Botón de retorno al panel -->
    <div class="mb-4">
        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo_id; ?>" 
           class="inline-flex items-center px-6 py-3 bg-gray-800 hover:bg-gray-900 text-white rounded-lg shadow-lg transition-all transform hover:scale-105 font-bold">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver al Panel de Control
        </a>
    </div>
    
    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-2xl p-6 mb-6">
        <div class="flex items-start justify-between">
            <!-- Logo y nombre del club a la izquierda -->
            <?php if ($club_responsable): ?>
            <div class="flex items-center gap-4 flex-shrink-0">
                <?php if ($club_logo_url): ?>
                <img src="<?= htmlspecialchars($club_logo_url) ?>" 
                     alt="<?= htmlspecialchars($club_responsable['nombre']) ?>" 
                     class="w-24 h-24 rounded-full border-4 border-purple-500 shadow-lg object-cover">
                <?php endif; ?>
                <h2 class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($club_responsable['nombre']) ?></h2>
            </div>
            <?php endif; ?>
            
            <!-- Título PODIOS y nombre del torneo centrados -->
            <div class="flex-1 flex flex-col items-center justify-center text-center">
                <!-- Título PODIOS con tamaño preponderante -->
                <div class="mb-2">
                    <h1 class="text-7xl font-extrabold text-purple-700 tracking-tight">
                        PODIOS
                    </h1>
                </div>
                
                <!-- Nombre del torneo -->
                <div class="mt-2">
                    <h3 class="text-2xl font-semibold text-gray-600">
                        <i class="fas fa-trophy mr-2"></i>
                        <?= htmlspecialchars($torneo['nombre']) ?>
                    </h3>
                </div>
            </div>
            
            <!-- Espacio vacío a la derecha para balance -->
            <div class="flex-shrink-0" style="width: 300px;"></div>
        </div>
        <div class="flex flex-wrap justify-center gap-2 mt-4">
            <?php
            $gPod = $genero_podio ?? 'M';
            $qPod = static function (string $g) use ($base_url, $use_standalone, $torneo_id): string {
                $p = ['action' => 'podios', 'torneo_id' => (int) $torneo_id, 'genero' => $g];

                return $base_url . ($use_standalone ? '?' : '&') . http_build_query($p);
            };
            $urlGeneroFn = $qPod;
            $generoActual = $gPod;
            include __DIR__ . '/../../public/includes/partials/fvd_reporte_genero_iconos.php';
            ?>
        </div>
    </div>
    
    <?php if (empty($todos_resultados)): ?>
    <div class="bg-white rounded-xl shadow-lg p-6 text-center">
        <div class="text-gray-600">
            <i class="fas fa-info-circle text-4xl mb-4"></i>
            <p class="text-lg">Aún no hay posiciones calculadas para este torneo. Los podios se mostrarán cuando se registren resultados.</p>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Botones de selección -->
    <div class="flex flex-wrap justify-center gap-3 mb-6">
        <?php if (count($podio) >= 1): ?>
        <button onclick="mostrarPodio(1)" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-yellow-400 to-yellow-600 hover:from-yellow-500 hover:to-yellow-700 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-yellow-300">
            <i class="fas fa-trophy mr-2"></i>1° Lugar
        </button>
        <?php endif; ?>
        
        <?php if (count($podio) >= 2): ?>
        <button onclick="mostrarPodio(2)" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-gray-300 to-gray-500 hover:from-gray-400 hover:to-gray-600 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-gray-300">
            <i class="fas fa-medal mr-2"></i>2° Lugar
        </button>
        <?php endif; ?>
        
        <?php if (count($podio) >= 3): ?>
        <button onclick="mostrarPodio(3)" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-orange-600 to-orange-800 hover:from-orange-700 hover:to-orange-900 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-orange-300">
            <i class="fas fa-medal mr-2"></i>3° Lugar
        </button>
        <?php endif; ?>
        
        <button onclick="mostrarTodos()" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-green-500 to-green-700 hover:from-green-600 hover:to-green-800 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-green-300">
            <i class="fas fa-th mr-2"></i>Todos los Podios
        </button>
        
        <button onclick="mostrarLista()" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-blue-500 to-blue-700 hover:from-blue-600 hover:to-blue-800 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-blue-300">
            <i class="fas fa-list mr-2"></i>Listado
        </button>
    </div>
    
    <!-- Contenedor de tarjetas de podio -->
    <div id="podioCards" class="hidden relative" style="min-height: 1600px;">
        <?php foreach ($podio as $jugador): ?>
        <div class="podio-card hidden absolute rounded-2xl shadow-2xl overflow-hidden" 
             data-posicion="<?= $jugador['posicion'] ?>"
             style="min-width: 792px; max-width: 1056px; left: 50%; transform: translateX(-50%);">
                
                <!-- Fondo degradado desde el título hasta abajo -->
                <div class="<?= getPodioBgClass($jugador['posicion']) ?> p-8" style="font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;">
                    <!-- Título de posición -->
                    <div class="text-center mb-5">
                        <h2 class="text-4xl font-extrabold drop-shadow-lg text-black tracking-wide">
                            <?php if ($jugador['posicion'] == 1): ?>
                                <i class="fas fa-trophy mr-3"></i>PRIMER LUGAR
                            <?php elseif ($jugador['posicion'] == 2): ?>
                                <i class="fas fa-medal mr-3"></i>SEGUNDO LUGAR
                            <?php else: ?>
                                <i class="fas fa-medal mr-3"></i>TERCER LUGAR
                            <?php endif; ?>
                        </h2>
                    </div>
                    
                    <!-- Contenido horizontal -->
                    <div class="flex items-start gap-6">
                        <!-- Foto del jugador - Ampliada con preferencia -->
                        <div class="flex-shrink-0">
                            <?php if ($jugador['foto_url']): ?>
                            <img src="<?= htmlspecialchars($jugador['foto_url']) ?>" 
                                 alt="<?= htmlspecialchars($jugador['nombre']) ?>" 
                                 class="w-40 h-40 rounded-full border-5 border-white shadow-2xl object-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="w-40 h-40 rounded-full border-5 border-white shadow-2xl bg-white/20 flex items-center justify-center hidden">
                                <i class="fas fa-user text-7xl text-black"></i>
                            </div>
                            <?php else: ?>
                            <div class="w-40 h-40 rounded-full border-5 border-white shadow-2xl bg-white/20 flex items-center justify-center">
                                <i class="fas fa-user text-7xl text-black"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Información del jugador -->
                        <div class="flex-1">
                            <!-- Identificador y nombre -->
                            <div class="mb-4">
                                <div class="text-2xl font-bold mb-2 text-black tracking-tight">
                                    <?= $jugador['id_usuario'] ?> - <?= strtoupper(htmlspecialchars($jugador['nombre'])) ?>
                                </div>
                                <div class="text-xl font-semibold text-black">
                                    <i class="fas fa-building mr-2"></i><?= htmlspecialchars($jugador['club_nombre']) ?>
                                </div>
                            </div>
                            
                            <!-- Estadísticas principales (misma fila) -->
                            <div class="flex gap-2.5 mb-4" style="flex-wrap: nowrap;">
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm flex-1 text-center" style="min-width: 0;">
                                    <div class="text-sm font-semibold text-black mb-1">Ganados</div>
                                    <div class="text-2xl font-extrabold text-black"><?= $jugador['ganados'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm flex-1 text-center" style="min-width: 0;">
                                    <div class="text-sm font-semibold text-black mb-1">Perdidos</div>
                                    <div class="text-2xl font-extrabold text-black"><?= $jugador['perdidos'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm flex-1 text-center" style="min-width: 0;">
                                    <div class="text-sm font-semibold text-black mb-1">Efectividad</div>
                                    <div class="text-2xl font-extrabold text-black"><?= $jugador['efectividad'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm flex-1 text-center" style="min-width: 0;">
                                    <div class="text-sm font-semibold text-black mb-1">Puntos</div>
                                    <div class="text-2xl font-extrabold text-black"><?= $jugador['puntos'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm flex-1 text-center" style="min-width: 0;">
                                    <div class="text-sm font-semibold text-black mb-1">Ranking</div>
                                    <div class="text-2xl font-extrabold text-black"><?= $jugador['ptosrnk'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm flex-1 text-center" style="min-width: 0;">
                                    <div class="text-sm font-semibold text-black mb-1">GFF</div>
                                    <div class="text-2xl font-extrabold text-black"><?= $jugador['gff'] ?></div>
                                </div>
                            </div>
                            
                            <!-- Separador -->
                            <div class="border-t-3 border-black/40 my-4"></div>
                            
                            <!-- Estadísticas secundarias -->
                            <div class="flex flex-wrap gap-3">
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm text-center flex-1 min-w-[85px]">
                                    <div class="text-sm font-semibold text-black mb-1">Zapato</div>
                                    <div class="text-xl font-extrabold text-black"><?= $jugador['zapato'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm text-center flex-1 min-w-[85px]">
                                    <div class="text-sm font-semibold text-black mb-1">Chancletas</div>
                                    <div class="text-xl font-extrabold text-black"><?= $jugador['chancletas'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm text-center flex-1 min-w-[85px]">
                                    <div class="text-sm font-semibold text-black mb-1">Sanciones</div>
                                    <div class="text-xl font-extrabold text-black"><?= $jugador['sancion'] ?></div>
                                </div>
                                <div class="bg-white/25 rounded-lg p-2.5 backdrop-blur-sm text-center flex-1 min-w-[85px]">
                                    <div class="text-sm font-semibold text-black mb-1">Tarjeta</div>
                                    <div class="text-base font-extrabold text-black"><?= getTarjetaTexto($jugador['tarjeta']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Lista completa de resultados -->
    <div id="listaResultados" class="hidden bg-white rounded-2xl shadow-2xl p-6">
        <h3 class="text-3xl font-bold text-purple-700 mb-6 text-center">
            <i class="fas fa-list mr-3"></i>Clasificación Completa del Torneo
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gradient-to-r from-purple-600 to-indigo-700 text-white">
                        <th class="px-4 py-3 text-left font-bold">Pos</th>
                        <th class="px-4 py-3 text-center font-bold"><?= htmlspecialchars(ResultadosReporteData::etiquetaColumnaIdentificador(false)) ?></th>
                        <th class="px-4 py-3 text-left font-bold">Jugador</th>
                        <th class="px-4 py-3 text-left font-bold"><?= htmlspecialchars(ResultadosReporteData::etiquetaAsociacion()) ?></th>
                        <th class="px-4 py-3 text-center font-bold">G</th>
                        <th class="px-4 py-3 text-center font-bold">P</th>
                        <th class="px-4 py-3 text-center font-bold">GFF</th>
                        <th class="px-4 py-3 text-center font-bold">Efect.</th>
                        <th class="px-4 py-3 text-center font-bold">Puntos</th>
                        <th class="px-4 py-3 text-center font-bold">Pts. Rnk</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todos_resultados as $index => $jugador): ?>
                    <tr class="border-b border-gray-200 hover:bg-gray-50 <?= $jugador['posicion'] <= 3 ? ($jugador['posicion'] == 1 ? 'bg-yellow-50' : ($jugador['posicion'] == 2 ? 'bg-gray-100' : 'bg-orange-50')) : '' ?>">
                        <td class="px-4 py-3 font-bold text-center">
                            <?= $jugador['posicion'] ?>
                            <?php if ($jugador['posicion'] == 1): ?>
                                <i class="fas fa-trophy text-yellow-500 ml-1"></i>
                            <?php elseif ($jugador['posicion'] <= 3): ?>
                                <i class="fas fa-medal text-gray-500 ml-1"></i>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <code><?= htmlspecialchars(ResultadosReporteData::textoIdentificadorFila($jugador, false)) ?></code>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold"><?= htmlspecialchars($jugador['nombre']) ?></div>
                        </td>
                        <td class="px-4 py-3"><?= htmlspecialchars($jugador['club_nombre'] ?? ResultadosReporteData::etiquetaSinAsociacion()) ?></td>
                        <td class="px-4 py-3 text-center font-bold"><?= $jugador['ganados'] ?></td>
                        <td class="px-4 py-3 text-center"><?= $jugador['perdidos'] ?></td>
                        <td class="px-4 py-3 text-center"><?= $jugador['gff'] ?></td>
                        <td class="px-4 py-3 text-center"><?= $jugador['efectividad'] ?></td>
                        <td class="px-4 py-3 text-center"><?= $jugador['puntos'] ?></td>
                        <td class="px-4 py-3 text-center font-bold text-purple-600"><?= $jugador['ptosrnk'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<script>
function mostrarPodio(posicion) {
    // Ocultar lista
    document.getElementById('listaResultados').classList.add('hidden');
    
    // Mostrar contenedor de tarjetas
    const podioCards = document.getElementById('podioCards');
    podioCards.classList.remove('hidden');
    
    // Ocultar todas las tarjetas
    document.querySelectorAll('.podio-card').forEach(card => {
        card.classList.add('hidden');
    });
    
    // Mostrar solo la tarjeta del podio seleccionado, centrada
    const tarjeta = document.querySelector(`.podio-card[data-posicion="${posicion}"]`);
    if (tarjeta) {
        tarjeta.classList.remove('hidden');
        tarjeta.style.left = '50%';
        tarjeta.style.top = '0px';
        tarjeta.style.transform = 'translateX(-50%)';
    }
}

function mostrarTodos() {
    // Ocultar lista
    document.getElementById('listaResultados').classList.add('hidden');
    
    // Mostrar contenedor de tarjetas
    const podioCards = document.getElementById('podioCards');
    podioCards.classList.remove('hidden');
    
    // Obtener todas las tarjetas ordenadas por posición
    const tarjetas = Array.from(document.querySelectorAll('.podio-card[data-posicion]'))
        .filter(card => card.dataset.posicion && ['1', '2', '3'].includes(card.dataset.posicion))
        .sort((a, b) => parseInt(a.dataset.posicion) - parseInt(b.dataset.posicion));
    
    // Primero mostrar todas las tarjetas para que se calculen sus alturas
    tarjetas.forEach(card => {
        card.classList.remove('hidden');
        card.style.left = '50%';
        card.style.transform = 'translateX(-50%)';
    });
    
    // Esperar un momento para que se calculen las alturas y luego posicionar con separación de 9rem
    setTimeout(() => {
        let currentTop = 0;
        const spacing = 9 * 16; // 9rem = 144px (6rem + 50% = 9rem)
        
        tarjetas.forEach((card) => {
            card.style.top = currentTop + 'px';
            // Calcular la posición de la siguiente tarjeta: altura actual + 3rem de separación
            currentTop += card.offsetHeight + spacing;
        });
    }, 50);
}

function mostrarLista() {
    // Ocultar tarjetas
    const podioCards = document.getElementById('podioCards');
    podioCards.classList.add('hidden');
    
    // Ocultar todas las tarjetas
    document.querySelectorAll('.podio-card').forEach(card => {
        card.classList.add('hidden');
    });
    
    // Mostrar lista
    document.getElementById('listaResultados').classList.remove('hidden');
}
</script>
