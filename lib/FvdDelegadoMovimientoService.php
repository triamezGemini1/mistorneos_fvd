<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/FinanzasAsociacionData.php';
require_once __DIR__ . '/SolicitudesAsociacionService.php';

/**
 * Movimientos delegado: alta, carnet, traspaso (admin_fvd DelegadoMovimientoTorneo).
 */
final class FvdDelegadoMovimientoService
{
    /**
     * Registra o actualiza movimiento_torneo para una afiliación completa (AF + carnet + anualidad).
     * Notifica a admin general (web + Telegram) si queda pendiente de aprobación FVD.
     *
     * @return int ID movimiento_torneo
     */
    public static function registrarSolicitudAfiliacion(
        PDO $pdo,
        int $userId,
        int $torneoId,
        int $clubId,
        bool $aprobadoPorAdmin = false
    ): int {
        self::assertClubAtleta($pdo, $userId, $clubId);
        FvdMovimientoTorneoHelper::assertTorneoEditable($pdo, $torneoId);
        $u = self::filaUsuario($pdo, $userId);
        $cedula = FvdMovimientoTorneoHelper::normalizarCedula((string) ($u['cedula'] ?? ''));
        $numfvd = (int) ($u['numfvd'] ?? 0);
        $sexo = (int) ($u['sexo'] ?? 0);
        $stUsr = (int) ($u['status'] ?? 0);
        $anualidad = $stUsr === FvdMovimientoTorneoHelper::STATUS_USUARIO_PENDIENTE_ANUALIDAD ? 1 : 1;
        $estatusSol = ($aprobadoPorAdmin && $numfvd > 0)
            ? SolicitudesAsociacionService::ESTATUS_APROBADO
            : SolicitudesAsociacionService::ESTATUS_PENDIENTE;
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);
        if ($clubCol === null || $clubCol === '') {
            throw new RuntimeException('movimiento_torneo sin columna id_club ni asociacion_id.');
        }

        $movId = self::upsertMovimiento($pdo, $userId, $torneoId, $clubId, $clubCol, [
            'cedula' => $cedula,
            'numfvd' => $numfvd,
            'sexo' => $sexo,
            'estatus' => $estatusSol,
            'afiliacion' => 1,
            'anualidad' => $anualidad,
            'carnet' => 1,
            'traspaso' => 0,
            'grupo_nombre' => null,
        ]);

        if ($estatusSol === SolicitudesAsociacionService::ESTATUS_PENDIENTE) {
            self::notificarAdminPendiente($pdo, $movId, $clubId);
        }

