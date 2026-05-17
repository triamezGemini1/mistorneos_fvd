<?php

if (!defined('APP_BOOTSTRAPPED')) { require __DIR__ . '/../config/bootstrap.php'; }
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/Pagination.php';

// Verificar roles permitidos
Auth::requireRole(['admin_general', 'admin_club']);

$action = $_GET['action'] ?? 'list';
$user_id = (int)($_GET['id'] ?? 0);

if ($action === 'send_access_notification' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require __DIR__ . '/users/send_access_notification.php';
    exit;
}

if ($action === 'send_access_notification_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/users/send_access_notification_batch.php';
    exit;
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $action;
    
    switch ($action) {
        case 'create':
            handleCreateUser();
            break;
        case 'update':
            handleUpdateUser();
            break;
        case 'delete':
            handleDeleteUser();
            break;
        case 'toggle_status':
            handleToggleStatus();
            break;
        case 'change_password':
            handleChangePassword();
            break;
        case 'approve_request':
            handleApproveRequest();
            break;
        case 'reject_request':
            handleRejectRequest();
            break;
        case 'assign_admin_torneo':
            handleAssignAdminTorneo();
            break;
        case 'assign_operador':
            handleAssignOperador();
            break;
        case 'change_role':
            handleChangeRole();
            break;
    }
}

function getReturnUrl(): string {
    $return_to = trim($_POST['return_to'] ?? $_GET['return_to'] ?? '');
    if ($return_to !== '' && preg_match('/^[a-z_]+$/', $return_to)) {
        $params = ['page' => $return_to];
        if ($return_to === 'admin_torneo_operadores') {
            $return_tab = trim($_POST['return_tab'] ?? $_GET['return_tab'] ?? '');
            $return_club_id = isset($_POST['return_club_id']) ? (int)$_POST['return_club_id'] : (isset($_GET['return_club_id']) ? (int)$_GET['return_club_id'] : 0);
            if ($return_tab !== '' && preg_match('/^(admin_torneo|operadores)$/', $return_tab)) {
                $params['tab'] = $return_tab;
            }
            if ($return_club_id > 0) {
                $params['club_id'] = $return_club_id;
            }
        }
        $query = http_build_query($params);
        return '?' . $query;
    }
    return '?page=users&action=list';
}

/**
 * Asigna el rol Admin Torneo a un usuario ya registrado en la plataforma (afiliado por cédula o usuario).
 */
function handleAssignAdminTorneo() {
    $current_user = Auth::user();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $club_id = isset($_POST['club_id']) && $_POST['club_id'] !== '' ? (int)$_POST['club_id'] : null;

    if ($user_id <= 0) {
        $_SESSION['errors'] = ['Usuario inválido'];
        header('Location: ' . getReturnUrl());
        exit;
    }
    if (!$club_id) {
        $_SESSION['errors'] = ['Debe seleccionar un club para el Admin Torneo'];
        header('Location: ' . getReturnUrl());
        exit;
    }

    $is_admin_club = $current_user['role'] === 'admin_club';
    if ($is_admin_club) {
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $my_clubs = ClubHelper::getClubesSupervised((int)$current_user['club_id']);
        if (!in_array($club_id, $my_clubs)) {
            $_SESSION['errors'] = ['Solo puede asignar Admin Torneo a usuarios de sus clubes'];
            header('Location: ' . getReturnUrl());
            exit;
        }
    }

    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT id, club_id, role FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $_SESSION['errors'] = ['Usuario no encontrado'];
            header('Location: ' . getReturnUrl());
            exit;
        }
        if ($is_admin_club) {
            $my_clubs = ClubHelper::getClubesSupervised((int)$current_user['club_id']);
            if (!in_array((int)($user['club_id'] ?? 0), $my_clubs)) {
                $_SESSION['errors'] = ['Solo puede asignar Admin Torneo a afiliados de sus clubes'];
                header('Location: ' . getReturnUrl());
                exit;
            }
        }

        $entidad_org = 0;
        $stmt_org = $pdo->prepare("SELECT c.cod_org FROM clubes c WHERE c.id = ?");
        $stmt_org->execute([$club_id]);
        $org_id = $stmt_org->fetchColumn();
        if ($org_id) {
            $stmt_ent = $pdo->prepare("SELECT entidad FROM organizaciones WHERE id = ?");
            $stmt_ent->execute([$org_id]);
            $entidad_org = (int)$stmt_ent->fetchColumn();
        }
        if ($entidad_org > 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET role = 'admin_torneo', club_id = ?, entidad = ? WHERE id = ?");
            $stmt->execute([$club_id, $entidad_org, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET role = 'admin_torneo', club_id = ? WHERE id = ?");
            $stmt->execute([$club_id, $user_id]);
        }
        $_SESSION['success_message'] = 'Usuario asignado como Admin Torneo correctamente.';
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al asignar: ' . $e->getMessage()];
    }
    header('Location: ' . getReturnUrl());
    exit;
}

