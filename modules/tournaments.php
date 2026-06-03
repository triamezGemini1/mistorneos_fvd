<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Pagination.php';

// Verificar permisos
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

// POST crear/actualizar torneo: procesar en el mismo entry point (index.php) para mantener sesión
$action_get = $_GET['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($action_get, ['save', 'update'], true)) {
    if ($action_get === 'save') {
        require_once __DIR__ . '/tournaments/save.php';
        exit;
    }
    if ($action_get === 'update') {
        require_once __DIR__ . '/tournaments/update.php';
        exit;
    }
}

// action=save y action=update solo tienen sentido por POST (envío del formulario). Si se accede por GET, redirigir al formulario adecuado.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && in_array($action_get, ['save', 'update'], true)) {
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $prefix = (strpos($script, '/') !== false) ? dirname($script) . '/' : '';
    if ($action_get === 'save') {
        header('Location: ' . $prefix . 'index.php?page=tournaments&action=new');
    } else {
        $id = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
        header('Location: ' . $prefix . 'index.php?page=tournaments&action=' . ($id > 0 ? 'edit&id=' . $id : 'list'));
    }
    exit;
}

// Obtener usuario actual y su club_id
$current_user = Auth::user();
$user_role = $current_user['role'];
$user_club_id = $current_user['club_id'] ?? null;
$is_admin_general = Auth::isAdminGeneral();
$is_admin_torneo = Auth::isAdminTorneo();
$has_cod_org = false;
try {
    $has_cod_org = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_ref_expr = $has_cod_org ? "COALESCE(NULLIF(cod_org, 0), id)" : "id";
$org_ref_expr_o = $has_cod_org ? "COALESCE(NULLIF(o.cod_org, 0), o.id, t.club_responsable)" : "COALESCE(o.id, t.club_responsable)";
$org_join_expr = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

// Obtener datos para la vista
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;

// Procesar eliminación si se solicita
if ($action === 'delete' && $id) {
    try {
        $tournament_id = (int)$id;
        
        // Obtener datos del torneo antes de eliminarlo
        $stmt = DB::pdo()->prepare("SELECT nombre, club_responsable, estatus, fechator FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);
        $tournament_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament_to_delete) {
            throw new Exception('Torneo no encontrado');
        }
        
        // Verificar que el torneo no haya iniciado (no se pueden eliminar torneos iniciados)
        $fecha_torneo = !empty($tournament_to_delete['fechator']) ? strtotime($tournament_to_delete['fechator']) : null;
        $es_iniciado = false;
        if ($fecha_torneo && $fecha_torneo <= strtotime('today')) {
            $es_iniciado = true;
        }
        if (!$es_iniciado) {
            $stmt_rondas = DB::pdo()->prepare("SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND mesa > 0");
            $stmt_rondas->execute([$tournament_id]);
            $tiene_rondas = (int)$stmt_rondas->fetchColumn() > 0;
            if ($tiene_rondas) {
                $es_iniciado = true;
            }
        }
        if ($es_iniciado) {
            throw new Exception('No se puede eliminar un torneo que ya ha iniciado. Solo se pueden eliminar torneos futuros sin rondas generadas.');
        }
        // No permitir eliminar si tiene inscritos
        $stmt_ins = DB::pdo()->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?");
        $stmt_ins->execute([$tournament_id]);
        $num_inscritos = (int)$stmt_ins->fetchColumn();
        if ($num_inscritos > 0) {
            throw new Exception('No se puede eliminar un torneo que tenga inscritos. Actualmente tiene ' . $num_inscritos . ' inscrito(s).');
        }

        // Verificar permisos
        if (!Auth::canModifyTournament($tournament_id)) {
            throw new Exception('No tiene permisos para eliminar este torneo. Solo puede eliminar torneos futuros de su club.');
        }
        
        // Eliminar el torneo
        $stmt = DB::pdo()->prepare("DELETE FROM tournaments WHERE id = ?");
        $result = $stmt->execute([$tournament_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            header('Location: ' . AppHelpers::dashboard('tournaments', ['success' => 'Torneo "' . $tournament_to_delete['nombre'] . '" eliminado exitosamente']));
        } else {
            throw new Exception('No se pudo eliminar el torneo');
        }
    } catch (Exception $e) {
        header('Location: ' . AppHelpers::dashboard('tournaments', ['error' => 'Error al eliminar: ' . $e->getMessage()]));
    }
    exit;
}

// Obtener lista de organizaciones para selectores (club_responsable = ID organización)
$organizaciones_list = [];
$default_organizacion_id = null;
$default_organizacion_nombre = null;
if (in_array($action, ['new', 'edit'])) {
    try {
        if ($is_admin_general) {
            $stmt = DB::pdo()->query("SELECT {$org_ref_expr} AS id, nombre FROM organizaciones WHERE estatus = 1 ORDER BY nombre ASC");
            $organizaciones_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // admin_club / admin_torneo: el torneo se asigna a la organización que lo genera
            if ($user_role === 'admin_club') {
                $default_organizacion_id = Auth::getUserOrganizacionId();
                if ($default_organizacion_id) {
                    $sql_org_nombre = $has_cod_org
                        ? "SELECT nombre FROM organizaciones WHERE (id = ? OR cod_org = ?) AND estatus = 1"
                        : "SELECT nombre FROM organizaciones WHERE id = ? AND estatus = 1";
                    $stmt = DB::pdo()->prepare($sql_org_nombre);
                    $stmt->execute($has_cod_org ? [$default_organizacion_id, $default_organizacion_id] : [$default_organizacion_id]);
                    $default_organizacion_nombre = $stmt->fetchColumn();
                }
            } elseif ($user_role === 'admin_torneo' && $user_club_id) {
                $stmt = DB::pdo()->prepare("SELECT cod_org FROM clubes WHERE id = ?");
                $stmt->execute([$user_club_id]);
                $default_organizacion_id = $stmt->fetchColumn();
                $default_organizacion_id = $default_organizacion_id ? (int)$default_organizacion_id : null;
                if ($default_organizacion_id) {
                    $sql_org_nombre = $has_cod_org
                        ? "SELECT nombre FROM organizaciones WHERE (id = ? OR cod_org = ?) AND estatus = 1"
                        : "SELECT nombre FROM organizaciones WHERE id = ? AND estatus = 1";
                    $stmt = DB::pdo()->prepare($sql_org_nombre);
                    $stmt->execute($has_cod_org ? [$default_organizacion_id, $default_organizacion_id] : [$default_organizacion_id]);
                    $default_organizacion_nombre = $stmt->fetchColumn();
                }
            }
        }
    } catch (Exception $e) {
        $organizaciones_list = [];
    }
}

// Al crear torneo (admin_general), organizacion_id en GET permite mostrar cuentas del admin de esa org
$organizacion_id_cuentas_new = null;
if ($action === 'new' && !empty($_GET['organizacion_id']) && (int)$_GET['organizacion_id'] > 0) {
    $organizacion_id_cuentas_new = (int)$_GET['organizacion_id'];
}

// Obtener datos para formularios y vistas
$tournament = null;
if (($action === 'edit' || $action === 'view') && $id) {
    try {
        $stmt = DB::pdo()->prepare("
            SELECT t.*, o.nombre as organizacion_nombre, o.logo as organizacion_logo,
                   {$org_ref_expr_o} as organizacion_ref
            FROM tournaments t
            {$org_join_expr}
            WHERE t.id = ?
        ");
        $stmt->execute([(int)$id]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            $error_message = "Torneo no encontrado";
            $action = 'list';
        } else {
            // Verificar permisos
            if (!Auth::canAccessTournament((int)$id)) {
                $error_message = "No tiene permisos para acceder a este torneo";
                $action = 'list';
                $tournament = null;
            } elseif ($action === 'edit' && !Auth::canModifyTournament((int)$id)) {
                // Admin torneo no puede editar torneos pasados
                $error_message = "No puede modificar torneos que ya han pasado. Solo puede verlos.";
                $action = 'view'; // Redirigir a vista en lugar de lista
                // No eliminar $tournament, solo cambiar acción
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al cargar el torneo: " . $e->getMessage();
        $action = 'list';
    }
}

// Obtener lista para vista de lista con paginación
$organizacion_id_param = isset($_GET['organizacion_id']) ? (int)$_GET['organizacion_id'] : null;
$estado_param = isset($_GET['estado']) ? trim($_GET['estado']) : null;
if ($estado_param && !in_array($estado_param, ['realizados', 'en_proceso', 'por_realizar'], true)) {
    $estado_param = null;
}
$organizacion_nombre_titulo = null;
$tournaments_by_org = [];
$tournaments_list = [];
$pagination = null;
$show_organizacion_links = false;
if ($action === 'list') {
    try {
        // Verificar permisos: admin_torneo requiere club, admin_club requiere organización
        $can_access = true;
        if (!$is_admin_general) {
            if ($user_role === 'admin_torneo' && !$user_club_id) {
                $error_message = "Su usuario no tiene un club asignado. Contacte al administrador.";
                $can_access = false;
            } elseif ($user_role === 'admin_club' && !Auth::getUserOrganizacionId()) {
                $error_message = "Su usuario no tiene una organización asignada. Contacte al administrador.";
                $can_access = false;
            }
        }
        
        if (!$can_access) {
            $tournaments_list = [];
        } else {
            // Configurar paginación
            $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
            $per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 25;
            
            // Construir filtro WHERE según permisos
            $tournament_filter = Auth::getTournamentFilterForRole('t');
            $where_parts = $tournament_filter['where'] ? [$tournament_filter['where']] : [];
            $params = $tournament_filter['params'];
            
            if ($organizacion_id_param > 0) {
                if (!$is_admin_general && $user_role === 'admin_club') {
                    $mi_org = Auth::getUserOrganizacionId();
                    if ((int)$mi_org !== $organizacion_id_param) {
                        $organizacion_id_param = null;
                    }
                }
                if ($organizacion_id_param > 0) {
                    if ($has_cod_org) {
                        $where_parts[] = "EXISTS (
                            SELECT 1 FROM organizaciones oflt
                            WHERE (oflt.id = t.club_responsable OR oflt.cod_org = t.club_responsable)
                              AND (oflt.id = ? OR oflt.cod_org = ?)
                        )";
                        $params[] = $organizacion_id_param;
                        $params[] = $organizacion_id_param;
                    } else {
                        $where_parts[] = 't.club_responsable = ?';
                        $params[] = $organizacion_id_param;
                    }
                    $stmt_o = DB::pdo()->prepare($has_cod_org
                        ? "SELECT nombre FROM organizaciones WHERE (id = ? OR cod_org = ?)"
                        : "SELECT nombre FROM organizaciones WHERE id = ?");
                    $stmt_o->execute($has_cod_org ? [$organizacion_id_param, $organizacion_id_param] : [$organizacion_id_param]);
                    $organizacion_nombre_titulo = $stmt_o->fetchColumn();
                }
            }
            if ($estado_param && $organizacion_id_param > 0) {
                if ($estado_param === 'realizados') {
                    $where_parts[] = 't.fechator < CURDATE()';
                } elseif ($estado_param === 'en_proceso') {
                    $where_parts[] = 't.fechator = CURDATE()';
                } elseif ($estado_param === 'por_realizar') {
                    $where_parts[] = 't.fechator > CURDATE()';
                }
            }
            $where_clause = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';
            $count_where = str_replace('t.', '', $where_clause);
            if ($count_where === '') {
                $count_where = $where_clause;
            }
            
            if ($organizacion_id_param > 0 && !$estado_param) {
                $show_organizacion_links = true;
                $tournaments_list = [];
            } else {
                $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
                $per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 25;
                $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM tournaments t $where_clause");
                $stmt->execute($params);
                $total_records = (int)$stmt->fetchColumn();
            
            // Crear objeto de paginación
            $pagination = new Pagination($total_records, $current_page, $per_page);
            
            // Obtener registros de la página actual
            $stmt = DB::pdo()->prepare("
                SELECT t.*, o.nombre as organizacion_nombre,
                       {$org_ref_expr_o} as organizacion_ref
                FROM tournaments t
                {$org_join_expr}
                $where_clause
                " . ($organizacion_id_param > 0 ? "ORDER BY t.fechator DESC, t.id DESC" : "ORDER BY COALESCE(o.nombre, '') ASC, t.fechator DESC, t.id DESC") . "
                LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}
            ");
                $stmt->execute($params);
                $tournaments_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($organizacion_id_param <= 0 && empty($estado_param)) {
                    foreach ($tournaments_list as $item) {
                        $oid = (int)($item['organizacion_ref'] ?? $item['club_responsable'] ?? 0);
                        $oname = $item['organizacion_nombre'] ?? 'Sin organización';
                        if (!isset($tournaments_by_org[$oid])) {
                            $tournaments_by_org[$oid] = ['nombre' => $oname, 'items' => []];
                        }
                        $tournaments_by_org[$oid]['items'][] = $item;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al cargar los torneos: " . $e->getMessage();
    }
}

// Funciones helper para convertir entre valores ENUM (texto) y numéricos
function getClaseNumero($clase) {
    if (is_numeric($clase)) {
        return (int)$clase;
    }
    // Convertir de texto ENUM a número
    $map = [
        'torneo' => 1,
        'campeonato' => 2
    ];
    return $map[strtolower($clase)] ?? 0;
}

function getModalidadNumero($modalidad) {
    if (is_numeric($modalidad)) {
        return (int)$modalidad;
    }
    // Convertir de texto ENUM a número
    $map = [
        'individual' => 1,
        'parejas' => 2,
        'equipos' => 3
    ];
    return $map[strtolower($modalidad)] ?? 0;
}

// Funciones helper para labels
function getClaseLabel($clase) {
    $clase_int = getClaseNumero($clase);
    $labels = [
        0 => '<span class="badge bg-secondary">No definido</span>',
        1 => '<span class="badge bg-primary">Torneo</span>',
        2 => '<span class="badge bg-success">Campeonato</span>'
    ];
    return $labels[$clase_int] ?? '<span class="badge bg-secondary">No definido</span>';
}

function getModalidadLabel($modalidad) {
    $modalidad_int = getModalidadNumero($modalidad);
    $labels = [
        0 => '<span class="badge bg-secondary">No definido</span>',
        1 => '<span class="badge bg-info">Individual</span>',
        2 => '<span class="badge bg-warning">Parejas</span>',
        3 => '<span class="badge bg-success">Equipos</span>',
        4 => '<span class="badge bg-primary">Parejas fijas</span>'
    ];
    return $labels[$modalidad_int] ?? '<span class="badge bg-secondary">No definido</span>';
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <?php if ($action === 'list' && $organizacion_nombre_titulo): ?>
                        <h1 class="h3 mb-0">Torneos de <?= htmlspecialchars($organizacion_nombre_titulo) ?></h1>
                        <p class="text-muted mb-0">
                            <a href="index.php?page=tournaments">Torneos</a>
                            <?php if ($estado_param): ?>
                                &nbsp;/&nbsp;<a href="index.php?page=tournaments&organizacion_id=<?= (int)$organizacion_id_param ?>"><?= htmlspecialchars($organizacion_nombre_titulo) ?></a>
                                &nbsp;/&nbsp;<?= $estado_param === 'realizados' ? 'Realizados' : ($estado_param === 'en_proceso' ? 'En proceso' : 'Por realizar') ?>
                            <?php else: ?>
                                &nbsp;/&nbsp;<?= htmlspecialchars($organizacion_nombre_titulo) ?>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <h1 class="h3 mb-0">Torneos</h1>
                        <p class="text-muted mb-0">Administra los torneos del sistema</p>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($action === 'list'): ?>
                        <a href="../report_tournaments.php" class="btn btn-success me-2">
                            <i class="fas fa-file-pdf me-2"></i>Descargar PDF
                        </a>
                        <a href="index.php?page=tournaments&action=new" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Nuevo Torneo
                        </a>
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
            
            <!-- Información de permisos para admin_torneo -->
            <?php if (!$is_admin_general && $action === 'list'): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de Torneo:</strong> 
                    Solo puede ver y modificar torneos activos del club 
                    <?php if ($user_club_id): ?>
                        <strong>"<?php 
                            $stmt = DB::pdo()->prepare("SELECT nombre FROM clubes WHERE id = ?");
                            $stmt->execute([$user_club_id]);
                            $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars($club_info['nombre'] ?? 'Desconocido');
                        ?>"</strong> (ID: <?= $user_club_id ?>)
                    <?php else: ?>
                        <span class="text-danger">(No tiene club asignado)</span>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
    <?php
    // Cargar estadísticas para widgets
    require_once __DIR__ . '/../lib/StatisticsHelper.php';
    $stats = StatisticsHelper::generateStatistics();
    
    // Widget de estadísticas de torneos
    if (!empty($stats) && !isset($stats['error'])):
    ?>
    <div class="row g-4 mb-4">
        <?php if ($user_role === 'admin_general'): ?>
            <div class="col-md-2">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary mb-0"><?= number_format($stats['total_clubs'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Clubes Activos</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success mb-0"><?= number_format($stats['total_admin_clubs'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Admin. de organización</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h3 class="text-secondary mb-0"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Admin. Torneo</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <h3 class="text-dark mb-0"><?= number_format($stats['total_operadores'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Operadores</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info mb-0"><?= number_format($stats['total_users'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Total Usuarios</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning mb-0"><?= number_format($stats['total_active_users'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Usuarios Activos</p>
                    </div>
                </div>
            </div>
        <?php elseif ($user_role === 'admin_club' && !empty($stats['supervised_clubs'])): ?>
            <div class="col-md-2">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary mb-0"><?= number_format($stats['total_afiliados'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Total Afiliados</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success mb-0"><?= number_format($stats['afiliados_by_gender']['hombres'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Hombres</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <h3 class="text-danger mb-0"><?= number_format($stats['afiliados_by_gender']['mujeres'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Mujeres</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h3 class="text-secondary mb-0"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Admin. Torneo</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <h3 class="text-dark mb-0"><?= number_format($stats['total_operadores'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Operadores</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Enlaces por tipo cuando se entra a torneos de una organización -->
    <?php if ($action === 'list' && !empty($show_organizacion_links) && $organizacion_nombre_titulo): ?>
        <div class="card mb-4">
            <div class="card-body">
                <p class="text-muted mb-3">Seleccione el tipo de torneos que desea ver:</p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="index.php?page=tournaments&organizacion_id=<?= (int)$organizacion_id_param ?>&estado=realizados" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-flag-checkered me-2"></i>Realizados
                    </a>
                    <a href="index.php?page=tournaments&organizacion_id=<?= (int)$organizacion_id_param ?>&estado=en_proceso" class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-play-circle me-2"></i>En proceso
                    </a>
                    <a href="index.php?page=tournaments&organizacion_id=<?= (int)$organizacion_id_param ?>&estado=por_realizar" class="btn btn-outline-success btn-lg">
                        <i class="fas fa-calendar-alt me-2"></i>Por realizar
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Vista de Lista Lineal o agrupada por organización -->
    <div class="card">
        <div class="card-body">
            <?php if ($show_organizacion_links): ?>
                <p class="text-muted mb-0">Use los enlaces de arriba para ver los torneos por estado.</p>
            <?php elseif (!empty($tournaments_by_org)): ?>
                <?php foreach ($tournaments_by_org as $org_id => $org_data): ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-2 border-bottom">
                            <h5 class="mb-0"><i class="fas fa-sitemap me-2 text-primary"></i><?= htmlspecialchars($org_data['nombre']) ?></h5>
                            <a href="index.php?page=tournaments&organizacion_id=<?= (int)$org_id ?>" class="btn btn-sm btn-outline-primary">Ver torneos de esta organización</a>
                        </div>
                        <div class="row g-3">
                            <?php foreach ($org_data['items'] as $item): ?>
                                <?php $mostrar_org_en_card = false; ?>
                        <?php
                        $puede_acceder = Auth::canAccessTournament((int)$item['id']);
                        $puede_editar = Auth::canModifyTournament((int)$item['id']);
                        $puede_ver_solo = !$puede_editar && $puede_acceder;
                        $fecha_torneo = $item['fechator'] ? strtotime($item['fechator']) : null;
                        $es_futuro = $fecha_torneo && $fecha_torneo >= strtotime('today');
                        $es_pasado = $fecha_torneo && $fecha_torneo < strtotime('today');
                        $es_iniciado = ($fecha_torneo && $fecha_torneo <= strtotime('today'));
                        if (!$es_iniciado) {
                            try {
                                $stmt_r = DB::pdo()->prepare("SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND mesa > 0");
                                $stmt_r->execute([(int)$item['id']]);
                                $es_iniciado = (int)$stmt_r->fetchColumn() > 0;
                            } catch (Exception $e) { $es_iniciado = false; }
                        }
                        $estado_torneo = !$item['estatus'] ? 'Inactivo' : ($es_futuro ? 'Próximo' : ($es_pasado ? 'Finalizado' : 'Hoy'));
                        $estado_badge = !$item['estatus'] ? 'secondary' : ($es_futuro ? 'success' : ($es_pasado ? 'warning' : 'info'));
                        try {
                            $stmt_inscritos = DB::pdo()->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?");
                            $stmt_inscritos->execute([(int)$item['id']]);
                            $total_inscritos = (int)$stmt_inscritos->fetchColumn();
                        } catch (Exception $e) { $total_inscritos = 0; }
                        ?>
                                <div class="col-12">
                                    <div class="card border-left-primary shadow-sm h-100" style="border-left: 4px solid #0d6efd;">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-md-6">
                                                    <h5 class="card-title mb-1"><i class="fas fa-trophy text-warning me-2"></i><?= htmlspecialchars($item['nombre']) ?></h5>
                                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                                        <span class="badge bg-<?= $estado_badge ?>"><?= $estado_torneo ?></span>
                                                        <?= getClaseLabel($item['clase'] ?? 0) ?>
                                                        <?= getModalidadLabel($item['modalidad'] ?? 0) ?>
                                                    </div>
                                                    <div class="row g-2 mb-2">
                                                        <div class="col-auto"><small class="text-muted"><i class="fas fa-calendar-alt me-1"></i><strong>Fecha:</strong> <?= $item['fechator'] ? date('d/m/Y', strtotime($item['fechator'])) : 'Sin fecha' ?><?php
                                                            $hora_item = isset($item['hora_torneo']) && $item['hora_torneo'] !== '' ? $item['hora_torneo'] : (isset($item['hora']) ? $item['hora'] : '');
                                                            if ($hora_item !== '') {
                                                                echo ' <i class="fas fa-clock me-1"></i><strong>Hora:</strong> ' . htmlspecialchars(strlen($hora_item) >= 5 ? substr($hora_item, 0, 5) : $hora_item);
                                                            }
                                                        ?></small></div>
                                                        <?php if (!empty($item['lugar'])): ?><div class="col-auto"><small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><strong>Lugar:</strong> <?= htmlspecialchars($item['lugar']) ?></small></div><?php endif; ?>
                                                        <?php if (isset($item['tipo_torneo']) && (int)$item['tipo_torneo'] > 0): $tt = (int)$item['tipo_torneo']; ?><div class="col-auto"><small class="text-muted"><i class="fas fa-flag me-1"></i><strong>Tipo:</strong> <?= htmlspecialchars($tt === 1 ? 'Interclubes' : ($tt === 2 ? 'Suizo puro' : ($tt === 3 ? 'Suizo sin repetir' : ''))) ?></small></div><?php endif; ?>
                                                    </div>
                                                    <div class="row g-2">
                                                        <?php if ($item['costo'] > 0): ?><div class="col-auto"><small class="text-muted"><i class="fas fa-dollar-sign me-1"></i><strong>Costo:</strong> $<?= number_format((float)$item['costo'], 2) ?></small></div><?php endif; ?>
                                                        <div class="col-auto"><small class="text-muted"><i class="fas fa-users me-1"></i><strong>Inscritos:</strong> <span class="badge bg-primary"><?= number_format($total_inscritos) ?></span></small></div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                                        <?php if ($puede_acceder): ?><a href="panel_torneo.php?torneo_id=<?= $item['id'] ?>" class="btn btn-success btn-lg"><i class="fas fa-cog me-2"></i>Panel de Control</a><?php endif; ?>
                                                        <a href="index.php?page=registrants&torneo_id=<?= $item['id'] ?>" class="btn btn-primary"><i class="fas fa-users me-1"></i>Inscritos</a>
                                                        <a href="index.php?page=tournaments&action=view&id=<?= $item['id'] ?>" class="btn btn-outline-info"><i class="fas fa-eye me-1"></i>Ver</a>
                                                        <?php if ($puede_editar): ?>
                                                            <a href="index.php?page=tournaments&action=edit&id=<?= $item['id'] ?>" class="btn btn-outline-primary"><i class="fas fa-edit me-1"></i>Editar</a>
                                                            <?php if (!$es_iniciado && $total_inscritos === 0): ?><a href="index.php?page=tournaments&action=delete&id=<?= $item['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('¿Eliminar este torneo?');" title="Eliminar torneo"><i class="fas fa-trash me-1"></i>Eliminar</a><?php elseif (!$es_iniciado && $total_inscritos > 0): ?><span class="btn btn-outline-secondary btn-sm" title="No se puede eliminar: tiene <?= $total_inscritos ?> inscrito(s)"><i class="fas fa-trash me-1"></i>Eliminar</span><?php endif; ?>
                                                        <?php elseif ($puede_ver_solo): ?><button class="btn btn-outline-secondary" disabled><i class="fas fa-lock me-1"></i>Bloqueado</button><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php elseif (empty($tournaments_list)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No hay torneos registrados.
                    <a href="index.php?page=tournaments&action=new" class="alert-link">Crear el primer torneo</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($tournaments_list as $item): ?>
                        <?php $mostrar_org_en_card = empty($organizacion_id_param); ?>
                        <?php
                        // Verificar permisos de acceso
                        $puede_acceder = Auth::canAccessTournament((int)$item['id']);
                        $puede_editar = Auth::canModifyTournament((int)$item['id']);
                        $puede_ver_solo = !$puede_editar && $puede_acceder;
                        
                        // Determinar estado del torneo
                        $fecha_torneo = $item['fechator'] ? strtotime($item['fechator']) : null;
                        $es_futuro = $fecha_torneo && $fecha_torneo >= strtotime('today');
                        $es_pasado = $fecha_torneo && $fecha_torneo < strtotime('today');
                        // Torneo iniciado: no se puede eliminar (fecha pasada/hoy o tiene rondas generadas)
                        $es_iniciado = false;
                        if ($fecha_torneo && $fecha_torneo <= strtotime('today')) {
                            $es_iniciado = true;
                        }
                        if (!$es_iniciado) {
                            try {
                                $stmt_r = DB::pdo()->prepare("SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND mesa > 0");
                                $stmt_r->execute([(int)$item['id']]);
                                $es_iniciado = (int)$stmt_r->fetchColumn() > 0;
                            } catch (Exception $e) {
                                $es_iniciado = false;
                            }
                        }
                        $estado_torneo = '';
                        $estado_badge = '';
                        if (!$item['estatus']) {
                            $estado_torneo = 'Inactivo';
                            $estado_badge = 'secondary';
                        } elseif ($es_futuro) {
                            $estado_torneo = 'Próximo';
                            $estado_badge = 'success';
                        } elseif ($es_pasado) {
                            $estado_torneo = 'Finalizado';
                            $estado_badge = 'warning';
                        } else {
                            $estado_torneo = 'Hoy';
                            $estado_badge = 'info';
                        }
                        
                        // Obtener total de inscritos
                        try {
                            $stmt_inscritos = DB::pdo()->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?");
                            $stmt_inscritos->execute([(int)$item['id']]);
                            $total_inscritos = (int)$stmt_inscritos->fetchColumn();
                        } catch (Exception $e) {
                            $total_inscritos = 0;
                        }
                        ?>
                        <div class="col-12">
                            <div class="card border-left-primary shadow-sm h-100" style="border-left: 4px solid #0d6efd;">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <!-- Información Principal -->
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-start mb-2">
                                                <div class="flex-grow-1">
                                                    <h5 class="card-title mb-1">
                                                        <i class="fas fa-trophy text-warning me-2"></i>
                                                        <?= htmlspecialchars($item['nombre']) ?>
                                                    </h5>
                                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                                        <span class="badge bg-<?= $estado_badge ?>"><?= $estado_torneo ?></span>
                                                        <?= getClaseLabel($item['clase'] ?? 0) ?>
                                                        <?= getModalidadLabel($item['modalidad'] ?? 0) ?>
                                                    </div>
                                                </div>
                                                <small class="text-muted">ID: <?= htmlspecialchars((string)$item['id']) ?></small>
                                            </div>
                                            
                                            <div class="row g-2 mb-2">
                                                <div class="col-auto">
                                                    <small class="text-muted">
                                                        <i class="fas fa-calendar-alt me-1"></i>
                                                        <strong>Fecha:</strong> <?= $item['fechator'] ? date('d/m/Y', strtotime($item['fechator'])) : 'Sin fecha' ?>
                                                        <?php $hora_l = isset($item['hora_torneo']) && $item['hora_torneo'] !== '' ? $item['hora_torneo'] : (isset($item['hora']) ? $item['hora'] : ''); if ($hora_l !== '') { echo ' · <strong>Hora:</strong> ' . htmlspecialchars(strlen($hora_l) >= 5 ? substr($hora_l, 0, 5) : $hora_l); } ?>
                                                        <?php if (isset($item['tipo_torneo']) && (int)$item['tipo_torneo'] > 0) { $tt = (int)$item['tipo_torneo']; echo ' · <strong>Tipo:</strong> ' . htmlspecialchars($tt === 1 ? 'Interclubes' : ($tt === 2 ? 'Suizo puro' : ($tt === 3 ? 'Suizo sin repetir' : ''))); } ?>
                                                    </small>
                                                </div>
                                                <?php if (!empty($item['lugar'])): ?>
                                                <div class="col-auto">
                                                    <small class="text-muted">
                                                        <i class="fas fa-map-marker-alt me-1"></i>
                                                        <strong>Lugar:</strong> <?= htmlspecialchars($item['lugar']) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="row g-2">
                                                <?php if (!empty($mostrar_org_en_card) && !empty($item['organizacion_nombre'])): ?>
                                                <div class="col-auto">
                                                    <small class="text-muted">
                                                        <i class="fas fa-sitemap me-1"></i>
                                                        <strong>Organización:</strong> <span class="badge bg-info"><?= htmlspecialchars($item['organizacion_nombre']) ?></span>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($item['costo'] > 0): ?>
                                                <div class="col-auto">
                                                    <small class="text-muted">
                                                        <i class="fas fa-dollar-sign me-1"></i>
                                                        <strong>Costo:</strong> $<?= number_format((float)$item['costo'], 2) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <div class="col-auto">
                                                    <small class="text-muted">
                                                        <i class="fas fa-users me-1"></i>
                                                        <strong>Inscritos:</strong> <span class="badge bg-primary"><?= number_format($total_inscritos) ?></span>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Acciones: Ver, Editar y Eliminar (solo torneos no iniciados) -->
                                        <div class="col-md-6">
                                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                                <?php if ($puede_acceder): ?>
                                                    <a href="panel_torneo.php?torneo_id=<?= $item['id'] ?>" 
                                                       class="btn btn-success btn-lg flex-grow-1 flex-md-grow-0">
                                                        <i class="fas fa-cog me-2"></i>Panel de Control
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <a href="index.php?page=registrants&torneo_id=<?= $item['id'] ?>" 
                                                   class="btn btn-primary">
                                                    <i class="fas fa-users me-1"></i>Inscritos
                                                </a>
                                                
                                                <a href="index.php?page=tournaments&action=view&id=<?= $item['id'] ?>" 
                                                   class="btn btn-outline-info" title="Ver detalles del torneo">
                                                    <i class="fas fa-eye me-1"></i>Ver
                                                </a>
                                                
                                                <?php if ($puede_editar): ?>
                                                    <a href="index.php?page=tournaments&action=edit&id=<?= $item['id'] ?>" 
                                                       class="btn btn-outline-primary" title="Editar y actualizar el torneo">
                                                        <i class="fas fa-edit me-1"></i>Editar
                                                    </a>
                                                    <?php if (!$es_iniciado && $total_inscritos === 0): ?>
                                                    <a href="index.php?page=tournaments&action=delete&id=<?= $item['id'] ?>" 
                                                       class="btn btn-outline-danger"
                                                       onclick="return confirm('¿Está seguro de eliminar el torneo \'<?= htmlspecialchars($item['nombre'], ENT_QUOTES) ?>\'?');"
                                                       title="Eliminar torneo">
                                                        <i class="fas fa-trash me-1"></i>Eliminar
                                                    </a>
                                                    <?php elseif (!$es_iniciado && $total_inscritos > 0): ?>
                                                    <span class="btn btn-outline-secondary" title="No se puede eliminar: tiene <?= (int)$total_inscritos ?> inscrito(s)">
                                                        <i class="fas fa-trash me-1"></i>Eliminar
                                                    </span>
                                                    <?php endif; ?>
                                                <?php elseif ($puede_ver_solo): ?>
                                                    <button class="btn btn-outline-secondary" disabled title="No puede modificar torneos pasados">
                                                        <i class="fas fa-lock me-1"></i>Bloqueado
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Paginación -->
                <?php if ($pagination): ?>
                    <div class="mt-4">
                        <?= $pagination->render() ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($action === 'view'): 
    // Obtener estadísticas adicionales del torneo
    $tournament_id = (int)$tournament['id'];
    $stats = [];
    
    try {
        // Total de inscritos
        $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?");
        $stmt->execute([$tournament_id]);
        $stats['total_inscritos'] = (int)$stmt->fetchColumn();
        
        // Inscritos confirmados
        $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND estatus = 'confirmado'");
        $stmt->execute([$tournament_id]);
        $stats['inscritos_confirmados'] = (int)$stmt->fetchColumn();
        
        // Estadísticas por género
        $stmt = DB::pdo()->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN u.sexo = 'M' OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 'F' OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND i.estatus != 'retirado'
        ");
        $stmt->execute([$tournament_id]);
        $gender_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['hombres'] = (int)($gender_stats['hombres'] ?? 0);
        $stats['mujeres'] = (int)($gender_stats['mujeres'] ?? 0);
        
        // Rondas generadas
        $stmt = DB::pdo()->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) as ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
        $stmt->execute([$tournament_id]);
        $stats['rondas_generadas'] = (int)($stmt->fetchColumn() ?? 0);
        
        // Total de partidas registradas
        $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND registrado = 1");
        $stmt->execute([$tournament_id]);
        $stats['total_partidas'] = (int)$stmt->fetchColumn();
        
        // Total de clubes participantes
        $stmt = DB::pdo()->prepare("SELECT COUNT(DISTINCT id_club) FROM inscritos WHERE torneo_id = ? AND id_club IS NOT NULL AND id_club > 0");
        $stmt->execute([$tournament_id]);
        $stats['clubes_participantes'] = (int)$stmt->fetchColumn();
        
        // Para modalidad equipos
        if ((int)$tournament['modalidad'] === 3) {
            $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM equipos WHERE id_torneo = ?");
            $stmt->execute([$tournament_id]);
            $stats['total_equipos'] = (int)$stmt->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("Error al obtener estadísticas del torneo: " . $e->getMessage());
        $stats = [
            'total_inscritos' => 0,
            'inscritos_confirmados' => 0,
            'hombres' => 0,
            'mujeres' => 0,
            'rondas_generadas' => 0,
            'total_partidas' => 0,
            'clubes_participantes' => 0
        ];
    }
?>
    <!-- Vista Individual Completa -->
    <div class="container-fluid">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0">
                                    <i class="fas fa-trophy me-2"></i><?= htmlspecialchars($tournament['nombre']) ?>
                                </h4>
                                <small class="opacity-75">Información Completa del Torneo</small>
                            </div>
                            <span class="badge bg-<?= $tournament['estatus'] ? 'success' : 'secondary' ?> fs-6">
                                <?= $tournament['estatus'] ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Estadísticas Rápidas -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-left-primary shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?= number_format($stats['total_inscritos']) ?></h3>
                                <p class="text-muted mb-0"><i class="fas fa-users me-1"></i>Total Inscritos</p>
                            </div>
                            <div class="fs-1 opacity-25 text-primary">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card border-left-success shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?= number_format($stats['inscritos_confirmados']) ?></h3>
                                <p class="text-muted mb-0"><i class="fas fa-check-circle me-1"></i>Confirmados</p>
                            </div>
                            <div class="fs-1 opacity-25 text-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card border-left-info shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?= number_format($stats['rondas_generadas']) ?>/<?= (int)($tournament['rondas'] ?? 0) ?></h3>
                                <p class="text-muted mb-0"><i class="fas fa-redo me-1"></i>Rondas</p>
                            </div>
                            <div class="fs-1 opacity-25 text-info">
                                <i class="fas fa-redo"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card border-left-warning shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?= number_format($stats['total_partidas']) ?></h3>
                                <p class="text-muted mb-0"><i class="fas fa-gamepad me-1"></i>Partidas</p>
                            </div>
                            <div class="fs-1 opacity-25 text-warning">
                                <i class="fas fa-gamepad"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Información Detallada -->
        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>Información General</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-calendar me-2 text-primary"></i>Fecha del Torneo</label>
                                    <p class="fs-5"><?= $tournament['fechator'] ? date('d/m/Y', strtotime($tournament['fechator'])) : 'Sin fecha' ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-clock me-2 text-primary"></i>Hora</label>
                                    <p class="fs-5"><?php
                                    $hora_ver = isset($tournament['hora_torneo']) && $tournament['hora_torneo'] !== '' && $tournament['hora_torneo'] !== null
                                        ? $tournament['hora_torneo'] : (isset($tournament['hora']) ? $tournament['hora'] : null);
                                    if ($hora_ver && is_string($hora_ver)) {
                                        echo htmlspecialchars(strlen($hora_ver) >= 5 ? substr($hora_ver, 0, 5) : $hora_ver);
                                    } else {
                                        echo '<span class="text-muted">No especificada</span>';
                                    }
                                    ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-flag me-2 text-primary"></i>Tipo de torneo</label>
                                    <p class="fs-5"><?php
                                    $tt = isset($tournament['tipo_torneo']) ? (int)$tournament['tipo_torneo'] : 0;
                                    echo $tt === 1 ? 'Interclubes' : ($tt === 2 ? 'Suizo puro' : ($tt === 3 ? 'Suizo sin repetir' : '<span class="text-muted">No definido</span>'));
                                    ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-map-marker-alt me-2 text-primary"></i>Lugar</label>
                                    <p class="fs-5"><?= !empty($tournament['lugar']) ? htmlspecialchars($tournament['lugar']) : '<span class="text-muted">No especificado</span>' ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-list-alt me-2 text-primary"></i>Clase</label>
                                    <p class="fs-5"><span class="badge bg-primary"><?= getClaseLabel($tournament['clase'] ?? 0) ?></span></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-users me-2 text-primary"></i>Modalidad</label>
                                    <p class="fs-5"><span class="badge bg-info"><?= getModalidadLabel($tournament['modalidad'] ?? 0) ?></span></p>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-clock me-2 text-primary"></i>Tiempo por Partida</label>
                                    <p class="fs-5"><?= (int)($tournament['tiempo'] ?? 0) ?> minutos</p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-star me-2 text-primary"></i>Puntos Base</label>
                                    <p class="fs-5"><?= (int)($tournament['puntos'] ?? 0) ?> puntos</p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-redo me-2 text-primary"></i>Total de Rondas</label>
                                    <p class="fs-5"><?= (int)($tournament['rondas'] ?? 0) ?> rondas</p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-dollar-sign me-2 text-primary"></i>Costo de Inscripción</label>
                                    <p class="fs-5">$<?= number_format((float)($tournament['costo'] ?? 0), 2) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-sitemap me-2 text-primary"></i>Organización</label>
                                    <p class="fs-5">
                                        <?php if (!empty($tournament['organizacion_nombre'])): ?>
                                            <span class="badge bg-info fs-6"><?= htmlspecialchars($tournament['organizacion_nombre']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">No asignada</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-muted"><i class="fas fa-building me-2 text-primary"></i>Clubes Participantes</label>
                                    <p class="fs-5"><span class="badge bg-success fs-6"><?= number_format($stats['clubes_participantes']) ?> clubes</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>Estadísticas</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span><i class="fas fa-mars me-2 text-primary"></i>Hombres</span>
                                <strong><?= number_format($stats['hombres']) ?></strong>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary" role="progressbar" 
                                     style="width: <?= $stats['total_inscritos'] > 0 ? ($stats['hombres'] / $stats['total_inscritos'] * 100) : 0 ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span><i class="fas fa-venus me-2 text-danger"></i>Mujeres</span>
                                <strong><?= number_format($stats['mujeres']) ?></strong>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-danger" role="progressbar" 
                                     style="width: <?= $stats['total_inscritos'] > 0 ? ($stats['mujeres'] / $stats['total_inscritos'] * 100) : 0 ?>%"></div>
                            </div>
                        </div>
                        
                        <?php if (isset($stats['total_equipos'])): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span><i class="fas fa-users-cog me-2 text-info"></i>Equipos</span>
                                <strong><?= number_format($stats['total_equipos']) ?></strong>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Última actualización: <?= date('d/m/Y H:i') ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Acciones -->
        <div class="card shadow-sm">
            <div class="card-footer bg-white">
                <div class="d-flex flex-wrap gap-2">
                    <a href="index.php?page=tournaments" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Volver a la Lista
                    </a>
                    <?php if (Auth::canAccessTournament($tournament_id)): ?>
                        <a href="panel_torneo.php?torneo_id=<?= $tournament_id ?>" class="btn btn-success">
                            <i class="fas fa-cog me-2"></i>Panel de Control
                        </a>
                        <a href="index.php?page=registrants&torneo_id=<?= $tournament_id ?>" class="btn btn-info">
                            <i class="fas fa-users me-2"></i>Ver Inscritos
                        </a>
                    <?php endif; ?>
                    <?php 
                    $tournament_finalizado = isset($tournament['finalizado']) && $tournament['finalizado'] == 1;
                    $admin_general_puede_editar_finalizado = Auth::isAdminGeneral();
                    if (Auth::canModifyTournament($tournament_id) && (!$tournament_finalizado || $admin_general_puede_editar_finalizado)): ?>
                        <a href="index.php?page=tournaments&action=edit&id=<?= $tournament_id ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i><?= ($tournament_finalizado && $admin_general_puede_editar_finalizado) ? 'Editar (corrección)' : 'Editar' ?>
                        </a>
                    <?php elseif ($tournament_finalizado && !$admin_general_puede_editar_finalizado): ?>
                        <span class="badge bg-danger fs-6 align-self-center">
                            <i class="fas fa-lock me-2"></i>Torneo Finalizado
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($action === 'new' || $action === 'edit'): ?>
    <?php
    // admin_club sin organización no puede crear torneos
    $puede_mostrar_form = true;
    if ($action === 'new' && $user_role === 'admin_club' && empty($default_organizacion_id)) {
        $puede_mostrar_form = false;
    }
    if ($action === 'new' && $user_role === 'admin_torneo' && empty($user_club_id)) {
        $puede_mostrar_form = false;
    }
    ?>
    <?php if (!$puede_mostrar_form): ?>
        <div class="container-fluid py-4">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php if ($user_role === 'admin_club'): ?>
                    No tiene una organización asignada. Contacte al administrador para poder crear torneos.
                <?php else: ?>
                    Su usuario no tiene un club asignado. Contacte al administrador para poder crear torneos.
                <?php endif; ?>
            </div>
            <a href="<?= htmlspecialchars((isset($dashboard_href) && is_callable($dashboard_href)) ? $dashboard_href('torneo_gestion', ['action' => 'index']) : 'index.php?page=torneo_gestion&action=index') ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
        </div>
    <?php else: ?>
    <!-- Formulario -->
    <div class="d-flex justify-content-center align-items-center min-vh-100 py-5" style="background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);">
        <div class="card shadow-lg tournament-form-card" style="max-width: 60%; width: 60%;">
            <div class="card-header bg-<?= $action === 'edit' ? 'warning' : 'success' ?> text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-<?= $action === 'edit' ? 'edit' : 'plus-circle' ?> me-2"></i>
                    <?= $action === 'edit' ? 'Editar' : 'Nuevo' ?> Torneo
                </h5>
            </div>
            <div class="card-body">
            <?php
            $form_params = ['page' => 'tournaments', 'action' => $action === 'edit' ? 'update' : 'save'];
            if ($action === 'edit') {
                $form_params['id'] = (int)$tournament['id'];
            }
            // Usar SCRIPT_NAME para garantizar que el POST llegue al mismo endpoint (evita fallos con base href o subcarpetas)
            $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
            $form_action = $script_path . '?' . http_build_query($form_params);
            ?>
            <form method="POST" action="<?= htmlspecialchars($form_action) ?>" enctype="multipart/form-data">
                <?= CSRF::input(); ?>
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)$tournament['id'] ?>">
                <?php endif;
                $form_org_id = ($action === 'edit' && isset($tournament['organizacion_ref'])) ? (int)$tournament['organizacion_ref'] : (int)($default_organizacion_id ?? 0);
                if ($action === 'new' && $organizacion_id_cuentas_new !== null && $organizacion_id_cuentas_new > 0) {
                    $form_org_id = (int)$organizacion_id_cuentas_new;
                }
                $form_org_logo = null;
                if ($form_org_id > 0) {
                    $stmt_logo = DB::pdo()->prepare($has_cod_org
                        ? "SELECT logo, nombre FROM organizaciones WHERE (id = ? OR cod_org = ?)"
                        : "SELECT logo, nombre FROM organizaciones WHERE id = ?");
                    $stmt_logo->execute($has_cod_org ? [$form_org_id, $form_org_id] : [$form_org_id]);
                    $row_org = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                    $form_org_logo = $row_org['logo'] ?? null;
                    $form_org_nombre_display = $row_org['nombre'] ?? ($action === 'edit' ? ($tournament['organizacion_nombre'] ?? '') : ($default_organizacion_nombre ?? ''));
                } else {
                    $form_org_nombre_display = '';
                }
                $base_asset = isset($layout_asset_base) ? rtrim($layout_asset_base, '/') : (class_exists('AppHelpers') && method_exists('AppHelpers', 'getPublicUrl') ? rtrim(AppHelpers::getPublicUrl(), '/') : '');
                ?>
                
                <!-- Fila 1: Organización + logo -->
                <div class="row align-items-center mb-3 pb-3 border-bottom">
                    <div class="col d-flex align-items-center gap-3">
                        <?php if ($is_admin_general): ?>
                        <input type="hidden" name="club_responsable" value="<?= (int)(class_exists('FvdConfig') ? FvdConfig::organizacionId() : 1) ?>">
                        <div class="flex-grow-1">
                            <label class="form-label mb-1 text-muted">Organizador</label>
                            <p class="mb-0 fs-5 fw-bold"><?= htmlspecialchars(class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_NOMBRE : 'FEDERACION VENEZOLANA DE DOMINO') ?></p>
                        </div>
                        <?php else: ?>
                        <?php $org_id = ($action === 'edit' && isset($tournament['organizacion_ref'])) ? (int)$tournament['organizacion_ref'] : (int)($default_organizacion_id ?? 0); ?>
                        <input type="hidden" name="club_responsable" value="<?= $org_id ?>">
                        <div class="flex-grow-1">
                            <label class="form-label mb-1 text-muted">Organización</label>
                            <p class="mb-0 fs-5 fw-bold"><?= htmlspecialchars($action === 'edit' ? ($tournament['organizacion_nombre'] ?? '') : ($default_organizacion_nombre ?? '—')) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($form_org_logo) && class_exists('AppHelpers')): ?>
                        <div class="flex-shrink-0">
                            <img src="<?= htmlspecialchars(AppHelpers::imageUrl($form_org_logo)) ?>" alt="Logo" class="rounded" style="max-height: 60px; max-width: 120px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Fila 2: Nombre, Lugar -->
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del Torneo *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                   value="<?= htmlspecialchars($action === 'edit' ? $tournament['nombre'] : '') ?>" 
                                   required placeholder="Ej: Torneo Nacional de Dominó 2025">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="lugar" class="form-label">Lugar</label>
                            <input type="text" class="form-control" id="lugar" name="lugar" 
                                   value="<?= htmlspecialchars($action === 'edit' ? ($tournament['lugar'] ?? '') : '') ?>" 
                                   placeholder="Ej: Club Central, Sala Principal">
                        </div>
                    </div>
                </div>
                
                <!-- Fila 3: Fecha, Hora, Tiempo, Puntos, Rondas -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="fechator" class="form-label">Fecha *</label>
                            <input type="date" class="form-control" id="fechator" name="fechator" 
                                   value="<?= htmlspecialchars($action === 'edit' ? ($tournament['fechator'] ?? '') : '') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="hora_torneo" class="form-label">Hora</label>
                            <input type="time" class="form-control" id="hora_torneo" name="hora_torneo" 
                                   value="<?php
                                   if ($action === 'edit' && !empty($tournament['hora_torneo'])) { $ht = $tournament['hora_torneo']; echo htmlspecialchars(strlen($ht) >= 5 ? substr($ht, 0, 5) : $ht); }
                                   elseif ($action === 'edit' && isset($tournament['hora']) && $tournament['hora'] !== '') { $ht = $tournament['hora']; echo htmlspecialchars(strlen($ht) >= 5 ? substr($ht, 0, 5) : $ht); }
                                   else { echo ''; }
                                   ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="tiempo" class="form-label">Tiempo (min)</label>
                            <input type="number" class="form-control" id="tiempo" name="tiempo" 
                                   value="<?= $action === 'edit' ? (int)($tournament['tiempo'] ?? 35) : 35 ?>" min="0" placeholder="35">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="puntos" class="form-label">Puntos</label>
                            <input type="number" class="form-control" id="puntos" name="puntos" 
                                   value="<?= $action === 'edit' ? (int)($tournament['puntos'] ?? 200) : 200 ?>" min="0" placeholder="200">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="rondas" class="form-label">Rondas</label>
                            <input type="number" class="form-control" id="rondas" name="rondas" 
                                   value="<?= $action === 'edit' ? (int)($tournament['rondas'] ?? 9) : 9 ?>" min="0" placeholder="9">
                        </div>
                    </div>
                </div>
                
                <!-- Fila 4: Clase, Modalidad, Tipo torneo, Ranking, Jugadores club -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="clase" class="form-label">Clase *</label>
                            <select class="form-select" id="clase" name="clase" required>
                                <option value="">Seleccionar...</option>
                                <?php $clase_actual = ($action === 'edit' && isset($tournament['clase'])) ? getClaseNumero($tournament['clase']) : 0; ?>
                                <option value="1"<?= $clase_actual === 1 ? ' selected' : '' ?>>Torneo</option>
                                <option value="2"<?= $clase_actual === 2 ? ' selected' : '' ?>>Campeonato</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="modalidad" class="form-label">Modalidad *</label>
                            <select class="form-select" id="modalidad" name="modalidad" required>
                                <option value="">Seleccionar...</option>
                                <?php $modalidad_actual = ($action === 'edit' && isset($tournament['modalidad'])) ? getModalidadNumero($tournament['modalidad']) : 0; ?>
                                <option value="1"<?= $modalidad_actual === 1 ? ' selected' : '' ?>>Individual</option>
                                <option value="2"<?= $modalidad_actual === 2 ? ' selected' : '' ?>>Parejas</option>
                                <option value="3"<?= $modalidad_actual === 3 ? ' selected' : '' ?>>Equipos</option>
                                <option value="4"<?= $modalidad_actual === 4 ? ' selected' : '' ?>>Parejas fijas</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="tipo_torneo" class="form-label">Tipo torneo</label>
                            <select class="form-select" id="tipo_torneo" name="tipo_torneo">
                                <?php $tt_val = isset($tournament['tipo_torneo']) ? (int)$tournament['tipo_torneo'] : 0; ?>
                                <option value="0"<?= $tt_val === 0 ? ' selected' : '' ?>>No definido</option>
                                <option value="1"<?= $tt_val === 1 ? ' selected' : '' ?>>Interclubes</option>
                                <option value="2"<?= $tt_val === 2 ? ' selected' : '' ?>>Suizo puro</option>
                                <option value="3"<?= $tt_val === 3 ? ' selected' : '' ?>>Suizo sin repetir</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="ranking" class="form-label">Ranking</label>
                            <select class="form-select" id="ranking" name="ranking">
                                <option value="0"<?= ($action === 'edit' && ($tournament['ranking'] ?? 0) == 0) ? ' selected' : '' ?>>No</option>
                                <option value="1"<?= ($action === 'edit' && ($tournament['ranking'] ?? 0) == 1) ? ' selected' : '' ?>>Sí</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="pareclub" class="form-label">Jugadores por club</label>
                            <input type="number" class="form-control" id="pareclub" name="pareclub" 
                                   value="<?= $action === 'edit' ? (int)($tournament['pareclub'] ?? 0) : '' ?>" min="0" step="1" placeholder="0">
                        </div>
                    </div>
                </div>
                
                <!-- Fila 5: Costo, Estado, Evento, Inscripción línea, Publicación, Cuenta bancaria -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="costo" class="form-label">Costo</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="costo" name="costo" step="0.01"
                                       value="<?= number_format((float)($action === 'edit' ? ($tournament['costo'] ?? 0) : 0), 2, '.', '') ?>" min="0" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="estatus" class="form-label">Estado *</label>
                            <select class="form-select" id="estatus" name="estatus" required>
                                <option value="1"<?= ($action === 'edit' && ($tournament['estatus'] ?? 1) == 1) ? ' selected' : ' selected' ?>>Activo</option>
                                <option value="0"<?= ($action === 'edit' && ($tournament['estatus'] ?? 1) == 0) ? ' selected' : '' ?>>Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="es_evento_masivo" class="form-label">Evento *</label>
                            <select class="form-select" id="es_evento_masivo" name="es_evento_masivo" required>
                                <option value="0"<?= ($action === 'edit' && isset($tournament['es_evento_masivo']) && (int)$tournament['es_evento_masivo'] == 0) ? ' selected' : (!isset($tournament['es_evento_masivo']) ? ' selected' : '') ?>>Ninguno</option>
                                <option value="1"<?= ($action === 'edit' && isset($tournament['es_evento_masivo']) && (int)$tournament['es_evento_masivo'] == 1) ? ' selected' : '' ?>>Nacional</option>
                                <option value="2"<?= ($action === 'edit' && isset($tournament['es_evento_masivo']) && (int)$tournament['es_evento_masivo'] == 2) ? ' selected' : '' ?>>Regional</option>
                                <option value="3"<?= ($action === 'edit' && isset($tournament['es_evento_masivo']) && (int)$tournament['es_evento_masivo'] == 3) ? ' selected' : '' ?>>Local</option>
                                <option value="4"<?= ($action === 'edit' && isset($tournament['es_evento_masivo']) && (int)$tournament['es_evento_masivo'] == 4) ? ' selected' : '' ?>>Privado</option>
                            </select>
                            <small class="form-text text-muted d-block" id="tipo-evento-info-wrap"><span id="tipo-evento-info">Ninguno: torneo normal.</span></small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="permite_inscripcion_linea" name="permite_inscripcion_linea" value="1"
                                    <?= ($action === 'edit' ? (($tournament['permite_inscripcion_linea'] ?? 1) == 1) : true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="permite_inscripcion_linea">Inscripción en línea</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="publicar_landing" name="publicar_landing" value="1"
                                    <?= ($action === 'edit' ? (($tournament['publicar_landing'] ?? 1) == 1) : false) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="publicar_landing">Publicar en portal</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="cuenta_id" class="form-label">Cuenta bancaria</label>
                                <?php
                                // Solo mostrar la(s) cuenta(s) del administrador de la organización del torneo
                                $cuentas_disponibles = [];
                                try {
                                    $pdo_cuentas = DB::pdo();
                                    $org_id_para_cuentas = 0;
                                    if ($action === 'edit' && isset($tournament['organizacion_ref']) && (int)$tournament['organizacion_ref'] > 0) {
                                        $org_id_para_cuentas = (int)$tournament['organizacion_ref'];
                                    } elseif ($action === 'new' && $organizacion_id_cuentas_new !== null && $organizacion_id_cuentas_new > 0) {
                                        $org_id_para_cuentas = $organizacion_id_cuentas_new;
                                    } else {
                                        $org_id_para_cuentas = (int)($default_organizacion_id ?? 0);
                                    }
                                    $admin_org_user_id = null;
                                    if ($org_id_para_cuentas > 0) {
                                        $stmt_admin = $pdo_cuentas->prepare($has_cod_org
                                            ? "SELECT admin_user_id FROM organizaciones WHERE (id = ? OR cod_org = ?) AND estatus = 1"
                                            : "SELECT admin_user_id FROM organizaciones WHERE id = ? AND estatus = 1");
                                        $stmt_admin->execute($has_cod_org ? [$org_id_para_cuentas, $org_id_para_cuentas] : [$org_id_para_cuentas]);
                                        $admin_org_user_id = $stmt_admin->fetchColumn();
                                    }
                                    if ($admin_org_user_id !== false && $admin_org_user_id !== null) {
                                        $admin_org_user_id = (int)$admin_org_user_id;
                                        $has_owner_column = false;
                                        try {
                                            $cols = $pdo_cuentas->query("SHOW COLUMNS FROM cuentas_bancarias")->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($cols as $col) {
                                                if (strtolower($col['Field'] ?? $col['field'] ?? '') === 'owner_user_id') {
                                                    $has_owner_column = true;
                                                    break;
                                                }
                                            }
                                        } catch (Exception $e) {
                                            $has_owner_column = false;
                                        }
                                        if ($has_owner_column) {
                                            $stmt_cuentas = $pdo_cuentas->prepare("
                                                SELECT id, banco, numero_cuenta, nombre_propietario, tipo_cuenta, telefono_afiliado
                                                FROM cuentas_bancarias
                                                WHERE estatus = 1 AND owner_user_id = ?
                                                ORDER BY banco, nombre_propietario ASC
                                            ");
                                            $stmt_cuentas->execute([$admin_org_user_id]);
                                            $cuentas_disponibles = $stmt_cuentas->fetchAll(PDO::FETCH_ASSOC);
                                        }
                                    }
                                } catch (Exception $e) {
                                    error_log("Error obteniendo cuentas bancarias: " . $e->getMessage());
                                }
                                ?>
                                <select class="form-select" id="cuenta_id" name="cuenta_id">
                                    <option value="">Ninguna (Pago en sitio)</option>
                                    <?php foreach ($cuentas_disponibles as $cuenta): ?>
                                    <option value="<?= $cuenta['id'] ?>" 
                                            <?= ($action === 'edit' && isset($tournament['cuenta_id']) && (int)$tournament['cuenta_id'] === (int)$cuenta['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cuenta['banco']) ?> - 
                                        <?= htmlspecialchars($cuenta['nombre_propietario']) ?>
                                        <?php if (!empty($cuenta['numero_cuenta'])): ?>
                                            (<?= htmlspecialchars($cuenta['numero_cuenta']) ?>)
                                        <?php elseif (!empty($cuenta['telefono_afiliado'])): ?>
                                            (Pago Móvil: <?= htmlspecialchars($cuenta['telefono_afiliado']) ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">
                                    <?php 
                                    $returnParams = [
                                        'action' => 'new',
                                        'return_torneo' => 1,
                                        'torneo_action' => $action === 'edit' ? 'edit' : 'new',
                                    ];
                                    if ($action === 'edit' && !empty($tournament['id'])) {
                                        $returnParams['torneo_id'] = (int) $tournament['id'];
                                    }
                                    $return_torneo_url = AppHelpers::dashboard('cuentas_bancarias', $returnParams);
                                    ?>
                                    <a href="<?= htmlspecialchars($return_torneo_url) ?>" class="text-decoration-none">
                                        <i class="fas fa-plus-circle"></i> Crear nueva cuenta
                                    </a>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sección de Archivos del Torneo -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h5 class="border-bottom pb-2 mb-3">
                            <i class="fas fa-file-upload me-2 text-primary"></i>Archivos del Torneo
                        </h5>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="afiche" class="form-label">
                                <i class="fas fa-image me-1"></i>Afiche del Torneo
                            </label>
                            <?php if ($action === 'edit' && !empty($tournament['afiche'])): 
                                $afiche_url = (isset($layout_asset_base) ? $layout_asset_base : AppHelpers::getPublicUrl()) . '/view_tournament_file.php?file=' . urlencode(str_replace('upload/tournaments/', '', $tournament['afiche']));
                                $afiche_ext = strtolower(pathinfo($tournament['afiche'], PATHINFO_EXTENSION));
                            ?>
                                <div class="alert alert-info py-2 mb-2">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Archivo actual:
                                    <a href="<?= htmlspecialchars($afiche_url) ?>" target="_blank" class="alert-link" rel="noopener"><i class="fas fa-external-link-alt me-1"></i>Abrir</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-1" onclick="abrirVistaPrevia('<?= htmlspecialchars($afiche_url, ENT_QUOTES) ?>', '<?= htmlspecialchars($afiche_ext) ?>', 'Afiche')">
                                        <i class="fas fa-eye me-1"></i>Vista previa
                                    </button>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="afiche" name="afiche" 
                                   accept=".pdf,.jpg,.jpeg,.png,.gif" data-preview-target="afiche-preview">
                            <small class="form-text text-muted">
                                Formatos: PDF, JPG, PNG, GIF (m�x. 5MB)
                            </small>
                            <div id="afiche-preview"></div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="invitacion" class="form-label">
                                <i class="fas fa-envelope me-1"></i>Invitación Oficial
                            </label>
                            <?php if ($action === 'edit' && !empty($tournament['invitacion'])): ?>
                                <div class="alert alert-info py-2 mb-2">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Archivo actual: 
                                    <?php $invitacion_url = (isset($layout_asset_base) ? $layout_asset_base : AppHelpers::getPublicUrl()) . '/view_tournament_file.php?file=' . urlencode(str_replace('upload/tournaments/', '', $tournament['invitacion'])); $invitacion_ext = strtolower(pathinfo($tournament['invitacion'], PATHINFO_EXTENSION)); ?>
                                    <a href="<?= htmlspecialchars($invitacion_url) ?>" target="_blank" class="alert-link" rel="noopener"><i class="fas fa-external-link-alt me-1"></i>Abrir</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-1" onclick="abrirVistaPrevia('<?= htmlspecialchars($invitacion_url, ENT_QUOTES) ?>', '<?= htmlspecialchars($invitacion_ext, ENT_QUOTES) ?>', 'Invitación')"><i class="fas fa-eye me-1"></i>Vista previa</button>
�n
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="invitacion" name="invitacion" 
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-preview-target="invitacion-preview">
                            <small class="form-text text-muted">
                                Formatos: PDF, DOC, DOCX, JPG, PNG, GIF (m�x. 5MB)
                            </small>
                            <div id="invitacion-preview"></div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="normas" class="form-label">
                                <i class="fas fa-file-alt me-1"></i>Normas/Condiciones
                            </label>
                            <?php if ($action === 'edit' && !empty($tournament['normas'])): 
                                $normas_url = (isset($layout_asset_base) ? $layout_asset_base : AppHelpers::getPublicUrl()) . '/view_tournament_file.php?file=' . urlencode(str_replace('upload/tournaments/', '', $tournament['normas']));
                                $normas_ext = strtolower(pathinfo($tournament['normas'], PATHINFO_EXTENSION));
                            ?>
                                <div class="alert alert-info py-2 mb-2">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Archivo actual:
                                    <a href="<?= htmlspecialchars($normas_url) ?>" target="_blank" class="alert-link" rel="noopener"><i class="fas fa-external-link-alt me-1"></i>Abrir</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-1" onclick="abrirVistaPrevia('<?= htmlspecialchars($normas_url, ENT_QUOTES) ?>', '<?= htmlspecialchars($normas_ext, ENT_QUOTES) ?>', 'Normas/Condiciones')">
                                        <i class="fas fa-eye me-1"></i>Vista previa
                                    </button>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="normas" name="normas" 
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-preview-target="normas-preview">
                            <small class="form-text text-muted">
                                Formatos: PDF, DOC, DOCX, JPG, PNG, GIF (m�x. 5MB)
                            </small>
                            <div id="normas-preview"></div>
                        </div>
                    </div>
                </div>
                
                <?php if ($action === 'edit'): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Nota:</strong> Si selecciona un nuevo archivo, reemplazar• el archivo anterior. 
                            Deje el campo vacío si desea mantener el archivo actual.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <a href="<?= htmlspecialchars((isset($dashboard_href) && is_callable($dashboard_href)) ? $dashboard_href('tournaments') : 'index.php?page=tournaments') ?>" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-<?= $action === 'edit' ? 'warning' : 'success' ?>">
                        <i class="fas fa-save me-2"></i><?= $action === 'edit' ? 'Actualizar Torneo' : 'Crear Torneo' ?>
                    </button>
                </div>
            </form>
            </div>
        </div>
    </div>
    
    <!-- Modal vista previa archivo torneo (afiche, invitación, normas) -->
    <div class="modal fade" id="modalVistaPreviaArchivo" tabindex="-1" aria-labelledby="modalVistaPreviaArchivoLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalVistaPreviaArchivoLabel"><i class="fas fa-eye me-2"></i><span id="modalVistaPreviaTitulo">Vista previa</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-0 bg-light" style="min-height: 70vh;">
                    <div id="modalVistaPreviaContenido" class="h-100 w-100 d-flex align-items-center justify-content-center"></div>
                </div>
                <div class="modal-footer">
                    <a id="modalVistaPreviaDescarga" href="#" target="_blank" class="btn btn-outline-primary" rel="noopener"><i class="fas fa-download me-1"></i>Descargar</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Script para vista previa de archivos -->
    <script>
    function abrirVistaPrevia(url, ext, titulo) {
        document.getElementById('modalVistaPreviaTitulo').textContent = titulo;
        document.getElementById('modalVistaPreviaDescarga').href = url;
        var cont = document.getElementById('modalVistaPreviaContenido');
        cont.innerHTML = '';
        var puedePrevisualizar = ['pdf','jpg','jpeg','png','gif'].indexOf(ext) !== -1;
        if (puedePrevisualizar) {
            if (['jpg','jpeg','png','gif'].indexOf(ext) !== -1) {
                var img = document.createElement('img');
                img.src = url;
                img.alt = titulo;
                img.style.maxWidth = '100%'; img.style.maxHeight = '70vh'; img.style.objectFit = 'contain';
                cont.appendChild(img);
            } else {
                var iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.style.width = '100%'; iframe.style.height = '70vh'; iframe.style.border = 'none';
                cont.appendChild(iframe);
            }
        } else {
            cont.innerHTML = '<div class="p-4 text-center"><p class="text-muted">No se puede previsualizar este tipo de archivo.</p><a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" class="btn btn-primary" rel="noopener"><i class="fas fa-download me-1"></i>Descargar archivo</a></div>';
        }
        var modalEl = document.getElementById('modalVistaPreviaArchivo');
        var modal = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('hidden.bs.modal', function() {
            document.getElementById('modalVistaPreviaContenido').innerHTML = '';
        });
        modal.show();
    }
    document.addEventListener('DOMContentLoaded', function() {
        // Función para formatear tama�o de archivo
        var clubResp = document.getElementById('club_responsable');
        if (clubResp && window.location.search.indexOf('action=new') !== -1) {
            clubResp.addEventListener('change', function() {
                var orgId = this.value;
                var url = new URL(window.location.href);
                if (orgId) { url.searchParams.set('organizacion_id', orgId); } else { url.searchParams.delete('organizacion_id'); }
                window.location.href = url.toString();
            });
        }
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }
        
        // Función para obtener icono según tipo de archivo
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const icons = {
                'pdf': '<i class="fas fa-file-pdf text-danger fa-3x"></i>',
                'doc': '<i class="fas fa-file-word text-primary fa-3x"></i>',
                'docx': '<i class="fas fa-file-word text-primary fa-3x"></i>',
                'jpg': '<i class="fas fa-file-image text-success fa-3x"></i>',
                'jpeg': '<i class="fas fa-file-image text-success fa-3x"></i>',
                'png': '<i class="fas fa-file-image text-info fa-3x"></i>',
                'gif': '<i class="fas fa-file-image text-warning fa-3x"></i>'
            };
            return icons[ext] || '<i class="fas fa-file fa-3x text-secondary"></i>';
        }
        
        // Configurar preview para cada campo de archivo
        const fileInputs = [
            { id: 'afiche', previewId: 'preview-afiche' },
            { id: 'invitacion', previewId: 'preview-invitacion' },
            { id: 'normas', previewId: 'preview-normas' }
        ];
        
        fileInputs.forEach(input => {
            const fileInput = document.getElementById(input.id);
            if (!fileInput) return;
            
            // Crear contenedor de preview después del input
            const previewContainer = document.createElement('div');
            previewContainer.id = input.previewId;
            previewContainer.className = 'mt-2';
            previewContainer.style.display = 'none';
            fileInput.parentNode.insertBefore(previewContainer, fileInput.nextSibling);
            
            // Evento de cambio de archivo
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                const preview = document.getElementById(input.previewId);
                
                if (!file) {
                    preview.style.display = 'none';
                    preview.innerHTML = '';
                    return;
                }
                
                // Validar tama�o (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    preview.innerHTML = `
                        <div class="alert alert-danger py-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            El archivo es demasiado grande (${formatFileSize(file.size)}). M�ximo: 5MB
                        </div>
                    `;
                    preview.style.display = 'block';
                    fileInput.value = '';
                    return;
                }
                
                const fileType = file.type;
                const fileName = file.name;
                
                // Si es imagen, mostrar preview
                if (fileType.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = `
                            <div class="card">
                                <div class="card-body p-2">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <img src="${e.target.result}" 
                                                 alt="Preview" 
                                                 style="max-width: 100px; max-height: 100px; border-radius: 5px;">
                                        </div>
                                        <div class="col">
                                            <strong class="d-block">${fileName}</strong>
                                            <small class="text-muted">${formatFileSize(file.size)}</small>
                                        </div>
                                        <div class="col-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="document.getElementById('${input.id}').value=''; document.getElementById('${input.previewId}').style.display='none';">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    // Para PDF, DOC, etc - mostrar info con icono
                    preview.innerHTML = `
                        <div class="card">
                            <div class="card-body p-2">
                                <div class="row align-items-center">
                                    <div class="col-auto text-center" style="width: 80px;">
                                        ${getFileIcon(fileName)}
                                    </div>
                                    <div class="col">
                                        <strong class="d-block">${fileName}</strong>
                                        <small class="text-muted">${formatFileSize(file.size)}</small>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="document.getElementById('${input.id}').value=''; document.getElementById('${input.previewId}').style.display='none';">
                                            <i class="fas fa-times"></i> Quitar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    preview.style.display = 'block';
                }
            });
        });
    });
    
    // Toggle para pago en línea
    function togglePagoEnLinea() {
        const checkbox = document.getElementById('pago_en_linea_habilitado');
        const config = document.getElementById('pago_en_linea_config');
        config.style.display = checkbox.checked ? 'block' : 'none';
    }
    
    // Toggle para API bancaria
    function toggleApiBanco() {
        const checkbox = document.getElementById('api_banco_habilitada');
        const config = document.getElementById('api_banco_config');
        config.style.display = checkbox.checked ? 'block' : 'none';
    }
    
    // Toggle para teléfono pago móvil principal
    document.getElementById('tipo_cuenta_principal')?.addEventListener('change', function() {
        const container = document.getElementById('telefono_pagomovil_container');
        container.style.display = this.value === 'pagomovil' ? 'block' : 'none';
    });
    
    // Toggle para teléfono pago móvil secundario
    document.getElementById('tipo_cuenta_secundaria')?.addEventListener('change', function() {
        const container = document.getElementById('telefono_pagomovil_secundario_container');
        container.style.display = this.value === 'pagomovil' ? 'block' : 'none';
    });
    
    // Controlar campo ranking según tipo de evento
    const tipoEventoSelect = document.getElementById('es_evento_masivo');
    const rankingSelect = document.getElementById('ranking');
    const tipoEventoInfo = document.getElementById('tipo-evento-info');
    
    const ayudaPorTipo = {
        0: 'Torneo normal. Inscripción en línea si está habilitada. Genera ranking.',
        1: 'Evento Nacional: no genera ranking (tipo polla). Inscripción abierta para todos los afiliados.',
        2: 'Evento Regional: requiere historial de participación previa (sin 2+ no presentaciones consecutivas) para inscripción en línea.',
        3: 'Evento Local: requiere historial de participación previa (sin 2+ no presentaciones consecutivas) para inscripción en línea.',
        4: 'Evento Privado: visible en el landing pero NO permite inscripción en línea. Solo inscripción presencial.'
    };
    
    function actualizarRankingSegunTipoEvento() {
        if (!tipoEventoSelect || !rankingSelect) return;
        
        const tipoEvento = parseInt(tipoEventoSelect.value) || 0;
        
        if (tipoEvento === 1) {
            rankingSelect.value = '0';
            rankingSelect.disabled = true;
            rankingSelect.classList.add('bg-light');
            if (tipoEventoInfo) {
                tipoEventoInfo.textContent = ayudaPorTipo[1] || 'Evento Nacional no genera ranking (tipo polla).';
                tipoEventoInfo.className = 'form-text text-muted text-warning';
            }
        } else if (tipoEvento === 2 || tipoEvento === 3) {
            rankingSelect.disabled = false;
            rankingSelect.classList.remove('bg-light');
            if (tipoEventoInfo) {
                tipoEventoInfo.textContent = ayudaPorTipo[tipoEvento] || 'Evento Regional/Local: restricciones de inscripción según historial.';
                tipoEventoInfo.className = 'form-text text-muted text-success';
            }
        } else if (tipoEvento === 4) {
            rankingSelect.disabled = false;
            rankingSelect.classList.remove('bg-light');
            if (tipoEventoInfo) {
                tipoEventoInfo.textContent = ayudaPorTipo[4] || 'Evento Privado: solo inscripción en sitio.';
                tipoEventoInfo.className = 'form-text text-muted text-warning';
            }
        } else {
            rankingSelect.disabled = false;
            rankingSelect.classList.remove('bg-light');
            if (tipoEventoInfo) {
                tipoEventoInfo.textContent = ayudaPorTipo[0] || 'Torneo normal. Inscripción en línea si está habilitada.';
                tipoEventoInfo.className = 'form-text text-muted';
            }
        }
    }
    
    // Ejecutar al cargar la página
    actualizarRankingSegunTipoEvento();
    
    // Ejecutar cuando cambie el tipo de evento
    tipoEventoSelect?.addEventListener('change', actualizarRankingSegunTipoEvento);
    </script>
    <?php endif; ?>
<?php endif; ?>

