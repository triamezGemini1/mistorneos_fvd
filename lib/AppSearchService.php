<?php

declare(strict_types=1);

require_once __DIR__ . '/AsociacionAdminHelper.php';

/**
 * Búsquedas centralizadas (mínimo 3 caracteres activos).
 */
final class AppSearchService
{
    public const MIN_QUERY_LENGTH = 3;

    public static function queryLength(string $q): int
    {
        $q = trim($q);

        return function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
    }

    public static function isQueryActive(string $q): bool
    {
        return self::queryLength($q) >= self::MIN_QUERY_LENGTH;
    }

    /** @return array{ok: false, message: string}|array{ok: true} */
    public static function validateActiveQuery(string $q): array
    {
        if (!self::isQueryActive($q)) {
            return [
                'ok' => false,
                'message' => 'Escriba al menos ' . self::MIN_QUERY_LENGTH . ' caracteres para buscar.',
            ];
        }

        return ['ok' => true];
    }

    private static function likePattern(string $q): string
    {
        return '%' . addcslashes(trim($q), '%_\\') . '%';
    }

    /**
     * Búsqueda global del panel (clubes, torneos, usuarios).
     *
     * @return list<array{type: string, icon: string, title: string, subtitle: string, url: string, badge: string}>
     */
    public static function globalSearch(PDO $pdo, string $q, int $limit = 12): array
    {
        if (!self::isQueryActive($q)) {
            return [];
        }
        $like = self::likePattern($q);
        $results = [];
        $perType = max(3, (int) ceil($limit / 3));

        $stmt = $pdo->prepare(
            'SELECT id, nombre, delegado FROM clubes
             WHERE estatus = 1 AND (nombre LIKE ? OR delegado LIKE ? OR telefono LIKE ?)
             ORDER BY nombre ASC LIMIT ' . (int) $perType
        );
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'type' => 'club',
                'icon' => 'fas fa-building',
                'title' => (string) ($row['nombre'] ?? ''),
                'subtitle' => (string) ($row['delegado'] ?? ''),
                'url' => 'index.php?page=clubs&action=edit&id=' . (int) ($row['id'] ?? 0),
                'badge' => 'Club',
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT t.id, t.nombre, o.nombre AS org_nombre
             FROM tournaments t
             LEFT JOIN organizaciones o ON t.club_responsable = o.id
             WHERE t.estatus = 1 AND t.nombre LIKE ?
             ORDER BY t.fechator DESC LIMIT ' . (int) $perType
        );
        $stmt->execute([$like]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'type' => 'tournament',
                'icon' => 'fas fa-trophy',
                'title' => (string) ($row['nombre'] ?? ''),
                'subtitle' => 'Org: ' . (string) ($row['org_nombre'] ?? 'N/A'),
                'url' => 'index.php?page=tournaments&action=edit&id=' . (int) ($row['id'] ?? 0),
                'badge' => 'Torneo',
            ];
        }

        [$sqlAmbito, $paramsAmbito] = AsociacionAdminHelper::filtroSqlUsuariosAsociacion($pdo, 'u');
        $stmt = $pdo->prepare(
            'SELECT u.id, u.nombre, u.username, u.cedula
             FROM usuarios u
             WHERE (u.nombre LIKE ? OR u.username LIKE ? OR u.cedula LIKE ?)
             ' . $sqlAmbito . '
             ORDER BY u.nombre ASC LIMIT ' . (int) $perType
        );
        $stmt->execute(array_merge([$like, $like, $like], $paramsAmbito));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'type' => 'user',
                'icon' => 'fas fa-user',
                'title' => (string) ($row['nombre'] ?? $row['username'] ?? ''),
                'subtitle' => 'ID ' . (int) ($row['id'] ?? 0) . ' · ' . (string) ($row['cedula'] ?? ''),
                'url' => 'index.php?page=users&action=edit&id=' . (int) ($row['id'] ?? 0),
                'badge' => 'Usuario',
            ];
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Lista de usuarios para autocompletado (nombre, usuario, cédula).
     *
     * @return list<array<string, mixed>>
     */
    public static function searchUsuarios(PDO $pdo, string $q, int $limit = 15, ?int $clubId = null): array
    {
        if (!self::isQueryActive($q)) {
            return [];
        }
        $like = self::likePattern($q);
        [$sqlAmbito, $paramsAmbito] = AsociacionAdminHelper::filtroSqlUsuariosAsociacion($pdo, 'u');
        $sql = 'SELECT u.id, u.username, u.nombre, u.cedula, u.celular, u.club_id, u.entidad
                FROM usuarios u
                WHERE (u.nombre LIKE ? OR u.username LIKE ? OR u.cedula LIKE ?)
                ' . $sqlAmbito;
        $params = array_merge([$like, $like, $like], $paramsAmbito);
        if ($clubId !== null && $clubId > 0) {
            $sql .= ' AND (u.club_id = ? OR u.club_id IS NULL)';
            $params[] = $clubId;
        }
        $sql .= ' ORDER BY u.nombre ASC LIMIT ' . (int) max(1, min(30, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
