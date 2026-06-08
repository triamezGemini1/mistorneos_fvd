<?php
/**
 * Lógica vital de torneo para desktop/core.
 * Este archivo no debe producir salida (no echo/print, sin espacio antes de <?php) para evitar "headers already sent".
 * Funciones extraídas de modules/torneo_gestion.php:
 * - generarRonda()
 * - actualizarEstadisticasInscritos()
 * y dependencias: recalcularClasificacionEquiposYJugadores, recalcularPosiciones,
 * actualizarEstadisticasEquipos, recalcularPosicionesEquipos, asignarNumeroSecuencialPorEquipo.
 * Usa DB::pdo() de db_bridge (SQLite local).
 */
require_once __DIR__ . '/db_bridge.php';
require_once dirname(__DIR__, 2) . '/lib/Core/TorneoMesaAsignacionResolver.php';
require_once __DIR__ . '/InscritosHelper.php';

/** Filtro evento local: fragmento SQL y bind para inscritos/partiresul (solo cuando DESKTOP_ENTIDAD_ID > 0). */
function logica_torneo_where_entidad(string $alias = ''): array
{
    $eid = DB::getEntidadId();
    if ($eid <= 0) return ['sql' => '', 'bind' => []];
    $col = $alias !== '' ? $alias . '.entidad_id' : 'entidad_id';
    return ['sql' => " AND {$col} = ?", 'bind' => [$eid]];
}

/**
 * Genera una nueva ronda.
 * Versión desktop: retorna ['success' => bool, 'message' => string] en lugar de redirect/exit.
 *
 * @param int   $torneo_id
 * @param int   $user_id
 * @param bool  $is_admin_general
 * @param array $opciones Opciones de estrategia (evita depender de $_POST):
 *   - estrategia_ronda2: para individual/parejas (default 'separar')
 *   - estrategia_asignacion: para equipos (default 'secuencial')
 */
function generarRonda($torneo_id, $user_id, $is_admin_general, array $opciones = []) {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT nombre, rondas, modalidad FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            return ['success' => false, 'message' => 'Torneo no encontrado'];
        }
        $total_rondas = (int)($torneo['rondas'] ?? 0);
        $modalidad = (int)($torneo['modalidad'] ?? 0);
        $es_torneo_equipos = ($modalidad === TorneoMesaAsignacionResolver::MODALIDAD_EQUIPOS);
        $mesaService = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);

        $ultima_ronda = $mesaService->obtenerUltimaRonda($torneo_id);
        if ($ultima_ronda > 0) {
            $todas_completas = $mesaService->todasLasMesasCompletas($torneo_id, $ultima_ronda);
            if (!$todas_completas) {
                $mesas_incompletas = $mesaService->contarMesasIncompletas($torneo_id, $ultima_ronda);
                return ['success' => false, 'message' => "Faltan resultados en {$mesas_incompletas} mesa(s) de la ronda {$ultima_ronda}"];
            }
        }

        try {
            actualizarEstadisticasInscritos($torneo_id, true);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error al actualizar estadísticas: ' . $e->getMessage()];
        }

        $proxima_ronda = $ultima_ronda + 1;
        $estrategia = $es_torneo_equipos
            ? ($opciones['estrategia_asignacion'] ?? $_POST['estrategia_asignacion'] ?? 'secuencial')
            : ($opciones['estrategia_ronda2'] ?? $_POST['estrategia_ronda2'] ?? 'separar');

        $resultado = $es_torneo_equipos
            ? TorneoMesaAsignacionResolver::generarAsignacionRondaEquipos($torneo_id, $proxima_ronda, $total_rondas, $estrategia)
            : $mesaService->generarAsignacionRonda($torneo_id, $proxima_ronda, $total_rondas, $estrategia);

        if ($resultado['success']) {
            $mensaje = $resultado['message'];
            if (isset($resultado['total_mesas'])) $mensaje .= ': ' . $resultado['total_mesas'] . ' mesas';
            if (isset($resultado['total_equipos'])) $mensaje .= ', ' . $resultado['total_equipos'] . ' equipos';
            if (isset($resultado['jugadores_bye']) && $resultado['jugadores_bye'] > 0) {
                $mensaje .= ', ' . $resultado['jugadores_bye'] . ' rezagado(s) sin mesa (retirados, sin partiresul)';
            }
            if (isset($resultado['excedentes_club_interclub_parejas']) && (int) $resultado['excedentes_club_interclub_parejas'] > 0) {
                $mensaje .= ', ' . (int) $resultado['excedentes_club_interclub_parejas'] . ' pareja(s) excedentes de club (sin mesa; no BYE ni estadística de partida en esa ronda)';
            }
            if (! empty($resultado['advertencias_interclub_r1']) && is_array($resultado['advertencias_interclub_r1'])) {
                $mensaje .= ' ' . implode(' ', array_map('strval', $resultado['advertencias_interclub_r1']));
            }
            return ['success' => true, 'message' => $mensaje];
        }
        return ['success' => false, 'message' => $resultado['message']];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al generar ronda: ' . $e->getMessage()];
    }
}

