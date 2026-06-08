<?php

declare(strict_types=1);

require_once __DIR__ . '/TorneoCampoNumerico.php';
require_once __DIR__ . '/Tournament/Handlers/TournamentActionHandler.php';

use Tournament\Handlers\TournamentActionHandler;

/**
 * Carga automática de resultados por ronda (pruebas): puntos diversos por mesa,
 * sanciones -80/-40, amarillas sin puntos, ~3% forfait, reporte de incidencias.
 */
final class CargaAutomaticaResultadosRondaService
{
    public const TIPO_FF = 'forfait';
    public const TIPO_S80 = 'sancion_80';
    public const TIPO_S40 = 'sancion_40';
    public const TIPO_AMARILLA = 'tarjeta_amarilla';

    /** @var array<string, string> */
    private const LETRA_SEC = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];

    /**
     * @param array{
     *   ff_pct?: float,
     *   sancion_80_pct?: float,
     *   sancion_40_pct?: float,
     *   amarilla_pct?: float,
     *   registrado_por?: int,
     *   dry_run?: bool,
     *   seed?: int|null,
     * } $opciones
     * @return array{
     *   success: bool,
     *   message: string,
     *   mesas_procesadas: int,
     *   jugadores_total: int,
     *   incidencias: list<array<string, mixed>>,
     *   resumen: array<string, int>,
     *   reporte_html: string,
     * }
     */
    public static function ejecutar(PDO $pdo, int $torneoId, int $partida, array $opciones = []): array
    {
        $ffPct = (float) ($opciones['ff_pct'] ?? 3.0);
        $s80Pct = (float) ($opciones['sancion_80_pct'] ?? 2.0);
        $s40Pct = (float) ($opciones['sancion_40_pct'] ?? 2.0);
        $amarillaPct = (float) ($opciones['amarilla_pct'] ?? 5.0);
        $userId = (int) ($opciones['registrado_por'] ?? 1);
        $dryRun = !empty($opciones['dry_run']);
        $seed = $opciones['seed'] ?? null;

        if ($seed !== null) {
            mt_srand((int) $seed);
        }

        if ($torneoId <= 0 || $partida <= 0) {
            return self::error('Torneo y partida (ronda) deben ser mayores que 0.');
        }

        $stT = $pdo->prepare('SELECT id, nombre, puntos FROM tournaments WHERE id = ? LIMIT 1');
        $stT->execute([$torneoId]);
        $torneo = $stT->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            return self::error('Torneo no encontrado.');
        }

        $puntosTorneo = max(50, (int) ($torneo['puntos'] ?? 100));
        $nombreTorneo = (string) ($torneo['nombre'] ?? 'Torneo');

        $sqlFilas = 'SELECT pr.id, pr.id_torneo, pr.partida, pr.mesa, pr.id_usuario, pr.secuencia,
                            pr.resultado1, pr.resultado2, pr.ff, pr.tarjeta, pr.sancion, pr.chancleta, pr.zapato,
                            u.nombre AS nombre_usuario
                     FROM partiresul pr
                     INNER JOIN usuarios u ON u.id = pr.id_usuario
                     WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
                     ORDER BY pr.mesa ASC, pr.secuencia ASC';
        $stFilas = $pdo->prepare($sqlFilas);
        $stFilas->execute([$torneoId, $partida]);
        $todasFilas = $stFilas->fetchAll(PDO::FETCH_ASSOC);

        if ($todasFilas === []) {
            return self::error("No hay mesas asignadas para torneo {$torneoId}, ronda {$partida}. Genere la ronda primero.");
        }

        $porMesa = [];
        foreach ($todasFilas as $fila) {
            $m = (int) $fila['mesa'];
            $porMesa[$m][] = $fila;
        }

        foreach ($porMesa as $mesa => $jugadores) {
            if (count($jugadores) !== 4) {
                return self::error("La mesa {$mesa} tiene " . count($jugadores) . ' jugadores (se requieren 4).');
            }
        }

        $overlay = self::asignarIncidenciasAleatorias(
            $todasFilas,
            $ffPct,
            $s80Pct,
            $s40Pct,
            $amarillaPct
        );

        $incidencias = [];
        foreach ($overlay as $idPartiresul => $tipo) {
            $fila = null;
            foreach ($todasFilas as $r) {
                if ((int) $r['id'] === $idPartiresul) {
                    $fila = $r;
                    break;
                }
            }
            if ($fila === null) {
                continue;
            }
            $incidencias[] = self::filaIncidencia($fila, $tipo);
        }

        $resumen = [
            'forfait' => 0,
            'sancion_80' => 0,
            'sancion_40' => 0,
            'tarjeta_amarilla' => 0,
            'sin_incidencia' => 0,
        ];
        foreach ($todasFilas as $fila) {
            $id = (int) $fila['id'];
            $tipo = $overlay[$id] ?? null;
            if ($tipo === self::TIPO_FF) {
                $resumen['forfait']++;
            } elseif ($tipo === self::TIPO_S80) {
                $resumen['sancion_80']++;
            } elseif ($tipo === self::TIPO_S40) {
                $resumen['sancion_40']++;
            } elseif ($tipo === self::TIPO_AMARILLA) {
                $resumen['tarjeta_amarilla']++;
            } else {
                $resumen['sin_incidencia']++;
            }
        }

        $reporteHtml = self::generarReporteHtml(
            $nombreTorneo,
            $torneoId,
            $partida,
            $incidencias,
            $resumen,
            count($porMesa),
            $dryRun
        );

        if ($dryRun) {
            return [
                'success' => true,
                'message' => 'Simulación (dry-run): no se escribió en la base de datos.',
                'mesas_procesadas' => 0,
                'jugadores_total' => count($todasFilas),
                'incidencias' => $incidencias,
                'resumen' => $resumen,
                'reporte_html' => $reporteHtml,
            ];
        }

        $pdo->beginTransaction();
        try {
            $mesasProcesadas = 0;
            ksort($porMesa, SORT_NUMERIC);
            foreach ($porMesa as $mesa => $rows) {
                $jugadores = self::armarJugadoresMesa($rows, $puntosTorneo, $overlay);
                $err = TournamentActionHandler::aplicarResultadosMesaCore(
                    $pdo,
                    $torneoId,
                    $partida,
                    (int) $mesa,
                    $jugadores,
                    $userId,
                    'Carga automática de prueba'
                );
                if ($err !== null && $err !== '') {
                    throw new RuntimeException("Mesa {$mesa}: {$err}");
                }
                $mesasProcesadas++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::error($e->getMessage());
        }

        if (\function_exists('actualizarEstadisticasInscritos')) {
            actualizarEstadisticasInscritos($torneoId);
        }
        if (! \class_exists(\RankingTorneoRecalc::class, false)) {
            require_once __DIR__ . '/RankingTorneoRecalc.php';
        }
        \RankingTorneoRecalc::reclasificarSiUltimaRondaTorneoCompleta($torneoId);

        self::enriquecerIncidenciasDesdeBd($pdo, $incidencias);

        $reporteHtml = self::generarReporteHtml(
            $nombreTorneo,
            $torneoId,
            $partida,
            $incidencias,
            $resumen,
            $mesasProcesadas,
            false
        );

        return [
            'success' => true,
            'message' => "Ronda {$partida} cargada: {$mesasProcesadas} mesa(s), " . count($todasFilas) . ' jugadores.',
            'mesas_procesadas' => $mesasProcesadas,
            'jugadores_total' => count($todasFilas),
            'incidencias' => $incidencias,
            'resumen' => $resumen,
            'reporte_html' => $reporteHtml,
        ];
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return array<int, string> id partiresul => tipo
     */
    private static function asignarIncidenciasAleatorias(
        array $filas,
        float $ffPct,
        float $s80Pct,
        float $s40Pct,
        float $amarillaPct
    ): array {
        $ids = array_map(static fn ($r) => (int) $r['id'], $filas);
        shuffle($ids);
        $n = count($ids);
        $overlay = [];

        $nFf = self::cantidadPorcentaje($n, $ffPct, $n >= 4 ? 1 : 0);

        foreach (array_slice($ids, 0, $nFf) as $id) {
            $overlay[$id] = self::TIPO_FF;
        }

        $pool = array_values(array_filter($ids, static fn ($id) => !isset($overlay[$id])));
        shuffle($pool);

        $n80 = self::cantidadPorcentaje($n, $s80Pct, 0);
        $n40 = self::cantidadPorcentaje($n, $s40Pct, 0);
        $nAm = self::cantidadPorcentaje($n, $amarillaPct, 0);

        $idx = 0;
        for ($i = 0; $i < $n80 && $idx < count($pool); $i++, $idx++) {
            $overlay[$pool[$idx]] = self::TIPO_S80;
        }
        for ($i = 0; $i < $n40 && $idx < count($pool); $i++, $idx++) {
            $overlay[$pool[$idx]] = self::TIPO_S40;
        }
        for ($i = 0; $i < $nAm && $idx < count($pool); $i++, $idx++) {
            $overlay[$pool[$idx]] = self::TIPO_AMARILLA;
        }

        return $overlay;
    }

    private static function cantidadPorcentaje(int $total, float $pct, int $minimo): int
    {
        if ($total <= 0 || $pct <= 0) {
            return $minimo;
        }
        $n = (int) round($total * ($pct / 100.0));

        return max($minimo, min($total, $n));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $overlay
     * @return list<array<string, mixed>>
     */
    private static function armarJugadoresMesa(array $rows, int $puntosTorneo, array $overlay): array
    {
        $puntosMesa = self::generarPuntosAleatoriosMesa($puntosTorneo);
        $jugadores = [];

        foreach ($rows as $row) {
            $sec = (int) ($row['secuencia'] ?? 0);
            $esParejaA = $sec === 1 || $sec === 2;
            $r1 = $esParejaA ? $puntosMesa['pareja_a'] : $puntosMesa['pareja_b'];
            $r2 = $esParejaA ? $puntosMesa['pareja_b'] : $puntosMesa['pareja_a'];
            if ($sec === 2 || $sec === 4) {
                $r1 = max(0, $r1 + random_int(-8, 8));
            }

            $j = [
                'id' => (int) ($row['id'] ?? 0),
                'id_usuario' => (int) ($row['id_usuario'] ?? 0),
                'secuencia' => (string) $sec,
                'resultado1' => (string) $r1,
                'resultado2' => (string) $r2,
                'tarjeta' => '0',
                'sancion' => '0',
                'chancleta' => (string) random_int(0, 2),
                'zapato' => (string) random_int(0, 1),
            ];

            $tipo = $overlay[(int) $row['id']] ?? null;
            switch ($tipo) {
                case self::TIPO_FF:
                    $j['ff'] = '1';
                    $j['tarjeta'] = '0';
                    $j['sancion'] = '0';
                    break;
                case self::TIPO_S80:
                    $j['sancion'] = '80';
                    $j['tarjeta'] = '0';
                    break;
                case self::TIPO_S40:
                    $j['sancion'] = '40';
                    $j['tarjeta'] = '0';
                    break;
                case self::TIPO_AMARILLA:
                    $j['tarjeta'] = '1';
                    $j['sancion'] = '0';
                    break;
            }

            $jugadores[] = $j;
        }

        return $jugadores;
    }

    /**
     * @return array{pareja_a: int, pareja_b: int, gana_a: bool}
     */
    private static function generarPuntosAleatoriosMesa(int $puntosTorneo): array
    {
        $maxR = (int) round($puntosTorneo * 1.6);
        $ganaA = random_int(0, 1) === 1;

        $variantes = [
            [min($maxR, $puntosTorneo), min($maxR, (int) round($puntosTorneo * 0.72))],
            [min($maxR, (int) round($puntosTorneo * 0.88)), min($maxR, (int) round($puntosTorneo * 0.55))],
            [min($maxR, $puntosTorneo + random_int(-15, 0)), min($maxR, (int) round($puntosTorneo * 0.65))],
            [min($maxR, random_int((int) ($puntosTorneo * 0.75), $puntosTorneo)), min($maxR, random_int((int) ($puntosTorneo * 0.5), (int) ($puntosTorneo * 0.85)))],
        ];
        $par = $variantes[array_rand($variantes)];
        $pa = max(1, $par[0]);
        $pb = max(1, $par[1]);

        if ($ganaA && $pa <= $pb) {
            $pa = min($maxR, $pb + random_int(3, 25));
        }
        if (!$ganaA && $pb <= $pa) {
            $pb = min($maxR, $pa + random_int(3, 25));
        }

        return ['pareja_a' => $pa, 'pareja_b' => $pb, 'gana_a' => $ganaA];
    }

    /**
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private static function filaIncidencia(array $fila, string $tipo): array
    {
        $sec = (int) ($fila['secuencia'] ?? 0);

        return [
            'id_partiresul' => (int) $fila['id'],
            'mesa' => (int) $fila['mesa'],
            'secuencia' => $sec,
            'letra' => self::LETRA_SEC[$sec] ?? '?',
            'id_usuario' => (int) $fila['id_usuario'],
            'nombre' => (string) ($fila['nombre_usuario'] ?? ''),
            'tipo' => $tipo,
            'tipo_label' => self::etiquetaTipo($tipo),
            'sancion' => self::sancionEsperada($tipo),
            'tarjeta' => self::tarjetaEsperada($tipo),
            'ff' => $tipo === self::TIPO_FF ? 1 : 0,
            'resultado1' => null,
            'resultado2' => null,
            'efectividad' => null,
        ];
    }

    private static function sancionEsperada(string $tipo): int
    {
        if ($tipo === self::TIPO_S80) {
            return 80;
        }
        if ($tipo === self::TIPO_S40) {
            return 40;
        }

        return 0;
    }

    private static function tarjetaEsperada(string $tipo): int
    {
        return $tipo === self::TIPO_AMARILLA ? 1 : 0;
    }

    private static function etiquetaTipo(string $tipo): string
    {
        if ($tipo === self::TIPO_FF) {
            return 'Forfait';
        }
        if ($tipo === self::TIPO_S80) {
            return 'Sanción -80 pts';
        }
        if ($tipo === self::TIPO_S40) {
            return 'Sanción -40 pts';
        }
        if ($tipo === self::TIPO_AMARILLA) {
            return 'Tarjeta amarilla (sin sanción en puntos)';
        }

        return '—';
    }

    /**
     * @param list<array<string, mixed>> $incidencias
     */
    private static function enriquecerIncidenciasDesdeBd(PDO $pdo, array &$incidencias): void
    {
        if ($incidencias === []) {
            return;
        }
        $ids = array_map(static fn ($i) => (int) $i['id_partiresul'], $incidencias);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, resultado1, resultado2, efectividad, ff, tarjeta, sancion FROM partiresul WHERE id IN ({$placeholders})");
        $st->execute($ids);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['id']] = $r;
        }
        foreach ($incidencias as &$inc) {
            $bd = $map[(int) $inc['id_partiresul']] ?? null;
            if ($bd === null) {
                continue;
            }
            $inc['resultado1'] = (int) $bd['resultado1'];
            $inc['resultado2'] = (int) $bd['resultado2'];
            $inc['efectividad'] = (int) $bd['efectividad'];
            $inc['ff'] = (int) $bd['ff'];
            $inc['tarjeta'] = (int) $bd['tarjeta'];
            $inc['sancion'] = (int) $bd['sancion'];
        }
        unset($inc);
    }

    /**
     * @param list<array<string, mixed>> $incidencias
     * @param array<string, int> $resumen
     */
    public static function generarReporteHtml(
        string $nombreTorneo,
        int $torneoId,
        int $partida,
        array $incidencias,
        array $resumen,
        int $mesas,
        bool $dryRun
    ): string {
        $fecha = date('Y-m-d H:i:s');
        $modo = $dryRun ? 'Simulación (sin guardar)' : 'Resultados guardados';
        $rows = '';
        foreach ($incidencias as $inc) {
            $rows .= '<tr>'
                . '<td>' . (int) $inc['mesa'] . '</td>'
                . '<td>' . htmlspecialchars((string) $inc['letra'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $inc['nombre'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . (int) $inc['id_usuario'] . '</td>'
                . '<td>' . htmlspecialchars((string) $inc['tipo_label'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="num">' . (int) ($inc['sancion'] ?? 0) . '</td>'
                . '<td class="num">' . (int) ($inc['tarjeta'] ?? 0) . '</td>'
                . '<td class="num">' . (int) ($inc['ff'] ?? 0) . '</td>'
                . '<td class="num">' . ($inc['resultado1'] !== null ? (int) $inc['resultado1'] : '—') . '</td>'
                . '<td class="num">' . ($inc['resultado2'] !== null ? (int) $inc['resultado2'] : '—') . '</td>'
                . '<td class="num">' . ($inc['efectividad'] !== null ? (int) $inc['efectividad'] : '—') . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="11">Sin incidencias disciplinarias en esta corrida (solo puntos de mesa).</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte faltas y sanciones — Torneo {$torneoId} Ronda {$partida}</title>
<style>
body{font-family:system-ui,sans-serif;margin:1.5rem;color:#1e293b}
h1{font-size:1.25rem;color:#1e3a5f}
.meta{color:#64748b;font-size:0.9rem;margin-bottom:1rem}
.cards{display:flex;flex-wrap:wrap;gap:0.75rem;margin:1rem 0}
.card{background:#f1f5f9;border-radius:8px;padding:0.6rem 1rem;min-width:120px}
.card strong{display:block;font-size:1.4rem}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left}
th{background:#e2e8f0}
.num{text-align:right}
</style>
</head>
<body>
<h1>Reporte de faltas y sanciones</h1>
<p class="meta"><strong>{$modo}</strong><br>
Torneo: {$torneoId} — {$nombreTorneo}<br>
Ronda: {$partida} · Mesas: {$mesas} · Generado: {$fecha}</p>
<div class="cards">
<div class="card"><span>Forfait</span><strong>{$resumen['forfait']}</strong></div>
<div class="card"><span>Sanción 80</span><strong>{$resumen['sancion_80']}</strong></div>
<div class="card"><span>Sanción 40</span><strong>{$resumen['sancion_40']}</strong></div>
<div class="card"><span>Amarilla sin pts</span><strong>{$resumen['tarjeta_amarilla']}</strong></div>
<div class="card"><span>Sin incidencia</span><strong>{$resumen['sin_incidencia']}</strong></div>
</div>
<table>
<thead><tr>
<th>Mesa</th><th>Letra</th><th>Jugador</th><th>Id usuario</th><th>Incidencia</th>
<th>Sanción</th><th>Tarjeta</th><th>FF</th><th>R1</th><th>R2</th><th>Efect.</th>
</tr></thead>
<tbody>{$rows}</tbody>
</table>
<p class="meta">Tarjeta: 0=ninguna, 1=amarilla, 3=roja, 4=negra. Tras cargar, ejecute «Actualizar estadísticas» en el panel si hace falta.</p>
</body>
</html>
HTML;
    }

    /**
     * @return array{success: false, message: string, mesas_procesadas: int, jugadores_total: int, incidencias: array, resumen: array, reporte_html: string}
     */
    private static function error(string $msg): array
    {
        return [
            'success' => false,
            'message' => $msg,
            'mesas_procesadas' => 0,
            'jugadores_total' => 0,
            'incidencias' => [],
            'resumen' => [],
            'reporte_html' => '',
        ];
    }

    /**
     * @param list<array<string, mixed>> $incidencias
     */
    public static function imprimirResumenConsola(array $resultado): void
    {
        echo ($resultado['success'] ? 'OK: ' : 'ERROR: ') . ($resultado['message'] ?? '') . "\n";
        if (!empty($resultado['resumen'])) {
            $r = $resultado['resumen'];
            echo "Resumen: forfait={$r['forfait']}, s80={$r['sancion_80']}, s40={$r['sancion_40']}, amarilla={$r['tarjeta_amarilla']}, sin_incidencia={$r['sin_incidencia']}\n";
        }
        foreach ($resultado['incidencias'] ?? [] as $inc) {
            echo sprintf(
                "  Mesa %d %s | %s | %s | sanc=%d tarj=%d ff=%d\n",
                (int) $inc['mesa'],
                (string) $inc['letra'],
                (string) $inc['nombre'],
                (string) $inc['tipo_label'],
                (int) ($inc['sancion'] ?? 0),
                (int) ($inc['tarjeta'] ?? 0),
                (int) ($inc['ff'] ?? 0)
            );
        }
    }
}
