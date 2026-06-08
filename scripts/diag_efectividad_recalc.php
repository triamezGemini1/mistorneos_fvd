<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/partiresul_efectividad_funcs.php';
require_once __DIR__ . '/../lib/TorneoCampoNumerico.php';

$pdo = DB::pdo();
$torneoId = (int) ($argv[1] ?? 1);
$inscritoId = (int) ($argv[2] ?? 0);

$stT = $pdo->prepare('SELECT puntos FROM tournaments WHERE id = ?');
$stT->execute([$torneoId]);
$puntosTorneo = (int) ($stT->fetchColumn() ?: 200);

echo "Torneo {$torneoId}, puntos={$puntosTorneo}\n\n";

$sql = "SELECT pr.partida, pr.mesa, pr.secuencia, pr.resultado1, pr.resultado2, pr.efectividad, pr.ff, pr.tarjeta, pr.sancion, pr.registrado
FROM partiresul pr
WHERE pr.id_torneo = ? AND pr.mesa > 0 AND pr.registrado = 1";
$params = [$torneoId];
if ($inscritoId > 0) {
    $sql .= ' AND pr.id_usuario = ?';
    $params[] = $inscritoId;
}
$sql .= ' ORDER BY pr.partida, pr.mesa, pr.secuencia';

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Group by mesa
$mesas = [];
foreach ($rows as $r) {
    $key = $r['partida'] . '-' . $r['mesa'];
    $mesas[$key][] = $r;
}

$issues = [];
foreach ($mesas as $key => $jugadores) {
    if (count($jugadores) !== 4) {
        continue;
    }
    $hayFf = false;
    $hayTg = false;
    foreach ($jugadores as $j) {
        if ((int) $j['ff'] === 1) {
            $hayFf = true;
        }
        $t = (int) TorneoCampoNumerico::codigoTarjeta((string) ($j['tarjeta'] ?? '0'));
        if ($t === 3 || $t === 4) {
            $hayTg = true;
        }
    }

    foreach ($jugadores as $j) {
        $r1 = (int) $j['resultado1'];
        $r2 = (int) $j['resultado2'];
        $ff = (int) $j['ff'];
        $tarjeta = (int) TorneoCampoNumerico::codigoTarjeta((string) ($j['tarjeta'] ?? '0'));
        $sancion = (int) $j['sancion'];
        $efDb = (int) $j['efectividad'];

        if ($hayFf) {
            $calc = calcularEfectividadForfait($ff === 1, $puntosTorneo);
            $efEsp = $calc['efectividad'];
        } elseif ($hayTg) {
            $calc = calcularEfectividadTarjetaGrave($tarjeta === 3 || $tarjeta === 4, $puntosTorneo);
            $efEsp = $calc['efectividad'];
        } elseif ($sancion > 0) {
            $efEsp = evaluarSancionIndividual($r1, $r2, $sancion, $puntosTorneo)['efectividad'];
        } else {
            $efEsp = calcularEfectividad($r1, $r2, $puntosTorneo, $ff, $tarjeta, 0);
        }

        if ($efDb !== $efEsp) {
            $issues[] = [
                'mesa' => $key,
                'seq' => $j['secuencia'],
                'r1' => $r1,
                'r2' => $r2,
                'ff' => $ff,
                'tarjeta' => $tarjeta,
                'ef_db' => $efDb,
                'ef_esperada' => $efEsp,
                'hay_ff_mesa' => $hayFf,
                'hay_tg_mesa' => $hayTg,
            ];
        }
    }
}

echo 'Discrepancias ef DB vs recalculo: ' . count($issues) . "\n";
foreach (array_slice($issues, 0, 30) as $i) {
    echo json_encode($i, JSON_UNESCAPED_UNICODE) . "\n";
}

// Resumen individual totales vs inscrito
if ($inscritoId > 0) {
    define('TORNEO_GESTION_SKIP_AUTH', true);
    define('TORNEO_GESTION_SKIP_ROUTER', true);
    require_once __DIR__ . '/../modules/torneo_gestion.php';
    $data = obtenerDatosResumenIndividual($torneoId, $inscritoId);
    $tot = $data['totales']['efectividad'] ?? 0;
    $ins = (int) ($data['inscrito']['efectividad'] ?? 0);
    echo "\nResumen totales ef={$tot}, inscrito ef={$ins}\n";
}
