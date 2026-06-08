<?php
require 'c:/wamp64/www/mistorneos_fvd/config/bootstrap.php';
require 'c:/wamp64/www/mistorneos_fvd/lib/partiresul_efectividad_funcs.php';

$mesa = [
    ['ff'=>0,'tarjeta'=>0,'resultado1'=>200,'resultado2'=>0,'sancion'=>0],
    ['ff'=>0,'tarjeta'=>0,'resultado1'=>200,'resultado2'=>0,'sancion'=>0],
    ['ff'=>1,'tarjeta'=>3,'resultado1'=>0,'resultado2'=>200,'sancion'=>0],
    ['ff'=>0,'tarjeta'=>0,'resultado1'=>200,'resultado2'=>0,'sancion'=>0],
];
$modo = detectarModoCalculoMesa($mesa);
echo "modo=$modo\n";
foreach ($mesa as $i => $f) {
    echo "jugador $i ef=" . calcularEfectividadJugadorMesa($f, $modo, 200) . "\n";
}