/**
 * Asigna el rol Operador a un usuario ya registrado. Misma lógica que Admin Torneo pero para operador.
 */
function handleAssignOperador() {
    $current_user = Auth::user();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $club_id = isset($_POST['club_id']) && $_POST['club_id'] !== '' ? (int)$_POST['club_id'] : null;

    if ($user_id <= 0) {
        $_SESSION['errors'] = ['Usuario inválido'];
        header('Location: ' . getReturnUrl());
        exit;
    }
    $is_admin_club = $current_user['role'] === 'admin_club';
    if ($is_admin_club) {
        $club_id = (int)($current_user['club_id'] ?? 0);
    }
    if (!$club_id) {
        $_SESSION['errors'] = ['Debe seleccionar un club para el Operador'];
        header('Location: ' . getReturnUrl());
        exit;
    }

    if ($is_admin_club) {
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $my_clubs = ClubHelper::getClubesSupervised((int)$current_user['club_id']);
        if (!in_array($club_id, $my_clubs)) {
            $_SESSION['errors'] = ['Solo puede asignar Operador a usuarios de sus clubes'];
            header('Location: ' . getReturnUrl());
            exit;
        }
    }

    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT id, club_id, role FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $_SESSION['errors'] = ['Usuario no encontrado'];
            header('Location: ' . getReturnUrl());
            exit;
        }
        // Al asignar operador, se incorpora al usuario a la organización del admin (club_id y entidad de la organización)
        $entidad_org = 0;
        $stmt_org = $pdo->prepare("SELECT c.cod_org FROM clubes c WHERE c.id = ?");
        $stmt_org->execute([$club_id]);
        $org_id = $stmt_org->fetchColumn();
        if ($org_id) {
            $stmt_ent = $pdo->prepare("SELECT entidad FROM organizaciones WHERE id = ?");
            $stmt_ent->execute([$org_id]);
            $entidad_org = (int)$stmt_ent->fetchColumn();
        }
        if ($entidad_org > 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET role = 'operador', club_id = ?, entidad = ? WHERE id = ?");
            $stmt->execute([$club_id, $entidad_org, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET role = 'operador', club_id = ? WHERE id = ?");
            $stmt->execute([$club_id, $user_id]);
        }
        $_SESSION['success_message'] = 'Usuario asignado como Operador correctamente.';
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al asignar: ' . $e->getMessage()];
    }
    header('Location: ' . getReturnUrl());
    exit;
}

/**
 * Cambia el rol de un usuario (admin_torneo, operador, usuario). Usado desde la página Admin Torneo y Operadores.
 */
function handleChangeRole() {
    $current_user = Auth::user();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_role = trim($_POST['new_role'] ?? '');
    $club_id = isset($_POST['club_id']) && $_POST['club_id'] !== '' ? (int)$_POST['club_id'] : null;

    if ($user_id <= 0) {
        $_SESSION['errors'] = ['Usuario inválido'];
        header('Location: ' . getReturnUrl());
        exit;
    }
    if (!in_array($new_role, ['admin_torneo', 'operador', 'usuario'], true)) {
        $_SESSION['errors'] = ['Rol no válido'];
        header('Location: ' . getReturnUrl());
        exit;
    }

    $is_admin_club = $current_user['role'] === 'admin_club';
    if ($is_admin_club && in_array($new_role, ['admin_torneo', 'operador'], true)) {
        $club_id = (int)($current_user['club_id'] ?? 0);
    }
    if (in_array($new_role, ['admin_torneo', 'operador'], true) && !$club_id) {
        $_SESSION['errors'] = ['Debe seleccionar un club para ' . ($new_role === 'admin_torneo' ? 'Admin Torneo' : 'Operador')];
        header('Location: ' . getReturnUrl());
        exit;
    }

    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT id, club_id, role FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $_SESSION['errors'] = ['Usuario no encontrado'];
            header('Location: ' . getReturnUrl());
            exit;
        }
        if ($is_admin_club) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $my_clubs = ClubHelper::getClubesSupervised((int)$current_user['club_id']);
            $user_club = (int)($user['club_id'] ?? 0);
            if ($new_role === 'usuario') {
                if (!in_array($user_club, $my_clubs)) {
                    $_SESSION['errors'] = ['Solo puede cambiar rol de usuarios de sus clubes'];
                    header('Location: ' . getReturnUrl());
                    exit;
                }
                $club_id = null;
            } else {
                if ($user_club > 0 && !in_array($user_club, $my_clubs)) {
                    $_SESSION['errors'] = ['Solo puede asignar como Admin Torneo u Operador a afiliados de sus clubes'];
                    header('Location: ' . getReturnUrl());
                    exit;
                }
                $club_id = (int)$current_user['club_id'];
            }
        }

        if ($new_role === 'usuario') {
            $stmt = $pdo->prepare("UPDATE usuarios SET role = ?, club_id = ? WHERE id = ?");
            $stmt->execute(['usuario', null, $user_id]);
        } else {
            $entidad_org = 0;
            $stmt_org = $pdo->prepare("SELECT c.cod_org FROM clubes c WHERE c.id = ?");
            $stmt_org->execute([$club_id]);
            $org_id = $stmt_org->fetchColumn();
            if ($org_id) {
                $stmt_ent = $pdo->prepare("SELECT entidad FROM organizaciones WHERE id = ?");
                $stmt_ent->execute([$org_id]);
                $entidad_org = (int)$stmt_ent->fetchColumn();
            }
            if ($entidad_org > 0) {
                $stmt = $pdo->prepare("UPDATE usuarios SET role = ?, club_id = ?, entidad = ? WHERE id = ?");
                $stmt->execute([$new_role, $club_id, $entidad_org, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET role = ?, club_id = ? WHERE id = ?");
                $stmt->execute([$new_role, $club_id, $user_id]);
            }
        }
        $_SESSION['success_message'] = 'Rol actualizado correctamente.';
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al cambiar rol: ' . $e->getMessage()];
    }
    header('Location: ' . getReturnUrl());
    exit;
}

