<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/FvdAdminGate.php';

FvdAdminGate::rejectPublicScriptIfDisabled();

$pdo = DB::pdo();
$base_url = app_base_url();
$app_url = rtrim($_ENV['APP_URL'] ?? $base_url, '/');
$url_solicitudes = $app_url . '/index.php?page=affiliate_requests&filter=pendiente';
$logged_user = Auth::user();
$logged_user_id = (int)($logged_user['id'] ?? 0);

/**
 * Verifica si un usuario ya es admin de una organización activa.
 */
function usuarioTieneOrganizacionActiva(PDO $pdo, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }
    if (class_exists('FvdConfig', false) && FvdConfig::isOrganizacionOperativa(FvdConfig::ORGANIZACION_ID)) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM organizaciones WHERE id = ? AND admin_user_id = ? AND estatus = 1 LIMIT 1');
            $stmt->execute([FvdConfig::ORGANIZACION_ID, $userId]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                return true;
            }
        } catch (Exception $e) {
            // seguir con comprobación legacy
        }
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 LIMIT 1");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Crea notificación web para admin_general sobre solicitudes de afiliación.
 * Incluye admins con status 0/1/'approved'/NULL por compatibilidad histórica.
 */
function notificarAdminsSolicitudAfiliacion(PDO $pdo, string $baseUrl, string $nombre, string $clubNombre, string $cedula = '', bool $recordatorio = false): void {
    try {
        $stmt_admin = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE role = 'admin_general'
              AND (status IN (0, 1, '0', '1', 'approved') OR status IS NULL)
        ");
        $stmt_admin->execute();
        $admins = $stmt_admin->fetchAll(PDO::FETCH_ASSOC);
        if (empty($admins)) {
            // Fallback: notificar a todos los admin_general aunque el status no cumpla el filtro histórico.
            $stmt_admin = $pdo->prepare("SELECT id FROM usuarios WHERE role = 'admin_general'");
            $stmt_admin->execute();
            $admins = $stmt_admin->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($admins)) {
            return;
        }

        $app_url = rtrim($_ENV['APP_URL'] ?? $baseUrl, '/');
        $url_solicitudes = $app_url . '/index.php?page=affiliate_requests&filter=pendiente';
        $prefijo = $recordatorio ? 'Recordatorio: ' : 'Nueva ';
        $mensaje = $prefijo . "solicitud de afiliación de " . ($nombre ?: 'N/A') . " (" . ($clubNombre ?: '') . "). Revisar en Solicitudes de Afiliación.";
        $has_datos_json = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'")->rowCount() > 0;

        foreach ($admins as $admin) {
            $uid = (int)($admin['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ($has_datos_json) {
                $pdo->prepare("INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino, datos_json) VALUES (?, 'web', ?, ?, ?)")
                    ->execute([$uid, $mensaje, $url_solicitudes, json_encode([
                        'tipo' => 'solicitud_afiliacion',
                        'nombre' => $nombre ?: '',
                        'club' => $clubNombre ?: '',
                        'cedula' => $cedula ?: '',
                        'recordatorio' => $recordatorio ? 1 : 0,
                    ])]);
            } else {
                $pdo->prepare("INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino) VALUES (?, 'web', ?, ?)")
                    ->execute([$uid, $mensaje, $url_solicitudes]);
            }
        }
    } catch (Exception $e) {
        error_log("Error notificando solicitud afiliación a admin: " . $e->getMessage());
    }
}

/**
 * Carga opciones de entidad (codigo, nombre) de forma resiliente.
 */
function loadEntidadesOptions(): array {
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
        return [];
    }
}

$entidades_options = loadEntidadesOptions();

/**
 * Carga las organizaciones disponibles para el selector de asociaciones en la solicitud de afiliación.
 * Solo incluye organizaciones activas (estatus=1) que aún no tienen responsable asignado (admin_user_id NULL o 0).
 * La tabla organizaciones debe existir y estar poblada por el administrador para que aparezcan opciones.
 */
function cargarOrganizacionesParaSelector(): array {
    try {
        $pdo = DB::pdo();
        // Comprobar existencia de la tabla con fetch (rowCount no es fiable en SELECT con algunos drivers)
        $stmtTable = $pdo->query("SHOW TABLES LIKE 'organizaciones'");
        $tableExists = $stmtTable && $stmtTable->fetch() !== false;
        if (!$tableExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS organizaciones (
                    id INT NOT NULL AUTO_INCREMENT,
                    nombre VARCHAR(255) NOT NULL,
                    direccion VARCHAR(255) NULL,
                    responsable VARCHAR(100) NULL,
                    telefono VARCHAR(50) NULL,
                    email VARCHAR(100) NULL,
                    entidad INT NOT NULL DEFAULT 0,
                    admin_user_id INT NULL,
                    logo VARCHAR(255) NULL,
                    estatus TINYINT NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_estatus (estatus),
                    KEY idx_admin_user_id (admin_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        // Intentar consulta con condición de responsable sin asignar (admin_user_id NULL o 0)
        try {
            $stmt = $pdo->query("
                SELECT id, nombre, COALESCE(entidad, 0) AS entidad 
                FROM organizaciones 
                WHERE estatus = 1 AND (admin_user_id IS NULL OR admin_user_id = 0) 
                ORDER BY nombre ASC
            ");
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return is_array($rows) ? $rows : [];
            }
        } catch (Exception $e) {
            // Si falla (ej. columna admin_user_id no existe o tipo distinto), intentar asegurar columna y repetir
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM organizaciones")->fetchAll(PDO::FETCH_ASSOC);
                $hasAdmin = false;
                foreach ($cols as $c) {
                    if (strtolower($c['Field'] ?? '') === 'admin_user_id') {
                        $hasAdmin = true;
                        break;
                    }
                }
                if (!$hasAdmin) {
                    $pdo->exec("ALTER TABLE organizaciones ADD COLUMN admin_user_id INT NULL DEFAULT NULL AFTER entidad");
                }
                $stmt = $pdo->query("
                    SELECT id, nombre, COALESCE(entidad, 0) AS entidad 
                    FROM organizaciones 
                    WHERE estatus = 1 AND (admin_user_id IS NULL OR admin_user_id = 0) 
                    ORDER BY nombre ASC
                ");
                if ($stmt) {
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    return is_array($rows) ? $rows : [];
                }
            } catch (Exception $e2) {
                error_log("affiliate_request: fallback organizaciones: " . $e2->getMessage());
            }
            // Último recurso: todas las activas (el backend validará al enviar)
            $stmt = $pdo->query("SELECT id, nombre, COALESCE(entidad, 0) AS entidad FROM organizaciones WHERE estatus = 1 ORDER BY nombre ASC");
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return is_array($rows) ? $rows : [];
            }
        }
        return [];
    } catch (Exception $e) {
        error_log("affiliate_request: error cargando organizaciones para selector: " . $e->getMessage());
        return [];
    }
}

// Crear tabla si no existe
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_afiliacion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nacionalidad CHAR(1) DEFAULT 'V',
            cedula VARCHAR(20) NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            email VARCHAR(150),
            celular VARCHAR(20),
            fechnac DATE,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            entidad INT NULL,
            rif VARCHAR(20),
            club_nombre VARCHAR(150) NOT NULL,
            club_ubicacion VARCHAR(255),
            motivo TEXT,
            estatus ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
            notas_admin TEXT,
            revisado_por INT,
            revisado_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_cedula_pendiente (cedula, estatus)
        )
    ");
} catch (Exception $e) {
    // Tabla ya existe
}

