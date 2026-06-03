<?php
/**
 * Simulación procedimiento: R1 Mesa 3 — Pareja A=170, Pareja B=234, sanción 80 en jugador B (sec 3).
 */
require_once __DIR__ . '/../lib/partiresul_efectividad_funcs.php';

$puntosTorneo = 200;
$parejaA = 170;
$parejaB = 234;
$sancionB = 80;
$sancionCalc = 80; // SancionesHelper: 80 → resta 80, tarjeta amarilla si sin previa

$jugadores = [
    ['letra' => 'A', 'sec' => 1, 'r1' => $parejaA, 'r2' => $parejaB, 'sanc' => 0],
    ['letra' => 'C', 'sec' => 2, 'r1' => $parejaA, 'r2' => $parejaB, 'sanc' => 0],
    ['letra' => 'B', 'sec' => 3, 'r1' => $parejaB, 'r2' => $parejaA, 'sanc' => $sancionB],
    ['letra' => 'D', 'sec' => 4, 'r1' => $parejaB, 'r2' => $parejaA, 'sanc' => 0],
];

echo "Torneo a {$puntosTorneo} pts | Pareja AC={$parejaA} vs BD={$parejaB} | B sanción {$sancionB}\n\n";
echo str_pad('Jug', 4) . str_pad('r1', 6) . str_pad('r2', 6) . str_pad('sanc', 6) . str_pad('r1_adj', 8) . str_pad('G/P', 5) . str_pad('Efect', 8) . "Notas\n";
echo str_repeat('-', 60) . "\n";

foreach ($jugadores as $j) {
    $r1 = $j['r1'];
    $r2 = $j['r2'];
    $sanc = $j['sanc'];
    $sancCalc = $sanc >= 80 ? 80 : ($sanc === 40 ? 40 : $sanc);
    $r1Adj = max(0, $r1 - $sancCalc);

    if ($sancCalc > 0) {
        $ev = evaluarSancionIndividual($r1, $r2, $sancCalc, $puntosTorneo);
        $ef = $ev['efectividad'];
        $gano = $ev['gano'];
        $nota = 'evaluarSancionIndividual(r1, r2=contraria)';
    } else {
        $ef = calcularEfectividad($r1, $r2, $puntosTorneo, 0, 0, 0);
        $gano = $r1 > $r2;
        $nota = 'calcularEfectividad(r1, r2)';
    }

    echo str_pad($j['letra'], 4)
        . str_pad((string) $r1, 6)
        . str_pad((string) $r2, 6)
        . str_pad((string) $sanc, 6)
        . str_pad((string) $r1Adj, 8)
        . str_pad($gano ? 'G' : 'P', 5)
        . str_pad((string) $ef, 8)
        . $nota . "\n";
}

echo "\nGanado/perdido en inscritos (agregación): gano si (r1-sanc)>r2 del oponente en partiresul.\n";
echo "Pareja BD gana mesa (234>170); B pierde individualmente por sanción (154<170).\n";
