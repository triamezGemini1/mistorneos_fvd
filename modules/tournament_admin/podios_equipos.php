<?php
/**
 * Página de Podios de Equipos del Torneo
 * Muestra los podios de equipos con información de cada jugador y estadísticas del equipo
 */

require_once __DIR__ . '/../../lib/app_helpers.php';

$pdo = DB::pdo();

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

// Obtener equipos con sus jugadores ordenados por posición
$equipos_podio = [];
$todos_equipos = [];

try {
    // Obtener equipos desde tabla equipos ordenados por clasificación
    $sql_equipos = "
        SELECT 
            e.id as equipo_id,
            e.codigo_equipo,
            e.nombre_equipo,
            e.id_club,
            c.nombre as club_nombre,
            e.posicion,
            e.ganados,
            e.perdidos,
            e.efectividad,
            e.puntos,
            e.sancion,
            e.gff
        FROM equipos e
        LEFT JOIN clubes c ON e.id_club = c.id
        WHERE e.id_torneo = ? 
            AND e.estatus = 0
            AND e.codigo_equipo IS NOT NULL
            AND e.codigo_equipo != ''
        ORDER BY 
            e.ganados DESC,
            e.efectividad DESC,
            e.puntos DESC,
            e.codigo_equipo ASC
    ";
    
    $stmt_equipos = $pdo->prepare($sql_equipos);
    $stmt_equipos->execute([$torneo_id]);
    $equipos_todos = $stmt_equipos->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay equipos en la tabla equipos, buscar desde inscritos
    if (empty($equipos_todos)) {
        $sql_codigos = "
            SELECT DISTINCT i.codigo_equipo
            FROM inscritos i
            WHERE i.torneo_id = ? 
                AND i.codigo_equipo IS NOT NULL 
                AND i.codigo_equipo != ''
                AND i.estatus != 'retirado'
            ORDER BY i.codigo_equipo ASC
        ";
        
        $stmt_codigos = $pdo->prepare($sql_codigos);
        $stmt_codigos->execute([$torneo_id]);
        $codigos_inscritos = $stmt_codigos->fetchAll(PDO::FETCH_COLUMN);
        
        // Crear estructura de equipos desde inscritos
        foreach ($codigos_inscritos as $codigo) {
            $sql_stats_equipo = "
                SELECT 
                    SUM(i.ganados) as ganados,
                    SUM(i.perdidos) as perdidos,
                    SUM(i.efectividad) as efectividad,
                    SUM(i.puntos) as puntos,
                    SUM(i.sancion) as sancion,
                    MIN(i.id_club) as id_club
                FROM inscritos i
                WHERE i.torneo_id = ? 
                    AND i.codigo_equipo = ?
                    AND i.estatus != 'retirado'
            ";
            
            $stmt_stats = $pdo->prepare($sql_stats_equipo);
            $stmt_stats->execute([$torneo_id, $codigo]);
            $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
            
            if ($stats) {
                $sql_club = "
                    SELECT c.nombre as club_nombre
                    FROM inscritos i
                    LEFT JOIN clubes c ON i.id_club = c.id
                    WHERE i.torneo_id = ? 
                        AND i.codigo_equipo = ?
                        AND i.estatus != 'retirado'
                    LIMIT 1
                ";
                $stmt_club = $pdo->prepare($sql_club);
                $stmt_club->execute([$torneo_id, $codigo]);
                $club_data = $stmt_club->fetch(PDO::FETCH_ASSOC);
                
                $equipos_todos[] = [
                    'equipo_id' => null,
                    'codigo_equipo' => $codigo,
                    'nombre_equipo' => 'Equipo ' . $codigo,
                    'id_club' => (int)($stats['id_club'] ?? 0),
                    'club_nombre' => $club_data['club_nombre'] ?? 'Sin Club',
                    'posicion' => 0,
                    'ganados' => (int)($stats['ganados'] ?? 0),
                    'perdidos' => (int)($stats['perdidos'] ?? 0),
                    'efectividad' => (int)($stats['efectividad'] ?? 0),
                    'puntos' => (int)($stats['puntos'] ?? 0),
                    'sancion' => (int)($stats['sancion'] ?? 0),
                    'gff' => 0
                ];
            }
        }
        
        // Ordenar equipos calculados por clasificación
        usort($equipos_todos, function($a, $b) {
            $ganados_a = (int)($a['ganados'] ?? 0);
            $ganados_b = (int)($b['ganados'] ?? 0);
            if ($ganados_a != $ganados_b) {
                return $ganados_b <=> $ganados_a;
            }
            
            $efec_a = (int)($a['efectividad'] ?? 0);
            $efec_b = (int)($b['efectividad'] ?? 0);
            if ($efec_a != $efec_b) {
                return $efec_b <=> $efec_a;
            }
            
            $pts_a = (int)($a['puntos'] ?? 0);
            $pts_b = (int)($b['puntos'] ?? 0);
            if ($pts_a != $pts_b) {
                return $pts_b <=> $pts_a;
            }
            
            return strcmp($a['codigo_equipo'] ?? '', $b['codigo_equipo'] ?? '');
        });
        
        // Asignar posiciones
        $posicion_actual = 1;
        foreach ($equipos_todos as &$equipo_temp) {
            $equipo_temp['posicion'] = $posicion_actual;
            $posicion_actual++;
        }
        unset($equipo_temp);
    } else {
        // Asignar posiciones basadas en el orden
        $posicion_actual = 1;
        foreach ($equipos_todos as &$equipo_temp) {
            $equipo_temp['posicion'] = $posicion_actual;
            $posicion_actual++;
        }
        unset($equipo_temp);
    }
    
    // Para cada equipo, obtener sus jugadores con fotos
    foreach ($equipos_todos as $equipo_data) {
        $codigo_equipo = $equipo_data['codigo_equipo'];
        
        // Buscar jugadores del equipo ordenados por posición en el torneo
        $sql_jugadores = "
            SELECT 
                i.id,
                i.id_usuario,
                i.posicion,
                i.ganados,
                i.perdidos,
                i.efectividad,
                i.puntos,
                i.ptosrnk,
                i.sancion,
                i.tarjeta,
                u.nombre as nombre_completo,
                u.photo_path,
                u.sexo,
                c.nombre as club_nombre
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? 
                AND i.codigo_equipo = ?
                AND i.estatus != 'retirado'
            ORDER BY 
                CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                i.ganados DESC,
                i.efectividad DESC,
                i.puntos DESC
        ";
        
        $stmt_jugadores = $pdo->prepare($sql_jugadores);
        $stmt_jugadores->execute([$torneo_id, $codigo_equipo]);
        $jugadores_equipo = $stmt_jugadores->fetchAll(PDO::FETCH_ASSOC);
        
        // Procesar jugadores con fotos
        $jugadores_procesados = [];
        foreach ($jugadores_equipo as $jug) {
            // Construir URL de foto del jugador
            $foto_url = null;
            if (!empty($jug['photo_path'])) {
                $base_url = AppHelpers::getBaseUrl();
                if (strpos($jug['photo_path'], 'upload/') === 0) {
                    $foto_url = $base_url . '/' . $jug['photo_path'];
                } else {
                    $foto_url = $base_url . '/uploads/photos/' . basename($jug['photo_path']);
                }
            }
            
            // Obtener GFF desde partiresul si está disponible
            $gff = 0;
            try {
                $stmt_gff = $pdo->prepare("
                    SELECT COUNT(*) as gff_count
                    FROM partiresul
                    WHERE id_torneo = ? 
                        AND id_usuario = ?
                        AND ff = 1
                        AND resultado1 = 200
                ");
                $stmt_gff->execute([$torneo_id, $jug['id_usuario']]);
                $gff_result = $stmt_gff->fetch(PDO::FETCH_ASSOC);
                $gff = (int)($gff_result['gff_count'] ?? 0);
            } catch (Exception $e) {
                $gff = 0;
            }
            
            $jugadores_procesados[] = [
                'id_usuario' => (int)$jug['id_usuario'],
                'posicion' => (int)($jug['posicion'] ?? 0),
                'nombre' => $jug['nombre_completo'] ?? 'N/A',
                'foto_url' => $foto_url,
                'sexo' => $jug['sexo'] ?? '',
                'ganados' => (int)($jug['ganados'] ?? 0),
                'perdidos' => (int)($jug['perdidos'] ?? 0),
                'efectividad' => (int)($jug['efectividad'] ?? 0),
                'puntos' => (int)($jug['puntos'] ?? 0),
                'ptosrnk' => (int)($jug['ptosrnk'] ?? 0),
                'gff' => $gff,
                'sancion' => (int)($jug['sancion'] ?? 0),
                'tarjeta' => (int)($jug['tarjeta'] ?? 0)
            ];
        }
        
        // Calcular estadísticas totales del equipo
        $total_ptosrnk = array_sum(array_column($jugadores_procesados, 'ptosrnk'));
        
        $equipo_completo = [
            'equipo_id' => isset($equipo_data['equipo_id']) ? (int)$equipo_data['equipo_id'] : 0,
            'codigo_equipo' => $codigo_equipo,
            'nombre_equipo' => $equipo_data['nombre_equipo'],
            'id_club' => (int)($equipo_data['id_club'] ?? 0),
            'club_nombre' => $equipo_data['club_nombre'] ?? 'Sin Club',
            'posicion' => (int)($equipo_data['posicion'] ?? 0),
            'ganados' => (int)($equipo_data['ganados'] ?? 0),
            'perdidos' => (int)($equipo_data['perdidos'] ?? 0),
            'efectividad' => (int)($equipo_data['efectividad'] ?? 0),
            'puntos' => (int)($equipo_data['puntos'] ?? 0),
            'sancion' => (int)($equipo_data['sancion'] ?? 0),
            'gff' => (int)($equipo_data['gff'] ?? 0),
            'total_ptosrnk' => $total_ptosrnk,
            'jugadores' => $jugadores_procesados
        ];
        
        $todos_equipos[] = $equipo_completo;
        
        // Agregar a podio si está en los 3 primeros
        if ($equipo_completo['posicion'] > 0 && $equipo_completo['posicion'] <= 3) {
            $equipos_podio[] = $equipo_completo;
        }
    }
    
    // Obtener equipo seleccionado desde GET según posición del podio
    $posicion_seleccionada = $_GET['posicion'] ?? 1;
    $equipo_mostrar = null;
    
    // Buscar el equipo con la posición seleccionada en el podio
    foreach ($equipos_podio as $eq) {
        if ($eq['posicion'] == $posicion_seleccionada) {
            $equipo_mostrar = $eq;
            break;
        }
    }
    
    // Si no se encontró, usar el primero del podio por defecto
    if (!$equipo_mostrar && !empty($equipos_podio)) {
        $equipo_mostrar = $equipos_podio[0];
    }
} catch (Exception $e) {
    error_log("Error obteniendo podios de equipos: " . $e->getMessage());
}

// Función helper para obtener color de fondo según posición
function getPodioEquipoBgClass($posicion) {
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
                        PODIOS EQUIPOS
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
    </div>
    
    <?php if (empty($todos_equipos)): ?>
    <div class="bg-white rounded-xl shadow-lg p-6 text-center">
        <div class="text-gray-600">
            <i class="fas fa-info-circle text-4xl mb-4"></i>
            <p class="text-lg">Aún no hay equipos registrados en este torneo. Los podios se mostrarán cuando se registren equipos.</p>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Botones de podio (1°, 2°, 3°) -->
    <div class="flex flex-wrap justify-center items-center gap-3 mb-6">
        <?php if (count($equipos_podio) >= 1): ?>
        <button onclick="mostrarPodio(1)" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-yellow-400 to-yellow-600 hover:from-yellow-500 hover:to-yellow-700 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-yellow-300">
            <i class="fas fa-trophy mr-2"></i>1° Lugar
        </button>
        <?php endif; ?>
        
        <?php if (count($equipos_podio) >= 2): ?>
        <button onclick="mostrarPodio(2)" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-gray-300 to-gray-500 hover:from-gray-400 hover:to-gray-600 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-gray-300">
            <i class="fas fa-medal mr-2"></i>2° Lugar
        </button>
        <?php endif; ?>
        
        <?php if (count($equipos_podio) >= 3): ?>
        <button onclick="mostrarPodio(3)" 
                class="px-6 py-3 rounded-full font-bold text-white bg-gradient-to-r from-orange-600 to-orange-800 hover:from-orange-700 hover:to-orange-900 shadow-lg transition-all transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-orange-300">
            <i class="fas fa-medal mr-2"></i>3° Lugar
        </button>
        <?php endif; ?>
    </div>
    
    <!-- Contenedor de tarjetas de podio -->
    <div id="podioCards" class="relative" style="min-height: 1200px;">
        <?php if ($equipo_mostrar): ?>
        <?php $equipo = $equipo_mostrar; ?>
        <div class="podio-card rounded-2xl shadow-2xl overflow-hidden mx-auto" 
             data-codigo-equipo="<?= htmlspecialchars($equipo['codigo_equipo']) ?>"
             style="min-width: 792px; max-width: 1056px; width: 100%;">
                
                <!-- Fondo degradado desde el título hasta abajo -->
                <div class="<?= getPodioEquipoBgClass($equipo['posicion']) ?> p-6" style="font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;">
                    <!-- Título de posición -->
                    <div class="text-center mb-3">
                        <h2 class="text-2xl font-extrabold drop-shadow-lg text-black tracking-wide">
                            <?php if ($equipo['posicion'] == 1): ?>
                                <i class="fas fa-trophy mr-2"></i>PRIMER LUGAR
                            <?php elseif ($equipo['posicion'] == 2): ?>
                                <i class="fas fa-medal mr-2"></i>SEGUNDO LUGAR
                            <?php elseif ($equipo['posicion'] == 3): ?>
                                <i class="fas fa-medal mr-2"></i>TERCER LUGAR
                            <?php else: ?>
                                <i class="fas fa-users mr-2"></i>EQUIPO <?= htmlspecialchars($equipo['codigo_equipo']) ?>
                            <?php endif; ?>
                        </h2>
                    </div>
                    
                    <!-- Identificación del equipo (código, nombre, club) en la misma línea - PARTE SUPERIOR -->
                    <div class="mb-3 bg-white/25 rounded-lg p-2 backdrop-blur-sm border-2 border-black">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <span class="font-extrabold text-black whitespace-nowrap" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= htmlspecialchars($equipo['codigo_equipo']) ?></span>
                            <span class="font-extrabold text-black whitespace-nowrap" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= htmlspecialchars($equipo['nombre_equipo']) ?></span>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-building text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"></i>
                                <span class="font-bold text-black whitespace-nowrap" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= htmlspecialchars($equipo['club_nombre']) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Estadísticas generales del equipo - PARTE SUPERIOR -->
                    <div class="mb-3 bg-white/25 rounded-lg p-2 backdrop-blur-sm border-2 border-black">
                        <div class="flex items-center justify-around flex-wrap gap-2">
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">Ganados</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['ganados'] ?></div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">Perdidos</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['perdidos'] ?></div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">Efectividad</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['efectividad'] ?></div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">Puntos</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['puntos'] ?></div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">Pts. Rnk</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['total_ptosrnk'] ?></div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">GFF</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['gff'] ?></div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-black mb-0.5" style="font-size: clamp(0.75rem, 2vw, 1.1rem);">Sanciones</div>
                                <div class="font-extrabold text-black" style="font-size: clamp(1rem, 2.5vw, 1.5rem);"><?= $equipo['sancion'] ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Jugadores en bloques de dos columnas -->
                    <div class="mb-3 mx-auto" style="width: 60vw; max-width: 60vw;">
                        <div class="flex flex-col" style="max-height: 75vh; overflow-y: auto;">
                            <?php 
                            $total_jugadores = count($equipo['jugadores']);
                            // Calcular altura por jugador: 75% del alto de la pantalla dividido entre el número de jugadores
                            // Restamos espacio para título e información del equipo en la parte superior (aprox 250px)
                            $altura_disponible = 'calc(75vh - 250px)';
                            $altura_por_jugador = $total_jugadores > 0 ? 'calc(' . $altura_disponible . ' / ' . $total_jugadores . ')' : 'auto';
                            $min_altura = '80px'; // Altura mínima para cada jugador
                            ?>
                            <?php foreach ($equipo['jugadores'] as $index => $jugador): ?>
                            <div class="flex bg-white/25 backdrop-blur-sm border-2 border-black border-b-2 border-red-500" 
                                 style="min-height: <?= $min_altura ?>; height: <?= $altura_por_jugador ?>; <?= $index === count($equipo['jugadores']) - 1 ? 'border-b-2 border-black' : '' ?>">
                                <!-- Columna 1: Foto del jugador (proporcional al alto de la fila) -->
                                <div class="flex-shrink-0 flex items-center justify-center p-2 border-r-2 border-black" style="width: 20%; height: 100%;">
                                    <?php if ($jugador['foto_url']): ?>
                                    <img src="<?= htmlspecialchars($jugador['foto_url']) ?>" 
                                         alt="<?= htmlspecialchars($jugador['nombre']) ?>" 
                                         class="rounded-full border-2 border-white shadow-md object-cover"
                                         style="width: 100%; height: 100%; max-width: 100%; max-height: 100%; aspect-ratio: 1/1; object-fit: cover;"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="rounded-full border-2 border-white shadow-md bg-white/20 flex items-center justify-center hidden" style="width: 100%; height: 100%; max-width: 100%; max-height: 100%; aspect-ratio: 1/1;">
                                        <i class="fas fa-user text-black" style="font-size: clamp(1.5rem, 4vw, 3rem);"></i>
                                    </div>
                                    <?php else: ?>
                                    <div class="rounded-full border-2 border-white shadow-md bg-white/20 flex items-center justify-center" style="width: 100%; height: 100%; max-width: 100%; max-height: 100%; aspect-ratio: 1/1;">
                                        <i class="fas fa-user text-black" style="font-size: clamp(1.5rem, 4vw, 3rem);"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Columna 2: Información del jugador (tamaño legible aumentado 30%) -->
                                <div class="flex-1 flex flex-col justify-center p-3 min-w-0" style="height: 100%;">
                                    <!-- Primera línea: ID usuario, posición, nombre -->
                                    <div class="flex items-center gap-3 flex-wrap mb-2">
                                        <span class="font-bold text-black whitespace-nowrap" style="font-size: clamp(1.17rem, 2.6vw, 1.56rem);">ID: <code><?= $jugador['id_usuario'] ?></code></span>
                                        <span class="font-bold text-black whitespace-nowrap" style="font-size: clamp(1.17rem, 2.6vw, 1.56rem);">|</span>
                                        <span class="font-bold text-black whitespace-nowrap" style="font-size: clamp(1.17rem, 2.6vw, 1.56rem);">Pos: <?= $jugador['posicion'] > 0 ? $jugador['posicion'] : '-' ?></span>
                                        <span class="font-bold text-black whitespace-nowrap" style="font-size: clamp(1.17rem, 2.6vw, 1.56rem);">|</span>
                                        <span class="font-bold text-black truncate flex-1" style="font-size: clamp(1.17rem, 2.6vw, 1.56rem);"><?= htmlspecialchars($jugador['nombre']) ?></span>
                                    </div>
                                    
                                    <!-- Segunda línea: Estadísticas completas (G, P, Ef, Pt, Rnk, GFF, Sanc) -->
                                    <div class="flex items-center gap-3 flex-wrap" style="font-size: clamp(1.04rem, 2.34vw, 1.43rem);">
                                        <span class="text-black/90 whitespace-nowrap"><strong>G:</strong> <?= $jugador['ganados'] ?></span>
                                        <span class="text-black/90">|</span>
                                        <span class="text-black/90 whitespace-nowrap"><strong>P:</strong> <?= $jugador['perdidos'] ?></span>
                                        <span class="text-black/90">|</span>
                                        <span class="text-black/90 whitespace-nowrap"><strong>Ef:</strong> <?= $jugador['efectividad'] ?></span>
                                        <span class="text-black/90">|</span>
                                        <span class="text-black/90 whitespace-nowrap"><strong>Pt:</strong> <?= $jugador['puntos'] ?></span>
                                        <span class="text-black/90">|</span>
                                        <span class="text-black/90 whitespace-nowrap"><strong>Rnk:</strong> <?= $jugador['ptosrnk'] ?></span>
                                        <span class="text-black/90">|</span>
                                        <span class="text-black/90 whitespace-nowrap"><strong>GFF:</strong> <?= $jugador['gff'] ?? 0 ?></span>
                                        <span class="text-black/90">|</span>
                                        <span class="text-black/90 whitespace-nowrap"><strong>Sanc:</strong> <?= $jugador['sancion'] ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-lg p-6 text-center">
            <div class="text-gray-600">
                <i class="fas fa-info-circle text-4xl mb-4"></i>
                <p class="text-lg">Seleccione un equipo para ver su información.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>
</div>

<script>
function mostrarPodio(posicion) {
    // Redirigir a la misma página con la posición seleccionada
    const url = new URL(window.location.href);
    url.searchParams.set('posicion', posicion);
    window.location.href = url.toString();
}
</script>

<style>
@media print {
    .bg-gradient-to-br { background: white !important; }
    button, a[href*="action="] { display: none !important; }
    .podio-card {
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>