// Asegurar columnas en tablas existentes
try {
    $cols = $pdo->query("SHOW COLUMNS FROM solicitudes_afiliacion")->fetchAll(PDO::FETCH_ASSOC);
    $has_entidad = false;
    $has_rif = false;
    foreach ($cols as $col) {
        $field = strtolower($col['Field'] ?? $col['field'] ?? '');
        if ($field === 'entidad') {
            $has_entidad = true;
        }
        if ($field === 'rif') {
            $has_rif = true;
        }
    }
    if (!$has_entidad) {
        $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN entidad INT NULL");
    }
    if (!$has_rif) {
        $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN rif VARCHAR(20) NULL");
    }
    $org_fields = ['org_direccion', 'org_responsable', 'org_telefono', 'org_email'];
    foreach ($org_fields as $f) {
        $has = false;
        foreach ($cols as $col) {
            if (strtolower($col['Field'] ?? $col['field'] ?? '') === $f) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            if ($f === 'org_direccion') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_direccion VARCHAR(255) NULL AFTER club_ubicacion");
            } elseif ($f === 'org_responsable') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_responsable VARCHAR(100) NULL AFTER org_direccion");
            } elseif ($f === 'org_telefono') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_telefono VARCHAR(50) NULL AFTER org_responsable");
            } elseif ($f === 'org_email') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_email VARCHAR(100) NULL AFTER org_telefono");
            }
        }
    }
    $has_user_id = false;
    $has_organizacion_id = false;
    $has_tipo_solicitud = false;
    foreach ($cols as $col) {
        $f = strtolower($col['Field'] ?? $col['field'] ?? '');
        if ($f === 'user_id') $has_user_id = true;
        if ($f === 'organizacion_id') $has_organizacion_id = true;
        if ($f === 'tipo_solicitud') $has_tipo_solicitud = true;
    }
    if (!$has_user_id) {
        $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN user_id INT NULL AFTER id");
    }
    if (!$has_organizacion_id) {
        $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN organizacion_id INT NULL AFTER user_id");
    }
    if (!$has_tipo_solicitud) {
        $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN tipo_solicitud VARCHAR(20) NULL DEFAULT 'particular' AFTER organizacion_id");
    }
} catch (Exception $e) {
    // Ignorar errores de alteración
}

$error = '';
$success = '';
$tipo_solicitud = isset($_GET['tipo']) && in_array($_GET['tipo'], ['asociacion', 'particular'], true) ? $_GET['tipo'] : null;
$bloqueo_admin_club = false;

