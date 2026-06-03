<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/PartiresulJugadorHelper.php';
require_once __DIR__ . '/../lib/ReporteParejasRepetidasService.php';

$tid = isset($argv[1]) ? (int) $argv[1] : 2;
$pdo = DB::pdo();

$st = $pdo->prepare('SELECT id, nombre, modalidad, rondas FROM tournaments WHERE id = ?');
$st->execute([$tid]);
$t = $st->fetch(PDO::FETCH_ASSOC);
if (!$t) {
    echo "Torneo {$tid} no encontrado.\n";
    exit(1);
}

echo "Torneo #{$tid}: {$t['nombre']} | modalidad={$t['modalidad']} | rondas_plan={$t['rondas']}\n";

$ult = (int) $pdo->prepare('SELECT COALESCE(MAX(partida),0) FROM partiresul WHERE id_torneo = ? AND mesa > 0')
    ->execute([$tid]) ?: 0;
$stUlt = $pdo->prepare('SELECT COALESCE(MAX(partida),0) FROM partiresul WHERE id_torneo = ? AND mesa > 0');
$stUlt->execute([$tid]);
$ult = (int) $stUlt->fetchColumn();
echo "Ultima ronda con mesas: {$ult}\n";

try {
    $stH = $pdo->prepare('SELECT COUNT(*) FROM historial_parejas WHERE torneo_id = ?');
    $stH->execute([$tid]);
    echo 'Filas historial_parejas: ' . (int) $stH->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "historial_parejas: tabla no disponible\n";
}

PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);
echo 'Esquema partiresul: ' . (PartiresulJugadorHelper::soloNumfvdEnPartiresul($pdo) ? 'solo numfvd' : 'mixto/legacy') . "\n";

if (PartiresulJugadorHelper::tieneColumnaNumfvd($pdo)) {
    $stSin = $pdo->prepare(
        'SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND mesa > 0 AND COALESCE(NULLIF(numfvd, 0), 0) = 0'
    );
    $stSin->execute([$tid]);
    $stTot = $pdo->prepare('SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND mesa > 0');
    $stTot->execute([$tid]);
    echo 'partiresul (mesa>0) sin numfvd: ' . (int) $stSin->fetchColumn() . ' / ' . (int) $stTot->fetchColumn() . "\n";
}

$reporte = (new ReporteParejasRepetidasService())->construirReporte($tid, $pdo, 2);
echo 'Parejas repetidas: grupos=' . (int) $reporte['total_grupos']
    . ' | sin_historial=' . (!empty($reporte['sin_historial']) ? 'si' : 'no') . "\n";
echo 'Mensaje reporte: ' . ($reporte['mensaje'] ?? '') . "\n";

$stR = $pdo->prepare(
    'SELECT partida, COUNT(*) AS filas, COUNT(DISTINCT mesa) AS mesas
     FROM partiresul WHERE id_torneo = ? AND mesa > 0 GROUP BY partida ORDER BY partida'
);
$stR->execute([$tid]);
echo "Rondas en partiresul:\n";
foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "  ronda {$row['partida']}: {$row['filas']} filas, {$row['mesas']} mesas distintas\n";
}

try {
    $stHr = $pdo->prepare(
        'SELECT ronda_id, COUNT(*) AS filas FROM historial_parejas WHERE torneo_id = ? GROUP BY ronda_id ORDER BY ronda_id'
    );
    $stHr->execute([$tid]);
    echo "Historial por ronda:\n";
    foreach ($stHr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  ronda {$row['ronda_id']}: {$row['filas']} parejas\n";
    }
} catch (Throwable $e) {
    // ignore
}

$stP = $pdo->prepare(
    'SELECT ronda_id, mesa, jugador_1_id, jugador_2_id, llave
     FROM historial_parejas
     WHERE torneo_id = ? AND llave IN (\'4370-5900\', \'5900-4370\')
     ORDER BY ronda_id'
);
$stP->execute([$tid]);
$filasP = $stP->fetchAll(PDO::FETCH_ASSOC);
echo "Historial llave 4370-5900: " . count($filasP) . " fila(s)\n";
foreach ($filasP as $f) {
    echo "  r{$f['ronda_id']} mesa {$f['mesa']} ({$f['jugador_1_id']}-{$f['jugador_2_id']})\n";
}
