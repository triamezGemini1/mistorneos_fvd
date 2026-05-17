<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Pagination.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';

// Verificar permisos
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

// Obtener informaci�n del usuario actual
$current_user = Auth::user();
$user_role = $current_user['role'] ?? '';
$user_club_id = Auth::getUserClubId();
$is_admin_general = Auth::isAdminGeneral();
$is_admin_torneo = Auth::isAdminTorneo();
$is_admin_club = ($user_role === 'admin_club');

// Validar que admin_torneo tenga club asignado
if ($is_admin_torneo && !$user_club_id) {
    $error_message = "Error: Su usuario no tiene un club asignado. Contacte al administrador general.";
}

// Obtener datos para la vista
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;
$return_to = $_GET['return_to'] ?? 'index'; // 'panel_torneo' cuando se entra desde panel_torneo.php para retorno correcto

// Procesar eliminaci�n si se solicita
// POST: cambiar estatus de inscrito (Confirmar/Retirar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cambiar_estatus_inscrito') {
    $success_message = null;
    $torneo_id_redir = (int)($_POST['torneo_id'] ?? 0);
    $csrf_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if ($csrf_token && $session_token && hash_equals($session_token, $csrf_token)) {
        $inscripcion_id = (int)($_POST['inscripcion_id'] ?? 0);
        $torneo_id_redir = (int)($_POST['torneo_id'] ?? 0);
        $nuevo_estatus = (int)($_POST['estatus'] ?? 0);
        if ($nuevo_estatus === InscritosHelper::ESTATUS_RETIRADO_NUM_LEGACY) {
            $nuevo_estatus = InscritosHelper::ESTATUS_RETIRADO_NUM;
        }
        if ($inscripcion_id > 0 && $torneo_id_redir > 0 && InscritosHelper::isValidEstatus($nuevo_estatus) && Auth::canAccessTournament($torneo_id_redir)) {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE id = ? AND torneo_id = ?");
            $stmt->execute([$inscripcion_id, $torneo_id_redir]);
            if ($stmt->fetch()) {
                $pdo->prepare("UPDATE inscritos SET estatus = ? WHERE id = ? AND torneo_id = ?")->execute([$nuevo_estatus, $inscripcion_id, $torneo_id_redir]);
                $success_message = 'Estatus del inscrito actualizado.';
            }
        }
    }
    $return_to_redir = $_POST['return_to'] ?? $return_to;
    $query = 'page=registrants&torneo_id=' . $torneo_id_redir . ($success_message ? '&success=' . urlencode($success_message) : '');
    if ($return_to_redir !== 'index') {
        $query .= '&return_to=' . urlencode($return_to_redir);
    }
    header('Location: index.php?' . $query);
    exit;
}

