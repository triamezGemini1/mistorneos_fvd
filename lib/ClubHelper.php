<?php
/**
 * Helper para gestión de clubes
 * Relación: clubes.admin_club_id = usuarios.id (admin_club)
 */

if (!defined('APP_BOOTSTRAPPED')) { 
    require_once __DIR__ . '/../config/bootstrap.php'; 
}
require_once __DIR__ . '/../config/db.php';

class ClubHelper {
    private static ?bool $hasCodOrgColumn = null;

    /** Condición SQL: club activo (acepta 1, '1' o 'activo' en producción). */
    public static function sqlWhereClubActivo(string $alias = ''): string
    {
        $p = ($alias !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) ? $alias . '.' : '';

        return "({$p}estatus = 1 OR {$p}estatus = '1' OR {$p}estatus = 'activo')";
    }

    /**
     * Etiqueta de asociación (código + nombre canónico FVD).
     */
    public static function etiquetaAsociacion(int $clubId, ?string $nombreDb = null): string
    {
        require_once __DIR__ . '/EntidadFvdCatalogo.php';

        return EntidadFvdCatalogo::etiqueta($clubId, $nombreDb);
    }

    /**
     * Nombre canónico de asociación para listados y persistencia.
     */
    public static function nombreAsociacionCanonico(int $clubId, ?string $nombreDb = null): string
    {
        require_once __DIR__ . '/EntidadFvdCatalogo.php';

        return EntidadFvdCatalogo::normalizarNombre($clubId, $nombreDb);
    }

    /**
     * @return list<array{id: int, nombre: string, entidad?: int}>
     */
    public static function listarClubesActivos(PDO $pdo, string $extraWhere = '', string $orderBy = 'nombre ASC'): array
    {
        $where = self::sqlWhereClubActivo();
        if ($extraWhere !== '') {
            $where = '(' . $where . ') AND (' . $extraWhere . ')';
        }
        $orderBy = preg_match('/^[a-zA-Z0-9_,.`\s]+$/', $orderBy) ? $orderBy : 'nombre ASC';
        try {
            $hasEntidad = (bool) $pdo->query("SHOW COLUMNS FROM clubes LIKE 'entidad'")->fetch(PDO::FETCH_ASSOC);
            $cols = $hasEntidad ? 'id, nombre, entidad' : 'id, nombre';
            $stmt = $pdo->query("SELECT {$cols} FROM clubes WHERE {$where} ORDER BY {$orderBy}");

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $row['nombre'] = self::nombreAsociacionCanonico($id, (string) ($row['nombre'] ?? ''));
                }
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('ClubHelper::listarClubesActivos: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Afiliados de un club: misma condición que en SQL manual `WHERE entidad = <id del club>`.
     * `usuarios.entidad` = PK `clubes.id`. Sin CAST, sin club_id/id_club, sin filtro de rol aquí.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function afiliadosMatchSqlAndParams(PDO $pdo, array $clubRow, int $clubId): array
    {
        return [
            '(u.entidad = ?)',
            [(int) $clubId],
        ];
    }

    /**
     * JOIN para conteos en listados de clubes (alias c = clubes).
     */
    public static function sqlJoinUsuariosAfiliadosOnClub(PDO $pdo, string $clubAlias = 'c'): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $clubAlias)) {
            $clubAlias = 'c';
        }
        $c = $clubAlias;

