<?php

declare(strict_types=1);

/**
 * Carga automática de resultados para cualquier ronda (pruebas / demo).
 *
 * Por cada mesa: puntos aleatorios diversos (pareja A vs B).
 * Incidencias sobre el plantel de la ronda:
 *   ~3% forfait (configurable)
 *   sanciones -80 y -40
 *   tarjetas amarillas (tarjeta=1, sancion=0, sin forfait)
 *
 * Usa la misma lógica que el formulario: TournamentActionHandler::aplicarResultadosMesaCore.
 *
 * Uso:
 *   php scripts/carga_automatica_resultados_ronda.php <id_torneo> <partida>
 *   php scripts/carga_automatica_resultados_ronda.php --dry-run 2 2
 *   php scripts/carga_automatica_resultados_ronda.php --seed=42 2 2 --report=dist/reporte_r2.html
 *   php scripts/carga_automatica_resultados_ronda.php 2 2 --ff-pct=3 --sancion-80=2 --sancion-40=2 --amarilla=5
 *
 * Opciones:
 *   --dry-run          Simula sin escribir en BD (genera reporte igual)
 *   --seed=N           Semilla aleatoria reproducible
 *   --report=ruta.html Guarda reporte HTML de faltas/sanciones
 *   --user-id=N        registrado_por (default 1)
 *   --ff-pct=N         % forfait (default 3)
 *   --sancion-80=N     % sanción 80 (default 2)
 *   --sancion-40=N     % sanción 40 (default 2)
 *   --amarilla=N       % amarilla sin puntos (default 5)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/CargaAutomaticaResultadosRondaService.php';

$dryRun = false;
$seed = null;
$reportPath = null;
$userId = 1;
$ffPct = 3.0;
$s80Pct = 2.0;
$s40Pct = 2.0;
$amarillaPct = 5.0;
$posArgs = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--seed=(\d+)$/', $arg, $m)) {
        $seed = (int) $m[1];
        continue;
    }
    if (preg_match('/^--report=(.+)$/', $arg, $m)) {
        $reportPath = $m[1];
        continue;
    }
    if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
        $userId = (int) $m[1];
        continue;
    }
    if (preg_match('/^--ff-pct=([\d.]+)$/', $arg, $m)) {
        $ffPct = (float) $m[1];
        continue;
    }
    if (preg_match('/^--sancion-80=([\d.]+)$/', $arg, $m)) {
        $s80Pct = (float) $m[1];
        continue;
    }
    if (preg_match('/^--sancion-40=([\d.]+)$/', $arg, $m)) {
        $s40Pct = (float) $m[1];
        continue;
    }
    if (preg_match('/^--amarilla=([\d.]+)$/', $arg, $m)) {
        $amarillaPct = (float) $m[1];
        continue;
    }
    $posArgs[] = $arg;
}

$torneoId = (int) ($posArgs[0] ?? 0);
$partida = (int) ($posArgs[1] ?? 0);

if ($torneoId <= 0 || $partida <= 0) {
    fwrite(STDERR, "Uso: php scripts/carga_automatica_resultados_ronda.php [opciones] <id_torneo> <partida>\n");
    fwrite(STDERR, "Opciones: --dry-run --seed=N --report=archivo.html --user-id=N\n");
    fwrite(STDERR, "          --ff-pct=3 --sancion-80=2 --sancion-40=2 --amarilla=5\n");
    exit(1);
}

$pdo = DB::pdo();

$resultado = CargaAutomaticaResultadosRondaService::ejecutar($pdo, $torneoId, $partida, [
    'ff_pct' => $ffPct,
    'sancion_80_pct' => $s80Pct,
    'sancion_40_pct' => $s40Pct,
    'amarilla_pct' => $amarillaPct,
    'registrado_por' => $userId,
    'dry_run' => $dryRun,
    'seed' => $seed,
]);

CargaAutomaticaResultadosRondaService::imprimirResumenConsola($resultado);

if ($reportPath === null) {
    $dist = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist';
    if (!is_dir($dist)) {
        @mkdir($dist, 0755, true);
    }
    $reportPath = $dist . DIRECTORY_SEPARATOR . "reporte_faltas_torneo{$torneoId}_r{$partida}_" . date('Y-m-d_His') . '.html';
}

$dirReport = dirname($reportPath);
if ($dirReport !== '' && !is_dir($dirReport)) {
    @mkdir($dirReport, 0755, true);
}

if (($resultado['reporte_html'] ?? '') !== '') {
    file_put_contents($reportPath, $resultado['reporte_html']);
    echo "\nReporte HTML: {$reportPath}\n";
}

exit($resultado['success'] ? 0 : 1);
