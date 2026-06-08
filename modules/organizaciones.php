<?php
/**
 * Acceso a Organizaciones: listado por entidad → detalle organización → detalle club con afiliados
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/FvdConfig.php';

Auth::requireRole(['admin_club', 'admin_general', 'admin_torneo']);

$current_user = Auth::user();
$is_admin_general = Auth::isAdminGeneral();
$organizacion_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;
$entidad_id = isset($_GET['entidad_id']) ? (int)$_GET['entidad_id'] : null;

// Admin organización sin id: index.php redirige ANTES del layout. Si llegamos aquí, fallback con meta refresh si headers ya enviados.
if (!$is_admin_general && !$organizacion_id) {
    $org_id = Auth::getUserOrganizacionId();
    $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') . '/' : '';
    $target = $base . 'index.php?page=' . ($org_id ? 'organizaciones&id=' . (int)$org_id : 'mi_organizacion');
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    }
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"><p>Redirigiendo...</p>';
    exit;
}

$pdo = DB::pdo();
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
require_once __DIR__ . '/../lib/EntidadFvdCatalogo.php';
$usuarios_territorio_expr = OrganizacionDashboardStats::usuarioTerritorioCoalesceExpr($pdo);

// ---------- Vista: Detalle de club (con afiliados) ----------
if ($organizacion_id && ($club_id || $entidad_id)) {
    require_once __DIR__ . '/../lib/ClubHelper.php';
    $club = null;
    $organizacion = null;
    $afiliados = [];
    if ($is_admin_general) {
        $stmt = $pdo->prepare("
            SELECT o.*, e.nombre as entidad_nombre
            FROM organizaciones o
            LEFT JOIN entidad e ON o.entidad = e.id
            WHERE o.id = ?
        ");
        $stmt->execute([(int) $organizacion_id]);
        $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($organizacion) {
            if ($club_id > 0) {
                $stmt = $pdo->prepare('SELECT * FROM clubes WHERE id = ? AND estatus = 1');
                $stmt->execute([$club_id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($entidad_id > 0) {
                $fedCod = OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($organizacion);
                if ($has_cod_org && $fedCod > 0) {
                    $fedExpr = OrganizacionDashboardStats::clubFederacionCodigoSqlExpr($pdo, 'c');
                    $stmt = $pdo->prepare("SELECT * FROM clubes c WHERE c.entidad = ? AND c.estatus = 1 AND ({$fedExpr}) = ? LIMIT 1");
                    $stmt->execute([$entidad_id, $fedCod]);
                    $club = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM clubes WHERE entidad = ? AND estatus = 1 LIMIT 1');
                    $stmt->execute([$entidad_id]);
                    $club = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if ($club) {
                    $club_id = (int) ($club['id'] ?? 0);
                } else {
                    $club = [
                        'id' => 0,
                        'entidad' => $entidad_id,
                        'nombre' => EntidadFvdCatalogo::normalizarNombre($entidad_id),
                        'estatus' => 1,
                    ];
                }
            }
        }
    } else {
        $org_id_user = (int) Auth::getUserOrganizacionId();
        $roleUser = (string) ($current_user['role'] ?? '');
        $orgAdminLike = in_array($roleUser, ['admin_club', 'admin_torneo'], true);
        $stmtChk = $pdo->prepare('SELECT id FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
        $stmtChk->execute([(int) $organizacion_id]);
        $orgPkResuelto = (int) $stmtChk->fetchColumn();
        if ($org_id_user > 0 && $orgPkResuelto > 0 && $orgPkResuelto === $org_id_user && $orgAdminLike) {
            $stmt = $pdo->prepare("
                SELECT o.*, e.nombre as entidad_nombre
                FROM organizaciones o
                LEFT JOIN entidad e ON o.entidad = e.id
                WHERE o.id = ? AND o.estatus = 1
            ");
            $stmt->execute([(int) $organizacion_id]);
            $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $organizacion = null;
        }
        if ($organizacion) {
            if ($club_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM clubes WHERE id = ? AND estatus = 1");
                $stmt->execute([$club_id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($entidad_id > 0) {
                $fedCod = OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($organizacion);
                if ($has_cod_org && $fedCod > 0) {
                    $fedExpr = OrganizacionDashboardStats::clubFederacionCodigoSqlExpr($pdo, 'c');
                    $stmt = $pdo->prepare("SELECT * FROM clubes c WHERE c.entidad = ? AND c.estatus = 1 AND ({$fedExpr}) = ? LIMIT 1");
                    $stmt->execute([$entidad_id, $fedCod]);
                    $club = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM clubes WHERE entidad = ? AND estatus = 1 LIMIT 1');
                    $stmt->execute([$entidad_id]);
                    $club = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if ($club) {
                    $club_id = (int) ($club['id'] ?? 0);
                } elseif ($entidad_id > 0) {
                    $club = [
                        'id' => 0,
                        'entidad' => $entidad_id,
                        'nombre' => EntidadFvdCatalogo::normalizarNombre($entidad_id),
                        'estatus' => 1,
                    ];
                }
            }
            if ($club && (int) ($club['id'] ?? 0) > 0) {
                $idsPermitidos = OrganizacionDashboardStats::clubIdsForOrganizacion($pdo, $organizacion, $has_cod_org);
                if (!in_array((int) $club['id'], $idsPermitidos, true)) {
                    $club = null;
                    $organizacion = null;
                }
            }
        } else {
            $club = null;
        }
    }
    if ($club && $organizacion) {
        $afiliados_page = max(1, (int)($_GET['afiliados_page'] ?? 1));
        require_once __DIR__ . '/../lib/FvdPaginacionCompacta.php';
        $afiliados_per_page = FvdPaginacionCompacta::PER_PAGE_DEFAULT;
        $sexo = strtolower(trim((string)($_GET['sexo'] ?? 'todos')));
        if (!in_array($sexo, ['todos', 'm', 'f'], true)) {
            $sexo = 'todos';
        }
        $sexoSql = '';
        $sexoParams = [];
        if ($sexo === 'm') {
            $sexoSql = " AND UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'M'";
        } elseif ($sexo === 'f') {
            $sexoSql = " AND UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'F'";
        }

        $orderAfiliadosSql = "(CASE WHEN u.status = 0 OR u.status = 'approved' OR u.status = 1 OR TRIM(CAST(u.status AS CHAR)) = '1' THEN 0 ELSE 1 END) ASC, COALESCE(u.created_at, FROM_UNIXTIME(0)) DESC, u.nombre ASC";
        try {
            $pt = $pdo->query("SHOW TABLES LIKE 'partiresul'");
            if ($pt && $pt->rowCount() > 0) {
                $fc = $pdo->query("SHOW COLUMNS FROM partiresul WHERE Field = 'fecha_partida'");
                if ($fc && $fc->fetch(PDO::FETCH_ASSOC)) {
                    $orderAfiliadosSql = "(CASE WHEN u.status = 0 OR u.status = 'approved' OR u.status = 1 OR TRIM(CAST(u.status AS CHAR)) = '1' THEN 0 ELSE 1 END) ASC, COALESCE((SELECT MAX(pr.fecha_partida) FROM partiresul pr WHERE pr.id_usuario = u.id), '1970-01-01') DESC, COALESCE(u.created_at, FROM_UNIXTIME(0)) DESC, u.nombre ASC";
                }
            }
        } catch (Throwable $ignored) {
        }

        // Afiliados: usuarios.entidad = código de asociación (clubes.entidad)
        [$scopeSql, $scopeParams] = ClubHelper::afiliadosMatchSqlAndParams($pdo, $club, (int) ($club['id'] ?? 0));

        $stResumen = $pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'M' THEN 1 ELSE 0 END) AS hombres,
                    SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'F' THEN 1 ELSE 0 END) AS mujeres
                FROM usuarios u
                WHERE {$scopeSql}
            ");
        $stResumen->execute($scopeParams);
        $afiliados_resumen = $stResumen->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'hombres' => 0, 'mujeres' => 0];

        $stmtCount = $pdo->prepare("
                SELECT COUNT(*)
                FROM usuarios u
                WHERE {$scopeSql}
                  {$sexoSql}
            ");
        $stmtCount->execute(array_merge($scopeParams, $sexoParams));
        $afiliados_total_rows = (int) $stmtCount->fetchColumn();

        $offset = ($afiliados_page - 1) * $afiliados_per_page;
        $stmt = $pdo->prepare("
                SELECT u.id, u.cedula, u.nombre, u.email, u.celular, u.status, u.created_at
                FROM usuarios u
                WHERE {$scopeSql}
                  {$sexoSql}
                ORDER BY {$orderAfiliadosSql}
                LIMIT ? OFFSET ?
            ");
        $bindPos = 1;
        foreach ($scopeParams as $p) {
            $stmt->bindValue($bindPos++, (int) $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($bindPos++, $afiliados_per_page, PDO::PARAM_INT);
        $stmt->bindValue($bindPos++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $afiliados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $afiliados_total_pages = max(1, (int)ceil($afiliados_total_rows / $afiliados_per_page));
        if ($afiliados_page > $afiliados_total_pages) {
            $afiliados_page = $afiliados_total_pages;
        }
    }
    if (!$club || !$organizacion) {
        $rid = (int) ($_GET['id'] ?? $organizacion_id ?? 0);
        $msg = urlencode('No se encontraron datos del club o no tiene permiso para verlos.');
        if ($rid > 0) {
            header('Location: index.php?page=organizaciones&id=' . $rid . '&error=' . $msg);
        } else {
            header('Location: index.php?page=organizaciones&error=' . $msg);
        }
        exit;
    }
    include __DIR__ . '/organizaciones/club_detail.php';
    return;
}

// ---------- Vista: Detalle de organización (con clubes y estadísticas) ----------
if ($organizacion_id) {
    $organizacion = null;
    $clubes = [];
    if ($is_admin_general) {
        $stmt = $pdo->prepare("
            SELECT o.*, e.nombre as entidad_nombre, u.nombre as admin_nombre, u.email as admin_email
            FROM organizaciones o
            LEFT JOIN entidad e ON o.entidad = e.id
            LEFT JOIN usuarios u ON o.admin_user_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([(int) $organizacion_id]);
        $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $org_id = (int) Auth::getUserOrganizacionId();
        $roleUser = (string) ($current_user['role'] ?? '');
        if ($org_id > 0 && $org_id === (int) $organizacion_id && in_array($roleUser, ['admin_club', 'admin_torneo'], true)) {
            $stmt = $pdo->prepare("
                SELECT o.*, e.nombre as entidad_nombre, u.nombre as admin_nombre, u.email as admin_email
                FROM organizaciones o
                LEFT JOIN entidad e ON o.entidad = e.id
                LEFT JOIN usuarios u ON o.admin_user_id = u.id
                WHERE o.id = ? AND o.estatus = 1
            ");
            $stmt->execute([$org_id]);
            $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    if (!$organizacion) {
        $organizacion_id = null;
    }
    if ($organizacion) {
    $organizacion_ref = (int)($organizacion['cod_org'] ?? 0);
    if ($organizacion_ref <= 0) {
        $organizacion_ref = (int)($organizacion['id'] ?? $organizacion_id);
    }
    $organizacion_entidad_ref = FvdConfig::entidadTerritorioEfectivaOrganizacion($organizacion);
    $clubes_page = max(1, (int)($_GET['clubes_page'] ?? 1));
    require_once __DIR__ . '/../lib/FvdPaginacionCompacta.php';
    require_once __DIR__ . '/../lib/ClubHelper.php';
    $clubes_per_page = FvdPaginacionCompacta::PER_PAGE_DEFAULT;
    $hasUsuariosOrganizacionId = false;
    try {
        $hasUsuariosOrganizacionId = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cod_org'")->fetch();
    } catch (Exception $ignored) {
    }

    $normalizar = static function (string $s): string {
        $s = trim($s);
        $s = strtr($s, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);
        return strtoupper($s);
    };
    $esFvd = strpos($normalizar((string)($organizacion['nombre'] ?? '')), 'FEDERACION VENEZOLANA DE DOMINO') !== false;

    if ($hasUsuariosOrganizacionId && $esFvd) {
        // Asociaciones FVD: todas las entidades que tengan usuarios de la organización con numfvd > 0.
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(c.id, 0) AS id,
                COALESCE(NULLIF(TRIM(c.nombre), ''), NULLIF(TRIM(e.nombre), ''), CONCAT('Entidad ', ue.entidad)) AS nombre,
                c.delegado,
                c.telefono,
                c.direccion,
                COALESCE(c.estatus, 1) AS estatus,
                ue.entidad AS entidad
            FROM (
                SELECT DISTINCT u.entidad
                FROM usuarios u
                WHERE " . $usuarios_territorio_expr . " = ?
                  AND COALESCE(u.entidad, 0) > 0
                  AND COALESCE(u.numfvd, 0) > 0
            ) ue
            LEFT JOIN clubes c
                ON " . ($has_cod_org
                ? '(' . OrganizacionDashboardStats::clubFederacionCodigoSqlExpr($pdo, 'c') . ') = ?'
                : 'c.cod_org = ?') . "
               AND c.entidad = ue.entidad
               AND c.estatus = 1
            LEFT JOIN entidad e
                ON e.id = ue.entidad
            ORDER BY nombre ASC
        ");
        $stmt->execute($has_cod_org
            ? [$organizacion_entidad_ref, OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($organizacion)]
            : [$organizacion_entidad_ref, $organizacion_ref]);
        $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $org_entidad_eff = FvdConfig::entidadTerritorioEfectivaOrganizacion($organizacion);
        $org_entidad_where = $org_entidad_eff > 0 ? " AND COALESCE(c.entidad, 0) = {$org_entidad_eff}" : "";
        [$clubWhere, $clubParams] = OrganizacionDashboardStats::clubScopeWhereForOrganizacion($pdo, $organizacion);
        $stmt = $pdo->prepare("
            SELECT c.id, c.nombre, c.delegado, c.telefono, c.direccion, c.estatus, c.entidad
            FROM clubes c
            WHERE {$clubWhere}{$org_entidad_where}
            ORDER BY c.nombre ASC
        ");
        $stmt->execute($clubParams);
        $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Evitar duplicados: una fila por código usuarios.entidad (preferir fila con PK clubes).
    $clubes_unicos = [];
    $clubes_seen = [];
    foreach ($clubes as $cRow) {
        $entidadCod = (int) ($cRow['entidad'] ?? 0);
        $clubKey = $entidadCod > 0
            ? 'ent:' . $entidadCod
            : 'id:' . (int) ($cRow['id'] ?? 0);
        if ($entidadCod > 0 && isset($clubes_seen[$clubKey])) {
            $prevIdx = $clubes_seen[$clubKey];
            $prevId = (int) ($clubes_unicos[$prevIdx]['id'] ?? 0);
            $curId = (int) ($cRow['id'] ?? 0);
            if ($prevId <= 0 && $curId > 0) {
                $clubes_unicos[$prevIdx] = $cRow;
            }
            continue;
        }
        if (isset($clubes_seen[$clubKey]) && $entidadCod <= 0) {
            continue;
        }
        $clubes_seen[$clubKey] = count($clubes_unicos);
        $clubes_unicos[] = $cRow;
    }
    $clubes = $clubes_unicos;
    foreach ($clubes as &$cNorm) {
        $codEnt = (int) ($cNorm['entidad'] ?? 0);
        if ($codEnt > 0) {
            $cNorm['nombre'] = EntidadFvdCatalogo::normalizarNombre($codEnt, (string) ($cNorm['nombre'] ?? ''));
        }
    }
    unset($cNorm);
    // Misma regla que clubes_asociados / detalle de club: solo clubes cuyo código federación coincide con la org (COALESCE cod_org/entidad).
    // El WHERE legacy arriba (cod_org = ref OR subquery PK) podía incluir filas ajenas; al pulsar «Editar» se abría otro club en el modal.
    $idsPermitidosOrg = OrganizacionDashboardStats::clubIdsForOrganizacion($pdo, $organizacion, $has_cod_org);
    if (!empty($idsPermitidosOrg)) {
        $clubes = array_values(array_filter($clubes, static function (array $cRow) use ($idsPermitidosOrg): bool {
            $clubId = (int) ($cRow['id'] ?? 0);
            if ($clubId <= 0) {
                return true;
            }

            return in_array($clubId, $idsPermitidosOrg, true);
        }));
    }
    $stats_afiliados_sin_club = 0;
    $stats_afiliados_total = 0;
    $stats_hombres_total = 0;
    $stats_mujeres_total = 0;
    $stats_otros_total = 0;
    $stats_asociaciones = count($clubes);

    $aprobSql = OrganizacionDashboardStats::sqlUsuarioAfiliadoAprobado('u');
    $numfvdSql = $esFvd ? ' AND COALESCE(u.numfvd, 0) > 0 ' : '';

    if ($hasUsuariosOrganizacionId) {
        $stGrp = $pdo->prepare("
            SELECT
                COALESCE(u.entidad, 0) AS entidad,
                COUNT(*) AS total_afiliados,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'M' THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'F' THEN 1 ELSE 0 END) AS mujeres
            FROM usuarios u
            WHERE u.role = 'usuario'
              AND {$aprobSql}
              AND COALESCE(u.entidad, 0) > 0
              AND {$usuarios_territorio_expr} = ?
              {$numfvdSql}
            GROUP BY COALESCE(u.entidad, 0)
        ");
        $stGrp->execute([$organizacion_entidad_ref]);
        $stSin = $pdo->prepare("
            SELECT COUNT(*) AS total,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'M' THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'F' THEN 1 ELSE 0 END) AS mujeres
            FROM usuarios u
            WHERE u.role = 'usuario'
              AND {$aprobSql}
              AND COALESCE(u.entidad, 0) = 0
              AND {$usuarios_territorio_expr} = ?
              {$numfvdSql}
        ");
        $stSin->execute([$organizacion_entidad_ref]);
    } else {
        $stGrp = $pdo->prepare("
            SELECT
                COALESCE(u.entidad, 0) AS entidad,
                COUNT(*) AS total_afiliados,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'M' THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'F' THEN 1 ELSE 0 END) AS mujeres
            FROM usuarios u
            WHERE u.role = 'usuario'
              AND {$aprobSql}
              AND COALESCE(u.entidad, 0) > 0
            GROUP BY COALESCE(u.entidad, 0)
        ");
        $stGrp->execute();
        $stSin = $pdo->prepare("
            SELECT COUNT(*) AS total,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'M' THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(CAST(u.sexo AS CHAR), ''))) = 'F' THEN 1 ELSE 0 END) AS mujeres
            FROM usuarios u
            WHERE u.role = 'usuario'
              AND {$aprobSql}
              AND COALESCE(u.entidad, 0) = 0
        ");
        $stSin->execute();
    }

    $statsPorEntidad = [];
    foreach ($stGrp->fetchAll(PDO::FETCH_ASSOC) as $rowGrp) {
        $statsPorEntidad[(int) ($rowGrp['entidad'] ?? 0)] = $rowGrp;
    }
    $rowSin = $stSin->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats_afiliados_sin_club = (int) ($rowSin['total'] ?? 0);

    foreach ($clubes as &$club) {
        $entidadClub = (int) ($club['entidad'] ?? 0);
        if ($entidadClub <= 0) {
            $entidadClub = ClubHelper::codigoAsociacionDesdeClubRow($club);
        }
        $rowClub = $statsPorEntidad[$entidadClub] ?? null;
        $club['total_afiliados'] = (int) ($rowClub['total_afiliados'] ?? 0);
        $club['hombres'] = (int) ($rowClub['hombres'] ?? 0);
        $club['mujeres'] = (int) ($rowClub['mujeres'] ?? 0);
    }
    unset($club);

    foreach ($statsPorEntidad as $rowSum) {
        $stats_afiliados_total += (int) ($rowSum['total_afiliados'] ?? 0);
        $stats_hombres_total += (int) ($rowSum['hombres'] ?? 0);
        $stats_mujeres_total += (int) ($rowSum['mujeres'] ?? 0);
    }
    $stats_afiliados_total += $stats_afiliados_sin_club;
    $stats_hombres_total += (int) ($rowSin['hombres'] ?? 0);
    $stats_mujeres_total += (int) ($rowSin['mujeres'] ?? 0);
    $stats_otros_total = max(0, $stats_afiliados_total - $stats_hombres_total - $stats_mujeres_total);

    // Paginación de clubes.
    $clubes_total_rows = count($clubes);
    $clubes_total_pages = max(1, (int)ceil($clubes_total_rows / $clubes_per_page));
    if ($clubes_page > $clubes_total_pages) {
        $clubes_page = $clubes_total_pages;
    }
    $clubes_offset = ($clubes_page - 1) * $clubes_per_page;
    $clubes_paginados = array_slice($clubes, $clubes_offset, $clubes_per_page);
    $stats_operadores = 0;
    $stats_admin_torneo = 0;
    $entidad_ids_asoc = array_values(array_unique(array_filter(array_map('intval', array_column($clubes, 'entidad')))));
    if ($hasUsuariosOrganizacionId && !empty($entidad_ids_asoc)) {
        $phEnt = implode(',', array_fill(0, count($entidad_ids_asoc), '?'));
        $staffAprob = OrganizacionDashboardStats::sqlUsuarioStaffAprobado('u');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM usuarios u
            WHERE u.role = 'operador'
              AND {$staffAprob}
              AND {$usuarios_territorio_expr} = ?
              AND COALESCE(u.entidad, 0) IN ({$phEnt})
        ");
        $stmt->execute(array_merge([$organizacion_entidad_ref], $entidad_ids_asoc));
        $stats_operadores = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM usuarios u
            WHERE u.role = 'admin_torneo'
              AND {$staffAprob}
              AND {$usuarios_territorio_expr} = ?
              AND COALESCE(u.entidad, 0) IN ({$phEnt})
        ");
        $stmt->execute(array_merge([$organizacion_entidad_ref], $entidad_ids_asoc));
        $stats_admin_torneo = (int) $stmt->fetchColumn();
    } elseif (!empty($clubes)) {
        $entidad_ids_asoc = ClubHelper::codigosAsociacionDesdeClubIds($pdo, array_column($clubes, 'id'));
        if ($entidad_ids_asoc === []) {
            $entidad_ids_asoc = array_values(array_unique(array_filter(array_map('intval', array_column($clubes, 'entidad')))));
        }
        if (!empty($entidad_ids_asoc)) {
            $phEnt = implode(',', array_fill(0, count($entidad_ids_asoc), '?'));
            $staffAprob = OrganizacionDashboardStats::sqlUsuarioStaffAprobado('u');
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM usuarios u
                WHERE u.role = 'operador'
                  AND {$staffAprob}
                  AND COALESCE(u.entidad, 0) IN ({$phEnt})
            ");
            $stmt->execute($entidad_ids_asoc);
            $stats_operadores = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM usuarios u
                WHERE u.role = 'admin_torneo'
                  AND {$staffAprob}
                  AND COALESCE(u.entidad, 0) IN ({$phEnt})
            ");
            $stmt->execute($entidad_ids_asoc);
            $stats_admin_torneo = (int) $stmt->fetchColumn();
        }
    }
    $org_dashboard_snap = OrganizacionDashboardStats::snapshot($pdo, $organizacion, $has_cod_org);
    include __DIR__ . '/organizaciones/org_detail.php';
    return;
    }
}

// ---------- Vista: Listado de organizaciones de una entidad (entidad_id) ----------
if ($is_admin_general && !$organizacion_id && !$club_id && $entidad_id > 0) {
    $entidad_nombre = 'Entidad ' . $entidad_id;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        $codeCol = $nameCol = null;
        foreach ($cols as $c) {
            $f = strtolower($c['Field'] ?? '');
            if (!$codeCol && in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) $codeCol = $f;
            if (!$nameCol && in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) $nameCol = $f;
        }
        if ($codeCol && $nameCol) {
            $stmt = $pdo->prepare("SELECT {$nameCol} AS nombre FROM entidad WHERE {$codeCol} = ?");
            $stmt->execute([$entidad_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['nombre'])) $entidad_nombre = $row['nombre'];
        }
    } catch (Exception $e) {}
    $organizaciones = [];
    try {
        // Caso especial: entidad 32 muestra asociaciones derivadas de usuarios con numfvd > 0
        // agrupadas por entidad/asociación del usuario.
        if ($entidad_id === 32) {
            $stmt = $pdo->query("
                SELECT
                    u.entidad AS entidad_asociacion,
                    COALESCE(NULLIF(TRIM(e.nombre), ''), CONCAT('Entidad ', u.entidad)) AS nombre_asociacion,
                    COUNT(*) AS total_afiliados,
                    SUM(CASE WHEN UPPER(COALESCE(u.sexo,'M'))='M' THEN 1 ELSE 0 END) AS hombres,
                    SUM(CASE WHEN UPPER(COALESCE(u.sexo,'M'))='F' THEN 1 ELSE 0 END) AS mujeres
                FROM usuarios u
                LEFT JOIN entidad e ON e.id = u.entidad
                WHERE COALESCE(u.numfvd, 0) > 0
                  AND COALESCE(u.entidad, 0) > 0
                GROUP BY u.entidad, e.nombre
                ORDER BY nombre_asociacion ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $organizaciones = [];
            foreach ($rows as $r) {
                $organizaciones[] = [
                    'id' => 0, // fila estadística (sin organización física directa)
                    'nombre' => (string)($r['nombre_asociacion'] ?? ''),
                    'estatus' => 1,
                    'total_clubes' => 1,
                    'total_torneos' => 0,
                    'total_afiliados' => (int)($r['total_afiliados'] ?? 0),
                    'hombres' => (int)($r['hombres'] ?? 0),
                    'mujeres' => (int)($r['mujeres'] ?? 0),
                    'entidad_asociacion' => (int)($r['entidad_asociacion'] ?? 0),
                ];
            }
        } else {
            $clubMatchOo = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'c', 'o');
            $stmt = $pdo->prepare("
                SELECT o.id, o.nombre, o.estatus,
                       (SELECT COUNT(DISTINCT c.id) FROM clubes c WHERE {$clubMatchOo} AND c.estatus = 1 AND COALESCE(c.entidad, 0) = COALESCE(o.entidad, 0)) as total_clubes,
                       (SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE " . ($has_cod_org ? "(t.club_responsable = o.id OR t.club_responsable = o.cod_org)" : "t.club_responsable = o.id") . " AND COALESCE(t.entidad, 0) = COALESCE(o.entidad, 0)) as total_torneos
                FROM organizaciones o
                WHERE o.entidad = ?
                ORDER BY o.estatus DESC, o.nombre ASC
            ");
            $stmt->execute([$entidad_id]);
            $organizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $hasUsuariosOrganizacionId = false;
        try {
            $hasUsuariosOrganizacionId = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cod_org'")->fetch();
        } catch (Exception $ignored) {
        }
        $normalizar = static function (string $s): string {
            $s = trim($s);
            $s = strtr($s, [
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            ]);
            return strtoupper($s);
        };
        foreach ($organizaciones as &$org) {
            if ($entidad_id === 32) {
                // Ya viene precalculado por asociación (entidad) para este caso especial.
                continue;
            }
            if ($hasUsuariosOrganizacionId) {
                $orgId = (int)($org['id'] ?? 0);
                $orgNombreNorm = $normalizar((string)($org['nombre'] ?? ''));
                $orgEsFvd = strpos($orgNombreNorm, 'FEDERACION VENEZOLANA DE DOMINO') !== false;
                $stmt2 = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_afiliados,
                        SUM(CASE WHEN UPPER(COALESCE(u.sexo,'M'))='M' THEN 1 ELSE 0 END) AS hombres,
                        SUM(CASE WHEN UPPER(COALESCE(u.sexo,'M'))='F' THEN 1 ELSE 0 END) AS mujeres
                    FROM usuarios u
                    WHERE " . $usuarios_territorio_expr . " = ?
                      AND COALESCE(u.entidad, 0) = ?
                      " . ($orgEsFvd ? " AND COALESCE(u.numfvd, 0) > 0 " : "") . "
                ");
                $stmt2->execute([(int)$entidad_id, $entidad_id]);
                $r2 = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
                $org['total_afiliados'] = (int)($r2['total_afiliados'] ?? 0);
                $org['hombres'] = (int)($r2['hombres'] ?? 0);
                $org['mujeres'] = (int)($r2['mujeres'] ?? 0);
            } else {
                $matchScope = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'c', 'oscope');
                $stmt2 = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_afiliados,
                        SUM(CASE WHEN UPPER(COALESCE(u.sexo,'M'))='M' THEN 1 ELSE 0 END) AS hombres,
                        SUM(CASE WHEN UPPER(COALESCE(u.sexo,'M'))='F' THEN 1 ELSE 0 END) AS mujeres
                    FROM usuarios u
                    INNER JOIN clubes c ON u.club_id = c.id
                    INNER JOIN organizaciones oscope ON oscope.id = ?
                    WHERE c.estatus = 1
                      AND ({$matchScope})
                ");
                $stmt2->execute([(int) ($org['id'] ?? 0)]);
                $r2 = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
                $org['total_afiliados'] = (int)($r2['total_afiliados'] ?? 0);
                $org['hombres'] = (int)($r2['hombres'] ?? 0);
                $org['mujeres'] = (int)($r2['mujeres'] ?? 0);
            }
        }
        unset($org);
        $entidad_totales = [
            'afiliados' => (int)array_sum(array_column($organizaciones, 'total_afiliados')),
            'hombres' => (int)array_sum(array_column($organizaciones, 'hombres')),
            'mujeres' => (int)array_sum(array_column($organizaciones, 'mujeres')),
        ];
    } catch (Exception $e) {}
    include __DIR__ . '/organizaciones/listado_organizaciones_entidad.php';
    return;
}

// ---------- Vista: Listado de entidades con resumen (solo admin_general, sin id ni entidad_id) ----------
if ($is_admin_general && !$organizacion_id && !$club_id) {
    $resumen_entidades = [];
    try {
        $stmt = $pdo->query("SELECT DISTINCT entidad FROM organizaciones WHERE entidad IS NOT NULL AND entidad != 0 ORDER BY entidad ASC");
        $entidad_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $entidad_codes = [];
    }
    $entidad_nombres = [];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        $codeCol = $nameCol = null;
        foreach ($cols as $c) {
            $f = strtolower($c['Field'] ?? '');
            if (!$codeCol && in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) $codeCol = $f;
            if (!$nameCol && in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) $nameCol = $f;
        }
        if ($codeCol && $nameCol && $entidad_codes) {
            $placeholders = implode(',', array_fill(0, count($entidad_codes), '?'));
            $stmt = $pdo->prepare("SELECT {$codeCol} AS cod, {$nameCol} AS nombre FROM entidad WHERE {$codeCol} IN ($placeholders)");
            $stmt->execute($entidad_codes);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $entidad_nombres[$r['cod']] = $r['nombre'];
        }
    } catch (Exception $e) {}
    foreach ($entidad_codes as $cod) {
        $nombre = $entidad_nombres[$cod] ?? ('Entidad ' . $cod);
        $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE entidad = ?");
        $stmt->execute([$cod]);
        $org_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $total_organizaciones = count($org_ids);
        $total_clubes = $total_afiliados = $total_torneos = 0;
        if ($org_ids) {
            $ph = implode(',', array_fill(0, count($org_ids), '?'));
            $clubMatchExist = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'c', 'o');
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT c.id) FROM clubes c
                WHERE c.estatus = 1
                  AND EXISTS (SELECT 1 FROM organizaciones o WHERE o.id IN ($ph) AND ({$clubMatchExist}))
            ");
            $stmt->execute($org_ids);
            $total_clubes = (int) $stmt->fetchColumn();
            if ($has_cod_org) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable IN ($ph) OR club_responsable IN (SELECT cod_org FROM organizaciones WHERE id IN ($ph))");
                $stmt->execute(array_merge($org_ids, $org_ids));
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable IN ($ph)");
                $stmt->execute($org_ids);
            }
            $total_torneos = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM usuarios u
                INNER JOIN clubes c ON u.club_id = c.id
                WHERE c.estatus = 1 AND u.role = 'usuario' AND u.status = 0
                  AND EXISTS (SELECT 1 FROM organizaciones o WHERE o.id IN ($ph) AND ({$clubMatchExist}))
            ");
            $stmt->execute($org_ids);
            $total_afiliados = (int) $stmt->fetchColumn();
        }
        $resumen_entidades[] = [
            'entidad_id' => $cod,
            'entidad_nombre' => $nombre,
            'total_organizaciones' => $total_organizaciones,
            'total_clubes' => $total_clubes,
            'total_afiliados' => $total_afiliados,
            'total_torneos' => $total_torneos,
        ];
    }
    include __DIR__ . '/organizaciones/listado_entidades.php';
    return;
}

// ---------- Vista: Listado por entidad (agrupado, fallback) ----------
try {
    $clubMatchList = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'c', 'o');
    $stmt = $pdo->query("
        SELECT o.*, e.id as entidad_id, e.nombre as entidad_nombre,
               (SELECT COUNT(DISTINCT c.id) FROM clubes c WHERE {$clubMatchList} AND c.estatus = 1 AND COALESCE(c.entidad, 0) = COALESCE(o.entidad, 0)) as total_clubes,
               (SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE " . ($has_cod_org ? "(t.club_responsable = o.id OR t.club_responsable = o.cod_org)" : "t.club_responsable = o.id") . " AND COALESCE(t.entidad, 0) = COALESCE(o.entidad, 0)) as total_torneos
        FROM organizaciones o
        LEFT JOIN entidad e ON o.entidad = e.id
        WHERE o.estatus = 1
        ORDER BY e.nombre ASC, o.nombre ASC
    ");
    $todas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $todas = [];
}
$por_entidad = [];
foreach ($todas as $org) {
    $key = $org['entidad_nombre'] ?? 'Sin entidad';
    if (!isset($por_entidad[$key])) {
        $por_entidad[$key] = [];
    }
    $por_entidad[$key][] = $org;
}
include __DIR__ . '/organizaciones/list_by_entidad.php';
