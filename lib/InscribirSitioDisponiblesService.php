<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/AsociacionAdminHelper.php';

/**
 * Listado de atletas no inscritos activamente en el torneo (incluye retirados).
 */
final class InscribirSitioDisponiblesService
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function listar(
        PDO $pdo,
        int $torneoId,
        int $clubId,
        string $q = ''
    ): array {
        if ($torneoId <= 0 || $clubId <= 0) {
            return ['items' => [], 'total' => 0];
        }

        $q = trim($q);
        if ($q === '') {
            return ['items' => [], 'total' => 0];
        }

        [$sqlClub, $paramsClub] = AsociacionAdminHelper::filtroSqlUsuariosPorClub($clubId, 'u');
        $sqlActivo = InscritosHelper::sqlWhereActivoConAlias('i');

        $sql = "
            SELECT
                u.id,
                u.username,
                u.nombre,
                u.cedula,
                COALESCE(NULLIF(c.id, 0), NULLIF(u.club_id, 0), NULLIF(u.entidad, 0)) AS club_id,
                c.nombre AS club_nombre
            FROM usuarios u
            LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(u.club_id, 0), NULLIF(u.entidad, 0))
            LEFT JOIN inscritos i ON i.id_usuario = u.id AND i.torneo_id = ?
            WHERE u.role = 'usuario'
              AND u.status = 0
              {$sqlClub}
              AND (i.id IS NULL OR NOT ({$sqlActivo}))
        ";

        $params = array_merge([$torneoId], $paramsClub);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $digits = preg_replace('/\D/', '', $q);
        if ($digits !== '' && ctype_digit($digits)) {
            $sql .= ' AND (u.nombre LIKE ? OR u.username LIKE ? OR u.cedula LIKE ? OR CAST(u.id AS CHAR) = ? OR u.cedula = ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $digits;
            $params[] = $digits;
        } else {
            $sql .= ' AND (u.nombre LIKE ? OR u.username LIKE ? OR u.cedula LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY COALESCE(u.nombre, u.username) ASC LIMIT 50';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $nom = trim((string) ($row['nombre'] ?? ''));
            if ($nom === '') {
                $nom = (string) ($row['username'] ?? '');
            }
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => $nom,
                'cedula' => (string) ($row['cedula'] ?? ''),
                'club_id' => (int) ($row['club_id'] ?? 0),
                'club_nombre' => (string) ($row['club_nombre'] ?? ''),
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }
}
