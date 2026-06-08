<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/admin_general_auth.php';
require_once __DIR__ . '/../public/simple_image_config.php';
require_once __DIR__ . '/../lib/Pagination.php';
require_once __DIR__ . '/../lib/FvdPaginacionCompacta.php';

// Verificar permisos - admin_club puede acceder a ver detalles de sus clubes
$current_user = Auth::user();
require_once __DIR__ . '/../lib/ClubHelper.php';

// Obtener datos para la vista
$action = $_GET['action'] ?? 'list';

// create, store, edit, update, destroy solo para admin_general
$crud_actions = ['new', 'save', 'edit', 'update', 'delete'];
if (in_array($action, $crud_actions, true)) {
    requireAdminGeneral();
}

// Si es admin_club y está intentando ver detalles o editar, verificar permisos después
// Si es admin_club y está en list u otra acción, redirigir
if ($current_user['role'] === 'admin_club' && !Auth::isAdminGeneral()) {
    if ($action !== 'detail' && $action !== 'afiliado_detail' && $action !== 'edit') {
        // Redirigir a clubes asociados solo si no es detalle
        echo '<script>window.location.href = "index.php?page=clubes_asociados";</script>';
        exit;
    }
    // Si es detail, continuar y verificar permisos más adelante
} else {
    Auth::requireRole(['admin_general', 'admin_torneo']);
}
$id = $_GET['id'] ?? null;
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;
$info_message = isset($_GET['info']) ? (string)$_GET['info'] : null;
$has_cod_org = false;
try {
    $has_cod_org = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join_expr = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

// Procesar eliminación si se solicita
if ($action === 'delete' && $id) {
    try {
        $club_id = (int)$id;
        
        // Obtener nombre y logo del club antes de eliminarlo
        $stmt = DB::pdo()->prepare("SELECT nombre, logo FROM clubes WHERE id = ?");
        $stmt->execute([$club_id]);
        $club_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$club_to_delete) {
            throw new Exception('Club no encontrado');
        }
        
        // Verificar permisos para eliminar
        $current_user = Auth::user();
        if ($current_user['role'] === 'admin_club' && $current_user['club_id'] != $club_id) {
            throw new Exception('No tienes permisos para eliminar este club');
        }
        
        // Eliminar el club
        $stmt = DB::pdo()->prepare("DELETE FROM clubes WHERE id = ?");
        $result = $stmt->execute([$club_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Eliminar logo si existe
            if ($club_to_delete['logo'] && file_exists(__DIR__ . '/../' . $club_to_delete['logo'])) {
                @unlink(__DIR__ . '/../' . $club_to_delete['logo']);
            }
            header('Location: index.php?page=clubs&success=' . urlencode('Club "' . $club_to_delete['nombre'] . '" eliminado exitosamente'));
        } else {
            throw new Exception('No se pudo eliminar el club');
        }
    } catch (Exception $e) {
        header('Location: index.php?page=clubs&error=' . urlencode('Error al eliminar: ' . $e->getMessage()));
    }
    exit;
}

// Obtener datos para edición o vista
$club = null;
$afiliado_detail = null;
$afiliado_torneos = [];

// Obtener datos del afiliado si se solicita
if ($action === 'afiliado_detail') {
    $club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    
    if ($club_id && $user_id) {
        try {
            // Obtener datos del club
            $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id = ?");
            $stmt->execute([$club_id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($club) {
                [$scopeSqlAf, $scopeParamsAf] = ClubHelper::afiliadosMatchSqlAndParams(DB::pdo(), $club, $club_id);
                $stmt = DB::pdo()->prepare("
                    SELECT u.*,
                           (SELECT COUNT(DISTINCT i2.torneo_id) FROM inscritos i2 WHERE i2.id_usuario = u.id) AS total_torneos
                    FROM usuarios u
                    WHERE u.id = ?
                      AND ({$scopeSqlAf})
                ");
                $stmt->execute(array_merge([$user_id], $scopeParamsAf));
                $afiliado_detail = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$afiliado_detail) {
                    $error_message = "Afiliado no encontrado o no pertenece a este club";
                    $action = 'detail';
                    $id = $club_id;
                } else {
                    // Obtener torneos del afiliado
                    $stmt = DB::pdo()->prepare("
                        SELECT 
                            t.id as torneo_id,
                            t.nombre as torneo_nombre,
                            t.fechator as torneo_fecha,
                            t.costo as torneo_costo,
                            t.clase,
                            t.modalidad,
                            COALESCE(c.nombre, o.nombre) as club_nombre,
                            i.id as inscripcion_id,
                            i.posicion,
                            i.puntos,
                            i.estatus as inscripcion_estatus,
                            i.fecha_inscripcion,
                            u.categ
                        FROM inscritos i
                        INNER JOIN tournaments t ON i.torneo_id = t.id
                        LEFT JOIN clubes c ON i.id_club = c.id
                        {$org_join_expr}
                        LEFT JOIN usuarios u ON i.id_usuario = u.id
                        WHERE i.id_usuario = ?
                        ORDER BY t.fechator DESC
                    ");
                    $stmt->execute([$user_id]);
                    $afiliado_torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                $error_message = "Club no encontrado";
                $action = 'list';
            }
        } catch (Exception $e) {
            $error_message = "Error al cargar el afiliado: " . $e->getMessage();
            if ($club_id) {
                $action = 'detail';
                $id = $club_id;
            } else {
                $action = 'list';
            }
        }
    } else {
        $error_message = "Parámetros inválidos";
        $action = 'list';
    }
}

if (($action === 'edit' || $action === 'view' || $action === 'detail') && $id) {
    try {
        $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id = ?");
        $stmt->execute([(int)$id]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$club) {
            $error_message = "Club no encontrado";
            $action = 'list';
        } else {
            // Verificar permisos para admin_club (detail/edit/view)
            if ($current_user['role'] === 'admin_club') {
                $can_access = ClubHelper::isClubManagedByAdmin((int)$current_user['id'], (int)$id)
                    || (!empty($current_user['club_id']) && ClubHelper::isClubSupervised($current_user['club_id'], (int)$id));
                if (!$can_access) {
                    $error_message = "No tiene permisos para acceder a este club";
                    $action = 'list';
                    $club = null;
                }
            }
        }
        
        if ($club && $action === 'detail') {
                // Inicializar variables para detalle
                $club_afiliados = [];
                $club_stats = [];
                $pagination_afiliados = null;
                
                $genero_filter = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : null;
                if ($genero_filter !== 'M' && $genero_filter !== 'F') {
                    $genero_filter = null;
                }
                
                // Obtener parámetro de origen para el redirect
                $from_page = isset($_GET['from']) ? $_GET['from'] : null;
                $from_admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null;

                $club_pk = (int) ($club['id'] ?? 0);
                [$afiliados_scope_sql, $afiliados_scope_params] = ClubHelper::afiliadosMatchSqlAndParams(DB::pdo(), $club, $club_pk);
                
                // Total: usuarios donde entidad = id del club (sin filtrar rol ni status)
                $count_query = "
                    SELECT COUNT(DISTINCT u.id)
                    FROM usuarios u
                    WHERE {$afiliados_scope_sql}
                ";
                
                $count_params = $afiliados_scope_params;
                if ($genero_filter !== null) {
                    $count_query .= ' AND u.sexo = ?';
                    $count_params[] = $genero_filter;
                }
                
                $stmt = DB::pdo()->prepare($count_query);
                $stmt->execute($count_params);
                $total_afiliados = (int)$stmt->fetchColumn();
                
                // Configurar paginación
                $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
                $per_page = FvdPaginacionCompacta::PER_PAGE_DEFAULT;
                $pagination_afiliados = new Pagination($total_afiliados, $current_page, $per_page);
                
                $afiliados_query = "
                    SELECT 
                        u.*,
                        (SELECT COUNT(DISTINCT i2.torneo_id) FROM inscritos i2 WHERE i2.id_usuario = u.id) AS total_torneos
                    FROM usuarios u
                    WHERE {$afiliados_scope_sql}
                ";
                
                $afiliados_params = $afiliados_scope_params;
                if ($genero_filter !== null) {
                    $afiliados_query .= ' AND u.sexo = ?';
                    $afiliados_params[] = $genero_filter;
                }
                
                $afiliados_query .= " ORDER BY u.status ASC, u.nombre ASC LIMIT {$pagination_afiliados->getLimit()} OFFSET {$pagination_afiliados->getOffset()}";
                
                $stmt = DB::pdo()->prepare($afiliados_query);
                $stmt->execute($afiliados_params);
                $club_afiliados = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Estadísticas (mismo universo que el listado; género solo si filtro activo)
                $stats_query = "
                    SELECT 
                        COUNT(*) as total_afiliados,
                        SUM(CASE WHEN u.status = 0 THEN 1 ELSE 0 END) as total_activos,
                        SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                        SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) as mujeres
                    FROM usuarios u
                    WHERE {$afiliados_scope_sql}
                ";
                
                $stats_params = $afiliados_scope_params;
                if ($genero_filter !== null) {
                    $stats_query .= ' AND u.sexo = ?';
                    $stats_params[] = $genero_filter;
                }
                
                $stmt = DB::pdo()->prepare($stats_query);
                $stmt->execute($stats_params);
                
                $club_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $error_message = "Error al cargar el club: " . $e->getMessage();
            $action = 'list';
        }
}

// Función para cargar mapa de entidades (reutilizada de home.php)
function loadEntidadMap(): array {
    $map = [];
    try {
        $pdo = DB::pdo();
        $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        $codeCandidates = ['codigo','cod_entidad','id','code'];
        $nameCandidates = ['nombre','descripcion','entidad','nombre_entidad'];
        $codeCol = null; $nameCol = null;
        foreach ($cols as $c) {
            $f = strtolower($c['Field'] ?? $c['field'] ?? '');
            if (!$codeCol && in_array($f, $codeCandidates, true)) { $codeCol = $f; }
            if (!$nameCol && in_array($f, $nameCandidates, true)) { $nameCol = $f; }
        }
        if ($codeCol && $nameCol) {
            $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
            $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    } catch (Exception $e) {
        error_log("clubs.php: no se pudo cargar entidades: " . $e->getMessage());
    }
    return $map;
}

// Obtener lista para vista de lista con paginación
$clubs_list = [];
$clubs_by_entidad = [];
$pagination = null;
$entidad_map = loadEntidadMap();

// Para formulario nuevo club: organizaciones (admin_general elige; admin_torneo usa la de su club)
$organizaciones_list = [];
$organizacion_id_new = null;
if ($action === 'new' && (Auth::isAdminGeneral() || $current_user['role'] === 'admin_torneo')) {
    if (Auth::isAdminGeneral()) {
        $stmt = DB::pdo()->query("SELECT id, nombre FROM organizaciones WHERE estatus = 1 ORDER BY nombre ASC");
        $organizaciones_list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $club_id_user = (int)($current_user['club_id'] ?? 0);
        if ($club_id_user > 0) {
            $stmt = DB::pdo()->prepare("SELECT cod_org FROM clubes WHERE id = ?");
            $stmt->execute([$club_id_user]);
            $organizacion_id_new = $stmt->fetchColumn() ? (int)$stmt->fetchColumn() : null;
        }
    }
}

if ($action === 'list') {
    try {
        // Obtener TODOS los clubes con información del admin_club
        $select_query = "
            SELECT 
                c.*,
                c.admin_club_id,
                admin.id as admin_id,
                admin.nombre as admin_nombre,
                admin.username as admin_username,
                admin.email as admin_email,
                admin.celular as admin_celular,
                admin.entidad as admin_entidad,
                COUNT(DISTINCT u.id) as total_afiliados,
                SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) as mujeres
            FROM clubes c
            LEFT JOIN usuarios admin ON c.admin_club_id = admin.id AND admin.role = 'admin_club'
            LEFT JOIN usuarios u ON " . ClubHelper::sqlJoinUsuariosAfiliadosOnClub(DB::pdo(), 'c') . "
            GROUP BY c.id
            ORDER BY admin.entidad ASC, admin.nombre ASC, c.nombre ASC
        ";
        $stmt = DB::pdo()->prepare($select_query);
        $stmt->execute();
        $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estructura jerárquica: Entidad -> Admin -> Clubes
        // $clubs_by_entidad[entidad_id]['admins'][admin_id]['clubes'][]
        foreach ($clubs_list as $club) {
            $entidad_id = (int)($club['admin_entidad'] ?? 0);
            $admin_id = (int)($club['admin_id'] ?? 0);
            $admin_nombre = $club['admin_nombre'] ?? 'Sin Administrador';
            
            // Inicializar entidad si no existe
            if (!isset($clubs_by_entidad[$entidad_id])) {
                $clubs_by_entidad[$entidad_id] = [
                    'entidad_nombre' => $entidad_map[$entidad_id] ?? ($entidad_id > 0 ? "Entidad {$entidad_id}" : 'Sin Entidad'),
                    'entidad_id' => $entidad_id,
                    'admins' => [],
                    'total_clubes' => 0,
                    'total_afiliados' => 0,
                    'total_hombres' => 0,
                    'total_mujeres' => 0
                ];
            }
            
            // Inicializar admin si no existe dentro de la entidad
            if (!isset($clubs_by_entidad[$entidad_id]['admins'][$admin_id])) {
                $clubs_by_entidad[$entidad_id]['admins'][$admin_id] = [
                    'admin_id' => $admin_id,
                    'admin_nombre' => $admin_nombre,
                    'admin_username' => $club['admin_username'] ?? '',
                    'admin_email' => $club['admin_email'] ?? '',
                    'admin_celular' => $club['admin_celular'] ?? '',
                    'clubes' => [],
                    'total_clubes' => 0,
                    'total_afiliados' => 0,
                    'total_hombres' => 0,
                    'total_mujeres' => 0
                ];
            }
            
            // Agregar club al admin
            $clubs_by_entidad[$entidad_id]['admins'][$admin_id]['clubes'][] = $club;
            
            // Acumular estadísticas del admin
            $clubs_by_entidad[$entidad_id]['admins'][$admin_id]['total_clubes']++;
            $clubs_by_entidad[$entidad_id]['admins'][$admin_id]['total_afiliados'] += (int)($club['total_afiliados'] ?? 0);
            $clubs_by_entidad[$entidad_id]['admins'][$admin_id]['total_hombres'] += (int)($club['hombres'] ?? 0);
            $clubs_by_entidad[$entidad_id]['admins'][$admin_id]['total_mujeres'] += (int)($club['mujeres'] ?? 0);
            
            // Acumular estadísticas de la entidad
            $clubs_by_entidad[$entidad_id]['total_clubes']++;
            $clubs_by_entidad[$entidad_id]['total_afiliados'] += (int)($club['total_afiliados'] ?? 0);
            $clubs_by_entidad[$entidad_id]['total_hombres'] += (int)($club['hombres'] ?? 0);
            $clubs_by_entidad[$entidad_id]['total_mujeres'] += (int)($club['mujeres'] ?? 0);
        }
        
        // Ordenar entidades por nombre
        uksort($clubs_by_entidad, function($a, $b) use ($entidad_map) {
            $nombre_a = $entidad_map[$a] ?? ($a > 0 ? "Entidad {$a}" : 'Sin Entidad');
            $nombre_b = $entidad_map[$b] ?? ($b > 0 ? "Entidad {$b}" : 'Sin Entidad');
            return strcmp($nombre_a, $nombre_b);
        });
        
        // Ordenar admins dentro de cada entidad por nombre
        foreach ($clubs_by_entidad as &$entidad_data) {
            uasort($entidad_data['admins'], function($a, $b) {
                return strcmp($a['admin_nombre'] ?? '', $b['admin_nombre'] ?? '');
            });
        }
        unset($entidad_data);
        
    } catch (Exception $e) {
        $error_message = "Error al cargar los clubs: " . $e->getMessage();
    }
}
?>

<div class="container-fluid<?= in_array($action, ['list', 'detail', 'afiliado_detail'], true) ? ' fvd-app-page fvd-listado-page' : '' ?>">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-building me-2"></i>Clubs
                    </h1>
                    <p class="text-muted mb-0">Administra los clubs del sistema</p>
                </div>
                <div>
                    <?php if ($action === 'list'): ?>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-success" onclick="exportarClubsExcel()" 
                                    <?= empty($clubs_list) ? 'disabled' : '' ?>>
                                <i class="fas fa-file-excel me-2"></i>Excel
                            </button>
                            <a href="../report_clubs.php" class="btn btn-danger">
                                <i class="fas fa-file-pdf me-2"></i>PDF
                            </a>
                            <a href="index.php?page=clubs&action=new" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Nuevo Club
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Alertas -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($info_message !== null && $info_message !== ''): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($info_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($action === 'list' && !empty($clubs_by_entidad)): ?>
            <!-- Tabla Resumen: Entidad → Admin Organización → Clubes Registrados -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Resumen: Clubes Registrados por Entidad y Administrador</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th><i class="fas fa-map-marked-alt me-1"></i>Entidad</th>
                                    <th><i class="fas fa-user-shield me-1"></i>Administrador de organización</th>
                                    <th class="text-center"><i class="fas fa-building me-1"></i>Clubes</th>
                                    <th class="text-center"><i class="fas fa-users me-1"></i>Afiliados</th>
                                    <th class="text-center"><i class="fas fa-mars text-primary me-1"></i>H</th>
                                    <th class="text-center"><i class="fas fa-venus text-danger me-1"></i>M</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_general_clubes = 0;
                                $total_general_afiliados = 0;
                                $total_general_hombres = 0;
                                $total_general_mujeres = 0;
                                
                                foreach ($clubs_by_entidad as $entidad_id => $entidad_data): 
                                    $first_admin_in_entidad = true;
                                    $num_admins = count($entidad_data['admins']);
                                    
                                    foreach ($entidad_data['admins'] as $admin_id => $admin_data):
                                        $total_general_clubes += $admin_data['total_clubes'];
                                        $total_general_afiliados += $admin_data['total_afiliados'];
                                        $total_general_hombres += $admin_data['total_hombres'];
                                        $total_general_mujeres += $admin_data['total_mujeres'];
                                ?>
                                <tr>
                                    <?php if ($first_admin_in_entidad): ?>
                                    <td rowspan="<?= $num_admins ?>" class="align-middle" style="background-color: #e7f1ff; border-left: 4px solid #0d6efd;">
                                        <strong><?= htmlspecialchars($entidad_data['entidad_nombre']) ?></strong>
                                        <br><small class="text-muted">Total: <?= $entidad_data['total_clubes'] ?> clubes</small>
                                    </td>
                                    <?php $first_admin_in_entidad = false; endif; ?>
                                    <td>
                                        <?php if ($admin_id > 0): ?>
                                            <a href="index.php?page=admin_clubs&view=detail&admin_id=<?= $admin_id ?>" class="text-decoration-none">
                                                <i class="fas fa-user-tie me-1 text-success"></i>
                                                <?= htmlspecialchars($admin_data['admin_nombre']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-user-slash me-1"></i>Sin Administrador</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info fs-6"><?= $admin_data['total_clubes'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= number_format($admin_data['total_afiliados']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= number_format($admin_data['total_hombres']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= number_format($admin_data['total_mujeres']) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; endforeach; ?>
                            </tbody>
                            <tfoot class="table-dark">
                                <tr>
                                    <th colspan="2" class="text-end">TOTALES GENERALES:</th>
                                    <th class="text-center"><span class="badge bg-light text-dark fs-6"><?= $total_general_clubes ?></span></th>
                                    <th class="text-center"><span class="badge bg-light text-dark"><?= number_format($total_general_afiliados) ?></span></th>
                                    <th class="text-center"><span class="badge bg-primary"><?= number_format($total_general_hombres) ?></span></th>
                                    <th class="text-center"><span class="badge bg-danger"><?= number_format($total_general_mujeres) ?></span></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
    <!-- Vista de Lista Jerárquica: Entidad → Administrador → Clubes -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($clubs_list)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No hay clubs registrados.
                    <a href="index.php?page=clubs&action=new" class="alert-link">Crear el primer club</a>
                </div>
            <?php else: ?>
                <?php foreach ($clubs_by_entidad as $entidad_id => $entidad_data): ?>
                    <!-- Cabecera de Entidad (id para enlaces desde “asociación” → clubes) -->
                    <div class="mb-4" id="asociacion-<?= (int)$entidad_id ?>">
                        <div class="py-3 px-4 rounded-top" style="background: linear-gradient(90deg, #0d6efd 0%, #0a58ca 100%); color: white;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-map-marked-alt me-2"></i>
                                    <span class="fs-5 fw-bold"><?= htmlspecialchars($entidad_data['entidad_nombre']) ?></span>
                                    <?php if ($entidad_id > 0): ?>
                                        <small class="ms-2 opacity-75">(Código: <?= $entidad_id ?>)</small>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-3">
                                    <span><i class="fas fa-user-tie me-1"></i><?= count($entidad_data['admins']) ?> Admin<?= count($entidad_data['admins']) !== 1 ? 's' : '' ?></span>
                                    <span><i class="fas fa-building me-1"></i><?= $entidad_data['total_clubes'] ?> Club<?= $entidad_data['total_clubes'] !== 1 ? 'es' : '' ?></span>
                                    <span><i class="fas fa-users me-1"></i><?= number_format($entidad_data['total_afiliados']) ?> Afiliados</span>
                                    <span><i class="fas fa-mars me-1"></i><?= number_format($entidad_data['total_hombres']) ?></span>
                                    <span><i class="fas fa-venus me-1"></i><?= number_format($entidad_data['total_mujeres']) ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Administradores de esta Entidad -->
                        <?php foreach ($entidad_data['admins'] as $admin_id => $admin_data): ?>
                            <div class="border border-top-0 mb-0">
                                <!-- Cabecera del Administrador -->
                                <div class="py-2 px-4" style="background: linear-gradient(90deg, #198754 0%, #157347 100%); color: white;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-user-shield me-2"></i>
                                            <strong><?= htmlspecialchars($admin_data['admin_nombre'] ?: 'Sin Administrador Asignado') ?></strong>
                                            <?php if ($admin_data['admin_username']): ?>
                                                <small class="ms-2 opacity-75">(@<?= htmlspecialchars($admin_data['admin_username']) ?>)</small>
                                            <?php endif; ?>
                                            <?php if ($admin_id > 0): ?>
                                                <a href="index.php?page=admin_clubs&view=detail&admin_id=<?= $admin_id ?>" 
                                                   class="btn btn-sm btn-outline-light ms-2" title="Ver detalles del administrador">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-3">
                                            <span><i class="fas fa-building me-1"></i><?= $admin_data['total_clubes'] ?> Club<?= $admin_data['total_clubes'] !== 1 ? 'es' : '' ?></span>
                                            <span><i class="fas fa-users me-1"></i><?= number_format($admin_data['total_afiliados']) ?></span>
                                            <span><i class="fas fa-mars me-1"></i><?= number_format($admin_data['total_hombres']) ?></span>
                                            <span><i class="fas fa-venus me-1"></i><?= number_format($admin_data['total_mujeres']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tabla de Clubes del Administrador -->
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 50px;">ID</th>
                                                <th style="width: 60px;">Logo</th>
                                                <th>Nombre del Club</th>
                                                <th>Delegado</th>
                                                <th>Teléfono</th>
                                                <th class="text-center">Afiliados</th>
                                                <th class="text-center"><i class="fas fa-mars text-primary"></i></th>
                                                <th class="text-center"><i class="fas fa-venus text-danger"></i></th>
                                                <th class="text-center">Estado</th>
                                                <th class="text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($admin_data['clubes'] as $item): ?>
                                                <tr>
                                                    <td><code><?= htmlspecialchars((string)$item['id']) ?></code></td>
                                                    <td><?= displayClubLogoTable($item) ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($item['nombre'] ?? 'N/A') ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($item['delegado'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($item['telefono'] ?? 'N/A') ?></td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?= (int)($item['total_afiliados'] ?? 0) ?></span>
                                                        <br>
                                                        <a href="index.php?page=clubs&action=detail&id=<?= $item['id'] ?>" 
                                                           class="btn btn-sm btn-link p-0 text-primary" 
                                                           title="Ver afiliados">
                                                            <small><i class="fas fa-users me-1"></i>Ver</small>
                                                        </a>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?= (int)($item['hombres'] ?? 0) ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-danger"><?= (int)($item['mujeres'] ?? 0) ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?= $item['estatus'] ? 'success' : 'secondary' ?>">
                                                            <?= $item['estatus'] ? 'Activo' : 'Inactivo' ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="btn-group" role="group">
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-outline-success" 
                                                                    title="Enviar Invitación"
                                                                    onclick="openInvitationModal(<?= $item['id'] ?>, '<?= htmlspecialchars($item['nombre'] ?? 'Club sin nombre', ENT_QUOTES) ?>')">
                                                                <i class="fas fa-paper-plane"></i>
                                                            </button>
                                                            <a href="index.php?page=clubs&action=view&id=<?= $item['id'] ?>" 
                                                               class="btn btn-sm btn-outline-info" title="Ver">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="index.php?page=clubs&action=edit&id=<?= $item['id'] ?>" 
                                                               class="btn btn-sm btn-outline-primary" title="Editar">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <a href="index.php?page=clubs&action=delete&id=<?= $item['id'] ?>" 
                                                               class="btn btn-sm btn-outline-danger" title="Eliminar"
                                                               onclick="return confirm('¿Está seguro de eliminar el club \'<?= htmlspecialchars($item['nombre'] ?? 'Club sin nombre') ?>\'?')">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; // Cierra foreach de admins ?>
                    </div>
                <?php endforeach; // Cierra foreach de entidades ?>
                
                <!-- Resumen Total -->
                <div class="card bg-dark text-white mt-4">
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h4 class="mb-0"><?= count($clubs_by_entidad) ?></h4>
                                <small>Entidades</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="mb-0"><?= count($clubs_list) ?></h4>
                                <small>Total Clubes</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="mb-0"><?= number_format(array_sum(array_column($clubs_list, 'total_afiliados'))) ?></h4>
                                <small>Total Afiliados</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="mb-0">
                                    <span class="text-primary"><?= number_format(array_sum(array_column($clubs_list, 'hombres'))) ?></span>
                                    /
                                    <span class="text-danger"><?= number_format(array_sum(array_column($clubs_list, 'mujeres'))) ?></span>
                                </h4>
                                <small><i class="fas fa-mars text-primary"></i> / <i class="fas fa-venus text-danger"></i></small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php
$clubs_scroll_entidad = ($action === 'list' && !empty($_GET['entidad_id'])) ? (int)$_GET['entidad_id'] : 0;
if ($clubs_scroll_entidad > 0):
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('asociacion-<?= $clubs_scroll_entidad ?>');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>
<?php endif; ?>

<?php elseif ($action === 'detail'): ?>
    <!-- Vista de Detalle del Club con Afiliados -->
    <?php 
    // Asegurar que las variables estén definidas
    $from_page = isset($from_page) ? $from_page : (isset($_GET['from']) ? $_GET['from'] : null);
    $from_admin_id = isset($from_admin_id) ? $from_admin_id : (isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null);
    if (!isset($genero_filter) || ($genero_filter !== 'M' && $genero_filter !== 'F')) {
        $g = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : '';
        $genero_filter = ($g === 'M' || $g === 'F') ? $g : null;
    }
    $filtro_activo = $genero_filter === 'M' ? 'Hombres' : ($genero_filter === 'F' ? 'Mujeres' : 'Todos');
    
    // Determinar URL de retorno según el origen
    $return_url = 'index.php?page=clubs';
    if ($from_page === 'admin_clubs' && $from_admin_id) {
        $return_url = 'index.php?page=admin_clubs&view=detail&admin_id=' . $from_admin_id;
    } elseif ($from_page === 'home') {
        // Si viene del dashboard (home), volver al dashboard
        $return_url = class_exists('AppHelpers') ? AppHelpers::landingUrl() : 'index.php?page=torneo_gestion&action=index';
    } elseif ($from_page === 'clubes_asociados') {
        $return_url = 'index.php?page=clubes_asociados';
    }
    
    $base_detail_sin_genero = 'index.php?page=clubs&action=detail&id=' . (int) ($club['id'] ?? 0);
    if ($from_page) {
        $base_detail_sin_genero .= '&from=' . urlencode((string) $from_page);
    }
    if ($from_admin_id) {
        $base_detail_sin_genero .= '&admin_id=' . (int) $from_admin_id;
    }
    $url_genero_m = $base_detail_sin_genero . '&genero=M';
    $url_genero_f = $base_detail_sin_genero . '&genero=F';
    $pag_base_url = $base_detail_sin_genero . ($genero_filter ? '&genero=' . urlencode($genero_filter) : '');
    ?>
    <div class="fvd-listado-toolbar">
        <a href="<?= htmlspecialchars($return_url) ?>" class="btn btn-outline-secondary btn-sm btn-volver">
            <i class="fas fa-arrow-left me-1"></i>Volver
        </a>
        <div class="fvd-listado-toolbar-main">
            <div class="min-w-0">
                <h1 class="fvd-listado-title">
                    <i class="fas fa-sitemap me-1 opacity-75"></i><?= htmlspecialchars($club['nombre'] ?? 'Asociación') ?>
                </h1>
                <?php if (!empty($club['entidad'])): ?>
                    <p class="fvd-listado-subtitle mb-0">ID entidad: <?= (int) $club['entidad'] ?></p>
                <?php endif; ?>
            </div>
            <div class="btn-group fvd-listado-filtros" role="group" aria-label="Filtro por género">
                <a href="<?= htmlspecialchars($base_detail_sin_genero) ?>" class="btn btn-sm <?= !$genero_filter ? 'btn-primary' : 'btn-outline-primary' ?>">Todos</a>
                <a href="<?= htmlspecialchars($url_genero_m) ?>" class="btn btn-sm <?= $genero_filter === 'M' ? 'btn-primary' : 'btn-outline-primary' ?>">Hombres</a>
                <a href="<?= htmlspecialchars($url_genero_f) ?>" class="btn btn-sm <?= $genero_filter === 'F' ? 'btn-primary' : 'btn-outline-primary' ?>">Mujeres</a>
            </div>
        </div>
    </div>

    <div class="row g-2 fvd-listado-kpis">
        <div class="col-6 col-md-3">
            <div class="fvd-listado-kpi fvd-listado-kpi--sky">
                <strong><?= (int)($club_stats['total_afiliados'] ?? 0) ?></strong>
                <span>Total</span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fvd-listado-kpi fvd-listado-kpi--green">
                <strong><?= (int)($club_stats['total_activos'] ?? 0) ?></strong>
                <span>Activos</span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fvd-listado-kpi fvd-listado-kpi--blue">
                <strong><?= (int)($club_stats['hombres'] ?? 0) ?></strong>
                <span>Hombres</span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="fvd-listado-kpi fvd-listado-kpi--rose">
                <strong><?= (int)($club_stats['mujeres'] ?? 0) ?></strong>
                <span>Mujeres</span>
            </div>
        </div>
    </div>

    <div class="card fvd-listado-card">
        <div class="card-header">
            <i class="fas fa-users me-1"></i>Afiliados
        </div>
        <div class="card-body">
            <?php if (empty($club_afiliados)): ?>
                <div class="fvd-listado-empty">
                    <i class="fas fa-user-slash me-1"></i>Este club no tiene afiliados registrados
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Estado</th>
                                <th>Sexo</th>
                                <th>Email</th>
                                <th>Celular</th>
                                <th>Torneos</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($club_afiliados as $afiliado): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string)($afiliado['id'] ?? 'N/A')) ?></code></td>
                                    <td><strong><?= htmlspecialchars($afiliado['nombre'] ?? 'N/A') ?></strong></td>
                                    <td>
                                        <?php
                                        $st = $afiliado['status'] ?? null;
                                        $esActivo = ($st === 0 || $st === '0');
                                        ?>
                                        <span class="badge bg-<?= $esActivo ? 'success' : 'secondary' ?>"><?= $esActivo ? 'Activo' : 'Inactivo' ?></span>
                                    </td>
                                    <td>
                                        <?php if ($afiliado['sexo'] === 'M'): ?>
                                            <span class="badge bg-primary">M</span>
                                        <?php elseif ($afiliado['sexo'] === 'F'): ?>
                                            <span class="badge bg-danger">F</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($afiliado['email'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($afiliado['celular'] ?? '-') ?></td>
                                    <td><span class="badge bg-info text-dark"><?= (int)($afiliado['total_torneos'] ?? 0) ?></span></td>
                                    <td>
                                        <?php
                                        $afiliado_detail_url = 'index.php?page=clubs&action=afiliado_detail&club_id=' . $club['id'] . '&user_id=' . $afiliado['id'];
                                        if ($from_page) {
                                            $afiliado_detail_url .= '&from=' . urlencode((string) $from_page);
                                        }
                                        if ($from_admin_id) {
                                            $afiliado_detail_url .= '&admin_id=' . (int) $from_admin_id;
                                        }
                                        if (!empty($genero_filter)) {
                                            $afiliado_detail_url .= '&genero=' . urlencode($genero_filter);
                                        }
                                        ?>
                                        <a href="<?= htmlspecialchars($afiliado_detail_url) ?>" class="btn btn-sm btn-outline-primary" title="Ver detalle">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= FvdPaginacionCompacta::render(
                    $pagination_afiliados ? $pagination_afiliados->getCurrentPage() : 1,
                    $pagination_afiliados ? $pagination_afiliados->getTotalPages() : 1,
                    $pagination_afiliados ? $pagination_afiliados->getTotalRecords() : 0,
                    FvdPaginacionCompacta::PER_PAGE_DEFAULT,
                    $pag_base_url,
                    'p',
                    'afiliados'
                ) ?>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($action === 'new' || $action === 'edit' || $action === 'view'): ?>
    <?php $club_form_readonly = ($action === 'view'); ?>
    <!-- Formulario (mismo diseño en alta, edición y consulta) -->
    <div class="card">
        <div class="card-header bg-<?= $club_form_readonly ? 'info' : ($action === 'edit' ? 'warning' : 'success') ?> text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-<?= $club_form_readonly ? 'eye' : ($action === 'edit' ? 'edit' : 'plus-circle') ?> me-2"></i>
                <?php if ($club_form_readonly): ?>
                    Consultar asociación
                <?php elseif ($action === 'edit'): ?>
                    Editar asociación
                <?php else: ?>
                    Nueva asociación
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST"
                  action="<?= $club_form_readonly ? '#' : 'index.php?page=clubs&action=' . ($action === 'edit' ? 'update' : 'save') ?>"
                  enctype="multipart/form-data"
                  <?= $club_form_readonly ? ' onsubmit="return false;"' : '' ?>>
                <?php if (!$club_form_readonly): ?>
                    <?= CSRF::input(); ?>
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= (int)$club['id'] ?>">
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($club_form_readonly): ?>
                <fieldset disabled class="border-0 m-0 p-0 ms-1 me-1 mb-3">
                    <legend class="visually-hidden">Datos del club (solo lectura)</legend>
                <?php endif; ?>

                <?php if ($action === 'new'): ?>
                    <?php if (Auth::isAdminGeneral()): ?>
                            <div class="mb-3">
                                <input type="hidden" name="organizacion_id" value="<?= (int)(class_exists('FvdConfig') ? FvdConfig::organizacionId() : 1) ?>">
                                <?php
                                $entidades_club_form = [];
                                try {
                                    $entidades_club_form = DB::pdo()->query("SELECT id, nombre FROM entidad ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Throwable $e) {
                                    try {
                                        $entidades_club_form = DB::pdo()->query("SELECT codigo AS id, nombre FROM entidad ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (Throwable $e2) {
                                        $entidades_club_form = [];
                                    }
                                }
                                ?>
                                <label for="entidad" class="form-label">Asociación (estado/región) *</label>
                                <select class="form-select" id="entidad" name="entidad" required>
                                    <option value="">-- Seleccione asociación --</option>
                                    <?php foreach ($entidades_club_form as $entRow): ?>
                                        <option value="<?= (int)$entRow['id'] ?>"><?= htmlspecialchars($entRow['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    <?php elseif ($current_user['role'] === 'admin_torneo'): ?>
                        <input type="hidden" name="organizacion_id" value="<?= (int)(class_exists('FvdConfig') ? FvdConfig::organizacionId() : 1) ?>">
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($action === 'edit' || $action === 'view'): ?>
                    <?php
                    $entClubId = (int)($club['entidad'] ?? 0);
                    $entClubNombre = ($entClubId > 0)
                        ? (string)($entidad_map[$entClubId] ?? $entidad_map[(string)$entClubId] ?? ('Asociación #' . $entClubId))
                        : '—';
                    ?>
                    <div class="mb-3">
                        <label class="form-label" for="entidad_consulta">Asociación (estado/región)</label>
                        <input type="text" class="form-control bg-light" id="entidad_consulta" readonly value="<?= htmlspecialchars($entClubNombre) ?>">
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del Club *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                   value="<?= htmlspecialchars(($action === 'edit' || $action === 'view') ? ($club['nombre'] ?? '') : '') ?>" 
                                   <?= !$club_form_readonly ? 'required' : '' ?> placeholder="Ej: Club de Dominó Central">
                        </div>
                        
                        <div class="mb-3">
                            <label for="delegado" class="form-label">Delegado</label>
                            <input type="text" class="form-control" id="delegado" name="delegado" 
                                   value="<?= htmlspecialchars(($action === 'edit' || $action === 'view') ? ($club['delegado'] ?? '') : '') ?>" 
                                   placeholder="Nombre del delegado">
                        </div>
                        
                        <div class="mb-3">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="tel" class="form-control" id="telefono" name="telefono" 
                                   value="<?= htmlspecialchars(($action === 'edit' || $action === 'view') ? ($club['telefono'] ?? '') : '') ?>" 
                                   placeholder="Ej: 04XX-XXXXXXX">
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars(($action === 'edit' || $action === 'view') ? ($club['email'] ?? '') : '') ?>" 
                                   placeholder="Ej: club@ejemplo.com">
                        </div>
                    </div>
                    
                    <div class="col-md-6"><div class="mb-3"><label for="direccion" class="form-label">Dirección</label>
                            <textarea class="form-control" id="direccion" name="direccion" rows="3" 
                                      placeholder="Dirección completa del club"><?= htmlspecialchars(($action === 'edit' || $action === 'view') ? ($club['direccion'] ?? '') : '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="logo" class="form-label">Logo del Club</label>
                            <?php if (!$club_form_readonly): ?>
                            <input type="file" class="form-control" id="logo" name="logo" accept="image/*" data-preview-target="club-logo-preview">
                            <small class="form-text text-muted">Formatos permitidos: JPG, PNG, GIF (máx. 5MB)</small>
                            <div id="club-logo-preview"></div>
                            <?php endif; ?>
                            <?php if (($action === 'edit' || $action === 'view') && !empty($club['logo'])): ?>
                                <div class="mt-2">
                                    <small class="text-muted">Logo<?= $club_form_readonly ? '' : ' actual' ?>:</small><br>
                                    <?= displayClubLogoEdit($club) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="permite_inscripcion_linea" name="permite_inscripcion_linea" value="1"
                                    <?= (($action === 'edit' || $action === 'view') ? (($club['permite_inscripcion_linea'] ?? 1) == 1) : true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="permite_inscripcion_linea">
                                    <i class="fas fa-globe me-1"></i>Permitir inscripciones en línea
                                </label>
                            </div>
                            <small class="form-text text-muted">Si está activo, los afiliados podrán inscribirse en línea en torneos de su ámbito. Si no, solo inscripción en sitio.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="estatus" class="form-label">Estado *</label>
                            <select class="form-select" id="estatus" name="estatus" <?= !$club_form_readonly ? 'required' : '' ?>>
                                <option value="1"<?= (($action === 'edit' || $action === 'view') && (int)($club['estatus'] ?? 1) === 1) || $action === 'new' ? ' selected' : '' ?>>Activo</option>
                                <option value="0"<?= ($action === 'edit' || $action === 'view') && (int)($club['estatus'] ?? 1) === 0 ? ' selected' : '' ?>>Inactivo</option>
                            </select>
                            <small class="form-text text-muted">Los clubs inactivos no aparecerán en listados públicos</small>
                        </div>
                    </div>
                </div>

                <?php if ($club_form_readonly): ?>
                </fieldset>
                <?php endif; ?>
                
                <div class="d-flex flex-wrap gap-2 justify-content-between mt-4 pt-3 border-top">
                    <a href="index.php?page=clubs" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i><?= $club_form_readonly ? 'Volver al listado' : 'Cancelar' ?>
                    </a>
                    <div class="d-flex flex-wrap gap-2">
                    <?php if ($club_form_readonly): ?>
                        <a href="index.php?page=clubs&action=edit&id=<?= (int)$club['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i>Editar
                        </a>
                        <a href="index.php?page=clubs&action=detail&id=<?= (int)$club['id'] ?>" class="btn btn-outline-primary">
                            <i class="fas fa-users me-2"></i>Ver afiliados
                        </a>
                        <button type="button" class="btn btn-success" onclick="openInvitationModal(<?= (int)$club['id'] ?>, '<?= htmlspecialchars($club['nombre'] ?? 'Club sin nombre', ENT_QUOTES) ?>')">
                            <i class="fas fa-paper-plane me-2"></i>Enviar invitación
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-<?= $action === 'edit' ? 'warning' : 'success' ?>">
                            <i class="fas fa-save me-2"></i><?= $action === 'edit' ? 'Actualizar club' : 'Crear club' ?>
                        </button>
                    <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($action === 'afiliado_detail' && $afiliado_detail): ?>
    <!-- Vista de Detalle del Afiliado -->
    <?php
    // Determinar URL de retorno
    $from_page = isset($_GET['from']) ? $_GET['from'] : null;
    $from_admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null;
    $return_url_detail = 'index.php?page=clubs&action=detail&id=' . $club['id'];
    if ($from_page === 'admin_clubs' && $from_admin_id) {
        $return_url_detail .= '&from=admin_clubs&admin_id=' . $from_admin_id;
    } elseif ($from_page === 'home') {
        $return_url_detail .= '&from=home';
    } elseif ($from_page === 'clubes_asociados') {
        $return_url_detail .= '&from=clubes_asociados';
    }
    $gBack = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : '';
    if ($gBack === 'M' || $gBack === 'F') {
        $return_url_detail .= '&genero=' . urlencode($gBack);
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="<?= htmlspecialchars($return_url_detail) ?>" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="fas fa-arrow-left me-1"></i>Volver al Club
            </a>
            <h2 class="mb-0">
                <i class="fas fa-user me-2"></i><?= htmlspecialchars($afiliado_detail['nombre']) ?>
            </h2>
            <small class="text-muted">Detalle Completo del Afiliado</small>
        </div>
    </div>
    
    <!-- Tres columnas: Foto | Datos | Estadísticas -->
    <?php
    $foto_url = null;
    if (!empty($afiliado_detail['photo_path'])) {
        $foto_url = AppHelpers::url('view_image.php', ['path' => $afiliado_detail['photo_path']]);
    }
    ?>
    <div class="row mb-4">
        <!-- Columna 1: Foto -->
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-center">
                    <?php if ($foto_url): ?>
                    <img src="<?= htmlspecialchars($foto_url) ?>" alt="Foto del afiliado" class="rounded-circle border shadow-sm" style="width: 140px; height: 140px; object-fit: cover;">
                    <?php else: ?>
                    <div class="rounded-circle border bg-light d-inline-flex align-items-center justify-content-center" style="width: 140px; height: 140px;">
                        <i class="fas fa-user fa-5x text-muted"></i>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Columna 2: Datos restantes -->
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <p class="mb-2"><strong>Sexo:</strong>
                        <?php if ($afiliado_detail['sexo'] === 'M'): ?>
                            <span class="badge bg-primary">Masculino</span>
                        <?php elseif ($afiliado_detail['sexo'] === 'F'): ?>
                            <span class="badge bg-danger">Femenino</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars($afiliado_detail['sexo'] ?? 'N/A') ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="mb-2"><strong>Email:</strong> <?= htmlspecialchars($afiliado_detail['email'] ?? 'N/A') ?></p>
                    <p class="mb-2"><strong>Celular:</strong> <?= htmlspecialchars($afiliado_detail['celular'] ?? 'N/A') ?></p>
                    <p class="mb-2"><strong>Club:</strong> <?= htmlspecialchars($club['nombre'] ?? 'Club sin nombre') ?></p>
                    <p class="mb-0"><strong>Registrado:</strong> <?= date('d/m/Y', strtotime($afiliado_detail['created_at'])) ?></p>
                </div>
            </div>
        </div>
        <!-- Columna 3: Estadísticas de participación -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Estadísticas de Participación</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2"><strong>Total de Torneos:</strong> <span class="badge bg-primary fs-6"><?= count($afiliado_torneos) ?></span></p>
                    <p class="mb-2"><strong>Torneos Activos:</strong>
                        <span class="badge bg-success">
                            <?= count(array_filter($afiliado_torneos, function($t) { return strtotime($t['torneo_fecha']) >= strtotime('today'); })) ?>
                        </span>
                    </p>
                    <p class="mb-0"><strong>Torneos Finalizados:</strong>
                        <span class="badge bg-secondary">
                            <?= count(array_filter($afiliado_torneos, function($t) { return strtotime($t['torneo_fecha']) < strtotime('today'); })) ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Torneos del Afiliado -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Torneos Participados (<?= count($afiliado_torneos) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($afiliado_torneos)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-trophy fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Este afiliado no ha participado en ningún torneo aún</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Torneo</th>
                                <th>Organización</th>
                                <th>Costo</th>
                                <th>Identificador</th>
                                <th>Categoría</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($afiliado_torneos as $torneo): ?>
                                <?php 
                                $es_futuro = strtotime($torneo['torneo_fecha']) >= strtotime('today');
                                $es_pasado = strtotime($torneo['torneo_fecha']) < strtotime('today');
                                ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($torneo['torneo_fecha'])) ?></td>
                                    <td><strong><?= htmlspecialchars($torneo['torneo_nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($torneo['organizacion_nombre'] ?? 'N/A') ?></td>
                                    <td>$<?= number_format($torneo['torneo_costo'] ?? 0, 0) ?></td>
                                    <td>
                                        <?php if ($torneo['posicion'] && $torneo['posicion'] > 0): ?>
                                            <span class="badge bg-info">#<?= $torneo['posicion'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($torneo['categ']): ?>
                                            <span class="badge bg-primary"><?= htmlspecialchars($torneo['categ']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($es_futuro): ?>
                                            <span class="badge bg-success">Próximo</span>
                                        <?php elseif ($es_pasado): ?>
                                            <span class="badge bg-secondary">Finalizado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Hoy</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?page=tournaments&action=view&id=<?= $torneo['torneo_id'] ?>" 
                                           class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-eye me-1"></i>Ver Torneo
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Modal para mostrar logo completo -->
<div class="modal fade" id="logoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoModalTitle">Logo del Club</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="logoModalImage" src="" alt="Logo del club" class="img-fluid" 
                     style="max-height: 500px; border-radius: 8px;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <a id="logoDownloadLink" href="" class="btn btn-primary">
                    <i class="fas fa-download me-2"></i>Descargar
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal para enviar invitaciones -->
<div class="modal fade" id="invitationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-paper-plane me-2"></i>Enviar Invitación a Torneo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="invitationAlert" class="alert d-none"></div>
                
                <form id="invitationForm">
                    <input type="hidden" id="inviting_club_id" name="club_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Club a Invitar:</label>
                        <p id="inviting_club_name" class="form-control-plaintext bg-light p-2 rounded"></p>
                        <small class="text-muted">Este club recibirá la invitación para participar en el torneo</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tournament_select" class="form-label fw-bold">
                            Seleccionar Torneo <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="tournament_select" name="torneo_id" required>
                            <option value="">Cargando torneos...</option>
                        </select>
                        <div class="form-text">Seleccione el torneo para el cual se generará la invitación</div>
                    </div>
                    
                    <div id="tournament_info" class="mb-3 d-none">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <i class="fas fa-info-circle me-2"></i>Información del Torneo
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Fecha:</strong> <span id="tournament_date"></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Costo:</strong> $<span id="tournament_cost"></span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="access_start" class="form-label fw-bold">
                            Fecha Inicio de Acceso <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="access_start" name="acceso1" required>
                        <small class="text-muted">Se calculará automáticamente: 7 días antes del torneo</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="access_end" class="form-label fw-bold">
                            Fecha Fin de Acceso <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="access_end" name="acceso2" required>
                        <small class="text-muted">Se calculará automáticamente: 1 día antes del torneo</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Nota:</strong> Se generará automáticamente un PDF de invitación con los logos de los clubes.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <button type="button" class="btn btn-success" id="sendInvitationBtn" onclick="sendInvitation()">
                    <i class="fas fa-paper-plane me-2"></i>Generar Invitación
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Función para mostrar logo completo
function showLogoModal(logoPath, clubName) {
    document.getElementById('logoModalTitle').textContent = `Logo - ${clubName}`;
    document.getElementById('logoModalImage').src = logoPath;
    document.getElementById('logoDownloadLink').href = logoPath;
    
    const logoModal = new bootstrap.Modal(document.getElementById('logoModal'));
    logoModal.show();
}

// Abrir modal de invitación
async function openInvitationModal(clubId, clubName) {
    console.log('• Abriendo modal para club:', clubId, clubName);
    
    // IMPORTANTE: Resetear formulario completamente primero
    resetInvitationForm();
    
    // Esperar un tick para asegurar que el reset se completó
    await new Promise(resolve => setTimeout(resolve, 50));
    
    // Establecer nuevo club - forzar la asignación
    const clubIdInput = document.getElementById('inviting_club_id');
    clubIdInput.value = '';  // Limpiar primero explícitamente
    clubIdInput.value = clubId;  // Asignar nuevo valor
    document.getElementById('inviting_club_name').textContent = clubName;
    document.getElementById('tournament_info').classList.add('d-none');
    document.getElementById('invitationAlert').classList.add('d-none');
    
    console.log('? Club ID asignado:', clubIdInput.value);
    
    // Mostrar modal
    const modal = new bootstrap.Modal(document.getElementById('invitationModal'));
    modal.show();
    
    // Cargar torneos
    await loadTournaments();
}

// Función para resetear el formulario de invitación
function resetInvitationForm() {
    const form = document.getElementById('invitationForm');
    if (form) {
        form.reset();
    }
    
    // Limpiar campos específicos
    document.getElementById('inviting_club_id').value = '';
    document.getElementById('tournament_select').value = '';
    document.getElementById('access_start').value = '';
    document.getElementById('access_end').value = '';
    document.getElementById('tournament_info').classList.add('d-none');
    document.getElementById('invitationAlert').classList.add('d-none');
    
    // Limpiar contenedores de vista previa si existen
    const previewContainer = document.getElementById('previewContainer');
    if (previewContainer) {
        previewContainer.remove();
    }
    
    const processingContainer = document.getElementById('processingContainer');
    if (processingContainer) {
        processingContainer.remove();
    }
    
    // Mostrar formulario original
    const invitationForm = document.getElementById('invitationForm');
    if (invitationForm) {
        invitationForm.style.display = 'block';
    }
    
    // Restaurar botón original
    const btn = document.getElementById('sendInvitationBtn');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Generar Invitación';
    }
    
    // Restaurar footer original
    const modalFooter = document.querySelector('#invitationModal .modal-footer');
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="fas fa-times me-2"></i>Cancelar
            </button>
            <button type="button" class="btn btn-success" id="sendInvitationBtn" onclick="sendInvitation()">
                <i class="fas fa-paper-plane me-2"></i>Generar Invitación
            </button>
        `;
    }
}

// Cargar torneos activos
async function loadTournaments() {
    const select = document.getElementById('tournament_select');
    
    try {
        select.innerHTML = '<option value="">Cargando torneos...</option>';
        select.disabled = true;
        
        // API está en public/api/ - usar ruta relativa correcta
        // Estamos en /public/index.php cargando modules/clubs.php
        const response = await fetch('api/tournaments.php?estatus=1');
        
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Error response:', errorText);
            throw new Error('Error al cargar torneos (HTTP ' + response.status + ')');
        }
        
        const data = await response.json();
        console.log('Torneos cargados:', data);
        
        select.innerHTML = '<option value="">-- Seleccione un torneo --</option>';
        select.disabled = false;
        
        if (data.success && data.data && data.data.length > 0) {
            data.data.forEach(tournament => {
                const option = document.createElement('option');
                option.value = tournament.id;
                option.textContent = tournament.nombre + ' - ' + new Date(tournament.fechator).toLocaleDateString('es-ES');
                option.dataset.fecha = tournament.fechator;
                option.dataset.costo = tournament.costo || '0';
                select.appendChild(option);
            });
            console.log('Torneos agregados al select:', data.data.length);
        } else {
            select.innerHTML = '<option value="">No hay torneos activos disponibles</option>';
            console.log('No hay torneos disponibles');
        }
    } catch (error) {
        console.error('Error cargando torneos:', error);
        select.innerHTML = '<option value="">Error al cargar torneos</option>';
        select.disabled = false;
        showInvitationAlert('Error al cargar torneos: ' + error.message, 'danger');
    }
}

// Evento al seleccionar torneo
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'tournament_select') {
            const selectedOption = e.target.options[e.target.selectedIndex];
            const infoDiv = document.getElementById('tournament_info');
            
            if (selectedOption.value) {
                const fecha = selectedOption.dataset.fecha;
                const costo = selectedOption.dataset.costo || '0';
                
                document.getElementById('tournament_date').textContent = new Date(fecha).toLocaleDateString('es-ES');
                document.getElementById('tournament_cost').textContent = costo;
                infoDiv.classList.remove('d-none');
                
                // Calcular fechas automáticamente
                calculateDates(fecha);
            } else {
                infoDiv.classList.add('d-none');
            }
        }
    });
    
    // Resetear formulario cuando se cierra el modal
    const invitationModal = document.getElementById('invitationModal');
    if (invitationModal) {
        invitationModal.addEventListener('hidden.bs.modal', function () {
            resetInvitationForm();
        });
    }
});

// Calcular fechas de acceso automáticamente
function calculateDates(fechaTorneo) {
    // Agregar T12:00:00 para evitar problema de zona horaria
    const fecha = new Date(fechaTorneo + 'T12:00:00');
    
    console.log('• Calculando fechas para torneo:', fechaTorneo);
    
    // acceso1: 7 días antes del torneo
    const acceso1 = new Date(fecha);
    acceso1.setDate(acceso1.getDate() - 7);
    
    // acceso2: 1 día antes del torneo
    const acceso2 = new Date(fecha);
    acceso2.setDate(acceso2.getDate() - 1);
    
    const acceso1_str = acceso1.toISOString().split('T')[0];
    const acceso2_str = acceso2.toISOString().split('T')[0];
    
    console.log('• Fechas calculadas:', {
        'Torneo': fechaTorneo,
        'Inicio inscripción (acceso1)': acceso1_str,
        'Fin inscripción (acceso2)': acceso2_str
    });
    
    document.getElementById('access_start').value = acceso1_str;
    document.getElementById('access_end').value = acceso2_str;
}

// Enviar invitación
async function sendInvitation() {
    const form = document.getElementById('invitationForm');
    const btn = document.getElementById('sendInvitationBtn');
    
    // Validar formulario
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Obtener datos
    const data = {
        club_id: parseInt(document.getElementById('inviting_club_id').value),
        torneo_id: parseInt(document.getElementById('tournament_select').value),
        acceso1: document.getElementById('access_start').value,
        acceso2: document.getElementById('access_end').value
    };
    
    console.log('• Enviando invitación:', data);
    
    // Validar datos
    if (!data.club_id || !data.torneo_id) {
        showInvitationAlert('? Debe seleccionar un torneo', 'danger');
        return;
    }
    
    // Validar fechas de acceso
    if (!data.acceso1 || !data.acceso2) {
        showInvitationAlert('? Las fechas de acceso son requeridas', 'danger');
        return;
    }
    
    console.log('? Validación pasada. Datos a enviar:', {
        club_id: data.club_id,
        torneo_id: data.torneo_id,
        acceso1: data.acceso1,
        acceso2: data.acceso2
    });
    
    // Deshabilitar botón
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...';
    
    try {
        // Usar ruta absoluta correcta
        const response = await fetch('../modules/clubs_send_invitation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        console.log('Response status:', response.status);
        
        const result = await response.json();
        console.log('Result:', result);
        
        if (result.success) {
            // Generar mensaje de WhatsApp y enviar automáticamente
            enviarAutomaticoWhatsApp(result);
        } else {
            showInvitationAlert('? Error: ' + result.error, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Generar Invitación';
        }
    } catch (error) {
        console.error('Error completo:', error);
        showInvitationAlert('? Error de conexión: ' + error.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Generar Invitación';
    }
}

// Mostrar alerta en modal
function showInvitationAlert(message, type) {
    const alert = document.getElementById('invitationAlert');
    alert.className = 'alert alert-' + type;
    alert.innerHTML = message; // Usar innerHTML para permitir HTML
    alert.classList.remove('d-none');
}

// Mostrar vista previa del PDF y opciones de envío
function mostrarVistaPrevia(result) {
    const modalBody = document.querySelector('#invitationModal .modal-body');
    const modalFooter = document.querySelector('#invitationModal .modal-footer');
    
    // Ocultar formulario
    document.getElementById('invitationForm').style.display = 'none';
    document.getElementById('invitationAlert').classList.add('d-none');
    
    // Crear contenedor de vista previa
    const previewContainer = document.createElement('div');
    previewContainer.id = 'previewContainer';
    previewContainer.innerHTML = `
        <div class="alert alert-success mb-3">
            <i class="fas fa-check-circle me-2"></i>
            <strong>¡Invitación creada exitosamente!</strong><br>
            <small>Token generado: <code>${result.token.substring(0, 20)}...</code></small>
        </div>
        
        <h6 class="mb-3">
            <i class="fas fa-file-pdf me-2 text-danger"></i>Vista Previa del PDF de Invitación
        </h6>
        
        <div class="border rounded mb-3" style="height: 400px; overflow: hidden;">
            <iframe src="../modules/generar_pdf_invitacion_simple.php?id=${result.invitation_id}" 
                    style="width: 100%; height: 100%; border: none;">
            </iframe>
        </div>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Próximos pasos:</strong><br>
            1. Descargue el PDF de invitación<br>
            2. Envíelo por WhatsApp al delegado del club<br>
            3. El delegado usar• el token para acceder e inscribir jugadores
        </div>
    `;
    
    modalBody.appendChild(previewContainer);
    
    // Actualizar footer con nuevas opciones
    modalFooter.innerHTML = `
        <a href="../modules/generar_pdf_invitacion_simple.php?id=${result.invitation_id}" 
           class="btn btn-danger">
            <i class="fas fa-file-pdf me-2"></i>Descargar PDF
        </a>
        <button type="button" 
                class="btn btn-success" 
                onclick="enviarPorWhatsApp(${result.invitation_id}, '${result.club_nombre}', '${result.torneo_nombre}')">
            <i class="fab fa-whatsapp me-2"></i>Enviar por WhatsApp
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Cerrar
        </button>
    `;
}

// Enviar automáticamente por WhatsApp después de generar invitación
async function enviarAutomaticoWhatsApp(result) {
    const modalBody = document.querySelector('#invitationModal .modal-body');
    const modalFooter = document.querySelector('#invitationModal .modal-footer');
    
    // Ocultar formulario
    document.getElementById('invitationForm').style.display = 'none';
    document.getElementById('invitationAlert').classList.add('d-none');
    
    // Mostrar proceso de envío
    const processingContainer = document.createElement('div');
    processingContainer.id = 'processingContainer';
    processingContainer.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-success mb-3" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Procesando...</span>
            </div>
            <h5 class="mb-3">Generando y enviando invitación...</h5>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Se abrió WhatsApp automáticamente en unos segundos
            </div>
            <div id="statusMessage" class="text-muted"></div>
        </div>
    `;
    
    modalBody.appendChild(processingContainer);
    
    // Actualizar footer
    modalFooter.innerHTML = `
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Cerrar
        </button>
    `;
    
    try {
        // Actualizar estado
        document.getElementById('statusMessage').textContent = 'Obteniendo datos de la invitación...';
        
        // Obtener datos completos de la invitación
        const response = await fetch(`../modules/invitations/get_invitation_data.php?id=${result.invitation_id}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Error al obtener datos');
        }
        
        const inv = data.data;
        
        // Actualizar estado
        document.getElementById('statusMessage').textContent = 'Generando mensaje de WhatsApp...';
        
        // Construir mensaje
        const protocol = window.location.protocol;
        const host = window.location.host;
        const pathArray = window.location.pathname.split('/');
        const projectRoot = '/' + pathArray[1]; // /mistorneos
        
        const loginUrl = `${protocol}//${host}${projectRoot}/modules/invitations/inscripciones/login.php?token=${inv.token}`;
        const pdfUrl = `${protocol}//${host}${projectRoot}/modules/generar_pdf_invitacion_simple.php?id=${result.invitation_id}`;
        
        // Función helper para formatear fechas sin problemas de zona horaria
        function formatearFecha(fechaStr) {
            if (!fechaStr) return 'N/A';
            // Agregar 'T12:00:00' para forzar interpretación local (mediodía)
            const fecha = new Date(fechaStr + 'T12:00:00');
            return fecha.toLocaleDateString('es-ES');
        }
        
        let mensaje = `*📋 INVITACIÓN TORNEO DE DOMINÓ*\n\n`;
        mensaje += `Estimado/a *${inv.club_invitado_delegado || 'Delegado'}*\n`;
        mensaje += `Club: *${inv.club_invitado_nombre}*\n\n`;
        mensaje += `????????????????\n\n`;
        mensaje += `*📋 TORNEO:* ${inv.torneo_nombre}\n`;
        mensaje += `*📋 FECHA DEL TORNEO:* ${formatearFecha(inv.torneo_fecha)}\n`;
        mensaje += `*📋 ORGANIZA:* ${inv.club_responsable_nombre || 'N/A'}\n\n`;
        mensaje += `? *PERÍODO DE INSCRIPCIÓN:*\n`;
        mensaje += `Desde: ${formatearFecha(inv.acceso1)}\n`;
        mensaje += `Hasta: ${formatearFecha(inv.acceso2)}\n\n`;
        mensaje += `????????????????\n\n`;
        mensaje += `*📋 INSCRIBIR JUGADORES (Click aquí):*\n${loginUrl}\n\n`;
        mensaje += `*📋 Ver PDF de Invitación:*\n${pdfUrl}\n\n`;
        
        // Agregar archivos adjuntos del torneo si existen
        if (result.archivos && Object.keys(result.archivos).length > 0) {
            mensaje += `????????????????\n\n`;
            mensaje += `*📋 ARCHIVOS ADJUNTOS:*\n\n`;
            
            if (result.archivos.afiche) {
                const afficheUrl = `${protocol}//${host}${projectRoot}/${result.archivos.afiche.url}`;
                mensaje += `${result.archivos.afiche.icono} *Afiche:*\n${afficheUrl}\n\n`;
            }
            
            if (result.archivos.invitacion) {
                const invitacionUrl = `${protocol}//${host}${projectRoot}/${result.archivos.invitacion.url}`;
                mensaje += `${result.archivos.invitacion.icono} *Invitación Oficial:*\n${invitacionUrl}\n\n`;
            }
            
            if (result.archivos.normas) {
                const normasUrl = `${protocol}//${host}${projectRoot}/${result.archivos.normas.url}`;
                mensaje += `${result.archivos.normas.icono} *Normas/Condiciones:*\n${normasUrl}\n\n`;
            }
        }
        
        mensaje += `????????????????\n\n`;
        mensaje += `*📋 INSTRUCCIONES:*\n`;
        mensaje += `1?? Haga click en "INSCRIBIR JUGADORES"\n`;
        mensaje += `2️⃣ Acceso AUTOMÁTICO (sin contraseñas)\n`;
        mensaje += `3?? Descargue los archivos adjuntos\n`;
        mensaje += `4?? Complete el formulario de inscripción\n\n`;
        mensaje += `*📋 Importante:* El link funciona directamente desde su celular\n\n`;
        mensaje += `¡Esperamos su participación! ??\n\n`;
        mensaje += `_Serviclubes LED_`;
        
        // Limpiar y formatear teléfono para WhatsApp
        let telefono = '';
        if (inv.club_invitado_telefono) {
            // Limpiar: solo números
            telefono = inv.club_invitado_telefono.replace(/[^0-9]/g, '');
            
            // Si empieza con 0, quitarlo (típico de Venezuela)
            if (telefono.startsWith('0')) {
                telefono = telefono.substring(1);
            }
            
            // Si no tiene código de país, agregar 58 (Venezuela)
            if (telefono.length === 10 && !telefono.startsWith('58')) {
                telefono = '58' + telefono;
            }
            
            // Si tiene código de país pero sin +, está OK
            // WhatsApp acepta sin +
            
            console.log('Teléfono original:', inv.club_invitado_telefono);
            console.log('Teléfono formateado:', telefono);
        }
        
        // Generar URL de WhatsApp
        const mensajeEncoded = encodeURIComponent(mensaje);
        let whatsappUrl;
        
        if (telefono && telefono.length >= 10) {
            whatsappUrl = `https://api.whatsapp.com/send?phone=${telefono}&text=${mensajeEncoded}`;
            console.log('URL WhatsApp con teléfono:', whatsappUrl);
        } else {
            whatsappUrl = `https://api.whatsapp.com/send?text=${mensajeEncoded}`;
            console.log('URL WhatsApp sin teléfono (debe seleccionar contacto)');
        }
        
        // Actualizar estado
        document.getElementById('statusMessage').innerHTML = `
            <div class="alert alert-success mt-3">
                <i class="fas fa-check-circle me-2"></i>
                �Listo! Abriendo WhatsApp...
            </div>
        `;
        
        // Esperar 1 segundo y abrir WhatsApp
        setTimeout(() => {
            // Intentar abrir WhatsApp
            window.location.href = whatsappUrl;
            
            // Mostrar opciones finales
            processingContainer.innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                    <h4 class="mt-3 mb-3">¡Invitación Creada Exitosamente!</h4>
                    
                    <div class="alert alert-info mb-4">
                        <i class="fab fa-whatsapp me-2"></i>
                        <strong>IMPORTANTE:</strong><br>
                        WhatsApp se ha abierto en una nueva pestaña.<br>
                        <strong class="text-danger">Haga click en el botón "Enviar" de WhatsApp para completar el envío.</strong><br>
                        <small>Si WhatsApp no se abrió, use el botón "Abrir WhatsApp" de abajo.</small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>�Problemas con WhatsApp?</strong><br>
                        Si WhatsApp no se abrió o no puede enviar, puede copiar el mensaje manualmente usando el botón "Copiar Mensaje".
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <button type="button" class="btn btn-success btn-lg" onclick="window.location.href='${whatsappUrl}'; this.innerHTML='<i class=\\'fab fa-whatsapp me-2\\'></i>WhatsApp Abierto'; this.disabled=true;">
                            <i class="fab fa-whatsapp me-2"></i>Abrir WhatsApp
                        </button>
                        
                        <button type="button" class="btn btn-primary btn-lg" onclick="copiarMensajeCompleto(${JSON.stringify(mensaje)})">
                            <i class="fas fa-copy me-2"></i>Copiar Mensaje Completo
                        </button>
                        
                        <a href="${pdfUrl}" class="btn btn-danger btn-lg">
                            <i class="fas fa-file-pdf me-2"></i>Ver PDF de Invitación
                        </a>
                        
                        <button type="button" class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-sync me-2"></i>Crear Nueva Invitación
                        </button>
                    </div>
                    
                    <div class="mt-4 p-3 bg-light rounded">
                        <h6 class="mb-2"><strong>Datos de la Invitación:</strong></h6>
                        <p class="mb-1"><strong>Club:</strong> ${inv.club_invitado_nombre}</p>
                        <p class="mb-1"><strong>Delegado:</strong> ${inv.club_invitado_delegado || 'N/A'}</p>
                        <p class="mb-1"><strong>Teléfono:</strong> ${inv.club_invitado_telefono || 'No registrado'} ${telefono ? '(Formato WhatsApp: +' + telefono + ')' : ''}</p>
                        <p class="mb-1"><strong>Torneo:</strong> ${inv.torneo_nombre}</p>
                        <p class="mb-0"><strong>Token:</strong> <code>${inv.token}</code></p>
                    </div>
                </div>
            `;
            
            modalFooter.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cerrar
                </button>
            `;
            
            // Si WhatsApp no se abrió (bloqueador de popups), mostrar alerta
            if (false) {
                setTimeout(() => {
                    alert('• WhatsApp no se pudo abrir automáticamente.\n\nPor favor, haga click en el botón "Abrir WhatsApp" que aparece arriba, o copie el mensaje manualmente.');
                }, 500);
            }
        }, 1000);
        
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('statusMessage').innerHTML = `
            <div class="alert alert-danger mt-3">
                <i class="fas fa-exclamation-circle me-2"></i>
                Error: ${error.message}
            </div>
        `;
    }
}

// Función para copiar mensaje completo
function copiarMensajeCompleto(mensaje) {
    const textarea = document.createElement('textarea');
    textarea.value = mensaje;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    
    try {
        document.execCommand('copy');
        alert('✓ Mensaje copiado al portapapeles!\n\nAhora puede pegarlo en WhatsApp manualmente.');
    } catch (err) {
        alert('✓ Error al copiar. Por favor, seleccione y copie el mensaje manualmente.');
    }
    
    document.body.removeChild(textarea);
}

// Función para exportar clubs a Excel
function exportarClubsExcel() {
    window.location.href = '../modules/clubs/export_excel.php';
}

// Log para debugging
console.log('Módulo clubs.php cargado correctamente');
</script>

<style>
.cursor-pointer {
    cursor: pointer;
}
.cursor-pointer:hover {
    opacity: 0.8;
    transform: scale(1.05);
    transition: all 0.2s;
}
</style>
