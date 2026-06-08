<?php

declare(strict_types=1);

/**
 * OrganizacionesData - Estadísticas de entidades y organizaciones para el dashboard admin_general.
 * Separa la lógica SQL de las vistas. Usado por el home simplificado (solo tarjetas).
 */
class OrganizacionesData
{
    private static function fvdOrganizacionId(): int
    {
        if (defined('ORGANIZACION_ID')) {
            return (int) ORGANIZACION_ID;
        }
        require_once __DIR__ . '/FvdConfig.php';

        return FvdConfig::ORGANIZACION_ID;
    }

    /**
     * Fila de organización FVD (ORGANIZACION_ID) para alcance de consultas globales.
     *
     * @return array<string, mixed>
     */
    private static function fvdOrganizacionRow(PDO $pdo): array
    {
        require_once __DIR__ . '/FvdConfig.php';
        FvdConfig::warmOrganizacionMaestra();
        $org = FvdConfig::getOrganizacionMaestra();
        $orgId = self::fvdOrganizacionId();
        if (!is_array($org) || (int) ($org['id'] ?? 0) <= 0) {
            $org = ['id' => $orgId, 'cod_org' => $orgId, 'entidad' => 0, 'estatus' => 1];
        }
        $org['id'] = $orgId;
        if (empty($org['cod_org'])) {
            $org['cod_org'] = $orgId;
        }
        if (!array_key_exists('entidad', $org)) {
            try {
                $stmt = $pdo->prepare('SELECT entidad FROM organizaciones WHERE id = ? LIMIT 1');
                $stmt->execute([$orgId]);
                $ent = $stmt->fetchColumn();
                $org['entidad'] = $ent !== false ? (int) $ent : 0;
            } catch (Exception $e) {
                $org['entidad'] = 0;
            }
        }

        return $org;
    }

    /**
     * WHERE + params: clubes activos del ámbito FVD (alias c por defecto).
     *
     * @return array{where: string, params: list<mixed>}
     */
    public static function sqlClubesAmbitoFvd(PDO $pdo, string $alias = 'c'): array
    {
        require_once __DIR__ . '/OrganizacionDashboardStats.php';
        $org = self::fvdOrganizacionRow($pdo);
        [$clubWhere, $clubParams] = OrganizacionDashboardStats::clubScopeWhereForOrganizacion($pdo, $org);
        if ($alias !== 'c' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $clubWhere = str_replace('c.', $alias . '.', $clubWhere);
        }

        return ['where' => $clubWhere, 'params' => $clubParams];
    }

