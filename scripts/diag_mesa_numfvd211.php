<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
$pdo = DB::pdo();
$tid = 1;
$nf = 211;
foreach ([[1, 54], [10, 39]] as [$r, $m]) {
    echo "=== R{$r} M{$m} ===\n";
    $st = $pdo->prepare('
        SELECT pr.secuencia, i.numfvd, u.nombre, pr.tarjeta, pr.sancion, pr.ff, pr.resultado1, pr.resultado2, pr.efectividad
        FROM partiresul pr
        INNER JOIN inscritos i ON i.torneo_id=pr.id_torneo AND i.id_usuario=pr.id_usuario
        INNER JOIN usuarios u ON u.id=pr.id_usuario
        WHERE pr.id_torneo=? AND pr.partida=? AND pr.mesa=? AND pr.registrado=1
        ORDER BY pr.secuencia
    ');
    $st->execute([$tid, $r, $m]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mark = ((int)$row['numfvd'] === $nf) ? ' <<<' : '';
        echo sprintf(
            "  seq%d nf%s %s tarj=%s sanc=%s ff=%s %d-%d ef=%d%s\n",
            $row['secuencia'],
            $row['numfvd'],
            $row['nombre'],
            $row['tarjeta'],
            $row['sancion'],
            $row['ff'],
            $row['resultado1'],
            $row['resultado2'],
            $row['efectividad'],
            $mark
        );
    }
}
