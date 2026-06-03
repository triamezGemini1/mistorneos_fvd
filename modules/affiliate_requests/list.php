<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/security.php';

Auth::requireRole(['admin_general']);

/**
 * Envía notificación por email al solicitante (aprobación o rechazo)
 * Usa NotificationSender para configuración unificada.
 */
function enviarNotificacionAfiliacion($email, $nombre, $username, $aprobado = true, $motivo = '') {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!class_exists('NotificationSender')) {
        require_once __DIR__ . '/../../lib/NotificationSender.php';
    }
    
    $app_url = rtrim($_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd', '/');
    
    if ($aprobado) {
        $asunto = '¡Tu solicitud de afiliación ha sido aprobada!';
        $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; padding: 20px; text-align: center;'>
                        <h1 style='margin: 0;'>🎉 ¡Felicitaciones!</h1>
                    </div>
                    <div style='padding: 30px; background: #f7fafc;'>
                        <p>Hola <strong>{$nombre}</strong>,</p>
                        <p>Nos complace informarte que tu solicitud de afiliación a <strong>La Estación del Dominó</strong> ha sido <span style='color: #38a169; font-weight: bold;'>APROBADA</span>.</p>
                        <p>Ya puedes acceder al sistema con las siguientes credenciales:</p>
                        <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <p><strong>Usuario:</strong> {$username}</p>
                            <p><strong>Contraseña:</strong> La que definiste al registrarte</p>
                        </div>
                        <p>Como administrador de club podrás:</p>
                        <ul>
                            <li>Crear y gestionar tus clubes</li>
                            <li>Organizar torneos</li>
                            <li>Invitar jugadores</li>
                            <li>Ver estadísticas y reportes</li>
                        </ul>
                        <div style='background: #e6fffa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #38a169;'>
                            <h3 style='margin-top: 0; color: #2d3748;'><i class='fas fa-book' style='margin-right: 10px;'></i>Manual de Usuario</h3>
                            <p style='margin-bottom: 10px;'>Consulta el manual completo con todas las funcionalidades del sistema:</p>
                            <p style='margin-bottom: 15px;'><a href='" . $app_url . "/manuales_web/manual_usuario.php' style='color: #38a169; font-weight: bold; text-decoration: none;'>📖 Ver Manual de Usuario</a></p>
                            <p style='margin: 0; font-size: 14px; color: #4a5568;'><strong>Nota:</strong> El manual solo está disponible para usuarios registrados. Debes iniciar sesión para acceder. El manual incluye guías paso a paso para crear torneos, invitar jugadores, gestionar inscripciones, administrar resultados y mucho más.</p>
                        </div>
                        <p style='text-align: center; margin-top: 30px;'>
                            <a href='" . AppHelpers::url("login.php' style='background: #48bb78; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;'>Iniciar Sesión</a>
                        </p>
                    </div>
                    <div style='background: #2d3748; color: white; padding: 15px; text-align: center; font-size: 12px;'>
                        La Estación del Dominó - Sistema de Gestión de Torneos
                    </div>
                </div>
            ");
        $result = NotificationSender::sendEmailHtml($email, $asunto, $body, $nombre);
    } else {
        $asunto = 'Actualización sobre tu solicitud de afiliación';
        $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #c53030 0%, #9b2c2c 100%); color: white; padding: 20px; text-align: center;'>
                        <h1 style='margin: 0;'>Solicitud No Aprobada</h1>
                    </div>
                    <div style='padding: 30px; background: #f7fafc;'>
                        <p>Hola <strong>{$nombre}</strong>,</p>
                        <p>Lamentamos informarte que tu solicitud de afiliación a <strong>La Estación del Dominó</strong> no ha sido aprobada en esta ocasión.</p>
                        " . ($motivo ? "<div style='background: #fed7d7; padding: 15px; border-radius: 8px; margin: 20px 0;'><strong>Motivo:</strong> " . htmlspecialchars($motivo) . "</div>" : "") . "
                        <p>Si tienes alguna pregunta o deseas más información, no dudes en contactarnos.</p>
                        <p>Puedes volver a enviar una solicitud cuando lo consideres conveniente.</p>
                    </div>
                    <div style='background: #2d3748; color: white; padding: 15px; text-align: center; font-size: 12px;'>
                        La Estación del Dominó - Sistema de Gestión de Torneos
                    </div>
                </div>
            ";
        $result = NotificationSender::sendEmailHtml($email, $asunto, $body, $nombre);
    }
    
    if (!$result['ok']) {
        error_log("Error enviando email de afiliación: " . ($result['error'] ?? 'desconocido'));
    }
    return $result['ok'];
}

