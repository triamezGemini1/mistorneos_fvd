<?php
/**
 * Recalcula efectividad/resultados de todas las mesas registradas de un torneo
 * usando la lógica corregida (tarjeta grave prevalece sobre forfait).
 *
 * Uso: php scripts/recalcular_efectividad_torneo.php TORNEO_ID [--dry-run]
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/TournamentActionHandler.php';

define('TORNEO_GESTION_SKIP_AUTH', true);
define('TORNEO_GESTION_SKIP_ROUTER', true);
require_once __DIR__ . '/../modules/torneo_gestion.php';

$torneoId = (int) ($argv[1] ?? 0);
$dryRun = in_array('--dry-run', $argv, true);

if ($torneoId <= 0) {
    fwrite(STDERR, "Uso: php scripts/recalcular_efectividad_torneo.php TORNEO_ID [--dry-run]\n");
    exit(1);
}

$pdo = DB::pdo();
$st = $pdo->prepare("
    SELECT DISTINCT partida, mesa
    FROM partiresul
    WHERE id_torneo = ? AND mesa > 0 AND registrado = 1
    ORDER BY partida, mesa
");
$st->execute([$torneoId]);
$mesas = $st->fetchAll(PDO::FETCH_ASSOC);

echo ($dryRun ? '[DRY-RUN] ' : '') . 'Mesas a recalcular: ' . count($mesas) . "\n";

$stFilas = $pdo->prepare("
    SELECT id, id_usuario, secuencia, resultado1, resultado2, ff, tarjeta, sancion, chancleta, zapato
    FROM partiresul
    WHERE id_torneo = ? AND partida = ? AND mesa = ?
    ORDER BY secuencia
");

$procesadas = 0;
$errores = 0;

foreach ($mesas as $m) {
    $partida = (int) $m['partida'];
    $mesa = (int) $m['mesa'];
    $stFilas->execute([$torneoId, $partida, $mesa]);
    $filas = $stFilas->fetchAll(PDO::FETCH_ASSOC);
    if (count($filas) !== 4) {
        echo "Omitida mesa {$partida}-{$mesa}: " . count($filas) . " filas\n";
        continue;
    }

    $jugadores = [];
    foreach ($filas as $f) {
        $jugadores[] = [
            'id' => (int) $f['id'],
            'id_usuario' => (int) $f['id_usuario'],
            'secuencia' => (int) $f['secuencia'],
            'resultado1' => (string) ($f['resultado1'] ?? '0'),
            'resultado2' => (string) ($f['resultado2'] ?? '0'),
            'ff' => (string) ((int) ($f['ff'] ?? 0)),
            'tarjeta' => (string) ((int) ($f['tarjeta'] ?? 0)),
            'sancion' => (string) ((int) ($f['sancion'] ?? 0)),
            'chancleta' => (string) ((int) ($f['chancleta'] ?? 0)),
            'zapato' => (string) ((int) ($f['zapato'] ?? 0)),
        ];
    }

    if ($dryRun) {
        $procesadas++;
        continue;
    }

    try {
        $pdo->beginTransaction();
        Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
            $pdo,
            $torneoId,
            $partida,
            $mesa,
            $jugadores,
            1,
            ''
        );
        $pdo->commit();
        $procesadas++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores++;
        echo "Error mesa {$partida}-{$mesa}: " . $e->getMessage() . "\n";
    }
}

if (! $dryRun && $procesadas > 0) {
    actualizarEstadisticasInscritos($torneoId, true);
    echo "Estadísticas de inscritos actualizadas.\n";
}

echo "Procesadas: {$procesadas}, errores: {$errores}\n";
