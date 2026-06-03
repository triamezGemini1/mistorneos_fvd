<?php

declare(strict_types=1);

require_once __DIR__ . '/FinanzasAsociacionData.php';
require_once __DIR__ . '/app_helpers.php';
require_once __DIR__ . '/NotificationManager.php';

/**
 * Solicitudes operativas de asociación (movimiento_torneo): afiliación, traspaso, carnet.
 */
final class SolicitudesAsociacionService
{
    public const ESTATUS_PENDIENTE = 0;
    public const ESTATUS_APROBADO = 1;
    public const ESTATUS_RECHAZADO = 2;

    /** @var list<string> */
    public const TIPOS_FILTRO = ['todas', 'afiliacion', 'traspaso', 'carnet'];

    public static function tablaDisponible(PDO $pdo): bool
    {
        return FinanzasAsociacionData::tablaExiste($pdo, 'movimiento_torneo');
    }

    public static function sqlBaseSolicitudes(string $alias = 'm'): string
    {
        return "({$alias}.afiliacion > 0 OR {$alias}.traspaso > 0 OR {$alias}.carnet > 0)";
    }

    public static function resolverTipoFila(array $row): string
    {
        if ((int) ($row['traspaso'] ?? 0) > 0) {
            return 'traspaso';
        }
        if ((int) ($row['carnet'] ?? 0) > 0) {
            return 'carnet';
        }
        if ((int) ($row['afiliacion'] ?? 0) > 0) {
            return 'afiliacion';
        }

        return 'otro';
    }

    public static function etiquetaTipo(string $tipo): string
    {
        switch ($tipo) {
            case 'afiliacion':
                return 'Afiliación';
            case 'traspaso':
                return 'Traspaso';
            case 'carnet':
                return 'Carnet';
            default:
                return 'Solicitud';
        }
    }