function handleCreateUser() {
    $current_user = Auth::user();
    $is_admin_club = $current_user['role'] === 'admin_club';
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'usuario';
    $cedula = trim($_POST['cedula'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $fechnac = trim($_POST['fechnac'] ?? '');
    $entidad = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
    
    // Manejar club_id
    $club_id = null;
    if (!empty($_POST['club_id']) && $_POST['club_id'] !== '0') {
        $club_id = (int)$_POST['club_id'];
    }
    
    // Si es admin_club, forzar el club_id a su propio club
    if ($is_admin_club) {
        $club_id = $current_user['club_id'];
    }
    
    // Para admin_general y usuario, forzar club_id a NULL
    if (in_array($role, ['admin_general', 'usuario'])) {
        $club_id = null;
    }
    
    $errors = [];
    
    // Validar roles permitidos según el usuario actual
    if ($is_admin_club) {
        // admin_club solo puede crear admin_torneo y operador
        $allowed_roles = ['admin_torneo', 'operador'];
        if (!in_array($role, $allowed_roles)) {
            $errors[] = 'Solo puedes crear usuarios con rol Admin Torneo u Operador';
        }
    }
    
    // Validaciones
    if (empty($cedula)) {
        $errors[] = 'La cédula es requerida';
    }
    
    if (empty($nombre)) {
        $errors[] = 'El nombre es requerido';
    }
    
    if (empty($username)) {
        $errors[] = 'El nombre de usuario es requerido';
    } elseif (strlen($username) < 3) {
        $errors[] = 'El nombre de usuario debe tener al menos 3 caracteres';
    }
    
    if (empty($password)) {
        $errors[] = 'La contraseña es requerida';
    } elseif (strlen($password) < 6) {
        $errors[] = 'La contraseña debe tener al menos 6 caracteres';
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no es válido';
    }
    
    if (!in_array($role, ['admin_general', 'admin_torneo', 'admin_club', 'usuario', 'operador'])) {
        $errors[] = 'El rol seleccionado no es válido';
    }
    
    // Validar que admin_torneo tenga club asignado
    if ($role === 'admin_torneo' && !$club_id) {
        $errors[] = 'Los usuarios con rol Admin Torneo deben tener un club asignado';
    }
    
    // Admin Organización puede crearse sin club asignado (lo crea posteriormente)

    // Entidad: debe ser la de la organización cuando admin_club crea admin_torneo/operador
    $entidad_raw = trim((string)($_POST['entidad'] ?? ''));
    if ($entidad_raw !== '' && is_numeric($entidad_raw)) {
        $entidad = (int)$entidad_raw;
    }
    if ($is_admin_club && in_array($role, ['admin_torneo', 'operador'], true)) {
        $organizacion_id = Auth::getUserOrganizacionId();
        if ($organizacion_id) {
            $stmt_ent = DB::pdo()->prepare("SELECT entidad FROM organizaciones WHERE id = ? AND estatus = 1");
            $stmt_ent->execute([$organizacion_id]);
            $entidad_org = (int)$stmt_ent->fetchColumn();
            if ($entidad_org > 0) {
                $entidad = $entidad_org;
            }
        }
        if ($entidad <= 0) {
            $errors[] = 'Su organización no tiene entidad definida. No se puede registrar operador ni admin de torneo sin entidad.';
        }
    } elseif (!$is_admin_club && $entidad <= 0) {
        $errors[] = 'Debe seleccionar la entidad geográfica';
    }
    
    if (empty($errors)) {
        // Obtener sexo si está disponible
        $sexo = null;
        if (isset($_POST['sexo']) && !empty($_POST['sexo'])) {
            $sexo = strtoupper(trim($_POST['sexo']));
            if (!in_array($sexo, ['M', 'F', 'O'])) {
                $sexo = null;
            }
        }
        
        // Si no hay sexo, intentar obtenerlo desde la BD externa
        if (!$sexo && !empty($cedula)) {
            require_once __DIR__ . '/../config/persona_database.php';
            try {
                $personaDb = new PersonaDatabase();
                // Extraer nacionalidad y número de cédula
                $nacionalidad_busqueda = 'V';
                $cedula_numero = $cedula;
                if (preg_match('/^([VEJP])(\d+)$/i', $cedula, $matches)) {
                    $nacionalidad_busqueda = strtoupper($matches[1]);
                    $cedula_numero = $matches[2];
                }
                $result = $personaDb->searchPersonaById($nacionalidad_busqueda, $cedula_numero);
                if (isset($result['persona']['sexo']) && !empty($result['persona']['sexo'])) {
                    $sexo_raw = strtoupper(trim($result['persona']['sexo']));
                    if ($sexo_raw === 'M' || $sexo_raw === '1' || $sexo_raw === 'MASCULINO') {
                        $sexo = 'M';
                    } elseif ($sexo_raw === 'F' || $sexo_raw === '2' || $sexo_raw === 'FEMENINO') {
                        $sexo = 'F';
                    } else {
                        $sexo = 'O';
                    }
                }
            } catch (Exception $e) {
                error_log("Error al obtener sexo desde BD persona: " . $e->getMessage());
            }
        }
        
        // Usar función centralizada para crear usuario
        $userData = [
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'role' => $role,
            'cedula' => $cedula,
            'nombre' => $nombre,
            'celular' => $celular ?: null,
            'fechnac' => $fechnac ?: null,
            'sexo' => $sexo,
            'club_id' => ($role === 'admin_club' && !$club_id) ? null : $club_id,
            'entidad' => $entidad,
            'status' => 0
        ];
        
        $result = Security::createUser($userData);
        
        if ($result['success']) {
            $_SESSION['success_message'] = 'Usuario creado exitosamente';
            header('Location: ' . getReturnUrl());
            exit;
        } else {
            $errors = array_merge($errors, $result['errors']);
        }
    }
    
    $_SESSION['errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
    $return_to = trim($_POST['return_to'] ?? '');
    if ($return_to === 'admin_torneo_operadores') {
        header('Location: ' . getReturnUrl());
        exit;
    }
}

function handleUpdateUser() {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'usuario';
    $password = $_POST['password'] ?? '';
    $entidad = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
    
    // Manejar club_id: NULL para admin_general y usuario, obligatorio para admin_torneo
    $club_id = null;
    if (!empty($_POST['club_id']) && $_POST['club_id'] !== '0') {
        $club_id = (int)$_POST['club_id'];
    }
    
    // Para admin_general y usuario, forzar club_id a NULL
    if (in_array($role, ['admin_general', 'usuario'])) {
        $club_id = null;
    }
    
    $errors = [];
    
    if ($user_id <= 0) {
        $errors[] = 'ID de usuario inv�lido';
    }
    
    if (empty($username)) {
        $errors[] = 'El nombre de usuario es requerido';
    } elseif (strlen($username) < 3) {
        $errors[] = 'El nombre de usuario debe tener al menos 3 caracteres';
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no es v�lido';
    }
    
    if (!in_array($role, ['admin_general', 'admin_torneo', 'admin_club', 'usuario'])) {
        $errors[] = 'El rol seleccionado no es v�lido';
    }
    
    // Validar que admin_torneo tenga club asignado
    if ($role === 'admin_torneo' && !$club_id) {
        $errors[] = 'Los usuarios con rol Admin Torneo deben tener un club asignado';
    }
    
    // Admin Organización puede quedar sin club asignado (lo crea posteriormente)

    // Validar entidad (ubicación geográfica)
    if ($entidad <= 0) {
        $errors[] = 'Debe seleccionar la entidad geográfica';
    }
    
    if (empty($errors)) {
        try {
            $pdo = DB::pdo();
            
            // Verificar si el usuario existe y no es el mismo usuario actual
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                $errors[] = 'El nombre de usuario ya existe';
            } else {
                // Actualizar usuario
                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        $errors[] = 'La contrase�a debe tener al menos 6 caracteres';
                    } else {
                        $password_hash = Security::hashPassword($password);
                        $stmt = $pdo->prepare("UPDATE usuarios SET username = ?, email = ?, role = ?, password_hash = ?, club_id = ?, entidad = ? WHERE id = ?");
                        $stmt->execute([$username, $email ?: null, $role, $password_hash, $club_id, $entidad, $user_id]);
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET username = ?, email = ?, role = ?, club_id = ?, entidad = ? WHERE id = ?");
                    $stmt->execute([$username, $email ?: null, $role, $club_id, $entidad, $user_id]);
                }
                
                if (empty($errors)) {
                    $_SESSION['success_message'] = 'Usuario actualizado exitosamente';
                    header('Location: ?action=list');
                    exit;
                }
            }
        } catch (Exception $e) {
            $errors[] = 'Error al actualizar el usuario: ' . $e->getMessage();
        }
    }
    
    $_SESSION['errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
}

function handleDeleteUser() {
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        $_SESSION['errors'] = ['ID de usuario inv�lido'];
        return;
    }
    
    // No permitir eliminar el propio usuario
    $current_user = Auth::user();
    if ($current_user && $current_user['id'] === $user_id) {
        $_SESSION['errors'] = ['No puede eliminar su propio usuario'];
        return;
    }
    
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $_SESSION['success_message'] = 'Usuario eliminado exitosamente';
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al eliminar el usuario: ' . $e->getMessage()];
    }
    
    header('Location: ?action=list');
    exit;
}

