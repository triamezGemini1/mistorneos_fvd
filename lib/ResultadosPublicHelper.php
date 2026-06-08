<?php
declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/ResultadosReporteData.php';
require_once __DIR__ . '/InscritosHelper.php';

/**
 * ResultadosPublicHelper - Datos para reportes públicos de torneos
 * Usado por evento_resultados.php para mostrar resultados sin autenticación
 */
class ResultadosPublicHelper
{
    /**
     * Obtiene info de rondas: total programadas, ejecutadas, faltantes
     */
    public static function getRoundsInfo(PDO $pdo, int $torneo_id): array
    {
        $total = 0;
        $ejecutadas = 0;
        $faltantes = 0;

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(rondas, 0) as rondas FROM tournaments WHERE id = ?");
            $stmt->execute([$torneo_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($row['rondas'] ?? 0);

            $stmt = $pdo->query("SHOW TABLES LIKE 'partiresul'");
            if ($stmt->rowCount() > 0) {
                $wReg = PartiresulEstatusSql::whereRegistradoUno('');
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(DISTINCT partida) as total_rondas_ejecutadas,
                        COUNT(*) as total_partidas,
                        SUM(CASE WHEN {$wReg} THEN 1 ELSE 0 END) as partidas_registradas
                    FROM partiresul
                    WHERE id_torneo = ? AND partida > 0
                ");
                $stmt->execute([$torneo_id]);
                $info = $stmt->fetch(PDO::FETCH_ASSOC);
                $ejecutadas = (int)($info['total_rondas_ejecutadas'] ?? 0);
            }
            $faltantes = max(0, $total - $ejecutadas);
        } catch (Exception $e) {
            error_log("ResultadosPublicHelper getRoundsInfo: " . $e->getMessage());
        }

        return [
            'total' => $total,
            'ejecutadas' => $ejecutadas,
            'faltantes' => $faltantes,
            'completado' => $total > 0 && $ejecutadas >= $total,
        ];
    }

    /**
     * Posiciones generales (clasificación). Filtra por género para no mezclar M/F (genero: M|F vía GET).
     *
     * @return list<array<string, mixed>>
     */
    public static function getPosiciones(PDO $pdo, int $torneo_id, int $limit = 500, int $offset = 0, ?string $generoGet = null): array
    {
        $filas = self::obtenerFilasPosicionesPublicoSinLimite($pdo, $torneo_id);
        $filas = self::aplicarFiltroGeneroPosicionesPublico($pdo, $torneo_id, $filas, $generoGet);
        return array_slice($filas, max(0, $offset), max(1, $limit));
    }

    public static function getPosicionesCount(PDO $pdo, int $torneo_id, ?string $generoGet = null): int
    {
        $filas = self::obtenerFilasPosicionesPublicoSinLimite($pdo, $torneo_id);
        $filas = self::aplicarFiltroGeneroPosicionesPublico($pdo, $torneo_id, $filas, $generoGet);

        return count($filas);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function obtenerFilasPosicionesPublicoSinLimite(PDO $pdo, int $torneo_id): array
    {
        try {
            $tienePartiresul = false;
            $st = $pdo->query("SHOW TABLES LIKE 'partiresul'");
            if ($st && $st->rowCount() > 0) {
                $tienePartiresul = true;
            }

            require_once __DIR__ . '/ResultadosReporteData.php';
            $gffSub = ResultadosReporteData::sqlGanadasPorForfaitSubquery('i');
            $tarjSub = ResultadosReporteData::sqlTarjetaEfectivaSubquery('i');
            $wRegBye = PartiresulEstatusSql::whereRegistradoUno('pr_bye');
            $sql = "SELECT i.*, COALESCE(u.nombre, u.username) as nombre_completo, u.sexo, c.nombre as club_nombre";
            if ($tienePartiresul) {
                $sql .= ", {$gffSub} as ganadas_por_forfait, {$tarjSub} as tarjeta_efectiva";
                $sql .= ", (SELECT COUNT(*) FROM partiresul pr_bye WHERE pr_bye.id_usuario = i.id_usuario AND pr_bye.id_torneo = ? AND {$wRegBye} AND pr_bye.mesa = 0 AND pr_bye.resultado1 > pr_bye.resultado2) as partidas_bye";
            } else {
                $sql .= ", 0 as ganadas_por_forfait, 0 as partidas_bye";
            }
            $sql .= " FROM inscritos i LEFT JOIN usuarios u ON i.id_usuario = u.id LEFT JOIN clubes c ON i.id_club = c.id
                WHERE i.torneo_id = ? AND (i.estatus IS NULL OR (i.estatus != 4 AND i.estatus != 'retirado'))
                ORDER BY CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                         i.ganados DESC, i.efectividad DESC, i.puntos DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($tienePartiresul ? [$torneo_id, $torneo_id] : [$torneo_id]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('ResultadosPublicHelper obtenerFilasPosicionesPublicoSinLimite: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    private static function aplicarFiltroGeneroPosicionesPublico(PDO $pdo, int $torneo_id, array $filas, ?string $generoGet): array
    {
        if ($filas === []) {
            return [];
        }
        $stmtT = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? LIMIT 1');
        $stmtT->execute([$torneo_id]);
        $torneoRow = $stmtT->fetch(PDO::FETCH_ASSOC) ?: [];
        $gen = ResultadosReporteData::generoFiltroDesdeParametro($generoGet);
        $modalidad = (int) ($torneoRow['modalidad'] ?? 0);

        return ResultadosReporteData::aplicarRankingPorGenero($filas, $gen, $modalidad);
    }

    /**
     * Resultados agrupados por club (top N jugadores por club)
     * Adaptado de resultados_por_club.php
     */
    public static function getResultadosPorClub(PDO $pdo, int $torneo_id, int $topN = 8): array
    {
        try {
            $tienePartiresul = false;
            $stmt = $pdo->query("SHOW TABLES LIKE 'partiresul'");
            if ($stmt && $stmt->rowCount() > 0) $tienePartiresul = true;

            $sql = "SELECT 
                i.*, i.id_club as codigo_club,
                u.nombre as nombre_completo, u.username, u.sexo, u.cedula,
                c.id as club_id_from_join, c.nombre as club_nombre, c.logo as club_logo,
                0 as ganadas_por_forfait";
            $ig = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
            $ie = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
            $ip = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
            $sql .= " FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id LEFT JOIN clubes c ON i.id_club = c.id
                WHERE i.torneo_id = ? AND (i.estatus IS NULL OR (i.estatus != 4 AND i.estatus != 'retirado'))
                ORDER BY COALESCE(i.id_club, -1) ASC, $ig DESC, $ie DESC, $ip DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$torneo_id]);
            $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $clubes = [];
            foreach ($todos as $j) {
                $cid = (int)($j['id_club'] ?? $j['codigo_club'] ?? 0);
                if ($cid === 0) $cid = -1;
                if (!isset($clubes[$cid])) {
                    $st = $pdo->prepare("SELECT id, nombre, logo FROM clubes WHERE id = ?");
                    $st->execute([$cid === -1 ? 0 : $cid]);
                    $ci = $st->fetch(PDO::FETCH_ASSOC);
                    $clubes[$cid] = [
                        'club_id' => $cid,
                        'club_nombre' => $ci['nombre'] ?? ($j['club_nombre'] ?? 'Sin Club'),
                        'club_logo' => $ci['logo'] ?? null,
                        'jugadores' => [],
                        'total_ganados' => 0,
                        'total_perdidos' => 0,
                        'total_efectividad' => 0,
                        'total_puntos' => 0,
                        'total_ptosrnk' => 0,
                        'total_gff' => 0,
                        'mejor_posicion' => 999,
                    ];
                }
                if (count($clubes[$cid]['jugadores']) < $topN) {
                    $clubes[$cid]['jugadores'][] = $j;
                    $clubes[$cid]['total_ganados'] += (int)($j['ganados'] ?? 0);
                    $clubes[$cid]['total_perdidos'] += (int)($j['perdidos'] ?? 0);
                    $clubes[$cid]['total_efectividad'] += (int)($j['efectividad'] ?? 0);
                    $clubes[$cid]['total_puntos'] += (int)($j['puntos'] ?? 0);
                    $clubes[$cid]['total_ptosrnk'] += (int)($j['ptosrnk'] ?? 0);
                    $clubes[$cid]['total_gff'] += (int)($j['ganadas_por_forfait'] ?? $j['gff'] ?? 0);
                    $pos = (int)($j['posicion'] ?? 0);
                    if ($pos > 0 && $pos < $clubes[$cid]['mejor_posicion']) {
                        $clubes[$cid]['mejor_posicion'] = $pos;
                    }
                }
            }

            foreach ($clubes as $cid => &$c) {
                $n = count($c['jugadores']);
                $c['promedio_efectividad'] = $n > 0 ? (int)round($c['total_efectividad'] / $n) : 0;
                $c['promedio_puntos'] = $n > 0 ? (int)round($c['total_puntos'] / $n) : 0;
                if ($c['mejor_posicion'] == 999) $c['mejor_posicion'] = 0;
            }
            unset($c);

            usort($clubes, function ($a, $b) {
                if ($a['total_ganados'] != $b['total_ganados']) return $b['total_ganados'] <=> $a['total_ganados'];
                if ($a['total_efectividad'] != $b['total_efectividad']) return $b['total_efectividad'] <=> $a['total_efectividad'];
                return $b['total_puntos'] <=> $a['total_puntos'];
            });

            return $clubes;
        } catch (Exception $e) {
            error_log("ResultadosPublicHelper getResultadosPorClub: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Equipos resumido (orden de clasificación)
     */
    public static function getResultadosEquiposResumido(PDO $pdo, int $torneo_id, int $limit = 50, int $offset = 0): array
    {
        try {
            $ordG = InscritosHelper::sqlExprColumnaNumerica('e.ganados');
            $ordE = InscritosHelper::sqlExprColumnaNumerica('e.efectividad');
            $ordP = InscritosHelper::sqlExprColumnaNumerica('e.puntos');
            $stmt = $pdo->prepare("
                SELECT e.id as equipo_id, e.codigo_equipo, e.nombre_equipo, e.id_club, c.nombre as club_nombre,
                    e.posicion, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion, e.gff
                FROM equipos e
                LEFT JOIN clubes c ON e.id_club = c.id
                WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
                ORDER BY $ordG DESC, $ordE DESC, $ordP DESC, e.codigo_equipo ASC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset
            );
            $stmt->execute([$torneo_id]);
            $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($equipos)) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT i.codigo_equipo FROM inscritos i
                    WHERE i.torneo_id = ? AND i.codigo_equipo IS NOT NULL AND i.codigo_equipo != '' AND (i.estatus IS NULL OR (i.estatus != 4 AND i.estatus != 'retirado'))
                    ORDER BY i.codigo_equipo ASC LIMIT " . (int)$limit . " OFFSET " . (int)$offset
                );
                $stmt->execute([$torneo_id]);
                $cods = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $sxG = InscritosHelper::sqlExprColumnaNumerica('ganados');
                $sxPe = InscritosHelper::sqlExprColumnaNumerica('perdidos');
                $sxE = InscritosHelper::sqlExprColumnaNumerica('efectividad');
                $sxP = InscritosHelper::sqlExprColumnaNumerica('puntos');
                $st3 = $pdo->prepare("SELECT SUM($sxG) g, SUM($sxPe) p, SUM($sxE) ef, SUM($sxP) pt FROM inscritos WHERE torneo_id = ? AND codigo_equipo = ? AND (estatus IS NULL OR (estatus != 4 AND estatus != 'retirado'))");
                foreach ($cods as $cod) {
                    $st2 = $pdo->prepare("
                        SELECT i.codigo_equipo, CONCAT('Equipo ', i.codigo_equipo) as nombre_equipo, i.id_club, c.nombre as club_nombre
                        FROM inscritos i LEFT JOIN clubes c ON i.id_club = c.id
                        WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND (i.estatus IS NULL OR (i.estatus != 4 AND i.estatus != 'retirado')) LIMIT 1
                    ");
                    $st2->execute([$torneo_id, $cod]);
                    $eq = $st2->fetch(PDO::FETCH_ASSOC);
                    if ($eq) {
                        $st3->execute([$torneo_id, $cod]);
                        $s = $st3->fetch(PDO::FETCH_ASSOC);
                        $equipos[] = [
                            'equipo_id' => null,
                            'codigo_equipo' => $cod,
                            'nombre_equipo' => $eq['nombre_equipo'],
                            'id_club' => $eq['id_club'],
                            'club_nombre' => $eq['club_nombre'],
                            'posicion' => 0,
                            'ganados' => (int)($s['g'] ?? 0),
                            'perdidos' => (int)($s['p'] ?? 0),
                            'efectividad' => (int)($s['ef'] ?? 0),
                            'puntos' => (int)($s['pt'] ?? 0),
                            'sancion' => 0,
                            'gff' => 0,
                        ];
                    }
                }
            }
            return $equipos;
        } catch (Exception $e) {
            error_log("ResultadosPublicHelper getResultadosEquiposResumido: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Equipos detallado con jugadores
     */
    public static function getResultadosEquiposDetallado(PDO $pdo, int $torneo_id, int $limit = 20, int $offset = 0): array
    {
        $equipos = self::getResultadosEquiposResumido($pdo, $torneo_id, $limit, $offset);
        foreach ($equipos as &$eq) {
            $cod = $eq['codigo_equipo'];
            $stmt = $pdo->prepare("
                SELECT i.id_usuario, u.nombre as nombre_completo, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.ptosrnk, " . \ResultadosReporteData::sqlGanadasPorForfaitSubquery('i') . " AS gff, i.sancion, " . \ResultadosReporteData::sqlTarjetaEfectivaSubquery('i') . " AS tarjeta
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND (i.estatus IS NULL OR (i.estatus != 4 AND i.estatus != 'retirado'))
                ORDER BY i.ganados DESC, i.efectividad DESC, i.puntos DESC
            ");
            $stmt->execute([$torneo_id, $cod]);
            $eq['jugadores'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($eq);
        return $equipos;
    }
}
