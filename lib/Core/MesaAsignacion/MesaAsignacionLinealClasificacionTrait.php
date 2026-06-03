<?php

declare(strict_types=1);

/**
 * Asignación individual según procedimiento FVD:
 * - R1: orden lineal posi_rnk; parejas (1+2),(3+4)… en mesas 1..n, luego segunda pasada 1..n [A,C,B,D].
 * - R2..N-1: clasificación estricta G/E/P; columnas 1..n=A, n+1..2n=C, etc.; reparar parejas/enfrentos.
 * - Última ronda: patrón 1+3 vs 2+4 fluido (ver generarRondaPatronIntercalado).
 */
trait MesaAsignacionLinealClasificacionTrait
{
    /**
     * Ronda 1: vector por posi_rnk; parejas consecutivas (1+2), (3+4), …
     * Primera pasada: una pareja por mesa 1..n (→ A y C). Segunda pasada: siguiente bloque de parejas, otra vez 1..n (→ B y D).
     *
     * @param list<array<string, mixed>> $ordenados
     * @return list<list<array<string, mixed>>>
     */
    private function armarMesasBloquesConsecutivosCuatro(array $ordenados): array
    {
        $mesas = [];
        $total = count($ordenados);
        $numMesas = (int) floor($total / self::JUGADORES_POR_MESA);

        for ($m = 0; $m < $numMesas; $m++) {
            $idxA = 2 * $m;
            $idxC = 2 * $m + 1;
            $idxB = (2 * $numMesas) + (2 * $m);
            $idxD = (2 * $numMesas) + (2 * $m) + 1;
            if ($idxD >= $total) {
                break;
            }
            $mesa = [
                $ordenados[$idxA],
                $ordenados[$idxC],
                $ordenados[$idxB],
                $ordenados[$idxD],
            ];
            if (in_array(null, $mesa, true)) {
                continue;
            }
            $mesas[] = $mesa;
        }

        return $mesas;
    }

    /**
     * Rondas 2..N-1: vector clasificación 1..n; mesa m → A=m+1, C=m+n+1, B=m+2n+1, D=m+3n+1 (base 0).
     *
     * @param list<array<string, mixed>> $clasificacionOrdenada
     * @return list<list<array<string, mixed>>>
     */
    private function armarMesasEsquemaColumnasClasificacion(array $clasificacionOrdenada): array
    {
        $mesas = [];
        $total = count($clasificacionOrdenada);
        $numMesas = (int) floor($total / self::JUGADORES_POR_MESA);

        for ($m = 0; $m < $numMesas; $m++) {
            $idxA = $m;
            $idxC = $m + $numMesas;
            $idxB = $m + (2 * $numMesas);
            $idxD = $m + (3 * $numMesas);
            if ($idxD >= $total) {
                break;
            }
            $mesas[] = [
                $clasificacionOrdenada[$idxA],
                $clasificacionOrdenada[$idxC],
                $clasificacionOrdenada[$idxB],
                $clasificacionOrdenada[$idxD],
            ];
        }

        return $mesas;
    }

    /**
     * Compañeros de R1 que ganaron la partida: no pueden enfrentarse (cruzados) en ronda 2.
     *
     * @return array<int, array<int, true>>
     */
    private function obtenerMatrizProhibirEnfrentoCompanerosGanadoresR1(int $torneoId): array
    {
        return $this->repo->obtenerMatrizProhibirEnfrentoCompanerosGanadoresR1($torneoId);
    }

    /**
     * @return array<string, mixed>
     */
    private function generarRondaPorBloquesLinealesClasificacion(
        int $torneoId,
        int $numRonda,
        bool $prohibirEnfrentoCompanerosGanadoresR1
    ): array {
        $clasificacion = $this->repo->obtenerClasificacionInscritosOrdenEstricto($torneoId);
        $totalInscritos = count($clasificacion);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)',
            ];
        }

        $numMesas = (int) floor($totalInscritos / self::JUGADORES_POR_MESA);
        $numBye = $totalInscritos - ($numMesas * self::JUGADORES_POR_MESA);
        $conteoBye = $this->repo->obtenerConteoByePorJugador($torneoId, $numRonda);
        if ($numBye > 0) {
            if (!empty($conteoBye)) {
                list($jugadoresParaMesas, $jugadoresBye) = $this->reordenarParaLimitarBye(
                    $clasificacion,
                    $conteoBye,
                    $numBye,
                    $numMesas
                );
            } else {
                $jugadoresParaMesas = array_slice($clasificacion, 0, $numMesas * self::JUGADORES_POR_MESA);
                $jugadoresBye = array_slice($clasificacion, $numMesas * self::JUGADORES_POR_MESA, $numBye);
            }
        } else {
            $jugadoresParaMesas = array_slice($clasificacion, 0, $numMesas * self::JUGADORES_POR_MESA);
            $jugadoresBye = [];
        }

        $mesas = $this->armarMesasEsquemaColumnasClasificacion($jugadoresParaMesas);

        $matrizCompañeros = $this->obtenerMatrizCompañerosParaRonda($torneoId, $numRonda - 1);
        $matrizProhibirEnfrento = $prohibirEnfrentoCompanerosGanadoresR1
            ? $this->obtenerMatrizProhibirEnfrentoCompanerosGanadoresR1($torneoId)
            : [];

        $mesas = $this->finalizarAsignacionRespetandoHistorial(
            $mesas,
            $jugadoresParaMesas,
            $matrizCompañeros,
            $matrizProhibirEnfrento,
            $numMesas
        );

        $jugadoresEnMesa = 0;
        foreach ($mesas as $mesa) {
            $jugadoresEnMesa += count($mesa);
        }
        $esperadosEnMesa = $numMesas * self::JUGADORES_POR_MESA;
        if ($mesas === [] || count($mesas) < $numMesas || $jugadoresEnMesa < $esperadosEnMesa) {
            return [
                'success' => false,
                'message' => 'No se pudo completar la ronda '
                    . $numRonda
                    . ' (mesas: '
                    . count($mesas)
                    . '/'
                    . $numMesas
                    . ', jugadores: '
                    . $jugadoresEnMesa
                    . '/'
                    . $esperadosEnMesa
                    . ').',
            ];
        }

        $this->repo->guardarAsignacionRonda($torneoId, $numRonda, $mesas, $this->registradoPorUsuarioId);
        if ($jugadoresBye !== []) {
            $this->aplicarJugadoresByeRonda($torneoId, $numRonda, $jugadoresBye);
        }

        $msg = $numRonda === 2
            ? 'Ronda 2 (clasificación G/E/P, esquema columnas 1..n; sin repetir pareja ni enfrentar compañero ganador R1)'
            : "Ronda {$numRonda} (clasificación G/E/P, esquema columnas; sin repetir parejas)";

        $conflictosRestantes = $this->contarMesasConConflictoPareja($mesas, $matrizCompañeros);
        if ($conflictosRestantes > 0) {
            $msg .= " ({$conflictosRestantes} mesa(s) con pareja repetida; revise reporte)";
        }

        return [
            'success' => true,
            'message' => $msg,
            'total_mesas' => count($mesas),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesas,
        ];
    }
}
