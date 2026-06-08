<?php
/**
 * Cálculo de efectividad / forfait / tarjeta grave (misma lógica que el formulario de resultados).
 * Extraído de modules/torneo_gestion.php para reutilización (CLI, tests) sin cargar el módulo web completo.
 */

/**
 * Calcula efectividad según las reglas del torneo
 * Versión mejorada con lógica individual para forfait y tarjetas graves
 */
function calcularEfectividad($resultado1, $resultado2, $puntosTorneo, $ff, $tarjeta, $sancion = 0) {
    // Validar puntos máximos
    $resultado1 = validarPuntos($resultado1, $puntosTorneo);
    $resultado2 = validarPuntos($resultado2, $puntosTorneo);

    // Aplicar sanción antes de calcular efectividad
    $resultado1Ajustado = max(0, $resultado1 - $sancion);

    // Forfait individual: -puntos_torneo efectividad
    if ($ff == 1) {
        return -$puntosTorneo;
    }

    // Tarjeta roja (3) o negra (4): -puntos_torneo efectividad
    if ($tarjeta == 3 || $tarjeta == 4) {
        return -$puntosTorneo;
    }

    // Calcular efectividad según si se alcanzaron los puntos del torneo
    $mayor = max($resultado1Ajustado, $resultado2);

    if ($mayor >= $puntosTorneo) {
        // Se alcanzaron los puntos: efectividad = puntos_torneo - resultado_contrario
        return calcularEfectividadAlcanzo($resultado1Ajustado, $resultado2, $puntosTorneo);
    } else {
        // No se alcanzaron: efectividad = diferencia de puntos
        return calcularEfectividadNoAlcanzo($resultado1Ajustado, $resultado2);
    }
}

/**
 * Calcular efectividad cuando SÍ se alcanzaron los puntos del torneo
 */
function calcularEfectividadAlcanzo($resultado1, $resultado2, $puntosTorneo) {
    if ($resultado1 == $resultado2) {
        return 0; // Empate
    } elseif ($resultado1 > $resultado2) {
        return $puntosTorneo - $resultado2; // Ganó
    } else {
        return -($puntosTorneo - $resultado1); // Perdió
    }
}

/**
 * Calcular efectividad cuando NO se alcanzaron los puntos del torneo
 */
function calcularEfectividadNoAlcanzo($resultado1, $resultado2) {
    if ($resultado1 == $resultado2) {
        return 0; // Empate
    } elseif ($resultado1 > $resultado2) {
        return $resultado1 - $resultado2; // Ganó
    } else {
        return -($resultado2 - $resultado1); // Perdió
    }
}

/**
 * Evaluar sanción de puntos para un jugador individualmente
 * Calcula el resultado ajustado aplicando la sanción y determina si ganó o perdió
 *
 * @param int $resultado1 Resultado1 original del jugador
 * @param int $resultadoOponente Resultado1 de la pareja oponente (sin ajustar)
 * @param int $sancion Puntos de sanción del jugador
 * @param int $puntosTorneo Puntos del torneo
 * @return array ['resultado_ajustado' => int, 'gano' => bool, 'efectividad' => int]
 */
function evaluarSancionIndividual($resultado1, $resultadoOponente, $sancion, $puntosTorneo) {
    // Aplicar sanción: resultado ajustado = resultado1 - sanción (mínimo 0)
    $resultadoAjustado = max(0, $resultado1 - $sancion);

    // Determinar si ganó o perdió comparando resultado ajustado con oponente
    $gano = ($resultadoAjustado > $resultadoOponente);

    // Calcular efectividad según si ganó o perdió
    $mayor = max($resultadoAjustado, $resultadoOponente);

    if ($gano) {
        // Ganó: efectividad positiva
        if ($mayor >= $puntosTorneo) {
            $efectividad = calcularEfectividadAlcanzo($resultadoAjustado, $resultadoOponente, $puntosTorneo);
        } else {
            $efectividad = calcularEfectividadNoAlcanzo($resultadoAjustado, $resultadoOponente);
        }
    } else {
        // Perdió: efectividad negativa
        if ($mayor >= $puntosTorneo) {
            $efectividad = -($puntosTorneo - $resultadoAjustado);
        } else {
            $efectividad = -($resultadoOponente - $resultadoAjustado);
        }
    }

    return [
        'resultado_ajustado' => $resultadoAjustado,
        'gano' => $gano,
        'efectividad' => $efectividad
    ];
}

/**
 * Calcular efectividad cuando hay sanción
 * Si el resultado ajustado (resultado1 - sanción) es igual o inferior al resultado del oponente,
 * se computa como perdida la partida
 *
 * @param int $resultado1Ajustado Resultado1 del jugador sancionado menos la sanción
 * @param int $resultadoOponente Resultado1 de la pareja oponente
 * @param int $puntosTorneo Puntos del torneo
 * @param int $sancion Puntos de sanción
 * @return int Efectividad calculada
 */
