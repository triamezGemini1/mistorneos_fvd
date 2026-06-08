<?php
require 'c:/wamp64/www/mistorneos_fvd/config/bootstrap.php';
require 'c:/wamp64/www/mistorneos_fvd/config/db.php';
$pdo = DB::pdo();
$torneoId = 1;
$partida = 7;
$mesa = 52;
$st = $pdo->prepare('SELECT secuencia,id_usuario,resultado1,resultado2,efectividad,ff,tarjeta,sancion FROM partiresul WHERE id_torneo=? AND partida=? AND mesa=? ORDER BY secuencia');
$st->execute([$torneoId,$partida,$mesa]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r)."\n";
