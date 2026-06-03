<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/FinanzasAsociacionData.php';

/**
 * Reportes delegado: afiliaciones, carnets, traspasos (admin_fvd DelegadoMovimientoTorneo).
 */
final class FvdDelegadoReporteService
{
    /**
     * Afiliados de la asociación con movimiento en el torneo (base carnet/traspaso).
     *
     * @return list<array<string, mixed>>
     */
    public static function reporteAfiliadosConMovimiento(PDO $pdo, int $clubId, int $torneoId): array
    {
        if ($clubId < 1 || $torneoId < 1 || !FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {
            return [];
        }
        $sql = 'SELECT u.id AS user_id, u.cedula, u.nombre, u.numfvd, u.sexo, u.email, u.status AS usuario_status,
            m.id AS movimiento_id, m.afiliacion, m.anualidad, m.carnet, m.traspaso, m.inscripcion,
            m.grupo_nombre, m.created_at AS movimiento_created_at, m.updated_at AS movimiento_updated_at
            FROM usuarios u
            LEFT JOIN movimiento_torneo m ON m.id_usuario = u.id AND m.torneo_id = ?
            WHERE u.club_id = ?
            ORDER BY u.nombre ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $clubId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Solo filas con afiliación = 1 en movimiento_torneo (reporte afiliaciones).
     *
     * @return list<array<string, mixed>>
     */
    public static function reporteAfiliacionesDesdeMovimiento(PDO $pdo, int $clubId, int $torneoId): array
    {
        if ($clubId < 1 || $torneoId < 1 || !FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {
            return [];
        }
        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);
        $sql = "SELECT u.id AS user_id, u.cedula, u.nombre, u.numfvd, u.sexo, u.email, u.status AS usuario_status,
            m.id AS movimiento_id, m.afiliacion, m.anualidad, m.carnet, m.traspaso, m.inscripcion,
            m.grupo_nombre, m.created_at AS movimiento_created_at, m.updated_at AS movimiento_updated_at
            FROM movimiento_torneo m
            INNER JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.torneo_id = ? AND m.{$clubCol} = ? AND m.afiliacion = 1
            ORDER BY (CASE WHEN COALESCE(u.numfvd, 0) < 1 AND COALESCE(m.numfvd, 0) < 1 THEN 0 ELSE 1 END) ASC,
                m.updated_at DESC, u.nombre ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $clubId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array{id:int, nombre:string}>
     */
    public static function listarOtrosClubes(PDO $pdo, int $clubOrigenId): array
    {
        $st = $pdo->prepare('SELECT id, nombre FROM clubes WHERE estatus = 1 AND id <> ? ORDER BY nombre');
        $st->execute([$clubOrigenId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function filaPasaBusqueda(array $row, string $q): bool
    {
        $q = trim($q);
        if ($q === '') {
            return true;
        }
        $ql = mb_strtolower($q);
        $ced = mb_strtolower(str_replace(' ', '', (string) ($row['cedula'] ?? '')));
        $nom = mb_strtolower((string) ($row['nombre'] ?? ''));
        $nf = (string) ($row['numfvd'] ?? '');
        if (str_contains($ced, str_replace(' ', '', $ql)) || str_contains($nom, $ql)) {
            return true;
        }
        if ($nf !== '' && str_contains(mb_strtolower($nf), $ql)) {
            return true;
        }

        return false;
    }

    public static function filaCarnetSolicitud(array $row, string $filtro): bool
    {
        if ($filtro !== 'solicitados') {
            return true;
        }
        $ca = (int) ($row['carnet'] ?? 0);
        $af = (int) ($row['afiliacion'] ?? 0);
        $tp = (int) ($row['traspaso'] ?? 0);
        $nfM = (int) ($row['numfvd'] ?? 0);
        $afiliPendiente = $af === 1 && $nfM < 1;

        return $ca >= 1 && !$afiliPendiente && $tp !== 1;
    }

    public static function filaTraspasoSolicitud(array $row, string $filtro): bool
    {
        if ($filtro !== 'solicitados') {
            return true;
        }

        return (int) ($row['traspaso'] ?? 0) >= 1;
    }

    public static function badgesMovimientoHtml(array $row): string
    {
        $bits = [];
        if ((int) ($row['afiliacion'] ?? 0) >= 1) {
            $bits[] = '<span class="ins-badge">AF</span>';
        }
        if ((int) ($row['anualidad'] ?? 0) >= 1) {
            $bits[] = '<span class="ins-badge">AN</span>';
        }
        if ((int) ($row['carnet'] ?? 0) >= 1) {
            $bits[] = '<span class="ins-badge">CA</span>';
        }
        if ((int) ($row['traspaso'] ?? 0) >= 1) {
            $bits[] = '<span class="ins-badge ins-badge--tp">TP</span>';
        }

        return $bits !== [] ? implode(' ', $bits) : '<span class="text-muted">—</span>';
    }

    public static function badgeAltaFederacion(array $row): string
    {
        $nf = (int) ($row['numfvd'] ?? 0);
        if ($nf > 0) {
            return '<span class="badge bg-success">Nº FVD ' . $nf . '</span>';
        }

        return '<span class="badge bg-warning text-dark">Pendiente FVD</span>';
    }

    public static function sexoLabel($sexo): string
    {
        $s = (int) $sexo;

        return $s === 1 ? 'M' : ($s === 2 ? 'F' : '—');
    }
}
