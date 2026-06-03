<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/SolicitudesAsociacionService.php';

/**
 * Aprobación / rechazo operativo (admin_fvd SupervisionFvd) sobre flags de movimiento_torneo.
 */
final class FvdSupervisionMovimientoService
{
    public static function aprobarMovimiento(PDO $pdo, int $movimientoId): void
    {
        $row = self::cargarMovimiento($pdo, $movimientoId);
        $tipo = SolicitudesAsociacionService::resolverTipoFila($row);
        if ($tipo === 'afiliacion' && (int) ($row['afiliacion'] ?? 0) > 0 && (int) ($row['numfvd'] ?? 0) < 1) {
            self::aprobarAfiliacion($pdo, $row);
        } elseif ($tipo === 'traspaso' && (int) ($row['traspaso'] ?? 0) > 0) {
            self::aprobarTraspaso($pdo, $row);
        } elseif ($tipo === 'carnet' && (int) ($row['carnet'] ?? 0) > 0) {
            self::aprobarCarnet($pdo, $row);
        } else {
            throw new InvalidArgumentException('No hay una solicitud pendiente reconocible en este movimiento.');
        }
    }

    public static function rechazarMovimiento(PDO $pdo, int $movimientoId): void
    {
        $row = self::cargarMovimiento($pdo, $movimientoId);
        $tipo = SolicitudesAsociacionService::resolverTipoFila($row);
        if ($tipo === 'afiliacion' && (int) ($row['afiliacion'] ?? 0) > 0 && (int) ($row['numfvd'] ?? 0) < 1) {
            self::rechazarAfiliacion($pdo, $row);
        } elseif ($tipo === 'traspaso' && (int) ($row['traspaso'] ?? 0) > 0) {
            self::rechazarTraspaso($pdo, $row);
        } elseif ($tipo === 'carnet' && (int) ($row['carnet'] ?? 0) > 0) {
            self::rechazarCarnet($pdo, $row);
        } else {
            throw new InvalidArgumentException('No se pudo rechazar: movimiento no coincide con solicitud pendiente.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function cargarMovimiento(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare('SELECT m.*, u.club_id AS usuario_club_id FROM movimiento_torneo m
            LEFT JOIN usuarios u ON u.id = m.id_usuario WHERE m.id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Movimiento no encontrado.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function aprobarAfiliacion(PDO $pdo, array $row): void
    {
        $mid = (int) $row['id'];
        $tid = (int) $row['torneo_id'];
        $uid = (int) $row['id_usuario'];
        if ($uid < 1) {
            throw new InvalidArgumentException('Movimiento sin usuario válido.');
        }
        $pdo->beginTransaction();
        try {
            $stU = $pdo->prepare('SELECT numfvd FROM usuarios WHERE id = ? LIMIT 1');
            $stU->execute([$uid]);
            $numfvdUsuario = (int) ($stU->fetchColumn() ?: 0);
            $numfvdFinal = $numfvdUsuario;
            if ($numfvdUsuario < 1) {
                $mx = (int) $pdo->query('SELECT COALESCE(MAX(numfvd), 0) FROM usuarios')->fetchColumn();
                $numfvdFinal = max(1, $mx + 1);
                $up = $pdo->prepare('UPDATE usuarios SET numfvd = ? WHERE id = ? AND COALESCE(numfvd, 0) < 1 LIMIT 1');
                $up->execute([$numfvdFinal, $uid]);
                if ($up->rowCount() < 1) {
                    $stU->execute([$uid]);
                    $numfvdFinal = (int) ($stU->fetchColumn() ?: 0);
                }
            }
            if ($numfvdFinal < 1) {
                throw new InvalidArgumentException('No se pudo asignar el Nº FVD.');
            }
            $stU2 = $pdo->prepare('SELECT cedula, sexo FROM usuarios WHERE id = ? LIMIT 1');
            $stU2->execute([$uid]);
            $uSync = $stU2->fetch(PDO::FETCH_ASSOC) ?: [];
            $cedSync = FvdMovimientoTorneoHelper::normalizarCedula((string) ($uSync['cedula'] ?? $row['cedula'] ?? ''));
            $sexoSync = (int) ($uSync['sexo'] ?? $row['sexo'] ?? 0);
            $st = $pdo->prepare(
                'UPDATE movimiento_torneo SET numfvd = ?, id_usuario = ?, cedula = ?, sexo = ?
                 WHERE id = ? AND torneo_id = ? AND afiliacion = 1 LIMIT 1'
            );
            $st->execute([$numfvdFinal, $uid, $cedSync, $sexoSync, $mid, $tid]);
            if ($st->rowCount() < 1) {
                throw new InvalidArgumentException('No se actualizó la afiliación del movimiento.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rechazarAfiliacion(PDO $pdo, array $row): void
    {
        $st = $pdo->prepare(
            'UPDATE movimiento_torneo SET afiliacion = 0, anualidad = 0, carnet = 0
             WHERE id = ? AND afiliacion = 1 AND COALESCE(numfvd, 0) < 1 LIMIT 1'
        );
        $st->execute([(int) $row['id']]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException('No se rechazó la afiliación.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function aprobarCarnet(PDO $pdo, array $row): void
    {
        $st = $pdo->prepare(
            'UPDATE movimiento_torneo SET carnet = 0
             WHERE id = ? AND carnet = 1 AND ' . FvdMovimientoTorneoHelper::SQL_AFILI_NO_BLOQUEA . ' AND traspaso <> 1 LIMIT 1'
        );
        $st->execute([(int) $row['id']]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException('No se aprobó el carnet.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rechazarCarnet(PDO $pdo, array $row): void
    {
        $st = $pdo->prepare(
            'UPDATE movimiento_torneo SET afiliacion = 0, anualidad = 0, carnet = 0
             WHERE id = ? AND carnet = 1 AND ' . FvdMovimientoTorneoHelper::SQL_AFILI_NO_BLOQUEA . ' AND traspaso <> 1 LIMIT 1'
        );
        $st->execute([(int) $row['id']]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException('No se rechazó el carnet.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function aprobarTraspaso(PDO $pdo, array $row): void
    {
        $dest = FvdMovimientoTorneoHelper::parsearDestinoClubDesdeGrupo((string) ($row['grupo_nombre'] ?? ''));
        $st = $pdo->prepare(
            'UPDATE movimiento_torneo SET traspaso = 0
             WHERE id = ? AND traspaso = 1 AND ' . FvdMovimientoTorneoHelper::SQL_AFILI_NO_BLOQUEA . ' LIMIT 1'
        );
        $st->execute([(int) $row['id']]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException('No se aprobó el traspaso.');
        }
        if ($dest > 0 && (int) ($row['id_usuario'] ?? 0) > 0) {
            $pdo->prepare('UPDATE usuarios SET club_id = ? WHERE id = ? LIMIT 1')->execute([$dest, (int) $row['id_usuario']]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rechazarTraspaso(PDO $pdo, array $row): void
    {
        $nota = FvdMovimientoTorneoHelper::notaHumanaGrupo((string) ($row['grupo_nombre'] ?? ''));
        $st = $pdo->prepare(
            'UPDATE movimiento_torneo SET inscripcion = 0, traspaso = 0, carnet = 0, grupo_nombre = ?
             WHERE id = ? AND traspaso = 1 AND ' . FvdMovimientoTorneoHelper::SQL_AFILI_NO_BLOQUEA . ' LIMIT 1'
        );
        $st->execute([$nota !== '' ? $nota : null, (int) $row['id']]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException('No se rechazó el traspaso.');
        }
    }
}
