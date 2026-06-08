<?php
/**
 * Top N jugadores por club (misma lógica que resultados_por_club.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/InscritosReporteStatsHelper.php';

if (!function_exists('obtenerTopJugadoresPorClub')) {
    function obtenerTopJugadoresPorClub($pdo, $torneo_id, $topN)
    {
        InscritosReporteStatsHelper::ensureColumnas($pdo);
        $cols = InscritosReporteStatsHelper::expresionesSelectClasificacion('i');
        $ig = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $ie = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $ip = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $sql = "SELECT 
                i.*,
                i.id_club as codigo_club,
                u.nombre as nombre_completo,
                u.username,
                u.sexo,
                u.cedula,
                c.id as club_id_from_join,
                c.nombre as club_nombre,
                c.logo as club_logo,
                {$cols['ganadas_por_forfait']}
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? 
                AND i.estatus != 'retirado'
            ORDER BY COALESCE(i.id_club, -1) ASC, 
                     $ig DESC, 
                     $ie DESC, 
                     $ip DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneo_id]);
        $todos_jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $jugadores_por_club = [];
        foreach ($todos_jugadores as $jugador) {
            $codigo_club = isset($jugador['id_club']) && $jugador['id_club'] !== null
                ? (int)$jugador['id_club']
                : ((int)($jugador['codigo_club'] ?? 0));
            if ($codigo_club == 0) {
                $codigo_club = -1;
            }
            if (!isset($jugadores_por_club[$codigo_club])) {
                $club_info = null;
                if ($codigo_club > 0) {
                    $stmt_club = $pdo->prepare('SELECT id, nombre, logo FROM clubes WHERE id = ?');
                    $stmt_club->execute([$codigo_club]);
                    $club_info = $stmt_club->fetch(PDO::FETCH_ASSOC);
                }
                $nombre_final = $club_info ? $club_info['nombre'] : ($jugador['club_nombre'] ?? 'Sin Club');
                $jugadores_por_club[$codigo_club] = [
                    'codigo_club' => $codigo_club,
                    'club_nombre' => $nombre_final,
                    'club_logo' => $club_info ? $club_info['logo'] : ($jugador['club_logo'] ?? null),
                    'jugadores' => [],
                ];
            }
            if (count($jugadores_por_club[$codigo_club]['jugadores']) < $topN) {
                $jugadores_por_club[$codigo_club]['jugadores'][] = $jugador;
            }
        }

        $estadisticas = [];
        $detalle = [];
        foreach ($jugadores_por_club as $codigo_club => $club_data) {
            $jugadores_seleccionados = $club_data['jugadores'];
            $total_puntos_grupo = $total_efectividad = $total_ganados = $total_perdidos = $total_ptosrnk = $total_gff = 0;
            $mejor_posicion = 999;
            $cantidad_jugadores = count($jugadores_seleccionados);
            foreach ($jugadores_seleccionados as $index => $jugador) {
                $ganados = (int)($jugador['ganados'] ?? 0);
                $perdidos = (int)($jugador['perdidos'] ?? 0);
                $efectividad = (int)($jugador['efectividad'] ?? 0);
                $puntos = (int)($jugador['puntos'] ?? 0);
                $ptosrnk = (int)($jugador['ptosrnk'] ?? 0);
                $gff = (int)($jugador['ganadas_por_forfait'] ?? 0);
                $posicion = (int)($jugador['posicion'] ?? 0);
                $total_puntos_grupo += $puntos;
                $total_efectividad += $efectividad;
                $total_ganados += $ganados;
                $total_perdidos += $perdidos;
                $total_ptosrnk += $ptosrnk;
                $total_gff += $gff;
                if ($posicion > 0 && $posicion < $mejor_posicion) {
                    $mejor_posicion = $posicion;
                }
                $detalle[] = [
                    'codigo_club' => $codigo_club,
                    'club_nombre' => $club_data['club_nombre'],
                    'ranking' => $index + 1,
                    'nombre' => $jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A',
                    'id_usuario' => (int)$jugador['id_usuario'],
                    'cedula' => $jugador['cedula'] ?? '',
                    'ganados' => $ganados,
                    'perdidos' => $perdidos,
                    'efectividad' => $efectividad,
                    'puntos' => $puntos,
                    'ptosrnk' => $ptosrnk,
                    'gff' => $gff,
                    'posicion' => $posicion,
                    'zapato' => (int)($jugador['zapato'] ?? $jugador['zapatos'] ?? 0),
                    'chancletas' => (int)($jugador['chancletas'] ?? 0),
                    'sancion' => (int)($jugador['sancion'] ?? 0),
                    'tarjeta' => (int)($jugador['tarjeta'] ?? 0),
                ];
            }
            $promedio_efectividad = $cantidad_jugadores > 0 ? (int)round($total_efectividad / $cantidad_jugadores) : 0;
            $estadisticas[] = [
                'codigo_club' => $codigo_club,
                'club_nombre' => $club_data['club_nombre'],
                'club_logo' => $club_data['club_logo'],
                'total_puntos_grupo' => $total_puntos_grupo,
                'promedio_efectividad' => $promedio_efectividad,
                'total_ganados' => $total_ganados,
                'total_perdidos' => $total_perdidos,
                'total_efectividad' => $total_efectividad,
                'total_ptosrnk' => $total_ptosrnk,
                'total_gff' => $total_gff,
                'mejor_posicion' => $mejor_posicion == 999 ? 0 : $mejor_posicion,
                'cantidad_jugadores' => $cantidad_jugadores,
            ];
        }
        usort($estadisticas, function ($a, $b) {
            if ($a['total_ganados'] != $b['total_ganados']) {
                return $b['total_ganados'] <=> $a['total_ganados'];
            }
            if ($a['total_efectividad'] != $b['total_efectividad']) {
                return $b['total_efectividad'] <=> $a['total_efectividad'];
            }
            return $b['total_puntos_grupo'] <=> $a['total_puntos_grupo'];
        });

        return ['estadisticas' => $estadisticas, 'detalle' => $detalle];
    }
}
