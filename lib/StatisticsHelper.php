<?php
/**
 * StatisticsHelper - Genera estadísticas del sistema según el rol del usuario
 */

if (!defined('APP_BOOTSTRAPPED')) { 
    require_once __DIR__ . '/../config/bootstrap.php'; 
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/ClubHelper.php';

class StatisticsHelper {
    
    /** TTL del caché de estadísticas: 5 minutos */
    private const CACHE_TTL = 300;
    
    /**
     * Genera estadísticas según el rol del usuario actual.
     * Usa caché temporal de 5 min para reducir TTFB.
     * 
     * @return array Estadísticas formateadas
     */
    public static function generateStatistics(): array {
        try {
            $current_user = Auth::user();
            
            if (!$current_user) {
                return ['error' => 'Usuario no autenticado'];
            }
            
            $user_role = $current_user['role'] ?? '';
            $user_id = Auth::id();
            $cache_key = 'stats_' . $user_role . '_' . $user_id;
            
            // Intentar obtener del caché (5 min)
            $cached = self::getCachedStats($cache_key);
            if ($cached !== null) {
                return $cached;
            }
            
            $stats = null;
            if ($user_role === 'admin_general') {
                $stats = self::generateAdminGeneralStats();
            } elseif ($user_role === 'admin_club') {
                $stats = self::generateAdminClubStatsByUserId($user_id);
            } else {
                return ['error' => 'Rol no válido o sin club asignado'];
            }
            
            if ($stats && !isset($stats['error'])) {
                self::setCachedStats($cache_key, $stats);
            }
            return $stats;
        } catch (Exception $e) {
            error_log("StatisticsHelper::generateStatistics error: " . $e->getMessage());
            return ['error' => 'Error al generar estadísticas: ' . $e->getMessage()];
        }
    }
    
    private static function getCacheDir(): string {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mistorneos_stats';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
    
    private static function getCachedStats(string $key): ?array {
        $file = self::getCacheDir() . DIRECTORY_SEPARATOR . md5($key) . '.cache';
        if (!file_exists($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = @unserialize($raw);
        if (!is_array($data) || ($data['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data['stats'] ?? null;
    }
    
    private static function setCachedStats(string $key, array $stats): void {
        $file = self::getCacheDir() . DIRECTORY_SEPARATOR . md5($key) . '.cache';
        $data = ['stats' => $stats, 'expires' => time() + self::CACHE_TTL];
        @file_put_contents($file, serialize($data), LOCK_EX);
    }
    
    /**
     * Genera estadísticas para admin_general
     * 
     * @return array
     */
    private static function generateAdminGeneralStats(): array {
        $pdo = DB::pdo();
        $stats = [];
        
        try {
            // Total de usuarios (todos)
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
            $result = $stmt->fetchColumn();
            $stats['total_users'] = $result !== false ? (int)$result : 0;
            
            // Total de usuarios activos (approved)
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE status = 0");
            $result = $stmt->fetchColumn();
            $stats['total_active_users'] = $result !== false ? (int)$result : 0;
            
            // Total de clubes activos
            $stmt = $pdo->query("SELECT COUNT(*) FROM clubes WHERE estatus = 1");
            $result = $stmt->fetchColumn();
            $stats['total_active_clubs'] = $result !== false ? (int)$result : 0;
            $stats['total_clubs'] = $stats['total_active_clubs']; // Alias para compatibilidad
            
            // Total de administradores de club (incluir pending y approved, ya que los admins pueden estar pending)
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'admin_club' AND status = 0");
            $result = $stmt->fetchColumn();
            $stats['total_admin_clubs'] = $result !== false ? (int)$result : 0;
            
            // Total de administradores de torneo
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'admin_torneo' AND status = 0");
            $result = $stmt->fetchColumn();
            $stats['total_admin_torneo'] = $result !== false ? (int)$result : 0;
            
            // Total de operadores
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'operador' AND status = 0");
            $result = $stmt->fetchColumn();
            $stats['total_operadores'] = $result !== false ? (int)$result : 0;
            
            // Total de torneos activos
            $stmt = $pdo->query("SELECT COUNT(*) FROM tournaments WHERE estatus = 1");
            $result = $stmt->fetchColumn();
            $stats['total_tournaments'] = $result !== false ? (int)$result : 0;
            
            // Total de inscritos
            $stmt = $pdo->query("SELECT COUNT(*) FROM inscritos");
            $result = $stmt->fetchColumn();
            $stats['total_inscritos'] = $result !== false ? (int)$result : 0;
            
            // Torneos activos (futuros)
            $stmt = $pdo->query("SELECT COUNT(*) FROM tournaments WHERE estatus = 1 AND fechator >= CURDATE()");
            $result = $stmt->fetchColumn();
            $stats['active_tournaments'] = $result !== false ? (int)$result : 0;
            
            // Estadísticas por administrador de club
            $stats['admins_by_club'] = self::getAdminsByClubStats();
            
        } catch (Exception $e) {
            error_log("StatisticsHelper::generateAdminGeneralStats error: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
            $stats['error'] = 'Error al generar estadísticas: ' . $e->getMessage();
            // Valores por defecto para evitar errores
            $stats['total_users'] = 0;
            $stats['total_active_users'] = 0;
            $stats['total_clubs'] = 0;
            $stats['total_active_clubs'] = 0;
            $stats['total_admin_clubs'] = 0;
            $stats['total_admin_torneo'] = 0;
            $stats['total_operadores'] = 0;
            $stats['total_tournaments'] = 0;
            $stats['total_inscritos'] = 0;
            $stats['active_tournaments'] = 0;
            $stats['admins_by_club'] = [];
        }
        
        return $stats;
    }
    
    /**
     * Genera estadísticas para administrador de organización (por admin_club_id)
     * 
     * @param int $admin_user_id ID del usuario administrador de organización
     * @return array
     */
    private static function generateAdminClubStatsByUserId(int $admin_user_id): array {
        $pdo = DB::pdo();
        $stats = [];
        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $hasCodOrg = false;
        }
        
        $organizacionRow = null;
        try {
            $stmtOrgRow = $pdo->prepare('SELECT o.* FROM organizaciones o WHERE o.admin_user_id = ? AND o.estatus = 1 LIMIT 1');
            $stmtOrgRow->execute([$admin_user_id]);
            $organizacionRow = $stmtOrgRow->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$organizacionRow) {
                $oid = (int) Auth::getUserOrganizacionId();
                if ($oid > 0) {
                    $stmtOrgRow = $pdo->prepare('SELECT o.* FROM organizaciones o WHERE o.id = ? AND o.estatus = 1 LIMIT 1');
                    $stmtOrgRow->execute([$oid]);
                    $organizacionRow = $stmtOrgRow->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }

            require_once __DIR__ . '/OrganizacionDashboardStats.php';

            $supervised_club_ids = [];
            if ($organizacionRow) {
                $supervised_club_ids = OrganizacionDashboardStats::clubIdsForOrganizacion($pdo, $organizacionRow, $hasCodOrg);
            }
            if (empty($supervised_club_ids)) {
                $supervised_club_ids = ClubHelper::getClubesByAdminClubId($admin_user_id);
                if (empty($supervised_club_ids)) {
                    $club_id = $pdo->prepare("SELECT club_id FROM usuarios WHERE id = ? AND role = 'admin_club'");
                    $club_id->execute([$admin_user_id]);
                    $cid = (int) $club_id->fetchColumn();
                    if ($cid > 0) {
                        $supervised_club_ids = ClubHelper::getClubesSupervised($cid);
                    }
                }
            }

            if (empty($supervised_club_ids)) {
                return ['error' => 'No hay clubes asignados'];
            }
            
            $placeholders = implode(',', array_fill(0, count($supervised_club_ids), '?'));
            
            // Total de administradores de torneo (admin_torneo) en los clubes supervisados
            // Nota: status puede ser ENUM o INT, usar ambos formatos
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT u.id) as total
                FROM usuarios u
                WHERE u.club_id IN ($placeholders) 
                  AND u.role = 'admin_torneo' 
                  AND u.status = 0
            ");
            $stmt->execute($supervised_club_ids);
            $stats['total_admin_torneo'] = (int)$stmt->fetchColumn();
            
            // Lista de administradores de torneo (LIMIT 50 para rendimiento)
            $stmt = $pdo->prepare("
                SELECT 
                    u.id as admin_id,
                    u.nombre as admin_nombre,
                    u.username as admin_username,
                    u.club_id,
                    u.status as admin_status,
                    c.nombre as club_nombre
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.club_id IN ($placeholders) 
                  AND u.role = 'admin_torneo' 
                  AND u.status = 0
                ORDER BY u.nombre ASC
                LIMIT 50
            ");
            $stmt->execute($supervised_club_ids);
            $stats['admins_torneo'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Totales por club_id en 2 consultas (evitar N+1)
            $torneos_by_club = [];
            $inscritos_by_club = [];
            if (!empty($stats['admins_torneo'])) {
                $club_ids_admin = array_unique(array_column($stats['admins_torneo'], 'club_id'));
                $club_ids_admin = array_filter($club_ids_admin, function ($id) { return (int)$id > 0; });
                if (!empty($club_ids_admin)) {
                    $ph2 = implode(',', array_fill(0, count($club_ids_admin), '?'));
                    $stmt = $pdo->prepare("SELECT club_responsable as club_id, COUNT(*) as c FROM tournaments WHERE club_responsable IN ($ph2) AND estatus = 1 GROUP BY club_responsable");
                    $stmt->execute(array_values($club_ids_admin));
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $torneos_by_club[(int)$r['club_id']] = (int)$r['c'];
                    }
                    $stmt = $pdo->prepare("SELECT t.club_responsable as club_id, COUNT(*) as c FROM inscritos i INNER JOIN tournaments t ON i.torneo_id = t.id WHERE t.club_responsable IN ($ph2) AND t.estatus = 1 GROUP BY t.club_responsable");
                    $stmt->execute(array_values($club_ids_admin));
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $inscritos_by_club[(int)$r['club_id']] = (int)$r['c'];
                    }
                }
            }
            foreach ($stats['admins_torneo'] as &$admin) {
                $cid = (int)($admin['club_id'] ?? 0);
                $admin['total_torneos'] = $torneos_by_club[$cid] ?? 0;
                $admin['total_inscritos'] = $inscritos_by_club[$cid] ?? 0;
            }
            unset($admin);
            
            // Total de operadores en los clubes supervisados
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT u.id) as total
                FROM usuarios u
                WHERE u.club_id IN ($placeholders) 
                  AND u.role = 'operador' 
                  AND u.status = 0
            ");
            $stmt->execute($supervised_club_ids);
            $stats['total_operadores'] = (int)$stmt->fetchColumn();
            
            // Lista de operadores (LIMIT 50 para rendimiento)
            $stmt = $pdo->prepare("
                SELECT 
                    u.id as operador_id,
                    u.nombre as operador_nombre,
                    u.username as operador_username,
                    u.club_id,
                    u.status as operador_status,
                    c.nombre as club_nombre
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.club_id IN ($placeholders) 
                  AND u.role = 'operador' 
                  AND u.status = 0
                ORDER BY u.nombre ASC
                LIMIT 50
            ");
            $stmt->execute($supervised_club_ids);
            $stats['operadores'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Total de clubes supervisados
            $stats['total_clubes'] = count($supervised_club_ids);
            
            // Clubes supervisados (LIMIT 50 para rendimiento)
            $stmt = $pdo->prepare("
                SELECT 
                    c.id, c.nombre, c.delegado, c.telefono, c.cod_org,
                    COUNT(DISTINCT u.id) as total_afiliados,
                    SUM(CASE WHEN u.sexo = 'M' OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                    SUM(CASE WHEN u.sexo = 'F' OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres,
                    SUM(CASE WHEN u.sexo IS NULL OR u.sexo = '' THEN 1 ELSE 0 END) as sin_genero
                FROM clubes c
                LEFT JOIN usuarios u ON " . ClubHelper::sqlJoinUsuariosAfiliadosOnClub($pdo, 'c') . "
                WHERE c.id IN ($placeholders) AND c.estatus = 1
                GROUP BY c.id, c.nombre, c.delegado, c.telefono, c.cod_org
                ORDER BY c.nombre ASC
                LIMIT 50
            ");
            $stmt->execute($supervised_club_ids);
            $stats['supervised_clubs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $torneos_by_responsable = [];
            $responsable_ids = [];
            foreach ($stats['supervised_clubs'] as $row) {
                $rid = (int)(!empty($row['cod_org']) ? $row['cod_org'] : $row['id']);
                if ($rid > 0) {
                    $responsable_ids[$rid] = true;
                }
            }
            if (!empty($responsable_ids)) {
                $phr = implode(',', array_fill(0, count($responsable_ids), '?'));
                $stmt = $pdo->prepare("SELECT club_responsable, COUNT(*) as c FROM tournaments WHERE estatus = 1 AND club_responsable IN ($phr) GROUP BY club_responsable");
                $stmt->execute(array_keys($responsable_ids));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $torneos_by_responsable[(int)$r['club_responsable']] = (int)$r['c'];
                }
            }
            foreach ($stats['supervised_clubs'] as &$club) {
                $key = (int)(!empty($club['cod_org']) ? $club['cod_org'] : $club['id']);
                $club['total_torneos'] = $torneos_by_responsable[$key] ?? 0;
                $club['total_afiliados'] = (int)($club['total_afiliados'] ?? 0);
                $club['hombres'] = (int)($club['hombres'] ?? 0);
                $club['mujeres'] = (int)($club['mujeres'] ?? 0);
                $club['sin_genero'] = (int)($club['sin_genero'] ?? 0);
                $club['delegado'] = $club['delegado'] ?? null;
                $club['telefono'] = $club['telefono'] ?? null;
            }
            unset($club);
            
            // Totales de afiliados
            $stats['total_afiliados'] = array_sum(array_column($stats['supervised_clubs'], 'total_afiliados'));
            
            // Totales por género
            $stats['afiliados_by_gender'] = [
                'hombres' => array_sum(array_column($stats['supervised_clubs'], 'hombres')),
                'mujeres' => array_sum(array_column($stats['supervised_clubs'], 'mujeres')),
                'sin_genero' => array_sum(array_column($stats['supervised_clubs'], 'sin_genero'))
            ];
            
            // Referencia legacy tournaments.club_responsable (id o cod_org de la organización)
            $org_id = 0;
            if ($organizacionRow) {
                $org_id = (int) ($organizacionRow['cod_org'] ?? 0);
                if ($org_id <= 0) {
                    $org_id = (int) ($organizacionRow['id'] ?? 0);
                }
            }
            if ($org_id <= 0) {
                $stmt_org = $pdo->prepare("SELECT " . ($hasCodOrg ? "COALESCE(NULLIF(cod_org,0), id)" : "id") . " FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 LIMIT 1");
                $stmt_org->execute([$admin_user_id]);
                $org_id = (int) $stmt_org->fetchColumn();
            }

            $orgNombreExpr = $hasCodOrg
                ? '(SELECT o2.nombre FROM organizaciones o2 WHERE o2.id = t.club_responsable OR o2.cod_org = t.club_responsable ORDER BY (o2.id = t.club_responsable) DESC, o2.id ASC LIMIT 1)'
                : '(SELECT o2.nombre FROM organizaciones o2 WHERE o2.id = t.club_responsable LIMIT 1)';

            // Total de torneos - Por responsable legacy (el total canónico se alinea abajo con OrganizacionDashboardStats)
            if ($org_id > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable = ? AND estatus = 1");
                $stmt->execute([$org_id]);
                $stats['total_torneos'] = (int) $stmt->fetchColumn();

                // Torneos (LIMIT 50): nombre de organización vía subconsulta escalar (evita duplicar filas por JOIN OR)
                $stmt = $pdo->prepare("
                    SELECT t.*, {$orgNombreExpr} AS organizacion_nombre
                    FROM tournaments t
                    WHERE t.club_responsable = ? AND t.estatus = 1
                    ORDER BY t.fechator DESC
                    LIMIT 50
                ");
                $stmt->execute([$org_id]);
                $stats['torneos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $torneo_ids = array_column($stats['torneos'], 'id');
                $inscritos_by_torneo = ['total' => [], 'hombres' => [], 'mujeres' => []];
                if (!empty($torneo_ids)) {
                    $ph = implode(',', array_fill(0, count($torneo_ids), '?'));
                    $stmt = $pdo->prepare("SELECT torneo_id, COUNT(*) as c FROM inscritos WHERE torneo_id IN ($ph) GROUP BY torneo_id");
                    $stmt->execute($torneo_ids);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $inscritos_by_torneo['total'][(int)$r['torneo_id']] = (int)$r['c'];
                    }
                    $stmt = $pdo->prepare("SELECT i.torneo_id, COUNT(*) as c FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id WHERE i.torneo_id IN ($ph) AND (u.sexo = 'M' OR UPPER(u.sexo) = 'M') GROUP BY i.torneo_id");
                    $stmt->execute($torneo_ids);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $inscritos_by_torneo['hombres'][(int)$r['torneo_id']] = (int)$r['c'];
                    }
                    $stmt = $pdo->prepare("SELECT i.torneo_id, COUNT(*) as c FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id WHERE i.torneo_id IN ($ph) AND (u.sexo = 'F' OR UPPER(u.sexo) = 'F') GROUP BY i.torneo_id");
                    $stmt->execute($torneo_ids);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $inscritos_by_torneo['mujeres'][(int)$r['torneo_id']] = (int)$r['c'];
                    }
                }
                foreach ($stats['torneos'] as &$t) {
                    $tid = (int)$t['id'];
                    $t['total_inscritos'] = $inscritos_by_torneo['total'][$tid] ?? 0;
                    $t['hombres_inscritos'] = $inscritos_by_torneo['hombres'][$tid] ?? 0;
                    $t['mujeres_inscritos'] = $inscritos_by_torneo['mujeres'][$tid] ?? 0;
                }
                unset($t);
            } else {
                $stats['total_torneos'] = 0;
                $stats['torneos'] = [];
            }

            // Métricas alineadas con «Mi organización» / OrganizacionDashboardStats (territorio, organizacion_id en torneos, inscripciones confirmadas)
            if ($organizacionRow) {
                $snap = OrganizacionDashboardStats::snapshot($pdo, $organizacionRow, $hasCodOrg);
                $stats['total_clubes'] = $snap['stats']['clubes'];
                $stats['total_afiliados'] = $snap['stats']['afiliados'];
                $stats['total_torneos'] = $snap['stats']['torneos'];
                $stats['total_inscritos'] = $snap['stats']['inscripciones'];
                $stats['torneos_activos'] = $snap['stats']['torneos_activos'];
                $stats['afiliados_by_gender'] = OrganizacionDashboardStats::affiliateGenderCounts($pdo, $organizacionRow, $hasCodOrg);
            }
            
            // Total de inscritos
            $stats['total_inscritos'] = array_sum(array_column($stats['torneos'], 'total_inscritos'));
            
            // Inscripciones a torneos activos (usa club_ids para inscritos por club)
            $stats['active_inscriptions'] = self::getActiveInscriptionsStats($supervised_club_ids);
            
        } catch (Exception $e) {
            error_log("StatisticsHelper::generateAdminClubStats error: " . $e->getMessage());
            $stats['error'] = 'Error al generar estadísticas: ' . $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Obtiene estadísticas por administrador de club (para admin_general)
     * 
     * @return array
     */
    private static function getAdminsByClubStats(): array {
        $pdo = DB::pdo();
        $admins = [];
        
        try {
            // Administradores de organización (LIMIT 50 para rendimiento)
            $stmt = $pdo->query("
                SELECT 
                    u.id as admin_id,
                    u.nombre as admin_nombre,
                    u.username as admin_username,
                    u.club_id as club_principal_id,
                    u.entidad,
                    u.status as admin_status,
                    c.nombre as club_principal_nombre
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.role = 'admin_club' AND u.status = 0
                ORDER BY u.nombre ASC
                LIMIT 50
            ");
            $admin_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($admin_list as $admin) {
                $admin_user_id = (int)($admin['admin_id'] ?? 0);
                $club_id = (int)($admin['club_principal_id'] ?? 0);
                
                try {
                    // Obtener clubes por admin_club_id (todos iguales, sin distinción)
                    $supervised_club_ids = ClubHelper::getClubesByAdminClubId($admin_user_id);
                    if (empty($supervised_club_ids) && $club_id > 0) {
                        $supervised_club_ids = ClubHelper::getClubesSupervised($club_id);
                    }
                    // club_principal_nombre: primer club para referencia (compatibilidad)
                    if (empty($admin['club_principal_nombre']) && !empty($supervised_club_ids)) {
                        $stmt = $pdo->prepare("SELECT nombre FROM clubes WHERE id = ?");
                        $stmt->execute([$supervised_club_ids[0]]);
                        $admin['club_principal_nombre'] = $stmt->fetchColumn() ?: '';
                    }
                    
                    if (empty($supervised_club_ids)) {
                        $admins[] = [
                            'admin_id' => $admin['admin_id'],
                            'admin_nombre' => $admin['admin_nombre'],
                            'admin_username' => $admin['admin_username'],
                            'club_principal_id' => $club_id,
                            'club_principal_nombre' => $admin['club_principal_nombre'],
                            'entidad' => (int)($admin['entidad'] ?? 0),
                            'supervised_clubs_count' => 0,
                            'total_users' => 0,
                            'users_by_gender' => ['hombres' => 0, 'mujeres' => 0],
                            'active_inscriptions' => ['total' => 0, 'by_gender' => ['hombres' => 0, 'mujeres' => 0], 'by_club' => []],
                            'clubs' => []
                        ];
                        continue;
                    }
                    
                    $placeholders = implode(',', array_fill(0, count($supervised_club_ids), '?'));
                    $codigosAsoc = ClubHelper::codigosAsociacionDesdeClubIds($pdo, $supervised_club_ids);
                    if ($codigosAsoc === []) {
                        $codigosAsoc = $supervised_club_ids;
                    }
                    $placeholdersCod = implode(',', array_fill(0, count($codigosAsoc), '?'));
                    
                    // Estadísticas de usuarios
                    $user_stats = ['total_users' => 0, 'hombres' => 0, 'mujeres' => 0];
                    try {
                        $stmt = $pdo->prepare("
                            SELECT 
                                COUNT(*) as total_users,
                                SUM(CASE WHEN sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                                SUM(CASE WHEN sexo = 'F' THEN 1 ELSE 0 END) as mujeres
                            FROM usuarios
                            WHERE entidad IN ($placeholdersCod)
                        ");
                        $stmt->execute($codigosAsoc);
                        $user_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user_stats;
                    } catch (Exception $e) {
                        error_log("Error obteniendo estadísticas de usuarios para admin " . $admin['admin_id'] . ": " . $e->getMessage());
                    }
                    
                    // Estadísticas de inscripciones activas
                    $inscription_stats = self::getActiveInscriptionsStats($supervised_club_ids);
                    
                    // Obtener datos de clubes (todos iguales, sin distinción)
                    $clubs_data = [];
                    foreach ($supervised_club_ids as $cid) {
                        try {
                            $stmt = $pdo->prepare("
                                SELECT 
                                    c.id,
                                    c.nombre,
                                    COUNT(DISTINCT u.id) as total_afiliados,
                                    SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                                    SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) as mujeres
                                FROM clubes c
                                LEFT JOIN usuarios u ON " . ClubHelper::sqlJoinUsuariosAfiliadosOnClub($pdo, 'c') . "
                                WHERE c.id = ?
                                GROUP BY c.id, c.nombre
                            ");
                            $stmt->execute([$cid]);
                            $club_data = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($club_data) {
                                $clubs_data[] = $club_data;
                            }
                        } catch (Exception $e) {
                            $stmt = $pdo->prepare("
                                SELECT c.id, c.nombre,
                                    COUNT(DISTINCT u.id) as total_afiliados,
                                    SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                                    SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) as mujeres
                                FROM clubes c
                                LEFT JOIN usuarios u ON " . ClubHelper::sqlJoinUsuariosAfiliadosOnClub($pdo, 'c') . "
                                WHERE c.id = ?
                                GROUP BY c.id, c.nombre
                            ");
                            $stmt->execute([$cid]);
                            $club_data = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($club_data) {
                                $clubs_data[] = $club_data;
                            }
                        }
                    }
                    
                    $admins[] = [
                        'admin_id' => $admin['admin_id'],
                        'admin_nombre' => $admin['admin_nombre'],
                        'admin_username' => $admin['admin_username'],
                        'club_principal_id' => $club_id,
                        'club_principal_nombre' => $admin['club_principal_nombre'],
                        'entidad' => (int)($admin['entidad'] ?? 0),
                        'supervised_clubs_count' => count($supervised_club_ids),
                        'total_users' => (int)($user_stats['total_users'] ?? 0),
                        'users_by_gender' => [
                            'hombres' => (int)($user_stats['hombres'] ?? 0),
                            'mujeres' => (int)($user_stats['mujeres'] ?? 0)
                        ],
                        'active_inscriptions' => $inscription_stats,
                        'clubs' => $clubs_data
                    ];
                } catch (Exception $e) {
                    error_log("Error procesando admin " . ($admin['admin_id'] ?? 'N/A') . ": " . $e->getMessage());
                    // Agregar el admin de todas formas con datos por defecto
                    $admins[] = [
                        'admin_id' => $admin['admin_id'],
                        'admin_nombre' => $admin['admin_nombre'],
                        'admin_username' => $admin['admin_username'],
                        'club_principal_id' => $club_id,
                        'club_principal_nombre' => $admin['club_principal_nombre'],
                        'entidad' => (int)($admin['entidad'] ?? 0),
                        'supervised_clubs_count' => 0,
                        'total_users' => 0,
                        'users_by_gender' => ['hombres' => 0, 'mujeres' => 0],
                        'active_inscriptions' => ['total' => 0, 'by_gender' => ['hombres' => 0, 'mujeres' => 0], 'by_club' => []],
                        'clubs' => []
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("StatisticsHelper::getAdminsByClubStats error: " . $e->getMessage());
        }
        
        return $admins;
    }
    
    /**
     * Obtiene estadísticas de inscripciones activas
     * 
     * @param array $club_ids IDs de clubes
     * @return array
     */
    private static function getActiveInscriptionsStats(array $club_ids): array {
        $pdo = DB::pdo();
        $stats = [
            'total' => 0,
            'by_gender' => ['hombres' => 0, 'mujeres' => 0],
            'by_club' => []
        ];
        
        if (empty($club_ids)) {
            return $stats;
        }
        
        try {
            $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
            
            // Obtener inscripciones de estos clubes en torneos activos
            // La tabla inscritos usa id_club (no club_id) y no tiene sexo (está en usuarios)
            $stmt = $pdo->prepare("
                SELECT 
                    i.id_club,
                    c.nombre as club_nombre,
                    COUNT(DISTINCT i.id) as total,
                    SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                    SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) as mujeres
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                INNER JOIN clubes c ON i.id_club = c.id
                INNER JOIN tournaments t ON i.torneo_id = t.id
                WHERE i.id_club IN ($placeholders)
                  AND t.estatus = 1
                  AND i.estatus = 'confirmado'
                GROUP BY i.id_club, c.nombre
                ORDER BY c.nombre ASC
            ");
            $stmt->execute($club_ids);
            $by_club = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats['by_club'] = $by_club;
            $stats['total'] = array_sum(array_column($by_club, 'total'));
            $stats['by_gender'] = [
                'hombres' => array_sum(array_column($by_club, 'hombres')),
                'mujeres' => array_sum(array_column($by_club, 'mujeres'))
            ];
            
        } catch (Exception $e) {
            error_log("StatisticsHelper::getActiveInscriptionsStats error: " . $e->getMessage());
        }
        
        return $stats;
    }
}
