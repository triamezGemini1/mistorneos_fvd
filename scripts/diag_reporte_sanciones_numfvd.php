<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/lib/InscritosReporteStatsHelper.php';
require_once dirname(__DIR__) . '/lib/PartiresulEstatusSql.php';
require_once dirname(__DIR__) . '/lib/NumfvdHelper.php';
require_once dirname(__DIR__) . '/lib/ReporteSancionesPorRondaService.php';

$numfvdBuscar = isset($argv[1]) ? (int) $argv[1] : 211;
$torneoArg = isset($argv[2]) ? (int) $argv[2] : 0;

$pdo = DB::pdo();

echo "=== Diagnóstico reporte sanciones NUMFVD {$numfvdBuscar} ===\n\n";

$sqlInsc = '
    SELECT i.torneo_id, i.id_usuario, i.numfvd, u.nombre, u.numfvd AS u_numfvd, t.nombre AS torneo
    FROM inscritos i
    INNER JOIN usuarios u ON u.id = i.id_usuario
    LEFT JOIN tournaments t ON t.id = i.torneo_id
    WHERE i.numfvd = ? OR u.numfvd = ?
    ORDER BY i.torneo_id DESC
';
$st = $pdo->prepare($sqlInsc);
$st->execute([$numfvdBuscar, $numfvdBuscar]);
$inscritos = $st->fetchAll(PDO::FETCH_ASSOC);

if ($inscritos === []) {
    echo "No hay inscrito con numfvd {$numfvdBuscar}\n";
    exit(1);
}

foreach ($inscritos as $ins) {
    if ($torneoArg > 0 && (int) $ins['torneo_id'] !== $torneoArg) {
        continue;
    }
    $tid = (int) $ins['torneo_id'];
    $uid = (int) $ins['id_usuario'];
    echo "Torneo {$tid}: {$ins['torneo']}\n";
    echo "  id_usuario={$uid} inscritos.numfvd={$ins['numfvd']} usuarios.numfvd={$ins['u_numfvd']} nombre={$ins['nombre']}\n";

    $wReg = PartiresulEstatusSql::whereRegistradoUno('pr');
    $tExpr = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr.tarjeta');

    $stPr = $pdo->prepare("
        SELECT pr.id, pr.partida, pr.mesa, pr.secuencia, pr.tarjeta AS tarjeta_raw,
               {$tExpr} AS tarjeta_norm, pr.sancion, pr.ff, pr.registrado, pr.numfvd AS pr_numfvd
        FROM partiresul pr
        WHERE pr.id_torneo = ? AND pr.id_usuario = ?
        ORDER BY pr.partida, pr.mesa, pr.secuencia
    ");
    $stPr->execute([$tid, $uid]);
    $partiresul = $stPr->fetchAll(PDO::FETCH_ASSOC);

    $conTarjeta = array_filter($partiresul, static fn ($r) => (int) ($r['tarjeta_norm'] ?? 0) > 0);
    echo '  partiresul total=' . count($partiresul) . ' con tarjeta norm>0=' . count($conTarjeta) . "\n";
    foreach ($conTarjeta as $r) {
        echo '    R' . $r['partida'] . ' M' . $r['mesa'] . ' seq=' . $r['secuencia']
            . ' tarjeta_raw=' . $r['tarjeta_raw'] . ' norm=' . $r['tarjeta_norm']
            . ' sancion=' . $r['sancion'] . ' reg=' . $r['registrado'] . "\n";
    }

    $reporte = (new ReporteSancionesPorRondaService())->construirReporte($tid, $pdo);
    foreach ($reporte['filas'] as $fila) {
        $nfFila = (int) ($fila['numfvd_sort'] ?? NumfvdHelper::desdeFila($fila));
        if ($nfFila === $numfvdBuscar
            || trim((string) ($fila['numfvd'] ?? '')) === (string) $numfvdBuscar) {
            echo '  EN REPORTE: ' . json_encode($fila, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    // ¿Otro id_usuario comparte numfvd en reporte por join erróneo?
    $exprNf = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo);
    $stCross = $pdo->prepare("
        SELECT pr.id_usuario, u.nombre, {$exprNf} AS numfvd, pr.partida, pr.mesa, pr.tarjeta, {$tExpr} AS tarjeta_norm
        FROM partiresul pr
        INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
        INNER JOIN usuarios u ON u.id = i.id_usuario
        WHERE pr.id_torneo = ? AND {$wReg} AND {$tExpr} IN (1,3,4) AND {$exprNf} = ?
    ");
    $stCross->execute([$tid, $numfvdBuscar]);
    $cross = $stCross->fetchAll(PDO::FETCH_ASSOC);
    echo '  filas SQL reporte con numfvd=' . $numfvdBuscar . ': ' . count($cross) . "\n";
    foreach ($cross as $r) {
        echo '    user=' . $r['id_usuario'] . ' ' . $r['nombre'] . ' R' . $r['partida'] . '-M' . $r['mesa']
            . ' tarjeta=' . $r['tarjeta'] . ' norm=' . $r['tarjeta_norm'] . "\n";
    }
    echo "\n";
}

// Detalle mesas con tarjeta para numfvd objetivo
if ($torneoArg <= 0 && isset($inscritos[0])) {
    $torneoArg = (int) $inscritos[0]['torneo_id'];
}
$stMesas = $pdo->prepare("
    SELECT pr.partida, pr.mesa, pr.secuencia, pr.id_usuario, i.numfvd, u.nombre,
           pr.tarjeta, pr.sancion, pr.ff, pr.resultado1, pr.resultado2, pr.efectividad
    FROM partiresul pr
    INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
    INNER JOIN usuarios u ON u.id = pr.id_usuario
    WHERE pr.id_torneo = ?
      AND pr.registrado = 1
      AND pr.partida IN (SELECT DISTINCT pr2.partida FROM partiresul pr2
          INNER JOIN inscritos i2 ON i2.torneo_id = pr2.id_torneo AND i2.id_usuario = pr2.id_usuario
          WHERE pr2.id_torneo = ? AND i2.numfvd = ? AND pr2.registrado = 1
          AND pr2.tarjeta IN (1,3,4,5,6,8))
      AND pr.mesa IN (SELECT DISTINCT pr3.mesa FROM partiresul pr3
          INNER JOIN inscritos i3 ON i3.torneo_id = pr3.id_torneo AND i3.id_usuario = pr3.id_usuario
          WHERE pr3.id_torneo = ? AND i3.numfvd = ? AND pr3.registrado = 1
          AND pr3.tarjeta IN (1,3,4,5,6,8))
    ORDER BY pr.partida, pr.mesa, pr.secuencia
");
$stMesas->execute([$torneoArg, $torneoArg, $numfvdBuscar, $torneoArg, $numfvdBuscar]);
$mesasDet = [];
foreach ($stMesas->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $k = $row['partida'] . '-' . $row['mesa'];
    $mesasDet[$k][] = $row;
}
echo "=== Mesas completas donde numfvd {$numfvdBuscar} tiene tarjeta en partiresul ===\n";
foreach ($mesasDet as $k => $jugadores) {
    echo "\n--- Ronda-Mesa {$k} ---\n";
    foreach ($jugadores as $j) {
        $mark = ((int) $j['numfvd'] === $numfvdBuscar) ? ' <<<' : '';
        echo "  seq{$j['secuencia']} nf{$j['numfvd']} {$j['nombre']} tarj={$j['tarjeta']} sanc={$j['sancion']} ff={$j['ff']} {$j['resultado1']}-{$j['resultado2']}{$mark}\n";
    }
}
