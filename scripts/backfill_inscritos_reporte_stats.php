<?php
/**
 * Rellena partiresul.triunfo_gff e inscritos.gff / partidas_bye para torneos existentes.
 * Uso: php scripts/backfill_inscritos_reporte_stats.php [torneo_id]
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/lib/InscritosReporteStatsHelper.php';
require_once dirname(__DIR__) . '/lib/PartiresulTriunfoGffHelper.php';

$pdo = DB::pdo();
$torneoArg = isset($argv[1]) ? (int) $argv[1] : 0;

InscritosReporteStatsHelper::ensureColumnas($pdo);
PartiresulTriunfoGffHelper::ensureColumna($pdo);

if ($torneoArg > 0) {
    $ids = [$torneoArg];
} else {
    $ids = array_map('intval', $pdo->query('SELECT DISTINCT torneo_id FROM inscritos ORDER BY torneo_id')->fetchAll(PDO::FETCH_COLUMN));
}

$n = 0;
foreach ($ids as $tid) {
    if ($tid <= 0) {
        continue;
    }
    PartiresulTriunfoGffHelper::backfillTorneo($pdo, $tid);
    InscritosReporteStatsHelper::sincronizarGffYBye($pdo, $tid);
    $n++;
    echo "OK torneo {$tid}\n";
}

echo "Completado: {$n} torneo(s).\n";