function calcularEfectividadConSancion($resultado1Ajustado, $resultadoOponente, $puntosTorneo, $sancion) {
    // Si el resultado ajustado es igual o inferior al oponente, se computa como perdida
    if ($resultado1Ajustado <= $resultadoOponente) {
        // Perdió: efectividad negativa
        $mayor = max($resultado1Ajustado, $resultadoOponente);
        if ($mayor >= $puntosTorneo) {
            return -($puntosTorneo - $resultado1Ajustado);
        } else {
            return -($resultadoOponente - $resultado1Ajustado);
        }
    } else {
        // Ganó: calcular efectividad normal con resultado ajustado
        $mayor = max($resultado1Ajustado, $resultadoOponente);
        if ($mayor >= $puntosTorneo) {
            return calcularEfectividadAlcanzo($resultado1Ajustado, $resultadoOponente, $puntosTorneo);
        } else {
            return calcularEfectividadNoAlcanzo($resultado1Ajustado, $resultadoOponente);
        }
    }
}

/**
 * Validar que los puntos no excedan el máximo permitido
 */
function validarPuntos($puntos, $puntosTorneo) {
    // Máximo permitido: puntos del torneo + 60% = puntosTorneo * 1.6
    $maximo = (int)round($puntosTorneo * 1.6);
    if ($puntos > $maximo) {
        return $maximo;
    }
    return $puntos;
}

/**
 * Calcular efectividad cuando hay forfait
 * @param bool $tieneForfait Si true, el jugador tiene forfait (pierde). Si false, el jugador NO tiene forfait (gana).
 * @param int $puntosTorneo Puntos del torneo
 * @return array ['efectividad' => int, 'resultado1' => int, 'resultado2' => int]
 */
function calcularEfectividadForfait($tieneForfait, $puntosTorneo) {
    if ($tieneForfait) {
        // Jugador CON forfait: PIERDE
        return [
            'efectividad' => -$puntosTorneo,
            'resultado1' => 0,
            'resultado2' => $puntosTorneo
        ];
    } else {
        // Jugador SIN forfait: GANA
        // Los ganadores por forfait reciben solo el 50% de efectividad (no el 100%)
        return [
            'efectividad' => (int)($puntosTorneo / 2),
            'resultado1' => $puntosTorneo,
            'resultado2' => 0
        ];
    }
}

/**
 * Calcular efectividad cuando hay tarjeta grave (roja o negra)
 *
 * REGLAS PARA TARJETA GRAVE:
 * - Los jugadores NO sancionados reciben:
 *   * Puntos del torneo en su totalidad (resultado1 = puntos_torneo, resultado2 = 0)
 *   * Efectividad = puntos del torneo (100% de efectividad)
 * - Los jugadores sancionados (infractores) reciben:
 *   * 0 puntos (resultado1 = 0, resultado2 = puntos_torneo)
 *   * Efectividad = -puntos del torneo
 *
 * @param bool $tieneTarjetaGrave Si true, el jugador tiene tarjeta grave (pierde). Si false, el jugador NO tiene tarjeta grave (gana).
 * @param int $puntosTorneo Puntos del torneo
 * @return array ['efectividad' => int, 'resultado1' => int, 'resultado2' => int]
 */
function calcularEfectividadTarjetaGrave($tieneTarjetaGrave, $puntosTorneo) {
    if ($tieneTarjetaGrave) {
        // Jugador CON tarjeta grave (infractor): PIERDE
        // Recibe 0 puntos y efectividad negativa igual a -puntos del torneo
        return [
            'efectividad' => -$puntosTorneo,  // -puntos del torneo
            'resultado1' => 0,                 // 0 puntos para el infractor
            'resultado2' => $puntosTorneo      // puntos del torneo para el oponente
        ];
    } else {
        // Jugador SIN tarjeta grave (no sancionado): GANA
        // Recibe los puntos del torneo en su totalidad y efectividad igual a puntos del torneo
        return [
            'efectividad' => $puntosTorneo,    // puntos del torneo (100% de efectividad, no 50% como en forfait)
            'resultado1' => $puntosTorneo,     // puntos del torneo en su totalidad
            'resultado2' => 0                  // 0 puntos para el oponente (infractor)
        ];
    }
}

function calcularTriunfoGffJugadorMesa(
    bool $hayForfaitEnMesa,
    bool $hayTarjetaGraveEnMesa,
    int $ff,
    int $tarjeta,
    int $resultado1,
    int $resultado2,
    int $efectividad
): int {
    if (! $hayForfaitEnMesa && ! $hayTarjetaGraveEnMesa) {
        return 0;
    }
    if ($ff === 1 || $tarjeta === 3 || $tarjeta === 4) {
        return 0;
    }
    if ($resultado1 <= $resultado2 || $efectividad <= 0) {
        return 0;
    }

    return 1;
}

/**
 * Interpreta forfait desde formulario web, importación Access u otros orígenes.
 */
