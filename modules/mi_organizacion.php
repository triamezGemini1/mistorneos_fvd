<?php
/**
 * Gestión de Mi Organización
 * Permite al admin_club editar los datos de su organización
 */

if (!defined('APP_BOOTSTRAPPED')) { 
    require_once __DIR__ . '/../config/bootstrap.php'; 
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/file_upload.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
require_once __DIR__ . '/../lib/FvdConfig.php';

Auth::requireRole(['admin_club', 'admin_general', 'admin_torneo']);

$current_user = Auth::user();
$user_role = (string) ($current_user['role'] ?? '');
$is_admin_general = Auth::isAdminGeneral();
$is_admin_torneo = $user_role === 'admin_torneo';
/** Edición de datos de federación / logo / ámbito: no admin_torneo */
$can_edit_mi_organizacion = $is_admin_general || $user_role === 'admin_club';
$message = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$has_cod_org = false;
try {
    $has_cod_org = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_ref_expr = $has_cod_org ? "COALESCE(NULLIF(o.cod_org, 0), o.id)" : "o.id";

// Obtener la organización del usuario
$organizacion = null;
$organizacion_id = null;

$action_get = $_GET['action'] ?? '';

// Desactivar/Reactivar: manejado por index.php -> admin_org/organizacion/actions/

if ($is_admin_general) {
    // Admin general: por defecto la federación FVD (id 1); se puede pasar otro ?id= explícito
    $organizacion_id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    if ($action_get === 'new') {
        $organizacion_id = null;
        $organizacion = [];
    } elseif (($organizacion_id === null || $organizacion_id === 0) && $action_get !== 'activar') {
        $organizacion_id = FvdConfig::ORGANIZACION_ID;
    }

    if ($organizacion_id && $action_get !== 'new') {
        // id en URL es siempre PK: evitar (id OR cod_org) con el mismo parámetro (colisión entre orgs).
        $stmt = DB::pdo()->prepare("
            SELECT o.*, e.nombre as entidad_nombre, u.nombre as admin_nombre, u.email as admin_email
            FROM organizaciones o
            LEFT JOIN entidad e ON o.entidad = e.id
            LEFT JOIN usuarios u ON o.admin_user_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([(int) $organizacion_id]);
        $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} elseif (in_array($user_role, ['admin_club', 'admin_torneo'], true)) {
    // Admin club solo puede ver su organización
    $org_id_user = Auth::getUserOrganizacionId();
    if ($org_id_user) {
        $stmt = DB::pdo()->prepare("
            SELECT o.*, e.nombre as entidad_nombre
            FROM organizaciones o
            LEFT JOIN entidad e ON o.entidad = e.id
            WHERE o.id = ? AND o.estatus = 1
            LIMIT 1
        ");
        $stmt->execute([(int) $org_id_user]);
        $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$organizacion) {
        // Fallback histórico por admin_user_id
        $stmt = DB::pdo()->prepare("
            SELECT o.*, e.nombre as entidad_nombre
            FROM organizaciones o
            LEFT JOIN entidad e ON o.entidad = e.id
            WHERE o.admin_user_id = ? AND o.estatus = 1
            LIMIT 1
        ");
        $stmt->execute([$current_user['id']]);
        $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $organizacion_id = $organizacion['id'] ?? null;
}

// Actualizar etiqueta de ámbito nacional (fila `entidad`; organización FVD)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'actualizar_entidad_ambito'
    && $can_edit_mi_organizacion
) {
    try {
        $org_id = (int) ($_POST['organizacion_id'] ?? 0);
        if ($org_id !== FvdConfig::ORGANIZACION_ID) {
            throw new Exception('Esta acción solo aplica a la federación.');
        }
        $nombre = trim((string) ($_POST['entidad_ambito_nombre'] ?? ''));
        if ($nombre === '') {
            throw new Exception('Indique el nombre del ámbito territorial.');
        }
        $pdo = DB::pdo();
        $pdo
            ->prepare('INSERT INTO entidad (id, nombre, estado) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)')
            ->execute([FvdConfig::ENTIDAD_AMBITO_NACIONAL_ID, $nombre]);
        $pdo
            ->prepare('UPDATE organizaciones SET entidad = ?, updated_at = NOW() WHERE id = ?')
            ->execute([FvdConfig::ENTIDAD_AMBITO_NACIONAL_ID, FvdConfig::ORGANIZACION_ID]);
        header('Location: index.php?page=mi_organizacion&id=' . FvdConfig::ORGANIZACION_ID . '&success=' . urlencode('Ámbito territorial actualizado correctamente'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Procesar creación de organización (solo admin_general)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear' && $is_admin_general) {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $admin_user_id = (int)($_POST['admin_user_id'] ?? 0);
        if (empty($nombre)) {
            throw new Exception('El nombre de la organización es requerido');
        }
        if ($admin_user_id <= 0) {
            throw new Exception('Debe seleccionar el administrador de la organización');
        }
        $stmt = DB::pdo()->prepare("SELECT id FROM organizaciones WHERE admin_user_id = ?");
        $stmt->execute([$admin_user_id]);
        if ($stmt->fetch()) {
            throw new Exception('Ese usuario ya tiene una organización asignada');
        }
        $responsable = trim($_POST['responsable'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $entidad = (int)($_POST['entidad'] ?? 0);

        if ($has_cod_org) {
            $stmt = DB::pdo()->prepare("
                INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, cod_org, admin_user_id, estatus, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$nombre, $direccion, $responsable, $telefono, $email, $entidad, $entidad, $admin_user_id]);
        } else {
            $stmt = DB::pdo()->prepare("
                INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, admin_user_id, estatus, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$nombre, $direccion, $responsable, $telefono, $email, $entidad, $admin_user_id]);
        }
        header('Location: index.php?page=mi_organizacion&success=' . urlencode('Organización creada correctamente'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Procesar solo reactivación (sin búsqueda de usuario): estatus=1, se usa cuando se llega desde "Reactivar" (datos ya validados en afiliación)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activar_reactivar' && $is_admin_general) {
    $org_id_react = (int)($_POST['organizacion_id'] ?? 0);
    if ($org_id_react > 0) {
        try {
            $stmt = DB::pdo()->prepare("UPDATE organizaciones SET estatus = 1, updated_at = NOW() WHERE id = ? AND estatus = 0");
            $stmt->execute([$org_id_react]);
            if ($stmt->rowCount() > 0) {
                $return_extra = (($_GET['return_to'] ?? '') === 'organizaciones' && !empty($_GET['entidad_id'])) ? '&entidad_id=' . (int)$_GET['entidad_id'] : '';
                $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') . '/' : '';
                header('Location: ' . $base . 'index.php?page=organizaciones' . $return_extra . '&success=' . urlencode('Organización reactivada correctamente.'));
                exit;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    if (empty($error)) {
        $error = 'No se pudo reactivar la organización.';
    }
}

// Procesar activación de organización inactiva: asignar usuario existente o crear nuevo responsable (solo admin_general)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activar_guardar' && $is_admin_general) {
    try {
        $org_id = (int)($_POST['organizacion_id'] ?? 0);
        $admin_user_id = (int)($_POST['admin_user_id'] ?? 0);
        $crear_responsable = (int)($_POST['crear_responsable'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        $password_confirm = (string)($_POST['password_confirm'] ?? '');
        if ($org_id <= 0) {
            throw new Exception('Organización es requerida');
        }
        if (strlen($password) < 6) {
            throw new Exception('La contraseña debe tener al menos 6 caracteres');
        }
        if ($password !== $password_confirm) {
            throw new Exception('Las contraseñas no coinciden');
        }
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT id, entidad, estatus FROM organizaciones WHERE id = ?");
        $stmt->execute([$org_id]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$org || (int)$org['estatus'] !== 0) {
            throw new Exception('Organización no encontrada o ya está activa');
        }
        $entidad_org = (int)$org['entidad'];

        if ($crear_responsable === 1) {
            // Crear nuevo usuario y asignarlo como responsable
            $nombre = trim($_POST['nombre_responsable'] ?? '');
            $cedula = trim($_POST['cedula_responsable'] ?? '');
            $nacionalidad = strtoupper(trim($_POST['nacionalidad_responsable'] ?? 'V'));
            if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
                $nacionalidad = 'V';
            }
            $username = trim($_POST['username_responsable'] ?? '');
            $email = trim($_POST['email_responsable'] ?? '');
            $celular = trim($_POST['celular_responsable'] ?? '');
            if (empty($nombre) || empty($cedula) || empty($username)) {
                throw new Exception('Nombre, cédula y nombre de usuario son requeridos para el nuevo responsable');
            }
            $cedula_digitos = preg_replace('/\D/', '', $cedula);
            if ($cedula_digitos === '') {
                $cedula_digitos = $cedula;
            }
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe un usuario con ese nombre de usuario. Elija otro.');
            }
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? OR cedula = ? LIMIT 1");
            $stmt->execute([$cedula_digitos, $nacionalidad . $cedula_digitos]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe un usuario registrado con esa cédula.');
            }
            $password_hash = Security::hashPassword($password);
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0x4000, 0x4fff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
            $pdo->beginTransaction();
            try {
                $cols = "cedula, nombre, email, celular, username, password_hash, role, club_id, entidad, status";
                $placeholders = "?, ?, ?, ?, ?, ?, 'admin_club', NULL, ?, 0";
                $params = [$cedula_digitos, $nombre, $email ?: null, $celular ?: null, $username, $password_hash, $entidad_org];
                if (method_exists($pdo, 'query')) {
                    $chk = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'nacionalidad'");
                    if ($chk && $chk->rowCount() > 0) {
                        $cols .= ", nacionalidad";
                        $placeholders .= ", ?";
                        $params[] = $nacionalidad;
                    }
                }
                if (method_exists($pdo, 'query')) {
                    $chk = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'uuid'");
                    if ($chk && $chk->rowCount() > 0) {
                        $cols .= ", uuid";
                        $placeholders .= ", ?";
                        $params[] = $uuid;
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO usuarios ({$cols}) VALUES ({$placeholders})");
                $stmt->execute($params);
                $admin_user_id = (int) $pdo->lastInsertId();
                if ($admin_user_id <= 0) {
                    throw new Exception('Error al crear el usuario');
                }
                $pdo->prepare("UPDATE organizaciones SET estatus = 1, admin_user_id = ?, updated_at = NOW() WHERE id = ?")->execute([$admin_user_id, $org_id]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            $success_msg = 'Organización activada. Se creó el usuario responsable y se asignó la contraseña.';
        } else {
            // Usuario existente: asignar y actualizar contraseña
            if ($admin_user_id <= 0) {
                throw new Exception('Debe buscar y seleccionar un responsable por cédula o elegir un usuario de la lista.');
            }
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt->execute([$admin_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Usuario no encontrado');
            }
            $password_hash = Security::hashPassword($password);
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE organizaciones SET estatus = 1, admin_user_id = ?, updated_at = NOW() WHERE id = ?")->execute([$admin_user_id, $org_id]);
                $pdo->prepare("UPDATE usuarios SET role = 'admin_club', password_hash = ?, entidad = ? WHERE id = ?")->execute([$password_hash, $entidad_org, $admin_user_id]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            $success_msg = 'Organización activada. Usuario asignado y contraseña actualizada.';
        }

        $return_extra = '';
        if (($_GET['return_to'] ?? '') === 'organizaciones' && !empty($_GET['entidad_id'])) {
            $return_extra = '&entidad_id=' . (int)$_GET['entidad_id'];
        }
        $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') . '/' : '';
        header('Location: ' . $base . 'index.php?page=organizaciones' . $return_extra . '&success=' . urlencode($success_msg));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Procesar formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'actualizar') {
    try {
        $org_id = (int)($_POST['organizacion_id'] ?? 0);
        
        // Validar permisos
        if (!$can_edit_mi_organizacion) {
            throw new Exception('No tiene permisos para editar la organización');
        }
        if (!$is_admin_general) {
            if (!$organizacion || (int) $organizacion['id'] !== $org_id) {
                throw new Exception('No tiene permisos para editar esta organización');
            }
        }
        
        // Validar datos
        $nombre = trim($_POST['nombre'] ?? '');
        if (empty($nombre)) {
            throw new Exception('El nombre de la organización es requerido');
        }
        
        $responsable = trim($_POST['responsable'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        
        // Procesar logo si se subió
        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            // Validar tipo de archivo
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = mime_content_type($_FILES['logo']['tmp_name']);
            
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception('Tipo de archivo no permitido. Use JPG, PNG, GIF o WEBP.');
            }
            
            // Crear directorio si no existe
            $upload_dir = __DIR__ . '/../upload/organizaciones/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generar nombre único
            $extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $filename = 'org_' . $org_id . '_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $filepath)) {
                $logo_path = 'upload/organizaciones/' . $filename;
                
                // Eliminar logo anterior si existe
                $stmt = DB::pdo()->prepare("SELECT logo FROM organizaciones WHERE id = ?");
                $stmt->execute([$org_id]);
                $old_logo = $stmt->fetchColumn();
                if ($old_logo && file_exists(__DIR__ . '/../' . $old_logo)) {
                    @unlink(__DIR__ . '/../' . $old_logo);
                }
            } else {
                throw new Exception('Error al subir el archivo');
            }
        }
        
        // Actualizar en base de datos
        $sql = "UPDATE organizaciones SET nombre = ?, responsable = ?, telefono = ?, email = ?, direccion = ?, updated_at = NOW()";
        $params = [$nombre, $responsable, $telefono, $email, $direccion];
        
        if ($logo_path) {
            $sql .= ", logo = ?";
            $params[] = $logo_path;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $org_id;
        
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        
        // Redirigir con mensaje de éxito
        $redirect = 'index.php?page=mi_organizacion';
        if ($is_admin_general) {
            $redirect .= '&id=' . $org_id;
        }
        $redirect .= '&success=' . urlencode('Organización actualizada correctamente');
        header('Location: ' . $redirect);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Recargar datos después de actualización
if ($organizacion_id && empty($organizacion)) {
    $stmt = DB::pdo()->prepare("
        SELECT o.*, e.nombre as entidad_nombre, u.nombre as admin_nombre, u.email as admin_email
        FROM organizaciones o
        LEFT JOIN entidad e ON o.entidad = e.id
        LEFT JOIN usuarios u ON o.admin_user_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([(int) $organizacion_id]);
    $organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Si admin_general y no hay ID, mostrar lista de organizaciones
$lista_organizaciones = [];
$admin_sin_organizacion = [];
$entidades_options = [];
if ($is_admin_general) {
    if (!$organizacion_id && $action_get !== 'new') {
        $dashColsLista = OrganizacionDashboardStats::columnFlags(DB::pdo());
        $has_t_org_lista = $dashColsLista['has_tournament_cod_org'];
        $clubMatchMi = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg(DB::pdo(), 'c', 'o');
        $fvd_pk = (int) FvdConfig::ORGANIZACION_ID;
        $oEntSql = "(CASE WHEN o.id = {$fvd_pk} THEN 0 ELSE COALESCE(o.entidad,0) END)";
        $sub_clubes_lista = "(SELECT COUNT(DISTINCT c.id) FROM clubes c WHERE c.estatus = 1 "
            . "AND ({$oEntSql} = 0 OR COALESCE(c.entidad,0) = {$oEntSql}) "
            . "AND ({$clubMatchMi}))";
        if ($has_t_org_lista) {
            $sub_torneos_lista = "(SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE t.estatus = 1 "
                . "AND ({$oEntSql} = 0 OR COALESCE(t.entidad,0) = {$oEntSql}) "
                . "AND ((t.cod_org IS NOT NULL AND t.cod_org > 0 AND t.cod_org = o.id) OR ((t.cod_org IS NULL OR t.cod_org = 0) AND "
                . ($has_cod_org ? "(t.club_responsable = COALESCE(NULLIF(o.cod_org, 0), o.id) OR t.club_responsable = o.id)" : "t.club_responsable = o.id")
                . ")))";
        } else {
            $sub_torneos_lista = "(SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE "
                . ($has_cod_org ? "(t.club_responsable = COALESCE(NULLIF(o.cod_org, 0), o.id) OR t.club_responsable = o.id)" : "t.club_responsable = o.id")
                . " AND t.estatus = 1 AND ({$oEntSql} = 0 OR COALESCE(t.entidad,0) = {$oEntSql}))";
        }
        $stmt = DB::pdo()->query("
            SELECT o.*, e.nombre as entidad_nombre, u.nombre as admin_nombre,
                   {$sub_clubes_lista} as total_clubes,
                   {$sub_torneos_lista} as total_torneos
            FROM organizaciones o
            LEFT JOIN entidad e ON o.entidad = e.id
            LEFT JOIN usuarios u ON o.admin_user_id = u.id
            ORDER BY o.estatus DESC, COALESCE(e.nombre, '') ASC, o.nombre ASC
        ");
        $lista_organizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($action_get === 'new' || $action_get === 'activar') {
        $stmt = DB::pdo()->query("
            SELECT u.id, u.nombre, u.username, u.email
            FROM usuarios u
            LEFT JOIN organizaciones o ON o.admin_user_id = u.id AND o.estatus = 1
            WHERE u.role = 'admin_club' AND u.status = 0 AND o.id IS NULL
            ORDER BY u.nombre ASC
        ");
        $admin_sin_organizacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
        try {
            $cols = DB::pdo()->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
            $codeCol = 'id';
            $nameCol = 'nombre';
            foreach ($cols as $col) {
                $f = strtolower($col['Field'] ?? '');
                if ($f === 'codigo' || $f === 'id') $codeCol = $col['Field'];
                if ($f === 'nombre') $nameCol = $col['Field'];
            }
            $stmt = DB::pdo()->query("SELECT {$codeCol} AS id, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
            $entidades_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $entidades_options = [];
        }
    }
}

// Estadísticas globales (compatibles con BD sin usuarios.organizacion_id; torneos priorizan tournaments.cod_org)
$stats = [
    'clubes' => 0,
    'torneos' => 0,
    'torneos_activos' => 0,
    'afiliados' => 0,
    'usuarios' => 0,
    'inscripciones' => 0,
];
if ($organizacion) {
    $snap = OrganizacionDashboardStats::snapshot(DB::pdo(), $organizacion, $has_cod_org);
    $stats = $snap['stats'];
}
?>

<?php
$url_inicio = class_exists('AppHelpers') ? AppHelpers::dashboard('home') : 'index.php?page=home';
?>
<div class="container-fluid py-4 fvd-app-page fvd-app-page--glass fvd-org-page">
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h1 class="h3 mb-0">
                    <i class="fas fa-building text-primary me-2"></i>
                    <?= $is_admin_general && !$organizacion ? 'Gestión de Organizaciones' : 'Mi Organización' ?>
                </h1>
                <?php if (!$is_admin_general && $organizacion): ?>
                <a href="<?= htmlspecialchars($url_inicio) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Regresar al inicio
                </a>
                <?php endif; ?>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($url_inicio) ?>">Inicio</a></li>
                    <?php if ($is_admin_general && $organizacion): ?>
                        <li class="breadcrumb-item"><a href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::dashboard('mi_organizacion') : 'index.php?page=mi_organizacion') ?>">Organizaciones</a></li>
                        <li class="breadcrumb-item active"><?= htmlspecialchars($organizacion['nombre']) ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active">Mi Organización</li>
                    <?php endif; ?>
                </ol>
            </nav>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($is_admin_general && $action_get === 'activar' && $organizacion && (int)($organizacion['estatus'] ?? 1) === 0): ?>
        <?php include __DIR__ . '/admin_org/organizacion/views/mi_organizacion_form_activar.php'; ?>
    <?php elseif ($is_admin_general && $action_get === 'new'): ?>
        <?php include __DIR__ . '/admin_org/organizacion/views/mi_organizacion_form_nueva.php'; ?>
    <?php elseif ($is_admin_general && !$organizacion): ?>
        <?php include __DIR__ . '/admin_org/organizacion/views/mi_organizacion_lista.php'; ?>
    <?php elseif ($organizacion): ?>
        <?php include __DIR__ . '/admin_org/organizacion/views/mi_organizacion_form_editar.php'; ?>
    <?php else: ?>
        <?php include __DIR__ . '/admin_org/organizacion/views/mi_organizacion_sin_org.php'; ?>
    <?php endif; ?>
</div>
