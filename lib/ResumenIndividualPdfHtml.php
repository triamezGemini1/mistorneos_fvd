<?php

declare(strict_types=1);

require_once __DIR__ . '/ResultadosReporteData.php';

/**
 * HTML Letter para PDF del resumen individual de un jugador.
 */
final class ResumenIndividualPdfHtml
{
    /**
     * @param array<string, mixed> $data Salida de obtenerDatosResumenIndividual()
     */
    public static function render(array $data): string
    {
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $torneo = (array) ($data['torneo'] ?? []);
        $inscrito = (array) ($data['inscrito'] ?? []);
        $equipo = (array) ($data['equipo'] ?? []);
        $filas = (array) ($data['resumenParticipacion'] ?? []);
        $totales = (array) ($data['totales'] ?? []);
        $esEquipos = ! empty($data['es_modalidad_equipos']) && $equipo !== [];

        $nombreJugador = $esc($inscrito['nombre_completo'] ?? $inscrito['nombre'] ?? 'Jugador');
        $nombreTorneo = $esc($torneo['nombre'] ?? 'Torneo');
        $club = $esc($inscrito['nombre_club'] ?? 'Sin club');
        $numfvd = (int) ($inscrito['numfvd'] ?? 0);
        $fechaGen = date('d/m/Y H:i');

        if ($esEquipos) {
            $pos = (int) ($inscrito['clasiequi'] ?? 0);
            $g = (int) ($equipo['ganados'] ?? 0);
            $p = (int) ($equipo['perdidos'] ?? 0);
            $ef = (int) ($equipo['efectividad'] ?? 0);
            $pts = (int) ($equipo['puntos'] ?? 0);
            $sanc = (int) ($equipo['sancion'] ?? 0);
        } else {
            $pos = (int) ($inscrito['posicion'] ?? 0);
            $g = (int) ($inscrito['ganados'] ?? 0);
            $p = (int) ($inscrito['perdidos'] ?? 0);
            $ef = (int) ($inscrito['efectividad'] ?? 0);
            $pts = (int) ($inscrito['puntos'] ?? 0);
            $sanc = (int) ($inscrito['sancion'] ?? 0);
        }

        // Tipografía base +80% (×1.8) y negrita en cuerpo del reporte
        $css = '
            @page { size: letter portrait; margin: 10mm; }
            html, body { margin: 0; padding: 0; }
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 16.2pt;
                font-weight: bold;
                line-height: 1.25;
                color: #111;
            }
            .sheet {
                width: 100%;
                max-width: 8.5in;
                margin: 0 auto;
                box-sizing: border-box;
            }
            h1 {
                font-size: 25pt;
                font-weight: bold;
                margin: 0 0 4px 0;
                text-align: center;
            }
            .meta {
                font-size: 14.4pt;
                font-weight: bold;
                color: #333;
                margin-bottom: 10px;
                text-align: center;
            }
            .info { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 16.2pt; font-weight: bold; }
            .info th {
                text-align: center;
                width: 24%;
                background: #f3f4f6;
                padding: 6px 8px;
                border: 1px solid #555;
                font-weight: bold;
            }
            .info td { padding: 6px 8px; border: 1px solid #555; text-align: center; font-weight: bold; }
            .stats { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-weight: bold; }
            .stats td {
                border: 1px solid #555;
                text-align: center;
                vertical-align: middle;
                padding: 8px 4px;
                background: #fafafa;
                font-weight: bold;
            }
            .stats .lbl { font-size: 13.5pt; color: #333; display: block; margin-top: 4px; font-weight: bold; }
            .stats .val { font-size: 18pt; font-weight: bold; line-height: 1.15; }
            h2 {
                font-size: 18pt;
                font-weight: bold;
                margin: 0 0 8px 0;
                border-bottom: 2px solid #333;
                padding-bottom: 4px;
                text-align: center;
            }
            table.det {
                width: 100%;
                max-width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
                font-size: 14.4pt;
                font-weight: bold;
            }
            table.det th, table.det td {
                border: 1px solid #555;
                padding: 4px 3px;
                vertical-align: middle;
                line-height: 1.2;
                text-align: center;
                font-weight: bold;
            }
            table.det th { background: #e5e7eb; font-size: 13pt; font-weight: bold; }
            table.det .col-rnd { width: 5%; }
            table.det .col-mesa { width: 5%; }
            table.det .col-pareja { width: 11%; font-size: 12.5pt; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
            table.det .col-rivales { width: 30%; font-size: 12.5pt; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            table.det .col-r1, table.det .col-r2 { width: 7%; font-size: 14pt; }
            table.det .col-ef { width: 8%; font-size: 14pt; }
            table.det .col-sanc, table.det .col-tar { width: 6%; font-size: 14pt; }
            table.det .col-res { width: 10%; font-size: 13pt; }
            .id-fvd { color: #1e3a8a; font-weight: bold; }
            .win { color: #1d4ed8; font-weight: bold; }
            .loss { color: #b91c1c; font-weight: bold; }
            .pareja { background: #f3f4f6; }
            .rivales { background: #e5e7eb; }
            tfoot td { font-weight: bold; background: #fef3c7; font-size: 14pt; text-align: center; }
        ';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
        $html .= '<div class="sheet">';
        $html .= '<h1>Resumen individual</h1>';
        $html .= '<div class="meta">' . $nombreTorneo . ' · Generado ' . $fechaGen . '</div>';

        $html .= '<table class="info"><tbody>';
        $html .= '<tr><th>ID FVD</th><td><span class="id-fvd">' . ($numfvd > 0 ? $numfvd : '—') . '</span></td></tr>';
        $html .= '<tr><th>Jugador</th><td>' . $nombreJugador . '</td></tr>';
        $html .= '<tr><th>Club</th><td>' . $club . '</td></tr>';
        if ($esEquipos) {
            $html .= '<tr><th>Equipo</th><td>' . $esc(trim(($equipo['codigo_equipo'] ?? '') . ' ' . ($equipo['nombre_equipo'] ?? ''))) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<table class="stats"><tr>';
        $html .= '<td><span class="val">' . ($pos > 0 ? $pos . '°' : '—') . '</span><span class="lbl">Posición</span></td>';
        $html .= '<td><span class="val">' . $g . '</span><span class="lbl">Ganados</span></td>';
        $html .= '<td><span class="val">' . $p . '</span><span class="lbl">Perdidos</span></td>';
        $html .= '<td><span class="val">' . $ef . '</span><span class="lbl">Efectividad</span></td>';
        $html .= '<td><span class="val">' . $pts . '</span><span class="lbl">Puntos</span></td>';
        $html .= '<td><span class="val">' . $sanc . '</span><span class="lbl">Sanciones</span></td>';
        $html .= '</tr></table>';

        $html .= '<h2>Participación por ronda</h2>';

        if ($filas === []) {
            $html .= '<p style="text-align:center;font-weight:bold;">Sin partidas registradas.</p>';
        } else {
            $html .= '<table class="det"><thead><tr>';
            $html .= '<th class="col-rnd">Rnd</th><th class="col-mesa">M</th><th class="col-pareja">Pareja</th><th class="col-rivales">Contrarios</th>';
            $html .= '<th class="col-r1">R1</th><th class="col-r2">R2</th><th class="col-ef">Ef</th><th class="col-sanc">Sanc</th><th class="col-tar">T</th><th class="col-res">Res</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($filas as $partida) {
                $p = (array) $partida;
                $comp = (array) ($p['compañero'] ?? []);
                $contrarios = (array) ($p['contrarios'] ?? []);
                $rivalesTxt = [];
                foreach ($contrarios as $c) {
                    $rivalesTxt[] = self::etiquetaJugadorFvd((array) $c, $esc);
                }

                $r1 = $p['resultado1'] ?? null;
                $r2 = $p['resultado2'] ?? null;
                $sancF = $p['sancion'] ?? null;
                $r1Txt = $r1 === null ? '—' : (string) (int) $r1;
                if ($r1 !== null && (int) ($sancF ?? 0) > 0) {
                    $r1Txt .= ' (' . max(0, (int) $r1 - (int) $sancF) . ')';
                }

                $tarjetaLetra = '—';
                if (($p['tarjeta'] ?? null) !== null && (int) $p['tarjeta'] > 0) {
                    $tarjetaLetra = ResultadosReporteData::tarjetaLetraReporte($p['tarjeta']);
                    if ($tarjetaLetra === '') {
                        $tarjetaLetra = '—';
                    }
                }

                $resTxt = '—';
                if (isset($p['gano']) && $p['gano'] !== null) {
                    $resTxt = $p['gano']
                        ? '<span class="win">Ganó</span>'
                        : '<span class="loss">Perdió</span>';
                }

                $parejaTxt = $comp !== [] ? self::etiquetaJugadorFvd($comp, $esc) : '—';
                $contrariosHtml = $rivalesTxt !== [] ? implode('<br>', $rivalesTxt) : '—';

                $html .= '<tr>';
                $html .= '<td class="col-rnd">' . (int) ($p['partida'] ?? 0) . '</td>';
                $html .= '<td class="col-mesa">' . (int) ($p['mesa'] ?? 0) . '</td>';
                $html .= '<td class="pareja col-pareja" title="' . strip_tags($parejaTxt) . '">' . $parejaTxt . '</td>';
                $html .= '<td class="rivales col-rivales" title="' . strip_tags(str_replace('<br>', ' · ', $contrariosHtml)) . '">' . $contrariosHtml . '</td>';
                $html .= '<td class="col-r1">' . $r1Txt . '</td>';
                $html .= '<td class="col-r2">' . ($r2 === null ? '—' : (string) (int) $r2) . '</td>';
                $html .= '<td class="col-ef">' . ($p['efectividad'] === null ? '—' : (string) (int) $p['efectividad']) . '</td>';
                $html .= '<td class="col-sanc">' . ($sancF === null ? '—' : (string) (int) $sancF) . '</td>';
                $html .= '<td class="col-tar">' . $esc($tarjetaLetra) . '</td>';
                $html .= '<td class="col-res">' . $resTxt . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody><tfoot><tr>';
            $html .= '<td colspan="4">TOTALES</td>';
            $html .= '<td>' . (int) ($totales['resultado1'] ?? 0) . '</td>';
            $html .= '<td>' . (int) ($totales['resultado2'] ?? 0) . '</td>';
            $html .= '<td>' . (int) ($totales['efectividad'] ?? 0) . '</td>';
            $html .= '<td>' . (int) ($totales['sancion'] ?? 0) . '</td>';
            $html .= '<td>—</td>';
            $html .= '<td>' . (int) ($totales['ganados'] ?? 0) . ' G / ' . (int) ($totales['perdidos'] ?? 0) . ' P</td>';
            $html .= '</tr></tfoot></table>';
        }

        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * @param array<string, mixed> $jugador
     */
    private static function etiquetaJugadorFvd(array $jugador, callable $esc): string
    {
        $nf = (int) ($jugador['numfvd'] ?? 0);
        $nombre = trim((string) ($jugador['nombre'] ?? ''));
        if ($nombre === '') {
            return $nf > 0 ? '<span class="id-fvd">' . $nf . '</span>' : '—';
        }
        if ($nf > 0) {
            return '<span class="id-fvd">' . $nf . '</span> · ' . $esc($nombre);
        }

        return $esc($nombre);
    }
}
