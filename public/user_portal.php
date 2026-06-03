<?php
/**
 * Portal de usuario. Patrón en bloque: db_config → auth_service → requireAuth.
 */
require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
AuthService::requireAuth();

$user = $_SESSION['user'];
$pdo = DB::pdo();
$base_url = app_base_url();

// Obtener datos actualizados del usuario
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([Auth::id()]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_data) {
    $user = array_merge($user, $user_data);
    $_SESSION['user'] = $user;
}

// Obtener sección activa
$section = $_GET['section'] ?? 'inicio';

// Procesar acciones POST
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::validate();
    
    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if (empty($current) || empty($new) || empty($confirm)) {
            $error = 'Todos los campos son requeridos';
        } elseif (strlen($new) < 6) {
            $error = 'La nueva contraseña debe tener al menos 6 caracteres';
        } elseif ($new !== $confirm) {
            $error = 'Las contraseñas no coinciden';
        } else {
            if (password_verify($current, $user['password_hash'])) {
                $new_hash = password_hash($new, PASSWORD_DEFAULT);
                // Verificar si la columna must_change_password existe
                $checkColumn = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'must_change_password'");
                if ($checkColumn->rowCount() > 0) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ?, must_change_password = 0 WHERE id = ?");
                    $stmt->execute([$new_hash, Auth::id()]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_hash, Auth::id()]);
                }
                $message = 'Contraseña actualizada exitosamente';
            } else {
                $error = 'La contraseña actual es incorrecta';
            }
        }
        $section = 'perfil';
    }
    
    if ($_POST['action'] === 'update_profile') {
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $telegram_chat_id = isset($_POST['telegram_chat_id']) ? (trim($_POST['telegram_chat_id']) ?: null) : null;
        
        $cols = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->fetch();
        if ($cols) {
            $stmt = $pdo->prepare("UPDATE usuarios SET email = ?, celular = ?, telegram_chat_id = ? WHERE id = ?");
            $stmt->execute([$email ?: null, $celular ?: null, $telegram_chat_id, Auth::id()]);
            $_SESSION['user']['telegram_chat_id'] = $telegram_chat_id;
            $user['telegram_chat_id'] = $telegram_chat_id;
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET email = ?, celular = ? WHERE id = ?");
            $stmt->execute([$email ?: null, $celular ?: null, Auth::id()]);
        }
        
        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['celular'] = $celular;
        $user['email'] = $email;
        $user['celular'] = $celular;
        
        $message = 'Perfil actualizado exitosamente';
        $section = 'perfil';
    }
    
    if ($_POST['action'] === 'upload_photo') {
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file = $_FILES['photo'];
            
            if (!in_array($file['type'], $allowed)) {
                $error = 'Solo se permiten imágenes JPG, PNG, GIF o WebP';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'La imagen no debe superar 2MB';
            } else {
                $upload_dir = __DIR__ . '/../uploads/photos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'user_' . Auth::id() . '_' . time() . '.' . $ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Eliminar foto anterior si existe
                    if (!empty($user['photo_path']) && file_exists(__DIR__ . '/../' . $user['photo_path'])) {
                        @unlink(__DIR__ . '/../' . $user['photo_path']);
                    }
                    
                    $photo_path = 'uploads/photos/' . $filename;
                    $stmt = $pdo->prepare("UPDATE usuarios SET photo_path = ? WHERE id = ?");
                    $stmt->execute([$photo_path, Auth::id()]);
                    
                    $_SESSION['user']['photo_path'] = $photo_path;
                    $user['photo_path'] = $photo_path;
                    
                    $message = 'Foto actualizada exitosamente';
                } else {
                    $error = 'Error al subir la imagen';
                }
            }
        } else {
            $error = 'Seleccione una imagen';
        }
        $section = 'perfil';
    }
    
    if ($_POST['action'] === 'regenerate_uuid') {
        $new_uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $stmt = $pdo->prepare("UPDATE usuarios SET uuid = ? WHERE id = ?");
        $stmt->execute([$new_uuid, Auth::id()]);
        
        $_SESSION['user']['uuid'] = $new_uuid;
        $user['uuid'] = $new_uuid;
        
        $message = 'Identificador único regenerado exitosamente';
        $section = 'perfil';
    }
}

// Generar UUID si no existe
if (empty($user['uuid'])) {
    $new_uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $stmt = $pdo->prepare("UPDATE usuarios SET uuid = ? WHERE id = ?");
    $stmt->execute([$new_uuid, Auth::id()]);
    
    $_SESSION['user']['uuid'] = $new_uuid;
    $user['uuid'] = $new_uuid;
}

// Obtener datos según sección
$torneos_programados = [];
$torneos_resultados = [];
$ranking = [];
$clubes = [];
$mis_inscripciones = [];
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