function handleToggleStatus() {
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        $_SESSION['errors'] = ['ID de usuario inv�lido'];
        return;
    }
    
    // No permitir desactivar el propio usuario
    $current_user = Auth::user();
    if ($current_user && $current_user['id'] === $user_id) {
        $_SESSION['errors'] = ['No puede desactivar su propio usuario'];
        return;
    }
    
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("UPDATE usuarios SET status = IF(status = 0, 1, 0) WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $_SESSION['success_message'] = 'Estado del usuario actualizado exitosamente';
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al actualizar el estado del usuario: ' . $e->getMessage()];
    }
    
    header('Location: ?action=list');
    exit;
}

function handleChangePassword() {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    if ($user_id <= 0) {
        $errors[] = 'ID de usuario inv�lido';
    }
    
    if (empty($new_password)) {
        $errors[] = 'La nueva contrase�a es requerida';
    } elseif (strlen($new_password) < 6) {
        $errors[] = 'La contrase�a debe tener al menos 6 caracteres';
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = 'Las contrase�as no coinciden';
    }
    
    if (empty($errors)) {
        try {
            $pdo = DB::pdo();
            
            // Verificar que el usuario existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt->execute([$user_id]);
            if (!$stmt->fetch()) {
                $errors[] = 'Usuario no encontrado';
            } else {
                // Actualizar contrase�a
                $password_hash = Security::hashPassword($new_password);
                $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$password_hash, $user_id]);
                
                $_SESSION['success_message'] = 'Contrase�a actualizada exitosamente';
            }
        } catch (Exception $e) {
            $errors[] = 'Error al actualizar la contrase�a: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
    
    header('Location: ?action=list');
    exit;
}