/**
 * Actualizar estadísticas de todos los inscritos basándose en PartiResul.
 * Solo reclasifica posiciones cuando $recalcularClasificacion es true (generar ronda, cierre de torneo, acción manual).
 */
function actualizarEstadisticasInscritos($torneo_id, bool $recalcularClasificacion = false) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT puntos FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        throw new Exception("Torneo no encontrado");
    }
    $entI = logica_torneo_where_entidad('i');
    $stmt = $pdo->prepare("SELECT id, id_usuario, torneo_id FROM inscritos i WHERE i.torneo_id = ? AND i.estatus != 4" . $entI['sql']);
    $stmt->execute(array_merge([$torneo_id], $entI['bind']));
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($inscritos)) {
        return;
    }

    foreach ($inscritos as $inscrito) {
        $idUsuario = (int)$inscrito['id_usuario'];
        $entP = logica_torneo_where_entidad();
        $stmt = $pdo->prepare("SELECT DISTINCT partida, mesa FROM partiresul WHERE id_torneo = ? AND id_usuario = ? AND registrado = 1" . $entP['sql'] . " ORDER BY partida, mesa");
        $stmt->execute(array_merge([$torneo_id, $idUsuario], $entP['bind']));
        $mesasJugador = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalGanados = $totalPerdidos = $totalEfectividad = $totalPuntos = $totalSancion = $totalChancletas = $totalZapatos = $ultimaTarjeta = 0;
        $fechaUltimaTarjeta = null;

        foreach ($mesasJugador as $mesaInfo) {
            $partida = (int)$mesaInfo['partida'];
            $mesa = (int)$mesaInfo['mesa'];

            if ($mesa === 0) {
                $stmt = $pdo->prepare("SELECT resultado1, resultado2, efectividad, sancion, fecha_partida FROM partiresul WHERE id_torneo = ? AND id_usuario = ? AND partida = ? AND mesa = 0 AND registrado = 1" . $entP['sql'] . " LIMIT 1");
                $stmt->execute(array_merge([$torneo_id, $idUsuario, $partida], $entP['bind']));
                $rowBye = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($rowBye) {
                    $totalGanados++;
                    $totalEfectividad += (int)($rowBye['efectividad'] ?? 0);
                    $totalPuntos += (int)($rowBye['resultado1'] ?? 0);
                    $totalSancion += (int)($rowBye['sancion'] ?? 0);
                }
                continue;
            }

            $stmt = $pdo->prepare("SELECT * FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ?" . $entP['sql'] . " ORDER BY secuencia");
            $stmt->execute(array_merge([$torneo_id, $partida, $mesa], $entP['bind']));
            $jugadoresMesa = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $jugadorActual = null;
            foreach ($jugadoresMesa as $jugador) {
                if ((int)$jugador['id_usuario'] == $idUsuario) {
                    $jugadorActual = $jugador;
                    break;
                }
            }
            if (!$jugadorActual) continue;

            $hayForfaitMesa = false;
            $hayTarjetaGraveMesa = false;
            foreach ($jugadoresMesa as $jugador) {
                if ((int)$jugador['ff'] == 1) $hayForfaitMesa = true;
                $t = (int)$jugador['tarjeta'];
                if ($t == 3 || $t == 4) $hayTarjetaGraveMesa = true;
            }

            $resultado1 = (int)$jugadorActual['resultado1'];
            $resultado2 = (int)$jugadorActual['resultado2'];
            $efectividad = (int)$jugadorActual['efectividad'];
            $ff = (int)$jugadorActual['ff'];
            $tarjeta = (int)$jugadorActual['tarjeta'];
            $sancion = (int)$jugadorActual['sancion'];
            $chancleta = (int)$jugadorActual['chancleta'];
            $zapato = (int)$jugadorActual['zapato'];
            $fechaPartida = $jugadorActual['fecha_partida'];

            $gano = false;
            if ($hayForfaitMesa) $gano = ($ff == 0);
            elseif ($hayTarjetaGraveMesa) $gano = !($tarjeta == 3 || $tarjeta == 4);
            else $gano = ($resultado1 > $resultado2);

            if ($gano) $totalGanados++; else $totalPerdidos++;
            $totalEfectividad += $efectividad;
            $totalPuntos += $resultado1;
            $totalSancion += $sancion;
            if ($gano) {
                $totalChancletas += $chancleta;
                $totalZapatos += $zapato;
            }
            if ($tarjeta > 0 && ($fechaUltimaTarjeta === null || $fechaPartida > $fechaUltimaTarjeta)) {
                $ultimaTarjeta = $tarjeta;
                $fechaUltimaTarjeta = $fechaPartida;
            }
        }

        $entU = logica_torneo_where_entidad();
        $stmt = $pdo->prepare("UPDATE inscritos SET ganados = ?, perdidos = ?, efectividad = ?, puntos = ?, sancion = ?, chancletas = ?, zapatos = ?, tarjeta = ? WHERE torneo_id = ? AND id_usuario = ?" . $entU['sql']);
        $stmt->execute(array_merge([$totalGanados, $totalPerdidos, $totalEfectividad, $totalPuntos, $totalSancion, $totalChancletas, $totalZapatos, $ultimaTarjeta, $torneo_id, $idUsuario], $entU['bind']));
    }

    if ($recalcularClasificacion) {
        recalcularClasificacionEquiposYJugadores($torneo_id);
    }
}

