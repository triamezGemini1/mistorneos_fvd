<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Pagination.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/InvitationJoinResolver.php';

// Verificar permisos: admin_general, admin_torneo y admin_club (organización responsable del torneo)
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

// Obtener información del usuario actual
$current_user = Auth::user();
$user_role = $current_user['role'] ?? '';
$user_club_id = Auth::getUserClubId();
$is_admin_general = Auth::isAdminGeneral();
$is_admin_torneo = Auth::isAdminTorneo();
$is_admin_club = Auth::isAdminClub();

// Validar que admin_torneo tenga club asignado
if ($is_admin_torneo && !$user_club_id) {
    $error_message = "Error: Su usuario no tiene un club asignado. Contacte al administrador general.";
}

// Obtener datos para la vista
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? $error_message ?? null;

// Procesar eliminación si se solicita
if ($action === 'delete' && $id) {
    try {
        $invitation_id = (int)$id;
        
        // Obtener datos de la invitación antes de eliminarla
        $stmt = DB::pdo()->prepare("
            SELECT i.*, t.nombre as torneo_nombre, t.club_responsable, c.nombre as club_nombre
            FROM " . TABLE_INVITATIONS . " i
            INNER JOIN tournaments t ON i.torneo_id = t.id
            INNER JOIN clubes c ON i.club_id = c.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invitation_id]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invitation) {
            throw new Exception('Invitación no encontrada');
        }
        
        // Verificar permisos y que el torneo no haya pasado
        if (!Auth::canModifyTournament((int)$invitation['torneo_id'])) {
            throw new Exception('No tiene permisos para eliminar invitaciones de este torneo. Solo puede eliminar invitaciones de torneos futuros de su club.');
        }
        
        // Eliminar la invitación
        $stmt = DB::pdo()->prepare("DELETE FROM " . TABLE_INVITATIONS . " WHERE id = ?");
        $result = $stmt->execute([$invitation_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            $filter_param = isset($_GET['filter_torneo']) ? '&filter_torneo=' . (int)$_GET['filter_torneo'] : '';
            $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
            header('Location: ' . $script_path . '?page=invitations' . $filter_param . '&success=' . urlencode('Invitación eliminada exitosamente'));
        } else {
            throw new Exception('No se pudo eliminar la invitación');
        }
    } catch (Exception $e) {
        $filter_param = isset($_GET['filter_torneo']) ? '&filter_torneo=' . (int)$_GET['filter_torneo'] : '';
        $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $script_path . '?page=invitations' . $filter_param . '&error=' . urlencode('Error al eliminar: ' . $e->getMessage()));
    }
    exit;
}

// Crear nueva invitación (POST): procesar en el mismo entry point para mantener sesión
$tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::validate();
    } catch (Throwable $e) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&error=' . urlencode('Sesión inválida o token expirado.'));
        exit;
    }
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $club_id = (int)($_POST['club_id'] ?? 0);
    $acceso1 = trim((string)($_POST['acceso1'] ?? ''));
    $acceso2 = trim((string)($_POST['acceso2'] ?? ''));
    $err = null;
    if (!$torneo_id) {
        $err = 'Debe seleccionar un torneo';
    } elseif (!$club_id) {
        $err = 'Debe seleccionar un club';
    } elseif ($acceso1 === '' || $acceso2 === '') {
        $err = 'Las fechas de acceso son requeridas';
    } elseif ($acceso1 > $acceso2) {
        $err = 'La fecha de inicio no puede ser mayor que la fecha fin';
    }
    if (!$err) {
        $stmt = DB::pdo()->prepare("SELECT id FROM {$tb_inv} WHERE torneo_id = ? AND club_id = ?");
        $stmt->execute([$torneo_id, $club_id]);
        if ($stmt->fetch()) {
            $err = 'Ya existe una invitación para este torneo y club';
        }
    }
    if (!$err && !Auth::canAccessTournament($torneo_id)) {
        $err = 'No tiene permiso para crear invitaciones en este torneo';
    }
    if ($err) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&action=new&torneo_id=' . $torneo_id . '&error=' . urlencode($err));
        exit;
    }
    $stmt = DB::pdo()->prepare("SELECT id, nombre, delegado, telefono, email FROM clubes WHERE id = ? AND estatus = 1");
    $stmt->execute([$club_id]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    $inv_delegado = $club['delegado'] ?? null;
    $inv_email = $club['email'] ?? null;
    $club_tel = $club['telefono'] ?? null;
    $token = bin2hex(random_bytes(32));
    if (strlen($token) !== 64) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&action=new&torneo_id=' . $torneo_id . '&error=' . urlencode('Error al generar token.'));
        exit;
    }
    $usuario_creador = (Auth::user() && isset(Auth::user()['id'])) ? (string)Auth::user()['id'] : '';
    $admin_club_id = Auth::id();
    $cols_inv = DB::pdo()->query("SHOW COLUMNS FROM {$tb_inv}")->fetchAll(PDO::FETCH_COLUMN);
    $campos = [
        'torneo_id' => $torneo_id,
        'club_id' => $club_id,
        'invitado_delegado' => $inv_delegado,
        'invitado_email' => $inv_email,
        'acceso1' => $acceso1,
        'acceso2' => $acceso2,
        'usuario' => $usuario_creador,
        'club_email' => $inv_email,
        'club_telefono' => $club_tel,
        'club_delegado' => $inv_delegado,
        'token' => $token,
        'estado' => 'activa',
    ];
    foreach ($cols_inv as $col_name) {
        if (strtolower((string)$col_name) === 'admin_club_id') {
            $campos[$col_name] = $admin_club_id;
            break;
        }
    }
    $cols = array_values(array_intersect($cols_inv, array_keys($campos)));
    $vals = array_map(function ($c) use ($campos) { return $campos[$c]; }, $cols);
    if (!empty($cols)) {
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = DB::pdo()->prepare("INSERT INTO {$tb_inv} (" . implode(', ', $cols) . ") VALUES ({$placeholders})");
        $stmt->execute($vals);
    } else {
        $stmt = DB::pdo()->prepare("INSERT INTO {$tb_inv} (torneo_id, club_id, acceso1, acceso2, usuario, token, estado) VALUES (?, ?, ?, ?, ?, ?, 'activa')");
        $stmt->execute([$torneo_id, $club_id, $acceso1, $acceso2, $usuario_creador, $token]);
    }
    $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    header('Location: ' . $sp . '?page=invitations&filter_torneo=' . $torneo_id . '&success=1&msg=' . urlencode('Invitación creada.'));
    exit;
}

