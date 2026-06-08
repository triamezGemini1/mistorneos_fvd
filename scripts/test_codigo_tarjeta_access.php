<?php
require_once dirname(__DIR__) . '/lib/TorneoCampoNumerico.php';
$cases = [0 => 0, 1 => 0, 5 => 1, 6 => 3, 8 => 4, 40 => 0, 80 => 0, 3 => 0, 4 => 0];
foreach ($cases as $in => $exp) {
    $got = TorneoCampoNumerico::codigoTarjetaDesdeAccess($in);
    echo "{$in} -> {$got}" . ($got === $exp ? ' OK' : " FAIL (expected {$exp})") . PHP_EOL;
}