if ($action === 'delete' && $id) {
    try {
        $registrant_id = (int)$id;
        
        // Obtener datos antes de eliminar, incluyendo informaci�n del torneo
        $stmt = DB::pdo()->prepare("
            SELECT u.nombre, r.torneo_id, t.club_responsable, t.estatus as torneo_estatus
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            LEFT JOIN tournaments t ON r.torneo_id = t.id
            WHERE r.id = ?
        ");
        $stmt->execute([$registrant_id]);
        $registrant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registrant) {
            throw new Exception('Inscrito no encontrado');
        }
        
        // Si es admin_torneo, verificar permisos
        if ($is_admin_torneo) {
            if (!Auth::canModifyTournament((int)$registrant['torneo_id'])) {
                throw new Exception('No tiene permisos para eliminar inscritos de este torneo. Solo puede eliminar inscritos de torneos futuros de su club.');
            }
        }
        
        // Admin club no puede eliminar inscritos
        if ($is_admin_club) {
            throw new Exception('No tiene permisos para eliminar inscritos');
        }
        
        // Eliminar
        $stmt = DB::pdo()->prepare("DELETE FROM inscritos WHERE id = ?");
        $result = $stmt->execute([$registrant_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            header('Location: index.php?page=registrants&success=' . urlencode('Inscrito "' . $registrant['nombre'] . '" eliminado exitosamente'));
        } else {
            throw new Exception('No se pudo eliminar el inscrito');
        }
    } catch (Exception $e) {
        header('Location: index.php?page=registrants&error=' . urlencode('Error al eliminar: ' . $e->getMessage()));
    }
    exit;
}

// Obtener datos para edici�n
$registrant = null;
$deuda_info = null;
if (($action === 'edit' || $action === 'view') && $id) {
    try {
        $stmt = DB::pdo()->prepare("
            SELECT r.*, u.nombre, u.sexo, t.nombre as torneo_nombre, t.costo as torneo_costo, 
                   t.club_responsable, t.estatus as torneo_estatus,
                   c.nombre as club_nombre
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            LEFT JOIN tournaments t ON r.torneo_id = t.id
            LEFT JOIN clubes c ON r.id_club = c.id
            WHERE r.id = ?
        ");
        $stmt->execute([(int)$id]);
        $registrant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registrant) {
            $error_message = "Inscrito no encontrado";
            $action = 'list';
        } else {
            // Si es admin_torneo, verificar permisos
            if ($is_admin_torneo) {
                if (!Auth::canAccessTournament((int)$registrant['torneo_id'])) {
                    header('Location: index.php?page=registrants&error=' . urlencode('No tiene permisos para acceder a este inscrito'));
                    exit;
                }
                
                // Si es acci�n de editar y el torneo ya pas�, redirigir a vista
                if ($action === 'edit' && Auth::isTournamentPast((int)$registrant['torneo_id'])) {
                    header('Location: index.php?page=registrants&action=view&id=' . $id . '&error=' . urlencode('No puede modificar inscritos de torneos pasados. Solo puede verlos.'));
                    exit;
                }
            }
            
            // Calcular deuda del club para este torneo
            if ($registrant['club_id'] && $registrant['torneo_id']) {
                $deuda_info = calcularDeudaClub($registrant['club_id'], $registrant['torneo_id']);
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al cargar el inscrito: " . $e->getMessage();
        $action = 'list';
    }
}

// Obtener listas para formularios
$tournaments_list = [];
$clubs_list = [];
$athletes_list = [];
// Torneo contextual (pasado por URL o por registro existente)
$torneo_id_context = ($action === 'edit' && $registrant) ? (int)($registrant['torneo_id'] ?? 0) : (int)($_GET['torneo_id'] ?? 0);
$torneo_info_form = null;

// En nuevo registro se requiere torneo contextual
if ($action === 'new' && $torneo_id_context <= 0) {
    $error_message = 'Debe seleccionar un torneo antes de inscribir.';
    $action = 'list';
}
if (in_array($action, ['new', 'edit'])) {
    try {
        // Filtrar torneos seg�n el rol del usuario
        $tournament_filter = Auth::getTournamentFilterForRole('t');
        $where_clause = "WHERE t.estatus = 1";
        
        if (!empty($tournament_filter['where'])) {
            $where_clause .= " AND " . $tournament_filter['where'];
        }
        
        $stmt = DB::pdo()->prepare("
            SELECT t.id, t.nombre, t.fechator, t.costo,
                   CASE WHEN t.fechator < CURDATE() THEN 1 ELSE 0 END as pasado 
            FROM tournaments t
            {$where_clause}
            ORDER BY t.fechator DESC
        ");
        $stmt->execute($tournament_filter['params']);
        $tournaments_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Obtener torneo contextual (si viene por URL o del registro)
        if ($torneo_id_context > 0) {
            foreach ($tournaments_list as $tinfo) {
                if ((int)$tinfo['id'] === $torneo_id_context) {
                    $torneo_info_form = $tinfo;
                    break;
                }
            }
            if (!$torneo_info_form) {
                // Intentar cargarlo directamente y validar rol
                $stmt = DB::pdo()->prepare("SELECT id, nombre, fechator, costo, club_responsable, estatus FROM tournaments WHERE id = ?");
                $stmt->execute([$torneo_id_context]);
                $tmp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($tmp && (empty($tournament_filter['where']) || Auth::canAccessTournament((int)$tmp['id']))) {
                    $torneo_info_form = $tmp;
                }
            }
        }

        // Determinar si el torneo ya inició (al menos una ronda)
        $torneo_iniciado = false;
        if ($torneo_id_context > 0) {
            try {
                $stmt = DB::pdo()->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) AS ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
                $stmt->execute([$torneo_id_context]);
                $ultima_ronda = (int)($stmt->fetchColumn() ?? 0);
                $torneo_iniciado = $ultima_ronda > 0;
            } catch (Exception $e) {
                $torneo_iniciado = false;
            }
        } else {
            // Si no hay torneo, se considera bloqueado para inscribir
            $torneo_iniciado = true;
        }
        
        // Obtener clubes según el rol del usuario
        if ($is_admin_general) {
            $stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
            $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // admin_club y admin_torneo: usar clubes supervisados
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $clubs_list = ClubHelper::getClubesSupervisedWithData($user_club_id);
        }
        
        // Obtener lista de usuarios (atletas) registrados
        $stmt = DB::pdo()->query("
            SELECT u.id, u.cedula, u.nombre, u.sexo, c.nombre as club_nombre 
            FROM usuarios u 
            LEFT JOIN clubes c ON u.club_id = c.id 
            WHERE u.role = 'usuario'
            ORDER BY u.nombre ASC
        ");
        $athletes_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Error al cargar datos: " . $e->getMessage();
    }
}

// Obtener lista para vista de lista con filtros
$registrants_list = [];
// Aceptar torneo_id desde la URL (para enlaces desde torneos) o filter_torneo (desde filtros)
$filter_torneo = $_GET['torneo_id'] ?? $_GET['filter_torneo'] ?? '';
$filtro_busqueda = trim((string) ($_GET['q'] ?? ''));
$filtro_estatus_insc = $_GET['estatus_filtro'] ?? 'todos';
if (!in_array($filtro_estatus_insc, ['todos', 'pendiente', 'confirmado', 'retirado'], true)) {
    $filtro_estatus_insc = 'todos';
}
$filter_clubs = $_GET['filter_clubs'] ?? [];
$total_registrants = 0;
$pagination = null;

// Validar que el admin_torneo solo filtre por torneos de su club
if ($is_admin_torneo && !empty($filter_torneo)) {
    if (!Auth::canAccessTournament((int)$filter_torneo)) {
        header('Location: index.php?page=registrants&error=' . urlencode('No tiene permisos para acceder a este torneo'));
        exit;
    }
}

// Estad�sticas del torneo seleccionado
$torneo_stats = null;
$resumen_por_club = [];

// Obtener lista de torneos para el filtro
$tournaments_filter = [];
try {
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_clause = "";
    
    if (!empty($tournament_filter['where'])) {
        $where_clause = "WHERE " . $tournament_filter['where'];
    }
    
    $stmt = DB::pdo()->prepare("
        SELECT t.id, t.nombre, t.fechator,
               CASE WHEN t.fechator < CURDATE() THEN 1 ELSE 0 END as pasado
        FROM tournaments t
        {$where_clause}
        ORDER BY t.fechator DESC
    ");
    $stmt->execute($tournament_filter['params']);
    $tournaments_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silencio
}

// Obtener lista de clubs para el filtro
$clubs_filter = [];
try {
    if ($is_admin_general) {
        $stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
        $clubs_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // admin_club y admin_torneo: usar clubes supervisados
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $clubs_filter = ClubHelper::getClubesSupervisedWithData($user_club_id);
    }
} catch (Exception $e) {
    // Silencio
}

// Calcular estad�sticas si hay torneo seleccionado
if (!empty($filter_torneo) && $action === 'list') {
    try {
        // Estad�sticas generales del torneo
        $stmt = DB::pdo()->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN (r.estatus = 1 OR r.estatus = 'confirmado') THEN 1 ELSE 0 END) as confirmados,
                SUM(CASE WHEN u.sexo = 1 OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 2 OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            WHERE r.torneo_id = ?
        ");
        $stmt->execute([(int)$filter_torneo]);
        $torneo_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($torneo_stats) {
            $torneo_stats['total'] = (int)($torneo_stats['total'] ?? 0);
            $torneo_stats['confirmados'] = (int)($torneo_stats['confirmados'] ?? 0);
            $torneo_stats['hombres'] = (int)($torneo_stats['hombres'] ?? 0);
            $torneo_stats['mujeres'] = (int)($torneo_stats['mujeres'] ?? 0);
            $contadores_inscripcion = InscritosHelper::contadoresResumenInscripcionTorneo(DB::pdo(), (int) $filter_torneo);
            $torneo_stats['equipos_activos'] = (int) ($contadores_inscripcion['equipos_activos'] ?? 0);
        }
        
        // Resumen por club
        $stmt = DB::pdo()->prepare("
            SELECT 
                COALESCE(c.id, r.id_club) as id,
                COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Club #', r.id_club)) as club_nombre,
                COUNT(r.id) as total_inscritos,
                SUM(CASE WHEN u.sexo = 1 OR UPPER(u.sexo) = 'M' THEN 1 ELSE 0 END) as hombres,
                SUM(CASE WHEN u.sexo = 2 OR UPPER(u.sexo) = 'F' THEN 1 ELSE 0 END) as mujeres
            FROM inscritos r
            LEFT JOIN clubes c ON c.id = r.id_club
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            WHERE r.torneo_id = ?
            GROUP BY COALESCE(c.id, r.id_club), COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Club #', r.id_club))
            ORDER BY total_inscritos DESC, club_nombre ASC
        ");
        $stmt->execute([(int)$filter_torneo]);
        $resumen_por_club = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convertir valores a enteros
        foreach ($resumen_por_club as &$club) {
            $club['total_inscritos'] = (int)($club['total_inscritos'] ?? 0);
            $club['hombres'] = (int)($club['hombres'] ?? 0);
            $club['mujeres'] = (int)($club['mujeres'] ?? 0);
        }
        unset($club); // Romper referencia
        
    } catch (Exception $e) {
        // Silencio
    }
}

if ($action === 'list') {
    try {
        // Construir query con filtros
        $where = [];
        $params = [];
        
        // SOLO ejecutar si hay torneo seleccionado
        if (empty($filter_torneo)) {
            $registrants_list = [];
            $total_registrants = 0;
            $pagination = null;
        } else {
            // Filtro base: torneo seleccionado
            $where[] = "r.torneo_id = ?";
            $params[] = (int)$filter_torneo;

            if ($filtro_estatus_insc === 'pendiente') {
                $where[] = "(r.estatus = 0 OR r.estatus = 'pendiente')";
            } elseif ($filtro_estatus_insc === 'confirmado') {
                $where[] = "(r.estatus IN (1, 2, 3) OR r.estatus IN ('confirmado', 'solvente'))";
            } elseif ($filtro_estatus_insc === 'retirado') {
                $where[] = InscritosHelper::sqlWhereRetiradoConAlias('r');
            }

            if ($filtro_busqueda !== '') {
                $like = '%' . $filtro_busqueda . '%';
                $cedulaDigits = '%' . preg_replace('/\D/', '', $filtro_busqueda) . '%';
                $searchParts = [
                    'u.nombre LIKE ?',
                    'u.cedula LIKE ?',
                    "REPLACE(REPLACE(REPLACE(TRIM(CAST(u.cedula AS CHAR)), '-', ''), '.', ''), ' ', '') LIKE ?",
                    'CAST(r.id_usuario AS CHAR) LIKE ?',
                    'CAST(r.id AS CHAR) LIKE ?',
                ];
                $searchParams = [$like, $like, $cedulaDigits, $like, $like];
                try {
                    if ((bool) DB::pdo()->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetchColumn()) {
                        $searchParts[] = 'CAST(u.numfvd AS CHAR) LIKE ?';
                        $searchParams[] = $like;
                    }
                } catch (Throwable $e) {
                }
                if (ctype_digit($filtro_busqueda)) {
                    $idNum = (int) $filtro_busqueda;
                    $searchParts[] = 'r.id_usuario = ?';
                    $searchParams[] = $idNum;
                    $searchParts[] = 'r.id = ?';
                    $searchParams[] = $idNum;
                }
                $where[] = '(' . implode(' OR ', $searchParts) . ')';
                $params = array_merge($params, $searchParams);
            }
            
            // En "Gestionar inscripciones" (con torneo seleccionado): mostrar TODOS los inscritos del torneo
            // por cualquier vía (en línea, en sitio, evento masivo, invitación). No filtrar por club.
            // El acceso al torneo ya se valida al seleccionarlo (dropdown o desde el panel).
            // NO aplicar filtro por club aquí: admin_club y admin_torneo deben ver todos los inscritos
            // del torneo para validar participación y confirmar inscritos en sitio.
            
            // Sin filtros adicionales por club: listar TODO lo inscrito en el torneo.
            
            $where_clause = 'WHERE ' . implode(' AND ', $where);
            
            // Contar total de registros con filtros
            $stmt = DB::pdo()->prepare("
            SELECT COUNT(*) 
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            LEFT JOIN tournaments t ON r.torneo_id = t.id
            LEFT JOIN clubes c ON r.id_club = c.id
            $where_clause
        ");
        $stmt->execute($params);
        $total_registrants = (int)$stmt->fetchColumn();
        
        // Sin paginación: en este reporte se debe listar TODO el torneo.
        $pagination = null;
        
        // Obtener registros de la p�gina actual
        $stmt = DB::pdo()->prepare("
            SELECT r.*, 
                   u.nombre, u.sexo, u.cedula, u.celular,
                   COALESCE(u.numfvd, 0) AS usuario_numfvd,
                   r.id_usuario,
                   t.nombre as torneo_nombre,
                   t.fechator as torneo_fecha,
                   t.costo as torneo_costo,
                   t.club_responsable,
                   t.estatus as torneo_estatus,
                   c.id as club_id,
                   c.nombre as club_nombre
            FROM inscritos r
            LEFT JOIN usuarios u ON r.id_usuario = u.id
            LEFT JOIN tournaments t ON r.torneo_id = t.id
            LEFT JOIN clubes c ON r.id_club = c.id
            $where_clause
            ORDER BY 
                CASE 
                    WHEN c.id = t.club_responsable THEN 1
                    ELSE 0
                END ASC,
                c.nombre ASC,
                u.nombre ASC
        ");
        $stmt->execute($params);
        $registrants_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = "Error al cargar los inscritos: " . $e->getMessage();
    }
}

// Funci�n para calcular deuda del club en un torneo
function calcularDeudaClub($club_id, $torneo_id) {
    try {
        // Obtener costo del torneo
        $stmt = DB::pdo()->prepare("SELECT costo FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        $costo_por_jugador = $torneo['costo'] ?? 0;
        
        // Contar inscritos del club en este torneo
        $stmt = DB::pdo()->prepare("SELECT COUNT(*) as total FROM inscritos WHERE id_club = ? AND torneo_id = ?");
        $stmt->execute([$club_id, $torneo_id]);
        $total_inscritos = $stmt->fetchColumn();
        
        // Calcular total a pagar
        $total_a_pagar = $total_inscritos * $costo_por_jugador;
        
        // Obtener pagos realizados
        $stmt = DB::pdo()->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_pagado 
            FROM payments 
            WHERE club_id = ? AND torneo_id = ? AND status = 'completed'
        ");
        $stmt->execute([$club_id, $torneo_id]);
        $total_pagado = $stmt->fetchColumn();
        
        // Calcular deuda
        $deuda = $total_a_pagar - $total_pagado;
        
        return [
            'total_inscritos' => $total_inscritos,
            'costo_por_jugador' => $costo_por_jugador,
            'total_a_pagar' => $total_a_pagar,
            'total_pagado' => $total_pagado,
            'deuda' => $deuda
        ];
    } catch (Exception $e) {
        return null;
    }
}

// Funci�n para obtener deuda por AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'deuda') {
    $club_id = (int)($_GET['club_id'] ?? 0);
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    
    if ($club_id && $torneo_id) {
        $deuda = calcularDeudaClub($club_id, $torneo_id);
        echo json_encode(['success' => true, 'data' => $deuda]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Par�metros inv�lidos']);
    }
    exit;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h1 class="h3 mb-0">
                    <i class="fas fa-users me-2"></i>Inscritos
                </h1>
                <?php if ($action === 'list'): ?>
                <div class="d-flex align-items-center flex-wrap gap-2 ms-auto">
                    <?php if (!empty($filter_torneo)): ?>
                    <?php
                    $url_panel = ($return_to === 'panel_torneo')
                        ? ('panel_torneo.php?action=panel&torneo_id=' . (int)$filter_torneo)
                        : ('index.php?page=torneo_gestion&action=panel&torneo_id=' . (int)$filter_torneo);
                    ?>
                    <a href="<?= htmlspecialchars($url_panel) ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Retornar al panel del torneo
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($filter_torneo) && class_exists('AppHelpers')): ?>
                    <a href="<?= htmlspecialchars(AppHelpers::torneoGestionUrl('reportes_inscritos', (int)$filter_torneo)) ?>"
                       class="btn btn-info">
                        <i class="fas fa-file-alt me-2"></i>Reportes
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
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
            
            <?php if ($is_admin_torneo && $user_club_id): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de Torneo:</strong> Solo puede ver y gestionar inscritos de torneos asignados a su club (ID: <?= $user_club_id ?>). 
                    Solo puede modificar inscritos de torneos activos.
                    <br><small class="text-muted">Variables: is_admin_torneo=<?= $is_admin_torneo ? 'true' : 'false' ?>, club_id=<?= $user_club_id ?></small>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
    <?php
    // Vista enfocada en un torneo: sin widgets globales ni resumen por club
    $modo_torneo_compacto = !empty($filter_torneo);

    $detailed_stats = null;
    if (!$modo_torneo_compacto) {
        require_once __DIR__ . '/../lib/StatisticsHelper.php';
        $detailed_stats = StatisticsHelper::generateStatistics();
    }

    // Estadísticas de organización (usuarios, clubes, roles) — no en gestión de inscripciones del torneo
    if (!$modo_torneo_compacto && !empty($detailed_stats) && !isset($detailed_stats['error'])):
    ?>
    <div class="row g-4 mb-4">
        <?php if ($user_role === 'admin_general'): ?>
            <div class="col-md-2">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary mb-0"><?= number_format($detailed_stats['total_users'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Total Usuarios</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success mb-0"><?= number_format($detailed_stats['total_active_users'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Usuarios Activos</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info mb-0"><?= number_format($detailed_stats['total_clubs'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Clubes Activos</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning mb-0"><?= number_format($detailed_stats['total_admin_clubs'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Admin. de organización</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h3 class="text-secondary mb-0"><?= number_format($detailed_stats['total_admin_torneo'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Admin. Torneo</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <h3 class="text-dark mb-0"><?= number_format($detailed_stats['total_operadores'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Operadores</p>
                    </div>
                </div>
            </div>
        <?php elseif ($user_role === 'admin_club' && !empty($detailed_stats['supervised_clubs'])): ?>
            <div class="col-md-2">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary mb-0"><?= number_format($detailed_stats['total_afiliados'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Total Afiliados</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success mb-0"><?= number_format($detailed_stats['afiliados_by_gender']['hombres'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Hombres</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <h3 class="text-danger mb-0"><?= number_format($detailed_stats['afiliados_by_gender']['mujeres'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Mujeres</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info mb-0"><?= number_format($detailed_stats['active_inscriptions']['total'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Inscripciones Activas</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h3 class="text-secondary mb-0"><?= number_format($detailed_stats['total_admin_torneo'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Admin. Torneo</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <h3 class="text-dark mb-0"><?= number_format($detailed_stats['total_operadores'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Operadores</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($modo_torneo_compacto): ?>
    <form id="filterForm" class="d-none" aria-hidden="true">
        <input type="hidden" name="filter_torneo" value="<?= (int)$filter_torneo ?>">
        <?php foreach ($filter_clubs as $cid): ?>
            <input type="hidden" name="filter_clubs[]" value="<?= (int)$cid ?>">
        <?php endforeach; ?>
    </form>
    <?php else: ?>
    <!-- Panel de Filtros y Exportaci�n -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-filter me-2"></i>Filtros y Reportes
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" action="index.php" id="filterForm">
                <input type="hidden" name="page" value="registrants">
                
                <div class="row g-3">
                    <!-- Filtro por Torneo -->
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-trophy me-1"></i>Torneo</label>
                        <select name="filter_torneo" class="form-select" id="filterTorneo" onchange="this.form.submit()" required>
                            <option value="">-- Seleccione un Torneo --</option>
                            <?php foreach ($tournaments_filter as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= ($filter_torneo == $t['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nombre']) ?> - <?= date('d/m/Y', strtotime($t['fechator'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($filter_torneo)): ?>
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Mostrando inscritos del torneo seleccionado
                            </small>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Filtro por Clubs - Solo mostrar si hay torneo seleccionado -->
                    <?php if (!empty($filter_torneo)): ?>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-users me-1"></i>Clubs</label>
                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllClubs" onclick="toggleAllClubs(this); this.form.submit();">
                                <label class="form-check-label fw-bold" for="selectAllClubs">
                                    Todos los Clubs
                                </label>
                            </div>
                            <hr class="my-2">
                            <?php foreach ($clubs_filter as $club): ?>
                                <div class="form-check">
                                    <input class="form-check-input club-checkbox" type="checkbox" name="filter_clubs[]" 
                                           value="<?= $club['id'] ?>" id="club_<?= $club['id'] ?>"
                                           onchange="this.form.submit()"
                                           <?= in_array($club['id'], $filter_clubs) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="club_<?= $club['id'] ?>">
                                        <?= htmlspecialchars($club['nombre']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Seleccione uno o varios clubs</small>
                    </div>
                    <?php else: ?>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-users me-1"></i>Clubs</label>
                        <div class="alert alert-secondary mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            Primero seleccione un torneo para filtrar por clubs
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="index.php?page=registrants" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Limpiar Filtros
                            </a>
                            
                            <div class="vr"></div>
                            
                            <?php if (!empty($filter_torneo)): ?>
                            <a href="index.php?page=registrants_report<?= !empty($filter_torneo) ? '&filter_torneo=' . (int)$filter_torneo : '' ?><?= !empty($filter_clubs) ? '&' . http_build_query(['filter_clubs' => $filter_clubs]) : '' ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-file-alt me-2"></i>Ir a Reportes
                            </a>
                            <?php
                            // Sustituir jugador retirado: solo cuando torneo iniciado y no es modalidad equipos
                            $modalidad_sust = 0;
                            if (!empty($filter_torneo)) {
                                $st = DB::pdo()->prepare("SELECT modalidad FROM tournaments WHERE id = ?");
                                $st->execute([(int)$filter_torneo]);
                                $modalidad_sust = (int)($st->fetchColumn() ?? 0);
                            }
                            if (!empty($torneo_iniciado) && $torneo_iniciado && $modalidad_sust !== 3): ?>
                            <a href="index.php?page=torneo_gestion&action=sustituir_jugador&torneo_id=<?= (int)$filter_torneo ?>" 
                               class="btn btn-warning">
                                <i class="fas fa-user-exchange me-2"></i>Sustituir jugador retirado
                            </a>
                            <?php endif; ?>
                            <?php else: ?>
                            <button type="button" class="btn btn-primary" disabled title="Primero debe seleccionar un torneo">
                                <i class="fas fa-file-alt me-2"></i>Ir a Reportes
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if (!empty($filter_torneo)): ?>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="alert alert-success border border-success mb-0 shadow-sm">
                            <div class="fw-bold mb-2 text-success">
                                <i class="fas fa-file-export me-2"></i>Ver y generar reportes de este torneo
                            </div>
                            <p class="small text-muted mb-2 mb-md-3">Resumen en pantalla, PDF/Excel simple o <strong>reporte detallado</strong> (logo del organizador, por asociación y equipo).</p>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <a href="index.php?page=registrants_report&amp;filter_torneo=<?= (int)$filter_torneo ?><?= !empty($filter_clubs) ? '&amp;' . htmlspecialchars(http_build_query(['filter_clubs' => $filter_clubs])) : '' ?>"
                                   class="btn btn-primary btn-sm">
                                    <i class="fas fa-desktop me-1"></i>Pantalla de reportes
                                </a>
                                <?php if (class_exists('AppHelpers')): ?>
                                <span class="vr d-none d-md-block"></span>
                                <span class="small text-muted me-1 d-none d-md-inline">Detallado:</span>
                                <a href="<?= htmlspecialchars(AppHelpers::torneoGestionUrl('inscripciones_reporte_detallado_pdf', (int)$filter_torneo)) ?>"
                                   class="btn btn-danger btn-sm" target="_blank" rel="noopener"
                                   title="PDF con encabezado organizador, asociación y equipos">
                                    <i class="fas fa-file-pdf me-1"></i>PDF detallado
                                </a>
                                <a href="<?= htmlspecialchars(AppHelpers::torneoGestionUrl('inscripciones_reporte_detallado_xls', (int)$filter_torneo)) ?>"
                                   class="btn btn-success btn-sm" target="_blank" rel="noopener">
                                    <i class="fas fa-file-excel me-1"></i>Excel detallado
                                </a>
                                <span class="vr d-none d-md-block"></span>
                                <span class="small text-muted me-1 d-none d-md-inline">Listado simple:</span>
                                <a href="<?= htmlspecialchars(AppHelpers::torneoGestionUrl('inscripciones_export_pdf', (int)$filter_torneo)) ?>"
                                   class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">PDF</a>
                                <a href="<?= htmlspecialchars(AppHelpers::torneoGestionUrl('inscripciones_export_xls', (int)$filter_torneo)) ?>"
                                   class="btn btn-outline-success btn-sm" target="_blank" rel="noopener">Excel</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </form>
            
            <?php if (!empty($filter_torneo) && $total_registrants > 0): ?>
                <div class="alert alert-info mt-3 mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Resultados:</strong> <?= $total_registrants ?> inscrito(s) encontrado(s)
                    <?php if (!empty($filter_clubs)): ?>
                        <br><small><?= count($filter_clubs) ?> club(s) seleccionado(s)</small>
                    <?php endif; ?>
                </div>
            <?php elseif (empty($filter_torneo)): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Seleccione un torneo</strong> en el desplegable de arriba para ver inscritos, estadísticas y <strong>reportes / exportaciones</strong>.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; ?>

    <!-- Estad�sticas y Resumen por Club -->
    <?php if (!empty($filter_torneo) && $torneo_stats && !$modo_torneo_compacto): ?>
        <?php
        $contadores_inscripcion = [
            'inscritos_total' => (int) ($torneo_stats['total'] ?? 0),
            'jugadores_confirmados' => (int) ($torneo_stats['confirmados'] ?? 0),
            'equipos_activos' => (int) ($torneo_stats['equipos_activos'] ?? 0),
        ];
        require __DIR__ . '/../resources/views/partials/torneo_inscripcion_badges_bs5.php';
        ?>
        <!-- Estad�sticas Generales del Torneo -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-users me-2"></i>Total Inscritos</h5>
                        <h2 class="mb-0"><?= number_format($torneo_stats['total']) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-check-circle me-2"></i>Confirmados</h5>
                        <h2 class="mb-0"><?= number_format($torneo_stats['confirmados'] ?? 0) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-male me-2"></i>Hombres</h5>
                        <h2 class="mb-0"><?= number_format($torneo_stats['hombres']) ?></h2>
                        <small><?= $torneo_stats['total'] > 0 ? round(($torneo_stats['hombres'] / $torneo_stats['total']) * 100, 1) : 0 ?>%</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-female me-2"></i>Mujeres</h5>
                        <h2 class="mb-0"><?= number_format($torneo_stats['mujeres']) ?></h2>
                        <small><?= $torneo_stats['total'] > 0 ? round(($torneo_stats['mujeres'] / $torneo_stats['total']) * 100, 1) : 0 ?>%</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resumen por Club -->
        <?php if (!empty($resumen_por_club)): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-building me-2"></i>Resumen de Inscritos por Club
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Club</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Hombres</th>
                                    <th class="text-center">Mujeres</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resumen_por_club as $club): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($club['club_nombre']) ?></strong></td>
                                        <td class="text-center"><span class="badge bg-primary"><?= number_format($club['total_inscritos']) ?></span></td>
                                        <td class="text-center"><span class="badge bg-info"><?= number_format($club['hombres']) ?></span></td>
                                        <td class="text-center"><span class="badge bg-danger"><?= number_format($club['mujeres']) ?></span></td>
                                        <td class="text-center">
                                            <a href="index.php?page=registrants&filter_torneo=<?= $filter_torneo ?>&filter_clubs[]=<?= $club['id'] ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Ver detalle">
                                                <i class="fas fa-eye me-1"></i>Detalle
                                            </a>
                                            <button onclick="exportarClubPDF(<?= $club['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Exportar PDF">
                                                <i class="fas fa-file-pdf me-1"></i>PDF
                                            </button>
                                            <button onclick="exportarClubExcel(<?= $club['id'] ?>)" class="btn btn-sm btn-outline-success" title="Exportar Excel">
                                                <i class="fas fa-file-excel me-1"></i>Excel
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-secondary">
                                <tr>
                                    <th>TOTAL</th>
                                    <th class="text-center"><?= number_format(array_sum(array_column($resumen_por_club, 'total_inscritos'))) ?></th>
                                    <th class="text-center"><?= number_format(array_sum(array_column($resumen_por_club, 'hombres'))) ?></th>
                                    <th class="text-center"><?= number_format(array_sum(array_column($resumen_por_club, 'mujeres'))) ?></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Vista de Lista -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($filter_torneo)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Por favor seleccione un torneo</strong> para ver los inscritos y estad�sticas.
                </div>
            <?php elseif (empty($registrants_list)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php if (!empty($filter_clubs)): ?>
                        No hay inscritos que coincidan con los filtros seleccionados.
                    <?php else: ?>
                        No hay inscritos registrados en este torneo.
                    <?php endif; ?>
                </div>
            <?php else: 
                // Obtener información del torneo y club para título/subtítulo
                $torneo_info = null;
                $club_info = null;
                $clubes_en_lista = [];
                
                $torneo_cerrado_reg = false;
                if (!empty($filter_torneo)) {
                    $stmt = DB::pdo()->prepare("SELECT nombre, fechator FROM tournaments WHERE id = ?");
                    $stmt->execute([(int)$filter_torneo]);
                    $torneo_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($torneo_info) {
                        try {
                            $st = DB::pdo()->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'locked'");
                            if ($st && (int)$st->fetchColumn() > 0) {
                                $st2 = DB::pdo()->prepare("SELECT COALESCE(locked, 0) AS locked FROM tournaments WHERE id = ?");
                                $st2->execute([(int)$filter_torneo]);
                                $torneo_cerrado_reg = ((int)($st2->fetchColumn() ?? 0)) === 1;
                            }
                        } catch (Exception $e) {}
                    }
                }
                
                // Obtener lista de clubes únicos en los inscritos mostrados
                if (!empty($registrants_list)) {
                    // Obtener IDs de clubes únicos y válidos
                    $clubes_ids_raw = array_column($registrants_list, 'club_id');
                    $clubes_ids = [];
                    foreach ($clubes_ids_raw as $id) {
                        if ($id !== null && $id !== '' && $id !== 0) {
                            $id_int = (int)$id;
                            if ($id_int > 0 && !in_array($id_int, $clubes_ids)) {
                                $clubes_ids[] = $id_int;
                            }
                        }
                    }
                    
                    if (!empty($clubes_ids) && is_array($clubes_ids)) {
                        // Asegurar que todos los valores sean enteros y válidos
                        $clubes_ids = array_values(array_filter(array_map('intval', $clubes_ids), function($id) {
                            return $id > 0;
                        }));
                        
                        if (empty($clubes_ids)) {
                            $clubes_en_lista = [];
                        } elseif (count($clubes_ids) === 1) {
                            try {
                                $stmt = DB::pdo()->prepare("SELECT id, nombre FROM clubes WHERE id = ? ORDER BY nombre ASC");
                                $stmt->execute([$clubes_ids[0]]);
                                $clubes_en_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (PDOException $e) {
                                error_log("Error al obtener club único: " . $e->getMessage());
                                $clubes_en_lista = [];
                            }
                        } else {
                            try {
                                $placeholders = implode(',', array_fill(0, count($clubes_ids), '?'));
                                $stmt = DB::pdo()->prepare("SELECT id, nombre FROM clubes WHERE id IN ($placeholders) ORDER BY nombre ASC");
                                $stmt->execute($clubes_ids);
                                $clubes_en_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (PDOException $e) {
                                error_log("Error al obtener múltiples clubes: " . $e->getMessage() . " - IDs: " . implode(',', $clubes_ids));
                                $clubes_en_lista = [];
                            }
                        }
                    } else {
                        $clubes_en_lista = [];
                    }
                }
                
                // Si hay un solo club filtrado, mostrarlo en el título
                if (!empty($filter_clubs) && count($filter_clubs) === 1) {
                    $stmt = DB::pdo()->prepare("SELECT nombre FROM clubes WHERE id = ?");
                    $stmt->execute([(int)$filter_clubs[0]]);
                    $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                // Si no hay club filtrado pero hay solo un club en la lista, mostrarlo
                if (!$club_info && count($clubes_en_lista) === 1) {
                    $club_info = $clubes_en_lista[0];
                }
            ?>
                <?php if ($torneo_info): ?>
                <div class="card border-primary shadow-sm mb-3">
                    <div class="card-header bg-dark text-white py-2">
                        <h3 class="mb-0 h5"><i class="fas fa-trophy me-2 text-warning"></i><?= htmlspecialchars($torneo_info['nombre']) ?>
                            <?php if ($club_info): ?><span class="text-white-50">— <?= htmlspecialchars($club_info['nombre']) ?></span>
                            <?php elseif (count($clubes_en_lista) > 1): ?><span class="text-white-50">— <?= count($clubes_en_lista) ?> Clubes</span><?php endif; ?>
                        </h3>
                    </div>
                    <div class="card-body py-3">
                        <style>
                            .registrants-filtro-toolbar .form-label-spacer {
                                visibility: hidden;
                                height: 1.5rem;
                                margin-bottom: 0.25rem;
                            }
                            .registrants-filtro-estatus {
                                flex-wrap: nowrap;
                                overflow-x: auto;
                                padding-bottom: 2px;
                            }
                            .registrants-filtro-estatus .btn,
                            .registrants-filtro-limpiar {
                                min-width: auto;
                                white-space: nowrap;
                                border-width: 2px;
                                border-radius: .5rem;
                                font-weight: 500;
                                height: calc(1.5em + 0.75rem + 2px);
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                padding: 0.375rem 0.85rem;
                            }
                        </style>
                        <form method="get" class="row g-3 align-items-end registrants-filtro-toolbar" id="registrantsFilterForm">
                            <input type="hidden" name="page" value="registrants">
                            <input type="hidden" name="filter_torneo" value="<?= (int)$filter_torneo ?>">
                            <?php $hay_filtro_insc_activo = $filtro_busqueda !== '' || $filtro_estatus_insc !== 'todos'; ?>
                            <div class="<?= $hay_filtro_insc_activo ? 'col-lg-4' : 'col-lg-5' ?>"><label class="form-label mb-1"><i class="fas fa-search me-1"></i>Buscar</label>
                                <input type="search" name="q" class="form-control js-filtro-busqueda-insc" placeholder="Cédula, nombre, ID usuario o NUMFVD (Enter)" value="<?= htmlspecialchars($filtro_busqueda) ?>"></div>
                            <?php
                            $ef_btn_todos = $filtro_estatus_insc === 'todos' ? 'btn-secondary' : 'btn-outline-secondary';
                            $ef_btn_pend = $filtro_estatus_insc === 'pendiente' ? 'btn-warning text-dark' : 'btn-outline-warning';
                            $ef_btn_conf = $filtro_estatus_insc === 'confirmado' ? 'btn-success' : 'btn-outline-success';
                            $ef_btn_ret = $filtro_estatus_insc === 'retirado' ? 'btn-dark' : 'btn-outline-dark';
                            ?>
                            <div class="<?= $hay_filtro_insc_activo ? 'col-lg-6' : 'col-lg-7' ?>">
                                <span class="form-label form-label-spacer mb-1 d-block" aria-hidden="true">Estado</span>
                                <div class="d-flex flex-nowrap gap-2 registrants-filtro-estatus">
                                    <input type="radio" class="btn-check js-filtro-estatus-insc" name="estatus_filtro" id="ef_todos" value="todos" <?= $filtro_estatus_insc === 'todos' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn <?= $ef_btn_todos ?>" for="ef_todos"><i class="fas fa-users me-1"></i>Todos</label>
                                    <input type="radio" class="btn-check js-filtro-estatus-insc" name="estatus_filtro" id="ef_pend" value="pendiente" <?= $filtro_estatus_insc === 'pendiente' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn <?= $ef_btn_pend ?>" for="ef_pend"><i class="fas fa-clock me-1"></i>Pendientes</label>
                                    <input type="radio" class="btn-check js-filtro-estatus-insc" name="estatus_filtro" id="ef_conf" value="confirmado" <?= $filtro_estatus_insc === 'confirmado' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn <?= $ef_btn_conf ?>" for="ef_conf"><i class="fas fa-check-circle me-1"></i>Confirmados</label>
                                    <input type="radio" class="btn-check js-filtro-estatus-insc" name="estatus_filtro" id="ef_ret" value="retirado" <?= $filtro_estatus_insc === 'retirado' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn <?= $ef_btn_ret ?>" for="ef_ret"><i class="fas fa-user-slash me-1"></i>Retirados</label>
                                </div>
                            </div>
                            <?php if ($hay_filtro_insc_activo): ?>
                            <div class="col-lg-2">
                                <span class="form-label form-label-spacer mb-1 d-block" aria-hidden="true">Limpiar</span>
                                <a href="index.php?page=registrants&amp;filter_torneo=<?= (int)$filter_torneo ?>" class="btn btn-outline-secondary w-100 registrants-filtro-limpiar" title="Limpiar filtros"><i class="fas fa-times me-1"></i>Limpiar</a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>NUMFVD</th>
                                <th>Nombre</th>
                                <th>Sexo</th>
                                <th>Celular</th>
                                <th class="text-center">Pago</th>
                                <th class="text-center">Retirado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Agrupar inscritos por club para ruptura de control
                            $inscritos_por_club = [];
                            foreach ($registrants_list as $item) {
                                $club_id = $item['club_id'] ?? 0;
                                $club_nombre = $item['club_nombre'] ?? 'Sin Club';
                                if (!isset($inscritos_por_club[$club_id])) {
                                    $inscritos_por_club[$club_id] = [
                                        'nombre' => $club_nombre,
                                        'inscritos' => []
                                    ];
                                }
                                $inscritos_por_club[$club_id]['inscritos'][] = $item;
                            }
                            
                            // Ordenar por nombre de club
                            uasort($inscritos_por_club, function($a, $b) {
                                return strcmp($a['nombre'], $b['nombre']);
                            });
                            
                            // Mostrar inscritos agrupados por club
                            $club_index = 0;
                            foreach ($inscritos_por_club as $club_id => $datos_club): 
                                // Mostrar encabezado del club (ruptura de control)
                                if ($club_index > 0):
                            ?>
                                <tr class="table-group-divider">
                                    <td colspan="8"></td>
                                </tr>
                            <?php 
                                endif;
                                $club_index++;
                            ?>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="8" class="bg-light">
                                        <i class="fas fa-building me-2"></i>
                                        <?= htmlspecialchars($datos_club['nombre']) ?>
                                        <span class="badge bg-primary ms-2"><?= count($datos_club['inscritos']) ?> inscrito(s)</span>
                                    </td>
                                </tr>
                            <?php foreach ($datos_club['inscritos'] as $item): ?>
                                <tr data-inscripcion-row="<?= (int)$item['id'] ?>">
                                    <td><code><?= (int)($item['id_usuario'] ?? 0) ?></code></td>
                                    <td><code><?= (int)($item['usuario_numfvd'] ?? 0) ?></code></td>
                                    <td><strong><?= htmlspecialchars($item['nombre']) ?></strong></td>
                                    <td>
                                        <?php
                                        $sexo_text = $item['sexo'] ?? '';
                                        if ($sexo_text === 'M' || $sexo_text == 1) {
                                            echo '<span class="badge bg-info">M</span>';
                                        } elseif ($sexo_text === 'F' || $sexo_text == 2) {
                                            echo '<span class="badge bg-success">F</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">O</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($item['celular'] ?? 'N/A') ?></td>
                                    <td class="text-center">
                                        <?php
                                        $est = $item['estatus'] ?? 0;
                                        $es_confirmado = InscritosHelper::esConfirmado($est);
                                        $es_retirado = InscritosHelper::esRetirado($est);
                                        $uid_est = (int) $item['id'];
                                        if (empty($torneo_cerrado_reg) && !$es_retirado): ?>
                                        <div class="form-check form-switch d-inline-flex justify-content-center mb-0">
                                            <input type="checkbox" class="form-check-input js-switch-pago-inscrito" role="switch"
                                                   data-inscripcion-id="<?= $uid_est ?>" data-torneo-id="<?= (int)$filter_torneo ?>"
                                                   <?= $es_confirmado ? 'checked' : '' ?>>
                                            <label class="form-check-label small ms-1 js-switch-pago-label"><?= $es_confirmado ? 'Confirmado' : 'Pendiente' ?></label>
                                        </div>
                                        <?php elseif ($es_retirado): ?>
                                            <span class="text-muted small">—</span>
                                        <?php elseif ($es_confirmado): ?>
                                            <span class="badge bg-success">Confirmado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (empty($torneo_cerrado_reg)): ?>
                                        <div class="form-check form-switch d-inline-flex justify-content-center mb-0">
                                            <input type="checkbox" class="form-check-input js-switch-retirado-inscrito" role="switch"
                                                   data-inscripcion-id="<?= $uid_est ?>" data-torneo-id="<?= (int)$filter_torneo ?>"
                                                   <?= $es_retirado ? 'checked' : '' ?>>
                                            <label class="form-check-label small ms-1 js-switch-retirado-label"><?= $es_retirado ? 'Retirado' : 'Activo' ?></label>
                                        </div>
                                        <?php else: ?>
                                            <span class="badge <?= $es_retirado ? 'bg-dark' : 'bg-secondary' ?>"><?= $es_retirado ? 'Retirado' : 'Activo' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm flex-wrap justify-content-center">
                                        <?php if (!$es_retirado && empty($torneo_cerrado_reg)): ?>
                                            <button type="button" class="btn btn-outline-primary js-enviar-mensaje-inscrito" title="Enviar mensaje (notificación web y Telegram)"
                                                    data-inscripcion-id="<?= (int)$item['id'] ?>" data-torneo-id="<?= (int)$filter_torneo ?>">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                            <?php if ($es_confirmado): ?>
                                            <button type="button" class="btn btn-outline-success js-recibo-inscrito" title="Recibo / imprimir"
                                                    data-inscripcion-id="<?= (int)$item['id'] ?>" data-torneo-id="<?= (int)$filter_torneo ?>">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                            <?php endif; ?>
                                        <?php elseif ($es_confirmado && empty($torneo_cerrado_reg)): ?>
                                            <button type="button" class="btn btn-outline-success js-recibo-inscrito" title="Recibo / imprimir"
                                                    data-inscripcion-id="<?= (int)$item['id'] ?>" data-torneo-id="<?= (int)$filter_torneo ?>">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($filter_torneo) && $action === 'list'): ?>
<div class="modal fade" id="modalReciboInscrito" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Recibo para el jugador</h5>
                <button type="button" class="btn-close btn-close-white d-none" data-bs-dismiss="modal" aria-hidden="true"></button>
            </div>
            <div class="modal-body" id="modalReciboInscritoBody"></div>
            <div class="modal-footer flex-wrap">
                <p class="text-muted small mb-0 me-auto w-100 w-md-auto">Imprima el recibo antes de cerrar esta ventana.</p>
                <button type="button" class="btn btn-success" id="btnReciboInscritoImprimir"><i class="fas fa-print me-1"></i>Imprimir</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="btnReciboInscritoCerrar">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<?php
$insc_api = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') . '/api/inscripcion_admin.php' : '/public/api/inscripcion_admin.php';
$insc_js = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') . '/assets/registrants-inscripciones.js' : '/public/assets/registrants-inscripciones.js';
?>
<script>
window.REGISTRANTS_INSC_CFG = {
    apiUrl: <?= json_encode($insc_api, JSON_UNESCAPED_UNICODE) ?>,
    csrf: <?= json_encode(class_exists('CSRF') ? CSRF::token() : '', JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars($insc_js) ?>"></script>
<?php endif; ?>

<?php elseif ($action === 'new' || $action === 'edit'): ?>
    <?php if ($action === 'new' && $torneo_id_context <= 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>Debe acceder desde un torneo para inscribir.
        </div>
    <?php elseif ($torneo_id_context > 0 && !$torneo_info_form): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>No se pudo cargar la información del torneo seleccionado.
        </div>
    <?php else: ?>
    <!-- Formulario -->
    <div class="card">
        <div class="card-header bg-<?= $action === 'edit' ? 'warning' : 'success' ?> text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-<?= $action === 'edit' ? 'edit' : 'user-plus' ?> me-2"></i>
                <?= $action === 'edit' ? 'Editar' : 'Nuevo' ?> Inscrito
            </h5>
        </div>
        <div class="card-body">
            <!-- Estad�sticas del Torneo -->
            <div id="tournamentStats" class="alert alert-info border d-none mb-4">
                <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Estad�sticas del Torneo</h5>
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-white rounded">
                            <div class="text-primary mb-2"><i class="fas fa-users fs-1"></i></div>
                            <h4 class="mb-1" id="stats_total">0</h4>
                            <small class="text-muted">Total Inscritos</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-white rounded">
                            <div class="text-info mb-2"><i class="fas fa-mars fs-1"></i></div>
                            <h4 class="mb-1" id="stats_hombres">0</h4>
                            <small class="text-muted">Hombres</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-white rounded">
                            <div class="text-success mb-2"><i class="fas fa-venus fs-1"></i></div>
                            <h4 class="mb-1" id="stats_mujeres">0</h4>
                            <small class="text-muted">Mujeres</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-white rounded">
                            <div class="text-warning mb-2"><i class="fas fa-hashtag fs-1"></i></div>
                            <h4 class="mb-1" id="stats_siguiente">-</h4>
                            <small class="text-muted">Siguiente ID</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($torneo_iniciado) && $torneo_iniciado): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                El torneo ya inició (hay rondas generadas). No se permiten nuevas inscripciones. Solo se muestra información de control.
            </div>
            <?php else: ?>
            <!-- VERSION: 15:15 - API CORREGIDO EN /public/api/ -->
            <form method="POST" action="inscribir_sitio_save.php" id="formRegistrant" onsubmit="return prepareSubmit()">
                <?= CSRF::input(); ?>
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)$registrant['id'] ?>">
                <?php endif; ?>
                
                <!-- Informaci�n de Deuda del Club -->
                <div id="deudaInfo" class="alert alert-light border d-none mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0"><i class="fas fa-calculator me-2 text-primary"></i>Estado de Cuenta del Club</h6>
                        </div>
                        <button type="button" class="btn-close" onclick="document.getElementById('deudaInfo').classList.add('d-none')"></button>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-3">
                            <small class="text-muted">Inscritos:</small>
                            <p class="mb-0 fw-bold"><span id="deuda_inscritos">0</span> jugadores</p>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Costo por jugador:</small>
                            <p class="mb-0 fw-bold">$<span id="deuda_costo">0</span></p>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Total a pagar:</small>
                            <p class="mb-0 fw-bold text-primary">$<span id="deuda_total">0</span></p>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Deuda pendiente:</small>
                            <p class="mb-0 fw-bold text-danger">$<span id="deuda_pendiente">0</span></p>
                        </div>
                    </div>
                </div>
                
                <?php if ($torneo_id_context > 0 && $torneo_info_form): ?>
                <input type="hidden" id="torneo_id" name="torneo_id" value="<?= (int)$torneo_id_context ?>">
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title mb-1"><i class="fas fa-trophy me-2"></i><?= htmlspecialchars($torneo_info_form['nombre']) ?></h5>
                        <div class="text-muted">
                            <span class="me-3"><i class="fas fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($torneo_info_form['fechator'])) ?></span>
                            <span class="me-3"><i class="fas fa-dollar-sign me-1"></i>Costo: <?= number_format($torneo_info_form['costo'], 2) ?></span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">Debe acceder desde un torneo seleccionado para inscribir.</div>
                <?php endif; ?>
                
                <!-- Campos del formulario ocultos hasta seleccionar torneo -->
                <div id="formFields" class="">
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="athlete_id" class="form-label">Usuario (ID) *</label>
                            <select class="form-select form-select-lg" id="athlete_id" name="id_usuario" required onchange="onAthleteChange()">
                                <option value="">Seleccionar usuario...</option>
                                <?php foreach ($athletes_list as $athlete): ?>
                                    <option value="<?= (int)$athlete['id'] ?>" 
                                            data-cedula="<?= htmlspecialchars($athlete['cedula']) ?>"
                                            data-nombre="<?= htmlspecialchars($athlete['nombre']) ?>"
                                            data-sexo="<?= htmlspecialchars($athlete['sexo']) ?>"
                                            data-club-id="<?= (int)($athlete['club_id'] ?? 0) ?>"
                                            data-club-nombre="<?= htmlspecialchars($athlete['club_nombre'] ?? '') ?>"
                                            data-celular="<?= htmlspecialchars($athlete['celular'] ?? '') ?>"
                                            <?= ($action === 'edit' && $registrant['athlete_id'] == $athlete['id']) ? 'selected' : '' ?>>
                                        ID <?= (int)$athlete['id'] ?> - <?= htmlspecialchars($athlete['nombre']) ?> (<?= htmlspecialchars($athlete['club_nombre'] ?? 'Sin club') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Seleccione un usuario registrado en el sistema. Puede buscar por cédula, pero solo se muestra el ID.</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="cedula_display" class="form-label">ID Usuario</label>
                            <input type="text" class="form-control" id="cedula_display" readonly 
                                   placeholder="Se carga automáticamente">
                        </div>
                        
                        <div class="mb-3">
                            <label for="nombre_display" class="form-label">Nombre Completo</label>
                            <input type="text" class="form-control" id="nombre_display" readonly 
                                   placeholder="Se carga autom�ticamente">
                        </div>
                        
                        <div class="mb-3">
                            <label for="sexo_display" class="form-label">Sexo</label>
                            <input type="text" class="form-control" id="sexo_display" readonly 
                                   placeholder="Se carga autom�ticamente">
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="club_display" class="form-label">Club</label>
                            <input type="text" class="form-control" id="club_display" readonly 
                                   placeholder="Se carga autom�ticamente">
                        </div>
                        
                        <div class="mb-3">
                            <label for="celular_display" class="form-label">Celular</label>
                            <input type="text" class="form-control" id="celular_display" readonly 
                                   placeholder="Se carga autom�ticamente">
                        </div>
                        
                        <div class="mb-3">
                            <label for="categoria_display" class="form-label">Categor�a</label>
                            <input type="text" class="form-control" id="categoria_display" readonly 
                                   placeholder="Se calcula autom�ticamente">
                            <input type="hidden" name="categ" id="categ" value="0">
                            <small class="text-muted">Junior (<19), Libre (19-60), Master (>60)</small>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="estatus" class="form-label">Estado *</label>
                            <select class="form-select" id="estatus" name="estatus" required>
                                <option value="1"<?= ($action === 'edit' && ($registrant['estatus'] ?? 1) == 1) ? ' selected' : ' selected' ?>>Activo</option>
                                <option value="0"<?= ($action === 'edit' && ($registrant['estatus'] ?? 1) == 0) ? ' selected' : '' ?>>Inactivo</option>
                            </select>
                        </div>
                    </div>
                </div>
                </div><!-- Fin formFields -->
                
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <a href="index.php?page=registrants" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-<?= $action === 'edit' ? 'warning' : 'success' ?>">
                        <i class="fas fa-save me-2"></i><?= $action === 'edit' ? 'Actualizar Inscrito' : 'Crear Inscrito' ?>
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
// Preparar formulario antes de enviar (remover nacionalidad del submit)
function prepareSubmit() {
    // No se requiere validaci�n adicional
    return true;
}

// Funci�n al cambiar torneo
async function onTorneoChange() {
    // Con torneo fijo, solo actualizar stats y deuda
    const torneoInput = document.getElementById('torneo_id');
    if (!torneoInput) return;
    const torneo_id = torneoInput.value;
    if (!torneo_id) return;
    await cargarEstadisticasTorneo(torneo_id);
    actualizarDeuda();
}

// Cargar estad�sticas del torneo
async function cargarEstadisticasTorneo(torneo_id) {
    const statsDiv = document.getElementById('tournamentStats');
    
    try {
        const response = await fetch(`../api/tournament_stats.php?torneo_id=${torneo_id}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('stats_total').textContent = data.stats.total || 0;
            document.getElementById('stats_hombres').textContent = data.stats.hombres || 0;
            document.getElementById('stats_mujeres').textContent = data.stats.mujeres || 0;
            document.getElementById('stats_siguiente').textContent = data.stats.siguiente_id || 1;
            
            statsDiv.classList.remove('d-none');
        }
    } catch (error) {
        console.error('Error al cargar estad�sticas:', error);
    }
}

// Funci�n al cambiar atleta seleccionado
function onAthleteChange() {
    const athleteSelect = document.getElementById('athlete_id');
    const selectedOption = athleteSelect.options[athleteSelect.selectedIndex];
    
    if (!selectedOption || !selectedOption.value) {
        // Limpiar campos si no hay selecci�n
        document.getElementById('cedula_display').value = '';
        document.getElementById('nombre_display').value = '';
        document.getElementById('sexo_display').value = '';
        document.getElementById('club_display').value = '';
        document.getElementById('celular_display').value = '';
        document.getElementById('categoria_display').value = '';
        document.getElementById('categ').value = '0';
        return;
    }
    
    // Llenar campos con datos del atleta
    document.getElementById('cedula_display').value = selectedOption.value || '';
    document.getElementById('nombre_display').value = selectedOption.getAttribute('data-nombre') || '';
    document.getElementById('sexo_display').value = selectedOption.getAttribute('data-sexo') === 'M' ? 'Masculino' : 
                                                   selectedOption.getAttribute('data-sexo') === 'F' ? 'Femenino' : 'Otro';
    document.getElementById('club_display').value = selectedOption.getAttribute('data-club-nombre') || '';
    document.getElementById('celular_display').value = selectedOption.getAttribute('data-celular') || '';
    
    // Calcular categor�a (necesitar�amos fecha de nacimiento, pero no la tenemos en atletas)
    // Por ahora dejar vac�o, se puede calcular despu�s si es necesario
    document.getElementById('categoria_display').value = 'Por calcular';
    document.getElementById('categ').value = '0';
    
    // Actualizar deuda con el club del atleta
    actualizarDeuda();
}

// Calcular categor�a por edad
function calcularCategoria() {
    const fechnac = document.getElementById('fechnac').value;
    if (!fechnac) return;
    
    const hoy = new Date();
    const nacimiento = new Date(fechnac);
    let edad = hoy.getFullYear() - nacimiento.getFullYear();
    const mes = hoy.getMonth() - nacimiento.getMonth();
    
    if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) {
        edad--;
    }
    
    let categoria, categoriaTexto;
    if (edad < 19) {
        categoria = 1;
        categoriaTexto = 'Junior (< 19 a�os)';
    } else if (edad > 60) {
        categoria = 3;
        categoriaTexto = 'Master (> 60 a�os)';
    } else {
        categoria = 2;
        categoriaTexto = 'Libre (19-60 a�os)';
    }
    
    document.getElementById('categ').value = categoria;
    document.getElementById('categ_display').value = categoriaTexto;
}

// Actualizar informaci�n de deuda
async function actualizarDeuda() {
    const athleteSelect = document.getElementById('athlete_id');
    const selectedOption = athleteSelect.options[athleteSelect.selectedIndex];
    const club_id = selectedOption ? selectedOption.getAttribute('data-club-id') : null;
    const torneo_id = document.getElementById('torneo_id').value;
    const deudaDiv = document.getElementById('deudaInfo');
    
    if (!club_id || !torneo_id) {
        deudaDiv.classList.add('d-none');
        return;
    }
    
    try {
        const response = await fetch(`index.php?page=registrants&ajax=deuda&club_id=${club_id}&torneo_id=${torneo_id}`);
        const result = await response.json();
        
        if (result.success && result.data) {
            const d = result.data;
            document.getElementById('deuda_inscritos').textContent = d.total_inscritos;
            document.getElementById('deuda_costo').textContent = Number(d.costo_por_jugador).toFixed(2);
            document.getElementById('deuda_total').textContent = Number(d.total_a_pagar).toFixed(2);
            document.getElementById('deuda_pendiente').textContent = Number(d.deuda).toFixed(2);
            
            deudaDiv.classList.remove('d-none');
        }
    } catch (error) {
        console.error('Error al calcular deuda:', error);
    }
}

// Funci�n para seleccionar/deseleccionar todos los clubs
function toggleAllClubs(checkbox) {
    const clubCheckboxes = document.querySelectorAll('.club-checkbox');
    clubCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
}

// Agregar un indicador visual cuando se est� aplicando el filtro
document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function() {
            // Mostrar indicador de carga
            const submitBtn = document.createElement('div');
            submitBtn.className = 'position-fixed top-50 start-50 translate-middle';
            submitBtn.style.zIndex = '9999';
            submitBtn.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div>';
            document.body.appendChild(submitBtn);
        });
    }
});

// Funci�n para exportar a Excel
function exportarExcel() {
    const form = document.getElementById('filterForm');
    if (!form) {
        alert('Error: formulario no encontrado');
        return;
    }
    
    const formData = new FormData(form);
    
    // Construir URL con par�metros
    const params = new URLSearchParams();
    params.append('torneo_id', formData.get('filter_torneo') || '');
    
    const clubs = formData.getAll('filter_clubs[]');
    clubs.forEach(club => params.append('club_ids[]', club));
    
    // Agregar numeraci�n si est� marcada
    if (document.getElementById('numerarRegistros').checked) {
        params.append('numerar', '1');
    }
    
    // Abrir en nueva ventana
    window.location.href = '../modules/registrants/export_excel.php?' + params.toString();
}

// Funci�n para exportar a PDF
function exportarPDF() {
    const form = document.getElementById('filterForm');
    if (!form) {
        alert('Error: formulario no encontrado');
        return;
    }
    
    const formData = new FormData(form);
    
    // Construir URL con par�metros
    const params = new URLSearchParams();
    params.append('torneo_id', formData.get('filter_torneo') || '');
    
    const clubs = formData.getAll('filter_clubs[]');
    clubs.forEach(club => params.append('club_ids[]', club));
    
    // Agregar numeraci�n si est� marcada
    if (document.getElementById('numerarRegistros').checked) {
        params.append('numerar', '1');
    }
    
    // Abrir en nueva ventana
    window.location.href = '../modules/registrants/export_pdf.php?' + params.toString();
}

// Funci�n para exportar un club espec�fico a PDF
function generarCredenciales() {
    const form = document.getElementById('filterForm');
    if (!form) {
        alert('Error: formulario no encontrado');
        return;
    }
    
    const formData = new FormData(form);
    const torneoId = formData.get('filter_torneo');
    
    if (!torneoId) {
        alert('Error: Debe seleccionar un torneo');
        return;
    }
    
    // Confirmar acci�n
    if (!confirm('�Desea generar credenciales para todos los jugadores del torneo seleccionado? Esto puede tardar unos momentos.')) {
        return;
    }
    
    // Construir URL con par�metros
    const params = new URLSearchParams();
    params.append('action', 'bulk');
    params.append('tournament_id', torneoId);
    
    // Si hay clubs espec�ficos seleccionados
    const clubs = formData.getAll('filter_clubs[]');
    if (clubs.length > 0) {
        params.append('club_id', clubs[0]); // Por ahora solo el primer club
    }
    
    // Mostrar indicador de carga
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generando...';
    
    // Abrir en nueva ventana para descargar el ZIP
    window.location.href = 'modules/registrants/generate_credential.php?' + params.toString();
    
    // Restaurar bot�n despu�s de 3 segundos
    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }, 3000);
}

// Funci�n para exportar un club espec�fico a PDF
function exportarClubPDF(clubId) {
    const torneoId = document.querySelector('[name="filter_torneo"]').value;
    
    if (!torneoId) {
        alert('Error: No hay torneo seleccionado');
        return;
    }
    
    const params = new URLSearchParams();
    params.append('torneo_id', torneoId);
    params.append('club_ids[]', clubId);
    
    window.location.href = '../modules/registrants/export_pdf.php?' + params.toString();
}

// Funci�n para exportar un club espec�fico a Excel
function exportarClubExcel(clubId) {
    const torneoId = document.querySelector('[name="filter_torneo"]').value;
    
    if (!torneoId) {
        alert('Error: No hay torneo seleccionado');
        return;
    }
    
    const params = new URLSearchParams();
    params.append('torneo_id', torneoId);
    params.append('club_ids[]', clubId);
    params.append('export', 'excel');
    
    window.location.href = '../modules/registrants/export_excel.php?' + params.toString();
}

// Funci�n para numerar en BD
function numerarEnBD() {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    
    // Verificar si hay registros filtrados
    if (!confirm('�Desea asignar n�meros consecutivos en la columna IDENTIFICADOR?\n\n' +
                 'Esto actualizar� la base de datos con numeraci�n: 1, 2, 3...\n' +
                 'Los clubs normales primero, el club responsable al final.\n\n' +
                 'Esta acci�n se aplicar� a los registros actualmente filtrados.')) {
        return;
    }
    
    const btn = document.getElementById('btnNumerarBD');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Numerando...';
    
    // Construir par�metros
    const params = new URLSearchParams();
    params.append('torneo_id', formData.get('filter_torneo') || '');
    
    const clubs = formData.getAll('filter_clubs[]');
    clubs.forEach(club => params.append('club_ids[]', club));
    
    fetch('../modules/registrants/numerar_identificador.php?' + params.toString(), {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('? ' + data.message + '\n\nRegistros numerados: ' + data.registros_actualizados);
            location.reload();
        } else {
            alert('? Error: ' + (data.message || 'Error desconocido'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('? Error de conexi�n al numerar registros');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

// Funci�n para actualizar deuda de clubes
function actualizarDeudaClubes() {
    if (!confirm('�Desea actualizar la deuda de todos los clubes basado en los inscritos actuales?\n\nEsto recalcular� la deuda de cada club por torneo.')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Actualizando...';
    
    fetch('../modules/registrants/actualizar_deuda.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ accion: 'actualizar' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('? ' + data.message + '\n\nClubs actualizados: ' + data.clubs_actualizados);
            location.reload();
        } else {
            alert('? Error: ' + (data.message || 'Error desconocido'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('? Error de conexi�n al actualizar deuda');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

// Funci�n para numerar jugadores por club
function numerarPorClub() {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const torneoId = formData.get('filter_torneo');
    
    if (!torneoId) {
        alert('? Por favor, seleccione un torneo primero');
        return;
    }
    
    const clubs = formData.getAll('filter_clubs[]');
    
    let mensaje = '�Desea numerar los jugadores por club?\n\n';
    mensaje += '?? Numeraci�n POR CLUB:\n';
    mensaje += '� Cada club tendr� su propia numeraci�n: 1, 2, 3...\n';
    mensaje += '� Los jugadores se ordenar�n alfab�ticamente dentro de cada club\n\n';
    
    if (clubs.length > 0) {
        mensaje += '?? Se numerar�n ' + clubs.length + ' club(s) seleccionado(s)\n';
    } else {
        mensaje += '?? Se numerar�n TODOS los clubs del torneo\n';
    }
    
    mensaje += '\n?? Esta acci�n actualizar� los n�meros de identificaci�n en la base de datos.';
    
    if (!confirm(mensaje)) {
        return;
    }
    
    const btn = document.getElementById('btnNumerarPorClub');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Numerando...';
    
    // Construir URL
    let url = '../modules/registrants/numerar_por_club.php?torneo_id=' + torneoId;
    
    if (clubs.length === 1) {
        // Si solo hay un club seleccionado, numerar ese club espec�fico
        url += '&club_id=' + clubs[0];
    } else if (clubs.length === 0) {
        // Si no hay clubs seleccionados, numerar todos
        url += '&numerar_todos=1';
    } else {
        // Si hay m�ltiples clubs, numerar todos (el backend lo procesar�)
        url += '&numerar_todos=1';
    }
    
    fetch(url, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let detalleMsg = '? ' + data.message + '\n\n';
            detalleMsg += '?? Total de jugadores numerados: ' + data.total_jugadores_actualizados + '\n';
            detalleMsg += '?? Clubs procesados: ' + data.clubes_procesados + '\n\n';
            
            if (data.detalle_clubes && data.detalle_clubes.length > 0) {
                detalleMsg += 'Detalle por club:\n';
                data.detalle_clubes.forEach(club => {
                    detalleMsg += '� ' + club.club_nombre + ': ' + club.jugadores_numerados + ' jugador(es)\n';
                });
            }
            
            alert(detalleMsg);
            location.reload();
        } else {
            alert('? Error: ' + (data.message || 'Error desconocido'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('? Error de conexi�n al numerar por club');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

// Inicializar event listeners cuando el DOM est� listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('?? DOM cargado - Inicializando...');
    
    // B�squeda solo con bot�n TEST (blur desactivado para evitar errores)
    // const cedulaInput = document.getElementById('cedula');
    // if (cedulaInput) {
    //     cedulaInput.addEventListener('blur', buscarPersona);
    // }
    
    // Cargar estadísticas del torneo fijo si existe
    const torneoInput = document.getElementById('torneo_id');
    if (torneoInput && torneoInput.value) {
        cargarEstadisticasTorneo(torneoInput.value);
    }
    
    <?php if ($deuda_info): ?>
    // Mostrar información de deuda (si se calculó)
    document.getElementById('deuda_inscritos').textContent = <?= $deuda_info['total_inscritos'] ?>;
    document.getElementById('deuda_costo').textContent = '<?= number_format((float)$deuda_info['costo_por_jugador'], 2) ?>';
    document.getElementById('deuda_total').textContent = '<?= number_format((float)$deuda_info['total_a_pagar'], 2) ?>';
    document.getElementById('deuda_pendiente').textContent = '<?= number_format((float)$deuda_info['deuda'], 2) ?>';
    document.getElementById('deudaInfo').classList.remove('d-none');
    <?php endif; ?>
});
</script>