// Torneos programados (futuros)
$stmt = $pdo->query("
    SELECT t.*, o.nombre as organizacion_nombre 
    FROM tournaments t 
    {$org_join}
    WHERE t.fechator >= CURDATE() AND t.estatus = 1
    ORDER BY t.fechator ASC
    LIMIT 20
");
$torneos_programados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Torneos con resultados (pasados)
$stmt = $pdo->query("
    SELECT t.*, o.nombre as club_nombre,
           (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id) as total_inscritos
    FROM tournaments t 
    {$org_join}
    WHERE t.fechator < CURDATE()
    ORDER BY t.fechator DESC
    LIMIT 20
");
$torneos_resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Clubes activos
$stmt = $pdo->query("SELECT * FROM clubes WHERE estatus = 1 ORDER BY nombre");
$clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mis inscripciones
$stmt = $pdo->prepare("
    SELECT i.*, t.nombre as torneo_nombre, t.fechator, t.lugar, o.nombre as organizacion_nombre
    FROM inscritos i
    JOIN tournaments t ON i.torneo_id = t.id
    {$org_join}
    JOIN usuarios u ON i.id_usuario = u.id
    WHERE u.cedula = ?
    ORDER BY t.fechator DESC
");
$stmt->execute([$user['cedula'] ?? '']);
$mis_inscripciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ranking (top jugadores por participaciones)
$stmt = $pdo->query("
    SELECT u.nombre, u.cedula, COUNT(*) as participaciones
    FROM inscritos i
    JOIN usuarios u ON i.id_usuario = u.id
    WHERE i.estatus = 1
    GROUP BY u.cedula, u.nombre
    ORDER BY participaciones DESC
    LIMIT 50
");
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Abierto', 2 => 'Por Categorías'];

// Notificaciones (sección campanita): conteo pendientes para el badge y listado
$notificaciones_portal = [];
$notif_pendientes_count = 0;
$uid = Auth::id();
if ($uid > 0) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications_queue WHERE usuario_id = ? AND canal = 'web' AND estado = 'pendiente'");
    $stmtCount->execute([$uid]);
    $notif_pendientes_count = (int) $stmtCount->fetchColumn();
    if ($section === 'notificaciones') {
        require_once __DIR__ . '/../lib/NotificationManager.php';
        $nm = new \NotificationManager($pdo);
        $nm->marcarWebVistas($uid);
        $hasDatosJsonPortal = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'")->rowCount() > 0;
        $stmt = $pdo->prepare("
            SELECT id, mensaje, url_destino, fecha_creacion" . ($hasDatosJsonPortal ? ", datos_json" : "") . "
            FROM notifications_queue
            WHERE usuario_id = ? AND canal = 'web'
            ORDER BY fecha_creacion DESC
            LIMIT 100
        ");
        $stmt->execute([$uid]);
        $notificaciones_portal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Foto del usuario
$photo_url = !empty($user['photo_path']) && file_exists(__DIR__ . '/../' . $user['photo_path'])
    ? $base_url . '/' . $user['photo_path']
    : null;

// Telegram: verificar si el usuario tiene chat_id y si el bot está configurado
$telegram_chat_id_actual = trim((string)($user['telegram_chat_id'] ?? ''));
$telegram_bot_username = trim((string)($_ENV['TELEGRAM_BOT_USERNAME'] ?? ''));
$telegram_bot_link = $telegram_bot_username ? 'https://t.me/' . ltrim($telegram_bot_username, '@') : '';
$tiene_telegram = !empty($telegram_chat_id_actual);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal del Jugador - La Estación del Dominó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"></noscript>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #48bb78;
            --warning: #ecc94b;
            --danger: #c53030;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: #f7fafc;
            min-height: 100vh;
        }
        
        .navbar-portal {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 1rem 0;
        }
        
        .navbar-portal .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
        }
        
        .sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 1.5rem;
            position: sticky;
            top: 1rem;
        }
        
        .sidebar .user-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            margin-bottom: 1rem;
        }
        
        .sidebar .user-photo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .sidebar .nav-link {
            color: var(--secondary);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
        }
        
        .sidebar .nav-link:hover {
            background: #edf2f7;
        }
        
        .sidebar .nav-link.active {
            background: var(--accent);
            color: white;
        }
        
        .sidebar .nav-link i {
            width: 24px;
        }
        
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .content-card h4 {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #edf2f7;
        }
        
        .tournament-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        
        .tournament-card:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .tournament-card .date {
            background: var(--primary);
            color: white;
            padding: 0.5rem;
            border-radius: 8px;
            text-align: center;
            min-width: 70px;
        }
        
        .tournament-card .date .day {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .tournament-card .date .month {
            font-size: 0.75rem;
            text-transform: uppercase;
        }
        
        .club-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.2s;
            height: 100%;
        }
        
        .club-card:hover {
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .club-card .club-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.5rem;
        }
        
        .ranking-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid #edf2f7;
        }
        
        .ranking-item:last-child {
            border-bottom: none;
        }
        
        .ranking-position {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-right: 1rem;
        }
        
        .ranking-position.gold { background: #ffd700; color: #1a202c; }
        .ranking-position.silver { background: #c0c0c0; color: #1a202c; }
        .ranking-position.bronze { background: #cd7f32; color: white; }
        .ranking-position.normal { background: #edf2f7; color: #4a5568; }
        
        .stat-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }
        
        .stat-card .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .stat-card .stat-label {
            opacity: 0.8;
            font-size: 0.9rem;
        }
        
        .badge-modalidad {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
        }
        
        .user-welcome {
            background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .profile-photo-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 1.5rem;
        }
        
        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--accent);
        }
        
        .profile-photo-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
        }
        
        .photo-upload-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent);
            color: white;
            border: 3px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .photo-upload-btn:hover {
            background: #38a169;
            transform: scale(1.1);
        }
        
        .uuid-display {
            background: #1a202c;
            color: #48bb78;
            font-family: 'Courier New', monospace;
            padding: 1rem;
            border-radius: 8px;
            font-size: 1.1rem;
            letter-spacing: 1px;
            text-align: center;
            word-break: break-all;
        }
        
        .credential-card {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .credential-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }
        
        .credential-card .credential-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            margin-bottom: 1rem;
        }
        
        .credential-card .credential-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .credential-card .credential-id {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            opacity: 0.8;
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            display: inline-block;
            margin-top: 0.5rem;
        }
        /* Notificaciones toast (Push + tarjeta) */
        #notification-container { position: fixed; top: 20px; right: 20px; z-index: 9999; pointer-events: none; }
        #notification-container .notification-card { pointer-events: auto; display: flex; background: #fff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); padding: 16px; margin-bottom: 15px; width: 350px; max-width: calc(100vw - 40px); border-left: 6px solid #1a365d; transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        #notification-container .notification-card.show { transform: translateX(0); }
        #notification-container .notification-card-icon { font-size: 24px; margin-right: 15px; background: #e6edf5; border-radius: 50%; width: 50px; height: 50px; min-width: 50px; display: flex; align-items: center; justify-content: center; color: #1a365d; }
        #notification-container .notification-card-content { flex: 1; min-width: 0; position: relative; }
        #notification-container .notification-card-title { margin: 0 24px 0 0; font-size: 15px; font-weight: bold; color: #333; }
        #notification-container .notification-card-message { margin: 5px 0 12px 0; font-size: 13px; color: #666; line-height: 1.4; word-break: break-word; }
        #notification-container .btn-close-notif { position: absolute; top: -4px; right: 0; background: none; border: none; color: #999; cursor: pointer; font-size: 18px; line-height: 1; padding: 0 4px; }
        #notification-container .btn-close-notif:hover { color: #333; }
        #notification-container .notification-card-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        #notification-container .btn-ver, #notification-container .btn-download { display: inline-flex; align-items: center; font-size: 12px; padding: 6px 12px; border-radius: 6px; text-decoration: none; cursor: pointer; border: none; font-family: inherit; }
        #notification-container .btn-ver { background: #1a365d; color: #fff; }
        #notification-container .btn-download { background: #28a745; color: #fff; }
        #notification-container .notification-card-nueva-ronda { border: 2px solid #dc2626 !important; text-align: center; }
        #notification-container .notification-card-nueva-ronda .notification-card-content { text-align: center; }
        #notification-container .notification-card-nueva-ronda .notif-nueva-ronda-header { font-size: 18px; }
        #notification-container .notification-card-nueva-ronda .notif-nueva-ronda-mesa { font-size: 18px; font-weight: 700; }
        #notification-container .notification-card-nueva-ronda .notif-nueva-ronda-stats { display: grid; grid-template-columns: repeat(5, 1fr); grid-template-rows: auto auto; gap: 2px 8px; justify-items: center; margin: 8px auto; }
        #notification-container .notification-card-nueva-ronda .notif-nueva-ronda-stats .notif-stats-label { font-weight: 700; font-size: 16px; color: #333; }
        #notification-container .notification-card-nueva-ronda .notif-nueva-ronda-stats .notif-stats-value { font-size: 16px; font-weight: 700; }
        #notification-container .notification-card-nueva-ronda .notification-card-actions { justify-content: center; }
        .notif-list-nueva-ronda { border: 2px solid #dc2626; border-radius: 8px; padding: 12px; text-align: center; }
        .notif-list-nueva-ronda .notif-list-ronda { font-size: 1.2rem; margin-bottom: 6px; }
        .notif-list-nueva-ronda .notif-list-mesa { font-size: 1.2rem; font-weight: 700; margin-bottom: 6px; }
        .notif-list-nueva-ronda .notif-list-atleta,
        .notif-list-nueva-ronda .notif-list-pareja { margin-bottom: 4px; }
        .notif-list-nueva-ronda .notif-list-stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); grid-template-rows: auto auto; gap: 2px 8px; justify-items: center; text-align: center; margin: 8px auto; }
        .notif-list-nueva-ronda .notif-list-stats-grid .notif-stats-label { font-size: 16px; }
        .notif-list-nueva-ronda .notif-list-stats-grid .notif-stats-value { font-size: 16px; font-weight: 700; }
        .notif-list-invitacion-formal { border: 2px solid #dc2626; border-radius: 8px; padding: 12px; text-align: center; }
        .notif-list-invitacion-formal .notif-list-invitacion-org { font-size: 1.2rem; margin-bottom: 6px; }
        .notif-list-invitacion-formal .notif-list-invitacion-torneo { font-size: 1.2rem; font-weight: 700; margin-bottom: 6px; }
    </style>
</head>
<body>
    <div id="notification-container" aria-live="polite"></div>
    <!-- Navbar -->
    <nav class="navbar navbar-portal navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?= $base_url ?>/public/user_portal.php">
                <?php 
                $logo_url = AppHelpers::getAppLogo();
                ?>
                <img src="<?= htmlspecialchars($logo_url) ?>" alt="La Estación del Dominó" class="me-2" style="height: 35px;">
                Portal del Jugador
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-md-inline">
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($user['nombre'] ?? $user['username']) ?>
                </span>
                <a href="?section=notificaciones" class="btn btn-outline-light btn-sm position-relative me-2" id="campana-link" title="Notificaciones" data-pendientes="<?= (int)$notif_pendientes_count ?>">
                    <i class="fas fa-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="campana-badge" style="display: <?= $notif_pendientes_count > 0 ? 'block' : 'none' ?>;"><?= $notif_pendientes_count > 99 ? '99+' : (int)$notif_pendientes_count ?></span>
                </a>
                <a href="<?= $base_url ?>/public/logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Salir
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="sidebar text-center">
                    <?php if ($photo_url): ?>
                        <img src="<?= htmlspecialchars($photo_url) ?>" alt="Foto" class="user-photo">
                    <?php else: ?>
                        <div class="user-photo-placeholder mx-auto">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                    <h6 class="mb-1"><?= htmlspecialchars($user['nombre'] ?? $user['username']) ?></h6>
                    <small class="text-muted"><?= htmlspecialchars($user['cedula'] ?? '') ?></small>
                    
                    <hr>
                    
                    <nav class="nav flex-column text-start">
                        <a class="nav-link <?= $section === 'inicio' ? 'active' : '' ?>" href="?section=inicio">
                            <i class="fas fa-home me-2"></i>Inicio
                        </a>
                        <a class="nav-link <?= $section === 'torneos' ? 'active' : '' ?>" href="?section=torneos">
                            <i class="fas fa-trophy me-2"></i>Torneos Programados
                        </a>
                        <a class="nav-link <?= $section === 'resultados' ? 'active' : '' ?>" href="?section=resultados">
                            <i class="fas fa-medal me-2"></i>Resultados
                        </a>
                        <a class="nav-link <?= $section === 'ranking' ? 'active' : '' ?>" href="?section=ranking">
                            <i class="fas fa-chart-line me-2"></i>Ranking
                        </a>
                        <a class="nav-link <?= $section === 'clubes' ? 'active' : '' ?>" href="?section=clubes">
                            <i class="fas fa-building me-2"></i>Clubes
                        </a>
                        <a class="nav-link <?= $section === 'mis_torneos' ? 'active' : '' ?>" href="?section=mis_torneos">
                            <i class="fas fa-clipboard-list me-2"></i>Mis Inscripciones
                        </a>
                        <a class="nav-link <?= $section === 'notificaciones' ? 'active' : '' ?>" href="?section=notificaciones">
                            <i class="fas fa-bell me-2"></i>Mis Notificaciones
                        </a>
                        <hr>
                        <a class="nav-link <?= $section === 'perfil' ? 'active' : '' ?>" href="?section=perfil">
                            <i class="fas fa-user-cog me-2"></i>Mi Perfil
                        </a>
                        <a class="nav-link" href="<?= htmlspecialchars(rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : $base_url, '/') . '/profile.php') ?>">
                            <i class="fas fa-user-edit me-2"></i>Perfil completo (panel)
                        </a>
                        <a class="nav-link <?= $section === 'credencial' ? 'active' : '' ?>" href="?section=credencial">
                            <i class="fas fa-id-badge me-2"></i>Mi Credencial
                        </a>
                        <hr>
                        <a class="nav-link" href="<?= htmlspecialchars(rtrim($base_url, '/') . '/manuales_web/manual_usuario.php#usuario-comun') ?>">
                            <i class="fas fa-book me-2"></i>Manual de Usuario
                            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Content -->
            <div class="col-lg-9">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($section === 'inicio'): ?>
                <!-- INICIO -->
                <div class="user-welcome">
                    <h4 class="mb-1"><i class="fas fa-hand-wave me-2"></i>¡Bienvenido, <?= htmlspecialchars($user['nombre'] ?? $user['username']) ?>!</h4>
                    <p class="mb-0 opacity-75">Explora torneos, consulta resultados y mantente al día con el dominó.</p>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <div class="stat-number"><?= count($torneos_programados) ?></div>
                            <div class="stat-label">Torneos Próximos</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #c53030 0%, #9b2c2c 100%);">
                            <div class="stat-number"><?= count($mis_inscripciones) ?></div>
                            <div class="stat-label">Mis Participaciones</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);">
                            <div class="stat-number"><?= count($clubes) ?></div>
                            <div class="stat-label">Clubes Activos</div>
                        </div>
                    </div>
                </div>
                
                <div class="content-card">
                    <h4><i class="fas fa-calendar-alt me-2"></i>Próximos Torneos</h4>
                    <?php if (empty($torneos_programados)): ?>
                        <p class="text-muted text-center py-4">No hay torneos programados</p>
                    <?php else: ?>
                        <?php foreach (array_slice($torneos_programados, 0, 5) as $t): ?>
                            <div class="tournament-card d-flex align-items-center">
                                <div class="date me-3">
                                    <div class="day"><?= date('d', strtotime($t['fechator'])) ?></div>
                                    <div class="month"><?= date('M', strtotime($t['fechator'])) ?></div>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?= htmlspecialchars($t['nombre']) ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($t['lugar'] ?? 'Por definir') ?>
                                        <span class="mx-2">|</span>
                                        <i class="fas fa-building me-1"></i><?= htmlspecialchars($t['organizacion_nombre'] ?? 'N/A') ?>
                                    </small>
                                </div>
                                <span class="badge bg-primary badge-modalidad"><?= $modalidades[$t['modalidad']] ?? 'N/A' ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="?section=torneos" class="btn btn-outline-primary btn-sm">Ver todos los torneos</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($section === 'torneos'): ?>
                <!-- TORNEOS PROGRAMADOS -->
                <div class="content-card">
                    <h4><i class="fas fa-trophy me-2"></i>Torneos Programados</h4>
                    <?php if (empty($torneos_programados)): ?>
                        <p class="text-muted text-center py-4">No hay torneos programados actualmente</p>
                    <?php else: ?>
                        <?php foreach ($torneos_programados as $t): ?>
                            <div class="tournament-card d-flex align-items-center">
                                <div class="date me-3">
                                    <div class="day"><?= date('d', strtotime($t['fechator'])) ?></div>
                                    <div class="month"><?= date('M', strtotime($t['fechator'])) ?></div>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?= htmlspecialchars($t['nombre']) ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($t['lugar'] ?? 'Por definir') ?>
                                        <span class="mx-2">|</span>
                                        <i class="fas fa-building me-1"></i><?= htmlspecialchars($t['organizacion_nombre'] ?? 'N/A') ?>
                                        <?php if ($t['costo'] > 0): ?>
                                            <span class="mx-2">|</span>
                                            <i class="fas fa-dollar-sign me-1"></i><?= number_format($t['costo'], 2) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary badge-modalidad mb-1"><?= $modalidades[$t['modalidad']] ?? 'N/A' ?></span><br>
                                    <span class="badge bg-secondary badge-modalidad"><?= $clases[$t['clase']] ?? 'N/A' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($section === 'resultados'): ?>
                <!-- RESULTADOS -->
                <div class="content-card">
                    <h4><i class="fas fa-medal me-2"></i>Torneos Finalizados</h4>
                    <?php if (empty($torneos_resultados)): ?>
                        <p class="text-muted text-center py-4">No hay resultados disponibles</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Torneo</th>
                                        <th>Club</th>
                                        <th>Modalidad</th>
                                        <th>Participantes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($torneos_resultados as $t): ?>
                                        <tr>
                                            <td><?= date('d/m/Y', strtotime($t['fechator'])) ?></td>
                                            <td><strong><?= htmlspecialchars($t['nombre']) ?></strong></td>
                                            <td><?= htmlspecialchars($t['organizacion_nombre'] ?? 'N/A') ?></td>
                                            <td><span class="badge bg-primary"><?= $modalidades[$t['modalidad']] ?? 'N/A' ?></span></td>
                                            <td><span class="badge bg-secondary"><?= $t['total_inscritos'] ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($section === 'ranking'): ?>
                <!-- RANKING -->
                <div class="content-card">
                    <h4><i class="fas fa-chart-line me-2"></i>Ranking de Jugadores</h4>
                    <p class="text-muted mb-4">Basado en el número de participaciones en torneos</p>
                    <?php if (empty($ranking)): ?>
                        <p class="text-muted text-center py-4">No hay datos de ranking disponibles</p>
                    <?php else: ?>
                        <?php foreach ($ranking as $index => $r): ?>
                            <?php
                            $pos_class = 'normal';
                            if ($index === 0) $pos_class = 'gold';
                            elseif ($index === 1) $pos_class = 'silver';
                            elseif ($index === 2) $pos_class = 'bronze';
                            ?>
                            <div class="ranking-item">
                                <div class="ranking-position <?= $pos_class ?>"><?= $index + 1 ?></div>
                                <div class="flex-grow-1">
                                    <strong><?= htmlspecialchars($r['nombre']) ?></strong>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-success"><?= $r['participaciones'] ?> participaciones</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($section === 'clubes'): ?>
                <!-- CLUBES -->
                <div class="content-card">
                    <h4><i class="fas fa-building me-2"></i>Clubes Disponibles</h4>
                    <?php if (empty($clubes)): ?>
                        <p class="text-muted text-center py-4">No hay clubes registrados</p>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($clubes as $c): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="club-card">
                                            <div class="club-icon">
                                                <?php 
                                                $logo_url = AppHelpers::getAppLogo();
                                                ?>
                                                <img src="<?= htmlspecialchars($logo_url) ?>" alt="La Estación del Dominó" style="height: 40px;">
                                            </div>
                                            <h6 class="mb-2"><?= htmlspecialchars($c['nombre']) ?></h6>
                                        <?php if (!empty($c['delegado'])): ?>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($c['delegado']) ?>
                                            </small>
                                        <?php endif; ?>
                                        <?php if (!empty($c['telefono'])): ?>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-phone me-1"></i><?= htmlspecialchars($c['telefono']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($section === 'mis_torneos'): ?>
                <!-- MIS INSCRIPCIONES -->
                <div class="content-card">
                    <h4><i class="fas fa-clipboard-list me-2"></i>Mis Inscripciones</h4>
                    <?php if (empty($mis_inscripciones)): ?>
                        <p class="text-muted text-center py-4">No tienes inscripciones registradas</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Torneo</th>
                                        <th>Club</th>
                                        <th>Lugar</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mis_inscripciones as $i): ?>
                                        <tr>
                                            <td><?= date('d/m/Y', strtotime($i['fechator'])) ?></td>
                                            <td><strong><?= htmlspecialchars($i['torneo_nombre']) ?></strong></td>
                                            <td><?= htmlspecialchars($i['organizacion_nombre'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($i['lugar'] ?? '-') ?></td>
                                            <td>
                                                <?php if ($i['estatus'] == 1 || $i['estatus'] === 'confirmado'): ?>
                                                    <span class="badge bg-success">Confirmado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pendiente</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($section === 'perfil'): ?>
                <!-- PERFIL -->
                <div class="content-card">
                    <h4><i class="fas fa-user-cog me-2"></i>Mi Perfil</h4>
                    
                    <div class="row">
                        <!-- Foto de perfil -->
                        <div class="col-md-4 text-center mb-4">
                            <div class="profile-photo-container">
                                <?php if ($photo_url): ?>
                                    <img src="<?= htmlspecialchars($photo_url) ?>" alt="Foto" class="profile-photo">
                                <?php else: ?>
                                    <div class="profile-photo-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <label for="photo-input" class="photo-upload-btn">
                                    <i class="fas fa-camera"></i>
                                </label>
                            </div>
                            <form method="POST" enctype="multipart/form-data" id="photo-form">
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                <input type="hidden" name="action" value="upload_photo">
                                <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none" data-preview-target="portal-photo-preview">
                                <div id="portal-photo-preview"></div>
                                <div class="mt-2">
                                    <button type="submit" class="btn btn-sm btn-primary">Guardar foto</button>
                                </div>
                            </form>
                            <small class="text-muted">Clic en el ícono para cambiar foto</small>
                        </div>
                        
                        <!-- Información personal -->
                        <div class="col-md-8">
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <strong><i class="fas fa-id-card me-2"></i>Información Personal</strong>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                        <input type="hidden" name="action" value="update_profile">
                                        <input type="hidden" name="telegram_chat_id" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>">
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Nombre</label>
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['nombre'] ?? '') ?>" disabled>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Cédula</label>
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['cedula'] ?? '') ?>" disabled>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Usuario</label>
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Celular</label>
                                            <input type="text" name="celular" class="form-control" value="<?= htmlspecialchars($user['celular'] ?? '') ?>">
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Guardar Cambios
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <?php
                            $has_telegram_col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
                            if ($has_telegram_col):
                            ?>
                            <!-- Vincular Telegram -->
                            <div class="card mb-4 border-primary" id="telegram">
                                <div class="card-header text-white" style="background: linear-gradient(135deg, #0088cc 0%, #229ED9 100%);">
                                    <strong><i class="fab fa-telegram-plane me-2"></i>Recibe notificaciones por Telegram</strong>
                                    <?php if ($tiene_telegram): ?>
                                        <span class="badge bg-success ms-2"><i class="fas fa-check me-1"></i>Vinculado</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <p class="mb-3"><strong>Ventajas de vincular Telegram:</strong></p>
                                    <ul class="mb-3">
                                        <li><i class="fas fa-bolt text-warning me-2"></i>Recibe al instante cuando se publique una nueva ronda</li>
                                        <li><i class="fas fa-mobile-alt text-info me-2"></i>Mensajes directos en tu celular, sin entrar a la web</li>
                                        <li><i class="fas fa-bell text-primary me-2"></i>Avisos de torneos, inscripciones y resultados</li>
                                        <li><i class="fas fa-shield-alt text-success me-2"></i>Es gratis y seguro</li>
                                    </ul>
                                    <p class="mb-2"><strong>Instrucciones (3 pasos, 2 minutos):</strong></p>
                                    <ol class="mb-3">
                                        <li><strong>Paso 1:</strong> Abre Telegram en tu celular. <?php if ($telegram_bot_link): ?>
                                            <a href="<?= htmlspecialchars($telegram_bot_link) ?>" class="btn btn-sm btn-outline-primary ms-1"><i class="fab fa-telegram-plane me-1"></i>Abrir bot</a> y envía <code>/start</code>
                                        <?php else: ?>
                                            Busca el bot de notificaciones del sistema y envía <code>/start</code>
                                        <?php endif; ?>
                                        </li>
                                        <li><strong>Paso 2:</strong> Busca <a href="https://t.me/userinfobot">@userinfobot</a> en Telegram, inicia conversación y copia el número <strong>Id</strong> que te muestra.</li>
                                        <li><strong>Paso 3:</strong> Pega ese número abajo y haz clic en Guardar.</li>
                                    </ol>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                        <input type="hidden" name="action" value="update_profile">
                                        <input type="hidden" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                                        <input type="hidden" name="celular" value="<?= htmlspecialchars($user['celular'] ?? '') ?>">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-6">
                                                <label class="form-label">Telegram Chat ID</label>
                                                <input type="text" name="telegram_chat_id" class="form-control" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>" placeholder="Ej: 123456789">
                                            </div>
                                            <div class="col-md-4">
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="fas fa-save me-1"></i>Guardar
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Cambiar contraseña -->
                    <div class="card">
                        <div class="card-header bg-light">
                            <strong><i class="fas fa-key me-2"></i>Cambiar Contraseña</strong>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row">
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                <input type="hidden" name="action" value="change_password">
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Contraseña Actual</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Nueva Contraseña</label>
                                    <input type="password" name="new_password" class="form-control" required>
                                    <small class="text-muted">Mínimo 6 caracteres</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Confirmar Nueva</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-key me-1"></i>Cambiar Contraseña
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($section === 'credencial'): ?>
                <!-- CREDENCIAL -->
                <div class="content-card">
                    <h4><i class="fas fa-id-badge me-2"></i>Mi Credencial Digital</h4>
                    
                    <div class="row justify-content-center">
                        <div class="col-md-6 mb-4">
                            <!-- Vista previa de credencial -->
                            <div class="credential-card" id="credential-preview">
                                <?php if ($photo_url): ?>
                                    <img src="<?= htmlspecialchars($photo_url) ?>" alt="Foto" class="credential-photo">
                                <?php else: ?>
                                    <div class="credential-photo d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.2);">
                                        <i class="fas fa-user fa-2x"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="credential-name"><?= htmlspecialchars($user['nombre'] ?? $user['username']) ?></div>
                                <div class="text-white-50"><?= htmlspecialchars($user['cedula'] ?? '') ?></div>
                                <div class="credential-id"><?= htmlspecialchars($user['uuid']) ?></div>
                                <div class="mt-3">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode($user['uuid']) ?>" 
                                         alt="QR Code" style="border-radius: 8px; background: white; padding: 5px;">
                                </div>
                                <div class="mt-2 text-white-50" style="font-size: 0.75rem;">
                                    La Estación del Dominó
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <!-- Identificador único -->
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <strong><i class="fas fa-fingerprint me-2"></i>Identificador Único</strong>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">Este es tu identificador único en el sistema. Puedes usarlo para identificarte en torneos.</p>
                                    <div class="uuid-display mb-3">
                                        <?= htmlspecialchars($user['uuid']) ?>
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="copyUUID()">
                                        <i class="fas fa-copy me-1"></i>Copiar
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Acciones -->
                            <div class="card">
                                <div class="card-header bg-light">
                                    <strong><i class="fas fa-cogs me-2"></i>Opciones</strong>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a href="?section=credencial&download=1" class="btn btn-primary">
                                            <i class="fas fa-download me-2"></i>Descargar Credencial
                                        </a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                            <input type="hidden" name="action" value="regenerate_uuid">
                                            <button type="submit" class="btn btn-outline-warning w-100" 
                                                    onclick="return confirm('¿Está seguro de regenerar su identificador? El anterior dejará de ser válido.')">
                                                <i class="fas fa-sync-alt me-2"></i>Regenerar Identificador
                                            </button>
                                        </form>
                                    </div>
                                    <small class="text-muted d-block mt-3">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Regenerar el identificador invalidará el anterior. Use esta opción solo si es necesario.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif ($section === 'notificaciones'): ?>
                <!-- NOTIFICACIONES -->
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h4 class="mb-1"><i class="fas fa-bell me-2"></i>Mis Notificaciones</h4>
                            <p class="text-muted mb-0">Mensajes y avisos del sistema<?= count($notificaciones_portal) > 0 ? ' · ' . count($notificaciones_portal) . ' mensaje(s)' : '' ?></p>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary btn-sm" id="portal-btn-probar-notif" title="Ver tarjeta toast"><i class="fas fa-flask me-1"></i>Probar notificación</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="portal-btn-probar-nueva-ronda" title="Ver tarjeta Nueva Ronda"><i class="fas fa-trophy me-1"></i>Probar Nueva Ronda</button>
                        </div>
                    </div>
                    
                    <?php if (!$tiene_telegram && $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0): ?>
                    <!-- Invitación especial: Vincular Telegram -->
                    <div class="alert alert-info border-0 mb-4" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);">
                        <div class="d-flex align-items-start">
                            <div class="me-3 fs-2"><i class="fab fa-telegram-plane text-primary"></i></div>
                            <div class="flex-grow-1">
                                <h5 class="alert-heading mb-2"><i class="fas fa-magic me-1"></i>¿Quieres recibir notificaciones en tu celular?</h5>
                                <p class="mb-2">Vincula tu cuenta con Telegram y recibe al instante avisos de nuevas rondas, torneos y resultados, sin tener que entrar a la web.</p>
                                <p class="mb-2"><strong>Es gratis, seguro y toma solo 2 minutos.</strong></p>
                                <a href="?section=perfil#telegram" class="btn btn-primary">
                                    <i class="fab fa-telegram-plane me-1"></i>Vincular Telegram ahora
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($notificaciones_portal)): ?>
                        <p class="text-muted"><i class="fas fa-inbox me-2"></i>No tienes notificaciones.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($notificaciones_portal as $n):
                                $datosP = (!empty($n['datos_json']) ? @json_decode($n['datos_json'], true) : null);
                                $esNuevaRondaP = $datosP && isset($datosP['tipo']) && $datosP['tipo'] === 'nueva_ronda';
                                $esResultadosMesaP = $datosP && isset($datosP['tipo']) && $datosP['tipo'] === 'resultados_mesa';
                                $esInvitacionFormalP = $datosP && isset($datosP['tipo']) && $datosP['tipo'] === 'invitacion_torneo_formal';
                            ?>
                                <li class="list-group-item">
                                    <?php if ($esResultadosMesaP): ?>
                                        <div class="notif-list-nueva-ronda">
                                            <div class="notif-list-ronda text-center fw-bold text-primary">RESULTADOS RONDA <?= htmlspecialchars($datosP['ronda'] ?? '—') ?> · MESA <?= htmlspecialchars($datosP['mesa'] ?? '—') ?></div>
                                            <div class="notif-list-atleta text-center">Atleta: <?= (int)($datosP['usuario_id'] ?? 0) ?> <?= htmlspecialchars($datosP['nombre'] ?? '') ?></div>
                                            <div class="notif-list-mesa text-center">Usted ha <?= htmlspecialchars($datosP['resultado_texto'] ?? '—') ?>.</div>
                                            <div class="notif-list-pareja text-center">Resultado: <?= htmlspecialchars($datosP['resultado1'] ?? '0') ?> a <?= htmlspecialchars($datosP['resultado2'] ?? '0') ?></div>
                                            <?php if (!empty($datosP['sancion']) && (int)$datosP['sancion'] > 0 || !empty($datosP['tarjeta_texto'])): ?>
                                                <div class="notif-list-pareja text-center text-warning">
                                                    <?php
                                                    $partes = [];
                                                    if (!empty($datosP['sancion']) && (int)$datosP['sancion'] > 0) $partes[] = 'Sancionado con ' . (int)$datosP['sancion'] . ' pts';
                                                    if (!empty($datosP['tarjeta_texto'])) $partes[] = 'Tarjeta ' . htmlspecialchars($datosP['tarjeta_texto']);
                                                    echo implode(' y ', $partes);
                                                    ?>.
                                                </div>
                                            <?php endif; ?>
                                            <div class="notif-list-pareja text-center small text-muted">Si no está conforme notifique a mesa técnica.</div>
                                            <div class="mt-2 text-center">
                                                <?php
                                                $urlResP = isset($datosP['url_resumen']) ? trim($datosP['url_resumen']) : '';
                                                $urlClaP = isset($datosP['url_clasificacion']) ? trim($datosP['url_clasificacion']) : '';
                                                $pre = rtrim($base_url, '/');
                                                $uRes = ltrim($urlResP, '/');
                                                $uCla = ltrim($urlClaP, '/');
                                                $hrefRes = ($urlResP !== '' && $urlResP !== '#') ? (strpos($urlResP, 'http') === 0 ? $urlResP : $pre . (strpos($uRes, 'public/') === 0 ? '/' : '/public/') . $uRes) : '';
                                                $hrefCla = ($urlClaP !== '' && $urlClaP !== '#') ? (strpos($urlClaP, 'http') === 0 ? $urlClaP : $pre . (strpos($uCla, 'public/') === 0 ? '/' : '/public/') . $uCla) : '';
                                                if ($hrefRes !== ''): ?>
                                                    <a href="<?= htmlspecialchars($hrefRes) ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-user-chart me-1"></i>Resumen jugador</a>
                                                <?php endif;
                                                if ($hrefCla !== ''): ?>
                                                    <a href="<?= htmlspecialchars($hrefCla) ?>" class="btn btn-sm btn-secondary"><i class="fas fa-list-ol me-1"></i>Listado de clasificación</a>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                        </div>
                                    <?php elseif ($esInvitacionFormalP): ?>
                                        <div class="notif-list-invitacion-formal">
                                            <div class="notif-list-invitacion-org text-center fw-bold text-primary"><?= htmlspecialchars($datosP['organizacion_nombre'] ?? 'Invitación a Torneo') ?></div>
                                            <div class="notif-list-invitacion-saludo text-center"><?= htmlspecialchars($datosP['tratamiento'] ?? 'Estimado/a') ?> <?= htmlspecialchars($datosP['nombre'] ?? '') ?></div>
                                            <div class="notif-list-invitacion-torneo text-center fw-bold"><?= htmlspecialchars($datosP['torneo'] ?? '') ?></div>
                                            <div class="notif-list-invitacion-lugar text-center"><?= htmlspecialchars($datosP['lugar_torneo'] ?? '') ?> · <?= htmlspecialchars($datosP['fecha_torneo'] ?? '') ?></div>
                                            <div class="mt-2 text-center">
                                                <?php
                                                $urlInscP = isset($datosP['url_inscripcion']) ? trim($datosP['url_inscripcion']) : ($n['url_destino'] ?? '#');
                                                $pre = rtrim($base_url, '/');
                                                $uInsc = ltrim($urlInscP, '/');
                                                $hrefInscP = ($urlInscP !== '' && $urlInscP !== '#') ? (strpos($urlInscP, 'http') === 0 ? $urlInscP : $pre . (strpos($uInsc, 'public/') === 0 ? '/' : '/public/') . $uInsc) : '';
                                                if ($hrefInscP !== ''): ?>
                                                    <a href="<?= htmlspecialchars($hrefInscP) ?>" class="btn btn-sm btn-primary"><i class="fas fa-pen-fancy me-1"></i>Inscribirse en línea</a>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                        </div>
                                    <?php elseif ($esNuevaRondaP): ?>
                                        <div class="notif-list-nueva-ronda">
                                            <div class="notif-list-ronda text-center fw-bold text-primary">RONDA <?= htmlspecialchars($datosP['ronda'] ?? '—') ?></div>
                                            <div class="notif-list-atleta text-center">Atleta: <?= (int)($datosP['usuario_id'] ?? 0) ?> <?= htmlspecialchars($datosP['nombre'] ?? '') ?></div>
                                            <div class="notif-list-mesa text-center">Juega en Mesa: <?= htmlspecialchars($datosP['mesa'] ?? '—') ?></div>
                                            <div class="notif-list-pareja text-center" title="Compañero de juego del atleta, inscrito con el mismo número de mesa y letra.">Pareja: <?= (int)($datosP['pareja_id'] ?? 0) ?> <?= htmlspecialchars($datosP['pareja_nombre'] ?? $datosP['pareja'] ?? '—') ?></div>
                                            <div class="notif-list-stats-grid text-center text-muted small">
                                                <span class="notif-stats-label fw-bold">Pos.</span>
                                                <span class="notif-stats-label fw-bold">Gana</span>
                                                <span class="notif-stats-label fw-bold">Perdi</span>
                                                <span class="notif-stats-label fw-bold">Efect</span>
                                                <span class="notif-stats-label fw-bold">Ptos</span>
                                                <span class="notif-stats-value"><?= htmlspecialchars($datosP['posicion'] ?? '0') ?></span>
                                                <span class="notif-stats-value"><?= htmlspecialchars($datosP['ganados'] ?? '0') ?></span>
                                                <span class="notif-stats-value"><?= htmlspecialchars($datosP['perdidos'] ?? '0') ?></span>
                                                <span class="notif-stats-value"><?= htmlspecialchars($datosP['efectividad'] ?? '0') ?></span>
                                                <span class="notif-stats-value"><?= htmlspecialchars($datosP['puntos'] ?? '0') ?></span>
                                            </div>
                                            <div class="mt-2 text-center">
                                                <?php
                                                $urlResP = isset($datosP['url_resumen']) ? trim($datosP['url_resumen']) : '';
                                                $urlClaP = isset($datosP['url_clasificacion']) ? trim($datosP['url_clasificacion']) : '';
                                                $pre = rtrim($base_url, '/');
                                                $uRes = ltrim($urlResP, '/');
                                                $uCla = ltrim($urlClaP, '/');
                                                $hrefRes = ($urlResP !== '' && $urlResP !== '#') ? (strpos($urlResP, 'http') === 0 ? $urlResP : $pre . (strpos($uRes, 'public/') === 0 ? '/' : '/public/') . $uRes) : '';
                                                $hrefCla = ($urlClaP !== '' && $urlClaP !== '#') ? (strpos($urlClaP, 'http') === 0 ? $urlClaP : $pre . (strpos($uCla, 'public/') === 0 ? '/' : '/public/') . $uCla) : '';
                                                if ($hrefRes !== ''): ?>
                                                    <a href="<?= htmlspecialchars($hrefRes) ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-user-chart me-1"></i>Resumen jugador</a>
                                                <?php endif;
                                                if ($hrefCla !== ''): ?>
                                                    <a href="<?= htmlspecialchars($hrefCla) ?>" class="btn btn-sm btn-secondary"><i class="fas fa-list-ol me-1"></i>Listado de clasificación</a>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="ms-2 me-auto">
                                                <div class="fw-normal"><?= htmlspecialchars($n['mensaje']) ?></div>
                                                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                            </div>
                                            <?php if (!empty($n['url_destino']) && $n['url_destino'] !== '#'): ?>
                                                <a href="<?= htmlspecialchars($n['url_destino']) ?>" class="btn btn-sm btn-outline-primary">Ver</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <script>
                document.getElementById('portal-btn-probar-notif') && document.getElementById('portal-btn-probar-notif').addEventListener('click', function() {
                    if (typeof enviarNotificacion === 'function') enviarNotificacion('Pago Confirmado', 'Tu recibo #1234 ha sido generado.', { showPdf: true });
                    else alert('Recarga la página para probar.');
                });
                document.getElementById('portal-btn-probar-nueva-ronda') && document.getElementById('portal-btn-probar-nueva-ronda').addEventListener('click', function() {
                    if (typeof enviarNotificacion === 'function') enviarNotificacion('', '', { datosEstructurados: { tipo: 'nueva_ronda', ronda: '3', mesa: '5', usuario_id: 135, nombre: 'Alberto López', ganados: '2', perdidos: '1', efectividad: '66.7', puntos: '120', url_resumen: '#', url_clasificacion: '#' } });
                    else alert('Recarga la página para probar.');
                });
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <!-- jsPDF: carga lazy al hacer clic en "Descargar PDF" (notifications-toast.js) -->
    <script src="assets/image-preview.js" defer></script>
    <script>window.notifAjaxUrl = '<?= htmlspecialchars(AppHelpers::url('notificaciones_ajax.php')) ?>'; window.APP_BASE_URL = '<?= htmlspecialchars(rtrim(AppHelpers::getBaseUrl(), "/")) ?>';</script>
    <script src="assets/notifications-toast.js" defer></script>
    <script>
    function copyUUID() {
        const uuid = '<?= htmlspecialchars($user['uuid']) ?>';
        navigator.clipboard.writeText(uuid).then(() => {
            alert('Identificador copiado al portapapeles');
        });
    }
    if (typeof actualizarCampanitaYToast === 'function') {
        actualizarCampanitaYToast();
        setInterval(actualizarCampanitaYToast, 60000); // 60 segundos para reducir carga
    }
    </script>
</body>
</html>
<?php
// Descargar credencial como imagen
if (isset($_GET['download']) && $_GET['download'] == '1') {
    // Redirigir a un endpoint de generación de credencial
    header('Location: generate_credential.php?user_id=' . Auth::id());
    exit;
}
?>