/** @param mixed $v */
function torneoFfDesdeEntrada($v): int
{
    if ($v === true || $v === 'on') {
        return 1;
    }
    if (is_numeric($v)) {
        return ((int) $v) === 1 ? 1 : 0;
    }
    $s = strtoupper(trim((string) $v));

    return in_array($s, ['1', 'S', 'SI', 'Y', 'YES', 'TRUE', 'FF', 'FORFAIT'], true) ? 1 : 0;
}

/**
 * Modo de cálculo de efectividad en una mesa.
 * Tarjeta grave (roja/negra) prevalece sobre forfait: Access suele marcar FF+tarjeta al infractor.
 *
 * @param array<int, array<string, mixed>> $jugadoresFilas
 * @return 'tarjeta_grave'|'forfait'|'normal'
 */
function detectarModoCalculoMesa(array $jugadoresFilas): string
{
    if (! class_exists('TorneoCampoNumerico', false)) {
        require_once __DIR__ . '/TorneoCampoNumerico.php';
    }
    foreach ($jugadoresFilas as $jugador) {
        $tarjeta = TorneoCampoNumerico::codigoTarjeta($jugador['tarjeta'] ?? 0);
        if ($tarjeta === 3 || $tarjeta === 4) {
            return 'tarjeta_grave';
        }
    }
    foreach ($jugadoresFilas as $jugador) {
        if (torneoFfDesdeEntrada($jugador['ff'] ?? 0) === 1) {
            return 'forfait';
        }
    }

    return 'normal';
}

/**
 * Efectividad de un jugador según el modo de la mesa (misma lógica que aplicarResultadosMesaCore).
 *
 * @param array<string, mixed> $fila
 */
function calcularEfectividadJugadorMesa(array $fila, string $modoMesa, int $puntosTorneo, bool $esEmpateManoNula = false): int
{
    if ($esEmpateManoNula) {
        return 0;
    }
    if (! class_exists('TorneoCampoNumerico', false)) {
        require_once __DIR__ . '/TorneoCampoNumerico.php';
    }

    $ff = torneoFfDesdeEntrada($fila['ff'] ?? 0);
    $tarjeta = TorneoCampoNumerico::codigoTarjeta($fila['tarjeta'] ?? 0);
    $resultado1 = TorneoCampoNumerico::intEstadistica($fila['resultado1'] ?? 0);
    $resultado2 = TorneoCampoNumerico::intEstadistica($fila['resultado2'] ?? 0);
    $sancion = min(80, max(0, TorneoCampoNumerico::intEstadistica($fila['sancion'] ?? 0)));

    if ($modoMesa === 'tarjeta_grave') {
        return calcularEfectividadTarjetaGrave($tarjeta === 3 || $tarjeta === 4, $puntosTorneo)['efectividad'];
    }
    if ($modoMesa === 'forfait') {
        return calcularEfectividadForfait($ff === 1, $puntosTorneo)['efectividad'];
    }
    if ($sancion > 0) {
        return evaluarSancionIndividual($resultado1, $resultado2, $sancion, $puntosTorneo)['efectividad'];
    }

    return calcularEfectividad(max(0, $resultado1 - $sancion), $resultado2, $puntosTorneo, $ff, $tarjeta, 0);
}

/**
 * Ajusta PF/PC antes de validar: misma lógica que el ingreso manual tras FF o tarjeta grave;
 * en mesas normales trunca negativos (legacy Access).
 *
 * @param array<int, array<string, mixed>> $jugadores
 */
function normalizarResultadosEntradaMesa(
    array &$jugadores,
    bool $hayForfaitEnMesa,
    bool $hayTarjetaGraveEnMesa,
    int $puntosTorneo
): void {
    if (! class_exists('TorneoCampoNumerico', false)) {
        require_once __DIR__ . '/TorneoCampoNumerico.php';
    }

    foreach ($jugadores as &$jugador) {
        $ff = torneoFfDesdeEntrada($jugador['ff'] ?? 0);
        $tarjeta = \TorneoCampoNumerico::codigoTarjeta($jugador['tarjeta'] ?? 0);
        $r1 = \TorneoCampoNumerico::intEstadistica($jugador['resultado1'] ?? 0);
        $r2 = \TorneoCampoNumerico::intEstadistica($jugador['resultado2'] ?? 0);

        if ($hayTarjetaGraveEnMesa) {
            $calc = calcularEfectividadTarjetaGrave($tarjeta === 3 || $tarjeta === 4, $puntosTorneo);
            $jugador['resultado1'] = $calc['resultado1'];
            $jugador['resultado2'] = $calc['resultado2'];
        } elseif ($hayForfaitEnMesa) {
            $calc = calcularEfectividadForfait($ff === 1, $puntosTorneo);
            $jugador['resultado1'] = $calc['resultado1'];
            $jugador['resultado2'] = $calc['resultado2'];
        } else {
            $jugador['resultado1'] = max(0, $r1);
            $jugador['resultado2'] = max(0, $r2);
        }
        $jugador['ff'] = $ff;
    }
    unset($jugador);
}