/**
 * Reclasifica posiciones si la última ronda programada del torneo quedó completa (última mesa ingresada).
 */
function reclasificarSiUltimaRondaTorneoCompleta($torneo_id): bool
{
    $torneo_id = (int) $torneo_id;
    if ($torneo_id <= 0) {
        return false;
    }
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT rondas, modalidad FROM tournaments WHERE id = ? LIMIT 1');
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $torneo) {
        return false;
    }
    $totalRondas = (int) ($torneo['rondas'] ?? 0);
    if ($totalRondas <= 0) {
        return false;
    }
    $modalidad = (int) ($torneo['modalidad'] ?? 0);
    $mesaService = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);
    $ultimaRonda = $mesaService->obtenerUltimaRonda($torneo_id);
    if ($ultimaRonda < $totalRondas) {
        return false;
    }
    if (! $mesaService->todasLasMesasCompletas($torneo_id, $ultimaRonda)) {
        return false;
    }
    recalcularRankingSegunModalidad($torneo_id);

    return true;
}

function recalcularRankingSegunModalidad($torneo_id) {
    $torneo_id = (int)$torneo_id;
    if ($torneo_id <= 0) {
        return;
    }
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT COALESCE(modalidad, 1) AS modalidad FROM tournaments WHERE id = ?');
    $stmt->execute([$torneo_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $modalidad = (int)($row['modalidad'] ?? 1);
    if (in_array($modalidad, [2, 3, 4], true)) {
        recalcularClasificacionEquiposYJugadores($torneo_id);
    } else {
        recalcularPosiciones($torneo_id);
    }
}

function recalcularClasificacionEquiposYJugadores($torneo_id) {
    actualizarEstadisticasEquipos($torneo_id);
    recalcularPosiciones($torneo_id);
    asignarNumeroSecuencialPorEquipo($torneo_id);
}

function actualizarEstadisticasEquipos($torneo_id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT modalidad FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $torneo) {
        return;
    }
    $mod = (int) ($torneo['modalidad'] ?? 0);
    if (! in_array($mod, [2, 3, 4], true)) {
        return;
    }

    $ent = logica_torneo_where_entidad();
    $sql = "SELECT codigo_equipo, SUM(puntos) as puntos_equipo, SUM(ganados) as ganados_equipo, SUM(perdidos) as perdidos_equipo, SUM(efectividad) as efectividad_equipo, SUM(sancion) as sancion_equipo, COUNT(*) as total_jugadores FROM inscritos WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo != '' AND estatus != 4" . $ent['sql'] . " GROUP BY codigo_equipo";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$torneo_id], $ent['bind']));
    $estadisticasEquipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($estadisticasEquipos)) return;

    $stmtUpdate = $pdo->prepare("UPDATE equipos SET puntos = ?, ganados = ?, perdidos = ?, efectividad = ?, sancion = ?, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id_torneo = ? AND codigo_equipo = ?");
    foreach ($estadisticasEquipos as $stats) {
        $stmtUpdate->execute([
            (int)($stats['puntos_equipo'] ?? 0),
            (int)($stats['ganados_equipo'] ?? 0),
            (int)($stats['perdidos_equipo'] ?? 0),
            (int)($stats['efectividad_equipo'] ?? 0),
            (int)($stats['sancion_equipo'] ?? 0),
            $torneo_id,
            $stats['codigo_equipo']
        ]);
    }
    recalcularPosicionesEquipos($torneo_id);
}

