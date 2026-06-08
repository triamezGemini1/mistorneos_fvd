<?php
define('TORNEO_GESTION_SKIP_AUTH', true);
define('TORNEO_GESTION_SKIP_ROUTER', true);
require 'c:/wamp64/www/mistorneos_fvd/config/bootstrap.php';
require 'c:/wamp64/www/mistorneos_fvd/modules/torneo_gestion.php';
$d = obtenerDatosResumenIndividual(1, 7457);
foreach ($d['resumenParticipacion'] as $p) {
    if ((int)$p['partida'] === 7 && (int)$p['mesa'] === 52) {
        echo json_encode(['partida'=>7,'mesa'=>52,'ef'=>$p['efectividad'],'gano'=>$p['gano']])."\n";
    }
}
echo 'total ef=' . $d['totales']['efectividad'] . ' inscrito ef=' . $d['inscrito']['efectividad'] . "\n";
