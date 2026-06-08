<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

$pdo = DB::pdo();

echo "=== 200-0 con efectividad != 200 ===\n";
$st = $pdo->query("
SELECT pr.id_torneo, pr.id_usuario, pr.partida, pr.mesa, pr.resultado1, pr.resultado2, pr.efectividad, pr.ff, pr.tarjeta
FROM partiresul pr
WHERE pr.registrado = 1 AND pr.mesa > 0
AND CAST(NULLIF(TRIM(pr.resultado1),'') AS SIGNED) >= 200
AND CAST(NULLIF(TRIM(pr.resultado2),'') AS SIGNED) = 0
AND CAST(NULLIF(TRIM(pr.efectividad),'') AS SIGNED) != 200
LIMIT 20
");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Ganó (r1>r2) con efectividad=0, sin FF ===\n";
$st2 = $pdo->query("
SELECT pr.id_torneo, pr.id_usuario, pr.partida, pr.mesa, pr.resultado1, pr.resultado2, pr.efectividad, pr.ff, pr.tarjeta
FROM partiresul pr
WHERE pr.registrado = 1 AND pr.mesa > 0
AND CAST(NULLIF(TRIM(pr.efectividad),'') AS SIGNED) = 0
AND CAST(NULLIF(TRIM(pr.resultado1),'') AS SIGNED) > CAST(NULLIF(TRIM(pr.resultado2),'') AS SIGNED)
AND pr.ff = 0
LIMIT 20
");
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Inscrito.efectividad vs SUM(partiresul) mesa>0 ===\n";
$st3 = $pdo->query("
SELECT i.torneo_id, i.id_usuario, i.efectividad AS ef_inscrito,
(SELECT COALESCE(SUM(CAST(NULLIF(TRIM(pr.efectividad),'') AS SIGNED)),0)
 FROM partiresul pr WHERE pr.id_torneo=i.torneo_id AND pr.id_usuario=i.id_usuario AND pr.registrado=1 AND pr.mesa>0) AS ef_sum_mesa
FROM inscritos i
HAVING ef_inscrito != ef_sum_mesa
LIMIT 15
");
foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$torneoId = (int) ($argv[1] ?? 2);
$mesa = (int) ($argv[2] ?? 15);
$partida = (int) ($argv[3] ?? 1);

echo "\n=== Mesa {$mesa} torneo {$torneoId} ronda {$partida} ===\n";
$st4 = $pdo->prepare("
SELECT secuencia, id_usuario, resultado1, resultado2, efectividad, ff, tarjeta, sancion, registrado
FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? ORDER BY secuencia
");
$st4->execute([$torneoId, $partida, $mesa]);
foreach ($st4->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$st5 = $pdo->prepare('SELECT puntos FROM tournaments WHERE id = ?');
$st5->execute([$torneoId]);
echo 'puntos torneo: ' . $st5->fetchColumn() . "\n";
