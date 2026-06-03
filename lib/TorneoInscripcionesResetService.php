<?php

declare(strict_types=1);

/**
 * Vaciar inscripciones de un torneo para permitir carga masiva desde cero.
 */
final class TorneoInscripcionesResetService
{
    /**
     * @return array{inscritos: int, equipos: int}
     */
    public static function vaciarInscripcionesTorneo(\PDO $pdo, int $torneoId, bool $borrarEquipos = false): array
    {
        if ($torneoId <= 0) {
            throw new \InvalidArgumentException('Torneo no válido.');
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?');
        $st->execute([$torneoId]);
        $nInscritos = (int) $st->fetchColumn();

        $nEquipos = 0;
        if ($borrarEquipos) {
            $stEq = $pdo->prepare('SELECT COUNT(*) FROM equipos WHERE id_torneo = ?');
            $stEq->execute([$torneoId]);
            $nEquipos = (int) $stEq->fetchColumn();
        }

        if ($nInscritos === 0 && $nEquipos === 0) {
            return ['inscritos' => 0, 'equipos' => 0];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM inscritos WHERE torneo_id = ?')->execute([$torneoId]);
            if ($borrarEquipos) {
                $pdo->prepare('DELETE FROM equipos WHERE id_torneo = ?')->execute([$torneoId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['inscritos' => $nInscritos, 'equipos' => $nEquipos];
    }
}