    /**
     * @return array{total:int,pendiente:int,afiliacion:int,traspaso:int,carnet:int}
     */
    public static function contadores(PDO $pdo, ?int $soloPendientes = null): array
    {
        $out = [
            'total' => 0,
            'pendiente' => 0,
            'afiliacion' => 0,
            'traspaso' => 0,
            'carnet' => 0,
        ];
        if (!self::tablaDisponible($pdo)) {
            return $out;
        }
        $base = self::sqlBaseSolicitudes('m');
        try {
            $out['total'] = (int) $pdo->query("SELECT COUNT(*) FROM movimiento_torneo m WHERE {$base}")->fetchColumn();
            $out['pendiente'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM movimiento_torneo m WHERE {$base} AND m.estatus = " . self::ESTATUS_PENDIENTE
            )->fetchColumn();
            $out['afiliacion'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM movimiento_torneo m WHERE {$base} AND m.estatus = " . self::ESTATUS_PENDIENTE . ' AND m.afiliacion > 0'
            )->fetchColumn();
            $out['traspaso'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM movimiento_torneo m WHERE {$base} AND m.estatus = " . self::ESTATUS_PENDIENTE . ' AND m.traspaso > 0'
            )->fetchColumn();
            $out['carnet'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM movimiento_torneo m WHERE {$base} AND m.estatus = " . self::ESTATUS_PENDIENTE . ' AND m.carnet > 0'
            )->fetchColumn();
        } catch (Throwable $e) {
            error_log('SolicitudesAsociacionService::contadores: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listar(
        PDO $pdo,
        string $tipoFiltro = 'todas',
        ?int $estatusFiltro = self::ESTATUS_PENDIENTE,
        int $limite = 200
    ): array {
        if (!self::tablaDisponible($pdo)) {
            return [];
        }
        $tipoFiltro = in_array($tipoFiltro, self::TIPOS_FILTRO, true) ? $tipoFiltro : 'todas';
        $clubCol = FinanzasAsociacionData::movimientoClubColumn($pdo) ?? 'id_club';
        $where = [self::sqlBaseSolicitudes('m')];
        $params = [];
        if ($estatusFiltro !== null) {
            $where[] = 'm.estatus = ?';
            $params[] = $estatusFiltro;
        }
        if ($tipoFiltro === 'afiliacion') {
            $where[] = 'm.afiliacion > 0';
        } elseif ($tipoFiltro === 'traspaso') {
            $where[] = 'm.traspaso > 0';
        } elseif ($tipoFiltro === 'carnet') {
            $where[] = 'm.carnet > 0';
        }
        $sql = "
            SELECT m.*,
                   c.nombre AS club_nombre,
                   c.delegado_user_id,
                   u.nombre AS usuario_nombre,
                   u.email AS usuario_email
            FROM movimiento_torneo m
            LEFT JOIN clubes c ON c.id = m.{$clubCol}
            LEFT JOIN usuarios u ON u.id = m.id_usuario AND m.id_usuario > 0
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT " . max(1, min(500, $limite));
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['tipo_solicitud'] = self::resolverTipoFila($r);
            $r['tipo_label'] = self::etiquetaTipo($r['tipo_solicitud']);
        }
        unset($r);

        return $rows;
    }

    public static function obtener(PDO $pdo, int $id): ?array
    {
        if ($id <= 0 || !self::tablaDisponible($pdo)) {
            return null;
        }
        $clubCol = FinanzasAsociacionData::movimientoClubColumn($pdo) ?? 'id_club';
        $st = $pdo->prepare("
            SELECT m.*, c.nombre AS club_nombre, c.delegado_user_id
            FROM movimiento_torneo m
            LEFT JOIN clubes c ON c.id = m.{$clubCol}
            WHERE m.id = ? AND " . self::sqlBaseSolicitudes('m') . '
            LIMIT 1
        ');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row !== [] ? $row : null;
    }

    public static function aprobar(PDO $pdo, int $id, int $adminId): bool
    {
        $row = self::obtener($pdo, $id);
        if ($row === null || (int) ($row['estatus'] ?? -1) !== self::ESTATUS_PENDIENTE) {
            return false;
        }
        try {
            require_once __DIR__ . '/FvdSupervisionMovimientoService.php';
            FvdSupervisionMovimientoService::aprobarMovimiento($pdo, $id);
        } catch (Throwable $e) {
            error_log('SolicitudesAsociacionService::aprobar: ' . $e->getMessage());

            return false;
        }
        $st = $pdo->prepare('UPDATE movimiento_torneo SET estatus = ? WHERE id = ? AND estatus = ?');
        $st->execute([self::ESTATUS_APROBADO, $id, self::ESTATUS_PENDIENTE]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        self::notificarResolucion($pdo, $row, true, $adminId);

        return true;
    }

    public static function rechazar(PDO $pdo, int $id, int $adminId, string $motivo = ''): bool
    {
        $row = self::obtener($pdo, $id);
        if ($row === null || (int) ($row['estatus'] ?? -1) !== self::ESTATUS_PENDIENTE) {
            return false;
        }
        try {
            require_once __DIR__ . '/FvdSupervisionMovimientoService.php';
            FvdSupervisionMovimientoService::rechazarMovimiento($pdo, $id);
        } catch (Throwable $e) {
            error_log('SolicitudesAsociacionService::rechazar: ' . $e->getMessage());

            return false;
        }
        $st = $pdo->prepare('UPDATE movimiento_torneo SET estatus = ? WHERE id = ? AND estatus = ?');
        $st->execute([self::ESTATUS_RECHAZADO, $id, self::ESTATUS_PENDIENTE]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        $row['motivo_rechazo'] = $motivo;
        self::notificarResolucion($pdo, $row, false, $adminId);

        return true;
    }

    /**
     * Aviso a administradores generales cuando una asociación envía una solicitud.
     */
    public static function notificarNuevaSolicitudAdmin(PDO $pdo, int $movimientoId, array $club, string $tipo): void
    {
        if ($movimientoId <= 0) {
            return;
        }
        try {
            $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
            $sqlAdmins = $hasTg
                ? "SELECT id, telegram_chat_id FROM usuarios WHERE role = 'admin_general' AND (status = 0 OR status = 'approved' OR status = 1 OR status = '0')"
                : "SELECT id FROM usuarios WHERE role = 'admin_general' AND (status = 0 OR status = 'approved' OR status = 1 OR status = '0')";
            $admins = $pdo->query($sqlAdmins)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($admins === []) {
                return;
            }
            $detalleAtleta = '';
            $stM = $pdo->prepare(
                'SELECT m.cedula, m.numfvd, u.nombre AS usuario_nombre
                 FROM movimiento_torneo m
                 LEFT JOIN usuarios u ON u.id = m.id_usuario
                 WHERE m.id = ? LIMIT 1'
            );
            $stM->execute([$movimientoId]);
            $mov = $stM->fetch(PDO::FETCH_ASSOC);
            if (is_array($mov) && $mov !== []) {
                $detalleAtleta = trim((string) ($mov['usuario_nombre'] ?? ''));
                $ced = trim((string) ($mov['cedula'] ?? ''));
                if ($ced !== '') {
                    $detalleAtleta .= ($detalleAtleta !== '' ? ' — ' : '') . 'Cédula ' . $ced;
                }
            }
            $url = AppHelpers::dashboard('solicitudes_asociacion', ['tipo' => $tipo !== '' ? $tipo : 'todas']);
            $msg = 'Nueva solicitud de ' . self::etiquetaTipo($tipo)
                . ' — ' . trim((string) ($club['nombre'] ?? 'Asociación'))
                . ' (ref. #' . $movimientoId . ').';
            if ($detalleAtleta !== '') {
                $msg .= ' Atleta: ' . $detalleAtleta . '.';
            }
            $msg .= ' Revise en Supervisión FVD para aprobar o rechazar.';
            $nm = new NotificationManager($pdo);
            $items = [];
            foreach ($admins as $adminRow) {
                $uid = (int) ($adminRow['id'] ?? $adminRow);
                if ($uid <= 0) {
                    continue;
                }
                $items[] = [
                    'id' => $uid,
                    'telegram_chat_id' => $hasTg
                        ? (trim((string) ($adminRow['telegram_chat_id'] ?? '')) ?: null)
                        : null,
                    'mensaje' => $msg,
                    'url_destino' => $url,
                    'datos_json' => json_encode([
                        'tipo' => 'solicitud_asociacion_nueva',
                        'movimiento_id' => $movimientoId,
                        'club_id' => (int) ($club['id'] ?? 0),
                        'tipo_solicitud' => $tipo,
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }
            if ($items !== []) {
                $nm->programarMasivoPersonalizado($items);
            }
        } catch (Throwable $e) {
            error_log('SolicitudesAsociacionService::notificarNuevaSolicitudAdmin: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function notificarResolucion(PDO $pdo, array $row, bool $aprobada, int $adminId): void
    {
        $tipo = self::resolverTipoFila($row);
        $tipoLabel = self::etiquetaTipo($tipo);
        $clubNombre = trim((string) ($row['club_nombre'] ?? 'su asociación'));
        $ref = (int) ($row['id'] ?? 0);
        $cedula = trim((string) ($row['cedula'] ?? ''));
        $estadoTxt = $aprobada ? 'aprobada' : 'rechazada';
        $urlPanel = AppHelpers::dashboard('asociacion_panel');
        $msgBase = "Su solicitud de {$tipoLabel} (ref. #{$ref}) fue {$estadoTxt} por la FVD.";
        if (!$aprobada && trim((string) ($row['motivo_rechazo'] ?? '')) !== '') {
            $msgBase .= ' Motivo: ' . trim((string) $row['motivo_rechazo']);
        }

        $destinatarios = [];
        $delegadoId = (int) ($row['delegado_user_id'] ?? 0);
        if ($delegadoId > 0) {
            $destinatarios[$delegadoId] = self::filaUsuarioNotif($pdo, $delegadoId);
        }
        $clubCol = FinanzasAsociacionData::movimientoClubColumn($pdo) ?? 'id_club';
        $clubId = (int) ($row[$clubCol] ?? $row['id_club'] ?? 0);
        if ($clubId > 0) {
            $stAc = $pdo->prepare("SELECT id FROM usuarios WHERE role = 'admin_club' AND club_id = ? AND id != ?");
            $stAc->execute([$clubId, $delegadoId]);
            foreach ($stAc->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
                $uid = (int) $uid;
                if ($uid > 0) {
                    $destinatarios[$uid] = self::filaUsuarioNotif($pdo, $uid);
                }
            }
        }
        $atletaId = (int) ($row['id_usuario'] ?? 0);
        if ($atletaId > 0) {
            $destinatarios[$atletaId] = self::filaUsuarioNotif($pdo, $atletaId);
        }

        try {
            $nm = new NotificationManager($pdo);
            $items = [];
            foreach ($destinatarios as $d) {
                if ($d === null) {
                    continue;
                }
                $items[] = [
                    'id' => (int) $d['id'],
                    'telegram_chat_id' => $d['telegram_chat_id'] ?? null,
                    'mensaje' => $msgBase . ($cedula !== '' ? " Atleta: {$cedula}." : ''),
                    'url_destino' => $urlPanel,
                    'datos_json' => json_encode([
                        'tipo' => $aprobada ? 'solicitud_asociacion_aprobada' : 'solicitud_asociacion_rechazada',
                        'movimiento_id' => $ref,
                        'tipo_solicitud' => $tipo,
                        'admin_id' => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }
            if ($items !== []) {
                $nm->programarMasivoPersonalizado($items);
            }
        } catch (Throwable $e) {
            error_log('SolicitudesAsociacionService::notificarResolucion: ' . $e->getMessage());
        }
    }

    /**
     * @return array{id:int, telegram_chat_id?:string|null}|null
     */
    private static function filaUsuarioNotif(PDO $pdo, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
        $sql = $hasTg
            ? 'SELECT id, telegram_chat_id FROM usuarios WHERE id = ? LIMIT 1'
            : 'SELECT id FROM usuarios WHERE id = ? LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return null;
        }

        return [
            'id' => (int) $u['id'],
            'telegram_chat_id' => $hasTg ? (trim((string) ($u['telegram_chat_id'] ?? '')) ?: null) : null,
        ];
    }
}
