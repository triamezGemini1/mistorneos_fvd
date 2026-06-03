<?php

declare(strict_types=1);

require_once __DIR__ . '/PartiresulJugadorHelper.php';
require_once __DIR__ . '/HistorialParejasService.php';

/**
 * Escritura unificada de asignación de ronda en partiresul (+ historial_parejas opcional).
 */
final class PartiresulAsignacionWriter
{
    public static function eliminarAsignacionRonda(PDO $pdo, int $torneoId, int $ronda, bool $soloMesasJuego = false): void
    {
        if ($soloMesasJuego) {
            $pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0')
                ->execute([$torneoId, $ronda]);
        } else {
            $pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?')
                ->execute([$torneoId, $ronda]);
        }
        HistorialParejasService::eliminarRonda($pdo, $torneoId, $ronda);
    }

    /**
     * @param list<list<array<string, mixed>>> $mesas Cada mesa: 4 jugadores con id_usuario (orden A,C,B,D)
     */
    public static function guardarMesas(
        PDO $pdo,
        int $torneoId,
        int $ronda,
        array $mesas,
        int $registradoPor,
        bool $guardarHistorial = true,
        ?string $fechaPartida = null
    ): void {
        if ($torneoId <= 0 || $ronda <= 0 || $mesas === []) {
            return;
        }

        PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);
        $fechaPartida = $fechaPartida ?? date('Y-m-d H:i:s');
        $registradoPor = max(1, $registradoPor);

        $filas = [];
        $numeroMesa = 1;
        foreach ($mesas as $mesa) {
            $secuencia = 1;
            foreach ($mesa as $jugador) {
                $idUsuario = (int) ($jugador['id_usuario'] ?? 0);
                if ($idUsuario <= 0) {
                    continue;
                }
                $ins = PartiresulJugadorHelper::datosInsertJugador($pdo, $torneoId, $idUsuario);
                $filas[] = [
                    $torneoId,
                    ...PartiresulJugadorHelper::valoresInsertClave($ins, $pdo),
                    $ronda,
                    $numeroMesa,
                    $secuencia,
                    $fechaPartida,
                    0,
                    $registradoPor,
                    0,
                    0,
                    0,
                    0,
                ];
                $secuencia++;
            }
            $numeroMesa++;
        }

        if ($filas === []) {
            return;
        }

        $columnas = '(id_torneo, ' . PartiresulJugadorHelper::fragmentoColumnasInsertClave($pdo) . ', partida, mesa, secuencia, fecha_partida, registrado, registrado_por,'
            . ' resultado1, resultado2, efectividad, ff)';
        $marcadorFila = '(?, ' . PartiresulJugadorHelper::fragmentoMarcadoresInsertClave($pdo) . ', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        foreach (array_chunk($filas, 100) as $lote) {
            $placeholders = [];
            $valores = [];
            foreach ($lote as $fila) {
                $placeholders[] = $marcadorFila;
                foreach ($fila as $valor) {
                    $valores[] = $valor;
                }
            }
            $sql = 'INSERT INTO partiresul ' . $columnas . ' VALUES ' . implode(',', $placeholders);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($valores);
        }

        if ($guardarHistorial) {
            HistorialParejasService::guardarDesdeMesasLayout($pdo, $torneoId, $ronda, $mesas);
        }
    }

    /**
     * Reemplaza partiresul e historial de una ronda (transacción externa opcional).
     *
     * @param list<list<array<string, mixed>>> $mesas
     */
    public static function reemplazarRonda(
        PDO $pdo,
        int $torneoId,
        int $ronda,
        array $mesas,
        int $registradoPor,
        bool $soloMesasJuego = false
    ): void {
        self::eliminarAsignacionRonda($pdo, $torneoId, $ronda, $soloMesasJuego);
        self::guardarMesas($pdo, $torneoId, $ronda, $mesas, $registradoPor, true);
    }
}