// Guardar edición de invitación (POST): mismo entry point para mantener sesión
if ($action === 'edit_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::validate();
    } catch (Throwable $e) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&error=' . urlencode('Sesión inválida o token expirado.'));
        exit;
    }
    $edit_id = (int)($_POST['id'] ?? 0);
    if ($edit_id <= 0) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&error=' . urlencode('ID de invitación inválido.'));
        exit;
    }
    $acceso1 = trim((string)($_POST['acceso1'] ?? ''));
    $acceso2 = trim((string)($_POST['acceso2'] ?? ''));
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $estado = $_POST['estado'] ?? 'activa';
    $invitado_delegado = trim((string)($_POST['invitado_delegado'] ?? ''));
    $invitado_email = trim((string)($_POST['invitado_email'] ?? ''));
    $filter_torneo = (int)($_POST['filter_torneo'] ?? $_GET['filter_torneo'] ?? 0);
    if ($acceso1 === '' || $acceso2 === '' || $acceso1 > $acceso2) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&action=edit&id=' . $edit_id . '&error=' . urlencode('Fechas de acceso inválidas.'));
        exit;
    }
    $stmt = DB::pdo()->prepare("SELECT id, torneo_id FROM {$tb_inv} WHERE id = ?");
    $stmt->execute([$edit_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv || !Auth::canModifyTournament((int)$inv['torneo_id'])) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        header('Location: ' . $sp . '?page=invitations&error=' . urlencode('No tiene permiso para editar esta invitación.'));
        exit;
    }
    $cols = DB::pdo()->query("SHOW COLUMNS FROM {$tb_inv}")->fetchAll(PDO::FETCH_COLUMN);
    $set = "acceso1 = ?, acceso2 = ?, usuario = ?, estado = ?";
    $params = [$acceso1, $acceso2, $usuario, $estado];
    if (in_array('invitado_delegado', $cols, true)) {
        $set .= ", invitado_delegado = ?";
        $params[] = $invitado_delegado === '' ? null : $invitado_delegado;
    }
    if (in_array('invitado_email', $cols, true)) {
        $set .= ", invitado_email = ?";
        $params[] = $invitado_email === '' ? null : $invitado_email;
    }
    $params[] = $edit_id;
    $stmt = DB::pdo()->prepare("UPDATE {$tb_inv} SET {$set} WHERE id = ?");
    $stmt->execute($params);
    $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $redirect = $sp . "?page=invitations&msg=" . urlencode("Invitación actualizada.");
    if (!empty($_POST['return_to']) && $_POST['return_to'] === 'invitacion_clubes' && !empty($_POST['torneo_id'])) {
        $redirect = $sp . "?page=invitacion_clubes&torneo_id=" . (int)$_POST['torneo_id'] . "&success=1&msg=" . urlencode("Invitación actualizada.");
    } elseif ($filter_torneo > 0) {
        $redirect .= "&filter_torneo=" . $filter_torneo;
    }
    header('Location: ' . $redirect);
    exit;
}