        return $movId;
    }

    public static function upsertNuevoAfiliado(PDO $pdo, int $userId, int $torneoId, int $clubId): int
    {
        return self::registrarSolicitudAfiliacion($pdo, $userId, $torneoId, $clubId, false);
    }

    public static function solicitarCarnet(PDO $pdo, int $userId, int $torneoId, int $clubId): int
    {
        self::assertClubAtleta($pdo, $userId, $clubId);
        FvdMovimientoTorneoHelper::assertTorneoEditable($pdo, $torneoId);
        $u = self::filaUsuario($pdo, $userId);
        $stUsr = (int) ($u['status'] ?? 0);
        $anualidad = $stUsr === FvdMovimientoTorneoHelper::STATUS_USUARIO_PENDIENTE_ANUALIDAD ? 1 : 0;
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);
        $ex = self::movimientoExistente($pdo, $userId, $torneoId);
        if ($ex !== null) {
            $curAn = (int) ($ex['anualidad'] ?? 0);
            $newAn = $anualidad > 0 ? max($curAn, 1) : $curAn;
            $pdo->prepare(
                "UPDATE movimiento_torneo SET carnet = 1, anualidad = ?, cedula = ?, numfvd = ?, sexo = ?,
                 id_usuario = ?, estatus = ?
                 WHERE id = ? LIMIT 1"
            )->execute([
                $newAn,
                FvdMovimientoTorneoHelper::normalizarCedula((string) ($u['cedula'] ?? '')),
                (int) ($u['numfvd'] ?? 0),
                (int) ($u['sexo'] ?? 0),
                $userId,
                SolicitudesAsociacionService::ESTATUS_PENDIENTE,
                (int) $ex['id'],
            ]);

            return self::notificarSiNuevo($pdo, (int) $ex['id'], $clubId, 'carnet');
        }

        return self::upsertMovimiento($pdo, $userId, $torneoId, $clubId, $clubCol, [
            'cedula' => FvdMovimientoTorneoHelper::normalizarCedula((string) ($u['cedula'] ?? '')),
            'numfvd' => (int) ($u['numfvd'] ?? 0),
            'sexo' => (int) ($u['sexo'] ?? 0),
            'estatus' => SolicitudesAsociacionService::ESTATUS_PENDIENTE,
            'afiliacion' => 0,
            'anualidad' => $anualidad,
            'carnet' => 1,
            'traspaso' => 0,
            'grupo_nombre' => null,
        ]);
    }

    public static function solicitarTraspaso(PDO $pdo, int $userId, int $torneoId, int $clubOrigenId, int $clubDestinoId): int
    {
        if ($clubDestinoId < 1 || $clubDestinoId === $clubOrigenId) {
            throw new InvalidArgumentException('Asociación destino inválida.');
        }
        $st = $pdo->prepare('SELECT id FROM clubes WHERE id = ? AND estatus = 1 LIMIT 1');
        $st->execute([$clubDestinoId]);
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('La asociación destino no existe.');
        }
        self::assertClubAtleta($pdo, $userId, $clubOrigenId);
        FvdMovimientoTorneoHelper::assertTorneoEditable($pdo, $torneoId);
        $u = self::filaUsuario($pdo, $userId);
        $stUsr = (int) ($u['status'] ?? 0);
        $anualidad = $stUsr === FvdMovimientoTorneoHelper::STATUS_USUARIO_PENDIENTE_ANUALIDAD ? 1 : 0;
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);
        $grupo = FvdMovimientoTorneoHelper::empaquetarGrupoConDestino($clubDestinoId);
        $ex = self::movimientoExistente($pdo, $userId, $torneoId);
        if ($ex !== null) {
            $curAn = (int) ($ex['anualidad'] ?? 0);
            $newAn = $anualidad > 0 ? max($curAn, 1) : $curAn;
            $pdo->prepare(
                "UPDATE movimiento_torneo SET carnet = 1, traspaso = 1, afiliacion = 0, anualidad = ?,
                 grupo_nombre = ?, cedula = ?, numfvd = ?, sexo = ?, id_usuario = ?, {$clubCol} = ?, estatus = ?
                 WHERE id = ? LIMIT 1"
            )->execute([
                $newAn,
                $grupo,
                FvdMovimientoTorneoHelper::normalizarCedula((string) ($u['cedula'] ?? '')),
                (int) ($u['numfvd'] ?? 0),
                (int) ($u['sexo'] ?? 0),
                $userId,
                $clubOrigenId,
                SolicitudesAsociacionService::ESTATUS_PENDIENTE,
                (int) $ex['id'],
            ]);

            return self::notificarSiNuevo($pdo, (int) $ex['id'], $clubOrigenId, 'traspaso');
        }

        return self::upsertMovimiento($pdo, $userId, $torneoId, $clubOrigenId, $clubCol, [
            'cedula' => FvdMovimientoTorneoHelper::normalizarCedula((string) ($u['cedula'] ?? '')),
            'numfvd' => (int) ($u['numfvd'] ?? 0),
            'sexo' => (int) ($u['sexo'] ?? 0),
            'estatus' => SolicitudesAsociacionService::ESTATUS_PENDIENTE,
            'afiliacion' => 0,
            'anualidad' => $anualidad,
            'carnet' => 1,
            'traspaso' => 1,
            'grupo_nombre' => $grupo,
        ]);
    }

    public static function insertarTridenteAdmin(
        PDO $pdo,
        int $userId,
        string $cedula,
        int $numfvd,
        int $sexo,
        int $clubId,
        int $torneoId
    ): int {
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);

        return self::upsertMovimiento($pdo, $userId, $torneoId, $clubId, $clubCol, [
            'cedula' => $cedula,
            'numfvd' => $numfvd,
            'sexo' => $sexo,
            'estatus' => SolicitudesAsociacionService::ESTATUS_APROBADO,
            'afiliacion' => 1,
            'anualidad' => 1,
            'carnet' => 1,
            'traspaso' => 0,
            'grupo_nombre' => null,
        ]);
    }

    /**
     * @param array{cedula:string,numfvd:int,sexo:int,estatus:int,afiliacion:int,anualidad:int,carnet:int,traspaso:int,grupo_nombre:?string} $data
     */
    private static function upsertMovimiento(
        PDO $pdo,
        int $userId,
        int $torneoId,
        int $clubId,
        string $clubCol,
        array $data
    ): int {
        $ex = self::movimientoExistente($pdo, $userId, $torneoId);
        if ($ex !== null) {
            $pdo->prepare(
                "UPDATE movimiento_torneo SET id_usuario = ?, cedula = ?, numfvd = ?, sexo = ?, {$clubCol} = ?,
                 estatus = ?, afiliacion = ?, anualidad = ?, carnet = ?, traspaso = ?, inscripcion = 0, grupo_nombre = ?
                 WHERE id = ? LIMIT 1"
            )->execute([
                $userId,
                $data['cedula'],
                $data['numfvd'],
                $data['sexo'],
                $clubId,
                $data['estatus'],
                $data['afiliacion'],
                $data['anualidad'],
                $data['carnet'],
                $data['traspaso'],
                $data['grupo_nombre'],
                (int) $ex['id'],
            ]);

            return (int) $ex['id'];
        }
        $pdo->prepare(
            "INSERT INTO movimiento_torneo
                (id_usuario, cedula, numfvd, sexo, {$clubCol}, estatus, afiliacion, anualidad, carnet, traspaso, inscripcion, torneo_id, grupo_nombre)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)"
        )->execute([
            $userId,
            $data['cedula'],
            $data['numfvd'],
            $data['sexo'],
            $clubId,
            $data['estatus'],
            $data['afiliacion'],
            $data['anualidad'],
            $data['carnet'],
            $data['traspaso'],
            $torneoId,
            $data['grupo_nombre'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{afiliacion:int,traspaso:int,carnet:int} $data
     */
    private static function tipoDesdeFlags(array $data): string
    {
        if ((int) ($data['traspaso'] ?? 0) > 0) {
            return 'traspaso';
        }
        if ((int) ($data['carnet'] ?? 0) > 0 && (int) ($data['afiliacion'] ?? 0) === 0) {
            return 'carnet';
        }

        return 'afiliacion';
    }

    private static function notificarAdminPendiente(PDO $pdo, int $movId, int $clubId): void
    {
        if ($movId <= 0) {
            return;
        }
        $st = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
        $st->execute([$clubId]);
        $club = ['id' => $clubId, 'nombre' => (string) ($st->fetchColumn() ?: 'Asociación')];
        SolicitudesAsociacionService::notificarNuevaSolicitudAdmin($pdo, $movId, $club, 'afiliacion');
    }

    private static function notificarSiNuevo(PDO $pdo, int $movId, int $clubId, string $tipo): int
    {
        if ($movId > 0) {
            $st = $pdo->prepare('SELECT estatus FROM movimiento_torneo WHERE id = ? LIMIT 1');
            $st->execute([$movId]);
            if ((int) ($st->fetchColumn() ?: -1) === SolicitudesAsociacionService::ESTATUS_PENDIENTE) {
                self::notificarAdminPendiente($pdo, $movId, $clubId);
            }
        }

        return $movId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function movimientoExistente(PDO $pdo, int $userId, int $torneoId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM movimiento_torneo WHERE id_usuario = ? AND torneo_id = ? LIMIT 1');
        $st->execute([$userId, $torneoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function filaUsuario(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare('SELECT id, cedula, numfvd, sexo, status, club_id FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Usuario no encontrado.');
        }

        return $row;
    }

    private static function assertClubAtleta(PDO $pdo, int $userId, int $clubId): void
    {
        $u = self::filaUsuario($pdo, $userId);
        $uClub = (int) ($u['club_id'] ?? 0);
        if ($uClub > 0 && $uClub !== $clubId) {
            throw new InvalidArgumentException('El atleta no pertenece a su asociación.');
        }
    }
}
