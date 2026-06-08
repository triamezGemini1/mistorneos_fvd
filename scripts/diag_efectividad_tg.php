<?php
require 'c:/wamp64/www/mistorneos_fvd/config/bootstrap.php';
require 'c:/wamp64/www/mistorneos_fvd/config/db.php';
$pdo = DB::pdo();

echo "=== Tarjeta grave (3,4) ganadores con ef != puntos torneo ===\n";
$st = $pdo->query("
SELECT pr.id_torneo, pr.partida, pr.mesa, pr.secuencia, pr.id_usuario,
       pr.resultado1, pr.resultado2, pr.efectividad, pr.ff, pr.tarjeta, t.puntos
FROM partiresul pr
JOIN tournaments t ON t.id = pr.id_torneo
WHERE pr.registrado = 1 AND pr.mesa > 0 AND pr.ff = 0
AND pr.tarjeta NOT IN (3,4)
AND pr.resultado1 > pr.resultado2
AND EXISTS (
  SELECT 1 FROM partiresul px
  WHERE px.id_torneo=pr.id_torneo AND px.partida=pr.partida AND px.mesa=pr.mesa
  AND px.tarjeta IN (3,4)
)
AND pr.efectividad != t.puntos
LIMIT 25
");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

echo "\n=== Normal 200-0 sin FF ni tarjeta grave en mesa, ef != 200 ===\n";
$st2 = $pdo->query("
SELECT pr.id_torneo, pr.partida, pr.mesa, pr.id_usuario,
       pr.resultado1, pr.resultado2, pr.efectividad, pr.ff, pr.tarjeta
FROM partiresul pr
WHERE pr.registrado = 1 AND pr.mesa > 0
AND CAST(NULLIF(TRIM(pr.resultado1),'') AS SIGNED) = 200
AND CAST(NULLIF(TRIM(pr.resultado2),'') AS SIGNED) = 0
AND pr.ff = 0
AND NOT EXISTS (
  SELECT 1 FROM partiresul px
  WHERE px.id_torneo=pr.id_torneo AND px.partida=pr.partida AND px.mesa=pr.mesa AND (px.ff=1 OR px.tarjeta IN (3,4))
)
AND CAST(NULLIF(TRIM(pr.efectividad),'') AS SIGNED) != 200
LIMIT 25
");
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}