// Obtener datos para edición
$invitation = null;
if ($action === 'edit' && $id) {
    try {
        $stmt = DB::pdo()->prepare("
            SELECT i.*, t.nombre as torneo_nombre, t.club_responsable, c.nombre as club_nombre
            FROM " . TABLE_INVITATIONS . " i
            INNER JOIN tournaments t ON i.torneo_id = t.id
            INNER JOIN clubes c ON i.club_id = c.id
            WHERE i.id = ?
        ");
        $stmt->execute([(int)$id]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invitation) {
            $error_message = "Invitación no encontrada";
            $action = 'list';
        } else {
            // Verificar permisos para editar
            if (!Auth::canModifyTournament((int)$invitation['torneo_id'])) {
                $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
                $target = $script_path . '?page=invitations&error=' . urlencode('No tiene permisos para editar invitaciones de este torneo. Solo puede editar invitaciones de torneos futuros de su club.');
                if (!headers_sent()) {
                    header('Location: ' . $target);
                    exit;
                }
                echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"><p>Redirigiendo...</p>';
                exit;
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al cargar la invitación: " . $e->getMessage();
        $action = 'list';
    }
}
if ($action === 'edit' && !$invitation) {
    $action = 'list';
    if (empty($error_message)) {
        $error_message = "Debe indicar la invitacin a editar (parmetro id).";
    }
}

// Obtener listas para formularios
$tournaments_list = [];
$clubs_list = [];
if (in_array($action, ['new', 'edit'])) {
    try {
        // Filtrar torneos según el rol del usuario (alias t para el filtro)
        $tournament_filter = Auth::getTournamentFilterForRole('t');
        $where_clause = "WHERE t.estatus = 1";
        
        if (!empty($tournament_filter['where'])) {
            $where_clause .= " AND " . $tournament_filter['where'];
        }
        
        $stmt = DB::pdo()->prepare("
            SELECT t.id, t.nombre, t.fechator,
                   CASE WHEN t.fechator < CURDATE() THEN 1 ELSE 0 END as pasado
            FROM tournaments t
            {$where_clause}
            ORDER BY t.fechator DESC
        ");
        $stmt->execute($tournament_filter['params']);
        $tournaments_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
        $torneo_id_clubs = (int)($_GET['torneo_id'] ?? $_GET['filter_torneo'] ?? 0);
        if ($torneo_id_clubs > 0) {
            $stmt = DB::pdo()->prepare("
                SELECT c.id, c.nombre
                FROM clubes c
                WHERE c.estatus = 1
                  AND c.id NOT IN (SELECT club_id FROM {$tb_inv} WHERE torneo_id = ?)
                ORDER BY c.nombre ASC
            ");
            $stmt->execute([$torneo_id_clubs]);
            $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
            $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = "Error al cargar datos: " . $e->getMessage();
    }
}

// Obtener lista para vista de lista con paginación. Torneo obligatorio desde panel (sin selector).
$invitations_list = [];
$pagination = null;
$filter_torneo = $_GET['filter_torneo'] ?? $_GET['torneo_id'] ?? '';
$stats = ['total' => 0, 'activas' => 0, 'expiradas' => 0, 'canceladas' => 0];

// Acceso solo por panel de control: index.php redirige ANTES del layout. Fallback por si se llega aquí.
if ($action === 'list' && empty($filter_torneo)) {
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $target = $script_path . '?page=torneo_gestion&action=index&error=' . urlencode('Acceda a Invitaciones desde el Panel de Control de un torneo.');
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    }
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"><p>Redirigiendo...</p>';
    exit;
}
if ($action === 'new') {
    $torneo_id_new = (int)($_GET['torneo_id'] ?? $_GET['filter_torneo'] ?? 0);
    if ($torneo_id_new <= 0 || !Auth::canAccessTournament($torneo_id_new)) {
        $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        $target = $script_path . '?page=torneo_gestion&action=index&error=' . urlencode('Indique el torneo desde el Panel de Control.');
        if (!headers_sent()) {
            header('Location: ' . $target);
            exit;
        }
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"><p>Redirigiendo...</p>';
        exit;
    }
}

// Validar que el admin tenga acceso al torneo
if (!empty($filter_torneo)) {
    if (!Auth::canAccessTournament((int)$filter_torneo)) {
        $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        $target = $script_path . '?page=invitations&error=' . urlencode('No tiene permisos para acceder a este torneo');
        if (!headers_sent()) {
            header('Location: ' . $target);
            exit;
        }
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"><p>Redirigiendo...</p>';
        exit;
    }
}

// Reporte de pagos por invitación (torneo + club)
$reportes_pago_list = [];
$reporte_pagos_club_nombre = '';
$reporte_pagos_torneo_nombre = '';
$reporte_pagos_subtotal = 0;
if ($action === 'reporte_pagos') {
    $torneo_id_rp = (int)($_GET['torneo_id'] ?? 0);
    $club_id_rp = (int)($_GET['club_id'] ?? 0);
    $filter_torneo = $filter_torneo ?: (string)$torneo_id_rp;
    if ($torneo_id_rp <= 0 || $club_id_rp <= 0 || !Auth::canAccessTournament($torneo_id_rp)) {
        $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        $target = $script_path . '?page=invitations&filter_torneo=' . (int)$filter_torneo . '&error=' . urlencode('Parámetros inválidos para el reporte de pagos.');
        if (!headers_sent()) {
            header('Location: ' . $target);
            exit;
        }
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"><p>Redirigiendo...</p>';
        exit;
    }
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT nombre FROM clubes WHERE id = ? LIMIT 1");
        $stmt->execute([$club_id_rp]);
        $reporte_pagos_club_nombre = $stmt->fetchColumn() ?: 'Club #' . $club_id_rp;
        $stmt = $pdo->prepare("SELECT nombre FROM tournaments WHERE id = ? LIMIT 1");
        $stmt->execute([$torneo_id_rp]);
        $reporte_pagos_torneo_nombre = $stmt->fetchColumn() ?: 'Torneo #' . $torneo_id_rp;
        $stmt = $pdo->prepare("
            SELECT rpu.id, rpu.fecha, rpu.hora, rpu.tipo_pago, rpu.banco, rpu.referencia, rpu.cantidad_inscritos, rpu.monto, rpu.estatus,
                   u.nombre as usuario_nombre, u.cedula as usuario_cedula
            FROM reportes_pago_usuarios rpu
            INNER JOIN inscritos i ON i.id = rpu.inscrito_id AND i.torneo_id = rpu.torneo_id
            INNER JOIN usuarios u ON u.id = rpu.id_usuario
            WHERE rpu.torneo_id = ? AND i.id_club = ?
            ORDER BY rpu.fecha DESC, rpu.id DESC
        ");
        $stmt->execute([$torneo_id_rp, $club_id_rp]);
        $reportes_pago_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reporte_pagos_subtotal = array_sum(array_column($reportes_pago_list, 'monto'));
    } catch (Exception $e) {
        $error_message = 'Error al cargar reporte de pagos: ' . $e->getMessage();
    }
}

// Obtener lista de torneos para el filtro
$tournaments_filter = [];
try {
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_clause = "WHERE t.estatus = 1";
    
    if (!empty($tournament_filter['where'])) {
        $where_clause .= " AND " . $tournament_filter['where'];
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

if ($action === 'list' && !empty($filter_torneo)) {
    try {
        // Configurar paginación
        $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
        $per_page = Pagination::DEFAULT_PER_PAGE;
        
        // Construir query con filtro de torneo
        $where = "WHERE i.torneo_id = ?";
        $params = [(int)$filter_torneo];
        
        // Contar total de registros con filtro
        $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM " . TABLE_INVITATIONS . " i $where");
        $stmt->execute($params);
        $total_records = (int)$stmt->fetchColumn();
        
        // Crear objeto de paginación
        $pagination = new Pagination($total_records, $current_page, $per_page);
        
        // Obtener registros de la página actual
        $cols_t = DB::pdo()->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
        $cols_inv = DB::pdo()->query("SHOW COLUMNS FROM " . TABLE_INVITATIONS)->fetchAll(PDO::FETCH_COLUMN);
        $has_id_usuario_vinculado = in_array('id_usuario_vinculado', $cols_inv);
        $sel_hora = in_array('hora_torneo', $cols_t) ? 't.hora_torneo as torneo_hora' : (in_array('hora', $cols_t) ? 't.hora as torneo_hora' : 'NULL as torneo_hora');
        $join_usuario = $has_id_usuario_vinculado ? "LEFT JOIN usuarios u ON u.id = i.id_usuario_vinculado" : "";
        $sel_usuario = $has_id_usuario_vinculado ? ", u.nombre as usuario_vinculado_nombre" : "";
        $stmt = DB::pdo()->prepare("
            SELECT 
                i.*,
                t.nombre as torneo_nombre,
                t.fechator as torneo_fecha,
                $sel_hora,
                c.nombre as club_nombre,
                c.delegado as club_delegado,
                c.telefono as club_telefono
                $sel_usuario
            FROM " . TABLE_INVITATIONS . " i
            INNER JOIN tournaments t ON i.torneo_id = t.id
            INNER JOIN clubes c ON i.club_id = c.id
            $join_usuario
            $where
            ORDER BY i.fecha_creacion DESC
            LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}
        ");
        $stmt->execute($params);
        $invitations_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estadísticas del torneo filtrado
        $stmt = DB::pdo()->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'activa' THEN 1 ELSE 0 END) as activas,
                SUM(CASE WHEN estado = 'expirada' THEN 1 ELSE 0 END) as expiradas,
                SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) as canceladas
            FROM " . TABLE_INVITATIONS . " i
            $where
        ");
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Error al cargar las invitaciones: " . $e->getMessage();
        $stats = ['total' => 0, 'activas' => 0, 'expiradas' => 0, 'canceladas' => 0];
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <?php
            $url_retorno_origen = '';
            if (!empty($_GET['return_to']) && $_GET['return_to'] === 'invitacion_clubes' && !empty($_GET['torneo_id'])) {
                $url_retorno_origen = 'index.php?page=invitacion_clubes&torneo_id=' . (int)$_GET['torneo_id'];
            } elseif (!empty($filter_torneo)) {
                $url_retorno_origen = 'index.php?page=invitacion_clubes&torneo_id=' . (int)$filter_torneo;
            } else {
                $url_retorno_origen = class_exists('AppHelpers') ? AppHelpers::dashboard('home') : 'index.php?page=home';
            }
            ?>
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-envelope me-2"></i>Invitaciones
                    </h1>
                    <p class="text-muted mb-0">Gestión de invitaciones a torneos</p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <a href="<?= htmlspecialchars($url_retorno_origen) ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Retorno al origen
                    </a>
                    <?php if ($action === 'list'): ?>
                        <a href="index.php?page=invitations&action=new&torneo_id=<?= (int)$filter_torneo ?>" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Nueva Invitación
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
            
            <?php if ($is_admin_torneo && $user_club_id): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de Torneo:</strong> Solo puede gestionar invitaciones de torneos asignados a su club (ID: <?= $user_club_id ?>).
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($is_admin_club): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de Organización:</strong> Solo puede ver y gestionar invitaciones de torneos de su organización.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'reporte_pagos'): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Reporte de pagos — <?= htmlspecialchars($reporte_pagos_club_nombre) ?></h5>
            <a href="index.php?page=invitations&filter_torneo=<?= (int)$filter_torneo ?>" class="btn btn-sm btn-outline-dark"><i class="fas fa-arrow-left me-1"></i>Volver a invitaciones</a>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Torneo: <strong><?= htmlspecialchars($reporte_pagos_torneo_nombre) ?></strong></p>
            <?php if (empty($reportes_pago_list)): ?>
                <p class="mb-0 text-muted">No hay reportes de pago registrados para este club en este torneo.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Jugador / Inscrito</th>
                                <th>Banco</th>
                                <th>Comprobante</th>
                                <th>Fecha</th>
                                <th>Cat. insc</th>
                                <th>Total</th>
                                <th>Validación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportes_pago_list as $rp): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rp['usuario_nombre'] ?? '') ?> (<?= htmlspecialchars($rp['usuario_cedula'] ?? '') ?>)</td>
                                    <td><?= htmlspecialchars($rp['banco'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($rp['referencia'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars(isset($rp['fecha']) ? date('d/m/Y', strtotime($rp['fecha'])) : '-') ?> <?= htmlspecialchars($rp['hora'] ?? '') ?></td>
                                    <td><?= (int)($rp['cantidad_inscritos'] ?? 1) ?></td>
                                    <td>$<?= number_format((float)($rp['monto'] ?? 0), 2) ?></td>
                                    <td>
                                        <?php $est = $rp['estatus'] ?? ''; ?>
                                        <?php if ($est === 'confirmado'): ?><span class="badge bg-success">Confirmado</span>
                                        <?php elseif ($est === 'rechazado'): ?><span class="badge bg-danger">Rechazado</span>
                                        <?php else: ?><span class="badge bg-warning">Pendiente</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">Subtotal club invitado:</th>
                                <th>$<?= number_format($reporte_pagos_subtotal, 2) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($action === 'list'): ?>
    <?php
    $torneo_nombre_filtro = '';
    foreach ($tournaments_filter as $t) {
        if ((int)$t['id'] === (int)$filter_torneo) {
            $torneo_nombre_filtro = $t['nombre'] . ' (' . date('d/m/Y', strtotime($t['fechator'])) . ')';
            break;
        }
    }
    ?>
    <div class="alert alert-secondary mb-3 py-2">
        <i class="fas fa-trophy me-2"></i><strong>Torneo:</strong> <?= htmlspecialchars($torneo_nombre_filtro ?: 'Torneo #' . (int)$filter_torneo) ?>
    </div>
    
    <?php if (!empty($filter_torneo)): ?>
    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    <p class="mb-0">Total Invitaciones</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['activas'] ?></h2>
                    <p class="mb-0">Activas</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['expiradas'] ?></h2>
                    <p class="mb-0">Expiradas</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['canceladas'] ?></h2>
                    <p class="mb-0">Canceladas</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Vista de Lista -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($invitations_list)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No hay invitaciones registradas.
                    <a href="index.php?page=invitations&action=new&torneo_id=<?= (int)$filter_torneo ?>" class="alert-link">Crear la primera invitación</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Torneo</th>
                                <th>Club</th>
                                <th>Delegado</th>
                                <th>Vigencia</th>
                                <th>Token</th>
                                <th>Estado</th>
                                <?php if (!empty($has_id_usuario_vinculado)): ?><th>Vinculación</th><?php endif; ?>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invitations_list as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$item['id']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($item['torneo_nombre']) ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?= date('d/m/Y', strtotime($item['torneo_fecha'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($item['club_nombre']) ?></strong>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($item['club_delegado'] ?? 'N/A') ?><br>
                                        <small class="text-muted"><?= htmlspecialchars($item['club_telefono'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="fas fa-calendar-check text-success"></i> 
                                            <?= date('d/m/Y', strtotime($item['acceso1'])) ?>
                                            <br>
                                            <i class="fas fa-calendar-times text-danger"></i> 
                                            <?= date('d/m/Y', strtotime($item['acceso2'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <code class="small"><?= substr($item['token'], 0, 12) ?>...</code>
                                        <button class="btn btn-sm btn-outline-secondary" 
                                                onclick="copyToken('<?= htmlspecialchars($item['token']) ?>')" 
                                                title="Copiar token completo">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_color = 'secondary';
                                        if ($item['estado'] === 'activa') $badge_color = 'success';
                                        elseif ($item['estado'] === 'expirada') $badge_color = 'warning';
                                        elseif ($item['estado'] === 'cancelada') $badge_color = 'danger';
                                        ?>
                                        <span class="badge bg-<?= $badge_color ?>">
                                            <?= htmlspecialchars(ucfirst($item['estado'])) ?>
                                        </span>
                                    </td>
                                    <?php if (!empty($has_id_usuario_vinculado)): ?>
                                    <td>
                                        <?php
                                        $id_vinculado = isset($item['id_usuario_vinculado']) ? (int)$item['id_usuario_vinculado'] : 0;
                                        if ($id_vinculado > 0 && !empty($item['usuario_vinculado_nombre'])): ?>
                                            <span class="badge bg-success" title="Vinculado a usuario ID <?= $id_vinculado ?>">Vinculado a: <?= htmlspecialchars($item['usuario_vinculado_nombre']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Sin vincular</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="text-center">
                                        <?php
                                        $delegado_nombre = !empty($item['club_delegado']) ? $item['club_delegado'] : 'Delegado';
                                        $fecha_txt = date('d/m/Y', strtotime($item['torneo_fecha']));
                                        $hora_txt = isset($item['torneo_hora']) && $item['torneo_hora'] !== '' && $item['torneo_hora'] !== null ? $item['torneo_hora'] : 'Por confirmar';
                                        if (is_string($hora_txt) && preg_match('/^\d{2}:\d{2}/', $hora_txt)) { $hora_txt = substr($hora_txt, 0, 5); }
                                        $url_tarjeta = rtrim(AppHelpers::getPublicUrl(), '/') . '/invitation/digital?token=' . urlencode($item['token']);
                                        $url_acceso = InvitationJoinResolver::buildJoinUrl($item['token']);
                                        $url_inscripciones = $url_acceso;
                                        $url_portal_admin = rtrim(AppHelpers::getPublicUrl(), '/') . '/invitation/register?torneo=' . (int)$item['torneo_id'] . '&club=' . (int)$item['club_id'];
                                        $msg_invitacion = "Estimado delegado de " . $item['club_nombre'] . ", le invitamos formalmente a nuestro evento " . $item['torneo_nombre'] . ". Enlace de acceso (registro e inscripción de jugadores): " . $url_acceso . " — Ver detalles de la invitación: " . $url_tarjeta;
                                        $url_wa = 'https://api.whatsapp.com/send?text=' . rawurlencode($msg_invitacion);
                                        $url_telegram = 'https://t.me/share/url?url=' . rawurlencode($url_acceso) . '&text=' . rawurlencode($msg_invitacion);
                                        ?>
                                        <div class="btn-group" role="group">
                                            <a href="<?= htmlspecialchars($url_inscripciones) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener noreferrer" title="Abrir formulario de inscripciones (enlace para el club)">
                                                <i class="fas fa-user-plus"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($url_portal_admin) ?>" class="btn btn-sm btn-outline-primary" title="Ver inscritos (portal del club, acceso admin)">
                                                <i class="fas fa-list-alt"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-copy-inscription-link" title="Copiar enlace de inscripciones" data-url="<?= htmlspecialchars($url_inscripciones) ?>">
                                                <i class="fas fa-link"></i>
                                            </button>
                                            <a href="<?= htmlspecialchars($url_wa) ?>" class="btn btn-sm btn-success" target="_blank" rel="noopener noreferrer" title="Enviar por WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                            <a href="<?= htmlspecialchars($url_telegram) ?>" class="btn btn-sm btn-info" target="_blank" rel="noopener noreferrer" title="Enviar por Telegram"><i class="fab fa-telegram"></i></a>
                                            <a href="<?= htmlspecialchars($url_tarjeta) ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" title="Ver tarjeta digital">
                                                <i class="fas fa-id-card"></i>
                                            </a>
                                            <a href="index.php?page=invitations&action=reporte_pagos&torneo_id=<?= (int)$item['torneo_id'] ?>&club_id=<?= (int)$item['club_id'] ?>&filter_torneo=<?= (int)$filter_torneo ?>" 
                                               class="btn btn-sm btn-outline-warning" title="Ver reporte de pagos">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <a href="index.php?page=invitations&action=edit&id=<?= $item['id'] ?>&filter_torneo=<?= $filter_torneo ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    title="Copiar Token"
                                                    onclick="copyToken('<?= htmlspecialchars($item['token']) ?>')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <a href="index.php?page=invitations&action=delete&id=<?= $item['id'] ?>&filter_torneo=<?= $filter_torneo ?>" 
                                               class="btn btn-sm btn-outline-danger" title="Eliminar"
                                               onclick="return confirm('¿Está seguro de eliminar esta invitación para <?= htmlspecialchars($item['club_nombre']) ?>?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación -->
                <?php if ($pagination): ?>
                    <?= $pagination->render() ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // Fin del if filter_torneo ?>

<?php elseif ($action === 'new' || ($action === 'edit' && $invitation)): ?>
    <!-- Formulario -->
    <div class="card">
        <div class="card-header bg-<?= $action === 'edit' ? 'warning' : 'success' ?> text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-<?= $action === 'edit' ? 'edit' : 'plus-circle' ?> me-2"></i>
                <?= $action === 'edit' ? 'Editar' : 'Nueva' ?> Invitación
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="index.php?page=invitations&action=<?= $action === 'edit' ? 'edit_save' : 'create' ?>">
                <?= CSRF::input(); ?>
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)$invitation['id'] ?>">
                    <?php if (!empty($_GET['filter_torneo'])): ?>
                    <input type="hidden" name="filter_torneo" value="<?= (int)$_GET['filter_torneo'] ?>">
                    <?php endif; ?>
                    <?php if (!empty($_GET['return_to']) && $_GET['return_to'] === 'invitacion_clubes' && !empty($_GET['torneo_id'])): ?>
                    <input type="hidden" name="return_to" value="invitacion_clubes">
                    <input type="hidden" name="torneo_id" value="<?= (int)$_GET['torneo_id'] ?>">
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Torneo</label>
                            <?php
                            $torneo_id_form = $action === 'edit' ? (int)$invitation['torneo_id'] : (int)($_GET['torneo_id'] ?? $_GET['filter_torneo'] ?? 0);
                            $torneo_nombre_form = '';
                            foreach ($tournaments_list as $tournament) {
                                if ((int)$tournament['id'] === $torneo_id_form) {
                                    $torneo_nombre_form = $tournament['nombre'] . ' - ' . date('d/m/Y', strtotime($tournament['fechator']));
                                    break;
                                }
                            }
                            if ($torneo_nombre_form === '' && $action === 'edit' && !empty($invitation['torneo_nombre'])) {
                                $torneo_nombre_form = $invitation['torneo_nombre'];
                            }
                            $default_acceso1 = '';
                            $default_acceso2 = '';
                            if ($action === 'new' && $torneo_id_form > 0) {
                                foreach ($tournaments_list as $tournament) {
                                    if ((int)$tournament['id'] === $torneo_id_form && !empty($tournament['fechator'])) {
                                        $f = new DateTime($tournament['fechator']);
                                        $default_acceso1 = (clone $f)->modify('-7 days')->format('Y-m-d');
                                        $default_acceso2 = (clone $f)->modify('-1 day')->format('Y-m-d');
                                        break;
                                    }
                                }
                            }
                            ?>
                            <input type="hidden" name="torneo_id" value="<?= $torneo_id_form ?>">
                            <div class="form-control-plaintext fw-semibold"><?= htmlspecialchars($torneo_nombre_form ?: 'Torneo #' . $torneo_id_form) ?></div>
                            <small class="text-muted">Definido por el panel de control del torneo</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="club_id" class="form-label">Club *</label>
                            <select class="form-select" id="club_id" name="club_id" required <?= $action === 'edit' ? 'disabled' : '' ?>>
                                <option value="">Seleccionar club...</option>
                                <?php foreach ($clubs_list as $club): ?>
                                    <option value="<?= (int)$club['id'] ?>"
                                            <?= ($action === 'edit' && $invitation['club_id'] == $club['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($club['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="club_id" value="<?= (int)$invitation['club_id'] ?>">
                                <small class="text-muted">El club no puede cambiarse al editar</small>
                            <?php elseif ($action === 'new'): ?>
                                <small class="text-muted">Solo se muestran clubes que aún no están invitados a este torneo.</small>
                                <?php if (empty($clubs_list)): ?>
                                    <div class="alert alert-info mt-2 mb-0">No hay más clubes disponibles para invitar; todos los clubes activos ya tienen invitación en este torneo.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="acceso1" class="form-label">Fecha Inicio de Acceso *</label>
                            <input type="date" class="form-control" id="acceso1" name="acceso1" 
                                   value="<?= htmlspecialchars($action === 'edit' ? $invitation['acceso1'] : ($default_acceso1 ?? '')) ?>" required>
                            <small class="text-muted">Desde cu�ndo el club puede inscribir jugadores</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="acceso2" class="form-label">Fecha Fin de Acceso *</label>
                            <input type="date" class="form-control" id="acceso2" name="acceso2" 
                                   value="<?= htmlspecialchars($action === 'edit' ? $invitation['acceso2'] : ($default_acceso2 ?? '')) ?>" required>
                            <small class="text-muted">Hasta cu�ndo el club puede inscribir jugadores</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="estado" class="form-label">Estado *</label>
                            <select class="form-select" id="estado" name="estado" required>
                                <option value="activa"<?= ($action === 'edit' && $invitation['estado'] === 'activa') ? ' selected' : ' selected' ?>>Activa</option>
                                <option value="expirada"<?= ($action === 'edit' && $invitation['estado'] === 'expirada') ? ' selected' : '' ?>>Expirada</option>
                                <option value="cancelada"<?= ($action === 'edit' && $invitation['estado'] === 'cancelada') ? ' selected' : '' ?>>Cancelada</option>
                            </select>
                        </div>
                        <?php if ($action === 'edit'): ?>
                        <div class="mb-3">
                            <label for="invitado_delegado" class="form-label">Delegado / Contacto invitado</label>
                            <input type="text" class="form-control" id="invitado_delegado" name="invitado_delegado" 
                                   value="<?= htmlspecialchars($invitation['invitado_delegado'] ?? '') ?>" 
                                   placeholder="Nombre del delegado o contacto">
                            <small class="text-muted">Se mantiene el token; actualice si cambió el responsable en el club.</small>
                        </div>
                        <div class="mb-3">
                            <label for="invitado_email" class="form-label">Email del invitado</label>
                            <input type="email" class="form-control" id="invitado_email" name="invitado_email" 
                                   value="<?= htmlspecialchars($invitation['invitado_email'] ?? '') ?>" placeholder="email@ejemplo.com">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Nota importante:</strong> El club invitado podrá registrar jugadores hasta la <strong>fecha del torneo</strong>, 
                    aunque el per�odo de acceso (acceso2) sea posterior.
                </div>
                
                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <?php
                    $back_url = 'index.php?page=invitations';
                    if ($action === 'edit' && !empty($_GET['return_to']) && $_GET['return_to'] === 'invitacion_clubes' && !empty($_GET['torneo_id'])) {
                        $back_url = 'index.php?page=invitacion_clubes&torneo_id=' . (int)$_GET['torneo_id'];
                    } elseif ($action === 'edit' && isset($_GET['filter_torneo'])) {
                        $back_url .= '&filter_torneo=' . (int)$_GET['filter_torneo'];
                    } elseif ($action === 'new' && $torneo_id_form > 0) {
                        $back_url .= '&filter_torneo=' . $torneo_id_form;
                    }
                    ?>
                    <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-<?= $action === 'edit' ? 'warning' : 'success' ?>">
                        <i class="fas fa-save me-2"></i><?= $action === 'edit' ? 'Actualizar Invitación' : 'Crear Invitación' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
function copyToken(token) {
    navigator.clipboard.writeText(token).then(() => {
        alert('Token copiado al portapapeles');
    });
}
document.querySelectorAll('.btn-copy-inscription-link').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = this.getAttribute('data-url');
        if (url && navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() { alert('Enlace de inscripciones copiado.'); });
        }
    });
});

</script>

