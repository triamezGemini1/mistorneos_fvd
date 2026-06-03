<?php

declare(strict_types=1);

require_once __DIR__ . '/NumfvdHelper.php';

/**
 * Persistencia de historial_parejas (parejas A-C y B-D por mesa).
 * Usado por MesaRepository, parejas fijas y equipos para una sola fuente de verdad.
 */
final class HistorialParejasService
{
    private static ?bool $tieneColumnaMesa = null;

    public static function eliminarRonda(PDO $pdo, int $torneoId, int $rondaId): void
    {
        if ($torneoId <= 0 || $rondaId <= 0) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM historial_parejas WHERE torneo_id = ? AND ronda_id = ?')
                ->execute([$torneoId, $rondaId]);
        } catch (Throwable $e) {
            // Tabla ausente en instalaciones antiguas.
        }
    }

    /**
     * @param list<list<array<string, mixed>>> $mesas Layout [A,C,B,D] con clave id_usuario en cada jugador
     */
    public static function guardarDesdeMesasLayout(PDO $pdo, int $torneoId, int $rondaId, array $mesas): void
    {
        if ($torneoId <= 0 || $rondaId <= 0 || $mesas === []) {
            return;
        }

        $parejas = [];
        $numeroMesa = 1;
        foreach ($mesas as $mesa) {
            if (count($mesa) < 4) {
                $numeroMesa++;
                continue;
            }
            $ids = [];
            foreach ($mesa as $jugador) {
                $raw = (int) ($jugador['id_usuario'] ?? 0);
                $ids[] = self::normalizarIdUsuarioTorneo($pdo, $torneoId, $raw);
            }
            $a = (int) ($ids[0] ?? 0);
            $c = (int) ($ids[1] ?? 0);
            $b = (int) ($ids[2] ?? 0);
            $d = (int) ($ids[3] ?? 0);
            if ($a > 0 && $c > 0) {
                $parejas[] = ['j1' => min($a, $c), 'j2' => max($a, $c), 'mesa' => $numeroMesa];
            }
            if ($b > 0 && $d > 0) {
                $parejas[] = ['j1' => min($b, $d), 'j2' => max($b, $d), 'mesa' => $numeroMesa];
            }
            $numeroMesa++;
        }

        if ($parejas === []) {
            return;
        }

        try {
            self::insertarBatch($pdo, $torneoId, $rondaId, $parejas, true);
        } catch (Throwable $e) {
            try {
                self::insertarBatch($pdo, $torneoId, $rondaId, $parejas, false);
            } catch (Throwable $e2) {
                error_log('HistorialParejasService::guardarDesdeMesasLayout: ' . $e2->getMessage());
            }
        }
    }

    private static function normalizarIdUsuarioTorneo(PDO $pdo, int $torneoId, int $identificador): int
    {
        if ($identificador <= 0) {
            return 0;
        }
        $uid = NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneoId, $identificador);

        return ($uid !== null && $uid > 0) ? $uid : $identificador;
    }

    /**
     * @param list<array{j1: int, j2: int, mesa: int}> $parejas
     */
    private static function insertarBatch(PDO $pdo, int $torneoId, int $rondaId, array $parejas, bool $conLlave): void
    {
        $conMesa = self::columnaMesaExiste($pdo);
        $filas = [];
        foreach ($parejas as $p) {
            $idMenor = (int) ($p['j1'] ?? 0);
            $idMayor = (int) ($p['j2'] ?? 0);
            $mesa = (int) ($p['mesa'] ?? 0);
            if ($idMenor <= 0 || $idMayor <= 0) {
                continue;
            }
            if ($conMesa && $conLlave) {
                $filas[] = [$torneoId, $rondaId, $mesa, $idMenor, $idMayor, $idMenor . '-' . $idMayor];
            } elseif ($conMesa) {
                $filas[] = [$torneoId, $rondaId, $mesa, $idMenor, $idMayor];
            } elseif ($conLlave) {
                $filas[] = [$torneoId, $rondaId, $idMenor, $idMayor, $idMenor . '-' . $idMayor];
            } else {
                $filas[] = [$torneoId, $rondaId, $idMenor, $idMayor];
            }
        }

        if ($filas === []) {
            return;
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertKw = $driver === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

        if ($conMesa && $conLlave) {
            $sqlBase = $insertKw . ' historial_parejas (torneo_id, ronda_id, mesa, jugador_1_id, jugador_2_id, llave) VALUES ';
            $marcador = '(?,?,?,?,?,?)';
        } elseif ($conMesa) {
            $sqlBase = $insertKw . ' historial_parejas (torneo_id, ronda_id, mesa, jugador_1_id, jugador_2_id) VALUES ';
            $marcador = '(?,?,?,?,?)';
        } elseif ($conLlave) {
            $sqlBase = $insertKw . ' historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id, llave) VALUES ';
            $marcador = '(?,?,?,?,?)';
        } else {
            $sqlBase = $insertKw . ' historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id) VALUES ';
            $marcador = '(?,?,?,?)';
        }

        foreach (array_chunk($filas, 200) as $lote) {
            $placeholders = [];
            $valores = [];
            foreach ($lote as $fila) {
                $placeholders[] = $marcador;
                foreach ($fila as $valor) {
                    $valores[] = $valor;
                }
            }
            $stmt = $pdo->prepare($sqlBase . implode(',', $placeholders));
            $stmt->execute($valores);
        }
    }

    private static function columnaMesaExiste(PDO $pdo): bool
    {
        if (self::$tieneColumnaMesa !== null) {
            return self::$tieneColumnaMesa;
        }
        try {
            $st = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $st->execute(['historial_parejas', 'mesa']);
            self::$tieneColumnaMesa = (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            self::$tieneColumnaMesa = false;
        }

        return self::$tieneColumnaMesa;
    }
}