function handleApproveRequest() {
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if ($request_id <= 0) {
        $_SESSION['errors'] = ['ID de solicitud inválido'];
        return;
    }
    
    try {
        $pdo = DB::pdo();
        
        // Obtener la solicitud
        $stmt = $pdo->prepare("SELECT * FROM user_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            $_SESSION['errors'] = ['Solicitud no encontrada o ya procesada'];
            return;
        }
        
        // Verificar que no exista un usuario con el mismo username o email
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? OR email = ?");
        $stmt->execute([$request['username'], $request['email']]);
        if ($stmt->fetch()) {
            $_SESSION['errors'] = ['Ya existe un usuario con este username o email'];
            return;
        }
        
        $entidad = isset($request['entidad']) ? (int)$request['entidad'] : null;
        $club_id = ($request['role'] === 'admin_club') ? null : ($request['club_id'] ?? null);
        
        // Crear el usuario
        $stmt = $pdo->prepare("INSERT INTO usuarios (username, password_hash, email, role, club_id, entidad, status) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([
            $request['username'], 
            $request['password_hash'], 
            $request['email'], 
            $request['role'],
            $club_id,
            $entidad
        ]);
        
        $user_id = $pdo->lastInsertId();
        
        // Actualizar la solicitud como aprobada
        $current_user = Auth::user();
        $stmt = $pdo->prepare("UPDATE user_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$current_user['id'], $request_id]);
        
        $_SESSION['success_message'] = 'Solicitud aprobada y usuario creado exitosamente';
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al aprobar la solicitud: ' . $e->getMessage()];
    }
    
    header('Location: ?action=requests');
    exit;
}