$pdo = DB::pdo();

// Obtener mensajes de sesión
$message = $_SESSION['success_message'] ?? $_SESSION['error_message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';

// Limpiar mensajes de sesión después de mostrarlos
if (isset($_SESSION['success_message']) || isset($_SESSION['error_message'])) {
    unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['message_type']);
}

$approved_request_id = $_SESSION['approved_request_id'] ?? null;
if (isset($_SESSION['approved_request_id'])) {
    unset($_SESSION['approved_request_id']);
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
        $has_f = false;
        foreach ($cols as $col) {
            if (strtolower($col['Field'] ?? $col['field'] ?? '') === $f) {
                $has_f = true;
                break;
            }
        }
        if (!$has_f) {
            if ($f === 'org_direccion') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_direccion VARCHAR(255) NULL AFTER club_ubicacion");
            } elseif ($f === 'org_responsable') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_responsable VARCHAR(100) NULL");
            } elseif ($f === 'org_telefono') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_telefono VARCHAR(50) NULL");
            } elseif ($f === 'org_email') {
                $pdo->exec("ALTER TABLE solicitudes_afiliacion ADD COLUMN org_email VARCHAR(100) NULL");
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

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validate();
    $action = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if ($action === 'aprobar' && $request_id) {
        try {
            $pdo->beginTransaction();
            
            // Obtener datos de la solicitud
            $stmt = $pdo->prepare("SELECT * FROM solicitudes_afiliacion WHERE id = ? AND estatus = 'pendiente'");
            $stmt->execute([$request_id]);
            $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($solicitud) {
                $entidad = isset($solicitud['entidad']) ? (int)$solicitud['entidad'] : 0;
                $admin_user_id = null;

                // ¿La solicitud ya tiene un usuario vinculado (creado al solicitar o usuario ya registrado)?
                if (!empty($solicitud['user_id'])) {
                    $admin_user_id = (int) $solicitud['user_id'];
                    $stmt = $pdo->prepare("SELECT id, status, role FROM usuarios WHERE id = ?");
                    $stmt->execute([$admin_user_id]);
                    $usr = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$usr) {
                        throw new Exception('Usuario vinculado a la solicitud no encontrado');
                    }
                    // Activar usuario (si estaba pending) y asegurar rol admin_club; actualizar entidad si viene en la solicitud
                    $stmt = $pdo->prepare("UPDATE usuarios SET status = 0, role = 'admin_club' WHERE id = ?");
                    $stmt->execute([$admin_user_id]);
                    if ($entidad > 0) {
                        $pdo->prepare("UPDATE usuarios SET entidad = ? WHERE id = ?")->execute([$entidad, $admin_user_id]);
                    }
                } else {
                    // Flujo legacy: crear usuario nuevo al aprobar
                    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? OR username = ?");
                    $stmt->execute([$solicitud['cedula'], $solicitud['username']]);
                    if ($stmt->fetch()) {
                        throw new Exception('Ya existe un usuario con esa cédula o nombre de usuario');
                    }
                    // password_hash no puede ser NULL en usuarios: usar el de la solicitud o uno temporal
                    $password_hash = trim($solicitud['password_hash'] ?? '');
                    if ($password_hash === '') {
                        $password_hash = Security::hashPassword(bin2hex(random_bytes(16)));
                        error_log("affiliate_requests/list: solicitud sin password_hash, asignada contraseña temporal para usuario " . $solicitud['username']);
                    }
                    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    $cedula_digitos = preg_replace('/\D/', '', (string)($solicitud['cedula'] ?? ''));
                    $nacionalidad_usr = isset($solicitud['nacionalidad']) && in_array(strtoupper(trim($solicitud['nacionalidad'])), ['V', 'E', 'J', 'P'], true)
                        ? strtoupper(trim($solicitud['nacionalidad'])) : 'V';
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (cedula, nacionalidad, nombre, email, celular, fechnac, username, password_hash, role, club_id, entidad, status, uuid, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin_club', NULL, ?, 0, ?, NOW())
                    ");
                    $stmt->execute([
                        $cedula_digitos,
                        $nacionalidad_usr,
                        $solicitud['nombre'],
                        $solicitud['email'],
                        $solicitud['celular'],
                        $solicitud['fechnac'],
                        $solicitud['username'],
                        $password_hash,
                        $entidad,
                        $uuid
                    ]);
                    $admin_user_id = (int) $pdo->lastInsertId();
                }

                $organizacion_id_solicitud = isset($solicitud['organizacion_id']) && (int)$solicitud['organizacion_id'] > 0 ? (int)$solicitud['organizacion_id'] : null;
                $tipo_solicitud = $solicitud['tipo_solicitud'] ?? 'particular';

                if ($organizacion_id_solicitud && ($tipo_solicitud === 'asociacion' || $tipo_solicitud === 'asociación')) {
                    // Solicitud de asociación: asignar usuario a la organización existente (solo si aún no tiene responsable)
                    $stmt = $pdo->prepare("UPDATE organizaciones SET admin_user_id = ?, updated_at = NOW() WHERE id = ? AND estatus = 1 AND (admin_user_id IS NULL OR admin_user_id = 0)");
                    $stmt->execute([$admin_user_id, $organizacion_id_solicitud]);
                    if ($stmt->rowCount() === 0) {
                        throw new Exception('La asociación seleccionada ya está asignada a otro responsable. No se puede aprobar esta solicitud.');
                    }
                    $nota = "Asociación existente asignada (org id {$organizacion_id_solicitud}).";
                } else {
                    // Solicitud particular: crear nueva organización
                    $org_nombre = trim($solicitud['club_nombre'] ?? '');
                    $org_direccion = trim($solicitud['org_direccion'] ?? $solicitud['club_ubicacion'] ?? '') ?: null;
                    $org_responsable = trim($solicitud['org_responsable'] ?? $solicitud['nombre'] ?? '') ?: null;
                    $org_telefono = trim($solicitud['org_telefono'] ?? $solicitud['celular'] ?? '') ?: null;
                    $org_email = trim($solicitud['org_email'] ?? $solicitud['email'] ?? '') ?: null;
                    $org_entidad = $entidad;

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
                            $stmt = $pdo->prepare("
                                INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, tipo_org, cod_org, admin_user_id, estatus, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, 1, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $org_nombre,
                                $org_direccion,
                                $org_responsable,
                                $org_telefono,
                                $org_email,
                                $org_entidad,
                                $org_entidad,
                                $admin_user_id
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, cod_org, admin_user_id, estatus, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $org_nombre,
                                $org_direccion,
                                $org_responsable,
                                $org_telefono,
                                $org_email,
                                $org_entidad,
                                $org_entidad,
                                $admin_user_id
                            ]);
                        }
                    } else {
                        if ($hasTipoOrg) {
                            $stmt = $pdo->prepare("
                                INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, tipo_org, admin_user_id, estatus, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $org_nombre,
                                $org_direccion,
                                $org_responsable,
                                $org_telefono,
                                $org_email,
                                $org_entidad,
                                $admin_user_id
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, admin_user_id, estatus, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $org_nombre,
                                $org_direccion,
                                $org_responsable,
                                $org_telefono,
                                $org_email,
                                $org_entidad,
                                $admin_user_id
                            ]);
                        }
                    }
                    $nota = "Organización: " . ($solicitud['club_nombre'] ?? 'N/A');
                }

                // Actualizar estado de la solicitud
                $stmt = $pdo->prepare("
                    UPDATE solicitudes_afiliacion 
                    SET estatus = 'aprobada', notas_admin = ?, revisado_at = NOW(), revisado_por = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nota, Auth::user()['id'], $request_id]);
                
                $pdo->commit();
                
                // Enviar notificación por email
                $email_enviado = enviarNotificacionAfiliacion(
                    $solicitud['email'], 
                    $solicitud['nombre'], 
                    $solicitud['username'], 
                    true
                );
                
                if ($organizacion_id_solicitud && ($tipo_solicitud === 'asociacion' || $tipo_solicitud === 'asociación')) {
                    $message = "Solicitud aprobada. Usuario '{$solicitud['username']}' asignado como responsable de la asociación.";
                } else {
                    $message = !empty($solicitud['user_id'])
                        ? "Solicitud aprobada. Usuario '{$solicitud['username']}' asignado como administrador de la organización."
                        : "Solicitud aprobada. Usuario '{$solicitud['username']}' creado como administrador de organización.";
                }
                if ($email_enviado) {
                    $message .= " Se envió notificación por email.";
                } elseif (!empty($solicitud['email'])) {
                    $message .= " No se pudo enviar el email (verifique MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD en .env).";
                }
                
                // Guardar mensaje y datos en sesión para mostrar modal de WhatsApp
                $_SESSION['success_message'] = $message;
                $_SESSION['message_type'] = 'success';
                $_SESSION['approved_request_id'] = $request_id;
                $_SESSION['approved_request_data'] = [
                    'id' => $request_id,
                    'nombre' => $solicitud['nombre'],
                    'username' => $solicitud['username'],
                    'club_nombre' => $solicitud['club_nombre'],
                    'celular' => $solicitud['celular'],
                    'email' => $solicitud['email']
                ];
                
                // NO redirigir - mantener en la misma página para mostrar modal de WhatsApp
                // El JavaScript mostrará el modal automáticamente
            } else {
                $_SESSION['error_message'] = 'Solicitud no encontrada o ya procesada';
                $_SESSION['message_type'] = 'warning';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = 'Error al aprobar: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
        
        // Redirigir después de procesar aprobación
        $redirect_url = '?page=affiliate_requests&filter=pendiente';
        if (isset($_GET['filter'])) {
            $redirect_url = '?page=affiliate_requests&filter=' . urlencode($_GET['filter']);
        }
        header("Location: " . $redirect_url);
        exit;
    } elseif ($action === 'rechazar' && $request_id) {
        try {
            // Obtener datos de la solicitud para enviar email
            $stmt = $pdo->prepare("SELECT nombre, email FROM solicitudes_afiliacion WHERE id = ? AND estatus = 'pendiente'");
            $stmt->execute([$request_id]);
            $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $notes = trim($_POST['notas_admin'] ?? 'Solicitud rechazada');
            $stmt = $pdo->prepare("
                UPDATE solicitudes_afiliacion 
                SET estatus = 'rechazada', notas_admin = ?, revisado_at = NOW(), revisado_por = ?
                WHERE id = ? AND estatus = 'pendiente'
            ");
            $stmt->execute([$notes, Auth::user()['id'], $request_id]);
            
            // Enviar notificación de rechazo
            $email_rechazo_ok = false;
            if ($solicitud && $solicitud['email']) {
                $email_rechazo_ok = enviarNotificacionAfiliacion(
                    $solicitud['email'], 
                    $solicitud['nombre'], 
                    '', 
                    false,
                    $notes
                );
            }
            
            $_SESSION['success_message'] = 'Solicitud rechazada' . ($solicitud && $solicitud['email'] && !$email_rechazo_ok ? '. No se pudo enviar el email al solicitante (verifique configuración de correo en .env).' : '');
            $_SESSION['message_type'] = 'warning';
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
        
        // Redirigir después de procesar rechazo
        $redirect_url = '?page=affiliate_requests&filter=pendiente';
        if (isset($_GET['filter'])) {
            $redirect_url = '?page=affiliate_requests&filter=' . urlencode($_GET['filter']);
        }
        header("Location: " . $redirect_url);
        exit;
    }
}

// Obtener solicitudes
$filter = $_GET['filter'] ?? 'pendiente';
$where = $filter !== 'todas' ? "WHERE estatus = ?" : "";
$params = $filter !== 'todas' ? [$filter] : [];

try {
    $stmt = $pdo->prepare("SELECT * FROM solicitudes_afiliacion {$where} ORDER BY created_at DESC");
    $stmt->execute($params);
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $solicitudes = [];
}

// Contar pendientes
$pendientes_count = 0;
try {
    $pendientes_count = $pdo->query("SELECT COUNT(*) FROM solicitudes_afiliacion WHERE estatus = 'pendiente'")->fetchColumn();
} catch (Exception $e) {}

$status_badges = [
    'pendiente' => 'warning',
    'aprobada' => 'success',
    'rechazada' => 'danger'
];
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-user-tie me-2"></i>Solicitudes de Afiliación
            <?php if ($pendientes_count > 0): ?>
                <span class="badge bg-warning"><?= $pendientes_count ?> pendiente<?= $pendientes_count > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </h2>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?: 'info' ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($approved_request_id && $message_type === 'success' && isset($_SESSION['approved_request_data'])): ?>
        <?php 
        $approved_data = $_SESSION['approved_request_data'];
        unset($_SESSION['approved_request_data']); // Limpiar después de usar
        ?>
        <!-- Modal de WhatsApp después de aprobar -->
        <div class="modal fade" id="whatsappModal" tabindex="-1" 
             aria-labelledby="whatsappModalLabel" aria-hidden="true" 
             data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="whatsappModalLabel">
                            <i class="fab fa-whatsapp me-2"></i>Notificación por WhatsApp
                        </h5>
                        <button type="button" class="btn-close btn-close-white" 
                                onclick="closeWhatsAppModal()" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>¡Solicitud aprobada exitosamente!</strong><br>
                            Usuario <code><?= htmlspecialchars($approved_data['username']) ?></code> creado como administrador de organización.
                        </div>
                        
                        <p class="mb-3">¿Deseas enviar una notificación por WhatsApp al administrador de la organización?</p>
                        
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><i class="fas fa-user me-2"></i>Datos del Administrador</h6>
                                <p class="mb-1"><strong>Nombre:</strong> <?= htmlspecialchars($approved_data['nombre']) ?></p>
                                <p class="mb-1"><strong>Organización:</strong> <?= htmlspecialchars($approved_data['club_nombre']) ?></p>
                                <p class="mb-1"><strong>Teléfono:</strong> <?= htmlspecialchars($approved_data['celular'] ?? 'No especificado') ?></p>
                                <p class="mb-0"><strong>Usuario:</strong> <code><?= htmlspecialchars($approved_data['username']) ?></code></p>
                            </div>
                        </div>
                        
                        <?php
                        // Generar URL de WhatsApp
                        $telefono = preg_replace('/[^0-9]/', '', $approved_data['celular'] ?? '');
                        if ($telefono && $telefono[0] == '0') {
                            $telefono = substr($telefono, 1);
                        }
                        if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
                            $telefono = '58' . $telefono;
                        }
                        
                        $mensaje = "🎉 *¡FELICITACIONES!*\n\n";
                        $mensaje .= "Hola *" . $approved_data['nombre'] . "*\n\n";
                        $mensaje .= "Tu solicitud de afiliación a *La Estación del Dominó* ha sido *APROBADA* ✅\n\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                        $mensaje .= "📋 *DATOS DE ACCESO*\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                        $mensaje .= "👤 *Usuario:* " . $approved_data['username'] . "\n";
                        $mensaje .= "🔐 *Contraseña:* La que definiste al registrarte\n\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                        $mensaje .= "🏢 *TU CLUB*\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                        $mensaje .= "🏢 *Organización:* " . $approved_data['club_nombre'] . "\n\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                        $mensaje .= "✨ *AHORA PUEDES:*\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                        $mensaje .= "✅ Gestionar tus clubes\n";
                        $mensaje .= "✅ Crear y organizar torneos\n";
                        $mensaje .= "✅ Invitar jugadores\n";
                        $mensaje .= "✅ Ver estadísticas y reportes\n";
                        $mensaje .= "✅ Crear clubes asociados\n\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                        $mensaje .= "🌐 *Ingresa al sistema:*\n";
                        $mensaje .= AppHelpers::url('login.php') . "\n\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                        $mensaje .= "📖 *MANUAL DE USUARIO*\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                        $mensaje .= "📚 Consulta el manual completo con todas las funcionalidades:\n";
                        $app_url = $_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd';
                        $manual_url = rtrim($app_url, '/') . '/manuales_web/manual_usuario.php';
                        $mensaje .= $manual_url . "\n\n";
                        $mensaje .= "⚠️ *Nota:* El manual solo está disponible para usuarios registrados. Debes iniciar sesión para acceder.\n\n";
                        $mensaje .= "El manual incluye guías paso a paso para:\n";
                        $mensaje .= "✅ Crear y gestionar torneos\n";
                        $mensaje .= "✅ Invitar jugadores\n";
                        $mensaje .= "✅ Gestionar inscripciones\n";
                        $mensaje .= "✅ Administrar resultados\n";
                        $mensaje .= "✅ Y mucho más...\n\n";
                        $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                        $mensaje .= "¡Bienvenido al proyecto! 🎲\n\n";
                        $mensaje .= "_La Estación del Dominó_";
                        
                        $mensaje_encoded = urlencode($mensaje);
                        $whatsapp_url = $telefono && strlen($telefono) >= 10 
                            ? "https://api.whatsapp.com/send?phone={$telefono}&text={$mensaje_encoded}"
                            : "https://api.whatsapp.com/send?text={$mensaje_encoded}";
                        ?>
                        
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($whatsapp_url) ?>" 
                               class="btn btn-success btn-lg"
                               onclick="markWhatsAppSent()">
                                <i class="fab fa-whatsapp me-2"></i>
                                Enviar Notificación por WhatsApp
                            </a>
                            <button type="button" class="btn btn-outline-secondary" 
                                    onclick="closeWhatsAppModal()">
                                <i class="fas fa-times me-2"></i>
                                Omitir y Continuar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="btn-group">
                <a href="?page=affiliate_requests&filter=pendiente" class="btn btn-<?= $filter === 'pendiente' ? 'warning' : 'outline-warning' ?>">
                    <i class="fas fa-clock me-1"></i>Pendientes
                </a>
                <a href="?page=affiliate_requests&filter=aprobada" class="btn btn-<?= $filter === 'aprobada' ? 'success' : 'outline-success' ?>">
                    <i class="fas fa-check me-1"></i>Aprobadas
                </a>
                <a href="?page=affiliate_requests&filter=rechazada" class="btn btn-<?= $filter === 'rechazada' ? 'danger' : 'outline-danger' ?>">
                    <i class="fas fa-times me-1"></i>Rechazadas
                </a>
                <a href="?page=affiliate_requests&filter=todas" class="btn btn-<?= $filter === 'todas' ? 'secondary' : 'outline-secondary' ?>">
                    <i class="fas fa-list me-1"></i>Todas
                </a>
            </div>
        </div>
    </div>
    
    <!-- Lista de Solicitudes (solo admin general puede autorizar) -->
    <div class="card">
        <div class="card-body">
            <p class="text-muted small mb-3">
                <i class="fas fa-info-circle me-1"></i> Todas las solicitudes (usuario nuevo o usuario existente que pide registrar una organización) quedan en <strong>pendiente</strong> hasta que el administrador general las autorice.
            </p>
            <?php if (empty($solicitudes)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No hay solicitudes <?= $filter !== 'todas' ? $filter . 's' : '' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Solicitante</th>
                                <th>Organización Propuesta</th>
                                <th>Usuario</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitudes as $sol): ?>
                                <tr>
                                    <td>
                                        <small><?= date('d/m/Y', strtotime($sol['created_at'])) ?></small><br>
                                        <small class="text-muted"><?= date('H:i', strtotime($sol['created_at'])) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($sol['nombre']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($sol['nacionalidad'] . '-' . $sol['cedula']) ?></small>
                                        <?php if (!empty($sol['user_id'])): ?>
                                            <br><span class="badge bg-info mt-1">Usuario existente – registro de organización</span>
                                        <?php endif; ?>
                                        <?php
                                        $tipo_sol = $sol['tipo_solicitud'] ?? 'particular';
                                        if ($tipo_sol === 'asociacion' || $tipo_sol === 'asociación'): ?>
                                            <br><span class="badge bg-primary mt-1">Asociación</span>
                                        <?php else: ?>
                                            <br><span class="badge bg-secondary mt-1">Particular</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($sol['club_nombre']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($sol['club_ubicacion'] ?? $sol['org_direccion'] ?? '-') ?></small>
                                        <?php if (!empty($sol['org_responsable'])): ?>
                                            <br><small class="text-muted"><i class="fas fa-user me-1"></i><?= htmlspecialchars($sol['org_responsable']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($sol['username']) ?></code>
                                    </td>
                                    <td>
                                        <small>
                                            <?php if ($sol['email']): ?>
                                                <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($sol['email']) ?><br>
                                            <?php endif; ?>
                                            <?php if ($sol['celular']): ?>
                                                <i class="fas fa-phone me-1"></i><?= htmlspecialchars($sol['celular']) ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $status_badges[$sol['estatus']] ?>">
                                            <?= ucfirst($sol['estatus']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="showDetailModal(<?= $sol['id'] ?>, <?= htmlspecialchars(json_encode($sol), ENT_QUOTES, 'UTF-8') ?>)"
                                                title="Ver detalles">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($sol['estatus'] === 'pendiente'): ?>
                                            <form method="POST" class="d-inline" 
                                                  onsubmit="return confirmApprove(<?= $sol['id'] ?>, '<?= addslashes(htmlspecialchars($sol['club_nombre'], ENT_QUOTES)) ?>', '<?= addslashes(htmlspecialchars($sol['username'], ENT_QUOTES)) ?>')">
                                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                                <input type="hidden" name="action" value="aprobar">
                                                <input type="hidden" name="request_id" value="<?= $sol['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Aprobar solicitud">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="showRejectModal(<?= $sol['id'] ?>, '<?= addslashes(htmlspecialchars($sol['nombre'] ?? '', ENT_QUOTES)) ?>')"
                                                    title="Rechazar solicitud">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php else: ?>
                                            <a href="../modules/affiliate_requests/send_whatsapp.php?id=<?= $sol['id'] ?>" 
                                               class="btn btn-sm btn-success" 
                                               title="Enviar notificación por WhatsApp">
                                                <i class="fab fa-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Detalle Único (Reutilizable) -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel">Detalle de Solicitud</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="detailModalBody">
                <!-- Se llena dinámicamente con JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Rechazar Único (Reutilizable) -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="rejectForm">
                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                <input type="hidden" name="action" value="rechazar">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel">Rechazar Solicitud</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p id="rejectModalMessage">¿Está seguro de rechazar la solicitud?</p>
                    <div class="mb-3">
                        <label class="form-label">Motivo del rechazo</label>
                        <textarea name="notas_admin" class="form-control" rows="3" 
                                  placeholder="Indique el motivo del rechazo..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-1"></i>Rechazar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Función para confirmar aprobación de solicitud
function confirmApprove(id, clubNombre, username) {
    const mensaje = `¿Aprobar esta solicitud?\n\nSe creará:\n- Usuario: ${username} (administrador de organización)\n\nOrganización declarada: ${clubNombre}`;
    return confirm(mensaje);
}

// Función para mostrar el modal de detalles con datos dinámicos
function showDetailModal(id, data) {
    // Cerrar cualquier modal abierto
    const openModals = document.querySelectorAll('.modal.show');
    openModals.forEach(function(modal) {
        const bsModal = bootstrap.Modal.getInstance(modal);
        if (bsModal) {
            bsModal.hide();
        }
    });
    
    // Limpiar backdrops
    const backdrops = document.querySelectorAll('.modal-backdrop');
    backdrops.forEach(function(backdrop) {
        backdrop.remove();
    });
    
    // Formatear fecha de nacimiento
    let fechaNac = '-';
    if (data.fechnac) {
        try {
            const fecha = new Date(data.fechnac);
            fechaNac = fecha.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
        } catch (e) {
            fechaNac = '-';
        }
    }
    
    // Formatear fecha de solicitud
    let fechaSolicitud = '-';
    if (data.created_at) {
        try {
            const fecha = new Date(data.created_at);
            fechaSolicitud = fecha.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            fechaSolicitud = '-';
        }
    }
    
    // Formatear fecha de revisión
    let fechaRevision = '';
    if (data.revisado_at) {
        try {
            const fecha = new Date(data.revisado_at);
            fechaRevision = fecha.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            fechaRevision = '';
        }
    }
    
    // Construir HTML del modal
    const html = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Datos Personales</h6>
                <p><strong>Nombre:</strong> ${escapeHtml(data.nombre || '')}</p>
                <p><strong>Cédula:</strong> ${escapeHtml((data.nacionalidad || 'V') + '-' + (data.cedula || ''))}</p>
                <p><strong>Email:</strong> ${data.email ? escapeHtml(data.email) : '<span class="text-muted">-</span>'}</p>
                <p><strong>Celular:</strong> ${data.celular ? escapeHtml(data.celular) : '<span class="text-muted">-</span>'}</p>
                <p><strong>Fecha Nac.:</strong> ${fechaNac}</p>
                <p><strong>Usuario:</strong> <code>${escapeHtml(data.username || '')}</code></p>
                <p><strong>Entidad:</strong> ${data.entidad ? escapeHtml(data.entidad) : '<span class="text-muted">-</span>'}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary mb-3"><i class="fas fa-building me-2"></i>Datos de la Organización</h6>
                <p><strong>Nombre:</strong> ${escapeHtml(data.club_nombre || '')}</p>
                <p><strong>RIF:</strong> ${data.rif ? escapeHtml(data.rif) : '<span class="text-muted">-</span>'}</p>
                <p><strong>Ubicación:</strong> ${data.club_ubicacion ? escapeHtml(data.club_ubicacion) : '<span class="text-muted">-</span>'}</p>
                ${data.motivo ? `
                    <h6 class="text-primary mt-4 mb-2"><i class="fas fa-comment me-2"></i>Motivo</h6>
                    <p class="bg-light p-2 rounded">${escapeHtml(data.motivo).replace(/\n/g, '<br>')}</p>
                ` : ''}
            </div>
        </div>
        ${data.notas_admin ? `
            <hr>
            <h6 class="text-secondary"><i class="fas fa-sticky-note me-2"></i>Notas del Administrador</h6>
            <p class="bg-light p-2 rounded">${escapeHtml(data.notas_admin).replace(/\n/g, '<br>')}</p>
        ` : ''}
        <hr>
        <div class="row text-muted small">
            <div class="col-md-6">
                <strong>Fecha solicitud:</strong> ${fechaSolicitud}
            </div>
            ${fechaRevision ? `
                <div class="col-md-6">
                    <strong>Revisado:</strong> ${fechaRevision}
                </div>
            ` : ''}
        </div>
    `;
    
    // Actualizar contenido del modal
    document.getElementById('detailModalLabel').textContent = `Detalle de Solicitud #${id}`;
    document.getElementById('detailModalBody').innerHTML = html;
    
    // Abrir modal
    const modalElement = document.getElementById('detailModal');
    const bsModal = new bootstrap.Modal(modalElement);
    bsModal.show();
}

// Función para mostrar el modal de rechazo
function showRejectModal(id, nombre) {
    // Cerrar cualquier modal abierto
    const openModals = document.querySelectorAll('.modal.show');
    openModals.forEach(function(modal) {
        const bsModal = bootstrap.Modal.getInstance(modal);
        if (bsModal) {
            bsModal.hide();
        }
    });
    
    // Limpiar backdrops
    const backdrops = document.querySelectorAll('.modal-backdrop');
    backdrops.forEach(function(backdrop) {
        backdrop.remove();
    });
    
    // Actualizar contenido del modal
    document.getElementById('rejectRequestId').value = id;
    document.getElementById('rejectModalMessage').innerHTML = `¿Está seguro de rechazar la solicitud de <strong>${escapeHtml(nombre)}</strong>?`;
    document.getElementById('rejectForm').querySelector('textarea[name="notas_admin"]').value = '';
    
    // Abrir modal
    const modalElement = document.getElementById('rejectModal');
    const bsModal = new bootstrap.Modal(modalElement);
    bsModal.show();
}

// Función auxiliar para escapar HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Función para cerrar el modal de WhatsApp y continuar
function closeWhatsAppModal() {
    const modal = document.getElementById('whatsappModal');
    const backdrop = document.querySelector('.modal-backdrop');
    
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
    
    if (backdrop) {
        backdrop.remove();
    }
    
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Recargar la página para actualizar la lista
    setTimeout(function() {
        window.location.reload();
    }, 300);
}

// Marcar que WhatsApp fue enviado
function markWhatsAppSent() {
    // Opcional: marcar en sesión que se envió WhatsApp
    // Por ahora solo cerramos el modal después de un momento
    setTimeout(function() {
        closeWhatsAppModal();
    }, 2000);
}

// Limpiar modales al cerrar
document.addEventListener('DOMContentLoaded', function() {
    // Limpiar cuando se cierra cualquier modal
    document.addEventListener('hidden.bs.modal', function(e) {
        const modal = e.target;
        
        // Limpiar formularios
        const forms = modal.querySelectorAll('form');
        forms.forEach(function(form) {
            if (form.id === 'rejectForm') {
                form.reset();
            }
        });
        
        // Limpiar backdrops
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(function(backdrop) {
            backdrop.remove();
        });
        
        // Restaurar body solo si no es el modal de WhatsApp
        if (modal.id !== 'whatsappModal') {
            const openModals = document.querySelectorAll('.modal.show');
            if (openModals.length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        }
    });
    
    // Si hay modal de WhatsApp, mostrarlo automáticamente
    const whatsappModal = document.getElementById('whatsappModal');
    if (whatsappModal) {
        // Mostrar el modal usando Bootstrap
        const bsModal = new bootstrap.Modal(whatsappModal, {
            backdrop: 'static',
            keyboard: false
        });
        bsModal.show();
        
        // Asegurar que el body tenga las clases correctas
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
    }
});
</script>