function recalcularPosicionesEquipos($torneo_id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT codigo_equipo, puntos, ganados, efectividad FROM equipos WHERE id_torneo = ? AND estatus = 0 ORDER BY ganados DESC, efectividad DESC, puntos DESC, codigo_equipo ASC");
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($equipos)) return;

    $ent = logica_torneo_where_entidad();
    $stmtUpdate = $pdo->prepare("UPDATE equipos SET posicion = ? WHERE id_torneo = ? AND codigo_equipo = ?");
    $stmtUpdateInscritos = $pdo->prepare("UPDATE inscritos SET clasiequi = ? WHERE torneo_id = ? AND codigo_equipo = ? AND estatus != 4" . $ent['sql']);
    $posicion = 1;
    foreach ($equipos as $equipo) {
        $stmtUpdate->execute([$posicion, $torneo_id, $equipo['codigo_equipo']]);
        $stmtUpdateInscritos->execute(array_merge([$posicion, $torneo_id, $equipo['codigo_equipo']], $ent['bind']));
        $posicion++;
    }
}

function asignarNumeroSecuencialPorEquipo($torneo_id) {
    $pdo = DB::pdo();
    $ent = logica_torneo_where_entidad();
    $stmtEquipos = $pdo->prepare("SELECT DISTINCT codigo_equipo FROM inscritos WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo != '' AND estatus != 4" . $ent['sql']);
    $stmtEquipos->execute(array_merge([$torneo_id], $ent['bind']));
    $codigos = $stmtEquipos->fetchAll(PDO::FETCH_COLUMN);
    $stmtJugadores = $pdo->prepare("SELECT id FROM inscritos WHERE torneo_id = ? AND codigo_equipo = ? AND estatus != 4" . $ent['sql'] . " ORDER BY CAST(ganados AS SIGNED) DESC, CAST(efectividad AS SIGNED) DESC, CAST(puntos AS SIGNED) DESC, id_usuario ASC");
    $stmtUpdateNumero = $pdo->prepare("UPDATE inscritos SET numero = ? WHERE id = ?");
    foreach ($codigos as $codigo) {
        $stmtJugadores->execute(array_merge([$torneo_id, $codigo], $ent['bind']));
        $jugadoresEquipo = $stmtJugadores->fetchAll(PDO::FETCH_ASSOC);
        $numeroSecuencial = 1;
        foreach ($jugadoresEquipo as $jug) {
            $stmtUpdateNumero->execute([$numeroSecuencial, $jug['id']]);
            $numeroSecuencial++;
        }
    }
}