function handleRejectRequest() {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if ($request_id <= 0) {
        $_SESSION['errors'] = ['ID de solicitud inválido'];
        return;
    }
    
    try {
        $pdo = DB::pdo();
        $current_user = Auth::user();
        
        $stmt = $pdo->prepare("UPDATE user_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$current_user['id'], $reason, $request_id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = 'Solicitud rechazada exitosamente';
        } else {
            $_SESSION['errors'] = ['Solicitud no encontrada o ya procesada'];
        }
    } catch (Exception $e) {
        $_SESSION['errors'] = ['Error al rechazar la solicitud: ' . $e->getMessage()];
    }
    
    header('Location: ?action=requests');
    exit;
}

function getUsers($page = 1, $per_page = 25, $admin_id = null, $search = null, $club_id = null, $role_filter = null) {
    $pdo = DB::pdo();
    $current_user = Auth::user();
    $is_admin_club = $current_user['role'] === 'admin_club';
    $is_admin_general = Auth::isAdminGeneral();
    $role_filter = $role_filter ? strtolower(trim((string)$role_filter)) : null;
    if ($role_filter) {
        $allowed_roles = $is_admin_club
            ? ['admin_torneo', 'operador']
            : ['admin_general', 'admin_club', 'admin_torneo', 'usuario', 'operador'];
        if (!in_array($role_filter, $allowed_roles, true)) {
            $role_filter = null;
        }
    }
    
    // Si es admin_club y no hay club_id seleccionado, retornar lista de clubes supervisados
    if ($is_admin_club && !$club_id && $current_user['club_id'] && !$role_filter) {
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $clubes = ClubHelper::getClubesSupervisedWithData($current_user['club_id']);
        
        // Enriquecer con total de usuarios por club
        foreach ($clubes as &$club) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE club_id = ? AND role != 'admin_club'");
            $stmt->execute([$club['id']]);
            $club['total_usuarios'] = (int)$stmt->fetchColumn();
        }
        
        return [
            'data' => $clubes,
            'pagination' => null,
            'is_club_list' => true
        ];
    }
    
    // Filtro por club
    $where_clauses = [];
    $params = [];
    
    if ($role_filter) {
        $where_clauses[] = 'u.role = ?';
        $params[] = $role_filter;
    }
    
    if ($is_admin_club && $club_id) {
        // Verificar que el club pertenezca a los supervisados
        require_once __DIR__ . '/../lib/ClubHelper.php';
        if (ClubHelper::isClubSupervised($current_user['club_id'], $club_id)) {
            $where_clauses[] = 'u.club_id = ?';
            $params[] = $club_id;
        } else {
            // Si no tiene permiso, retornar vacío
            return [
                'data' => [],
                'pagination' => new Pagination(0, $page, $per_page),
                'is_club_list' => false
            ];
        }
    } elseif ($is_admin_club && $current_user['club_id']) {
        // Si es admin_club sin club_id seleccionado, mostrar todos los usuarios de sus clubes
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $clubes_ids = ClubHelper::getClubesSupervised($current_user['club_id']);
        if (!empty($clubes_ids)) {
            $placeholders = implode(',', array_fill(0, count($clubes_ids), '?'));
            $where_clauses[] = "u.club_id IN ($placeholders)";
            $params = array_merge($params, $clubes_ids);
        }
    } elseif ($is_admin_general && $admin_id && !$club_id) {
        // Si es admin_general y hay admin_id pero NO hay club_id, mostrar información del admin y sus clubes
        $stmt = $pdo->prepare("
            SELECT u.id, u.cedula, u.nombre, u.username, u.email, u.celular, u.role, u.status, u.created_at, u.club_id,
                   c.nombre as club_nombre, c.delegado, c.telefono as club_telefono, c.direccion as club_direccion
            FROM usuarios u 
            LEFT JOIN clubes c ON u.club_id = c.id 
            WHERE u.id = ? AND u.role = 'admin_club'
        ");
        $stmt->execute([$admin_id]);
        $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin_data && $admin_data['club_id']) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $clubes = ClubHelper::getClubesSupervisedWithData($admin_data['club_id']);
            
            // Enriquecer con total de usuarios por club
            $total_afiliados_general = 0;
            foreach ($clubes as &$club) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE club_id = ? AND role != 'admin_club' AND status = 0");
                $stmt->execute([$club['id']]);
                $club['total_usuarios'] = (int)$stmt->fetchColumn();
                $total_afiliados_general += $club['total_usuarios'];
            }
            
            // Obtener estadísticas adicionales
            $club_ids = ClubHelper::getClubesSupervised($admin_data['club_id']);
            $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable IN ($placeholders) AND estatus = 1");
            $stmt->execute($club_ids);
            $total_torneos = (int)$stmt->fetchColumn();
            
            return [
                'data' => $clubes,
                'pagination' => null,
                'is_club_list' => true,
                'admin_id' => $admin_id,
                'admin_info' => $admin_data,
                'total_afiliados' => $total_afiliados_general,
                'total_clubes' => count($clubes),
                'total_torneos' => $total_torneos
            ];
        } elseif ($admin_data) {
            return [
                'data' => [],
                'pagination' => null,
                'is_club_list' => true,
                'admin_id' => $admin_id,
                'admin_info' => $admin_data,
                'total_afiliados' => 0,
                'total_clubes' => 0,
                'total_torneos' => 0
            ];
        }
    } elseif ($is_admin_general && $admin_id && $club_id && $club_id === 'all') {
        // Si es admin_general y hay admin_id Y club_id = 'all', mostrar todos los afiliados de todos los clubes
        $stmt = $pdo->prepare("SELECT club_id FROM usuarios WHERE id = ? AND role = 'admin_club'");
        $stmt->execute([$admin_id]);
        $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin_data && $admin_data['club_id']) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $club_ids = ClubHelper::getClubesSupervised($admin_data['club_id']);
            if (!empty($club_ids)) {
                $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
                $where_clauses[] = "u.club_id IN ($placeholders)";
                $params = array_merge($params, $club_ids);
                // Excluir admin_club de los resultados
                $where_clauses[] = 'u.role != ?';
                $params[] = 'admin_club';
            }
        }
    } elseif ($is_admin_general && $admin_id && $club_id && $club_id !== 'all') {
        // Si es admin_general y hay admin_id Y club_id específico, filtrar usuarios del club seleccionado
        // Verificar que el club pertenezca al admin
        $stmt = $pdo->prepare("SELECT club_id FROM usuarios WHERE id = ? AND role = 'admin_club'");
        $stmt->execute([$admin_id]);
        $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin_data && $admin_data['club_id']) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            if (ClubHelper::isClubSupervised($admin_data['club_id'], $club_id)) {
                $where_clauses[] = 'u.club_id = ?';
                $params[] = $club_id;
                // Excluir admin_club de los resultados (solo mostrar usuarios normales/afiliados)
                $where_clauses[] = 'u.role != ?';
                $params[] = 'admin_club';
            } else {
                // Si no tiene permiso, retornar vacío
                return [
                    'data' => [],
                    'pagination' => new Pagination(0, $page, $per_page),
                    'is_club_list' => false
                ];
            }
        }
    } elseif ($is_admin_general && !$admin_id && $club_id && $club_id !== 'all') {
        // Admin_general: seleccionar club directamente - ver afiliados
        $where_clauses[] = 'u.club_id = ?';
        $params[] = $club_id;
        $where_clauses[] = 'u.role != ?';
        $params[] = 'admin_club';
    }
    
    // Excluir admin_club de los resultados cuando se muestran usuarios
    // Solo excluir si NO estamos en la vista inicial de admin_club para admin_general (cuando !$admin_id)
    if (!($is_admin_general && !$admin_id) && !$role_filter) {
        // Si hay filtros aplicados, excluir admin_club para mostrar solo usuarios normales
        if (!empty($where_clauses)) {
            // Verificar que no se haya agregado ya la exclusión de admin_club
            $has_admin_club_exclusion = false;
            foreach ($where_clauses as $clause) {
                if (strpos($clause, "u.role != ?") !== false || strpos($clause, "u.role <> 'admin_club'") !== false) {
                    $has_admin_club_exclusion = true;
                    break;
                }
            }
            if (!$has_admin_club_exclusion) {
                $where_clauses[] = 'u.role != ?';
                $params[] = 'admin_club';
            }
        }
    }
    
    // Búsqueda por ID, cédula, correo, nombre o username
    if ($search && !empty(trim($search))) {
        $search_term = trim($search);
        
        // Si es un número, buscar también por ID
        if (is_numeric($search_term)) {
            $where_clauses[] = '(u.id = ? OR u.cedula LIKE ? OR u.email LIKE ? OR u.nombre LIKE ? OR u.username LIKE ?)';
            $params[] = (int)$search_term;
            $params[] = '%' . $search_term . '%';
            $params[] = '%' . $search_term . '%';
            $params[] = '%' . $search_term . '%';
            $params[] = '%' . $search_term . '%';
        } else {
            // Búsqueda por texto (cédula, correo, nombre, username)
            $search_term_like = '%' . $search_term . '%';
            $where_clauses[] = '(u.cedula LIKE ? OR u.email LIKE ? OR u.nombre LIKE ? OR u.username LIKE ?)';
            $params[] = $search_term_like;
            $params[] = $search_term_like;
            $params[] = $search_term_like;
            $params[] = $search_term_like;
        }
    }
    
    $where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Contar total de registros
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u $where");
    $stmt->execute($params);
    $total_records = (int)$stmt->fetchColumn();
    
    // Crear objeto de paginación
    $pagination = new Pagination($total_records, $page, $per_page);
    
    // Obtener registros de la página actual
    $sql = "
        SELECT u.id, u.cedula, u.nombre, u.username, u.email, u.celular, u.role, u.status, u.created_at, u.updated_at, u.club_id, u.entidad, u.sexo,
               c.nombre as club_nombre
        FROM usuarios u 
        LEFT JOIN clubes c ON u.club_id = c.id 
        $where
        ORDER BY u.created_at DESC
        LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return [
        'data' => $stmt->fetchAll(),
        'pagination' => $pagination,
        'is_admin_list' => false
    ];
}