        return "u.entidad = {$c}.id";
    }

    private static function hasCodOrg(): bool
    {
        if (self::$hasCodOrgColumn !== null) {
            return self::$hasCodOrgColumn;
        }
        try {
            self::$hasCodOrgColumn = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$hasCodOrgColumn = false;
        }
        return self::$hasCodOrgColumn;
    }
    
    /**
     * IDs de clubes bajo el alcance de una federación (código canónico vía OrganizacionDashboardStats).
     * Uso: listados, filtros, permisos por organización. No sustituye a cargar un club por PK.
     *
     * @param int $organizacion_id Preferir siempre la PK `organizaciones.id`. Si no hay fila, y existe `cod_org`,
     *                             se intenta una segunda búsqueda solo por `cod_org` (nunca `id = ? OR cod_org = ?`
     *                             con el mismo parámetro: colisión cuando el código federación coincide con la PK de otra org).
     * @return int[] IDs primarios en `clubes.id` (no son valores de cod_org del club)
     */
    public static function getClubesByOrganizacionId(int $organizacion_id): array {
        if ($organizacion_id <= 0) {
            return [];
        }
        try {
            $pdo = DB::pdo();
            $hasCod = self::hasCodOrg();
            $stmt = $pdo->prepare('SELECT * FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
            $stmt->execute([$organizacion_id]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$org && $hasCod) {
                $stmt = $pdo->prepare('SELECT * FROM organizaciones WHERE cod_org = ? AND estatus = 1 ORDER BY id ASC LIMIT 1');
                $stmt->execute([$organizacion_id]);
                $org = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$org) {
                return [];
            }
            require_once __DIR__ . '/OrganizacionDashboardStats.php';

            return OrganizacionDashboardStats::clubIdsForOrganizacion($pdo, $org, $hasCod);
        } catch (Exception $e) {
            error_log("ClubHelper::getClubesByOrganizacionId error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * IDs de clubes gestionados por el admin (lista de PKs `clubes.id`).
     * Para edición/detalle de un club concreto desde UI, seguir usando siempre ese id; no mapear a cod_org.
     *
     * @param int $admin_club_user_id ID del usuario admin_club (usuarios.id)
     * @return int[] Lista de `clubes.id`
     */
    public static function getClubesByAdminClubId(int $admin_club_user_id): array {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 LIMIT 1");
            $stmt->execute([$admin_club_user_id]);
            $org_id = $stmt->fetchColumn();
            if ($org_id) {
                return self::getClubesByOrganizacionId((int)$org_id);
            }
            // Legacy: sin organización, por admin_club_id directo en clubes
            $stmt = $pdo->prepare("SELECT id FROM clubes WHERE admin_club_id = ? AND estatus = 1");
            $stmt->execute([$admin_club_user_id]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_map('intval', $ids ?: []));
        } catch (Exception $e) {
            error_log("ClubHelper::getClubesByAdminClubId error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene todos los clubes que gestiona un admin_club (por organización o admin_club_id)
     * La tabla clubes_asociados ya no se usa; se usa cod_org en clubes.
     *
     * @param int $club_id Club asignado al admin (usuarios.club_id) - para compatibilidad
     * @return array Lista de IDs de clubes
     */
    public static function getClubesSupervised(int $club_id): array {
        try {
            $pdo = DB::pdo();
            $admin_user_id = null;
            $stmt = $pdo->prepare("SELECT admin_club_id FROM clubes WHERE id = ?");
            $stmt->execute([$club_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['admin_club_id'])) {
                $admin_user_id = (int)$row['admin_club_id'];
            } else {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE club_id = ? AND role = 'admin_club' LIMIT 1");
                $stmt->execute([$club_id]);
                $admin_user_id = (int)$stmt->fetchColumn();
            }
            if ($admin_user_id > 0) {
                return self::getClubesByAdminClubId($admin_user_id);
            }
            return [$club_id];
        } catch (Exception $e) {
            error_log("ClubHelper::getClubesSupervised error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene los clubes con sus datos completos
     * 
     * @param int $club_id Club principal
     * @return array Lista de clubes con datos
     */
    public static function getClubesSupervisedWithData(int $club_id): array {
        $club_ids = self::getClubesSupervised($club_id);
        
        if (empty($club_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
        
        try {
            $stmt = DB::pdo()->prepare("
                SELECT id, nombre, delegado, telefono, estatus, entidad,
                       CASE WHEN id = ? THEN 1 ELSE 0 END as es_principal
                FROM clubes 
                WHERE id IN ($placeholders) AND estatus = 1
                ORDER BY es_principal DESC, nombre ASC
            ");
            $params = array_merge([$club_id], $club_ids);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("ClubHelper::getClubesSupervisedWithData error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verifica si un club es gestionado por un admin_club (admin organización)
     * Por organización: clubes.cod_org = código canónico de la federación del admin; o legacy admin_club_id.
     *
     * @param int $admin_club_user_id ID del usuario admin_club
     * @param int $club_id Club a verificar
     * @return bool
     */
    public static function isClubManagedByAdmin(int $admin_club_user_id, int $club_id): bool {
        try {
            $pdo = DB::pdo();
            $club_fed = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(cod_org, 0), NULLIF(entidad, 0)) FROM clubes WHERE id = ? LIMIT 1");
                $stmt->execute([$club_id]);
                $club_fed = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("SELECT cod_org FROM clubes WHERE id = ? LIMIT 1");
                $stmt->execute([$club_id]);
                $club_fed = (int) $stmt->fetchColumn();
            }
            if ($club_fed > 0) {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM organizaciones o
                    WHERE o.admin_user_id = ? AND o.estatus = 1
                      AND COALESCE(NULLIF(o.cod_org, 0), NULLIF(o.entidad, 0)) = ?
                    LIMIT 1
                ");
                $stmt->execute([$admin_club_user_id, $club_fed]);
                if ($stmt->fetch()) {
                    return true;
                }
            }
            $stmt = $pdo->prepare("SELECT 1 FROM clubes WHERE id = ? AND admin_club_id = ?");
            $stmt->execute([$club_id, $admin_club_user_id]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Verifica si un club está bajo supervisión (compatibilidad)
     * 
     * @param int $club_principal_id Club del admin (usuarios.club_id)
     * @param int $club_id Club a verificar
     * @return bool
     */
    public static function isClubSupervised(int $club_principal_id, int $club_id): bool {
        $clubes = self::getClubesSupervised($club_principal_id);
        return in_array($club_id, $clubes);
    }
    
    /**
     * Asociar club ya no se usa (tabla clubes_asociados eliminada).
     * Los clubes se agrupan por cod_org en clubes.
     */
    public static function asociarClub(int $club_principal_id, int $club_asociado_id): bool {
        return false;
    }

    /**
     * Desasociar club ya no se usa (tabla clubes_asociados eliminada).
     */
    public static function desasociarClub(int $club_principal_id, int $club_asociado_id): bool {
        return false;
    }

    /**
     * Clubes de la misma organización (excluyendo el dado).
     * Sustituye la antigua "disponibles para asociar" sin usar clubes_asociados.
     */
    public static function getClubesDisponibles(int $club_principal_id): array {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT c.id, c.nombre, c.delegado
                FROM clubes c
                INNER JOIN clubes c2 ON c.cod_org = c2.cod_org AND c2.id = ?
                WHERE c.id != ? AND c.estatus = 1 AND c.cod_org IS NOT NULL
                ORDER BY c.nombre ASC
            ");
            $stmt->execute([$club_principal_id, $club_principal_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("ClubHelper::getClubesDisponibles error: " . $e->getMessage());
            return [];
        }
    }
}