if ($logged_user_id > 0) {
    $rol_logueado = (string)($logged_user['role'] ?? '');
    if ($rol_logueado === 'admin_club' && usuarioTieneOrganizacionActiva($pdo, $logged_user_id)) {
        $bloqueo_admin_club = true;
        $error = 'Ya está registrado con una organización y no puede crear otra.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validate();
    
    $tipo_post = isset($_POST['tipo_solicitud']) && in_array($_POST['tipo_solicitud'], ['asociacion', 'particular'], true) ? $_POST['tipo_solicitud'] : 'particular';
    // Asociación: el selector envía entidad (codigo); resolver a organizacion_id (buscar o crear organización por entidad)
    $organizacion_id_post = null;
    if ($tipo_post === 'asociacion') {
        $asociacion_entidad = trim((string)($_POST['asociacion_entidad'] ?? ''));
        if ($asociacion_entidad !== '') {
            $entidad_codigo = is_numeric($asociacion_entidad) ? (int)$asociacion_entidad : $asociacion_entidad;
            $entidad_nombre = null;
            foreach (loadEntidadesOptions() as $ent) {
                $cod = $ent['codigo'] ?? $ent['id'] ?? null;
                if ($cod === $entidad_codigo || (string)$cod === (string)$entidad_codigo) {
                    $entidad_nombre = $ent['nombre'] ?? '';
                    break;
                }
            }
            if ($entidad_nombre === null) {
                try {
                    $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
                    $codeCol = 'codigo';
                    $nameCol = 'nombre';
                    foreach ($cols as $c) {
                        $f = strtolower($c['Field'] ?? '');
                        if (in_array($f, ['codigo', 'id', 'code'], true)) $codeCol = $c['Field'];
                        if (in_array($f, ['nombre', 'descripcion'], true)) $nameCol = $c['Field'];
                    }
                    $stmt = $pdo->prepare("SELECT {$nameCol} AS nombre FROM entidad WHERE {$codeCol} = ? LIMIT 1");
                    $stmt->execute([$entidad_codigo]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $entidad_nombre = $row['nombre'] ?? '';
                } catch (Exception $e) {}
            }
            $entidad_nombre = $entidad_nombre ?: 'Asociación ' . $entidad_codigo;
            try {
                $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE estatus = 1 AND (admin_user_id IS NULL OR admin_user_id = 0) AND (entidad = ? OR entidad = ?) LIMIT 1");
                $stmt->execute([$entidad_codigo, (int)$entidad_codigo]);
                $org = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($org) {
                    $organizacion_id_post = (int)$org['id'];
                } else {
                    $entidad_int = (int) $entidad_codigo;
                    try {
                        $hasCodOrg = false;
                        $hasTipoOrg = false;
                        try {
                            $hasCodOrg = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
                        } catch (Throwable $ignored) {
                            $hasCodOrg = false;
                        }
                        try {
                            $hasTipoOrg = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'tipo_org'")->fetch(PDO::FETCH_ASSOC);
                        } catch (Throwable $ignored) {
                            $hasTipoOrg = false;
                        }
                        if ($hasCodOrg) {
                            if ($hasTipoOrg) {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, tipo_org, cod_org, admin_user_id, estatus, created_at, updated_at) VALUES (?, ?, 0, ?, NULL, 1, NOW(), NOW())");
                                $ins->execute([$entidad_nombre, $entidad_int, $entidad_int]);
                            } else {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, cod_org, admin_user_id, estatus, created_at, updated_at) VALUES (?, ?, ?, NULL, 1, NOW(), NOW())");
                                $ins->execute([$entidad_nombre, $entidad_int, $entidad_int]);
                            }
                        } else {
                            if ($hasTipoOrg) {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, tipo_org, admin_user_id, estatus, created_at, updated_at) VALUES (?, ?, 0, NULL, 1, NOW(), NOW())");
                                $ins->execute([$entidad_nombre, $entidad_int]);
                            } else {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, admin_user_id, estatus, created_at, updated_at) VALUES (?, ?, NULL, 1, NOW(), NOW())");
                                $ins->execute([$entidad_nombre, $entidad_int]);
                            }
                        }
                        $organizacion_id_post = (int) $pdo->lastInsertId();
                    } catch (Exception $e) {
                        if (!isset($hasCodOrg)) {
                            $hasCodOrg = false;
                        }
                        if (!isset($hasTipoOrg)) {
                            $hasTipoOrg = false;
                        }
                        if ($hasCodOrg) {
                            if ($hasTipoOrg) {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, tipo_org, cod_org, admin_user_id, estatus) VALUES (?, ?, 0, ?, 0, 1)");
                                $ins->execute([$entidad_nombre, $entidad_int, $entidad_int]);
                            } else {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, cod_org, admin_user_id, estatus) VALUES (?, ?, ?, 0, 1)");
                                $ins->execute([$entidad_nombre, $entidad_int, $entidad_int]);
                            }
                        } else {
                            if ($hasTipoOrg) {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, tipo_org, admin_user_id, estatus) VALUES (?, ?, 0, 0, 1)");
                                $ins->execute([$entidad_nombre, $entidad_int]);
                            } else {
                                $ins = $pdo->prepare("INSERT INTO organizaciones (nombre, entidad, admin_user_id, estatus) VALUES (?, ?, 0, 1)");
                                $ins->execute([$entidad_nombre, $entidad_int]);
                            }
                        }
                        $organizacion_id_post = (int) $pdo->lastInsertId();
                    }
                }
            } catch (Exception $e) {
                error_log("affiliate_request: resolver entidad a organizacion: " . $e->getMessage());
            }
        }
    }
    
    // Nacionalidad solo puede venir de la consulta por cédula (BD externa); no hay select, es obligatorio que venga cargada
    $nacionalidad = strtoupper(trim((string)($_POST['nacionalidad'] ?? '')));
    if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
        $nacionalidad = '';
    }
    // Cédula solo numérica (nunca concatenar con nacionalidad)
    $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $fechnac = trim($_POST['fechnac'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim((string)($_POST['password'] ?? ''));
    $password_confirm = trim((string)($_POST['password_confirm'] ?? ''));
    $entidad = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
    $rif = trim($_POST['rif'] ?? '');
    $club_nombre = trim($_POST['club_nombre'] ?? '');
    $club_ubicacion = trim($_POST['club_ubicacion'] ?? '');
    $org_direccion = trim($_POST['org_direccion'] ?? '');
    $org_responsable = trim($_POST['org_responsable'] ?? '');
    $org_telefono = trim($_POST['org_telefono'] ?? '');
    $org_email = trim($_POST['org_email'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');
    
    // Buscar si ya existe usuario por cédula (registrado) - probar con y sin prefijo V/E/J/P
    $cedula_externa = preg_replace('/^[VEJP]/i', '', $cedula);
    $cedula_externa = $cedula_externa ?: $cedula;
    $usuario_existente = null;
    try {
        if ($logged_user_id > 0) {
            $stmt = $pdo->prepare("SELECT id, nombre, username, email, celular, fechnac, cedula, role FROM usuarios WHERE id = ? LIMIT 1");
            $stmt->execute([$logged_user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, nombre, username, email, celular, fechnac, cedula, role FROM usuarios WHERE cedula = ? OR cedula = ?");
            $stmt->execute([$cedula, $cedula_externa]);
        }
        $usuario_existente = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $es_usuario_registrado = !empty($usuario_existente);

    // Validaciones: nacionalidad debe venir de la consulta por cédula (campo oculto rellenado por la API)
    if ($bloqueo_admin_club) {
        $error = 'Ya está registrado con una organización y no puede crear otra.';
    } elseif ($nacionalidad === '') {
        $error = 'Debe buscar su cédula para cargar sus datos. La nacionalidad se obtiene de la consulta al sistema.';
    } elseif (empty($cedula) || empty($nombre)) {
        $error = 'Todos los campos marcados con * son requeridos';
    } elseif ($tipo_post === 'asociacion') {
        $org = null;
        if ($organizacion_id_post <= 0) {
            $error = 'Debe seleccionar una asociación';
        } else {
            $stmt = $pdo->prepare("SELECT id, nombre, admin_user_id, entidad FROM organizaciones WHERE id = ? AND estatus = 1");
            $stmt->execute([$organizacion_id_post]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$org) {
                $error = 'La asociación seleccionada no existe o está inactiva';
            } elseif (!empty($org['admin_user_id']) && (int)$org['admin_user_id'] !== 0) {
                $error = 'La asociación seleccionada ya está asignada a otro responsable. Elija otra asociación.';
            }
        }
        if (empty($error) && $org) {
            if (empty($club_nombre)) $club_nombre = $org['nombre'] ?? '';
            if ($entidad <= 0) $entidad = (int)($org['entidad'] ?? 0);
        }
    } elseif ($tipo_post === 'particular' && empty($club_nombre)) {
        $error = 'El nombre de la organización es requerido';
    } elseif (!$es_usuario_registrado && (empty($username) || empty($password))) {
        $error = 'Nombre de usuario y contraseña son requeridos para nuevos usuarios';
    } elseif ($es_usuario_registrado && trim($password) !== '' && (strlen($password) < 6 || $password !== $password_confirm)) {
        $error = 'Si actualiza la contraseña debe tener al menos 6 caracteres y coincidir con la confirmación';
    } elseif ($tipo_post === 'particular' && $entidad <= 0) {
        $error = 'Debe seleccionar la entidad';
    } elseif (!empty($rif) && strlen($rif) < 6) {
        $error = 'El RIF no es válido';
    } elseif (!$es_usuario_registrado && strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } elseif (!$es_usuario_registrado && $password !== $password_confirm) {
        $error = 'Las contraseñas no coinciden';
    } elseif (!$es_usuario_registrado) {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'El nombre de usuario ya está en uso';
        }
    } elseif ($es_usuario_registrado && trim($username) !== '' && $username !== ($usuario_existente['username'] ?? '')) {
        // Usuario existente que quiere cambiar username: no debe estar en uso por otro
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
        $stmt->execute([$username, $usuario_existente['id']]);
        if ($stmt->fetch()) {
            $error = 'El nombre de usuario ya está en uso por otra cuenta';
        }
    } elseif ($es_usuario_registrado && (($usuario_existente['role'] ?? '') === 'admin_club') && usuarioTieneOrganizacionActiva($pdo, (int)$usuario_existente['id'])) {
        // Regla de negocio: un admin de organización con organización activa no puede registrar otra
        $error = 'Ya está registrado con una organización y no puede crear otra.';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido';
    }

    if (empty($error)) {
        try {
            // No permitir solicitud pendiente duplicada (por cédula o por user_id si ya está registrado)
            $sql_dup = "SELECT id FROM solicitudes_afiliacion WHERE estatus = 'pendiente' AND (cedula = ?" . ($es_usuario_registrado ? " OR user_id = ?" : "") . ")";
            $params_dup = $es_usuario_registrado ? [$cedula, $usuario_existente['id']] : [$cedula];
            $stmt = $pdo->prepare($sql_dup);
            $stmt->execute($params_dup);
            if ($stmt->fetch()) {
                $error = 'Ya tienes una solicitud de afiliación pendiente de revisión';
                // Si ya existe pendiente, enviar recordatorio al admin_general para asegurar visibilidad.
                notificarAdminsSolicitudAfiliacion($pdo, $base_url, $nombre, $club_nombre, $cedula, true);
            }
        } catch (Exception $e) {}

        if (empty($error)) {
            try {
                $user_id_solicitud = null;
                $password_hash = null;
                $username_solicitud = $username;

                if ($es_usuario_registrado) {
                    // Usuario ya registrado: forzar actualización de usuario y contraseña en la tabla usuarios con los datos del formulario.
                    $user_id_solicitud = (int) $usuario_existente['id'];
                    $username_solicitud = trim($username) !== '' ? $username : $usuario_existente['username'];
                    $nueva_password = (trim($password) !== '' && strlen($password) >= 6);
                    $password_hash = $nueva_password ? password_hash($password, PASSWORD_DEFAULT) : password_hash('', PASSWORD_DEFAULT);
                    // Actualizar credenciales en usuarios: siempre username si cambió; password solo si ingresó una nueva
                    if ($nueva_password) {
                        $pdo->prepare("UPDATE usuarios SET username = ?, password_hash = ? WHERE id = ?")->execute([$username_solicitud, $password_hash, $user_id_solicitud]);
                    } else {
                        $pdo->prepare("UPDATE usuarios SET username = ? WHERE id = ?")->execute([$username_solicitud, $user_id_solicitud]);
                    }
                } else {
                    // Usuario nuevo: crear registro en usuarios (pendiente) con nacionalidad desde persona
                    $nacionalidad_usuarios = in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? $nacionalidad : 'V';
                    $password_hash = (trim($password) !== '') ? password_hash($password, PASSWORD_DEFAULT) : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (cedula, nacionalidad, nombre, email, celular, fechnac, username, password_hash, role, club_id, entidad, status, uuid, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin_club', NULL, ?, 1, ?, NOW())
                    ");
                    $stmt->execute([
                        $cedula,
                        $nacionalidad_usuarios,
                        $nombre,
                        $email ?: null,
                        $celular ?: null,
                        $fechnac ?: null,
                        $username,
                        $password_hash,
                        $entidad,
                        $uuid
                    ]);
                    $user_id_solicitud = (int) $pdo->lastInsertId();
                }

                // Asociación: verificar de nuevo que la organización siga sin asignar (evitar condición de carrera)
                if ($tipo_post === 'asociacion' && $organizacion_id_post > 0) {
                    $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE id = ? AND estatus = 1 AND (admin_user_id IS NULL OR admin_user_id = 0)");
                    $stmt->execute([$organizacion_id_post]);
                    if (!$stmt->fetch()) {
                        $error = 'La asociación seleccionada ya está asignada a otro responsable. Elija otra asociación.';
                    }
                }

                if (empty($error)) {
                // La columna nacionalidad no acepta NULL ni vacío: asegurar valor siempre
                $nacionalidad_db = in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? $nacionalidad : 'V';

                $stmt = $pdo->prepare("
                    INSERT INTO solicitudes_afiliacion 
                    (user_id, organizacion_id, tipo_solicitud, nacionalidad, cedula, nombre, email, celular, fechnac, username, password_hash, entidad, rif, club_nombre, club_ubicacion, org_direccion, org_responsable, org_telefono, org_email, motivo, estatus, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())
                ");
                $stmt->execute([
                    $user_id_solicitud ?: null,
                    $tipo_post === 'asociacion' && $organizacion_id_post > 0 ? $organizacion_id_post : null,
                    $tipo_post,
                    $nacionalidad_db,
                    $cedula,
                    $nombre,
                    $email ?: null,
                    $celular ?: null,
                    $fechnac ?: null,
                    $username_solicitud,
                    $password_hash,
                    $entidad,
                    $rif ?: null,
                    $club_nombre,
                    $club_ubicacion ?: null,
                    $org_direccion ?: null,
                    $org_responsable ?: null,
                    $org_telefono ?: null,
                    $org_email ?: null,
                    $motivo ?: null
                ]);

                if ($tipo_post === 'asociacion') {
                    $success = 'Solicitud de afiliación como asociación enviada. Será revisada por el administrador; al aprobarse quedarás asignado como responsable de la asociación seleccionada.';
                } else {
                    $success = $es_usuario_registrado
                        ? 'Se creó una solicitud pendiente para registrar tu organización. Debe ser autorizada por el administrador general; al aprobarse se te asignará la nueva organización como administrador.'
                        : '¡Solicitud enviada! Se ha creado tu usuario en estado pendiente. Debe ser autorizada por el administrador general; al aprobarse podrás acceder y se creará tu organización.';
                }
                
                // Notificar a admin_general sobre la nueva solicitud (campanita web + email)
                try {
                    notificarAdminsSolicitudAfiliacion($pdo, $base_url, $nombre, $club_nombre, $cedula, false);
                    $stmt_admin = $pdo->prepare("
                        SELECT id
                        FROM usuarios
                        WHERE role = 'admin_general'
                          AND (status IN (0, 1, '0', '1', 'approved') OR status IS NULL)
                    ");
                    $stmt_admin->execute();
                    $admins = $stmt_admin->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($admins)) {
                        $stmt_admin = $pdo->prepare("SELECT id FROM usuarios WHERE role = 'admin_general'");
                        $stmt_admin->execute();
                        $admins = $stmt_admin->fetchAll(PDO::FETCH_ASSOC);
                    }
                    // Email a admin_general (siempre intentar envío por correo, no solo web)
                    if (!empty($admins)) {
                        require_once __DIR__ . '/../lib/NotificationSender.php';
                        $stmt_mail = $pdo->prepare("SELECT id, nombre, email FROM usuarios WHERE role = 'admin_general' AND email IS NOT NULL AND email != '' LIMIT 5");
                        $stmt_mail->execute();
                        $admins_mail = $stmt_mail->fetchAll(PDO::FETCH_ASSOC);
                        $asunto = 'Nueva solicitud de afiliación - ' . ($club_nombre ?? 'Sin nombre');
                        $cuerpo = "Se ha recibido una nueva solicitud de afiliación:\n\n";
                        $cuerpo .= "Nombre: " . ($nombre ?? '') . "\n";
                        $cuerpo .= "Organización/Club: " . ($club_nombre ?? '') . "\n";
                        $cuerpo .= "Email: " . ($email ?? '') . "\n";
                        $cuerpo .= "Celular: " . ($celular ?? '') . "\n\n";
                        $cuerpo .= "Revisar: " . $url_solicitudes;
                        foreach ($admins_mail as $a) {
                            $r = NotificationSender::sendEmail($a['email'], $asunto, $cuerpo, $a['nombre'] ?? '');
                            if ($r['ok']) break;
                            error_log("Email afiliación a admin {$a['email']}: " . ($r['error'] ?? ''));
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error notificando solicitud afiliación a admin: " . $e->getMessage());
                }
                
                $_POST = [];
                $nacionalidad = 'V';
                $cedula = '';
                $nombre = '';
                $email = '';
                $celular = '';
                $fechnac = '';
                $username = '';
                $password = '';
                $password_confirm = '';
                $entidad = 0;
                $rif = '';
                $club_nombre = '';
                $club_ubicacion = '';
                $org_direccion = '';
                $org_responsable = '';
                $org_telefono = '';
                $org_email = '';
                $motivo = '';
                }
            } catch (Exception $e) {
                $error = 'Error al enviar la solicitud: ' . $e->getMessage();
                error_log("Affiliate request error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#c53030">
    <title>Solicitud de Afiliación - La Estación del Dominó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #c53030 0%, #9b2c2c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }
        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .register-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        .register-body {
            padding: 2rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #c53030;
            box-shadow: 0 0 0 0.2rem rgba(197, 48, 48, 0.25);
        }
        .btn-register {
            background: linear-gradient(135deg, #c53030 0%, #9b2c2c 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        .btn-register:hover {
            background: linear-gradient(135deg, #9b2c2c 0%, #742a2a 100%);
        }
        .info-box {
            background: #fef5e7;
            border-left: 4px solid #ecc94b;
            padding: 1rem;
            border-radius: 0 8px 8px 0;
            margin-bottom: 1.5rem;
        }
        .search-status {
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
        .section-title {
            color: #c53030;
            font-weight: 600;
            border-bottom: 2px solid #fed7d7;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 80%;">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="register-card">
                    <div class="register-header">
                        <i class="fas fa-building"></i>
                        <h3 class="mb-1">Solicitud de Afiliación</h3>
                        <p class="mb-0 opacity-75">Únete como organizador de torneos</p>
                    </div>
                    <div class="register-body">
                        <?php if ($tipo_solicitud === null && !$success): ?>
                            <!-- Pantalla de elección: Asociación o Particular -->
                            <div class="info-box">
                                <i class="fas fa-info-circle text-warning me-2"></i>
                                <strong>Elija el tipo de solicitud:</strong> Si su asociación ya está registrada en el sistema, selecciónela para quedar asignado como responsable. Si es un particular, use el formulario estándar para crear una nueva organización.
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <a href="?tipo=asociacion" class="text-decoration-none">
                                        <div class="card h-100 border-primary shadow-sm hover-shadow">
                                            <div class="card-body text-center py-4">
                                                <i class="fas fa-sitemap fa-3x text-primary mb-2"></i>
                                                <h5 class="card-title">Solicitud para Asociación</h5>
                                                <p class="card-text text-muted small">Mi asociación ya está registrada. Deseo quedar asignado como responsable.</p>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="?tipo=particular" class="text-decoration-none">
                                        <div class="card h-100 border-success shadow-sm hover-shadow">
                                            <div class="card-body text-center py-4">
                                                <i class="fas fa-user-plus fa-3x text-success mb-2"></i>
                                                <h5 class="card-title">Solicitud para Particular</h5>
                                                <p class="card-text text-muted small">Deseo afiliarme como organizador y crear mi organización/club.</p>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            <div class="text-center">
                                <a href="landing.php" class="btn btn-outline-secondary"><i class="fas fa-home me-1"></i>Volver al Inicio</a>
                            </div>
                        <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <a href="affiliate_request.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Cambiar tipo de solicitud</a>
                        </div>
                        <div class="info-box">
                            <i class="fas fa-info-circle text-warning me-2"></i>
                            <strong>Información:</strong> <?= $tipo_solicitud === 'asociacion' ? 'Seleccione su asociación y complete sus datos. Al aprobarse quedará asignado como responsable de esa asociación.' : 'Al afiliarte podrás crear tus propios clubes, organizar torneos e invitar jugadores. Tu solicitud será revisada por el administrador del sistema.' ?>
                        </div>
                        <div class="alert alert-light border mb-3">
                            <i class="fas fa-shield-alt text-primary me-2"></i>
                            <strong>Reglas del proceso:</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                <li>Si ya tienes una cuenta registrada, puedes solicitar afiliación para asumir rol de administrador de organización.</li>
                                <li>Si ya eres administrador de organización y tienes una organización activa asignada, no puedes crear otra organización con este formulario.</li>
                            </ul>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                            </div>
                            <div class="text-center">
                                <a href="affiliate_request.php" class="btn btn-outline-secondary me-2"><i class="fas fa-file-alt me-1"></i>Nueva solicitud</a>
                                <a href="landing.php" class="btn btn-outline-secondary"><i class="fas fa-home me-1"></i>Volver al Inicio</a>
                            </div>
                        <?php else: ?>
                            <?php
                            $post = $_POST;
                            $preservar = !empty($error);
                            $form_tipo = $tipo_solicitud ?? ($preservar ? ($post['tipo_solicitud'] ?? 'particular') : 'particular');
                            ?>
                            <form method="POST" id="affiliateForm" data-preservar="<?= $preservar ? '1' : '0' ?>">
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                <input type="hidden" name="tipo_solicitud" value="<?= htmlspecialchars($form_tipo) ?>">
                                <input type="hidden" name="fechnac" id="fechnac" value="<?= htmlspecialchars($preservar ? ($post['fechnac'] ?? '') : '') ?>">
                                
                                <?php if ($form_tipo === 'asociacion'): ?>
                                <!-- Selector de asociación: opciones desde tabla entidad (nombres de entidades/asociaciones) -->
                                <h6 class="section-title"><i class="fas fa-sitemap me-2"></i>Seleccionar Asociación</h6>
                                <div class="mb-4" style="max-width: 40%;">
                                    <label class="form-label">Asociación / Organización *</label>
                                    <select name="asociacion_entidad" id="asociacion_entidad" class="form-select" required style="width: 100%; max-width: 100%;">
                                        <option value="">-- Seleccione la asociación a la que desea quedar asignado --</option>
                                        <?php foreach ($entidades_options as $ent): 
                                            $cod = $ent['codigo'] ?? $ent['id'] ?? '';
                                            $nom = $ent['nombre'] ?? $cod;
                                            $sel = $preservar && isset($post['asociacion_entidad']) && (string)($post['asociacion_entidad'] ?? '') === (string)$cod;
                                        ?>
                                            <option value="<?= htmlspecialchars($cod) ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($nom) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted d-block mt-1">Listado desde entidades. Al aprobar la solicitud quedará como responsable de la asociación seleccionada.</small>
                                    <?php if (empty($entidades_options)): ?>
                                        <div class="alert alert-warning mt-2 mb-0 small">
                                            <i class="fas fa-info-circle me-1"></i>
                                            No hay entidades cargadas. Contacte al administrador para dar de alta las entidades (asociaciones).
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Datos de la Organización (Particular: editable; Asociación: opcional complemento) -->
                                <h6 class="section-title mt-4"><i class="fas fa-building me-2"></i>Datos de la Organización</h6>

                                <!-- Fila 1: Nombre (30% menos), RIF (50% del actual), Dirección -->
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nombre de la Organización <?= $form_tipo === 'particular' ? '*' : '' ?></label>
                                        <input type="text" name="club_nombre" id="club_nombre" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['club_nombre'] ?? '') : '') ?>" <?= $form_tipo === 'particular' ? 'required' : 'placeholder="Opcional (se usará el de la asociación)"' ?>>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">RIF</label>
                                        <input type="text" name="rif" id="rif" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['rif'] ?? '') : '') ?>" placeholder="J-12345678-9">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Dirección</label>
                                        <input type="text" name="org_direccion" id="org_direccion" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['org_direccion'] ?? '') : '') ?>" placeholder="Dirección de la organización">
                                    </div>
                                </div>

                                <!-- Fila 2: Responsable, Teléfono (50%), Email (-30%), Ubicación/Ciudad (-30%) -->
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Responsable / Presidente *</label>
                                        <input type="text" name="org_responsable" id="org_responsable" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['org_responsable'] ?? '') : '') ?>" required placeholder="Nombre del responsable">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Teléfono</label>
                                        <input type="text" name="org_telefono" id="org_telefono" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['org_telefono'] ?? '') : '') ?>" placeholder="0212-1234567">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="org_email" id="org_email" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['org_email'] ?? '') : '') ?>" placeholder="contacto@org.com">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Ubicación / Ciudad</label>
                                        <input type="text" name="club_ubicacion" id="club_ubicacion" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['club_ubicacion'] ?? '') : '') ?>" placeholder="Caracas, Miranda">
                                    </div>
                                </div>

                                <?php if ($form_tipo === 'particular'): ?>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Entidad *</label>
                                        <select name="entidad" id="entidad" class="form-select" required>
                                            <option value="">Seleccionar Entidad</option>
                                            <?php if (!empty($entidades_options)): ?>
                                                <?php foreach ($entidades_options as $ent): ?>
                                                    <?php $sel = $preservar && isset($post['entidad']) && (string)$post['entidad'] === (string)$ent['codigo']; ?>
                                                    <option value="<?= htmlspecialchars($ent['codigo']) ?>" <?= $sel ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($ent['nombre'] ?? $ent['codigo']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="" disabled>No hay entidades disponibles</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="entidad" id="entidad" value="<?= (int)($preservar ? ($post['entidad'] ?? 0) : 0) ?>">
                                <?php endif; ?>
                                
                                <!-- Datos Personales: cédula, nombre, email, celular en una línea -->
                                <h6 class="section-title mt-4"><i class="fas fa-user me-2"></i>Datos Personales</h6>
                                
                                <input type="hidden" name="nacionalidad" id="nacionalidad" value="<?= htmlspecialchars($preservar ? ($post['nacionalidad'] ?? '') : '') ?>">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Cédula *</label>
                                        <input type="text" name="cedula" id="cedula" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['cedula'] ?? '') : '') ?>" 
                                               onblur="buscarPersona()" required>
                                        <div id="busqueda_resultado" class="search-status"></div>
                                        <div id="nacionalidad_visible" class="mt-2 p-2 rounded bg-light border small">
                                            <span class="text-muted">Nacionalidad:</span> <strong id="nacionalidad_valor" class="text-primary"><?= $preservar && !empty($post['nacionalidad']) ? htmlspecialchars($post['nacionalidad']) : '—' ?></strong>
                                            <span id="nacionalidad_hint" class="text-muted"> (se carga al buscar por cédula)</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Nombre Completo *</label>
                                        <input type="text" name="nombre" id="nombre" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['nombre'] ?? '') : '') ?>" required>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" id="email" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['email'] ?? '') : '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Celular</label>
                                        <input type="text" name="celular" id="celular" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['celular'] ?? '') : '') ?>">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Motivo de la Solicitud</label>
                                    <textarea name="motivo" id="motivo" class="form-control" rows="3"
                                              placeholder="Cuéntanos por qué deseas afiliarte..."><?= htmlspecialchars($preservar ? ($post['motivo'] ?? '') : '') ?></textarea>
                                </div>
                                
                                <!-- Credenciales de Acceso (solo si no estás registrado) -->
                                <h6 class="section-title mt-4"><i class="fas fa-key me-2"></i>Credenciales de Acceso</h6>
                                <p class="text-muted small mb-2">Si ya tienes cuenta en el sistema, deja usuario y contraseña en blanco: al aprobar la solicitud se te asignará la organización como administrador.</p>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nombre de Usuario</label>
                                        <input type="text" name="username" id="username" class="form-control" 
                                               value="<?= htmlspecialchars($preservar ? ($post['username'] ?? '') : '') ?>" placeholder="Solo si eres nuevo">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contraseña</label>
                                        <div class="input-group">
                                            <input type="password" name="password" id="password" class="form-control" placeholder="Solo si eres nuevo" autocomplete="new-password">
                                            <button type="button" class="btn btn-outline-secondary" id="togglePassword" title="Mostrar/ocultar contraseña" aria-label="Mostrar contraseña">
                                                <i class="fas fa-eye" id="iconPassword"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Mínimo 6 caracteres (solo usuarios nuevos)</small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Confirmar Contraseña</label>
                                        <div class="input-group">
                                            <input type="password" name="password_confirm" id="password_confirm" class="form-control" placeholder="Solo si eres nuevo" autocomplete="new-password">
                                            <button type="button" class="btn btn-outline-secondary" id="togglePasswordConfirm" title="Mostrar/ocultar contraseña" aria-label="Mostrar contraseña">
                                                <i class="fas fa-eye" id="iconPasswordConfirm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-register btn-primary w-100 mt-3">
                                    <i class="fas fa-paper-plane me-2"></i>Enviar Solicitud de Afiliación
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if (!$success && $tipo_solicitud !== null): ?>
                        <div class="text-center mt-4">
                            <p class="text-muted mb-2">¿Solo quieres participar en torneos?</p>
                            <a href="user_register.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-user-plus me-1"></i>Registro de Jugador
                            </a>
                            <a href="affiliate_request.php" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-file-alt me-1"></i>Cambiar tipo</a>
                            <a href="landing.php" class="btn btn-outline-secondary btn-sm ms-2">
                                <i class="fas fa-home me-1"></i>Volver al Inicio
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('affiliateForm');
            const preservar = form && form.getAttribute('data-preservar') === '1';
            // Solo limpiar el formulario si NO hubo error (envío satisfactorio o primera carga sin datos a preservar)
            if (form && !preservar) {
                const cedula = document.getElementById('cedula');
                const nombre = document.getElementById('nombre');
                const email = document.getElementById('email');
                const celular = document.getElementById('celular');
                const username = document.getElementById('username');
                const password = document.getElementById('password');
                const passwordConfirm = document.getElementById('password_confirm');
                const nacionalidad = document.getElementById('nacionalidad');
                const fechnac = document.getElementById('fechnac');
                const entidad = document.getElementById('entidad');
                const rif = document.getElementById('rif');
                const clubNombre = document.getElementById('club_nombre');
                const clubUbicacion = document.getElementById('club_ubicacion');
                const orgDireccion = document.getElementById('org_direccion');
                const orgResponsable = document.getElementById('org_responsable');
                const orgTelefono = document.getElementById('org_telefono');
                const orgEmail = document.getElementById('org_email');
                const motivo = document.getElementById('motivo');
                
                if (cedula) cedula.value = '';
                if (nombre) nombre.value = '';
                if (email) email.value = '';
                if (celular) celular.value = '';
                if (username) username.value = '';
                if (password) password.value = '';
                if (passwordConfirm) passwordConfirm.value = '';
                if (nacionalidad) nacionalidad.value = '';
                actualizarIndicadorNacionalidad(null);
                if (fechnac) fechnac.value = '';
                if (entidad) entidad.value = '';
                if (rif) rif.value = '';
                if (clubNombre) clubNombre.value = '';
                if (clubUbicacion) clubUbicacion.value = '';
                if (orgDireccion) orgDireccion.value = '';
                if (orgResponsable) orgResponsable.value = '';
                if (orgTelefono) orgTelefono.value = '';
                if (orgEmail) orgEmail.value = '';
                if (motivo) motivo.value = '';
                
                const busquedaResultado = document.getElementById('busqueda_resultado');
                if (busquedaResultado) busquedaResultado.innerHTML = '';
            } else if (form && preservar) {
                var nacHidden = document.getElementById('nacionalidad');
                if (nacHidden && nacHidden.value) actualizarIndicadorNacionalidad(nacHidden.value);
            }

            // Visor de contraseña: mostrar/ocultar
            const togglePassword = document.getElementById('togglePassword');
            const togglePasswordConfirm = document.getElementById('togglePasswordConfirm');
            const inputPassword = document.getElementById('password');
            const inputPasswordConfirm = document.getElementById('password_confirm');
            const iconPassword = document.getElementById('iconPassword');
            const iconPasswordConfirm = document.getElementById('iconPasswordConfirm');
            if (togglePassword && inputPassword && iconPassword) {
                togglePassword.addEventListener('click', function() {
                    const type = inputPassword.type === 'password' ? 'text' : 'password';
                    inputPassword.type = type;
                    iconPassword.classList.toggle('fa-eye', type === 'password');
                    iconPassword.classList.toggle('fa-eye-slash', type === 'text');
                    togglePassword.setAttribute('aria-label', type === 'password' ? 'Mostrar contraseña' : 'Ocultar contraseña');
                });
            }
            if (togglePasswordConfirm && inputPasswordConfirm && iconPasswordConfirm) {
                togglePasswordConfirm.addEventListener('click', function() {
                    const type = inputPasswordConfirm.type === 'password' ? 'text' : 'password';
                    inputPasswordConfirm.type = type;
                    iconPasswordConfirm.classList.toggle('fa-eye', type === 'password');
                    iconPasswordConfirm.classList.toggle('fa-eye-slash', type === 'text');
                    togglePasswordConfirm.setAttribute('aria-label', type === 'password' ? 'Mostrar contraseña' : 'Ocultar contraseña');
                });
            }

        });
        
    function actualizarIndicadorNacionalidad(valor) {
        const valEl = document.getElementById('nacionalidad_valor');
        const hint = document.getElementById('nacionalidad_hint');
        if (valEl) valEl.textContent = valor || '—';
        if (hint) hint.style.display = valor ? 'none' : '';
    }
        
    function buscarPersona() {
        const cedula = document.getElementById('cedula').value.trim();
        const campoNacionalidad = document.getElementById('nacionalidad');
        const nacionalidadParam = (campoNacionalidad && campoNacionalidad.value) ? campoNacionalidad.value : 'V';
        const resultadoDiv = document.getElementById('busqueda_resultado');
        
        if (!cedula) {
            resultadoDiv.innerHTML = '';
            return;
        }
        
        resultadoDiv.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Buscando...</span>';
        
        const baseUrl = '<?= rtrim($base_url ?? app_base_url(), "/") ?>';
        const apiUrl = `${baseUrl}/public/api/search_user_persona.php?cedula=${encodeURIComponent(cedula)}&nacionalidad=${encodeURIComponent(nacionalidadParam)}`;
        
        fetch(apiUrl)
            .then(response => {
                // Verificar si la respuesta es exitosa
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                // Verificar si la respuesta es JSON
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    throw new Error("La respuesta no es JSON válido");
                }
                return response.json();
            })
            .then(data => {
                // Verificar estructura de respuesta
                if (!data || typeof data !== 'object') {
                    throw new Error('Respuesta inválida del servidor');
                }
                
                // Si hay un error en la respuesta
                if (data.success === false) {
                    resultadoDiv.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle me-1"></i>${data.error || 'Error en la búsqueda'}</span>`;
                    return;
                }
                
                // Si se encontró la persona
                if (data.success && data.data && data.data.encontrado) {
                    if (data.data.existe_usuario && data.data.usuario_existente) {
                        const u = data.data.usuario_existente;
                        const campNac = document.getElementById('nacionalidad');
                        if (campNac) { campNac.value = 'V'; actualizarIndicadorNacionalidad('V'); }
                        document.getElementById('nombre').value = u.nombre || '';
                        document.getElementById('celular').value = u.celular || '';
                        document.getElementById('email').value = u.email || '';
                        const fechnacEl = document.getElementById('fechnac');
                        if (fechnacEl) fechnacEl.value = u.fechnac || '';
                        document.getElementById('username').value = '';
                        document.getElementById('password').value = '';
                        document.getElementById('password_confirm').value = '';
                        resultadoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Usuario encontrado. Puede enviar la solicitud de afiliación para su organización.</span>';
                    } else if (data.data.persona) {
                        const persona = data.data.persona;
                        const campoNacionalidad = document.getElementById('nacionalidad');
                        const valorNac = (persona.nacionalidad && ['V','E','J','P'].includes(String(persona.nacionalidad).toUpperCase()))
                            ? String(persona.nacionalidad).toUpperCase() : 'V';
                        if (campoNacionalidad) {
                            campoNacionalidad.value = valorNac;
                            campoNacionalidad.dispatchEvent(new Event('change', { bubbles: true }));
                            actualizarIndicadorNacionalidad(valorNac);
                        }
                        if (persona.cedula !== undefined && persona.cedula !== '') {
                            const cedulaEl = document.getElementById('cedula');
                            if (cedulaEl) cedulaEl.value = String(persona.cedula).replace(/\D/g, '');
                        }
                        document.getElementById('nombre').value = persona.nombre || '';
                        document.getElementById('celular').value = persona.celular || '';
                        document.getElementById('email').value = persona.email || '';
                        document.getElementById('fechnac').value = persona.fechnac || '';
                        
                        // Generar username sugerido
                        if (!document.getElementById('username').value) {
                            const nameParts = (persona.nombre || '').toLowerCase().split(' ');
                            if (nameParts.length >= 2) {
                                document.getElementById('username').value = nameParts[0] + '.' + nameParts[nameParts.length - 1];
                            }
                        }
                        
                        resultadoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Datos encontrados y completados</span>';
                    } else {
                        const campNac = document.getElementById('nacionalidad');
                        if (campNac) campNac.value = '';
                        actualizarIndicadorNacionalidad(null);
                        resultadoDiv.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>No encontrado. Complete los datos manualmente.</span>';
                    }
                } else {
                    const campNac = document.getElementById('nacionalidad');
                    if (campNac) campNac.value = '';
                    actualizarIndicadorNacionalidad(null);
                    resultadoDiv.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>No encontrado. Complete los datos manualmente.</span>';
                }
            })
            .catch(error => {
                console.error('Error en búsqueda:', error);
                resultadoDiv.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Error en la búsqueda: ${error.message}</span>`;
            });
    }
    </script>
</body>
</html>