function getUser($id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, username, email, role, status, club_id, entidad, created_at, updated_at FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getUserRequests($page = 1, $per_page = 25) {
    $pdo = DB::pdo();
    
    // Contar total de registros
    $stmt = $pdo->query("SELECT COUNT(*) FROM user_requests");
    $total_records = (int)$stmt->fetchColumn();
    
    // Crear objeto de paginación
    $pagination = new Pagination($total_records, $page, $per_page);
    
    // Obtener registros de la página actual
    $stmt = $pdo->prepare("
        SELECT ur.*, c.nombre as club_nombre, u.username as approved_by_username
        FROM user_requests ur 
        LEFT JOIN clubes c ON ur.club_id = c.id 
        LEFT JOIN usuarios u ON ur.approved_by = u.id
        ORDER BY ur.created_at DESC
        LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}
    ");
    $stmt->execute();
    
    return [
        'data' => $stmt->fetchAll(),
        'pagination' => $pagination
    ];
}

/**
 * Obtiene opciones de la tabla entidad de forma resiliente a diferencias de nombres de columna.
 */
function getEntidadesOptions(): array {
    try {
        $pdo = DB::pdo();
        $columns = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) {
            return [];
        }

        $codeCandidates = ['codigo', 'cod_entidad', 'id', 'code'];
        $nameCandidates = ['nombre', 'descripcion', 'entidad', 'nombre_entidad'];

        $codeCol = null;
        $nameCol = null;
        foreach ($columns as $col) {
            $field = strtolower($col['Field'] ?? $col['field'] ?? '');
            if (!$codeCol && in_array($field, $codeCandidates, true)) {
                $codeCol = $col['Field'] ?? $col['field'];
            }
            if (!$nameCol && in_array($field, $nameCandidates, true)) {
                $nameCol = $col['Field'] ?? $col['field'];
            }
        }

        if (!$codeCol && isset($columns[0]['Field'])) {
            $codeCol = $columns[0]['Field'];
        }
        if (!$nameCol && isset($columns[1]['Field'])) {
            $nameCol = $columns[1]['Field'];
        }
        if (!$codeCol || !$nameCol) {
            return [];
        }

        $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("No se pudo obtener entidades: " . $e->getMessage());
        return [];
    }
}

