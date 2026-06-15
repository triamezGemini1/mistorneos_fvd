<?php

declare(strict_types=1);

require_once __DIR__ . '/ClubHelper.php';
require_once __DIR__ . '/app_helpers.php';

/**
 * Asociaciones activas para la landing pública y ficha de detalle.
 */
final class AsociacionesActivasLandingService
{
    private PDO $pdo;

    public static function landingAsociacionesUrl(): string
    {
        return rtrim(AppHelpers::url('landing-spa.php', ['section' => 'asociaciones-activas']), '/')
            . '#asociaciones-activas';
    }

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return list<array<string,mixed>> */
    public function listarParaLanding(): array
    {
        $rows = $this->fetchRows(null);

        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return array<string,mixed>|null */
    public function obtenerDetallePublico(int $clubId): ?array
    {
        if ($clubId <= 0) {
            return null;
        }

        $rows = $this->fetchRows($clubId);

        return isset($rows[0]) ? $this->mapRow($rows[0]) : null;
    }

    /** @return list<array<string,mixed>> */
    private function fetchRows(?int $clubId): array
    {
        $whereActivo = ClubHelper::sqlWhereClubActivo('c');
        $joinAfiliados = ClubHelper::sqlJoinUsuariosAfiliadosOnClub($this->pdo, 'c');
        $params = [];
        $filtroId = '';
        if ($clubId !== null && $clubId > 0) {
            $filtroId = ' AND c.id = ?';
            $params[] = $clubId;
        }

        $hasEntidad = $this->columnExists('clubes', 'entidad');
        $groupEntidad = $hasEntidad ? ', c.entidad' : '';

        $sql = "
            SELECT
                c.id,
                c.nombre,
                c.logo,
                c.delegado,
                c.telefono,
                c.email,
                c.direccion
                {$groupEntidad},
                COUNT(u.id) AS total_afiliados,
                SUM(CASE WHEN u.status = 0 THEN 1 ELSE 0 END) AS afiliados_activos,
                SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) AS mujeres
            FROM clubes c
            LEFT JOIN usuarios u ON {$joinAfiliados}
            WHERE {$whereActivo}{$filtroId}
            GROUP BY c.id, c.nombre, c.logo, c.delegado, c.telefono, c.email, c.direccion{$groupEntidad}
            ORDER BY c.nombre ASC
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('AsociacionesActivasLandingService: ' . $e->getMessage());

            return [];
        }
    }

    /** @param array<string,mixed> $row */
    private function mapRow(array $row): array
    {
        $total = (int) ($row['total_afiliados'] ?? 0);
        $activos = (int) ($row['afiliados_activos'] ?? 0);
        $codigo = ClubHelper::codigoAsociacionDesdeClubRow($row);
        $nombre = ClubHelper::nombreAsociacionCanonico($codigo, (string) ($row['nombre'] ?? ''));
        $logo = AppHelpers::normalizeStoragePath((string) ($row['logo'] ?? ''));
        $clubId = (int) ($row['id'] ?? 0);
        $logoUrl = $logo !== '' ? AppHelpers::publicImageUrl($logo) : '';

        return [
            'id' => $clubId,
            'nombre' => $nombre,
            'representante' => trim((string) ($row['delegado'] ?? '')),
            'delegado' => trim((string) ($row['delegado'] ?? '')),
            'telefono' => trim((string) ($row['telefono'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'direccion' => trim((string) ($row['direccion'] ?? '')),
            'logo_path' => $logo !== '' ? $logo : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
            'total_afiliados' => $total,
            'afiliados_activos' => $activos,
            'afiliados_inactivos' => max(0, $total - $activos),
            'hombres' => (int) ($row['hombres'] ?? 0),
            'mujeres' => (int) ($row['mujeres'] ?? 0),
            'detalle_url' => AppHelpers::url('asociacion_detalle.php', ['id' => $clubId]),
            'asociaciones_url' => self::landingAsociacionesUrl(),
        ];
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
