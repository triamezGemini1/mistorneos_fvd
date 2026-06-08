<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/lib/InscritosReporteStatsHelper.php';
require_once dirname(__DIR__) . '/lib/PartiresulEstatusSql.php';
require_once dirname(__DIR__) . '/lib/ReporteSancionesPorRondaService.php';

$pdo = DB::pdo();
$tid = 1;
$wReg = PartiresulEstatusSql::whereRegistradoUno('pr');
$tExpr = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr.tarjeta');
$wReal = ReporteSancionesPorRondaService::sqlWhereTarjetaAtribuibleJugador('pr');

$st1 = $pdo->prepare("SELECT COUNT(*) FROM partiresul pr WHERE pr.id_torneo=? AND {$wReg} AND {$tExpr} IN (1,3,4)");
$st1->execute([$tid]);
$st2 = $pdo->prepare("SELECT COUNT(*) FROM partiresul pr WHERE pr.id_torneo=? AND {$wReg} AND {$tExpr} IN (1,3,4) AND {$wReal}");
$st2->execute([$tid]);
echo 'Filas partiresul con tarjeta cruda: ' . $st1->fetchColumn() . PHP_EOL;
echo 'Filas tarjeta atribuible (reporte): ' . $st2->fetchColumn() . PHP_EOL;

$st3 = $pdo->prepare("
    SELECT i.numfvd, u.nombre, pr.partida, pr.mesa, {$tExpr} AS t, pr.sancion, pr.ff, pr.efectividad
    FROM partiresul pr
    INNER JOIN inscritos i ON i.torneo_id=pr.id_torneo AND i.id_usuario=pr.id_usuario
    INNER JOIN usuarios u ON u.id=pr.id_usuario
    WHERE pr.id_torneo=? AND {$wReg} AND {$tExpr} IN (1,3,4) AND {$wReal}
    ORDER BY pr.partida, i.numfvd LIMIT 8
");
$st3->execute([$tid]);
echo PHP_EOL . 'Muestra incluida:' . PHP_EOL;
foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  nf{$r['numfvd']} R{$r['partida']}-M{$r['mesa']} t={$r['t']} sanc={$r['sancion']} ff={$r['ff']} ef={$r['efectividad']} {$r['nombre']}\n";
}