// Obtener datos para la vista con paginaci�n
$current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 25;
$admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null;
$club_id = isset($_GET['club_id']) ? ($_GET['club_id'] === 'all' ? 'all' : (int)$_GET['club_id']) : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : null;
$role_filter = $_GET['role'] ?? null;
$users_result = null;
$requests_result = null;

if ($action === 'requests') {
    $requests_result = getUserRequests($current_page, $per_page);
} else {
    $users_result = getUsers($current_page, $per_page, $admin_id, $search, $club_id, $role_filter);
}

$users = $users_result ? $users_result['data'] : [];
$pagination = $users_result ? $users_result['pagination'] : ($requests_result ? $requests_result['pagination'] : null);
$user = null;
if ($action === 'edit' && $user_id > 0) {
    $user = getUser($user_id);
    if (!$user) {
        $_SESSION['errors'] = ['Usuario no encontrado'];
        header('Location: ?action=list');
        exit;
    }
}

// Obtener mensajes de sesi�n
$success_message = $_SESSION['success_message'] ?? null;
$errors = $_SESSION['errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];

// Limpiar mensajes de sesi�n
unset($_SESSION['success_message'], $_SESSION['errors'], $_SESSION['form_data']);

$entidades_options = getEntidadesOptions();

// Incluir la vista
include __DIR__ . '/users/list.php';



