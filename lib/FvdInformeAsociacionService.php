<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/FinanzasAsociacionData.php';

/**
 * Informes por asociación / renglón (simplificado desde admin_fvd InformeFvd).
 */
final class FvdInformeAsociacionService
{
    /** @var list<string> */
    public const RENGLONES = ['afiliacion', 'carnet', 'traspaso', 'anualidad', 'inscripcion'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function consolidadoPorClub(PDO $pdo, ?int $torneoId = null): array
    {
        if (!FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {
            return [];
        }
        $tid = $torneoId ?? FvdMovimientoTorneoHelper::torneoActivoId($pdo);
        if ($tid === null || $tid < 1) {
            return [];
        }
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);
        $sql = "
            SELECT c.id, c.nombre,
                SUM(CASE WHEN m.afiliacion > 0 THEN 1 ELSE 0 END) AS n_afiliacion,
                SUM(CASE WHEN m.carnet > 0 THEN 1 ELSE 0 END) AS n_carnet,
                SUM(CASE WHEN m.traspaso > 0 THEN 1 ELSE 0 END) AS n_traspaso,
                SUM(CASE WHEN m.anualidad > 0 THEN 1 ELSE 0 END) AS n_anualidad,
                SUM(CASE WHEN m.inscripcion > 0 THEN 1 ELSE 0 END) AS n_inscripcion
            FROM movimiento_torneo m
            INNER JOIN clubes c ON c.id = m.{$clubCol}
            WHERE m.torneo_id = ?
            GROUP BY c.id, c.nombre
            ORDER BY c.nombre
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$tid]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function detalleRenglon(PDO $pdo, int $clubId, string $renglon, ?int $torneoId = null): array
    {
        $renglon = strtolower(trim($renglon));
        if (!in_array($renglon, self::RENGLONES, true) || $clubId < 1) {
            return [];
        }
        $tid = $torneoId ?? FvdMovimientoTorneoHelper::torneoActivoId($pdo);
        if ($tid === null || $tid < 1) {
            return [];
        }
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);
        $flag = $renglon;
        $sql = "
            SELECT m.*, u.nombre AS usuario_nombre, u.email
            FROM movimiento_torneo m
            LEFT JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.torneo_id = ? AND m.{$clubCol} = ? AND m.{$flag} > 0
            ORDER BY m.id DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$tid, $clubId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['nota_display'] = FvdMovimientoTorneoHelper::notaHumanaGrupo((string) ($r['grupo_nombre'] ?? ''));
            if ($renglon === 'traspaso') {
                $dest = FvdMovimientoTorneoHelper::parsearDestinoClubDesdeGrupo((string) ($r['grupo_nombre'] ?? ''));
                if ($dest > 0 && empty($r['club_destino_nombre'])) {
                    $stD = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
                    $stD->execute([$dest]);
                    $r['club_destino_nombre'] = (string) ($stD->fetchColumn() ?: '');
                }
            }
        }
        unset($r);

        return $rows;
    }
}