    /**
     * Condición sobre usuarios (alias u) limitada al ámbito institucional FVD.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function sqlUsuarioAmbitoFvd(PDO $pdo, string $alias = 'u'): array
    {
        require_once __DIR__ . '/FvdConfig.php';
        require_once __DIR__ . '/OrganizacionDashboardStats.php';
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 'u';
        }
        $org = self::fvdOrganizacionRow($pdo);
        $orgEnt = FvdConfig::entidadTerritorioEfectivaOrganizacion($org);
        [$clubWhere, $clubParams] = OrganizacionDashboardStats::clubScopeWhereForOrganizacion($pdo, $org);
        $terrExpr = OrganizacionDashboardStats::usuarioTerritorioCoalesceExpr($pdo, $alias);
        $sql = "(
            (? > 0 AND {$terrExpr} = ?)
            OR EXISTS (
                SELECT 1 FROM clubes c
                WHERE c.id = {$alias}.club_id
                  AND ({$clubWhere})
            )
        )";

        return [$sql, [$orgEnt, $orgEnt, ...$clubParams]];
    }

    private static function hasCodOrg(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }
    /**
     * Conteo de atletas (role=usuario) activos/inactivos y por género.
     * Si $entidadId > 0, solo usuarios de esa asociación (usuarios.entidad).
     *
     * @return array<string, int>
     */
    public static function loadAtletasStats(?int $entidadId = null): array
    {
        $stats = [
            'atletas_activos' => 0,
            'atletas_inactivos' => 0,
            'hombres_activos' => 0,
            'mujeres_activos' => 0,
            'hombres_inactivos' => 0,
            'mujeres_inactivos' => 0,
        ];

        try {
            $pdo = DB::pdo();
            $where = "u.role = 'usuario'";
            $params = [];
            if ($entidadId !== null && $entidadId > 0) {
                $where .= ' AND u.entidad = ?';
                $params[] = $entidadId;
            } else {
                [$fvdScope, $fvdParams] = self::sqlUsuarioAmbitoFvd($pdo);
                $where .= " AND {$fvdScope}";
                $params = array_merge($params, $fvdParams);
            }

            $sql = "
                SELECT
                    SUM(CASE WHEN u.status = 0 THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN u.status <> 0 THEN 1 ELSE 0 END) AS inactivos,
                    SUM(CASE WHEN u.status = 0 AND (u.sexo = 'M' OR UPPER(COALESCE(u.sexo,'')) = 'M') THEN 1 ELSE 0 END) AS hombres_activos,
                    SUM(CASE WHEN u.status = 0 AND (u.sexo = 'F' OR UPPER(COALESCE(u.sexo,'')) = 'F') THEN 1 ELSE 0 END) AS mujeres_activos,
                    SUM(CASE WHEN u.status <> 0 AND (u.sexo = 'M' OR UPPER(COALESCE(u.sexo,'')) = 'M') THEN 1 ELSE 0 END) AS hombres_inactivos,
                    SUM(CASE WHEN u.status <> 0 AND (u.sexo = 'F' OR UPPER(COALESCE(u.sexo,'')) = 'F') THEN 1 ELSE 0 END) AS mujeres_inactivos
                FROM usuarios u
                WHERE {$where}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $stats['atletas_activos'] = (int)($row['activos'] ?? 0);
            $stats['atletas_inactivos'] = (int)($row['inactivos'] ?? 0);
            $stats['hombres_activos'] = (int)($row['hombres_activos'] ?? 0);
            $stats['mujeres_activos'] = (int)($row['mujeres_activos'] ?? 0);
            $stats['hombres_inactivos'] = (int)($row['hombres_inactivos'] ?? 0);
            $stats['mujeres_inactivos'] = (int)($row['mujeres_inactivos'] ?? 0);
        } catch (Exception $e) {
            error_log('OrganizacionesData loadAtletasStats: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Estadísticas globales para el dashboard admin_general (solo tarjetas).
     *
     * @return array<string, int>
     */
    public static function loadStatsGlobales(): array
    {
        $stats = [
            'total_entidades' => 0,
            'total_organizaciones' => 0,
            'total_users' => 0,
            'total_admin_clubs' => 0,
            'total_admin_torneo' => 0,
            'total_operadores' => 0,
        ];

        try {
            $pdo = DB::pdo();

            try {
                $stmt = $pdo->query('SELECT COUNT(*) FROM entidad');
                $stats['total_entidades'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['total_entidades'] = 0;
            }

            $stats['total_organizaciones'] = 1;

            [$uScope, $uScopeParams] = self::sqlUsuarioAmbitoFvd($pdo);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE {$uScope}");
            $stmt->execute($uScopeParams);
            $stats['total_users'] = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'admin_club' AND u.status = 0 AND {$uScope}");
            $stmt->execute($uScopeParams);
            $stats['total_admin_clubs'] = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'admin_torneo' AND u.status = 0 AND {$uScope}");
            $stmt->execute($uScopeParams);
            $stats['total_admin_torneo'] = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'operador' AND u.status = 0 AND {$uScope}");
            $stmt->execute($uScopeParams);
            $stats['total_operadores'] = (int) $stmt->fetchColumn();

            require_once __DIR__ . '/StatisticsHelper.php';
            $helperStats = StatisticsHelper::generateStatistics();
            if (!isset($helperStats['error'])) {
                $stats['total_admin_clubs'] = (int)($helperStats['total_admin_clubs'] ?? $stats['total_admin_clubs']);
                $stats['total_admin_torneo'] = (int)($helperStats['total_admin_torneo'] ?? $stats['total_admin_torneo']);
                $stats['total_operadores'] = (int)($helperStats['total_operadores'] ?? $stats['total_operadores']);
            }
        } catch (Exception $e) {
            error_log('OrganizacionesData loadStatsGlobales: ' . $e->getMessage());
        }

        return array_merge($stats, self::loadAtletasStats(null));
    }

    /**
     * Contadores para el panel operativo del admin general (badges en SUPERVISIÓN).
     *
     * @return array{solicitudes_afiliacion_total: int, solicitudes_afiliacion_pendiente: int, comentarios_pendientes: int}
     */
    public static function loadAdminGeneralPanelBadges(): array
    {
        $out = [
            'solicitudes_afiliacion_total' => 0,
            'solicitudes_afiliacion_pendiente' => 0,
            'comentarios_pendientes' => 0,
        ];
        try {
            $pdo = DB::pdo();
            $chk = $pdo->query("SHOW TABLES LIKE 'solicitudes_afiliacion'");
            if ($chk && $chk->rowCount() > 0) {
                $out['solicitudes_afiliacion_total'] = (int) $pdo->query('SELECT COUNT(*) FROM solicitudes_afiliacion')->fetchColumn();
                $out['solicitudes_afiliacion_pendiente'] = (int) $pdo->query("SELECT COUNT(*) FROM solicitudes_afiliacion WHERE estatus = 'pendiente'")->fetchColumn();
            }
            $chk2 = $pdo->query("SHOW TABLES LIKE 'comentariossugerencias'");
            if ($chk2 && $chk2->rowCount() > 0) {
                $out['comentarios_pendientes'] = (int) $pdo->query("SELECT COUNT(*) FROM comentariossugerencias WHERE estatus = 'pendiente'")->fetchColumn();
            }
        } catch (Throwable $e) {
            error_log('OrganizacionesData::loadAdminGeneralPanelBadges: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Mapa id/codigo => nombre de entidades (para selects y listados).
     *
     * @return array<int|string, string>
     */
    public static function loadEntidadMap(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $map = [];
        try {
            $stmt = DB::pdo()->query("SELECT id AS codigo, nombre FROM entidad ORDER BY nombre ASC");
            $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            try {
                $stmt = DB::pdo()->query("SELECT codigo, nombre FROM entidad ORDER BY nombre ASC");
                $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e2) {
                error_log("OrganizacionesData loadEntidadMap: " . $e->getMessage());
            }
        }
        $cached = $map;
        return $map;
    }

    /**
     * Listado de asociaciones (entidades) con atletas activos/inactivos por género.
     *
     * @return list<array<string, mixed>>
     */
    public static function loadResumenEntidades(): array
    {
        require_once __DIR__ . '/EntidadFvdCatalogo.php';
        try {
            $pdo = DB::pdo();
        } catch (Exception $e) {
            return [];
        }

        $resumen = [];
        foreach (self::loadEntidadRows($pdo) as $e) {
            $cod = (int)($e['codigo'] ?? 0);
            if ($cod <= 0) {
                continue;
            }
            $atletas = self::loadAtletasStats($cod);
            $resumen[] = array_merge([
                'entidad_id' => $cod,
                'entidad_codigo' => $cod,
                'entidad_nombre' => EntidadFvdCatalogo::normalizarNombre($cod, (string)($e['nombre'] ?? '')),
                'estado' => (int)($e['estado'] ?? 1),
            ], $atletas);
        }
        return $resumen;
    }

    /**
     * @return list<array{codigo: int|string, nombre: string, estado?: int}>
     */
    private static function loadEntidadRows(PDO $pdo): array
    {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM entidad')->fetchAll(PDO::FETCH_ASSOC);
            $codeCol = $nameCol = $stateCol = null;
            foreach ($cols as $c) {
                $f = strtolower($c['Field'] ?? '');
                if (!$codeCol && in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) {
                    $codeCol = $c['Field'];
                }
                if (!$nameCol && in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) {
                    $nameCol = $c['Field'];
                }
                if (!$stateCol && in_array($f, ['estado', 'estatus', 'status', 'activo'], true)) {
                    $stateCol = $c['Field'];
                }
            }
            if ($codeCol && $nameCol) {
                $select = "{$codeCol} AS codigo, {$nameCol} AS nombre";
                if ($stateCol) {
                    $select .= ", {$stateCol} AS estado";
                }
                $stmt = $pdo->query("SELECT {$select} FROM entidad ORDER BY {$codeCol} ASC");
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Exception $e) {
        }
        return [];
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }
}
