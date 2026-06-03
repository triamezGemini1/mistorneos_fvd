<?php
/**
 * Login por invitación (token). Patrón en bloque: conexión única; sin requireAuth (acceso por token).
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';

// Obtener parámetros de la URL de invitación
$token = $_GET['token'] ?? '';
$torneo_id = $_GET['torneo'] ?? '';
$club_id = $_GET['club'] ?? '';

$error_message = '';
$invitation_data = null;
$club_data = null;
$tournament_data = null;

// Validar parámetros de invitación
if (empty($token) || empty($torneo_id) || empty($club_id)) {
    // Si no hay token, redirigir automáticamente al sistema simple (sin token)
    if (empty($token) && !empty($torneo_id) && !empty($club_id)) {
        header("Location: simple_invitation_login.php?torneo=" . urlencode($torneo_id) . "&club=" . urlencode($club_id));
        exit;
    }
    $error_message = "Parámetros de invitación inválidos";
} else {
    try {
        // Verificar invitación v�lida
        $stmt = DB::pdo()->prepare("
            SELECT i.*, t.nombre as tournament_name, t.fechator, t.clase, t.modalidad,
                   c.nombre as club_name, c.direccion, c.delegado, c.telefono, c.email, c.logo as club_logo
            FROM invitations i 
            LEFT JOIN tournaments t ON i.torneo_id = t.id 
            LEFT JOIN clubes c ON i.club_id = c.id 
            WHERE i.token = ? AND i.torneo_id = ? AND i.club_id = ? AND i.estado = 'activa'
        ");
        $stmt->execute([$token, $torneo_id, $club_id]);
        $invitation_data = $stmt->fetch();
        
        if (!$invitation_data) {
            $error_message = "Invitación no v�lida o expirada";
        } else {
            // Verificar fechas de acceso
            $now = new DateTime();
            $start_date = new DateTime($invitation_data['acceso1']);
            $end_date = new DateTime($invitation_data['acceso2']);
            
            if ($now < $start_date) {
                $error_message = "El per�odo de inscripción a�n no ha comenzado";
            } elseif ($now > $end_date) {
                $error_message = "El per�odo de inscripción ha expirado";
            } else {
                // Preparar datos para mostrar
                $club_data = [
                    'id' => $invitation_data['club_id'],
                    'nombre' => $invitation_data['club_name'],
                    'direccion' => $invitation_data['direccion'],
                    'delegado' => $invitation_data['delegado'],
                    'telefono' => $invitation_data['telefono'],
                    'email' => $invitation_data['email'],
                    'logo' => $invitation_data['club_logo']
                ];
                
                $tournament_data = [
                    'id' => $invitation_data['torneo_id'],
                    'nombre' => $invitation_data['tournament_name'],
                    'fechator' => $invitation_data['fechator'],
                    'clase' => $invitation_data['clase'],
                    'modalidad' => $invitation_data['modalidad']
                ];
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al validar invitación: " . $e->getMessage();
    }
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error_message) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error_message = "Usuario y contraseña son requeridos";
    } else {
        try {
            // Verificar credenciales del usuario invitado
            $stmt = DB::pdo()->prepare("
                SELECT u.*, c.nombre as club_name 
                FROM usuarios u 
                LEFT JOIN clubes c ON u.email = c.email 
                WHERE u.username = ? AND u.role = 'admin_club'
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            require_once __DIR__ . '/../lib/security.php';
            
            // Usar autenticación centralizada para admin_club
            $authenticatedUser = Security::authenticateClubAdmin($username, $password, $club_data['email']);
            
            if ($authenticatedUser) {
                // Autenticar usuario
                $_SESSION['user'] = [
                    'id' => $authenticatedUser['id'],
                    'username' => $authenticatedUser['username'],
                    'role' => $authenticatedUser['role'],
                    'email' => $authenticatedUser['email'],
                    'club_id' => $authenticatedUser['club_id'],
                    'organizacion_id' => defined('ORGANIZACION_ID') ? ORGANIZACION_ID : 1,
                ];
                if (class_exists('FvdConfig', false)) {
                    FvdConfig::anchorSession();
                }
                session_regenerate_id(true);
                
                // Redirigir al formulario de inscripción independiente
                $redirect_url = AppHelpers::url("invitation_register_standalone.php?torneo=") . urlencode($torneo_id) . "&club=" . urlencode($club_id);
                header('Location: ' . $redirect_url);
                exit;
            } else {
                $error_message = "Usuario o contraseña incorrectos";
            }
        } catch (Exception $e) {
            $error_message = "Error en la autenticación: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema - <?= htmlspecialchars($tournament_data['nombre'] ?? 'Torneo') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        .header-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 120px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }
        .logo-container img {
            max-height: 100px;
            max-width: 150px;
            object-fit: contain;
        }
        .invitation-text {
            font-size: 1.1rem;
            color: #6c757d;
            font-style: italic;
        }
        .tournament-name {
            font-size: 1.8rem;
            font-weight: bold;
            color: #495057;
            margin: 1rem 0;
        }
        .credentials-box {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .credential-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #dee2e6;
        }
        .credential-item:last-child {
            border-bottom: none;
        }
        .credential-label {
            font-weight: bold;
            color: #495057;
        }
        .credential-value {
            font-family: monospace;
            background: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 5px;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <?php if ($error_message): ?>
                    <!-- Error -->
                    <div class="card">
                        <div class="card-header text-center">
                            <h3 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error de Acceso
                            </h3>
                        </div>
                        <div class="card-body text-center py-5">
                            <i class="fas fa-lock text-danger fs-1 mb-3"></i>
                            <h5 class="text-danger"><?= htmlspecialchars($error_message) ?></h5>
                            <p class="text-muted">Por favor, verifica que tienes acceso válido a esta página.</p>
                            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Volver
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Header con Información del Torneo -->
                    <div class="header-section p-4">
                        <div class="row align-items-center">
                            <!-- Logo del club invitado -->
                            <div class="col-md-3">
                                <div class="logo-container">
                                    <?php if ($club_data && $club_data['logo']): ?>
                                        <img src="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::imageUrl($club_data['logo']) : $club_data['logo']) ?>"
                                             alt="Club Invitado"
                                             class="img-fluid">
                                    <?php else: ?>
                                        <i class="fas fa-building text-muted fs-1"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Información central -->
                            <div class="col-md-6 text-center">
                                <h2 class="h3 text-primary mb-2">
                                    <?= htmlspecialchars($club_data['nombre']) ?>
                                </h2>
                                
                                <p class="invitation-text mb-3">
                                    Acceso al sistema de inscripciones para:
                                </p>
                                
                                <h1 class="tournament-name text-success">
                                    <?= htmlspecialchars($tournament_data['nombre']) ?>
                                </h1>
                                
                                <div class="mt-3">
                                    <span class="badge bg-info fs-6">
                                        <i class="fas fa-calendar-alt me-2"></i>
                                        <?= date('d/m/Y', strtotime($tournament_data['fechator'])) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Información del per�odo -->
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h6 class="text-muted">Per�odo de Inscripción</h6>
                                    <small class="text-muted">
                                        Desde: <?= date('d/m/Y', strtotime($invitation_data['acceso1'])) ?><br>
                                        Hasta: <?= date('d/m/Y', strtotime($invitation_data['acceso2'])) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario de Login -->
                    <div class="card">
                        <div class="card-header text-center">
                            <h3 class="mb-0">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                Acceso al Sistema
                            </h3>
                        </div>
                        <div class="card-body">
                            <!-- Información del club -->
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Club:</strong> <?= htmlspecialchars($club_data['nombre']) ?><br>
                                <strong>Delegado:</strong> <?= htmlspecialchars($club_data['delegado']) ?><br>
                                <strong>Teléfono:</strong> <?= htmlspecialchars($club_data['telefono']) ?>
                            </div>
                            
                            <!-- Credenciales de acceso -->
                            <div class="credentials-box">
                                <h6 class="text-center mb-3">
                                    <i class="fas fa-key me-2"></i>
                                    Credenciales de Acceso
                                </h6>
                                <div class="credential-item">
                                    <span class="credential-label">Usuario:</span>
                                    <span class="credential-value"><?= htmlspecialchars($invitation_data['usuario']) ?></span>
                                </div>
                                <div class="credential-item">
                                    <span class="credential-label">Contraseña:</span>
                                    <span class="credential-value">usuario</span>
                                </div>
                            </div>
                            
                            <!-- Formulario de login -->
                            <form method="POST" id="loginForm">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="username" class="form-label">Usuario</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="username" 
                                               name="username" 
                                               value="<?= htmlspecialchars($invitation_data['usuario']) ?>"
                                               placeholder="Ingrese el usuario del club"
                                               required>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label for="password" class="form-label">Contraseña</label>
                                        <div class="input-group">
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="password" 
                                                   name="password" 
                                                   value="usuario"
                                                   placeholder="Ingrese la contraseña"
                                                   required>
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    onclick="togglePasswordVisibility()">
                                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-sign-in-alt me-2"></i>Acceder al Sistema de Inscripciones
                                    </button>
                                </div>
                            </form>
                            
                            <!-- Información adicional -->
                            <div class="mt-4 text-center">
                                <small class="text-muted">
                                    <i class="fas fa-shield-alt me-1"></i>
                                    Despu�s de autenticarse, ser• redirigido al formulario de inscripción de jugadores.
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    
    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
        
        // Auto-focus en el campo de contraseña si el usuario ya est• pre-cargado
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            
            if (usernameInput.value && passwordInput.value) {
                passwordInput.focus();
                passwordInput.select();
            }
        });
    </script>
</body>
</html>