function recalcularPosiciones($torneo_id) {
    require_once __DIR__ . '/../../lib/ClasirankingRankingHelper.php';
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT modalidad, nombre FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) return;

    $modalidadRaw = $torneo['modalidad'] ?? 1;
    $modalidadNum = is_numeric($modalidadRaw) ? (int)$modalidadRaw : 1;
    if (in_array($modalidadNum, [2, 4], true)) {
        $tipoTorneoClasi = 2;
    } elseif ($modalidadNum === 3) {
        $tipoTorneoClasi = 3;
    } else {
        $tipoTorneoClasi = 1;
    }
    $limitePosiciones = ($tipoTorneoClasi === 2) ? 20 : (($tipoTorneoClasi === 3) ? 10 : 30);

    $ent = logica_torneo_where_entidad();
    $pdo->prepare("UPDATE inscritos SET posicion = 0 WHERE torneo_id = ?" . $ent['sql'])->execute(array_merge([$torneo_id], $ent['bind']));
    $stmt = $pdo->prepare("SELECT id, id_usuario, codigo_equipo, clasiequi, CAST(ganados AS SIGNED) as ganados, CAST(efectividad AS SIGNED) as efectividad, CAST(puntos AS SIGNED) as puntos FROM inscritos WHERE torneo_id = ? AND estatus != 4" . $ent['sql'] . " ORDER BY CAST(ganados AS SIGNED) DESC, CAST(efectividad AS SIGNED) DESC, CAST(puntos AS SIGNED) DESC");
    $stmt->execute(array_merge([$torneo_id], $ent['bind']));
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($inscritos)) return;

    $ganadosEquipoPorCodigo = [];
    if (in_array($modalidadNum, [2, 4], true)) {
        $stmtEqG = $pdo->prepare("SELECT codigo_equipo, ganados FROM equipos WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != ''");
        $stmtEqG->execute([$torneo_id]);
        while ($rowEq = $stmtEqG->fetch(PDO::FETCH_ASSOC)) {
            $cod = trim((string) ($rowEq['codigo_equipo'] ?? ''));
            if ($cod !== '') {
                $ganadosEquipoPorCodigo[$cod] = (int) ($rowEq['ganados'] ?? 0);
            }
        }
    }

    $existeClasiRanking = false;
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clasiranking' LIMIT 1");
            $existeClasiRanking = ($stmt && $stmt->fetch() !== false);
        } else {
            $stmt = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'clasiranking' LIMIT 1");
            $existeClasiRanking = ($stmt && $stmt->fetch() !== false);
        }
    } catch (Exception $e) {}

    $puntosPorPartidaGanadaFuera = null;
    $puntosAsistenciaFuera = 1;
    if ($existeClasiRanking && $limitePosiciones >= 1) {
        try {
            $stmtF = $pdo->prepare("SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1");
            $stmtF->execute([$tipoTorneoClasi, $limitePosiciones]);
            $rf = $stmtF->fetch(PDO::FETCH_ASSOC);
            if ($rf) {
                $puntosPorPartidaGanadaFuera = (int)($rf['puntos_por_partida_ganada'] ?? 0);
                $puntosAsistenciaFuera = (int)($rf['puntos_asistencia'] ?? 1);
            }
        } catch (Exception $e) {
            try {
                $stmtF = $pdo->prepare("SELECT puntos_por_partida_ganada FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1");
                $stmtF->execute([$tipoTorneoClasi, $limitePosiciones]);
                $rf = $stmtF->fetch(PDO::FETCH_ASSOC);
                if ($rf) {
                    $puntosPorPartidaGanadaFuera = (int)($rf['puntos_por_partida_ganada'] ?? 0);
                }
            } catch (Exception $e2) {
            }
        }
    }
    if ($existeClasiRanking && $puntosPorPartidaGanadaFuera === null) {
        try {
            $stmtF2 = $pdo->prepare("SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia FROM clasiranking WHERE tipo_torneo = ? ORDER BY clasificacion DESC LIMIT 1");
            $stmtF2->execute([$tipoTorneoClasi]);
            $rf2 = $stmtF2->fetch(PDO::FETCH_ASSOC);
            if ($rf2) {
                $puntosPorPartidaGanadaFuera = (int) ($rf2['puntos_por_partida_ganada'] ?? 0);
                $puntosAsistenciaFuera = (int) ($rf2['puntos_asistencia'] ?? 1);
            }
        } catch (Exception $e) {
        }
    }
    $pppFuera = (int) ($puntosPorPartidaGanadaFuera ?? 0);
    $pasFuera = max(1, (int) ($puntosAsistenciaFuera ?? 1));

    $posicion = 1;
    $stmtUpdate = $pdo->prepare("UPDATE inscritos SET posicion = ?, ptosrnk = ? WHERE id = ?");
    $rankingPorClasificacion = [];
    foreach ($inscritos as $inscrito) {
        $id = (int)$inscrito['id'];
        $ganados = (int)($inscrito['ganados'] ?? 0);
        $codEq = trim((string)($inscrito['codigo_equipo'] ?? ''));
        if (in_array($modalidadNum, [2, 4], true) && $codEq !== '' && array_key_exists($codEq, $ganadosEquipoPorCodigo)) {
            $ganados = $ganadosEquipoPorCodigo[$codEq];
        }
        $clasiequi = (int)($inscrito['clasiequi'] ?? 0);

        $clasificacionRanking = $posicion;
        if (in_array($modalidadNum, [2, 3, 4], true) && $clasiequi > 0) {
            $clasificacionRanking = $clasiequi;
        }

        $ptosrnk = $pasFuera;
        if ($existeClasiRanking && $clasificacionRanking >= 1 && $clasificacionRanking <= $limitePosiciones) {
            if (!array_key_exists($clasificacionRanking, $rankingPorClasificacion)) {
                $rankingPorClasificacion[$clasificacionRanking] = ClasirankingRankingHelper::obtenerFilaParaClasificacion(
                    $pdo,
                    $tipoTorneoClasi,
                    $clasificacionRanking,
                    $limitePosiciones
                );
            }
            $ranking = $rankingPorClasificacion[$clasificacionRanking];
            if ($ranking) {
                $ptosrnk = (int) $ranking['puntos_posicion'] + ($ganados * (int) $ranking['puntos_por_partida_ganada']) + (int) $ranking['puntos_asistencia'];
            } else {
                $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
            }
        } elseif ($existeClasiRanking && $clasificacionRanking > $limitePosiciones) {
            $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
        } elseif ($existeClasiRanking) {
            $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
        }
        $stmtUpdate->execute([$posicion, $ptosrnk, $id]);
        $posicion++;
    }

    try {
        $pdo->prepare("UPDATE inscritos SET ptosrnk = 0 WHERE torneo_id = ? AND estatus = 4" . $ent['sql'])->execute(array_merge([$torneo_id], $ent['bind']));
    } catch (Exception $e) {
    }
}
