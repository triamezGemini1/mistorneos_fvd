<?php

declare(strict_types=1);

/**
 * DashboardData - Carga de datos para el dashboard de administradores
 * Separa la lógica SQL de las vistas. Las vistas solo hacen foreach.
 */
class DashboardData
{
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
                error_log("DashboardData: no se pudo cargar entidades: " . $e->getMessage());
            }
        }
        $cached = $map;
        return $map;
    }

    public static function loadEntidadSummary(array $entidad_map): array
    {
        $data = [];
        try {
            $sqlSummary = "
                SELECT entidad,
                       SUM(CASE WHEN role = 'admin_club' AND status = 0 THEN 1 ELSE 0 END) AS admin_clubs,
                       SUM(CASE WHEN role = 'usuario' AND status = 0 AND (sexo = 'M' OR UPPER(sexo) = 'M') THEN 1 ELSE 0 END) AS hombres,
                       SUM(CASE WHEN role = 'usuario' AND status = 0 AND (sexo = 'F' OR UPPER(sexo) = 'F') THEN 1 ELSE 0 END) AS mujeres,
                       SUM(CASE WHEN role = 'usuario' AND status = 0 AND (sexo NOT IN('M','F') OR sexo IS NULL) THEN 1 ELSE 0 END) AS otros,
                       SUM(CASE WHEN role = 'usuario' AND status = 0 THEN 1 ELSE 0 END) AS total_afiliados
                FROM usuarios
                GROUP BY entidad
                ORDER BY entidad ASC
            ";
            $rows = DB::pdo()->query($sqlSummary)->fetchAll(PDO::FETCH_ASSOC);
            $sqlAdmins = "
                SELECT u.id as admin_id, u.username as admin_username, u.nombre as admin_nombre, u.entidad,
                       u.status, u.club_id, c.nombre as club_principal_nombre
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.role = 'admin_club' AND u.status = 0
            ";
            $adminsRaw = DB::pdo()->query($sqlAdmins)->fetchAll(PDO::FETCH_ASSOC);
            $adminsByEntidad = [];
            foreach ($adminsRaw as $a) {
                $ent = (int)($a['entidad'] ?? 0);
                $adminsByEntidad[$ent][] = $a;
            }
            $sqlClubes = "
                SELECT u.entidad, COUNT(DISTINCT c.id) as total_clubes
                FROM clubes c
                INNER JOIN usuarios u ON c.admin_club_id = u.id AND u.role = 'admin_club'
                WHERE u.entidad > 0
                GROUP BY u.entidad
            ";
            $clubesRaw = DB::pdo()->query($sqlClubes)->fetchAll(PDO::FETCH_ASSOC);
            $clubesByEntidad = [];
            foreach ($clubesRaw as $row) {
                $clubesByEntidad[(int)$row['entidad']] = (int)$row['total_clubes'];
            }
            foreach ($rows as $r) {
                $ent = (int)($r['entidad'] ?? 0);
                if ($ent <= 0) continue;
                $data[] = [
                    'entidad' => $ent,
                    'entidad_nombre' => $entidad_map[$ent] ?? ("Entidad " . $ent),
                    'admin_clubs' => (int)$r['admin_clubs'],
                    'total_clubes' => $clubesByEntidad[$ent] ?? 0,
                    'hombres' => (int)$r['hombres'],
                    'mujeres' => (int)$r['mujeres'],
                    'otros' => (int)$r['otros'],
                    'total_afiliados' => (int)$r['total_afiliados'],
                    'admins' => $adminsByEntidad[$ent] ?? []
                ];
            }
            usort($data, fn($a, $b) => strcmp($a['entidad_nombre'], $b['entidad_nombre']));
        } catch (Exception $e) {
            error_log("DashboardData loadEntidadSummary: " . $e->getMessage());
        }
        return $data;
    }

    /**
     * Carga todos los datos del dashboard según el rol del usuario.
     * Retorna array con: stats, torneos_list, torneos_linea_acl, torneos_linea_ag, etc.
     */
    public static function loadAll(string $user_role, array $current_user): array
    {
        $user_club_id = $current_user['club_id'] ?? null;
        $entidad_actual = isset($current_user['entidad']) ? (int)$current_user['entidad'] : 0;
        $entidad_map = [];
        $entidad_nombre_actual = 'No definida';

        if ($user_role === 'admin_club') {
            try {
                $stmt = DB::pdo()->prepare("SELECT nombre FROM entidad WHERE id = ? LIMIT 1");
                $stmt->execute([$entidad_actual]);
                $n = $stmt->fetchColumn();
                if ($n) {
                    $entidad_nombre_actual = $n;
                } else {
                    $stmt = DB::pdo()->prepare("SELECT nombre FROM entidad WHERE codigo = ? LIMIT 1");
                    $stmt->execute([$entidad_actual]);
                    $n = $stmt->fetchColumn();
                    if ($n) $entidad_nombre_actual = $n;
                }
            } catch (Exception $e) {
            }
        } else {
            $entidad_map = self::loadEntidadMap();
            $entidad_nombre_actual = $entidad_map[$entidad_actual] ?? 'No definida';
        }

        $result = [
            'entidad_map' => $entidad_map,
            'entidad_nombre_actual' => $entidad_nombre_actual,
            'stats' => [],
            'admin_club_stats' => [],
            'admin_clubs_list' => [],
            'torneos_por_entidad_ag' => [],
            'torneos_linea_ag' => ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []],
            'torneos_linea_acl' => ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []],
            'athletes_by_club' => [],
            'registrants_by_tournament' => [],
            'registrants_by_club' => [],
            'grouped_registrants' => [],
            'admin_clubs_stats_error' => null,
            'actas_pendientes' => 0,
        ];

        try {
            require_once __DIR__ . '/StatisticsHelper.php';
            require_once __DIR__ . '/ClubHelper.php';

            $club_filter = Auth::getClubFilterForRole('c');
            $tournament_filter = Auth::getTournamentFilterForRole();

            if ($user_role === 'admin_club') {
                if (function_exists('session_write_close')) {
                    session_write_close();
                }
                require_once __DIR__ . '/OrganizacionesData.php';
                $entidadId = (int)($current_user['entidad'] ?? 0);
                $atletasEntidad = OrganizacionesData::loadAtletasStats($entidadId > 0 ? $entidadId : null);
                $admin_club_stats = StatisticsHelper::generateStatistics();
                if (!isset($admin_club_stats['error'])) {
                    $result['admin_club_stats'] = $admin_club_stats;
                    $result['stats'] = array_merge([
                        'clubs' => (int)($admin_club_stats['total_clubes'] ?? 0),
                        'tournaments' => (int)($admin_club_stats['total_torneos'] ?? 0),
                        'registrants' => (int)($admin_club_stats['total_inscritos'] ?? 0),
                        'payments' => 0,
                        'active_tournaments' => (int)($admin_club_stats['torneos_activos'] ?? 0),
                        'pending_payments' => 0,
                        'total_revenue' => 0,
                    ], $atletasEntidad);
                    $torneos_list = $admin_club_stats['torneos'] ?? [];
                } else {
                    $result['admin_clubs_stats_error'] = $admin_club_stats['error'];
                    $torneos_list = [];
                }
                $result['torneos_linea_acl'] = self::classifyTorneos($torneos_list);
                $result['athletes_by_club'] = self::loadAthletesByClub($current_user, $user_club_id);
            } else {
                $result['stats'] = self::loadStatsBasic($club_filter, $tournament_filter);
            }

            if ($user_role === 'admin_general') {
                require_once __DIR__ . '/OrganizacionesData.php';
                $agStats = self::loadStatsAdminGeneral($entidad_map);
                $result['admin_clubs_list'] = $agStats['_admin_clubs_list'] ?? [];
                unset($agStats['_admin_clubs_list']);
                $result['stats'] = array_merge($result['stats'], $agStats, OrganizacionesData::loadAtletasStats(null));
                $result['torneos_por_entidad_ag'] = self::loadTorneosPorEntidad($entidad_map);
                $result['torneos_linea_ag'] = self::flattenTorneosLinea($result['torneos_por_entidad_ag']);
            }

            if ($user_role === 'admin_torneo') {
                $tournament_filter = Auth::getTournamentFilterForRole('t');
                $result['torneos_linea_acl'] = self::classifyTorneos(self::loadTorneosActivosFlat($tournament_filter));
            }

            if (in_array($user_role, ['admin_club', 'admin_general'], true)) {
                try {
                    require_once __DIR__ . '/ActasPendientesHelper.php';
                    $result['actas_pendientes'] = ActasPendientesHelper::contar();
                    $result['actas_ultimo_envio'] = ActasPendientesHelper::ultimoEnvio();
                } catch (Exception $e) {
                    $result['actas_pendientes'] = 0;
                    $result['actas_ultimo_envio'] = null;
                }
            }

            if (!empty($club_filter) && !empty($tournament_filter)) {
                $result['registrants_by_tournament'] = self::loadRegistrantsByTournament($tournament_filter);
                $result['registrants_by_club'] = self::loadRegistrantsByClub($club_filter);
            }
            $result['grouped_registrants'] = self::loadGroupedRegistrants();
        } catch (Exception $e) {
            error_log("DashboardData loadAll: " . $e->getMessage());
            $result['stats'] = array_merge($result['stats'], self::defaultStats());
        }

        return $result;
    }

    private static function loadStatsBasic(array $club_filter, array $tournament_filter): array
    {
        $pdo = DB::pdo();
        $stats = [];
        $fvdClubScope = self::fvdClubScopeSql($pdo);
        $clubs_query = "SELECT COUNT(*) FROM clubes c WHERE {$fvdClubScope['where']}"
            . ($club_filter['where'] ? ' AND ' . $club_filter['where'] : '');
        $stmt = $pdo->prepare($clubs_query);
        $stmt->execute(array_merge($fvdClubScope['params'], $club_filter['params']));
        $stats['clubs'] = (int)$stmt->fetchColumn();
        $tournaments_query = "SELECT COUNT(*) FROM tournaments t WHERE t.estatus = 1" . ($tournament_filter['where'] ? " AND " . $tournament_filter['where'] : "");
        $stmt = $pdo->prepare($tournaments_query);
        $stmt->execute($tournament_filter['params']);
        $stats['tournaments'] = (int)$stmt->fetchColumn();
        $registrants_query = "SELECT COUNT(*) FROM inscritos r INNER JOIN tournaments t ON r.torneo_id = t.id WHERE 1=1" . ($tournament_filter['where'] ? " AND " . $tournament_filter['where'] : "");
        $stmt = $pdo->prepare($registrants_query);
        $stmt->execute($tournament_filter['params']);
        $stats['registrants'] = (int)$stmt->fetchColumn();
        $stats = array_merge($stats, self::loadPaymentStats($tournament_filter));
        $active_query = "SELECT COUNT(*) FROM tournaments t WHERE t.estatus = 1 AND t.fechator >= CURDATE()" . ($tournament_filter['where'] ? " AND " . $tournament_filter['where'] : "");
        $stmt = $pdo->prepare($active_query);
        $stmt->execute($tournament_filter['params']);
        $stats['active_tournaments'] = (int)$stmt->fetchColumn();
        return $stats;
    }

    /**
     * Estadísticas de payments; 0 si la tabla no existe o falla (esquema FVD en transición).
     *
     * @return array{payments: int, pending_payments: int, total_revenue: float}
     */
    private static function loadPaymentStats(array $tournament_filter): array
    {
        $defaults = ['payments' => 0, 'pending_payments' => 0, 'total_revenue' => 0.0];
        try {
            $pdo = DB::pdo();
            $chk = $pdo->query("SHOW TABLES LIKE 'payments'");
            if (!$chk || $chk->rowCount() === 0) {
                return $defaults;
            }
            $where_t = $tournament_filter['where'] ? ' AND ' . $tournament_filter['where'] : '';
            $payments_query = "SELECT COUNT(*) FROM payments p INNER JOIN tournaments t ON p.torneo_id = t.id WHERE p.status = 'completed'" . $where_t;
            $stmt = $pdo->prepare($payments_query);
            $stmt->execute($tournament_filter['params']);
            $defaults['payments'] = (int)$stmt->fetchColumn();
            $pending_query = "SELECT COUNT(*) FROM payments p INNER JOIN tournaments t ON p.torneo_id = t.id WHERE p.status = 'pending'" . $where_t;
            $stmt = $pdo->prepare($pending_query);
            $stmt->execute($tournament_filter['params']);
            $defaults['pending_payments'] = (int)$stmt->fetchColumn();
            $revenue_query = "SELECT COALESCE(SUM(p.amount), 0) FROM payments p INNER JOIN tournaments t ON p.torneo_id = t.id WHERE p.status = 'completed'" . $where_t;
            $stmt = $pdo->prepare($revenue_query);
            $stmt->execute($tournament_filter['params']);
            $defaults['total_revenue'] = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('DashboardData loadPaymentStats: ' . $e->getMessage());
        }
        return $defaults;
    }

    /** @return array{where: string, params: list<mixed>} */
    private static function fvdClubScopeSql(PDO $pdo): array
    {
        require_once __DIR__ . '/OrganizacionesData.php';
        return OrganizacionesData::sqlClubesAmbitoFvd($pdo, 'c');
    }

    private static function loadStatsAdminGeneral(array $entidad_map): array
    {
        $stats = [];
        $pdo = DB::pdo();
        require_once __DIR__ . '/OrganizacionesData.php';
        [$uScope, $uScopeParams] = OrganizacionesData::sqlUsuarioAmbitoFvd($pdo);
        [$clubWhere, $clubParams] = OrganizacionesData::sqlClubesAmbitoFvd($pdo, 'c');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE {$uScope}");
        $stmt->execute($uScopeParams);
        $stats['total_users'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.status = 0 AND {$uScope}");
        $stmt->execute($uScopeParams);
        $stats['active_users'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'admin_club' AND u.status = 0 AND {$uScope}");
        $stmt->execute($uScopeParams);
        $stats['total_admin_clubs'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM clubes c WHERE {$clubWhere}");
        $stmt->execute($clubParams);
        $stats['total_clubs'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'usuario' AND u.status = 0 AND {$uScope}");
        $stmt->execute($uScopeParams);
        $stats['total_afiliados'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'usuario' AND u.status = 0 AND (u.sexo = 'M' OR UPPER(u.sexo) = 'M') AND {$uScope}");
        $stmt->execute($uScopeParams);
        $stats['total_hombres'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE u.role = 'usuario' AND u.status = 0 AND (u.sexo = 'F' OR UPPER(u.sexo) = 'F') AND {$uScope}");
        $stmt->execute($uScopeParams);
        $stats['total_mujeres'] = (int)$stmt->fetchColumn();
        $stats['entidades_summary'] = self::loadEntidadSummary($entidad_map);
        $admin_clubs_list = [];
        try {
            $admin_clubs_stats = StatisticsHelper::generateStatistics();
            if (!isset($admin_clubs_stats['error'])) {
                $stats['total_admin_clubs'] = (int)($admin_clubs_stats['total_admin_clubs'] ?? 0);
                $stats['total_admin_torneo'] = (int)($admin_clubs_stats['total_admin_torneo'] ?? 0);
                $stats['total_operadores'] = (int)($admin_clubs_stats['total_operadores'] ?? 0);
                $stats['total_clubs'] = (int)($admin_clubs_stats['total_clubs'] ?? 0);
                $stats['total_tournaments'] = (int)($admin_clubs_stats['total_tournaments'] ?? 0);
                $stats['total_inscritos'] = (int)($admin_clubs_stats['total_inscritos'] ?? 0);
                $admin_clubs_list = $admin_clubs_stats['admins_by_club'] ?? [];
                foreach ($admin_clubs_list as &$admin) {
                    $club_ids = ClubHelper::getClubesByAdminClubId((int)($admin['admin_id'] ?? 0));
                    if (empty($club_ids) && !empty($admin['club_principal_id'])) {
                        $club_ids = ClubHelper::getClubesSupervised($admin['club_principal_id']);
                    }
                    if (!empty($club_ids)) {
                        $ph = implode(',', array_fill(0, count($club_ids), '?'));
                        $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable IN ($ph)");
                        $stmt->execute($club_ids);
                        $admin['total_torneos'] = (int)$stmt->fetchColumn();
                    } else {
                        $admin['total_torneos'] = 0;
                    }
                }
                unset($admin);
            }
            if (empty($admin_clubs_list)) {
                $sql = "SELECT u.id as admin_id, u.username as admin_username, u.nombre as admin_nombre, u.entidad,
                    u.club_id as club_principal_id, c.nombre as club_principal_nombre,
                    (SELECT COUNT(*) FROM clubes cx WHERE cx.cod_org = (SELECT COALESCE(NULLIF(o.cod_org, 0), NULLIF(o.entidad, 0)) FROM organizaciones o WHERE o.admin_user_id = u.id LIMIT 1) AND cx.estatus = 1) as supervised_clubs_count,
                    (SELECT COUNT(*) FROM usuarios ux WHERE ux.club_id = u.club_id AND ux.role = 'usuario' AND ux.status = 0) as total_users,
                    (SELECT COUNT(*) FROM usuarios ux WHERE ux.club_id = u.club_id AND ux.role = 'usuario' AND ux.status = 0 AND (ux.sexo = 'M' OR UPPER(ux.sexo) = 'M')) as hombres,
                    (SELECT COUNT(*) FROM usuarios ux WHERE ux.club_id = u.club_id AND ux.role = 'usuario' AND ux.status = 0 AND (ux.sexo = 'F' OR UPPER(ux.sexo) = 'F')) as mujeres,
                    (SELECT COUNT(*) FROM tournaments t WHERE t.club_responsable = u.club_id AND t.estatus = 1) as total_torneos
                    FROM usuarios u LEFT JOIN clubes c ON u.club_id = c.id
                    WHERE u.role = 'admin_club' AND u.status = 0 ORDER BY u.entidad ASC, c.nombre ASC";
                $admin_clubs_list = DB::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                foreach ($admin_clubs_list as &$a) {
                    $h = (int)($a['hombres'] ?? 0);
                    $m = (int)($a['mujeres'] ?? 0);
                    $tot = (int)($a['total_users'] ?? 0);
                    $a['entidad'] = (int)($a['entidad'] ?? 0);
                    $a['users_by_gender'] = ['hombres' => $h, 'mujeres' => $m, 'otros' => max(0, $tot - $h - $m)];
                }
                unset($a);
                if (empty($stats['total_admin_clubs'])) {
                    $stats['total_admin_clubs'] = count($admin_clubs_list);
                }
            }
        } catch (Exception $e) {
            error_log("DashboardData loadStatsAdminGeneral: " . $e->getMessage());
        }
        $stats['_admin_clubs_list'] = $admin_clubs_list;
        return $stats;
    }

    private static function classifyTorneos(array $torneos_list): array
    {
        $out = ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
        if (empty($torneos_list)) return $out;
        $ids = array_column($torneos_list, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = DB::pdo()->prepare("SELECT id_torneo, MAX(CAST(partida AS UNSIGNED)) as ultima_ronda FROM partiresul WHERE id_torneo IN ($placeholders) AND mesa > 0 GROUP BY id_torneo");
        $stmt->execute($ids);
        $ultima_ronda = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ultima_ronda[(int)$row['id_torneo']] = (int)$row['ultima_ronda'];
        }
        $hoy = date('Y-m-d');
        foreach ($torneos_list as $t) {
            $t['ultima_ronda'] = $ultima_ronda[(int)$t['id']] ?? 0;
            $t['rondas'] = (int)($t['rondas'] ?? 0);
            $locked = (int)($t['locked'] ?? 0) === 1;
            $fecha_ok = !empty($t['fechator']) && strtotime($t['fechator']) <= strtotime($hoy);
            if ($locked) $out['realizados'][] = $t;
            elseif ($fecha_ok || $t['ultima_ronda'] > 0) $out['en_proceso'][] = $t;
            else $out['por_realizar'][] = $t;
        }
        return $out;
    }

    private static function loadTorneosPorEntidad(array $entidad_map): array
    {
        $torneos_por_entidad = [];
        try {
            $has_cod_org = false;
            try {
                $has_cod_org = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $ignored) {
                $has_cod_org = false;
            }
            $org_join = $has_cod_org
                ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
                : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";
            $tournament_filter = Auth::getTournamentFilterForRole('t');
            $where_t = !empty($tournament_filter['where']) ? ' AND ' . $tournament_filter['where'] : '';
            $params_t = $tournament_filter['params'];
            $sql = "
                SELECT t.id, t.nombre, t.fechator, t.estatus, t.locked, t.rondas, t.club_responsable,
                       o.nombre as organizacion_nombre,
                       (SELECT u.entidad FROM usuarios u INNER JOIN organizaciones org ON org.admin_user_id = u.id WHERE org.id = t.club_responsable LIMIT 1) as entidad,
                       (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id) as total_inscritos,
                       (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND estatus = 1) as inscritos_confirmados
                FROM tournaments t
                {$org_join}
                WHERE t.estatus = 1 $where_t
                ORDER BY t.fechator DESC, t.id DESC
            ";
            $stmt = DB::pdo()->prepare($sql);
            $stmt->execute($params_t);
            $torneos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_column($torneos_raw, 'id');
            $ultima_ronda_by = [];
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt_r = DB::pdo()->prepare("SELECT id_torneo, MAX(CAST(partida AS UNSIGNED)) as ultima_ronda FROM partiresul WHERE id_torneo IN ($ph) AND mesa > 0 GROUP BY id_torneo");
                $stmt_r->execute($ids);
                foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $ultima_ronda_by[(int)$row['id_torneo']] = (int)$row['ultima_ronda'];
                }
            }
            $hoy = date('Y-m-d');
            foreach ($torneos_raw as $t) {
                $t['ultima_ronda'] = $ultima_ronda_by[(int)$t['id']] ?? 0;
                $t['rondas'] = (int)($t['rondas'] ?? 0);
                $locked = (int)($t['locked'] ?? 0) === 1;
                $fecha_ok = $t['fechator'] ? (strtotime($t['fechator']) <= strtotime($hoy)) : false;
                $t['categoria'] = $locked ? 'realizados' : ($fecha_ok || $t['ultima_ronda'] > 0 ? 'en_proceso' : 'por_realizar');
                $ent = (int)($t['entidad'] ?? 0);
                $ent_nombre = $ent <= 0 ? 'Sin entidad' : ($entidad_map[$ent] ?? ('Entidad ' . $ent));
                if (!isset($torneos_por_entidad[$ent_nombre])) {
                    $torneos_por_entidad[$ent_nombre] = ['entidad_cod' => $ent, 'por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
                }
                $torneos_por_entidad[$ent_nombre][$t['categoria']][] = $t;
            }
            if (isset($torneos_por_entidad['Sin entidad'])) {
                $sin = $torneos_por_entidad['Sin entidad'];
                unset($torneos_por_entidad['Sin entidad']);
                $torneos_por_entidad['Sin entidad'] = $sin;
            }
        } catch (Exception $e) {
            error_log("DashboardData loadTorneosPorEntidad: " . $e->getMessage());
        }
        return $torneos_por_entidad;
    }

    private static function flattenTorneosLinea(array $torneos_por_entidad): array
    {
        $out = ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
        foreach ($torneos_por_entidad as $datos) {
            foreach (['por_realizar', 'en_proceso', 'realizados'] as $cat) {
                foreach ($datos[$cat] ?? [] as $t) {
                    $out[$cat][] = $t;
                }
            }
        }
        return $out;
    }

    /**
     * Clasificación de torneos para el home según rol.
     *
     * @return array{por_realizar: list<array>, en_proceso: list<array>, realizados: list<array>}
     */
    public static function torneosLineaParaHome(string $user_role, array $current_user): array
    {
        if ($user_role === 'admin_general') {
            $entidad_map = self::loadEntidadMap();

            return self::flattenTorneosLinea(self::loadTorneosPorEntidad($entidad_map));
        }
        if ($user_role === 'admin_club') {
            require_once __DIR__ . '/StatisticsHelper.php';
            $admin_club_stats = StatisticsHelper::generateStatistics();
            $torneos_list = $admin_club_stats['torneos'] ?? [];

            return self::classifyTorneos(is_array($torneos_list) ? $torneos_list : []);
        }
        if ($user_role === 'admin_torneo') {
            $tournament_filter = Auth::getTournamentFilterForRole('t');

            return self::classifyTorneos(self::loadTorneosActivosFlat($tournament_filter));
        }

        return ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
    }

    /**
     * Torneos activos/en curso y próximos (ventana de días) para la tarjeta del home.
     *
     * @param list<array<string, mixed>> $en_proceso
     * @param list<array<string, mixed>> $por_realizar
     * @return list<array<string, mixed>>
     */
    public static function filtrarTorneosHomeDashboard(array $en_proceso, array $por_realizar, int $diasVentana = 15): array
    {
        $hoy = strtotime(date('Y-m-d'));
        $limite = strtotime('+' . max(1, $diasVentana) . ' days', $hoy);
        $out = [];

        foreach ($en_proceso as $t) {
            $t['_dashboard_estado'] = 'en_proceso';
            $out[] = $t;
        }

        foreach ($por_realizar as $t) {
            $ft = trim((string) ($t['fechator'] ?? ''));
            if ($ft === '') {
                continue;
            }
            $fecha = strtotime(date('Y-m-d', strtotime($ft)));
            if ($fecha === false || $fecha < $hoy || $fecha > $limite) {
                continue;
            }
            $t['_dashboard_estado'] = 'por_realizar';
            $out[] = $t;
        }

        usort($out, static function (array $a, array $b): int {
            $fa = strtotime((string) ($a['fechator'] ?? '')) ?: PHP_INT_MAX;
            $fb = strtotime((string) ($b['fechator'] ?? '')) ?: PHP_INT_MAX;
            if ($fa === $fb) {
                return strcmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
            }

            return $fa <=> $fb;
        });

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadTorneosActivosFlat(array $tournament_filter): array
    {
        try {
            $pdo = DB::pdo();
            $has_cod_org = false;
            try {
                $has_cod_org = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $ignored) {
                $has_cod_org = false;
            }
            $org_join = $has_cod_org
                ? 'LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)'
                : 'LEFT JOIN organizaciones o ON t.club_responsable = o.id';
            $where_t = !empty($tournament_filter['where']) ? ' AND ' . $tournament_filter['where'] : '';
            $params_t = $tournament_filter['params'] ?? [];
            $sql = "
                SELECT t.id, t.nombre, t.fechator, t.estatus, t.locked, t.rondas, t.club_responsable,
                       o.nombre as organizacion_nombre,
                       (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id) as total_inscritos,
                       (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND estatus = 1) as inscritos_confirmados
                FROM tournaments t
                {$org_join}
                WHERE t.estatus = 1 {$where_t}
                ORDER BY t.fechator ASC, t.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params_t);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('DashboardData loadTorneosActivosFlat: ' . $e->getMessage());

            return [];
        }
    }

    private static function loadAthletesByClub(array $current_user, $user_club_id): array
    {
        $out = [];
        $supervised_clubs = ClubHelper::getClubesByAdminClubId((int)($current_user['id'] ?? 0));
        if (empty($supervised_clubs) && $user_club_id) {
            $supervised_clubs = ClubHelper::getClubesSupervised($user_club_id);
        }
        if (empty($supervised_clubs)) return $out;
        $ph = str_repeat('?,', count($supervised_clubs) - 1) . '?';
        $sql = "
            SELECT c.id as club_id, c.nombre as club_nombre,
                   COUNT(DISTINCT u.id) as total_atletas,
                   SUM(CASE WHEN u.sexo = 'M' OR u.sexo = 1 THEN 1 ELSE 0 END) as hombres,
                   SUM(CASE WHEN u.sexo = 'F' OR u.sexo = 2 THEN 1 ELSE 0 END) as mujeres,
                   (SELECT COUNT(*) FROM tournaments t WHERE t.club_responsable = COALESCE(c.cod_org, c.id) AND t.estatus = 1) as torneos_count,
                   (SELECT COUNT(*) FROM inscritos i INNER JOIN tournaments t ON i.torneo_id = t.id WHERE t.club_responsable = COALESCE(c.cod_org, c.id)) as inscritos_count
            FROM clubes c
            LEFT JOIN usuarios u ON u.club_id = c.id AND u.role = 'usuario' AND u.status = 0
            WHERE c.id IN ($ph)
            GROUP BY c.id, c.nombre, c.cod_org
            ORDER BY c.nombre ASC
        ";
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($supervised_clubs);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['total_atletas'] = (int)($r['total_atletas'] ?? 0);
            $r['hombres'] = (int)($r['hombres'] ?? 0);
            $r['mujeres'] = (int)($r['mujeres'] ?? 0);
            $r['torneos_count'] = (int)($r['torneos_count'] ?? 0);
            $r['inscritos_count'] = (int)($r['inscritos_count'] ?? 0);
        }
        return $rows;
    }

    private static function loadRegistrantsByTournament(array $tournament_filter): array
    {
        $sql = "SELECT t.nombre as tournament_name, COUNT(r.id) as count
                FROM tournaments t
                LEFT JOIN inscritos r ON t.id = r.torneo_id
                WHERE t.estatus = 1" . ($tournament_filter['where'] ? " AND " . $tournament_filter['where'] : "") . "
                GROUP BY t.id, t.nombre ORDER BY count DESC LIMIT 5";
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($tournament_filter['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function loadRegistrantsByClub(array $club_filter): array
    {
        $sql = "SELECT c.nombre as club_name, COUNT(r.id) as count
                FROM clubes c
                LEFT JOIN inscritos r ON c.id = r.id_club
                WHERE c.estatus = 1" . ($club_filter['where'] ? " AND " . $club_filter['where'] : "") . "
                GROUP BY c.id, c.nombre ORDER BY count DESC LIMIT 5";
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($club_filter['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function loadGroupedRegistrants(): array
    {
        $rows = DB::pdo()->query("
            SELECT r.id, u.nombre, t.nombre as tournament_name, r.fecha_inscripcion as created_at, t.id as tournament_id
            FROM inscritos r
            INNER JOIN usuarios u ON r.id_usuario = u.id
            LEFT JOIN tournaments t ON r.torneo_id = t.id
            ORDER BY t.nombre, r.fecha_inscripcion DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $r) {
            $tn = $r['tournament_name'] ?? 'Sin Torneo';
            if (!isset($grouped[$tn])) $grouped[$tn] = [];
            $grouped[$tn][] = $r;
        }
        return $grouped;
    }

    private static function defaultStats(): array
    {
        return [
            'clubs' => 0, 'tournaments' => 0, 'registrants' => 0, 'payments' => 0,
            'active_tournaments' => 0, 'pending_payments' => 0, 'total_revenue' => 0,
            'total_users' => 0, 'active_users' => 0, 'total_admin_clubs' => 0, 'total_clubs' => 0,
            'total_afiliados' => 0, 'total_hombres' => 0, 'total_mujeres' => 0,
        ];
    }
}
