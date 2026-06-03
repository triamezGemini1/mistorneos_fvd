<?php

declare(strict_types=1);

/**
 * Verifica lógica R2 por zonas (sin BD).
 * Uso: php scripts/verificar_ronda2_zonas.php
 */

function esG(int $n): bool
{
    return $n > 0;
}

/** @return list<list<string>> */
function mesasPatron1537(array $labels): array
{
    $mesas = [];
    $idx = 0;
    $n = count($labels);
    while ($idx + 7 < $n) {
        $mesas[] = [$labels[$idx], $labels[$idx + 4], $labels[$idx + 2], $labels[$idx + 6]];
        $mesas[] = [$labels[$idx + 1], $labels[$idx + 5], $labels[$idx + 3], $labels[$idx + 7]];
        $idx += 8;
    }
    if ($idx + 3 < $n) {
        $mesas[] = [$labels[$idx], $labels[$idx + 2], $labels[$idx + 1], $labels[$idx + 3]];
    }

    return $mesas;
}

/** @return list<list<string>> */
function construirZonas(int $numG, int $numP): array
{
    $ganadores = [];
    for ($i = 1; $i <= $numG; $i++) {
        $ganadores[] = 'G' . $i;
    }
    $perdedores = [];
    for ($i = 1; $i <= $numP; $i++) {
        $perdedores[] = 'P' . $i;
    }

    $mesas = [];
    while (count($ganadores) >= 8) {
        $bloque = array_splice($ganadores, 0, 8);
        $mesas = array_merge($mesas, mesasPatron1537($bloque));
    }
    $nPeoresG = count($ganadores);
    $perdedoresMedio = [];
    if ($nPeoresG > 0 && $perdedores !== []) {
        $perdedoresMedio = array_splice($perdedores, 0, min($nPeoresG, count($perdedores)));
    }
    $zonaMedia = array_merge($ganadores, $perdedoresMedio);
    while (count($zonaMedia) % 4 !== 0 && $perdedores !== []) {
        $zonaMedia[] = array_shift($perdedores);
    }
    $mesas = array_merge($mesas, mesasPatron1537($zonaMedia));
    while (count($perdedores) >= 8) {
        $bloque = array_splice($perdedores, 0, 8);
        $mesas = array_merge($mesas, mesasPatron1537($bloque));
    }
    if (count($perdedores) >= 4) {
        $mesas = array_merge($mesas, mesasPatron1537(array_splice($perdedores, 0, 4)));
    }

    return $mesas;
}

function clasificarMesa(array $mesa): string
{
    $g = 0;
    foreach ($mesa as $l) {
        if ($l[0] === 'G') {
            $g++;
        }
    }
    if ($g === 4) {
        return 'alta';
    }
    if ($g === 0) {
        return 'baja';
    }

    return 'media';
}

function verificar(int $numG, int $numP): bool
{
    $mesas = construirZonas($numG, $numP);
    $ok = true;
    $vistoAlta = false;
    $vistoMedia = false;
    $vistoBaja = false;
    $errores = [];

    foreach ($mesas as $i => $mesa) {
        $z = clasificarMesa($mesa);
        if ($z === 'alta') {
            $vistoAlta = true;
            if ($vistoMedia || $vistoBaja) {
                $errores[] = 'Mesa ' . ($i + 1) . ' alta después de media/baja: ' . implode(',', $mesa);
                $ok = false;
            }
        } elseif ($z === 'media') {
            $vistoMedia = true;
            if ($vistoBaja) {
                $errores[] = 'Mesa ' . ($i + 1) . ' media después de baja: ' . implode(',', $mesa);
                $ok = false;
            }
            foreach ($mesa as $l) {
                if ($l[0] === 'G' && (int) substr($l, 1) > $numG - (int) ceil($numG % 8 / 4)) {
                    // peores G tienen número alto
                }
            }
        } else {
            $vistoBaja = true;
            if ($vistoAlta && !$vistoMedia && $numG % 8 !== 0 && $numG > 8) {
                // puede haber alta luego baja sin media si no hay resto G - edge
            }
        }
        foreach ($mesa as $l) {
            if ($z === 'alta' && $l[0] === 'P') {
                $errores[] = 'Mesa ' . ($i + 1) . ' alta con perdedor ' . $l;
                $ok = false;
            }
            if ($z === 'baja' && $l[0] === 'G') {
                $errores[] = 'Mesa ' . ($i + 1) . ' baja con ganador ' . $l;
                $ok = false;
            }
        }
    }

    $total = ($numG + $numP);
    $esperadas = (int) floor($total / 4);
    if (count($mesas) !== $esperadas) {
        $errores[] = "Conteo mesas: esperadas {$esperadas}, obtenidas " . count($mesas);
        $ok = false;
    }

    echo ($ok ? '[OK] ' : '[FAIL] ') . "{$numG}G + {$numP}P → " . count($mesas) . " mesas\n";
    foreach ($mesas as $i => $mesa) {
        echo '  M' . ($i + 1) . ' [' . clasificarMesa($mesa) . '] ' . implode(' · ', $mesa) . "\n";
    }
    foreach ($errores as $e) {
        echo "  ! {$e}\n";
    }

    return $ok;
}

$casos = [[8, 8], [10, 6], [6, 10], [12, 4], [16, 0], [0, 16], [9, 7]];
$todoOk = true;
foreach ($casos as [$g, $p]) {
    if ($g + $p < 4) {
        continue;
    }
    if (!verificar($g, $p)) {
        $todoOk = false;
    }
    echo "\n";
}

exit($todoOk ? 0 : 1);
