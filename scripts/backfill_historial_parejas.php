<?php
/**
 * Rellena historial_parejas desde partiresul (incluye mesa si la columna existe).
 * Regla: jugador_1_id/jugador_2_id y llave = id_menor-id_mayor; parejas sec 1-2 y 3-4 por mesa.
 *
 * Uso: php scripts/backfill_historial_parejas.php [torneo_id]
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/NumfvdHelper.php';
require_once __DIR__ . '/../lib/PartiresulJugadorHelper.php';

$pdo = DB::pdo();
$torneoFiltro = isset($argv[1]) ? (int) $argv[1] : 0;

$tieneMesa = false;
try {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute(['historial_parejas', 'mesa']);
    $tieneMesa = (bool) $st->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo verificar columna mesa: {$e->getMessage()}\n");
    exit(1);
}

$cols = 'torneo_id, ronda_id, jugador_1_id, jugador_2_id, llave';
$placeholders = '(?, ?, ?, ?, ?)';
if ($tieneMesa) {
    $cols = 'torneo_id, ronda_id, mesa, jugador_1_id, jugador_2_id, llave';
    $placeholders = '(?, ?, ?, ?, ?, ?)';
}

$insert = $pdo->prepare(
    'INSERT IGNORE INTO historial_parejas (' . $cols . ') VALUES ' . $placeholders
);

$sql = 'SELECT id_torneo, partida, mesa, secuencia, id_usuario
        FROM partiresul
        WHERE mesa > 0 AND partida > 0';
$params = [];
if ($torneoFiltro > 0) {
    $sql .= ' AND id_torneo = ?';
    $params[] = $torneoFiltro;
}
$sql .= ' ORDER BY id_torneo, partida, mesa, secuencia';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$insertados = 0;
$mesaActual = null;
$jugadores = [];
$torneoId = null;
$partida = null;
$numMesa = 0;

$flush = static function () use (
    &$mesaActual,
    &$jugadores,
    &$torneoId,
    &$partida,
    &$numMesa,
    $insert,
    $tieneMesa,
    &$insertados
): void {
    if ($torneoId === null || $partida === null || $numMesa <= 0) {
        return;
    }
    $pares = [];
    if (count($jugadores) >= 4) {
        $pares[] = [min($jugadores[0], $jugadores[1]), max($jugadores[0], $jugadores[1])];
        $pares[] = [min($jugadores[2], $jugadores[3]), max($jugadores[2], $jugadores[3])];
    } elseif (count($jugadores) >= 2) {
        $pares[] = [min($jugadores[0], $jugadores[1]), max($jugadores[0], $jugadores[1])];
    }
    foreach ($pares as [$j1, $j2]) {
        if ($j1 <= 0 || $j2 <= 0) {
            continue;
        }
        if ($tieneMesa) {
            $insert->execute([$torneoId, $partida, $numMesa, $j1, $j2, $j1 . '-' . $j2]);
        } else {
            $insert->execute([$torneoId, $partida, $j1, $j2, $j1 . '-' . $j2]);
        }
        $insertados += (int) $insert->rowCount();
    }
};

foreach ($rows as $r) {
    $key = $r['id_torneo'] . '-' . $r['partida'] . '-' . $r['mesa'];
    if ($mesaActual !== $key) {
        $flush();
        $mesaActual = $key;
        $torneoId = (int) $r['id_torneo'];
        $partida = (int) $r['partida'];
        $numMesa = (int) $r['mesa'];
        $jugadores = [];
    }
    $jugadores[] = (int) $r['id_usuario'];
}
$flush();

echo "Backfill historial_parejas completado. Filas nuevas (IGNORE): {$insertados}";
echo $tieneMesa ? " (con mesa).\n" : " (sin columna mesa).\n";
