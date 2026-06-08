<?php
/**
 * HTML de cuerpo para impresión/PDF de reportes de resultados y clasificación (misma fuente que vista impresa).
 */
declare(strict_types=1);

require_once __DIR__ . '/ResultadosReporteData.php';
require_once __DIR__ . '/ResultadosPorClubHelper.php';

final class ResultadosReportesPrintHtml
{
    /**
     * Orden de tipos para el reporte combinado "todos" (respeta modalidad equipos).
     *
     * @return list<string>
     */
    public static function tiposParaTodos(bool $esEquipos): array
    {
        $tipos = ['posiciones', 'clubes_resumido', 'clubes_detallado'];
        if ($esEquipos) {
            $tipos[] = 'general';
            $tipos[] = 'equipos_resumido';
            $tipos[] = 'equipos_detallado';
        }
        $tipos[] = 'consolidado';

        return $tipos;
    }

    /**
     * @param callable(string): string $esc
     * @param string|null $generoGet M o F vía GET (PDF/impresión); evita mezclar géneros en clasificación.
     */
    public static function renderBody(\PDO $pdo, int $torneo_id, array $torneo, string $tipo, callable $esc, ?string $generoGet = null): string
    {
        $nombreTorneo = $esc($torneo['nombre'] ?? '');
        $fechaTor = $esc($torneo['fechator'] ?? '');
        $fechaGen = $esc(date('d/m/Y H:i'));

        ob_start();
        if ($tipo === 'clubes_resumido') {
            $topN = max(1, (int)($torneo['pareclub'] ?? 8));
            $dataClub = obtenerTopJugadoresPorClub($pdo, $torneo_id, $topN);
            echo '<h1>Clubes — resumido — ' . $nombreTorneo . '</h1><div class="meta">Letter · Top ' . $topN . ' · ' . $fechaGen . '</div>';
            echo '<table class="rep-table"><thead><tr><th>' . $esc(ResultadosReporteData::etiquetaAsociacion()) . '</th><th>Jug.</th><th>∑G</th><th>∑P</th><th>Prom.ef.</th><th>∑Pts</th><th>∑GFF</th><th>Mej.pos</th></tr></thead><tbody>';
            $ri = 0;
            foreach ($dataClub['estadisticas'] as $st) {
                ++$ri;
                $alt = ($ri % 2 === 0) ? ' class="row-alt"' : '';
                echo '<tr' . $alt . '><td>' . $esc($st['club_nombre']) . '</td><td class="num">' . (int)$st['cantidad_jugadores'] . '</td><td class="num">' . (int)$st['total_ganados'] . '</td><td class="num">' . (int)$st['total_perdidos'] . '</td><td class="num">' . (int)$st['promedio_efectividad'] . '</td><td class="num">' . (int)$st['total_puntos_grupo'] . '</td><td class="num">' . (int)$st['total_gff'] . '</td><td class="num">' . (int)$st['mejor_posicion'] . '</td></tr>';
            }
            echo '</tbody></table>';
        } elseif ($tipo === 'clubes_detallado') {
            $topN = max(1, (int)($torneo['pareclub'] ?? 8));
            $dataClub = obtenerTopJugadoresPorClub($pdo, $torneo_id, $topN);
            echo '<h1>Clubes — detallado — ' . $nombreTorneo . '</h1><div class="meta">Letter · Top ' . $topN . ' · ' . $fechaGen . '</div>';
            $byClub = [];
            foreach ($dataClub['detalle'] as $row) {
                $byClub[$row['club_nombre']][] = $row;
            }
            foreach ($byClub as $clubNombre => $rows) {
                echo '<div class="club-block"><h2>' . $esc($clubNombre) . '</h2><table class="rep-table"><thead><tr><th>#</th><th>Jugador</th><th>Pos</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th></tr></thead><tbody>';
                $ri = 0;
                foreach ($rows as $r) {
                    ++$ri;
                    $alt = ($ri % 2 === 0) ? ' class="row-alt"' : '';
                    echo '<tr' . $alt . '><td class="num">' . (int)$r['ranking'] . '</td><td>' . $esc($r['nombre']) . '</td><td class="num">' . (int)$r['posicion'] . '</td><td class="num">' . (int)$r['ganados'] . '</td><td class="num">' . (int)$r['perdidos'] . '</td><td class="num">' . (int)$r['efectividad'] . '</td><td class="num">' . (int)$r['puntos'] . '</td><td class="num">' . (int)$r['ptosrnk'] . '</td><td class="num">' . (int)$r['gff'] . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        } elseif ($tipo === 'general' || $tipo === 'posiciones') {
            $data = ResultadosReporteData::cargar($pdo, $torneo_id, $torneo, $generoGet);
            $modalidadRep = (int) ($torneo['modalidad'] ?? 0);
            $esParejasRep = in_array($modalidadRep, [2, 4], true);
            $mostrarColEquipo = $modalidadRep === 3;
            $colJugadorRep = $esParejasRep ? 'Pareja' : 'Jugador';
            $h1p = $tipo === 'posiciones' ? 'Tabla de posiciones — ' : 'Resultados general — ';
            $gl = ResultadosReporteData::generoFiltroDesdeParametro($generoGet);
            $genLabel = ResultadosReporteData::etiquetaGeneroFiltro($gl);
            $colIdRep = $esParejasRep ? 'Cód. pareja' : ResultadosReporteData::etiquetaColumnaIdentificador(false);
            $colAsocRep = ResultadosReporteData::etiquetaAsociacion();
            echo '<h1>' . $h1p . $nombreTorneo . '</h1><div class="meta">' . $fechaTor . ' · ' . $fechaGen . ' · Clasificación: ' . $esc($genLabel) . '</div>';
            echo '<table class="rep-table"><thead><tr><th>Pos</th><th>' . $esc($colIdRep) . '</th><th>' . $esc($colJugadorRep) . '</th><th>' . $esc($colAsocRep) . '</th>';
            if ($mostrarColEquipo) {
                echo '<th>Equipo</th>';
            }
            echo '<th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th><th>Sanc.</th><th>Tarj.</th></tr></thead><tbody>';
            $n = 0;
            foreach ($data['participantes'] as $p) {
                ++$n;
                $alt = ($n % 2 === 0) ? ' class="row-alt"' : '';
                $pos = (int)($p['posicion'] ?? 0) ?: $n;
                $idTxt = $esc(ResultadosReporteData::textoIdentificadorFila($p, $esParejasRep));
                $asocTxt = $esc($p['club_nombre'] ?? ResultadosReporteData::etiquetaSinAsociacion());
                echo '<tr' . $alt . '><td class="num">' . $pos . '</td><td>' . $idTxt . '</td><td>' . $esc($p['nombre_completo'] ?? '') . '</td><td>' . $asocTxt . '</td>';
                if ($mostrarColEquipo) {
                    $eq = trim(($p['codigo_equipo'] ?? '') . ' ' . ($p['nombre_equipo'] ?? ''));
                    echo '<td>' . $esc($eq) . '</td>';
                }
                echo '<td class="num">' . (int)$p['ganados'] . '</td><td class="num">' . (int)$p['perdidos'] . '</td><td class="num">' . (int)$p['efectividad'] . '</td><td class="num">' . (int)$p['puntos'] . '</td><td class="num">' . (int)$p['ptosrnk'] . '</td><td class="num">' . (int)$p['gff'] . '</td><td class="num">' . (int)$p['sancion'] . '</td><td class="num">' . $esc(ResultadosReporteData::tarjetaTexto($p['tarjeta'] ?? 0)) . '</td></tr>';
            }
            echo '</tbody></table>';
        } elseif ($tipo === 'equipos_resumido') {
            $sql = "SELECT e.codigo_equipo, e.nombre_equipo, c.nombre AS club_nombre, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion FROM equipos e LEFT JOIN clubes c ON e.id_club = c.id WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo != '' ORDER BY e.ganados DESC, e.efectividad DESC, e.puntos DESC";
            $st = $pdo->prepare($sql);
            $st->execute([$torneo_id]);
            $eqs = $st->fetchAll(PDO::FETCH_ASSOC);
            echo '<h1>Equipos — resumido — ' . $nombreTorneo . '</h1><div class="meta">Letter · ' . $fechaGen . '</div>';
            echo '<table class="rep-table"><thead><tr><th>#</th><th>Cód.</th><th>Equipo</th><th>' . $esc(ResultadosReporteData::etiquetaAsociacion()) . '</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Sanc.</th></tr></thead><tbody>';
            $pos = 1;
            foreach ($eqs as $e) {
                $alt = ($pos % 2 === 0) ? ' class="row-alt"' : '';
                echo '<tr' . $alt . '><td class="num">' . $pos . '</td><td>' . $esc($e['codigo_equipo']) . '</td><td>' . $esc($e['nombre_equipo'] ?? '') . '</td><td>' . $esc($e['club_nombre'] ?? '') . '</td><td class="num">' . (int)$e['ganados'] . '</td><td class="num">' . (int)$e['perdidos'] . '</td><td class="num">' . (int)$e['efectividad'] . '</td><td class="num">' . (int)$e['puntos'] . '</td><td class="num">' . (int)($e['sancion'] ?? 0) . '</td></tr>';
                ++$pos;
            }
            echo '</tbody></table>';
        } elseif ($tipo === 'equipos_detallado') {
            $gffSql = ResultadosReporteData::sqlGffSubquery();
            $sqlEq = "SELECT e.codigo_equipo, e.nombre_equipo, c.nombre AS club_nombre, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion FROM equipos e LEFT JOIN clubes c ON e.id_club = c.id WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo != '' ORDER BY e.ganados DESC, e.efectividad DESC, e.puntos DESC";
            $eqs = $pdo->prepare($sqlEq);
            $eqs->execute([$torneo_id]);
            echo '<h1>Equipos — detallado — ' . $nombreTorneo . '</h1><div class="meta">Letter · ' . $fechaGen . '</div>';
            foreach ($eqs->fetchAll(PDO::FETCH_ASSOC) as $e) {
                echo '<div class="club-block"><h2>[' . $esc($e['codigo_equipo']) . '] ' . $esc($e['nombre_equipo'] ?? '') . ' — ' . $esc($e['club_nombre'] ?? '') . '</h2>';
                echo '<div class="meta">G ' . (int)($e['ganados'] ?? 0) . ' P ' . (int)($e['perdidos'] ?? 0) . ' Ef ' . (int)($e['efectividad'] ?? 0) . ' Pts ' . (int)($e['puntos'] ?? 0) . '</div>';
                $sj = $pdo->prepare("SELECT u.nombre AS nombre_completo, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.ptosrnk, {$gffSql} AS gff, i.sancion, i.tarjeta
            FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 'retirado'
            ORDER BY i.ganados DESC, i.efectividad DESC, i.puntos DESC");
                $sj->execute([$torneo_id, $e['codigo_equipo']]);
                echo '<table class="rep-table"><thead><tr><th>Jugador</th><th>Pos</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th></tr></thead><tbody>';
                $rj = 0;
                foreach ($sj->fetchAll(PDO::FETCH_ASSOC) as $j) {
                    ++$rj;
                    $alt = ($rj % 2 === 0) ? ' class="row-alt"' : '';
                    echo '<tr' . $alt . '><td>' . $esc($j['nombre_completo']) . '</td><td class="num">' . (int)$j['posicion'] . '</td><td class="num">' . (int)$j['ganados'] . '</td><td class="num">' . (int)$j['perdidos'] . '</td><td class="num">' . (int)$j['efectividad'] . '</td><td class="num">' . (int)$j['puntos'] . '</td><td class="num">' . (int)$j['ptosrnk'] . '</td><td class="num">' . (int)$j['gff'] . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        } else {
            $data = ResultadosReporteData::cargar($pdo, $torneo_id, $torneo, $generoGet);
            $participantes = $data['participantes'];
            $clubes = $data['resumen_clubes'];
            $equipos = $data['equipos'];
            $rondas = $data['rondas'];
            $esEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
            $glC = ResultadosReporteData::generoFiltroDesdeParametro($generoGet);
            $genLabelC = ResultadosReporteData::etiquetaGeneroFiltro($glC);
            echo '<h1>Reporte consolidado — ' . $nombreTorneo . '</h1><div class="meta">Fecha torneo: ' . $fechaTor . ' · Generado: ' . $fechaGen . ' · Clasificación: ' . $esc($genLabelC) . '</div>';
            if (!empty($rondas)) {
                echo '<h2>Rondas</h2><table class="rep-table"><thead><tr><th>Ronda</th><th>Mesas</th><th>Reg.</th></tr></thead><tbody>';
                $ri = 0;
                foreach ($rondas as $r) {
                    ++$ri;
                    $alt = ($ri % 2 === 0) ? ' class="row-alt"' : '';
                    echo '<tr' . $alt . '><td class="num">' . $esc($r['num_ronda']) . '</td><td class="num">' . $esc($r['mesas']) . '</td><td class="num">' . $esc($r['registros']) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            if ($esEquipos && !empty($equipos)) {
                echo '<h2>Equipos</h2><table class="rep-table"><thead><tr><th>Pos</th><th>Cód</th><th>Nombre</th><th>G</th><th>P</th><th>Ef</th><th>Pts</th></tr></thead><tbody>';
                $ei = 0;
                foreach ($equipos as $eq) {
                    ++$ei;
                    $alt = ($ei % 2 === 0) ? ' class="row-alt"' : '';
                    echo '<tr' . $alt . '><td class="num">' . $esc($eq['pos_equipo'] ?? '') . '</td><td>' . $esc($eq['codigo_equipo']) . '</td><td>' . $esc($eq['nombre_equipo'] ?? '') . '</td><td class="num">' . $esc($eq['g_eq'] ?? '') . '</td><td class="num">' . $esc($eq['p_eq'] ?? '') . '</td><td class="num">' . $esc($eq['ef_eq'] ?? '') . '</td><td class="num">' . $esc($eq['pts_eq'] ?? '') . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '<h2>Por asociación</h2><table class="rep-table"><thead><tr><th>' . $esc(ResultadosReporteData::etiquetaAsociacion()) . '</th><th>Jug</th><th>∑G</th><th>∑P</th></tr></thead><tbody>';
            $ci = 0;
            foreach ($clubes as $c) {
                ++$ci;
                $alt = ($ci % 2 === 0) ? ' class="row-alt"' : '';
                echo '<tr' . $alt . '><td>' . $esc($c['club_nombre']) . '</td><td class="num">' . $esc($c['jugadores']) . '</td><td class="num">' . $esc($c['sum_ganados']) . '</td><td class="num">' . $esc($c['sum_perdidos']) . '</td></tr>';
            }
            $esParejasPdfCons = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
            $colJugPdfCons = $esParejasPdfCons ? 'Pareja' : 'Jugador';
            $titClasPdf = $esParejasPdfCons ? 'Clasificación por pareja' : 'Clasificación individual';
            echo '</tbody></table><h2>' . $esc($titClasPdf) . '</h2><table class="rep-table"><thead><tr><th>Pos</th><th>' . $esc($colJugPdfCons) . '</th><th>' . $esc(ResultadosReporteData::etiquetaAsociacion()) . '</th><th>G</th><th>P</th><th>Pts</th></tr></thead><tbody>';
            $n = 0;
            foreach ($participantes as $p) {
                ++$n;
                $alt = ($n % 2 === 0) ? ' class="row-alt"' : '';
                $pos = (int)($p['posicion'] ?? 0) ?: $n;
                echo '<tr' . $alt . '><td class="num">' . $pos . '</td><td>' . $esc($p['nombre_completo'] ?? '') . '</td><td>' . $esc($p['club_nombre'] ?? '') . '</td><td class="num">' . $esc($p['ganados'] ?? '') . '</td><td class="num">' . $esc($p['perdidos'] ?? '') . '</td><td class="num">' . $esc($p['puntos'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        return (string)ob_get_clean();
    }

    public static function cssZebraPrint(): string
    {
        return '
        table.rep-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
        table.rep-table th, table.rep-table td { border: 1px solid #666; padding: 4px 6px; }
        table.rep-table thead th { background: #e5e7eb; font-weight: 700; }
        table.rep-table tbody tr.row-alt td { background: #f3f4f6; }
        ';
    }

    public static function cssZebraPdf(): string
    {
        return '
    table.rep-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.rep-table th, table.rep-table td { border: 1px solid #555; padding: 2px 4px; text-align: left; font-size: 7pt; }
    table.rep-table thead th { background: #e0e0e0; font-weight: bold; }
    table.rep-table tbody tr.row-alt td { background: #eceff4; }
    ';
    }
}
