<?php
/**
 * Inscripción por invitación (token). Patrón en bloque: conexión única; sin requireAuth (acceso por token).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';

// Obtener parámetros de la URL
$token = $_GET['token'] ?? '';
$torneo_id = $_GET['torneo'] ?? '';
$club_id = $_GET['club'] ?? '';

$error_message = '';
$success_message = '';
$invitation_data = null;
$tournament_data = null;
$club_data = null;
$organizer_club_data = null;

// Validar invitación
if (empty($token) || empty($torneo_id) || empty($club_id)) {
    $error_message = "Parámetros de acceso inválidos";
} else {
    try {
        // Verificar invitación v�lida
        $stmt = DB::pdo()->prepare("
            SELECT i.*, t.nombre as tournament_name, t.fechator, t.clase, t.modalidad, t.club_responsable,
                   c.nombre as club_name, c.direccion, c.delegado, c.telefono, c.email, c.logo as club_logo
            FROM invitations i 
            LEFT JOIN tournaments t ON i.torneo_id = t.id 
            LEFT JOIN clubes c ON i.club_id = c.id 
            WHERE i.token = ? AND i.torneo_id = ? AND i.club_id = ? AND i.estado = 'activa'
        ");
        $stmt->execute([$token, $torneo_id, $club_id]);
        $invitation_data = $stmt->fetch();
        
        if (!$invitation_data) {
            $error_message = "Invitación no v�lida";
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
                // Obtener datos del club organizador
                $stmt_organizer = DB::pdo()->prepare("
                    SELECT nombre, logo, direccion, delegado, telefono, email 
                    FROM clubes 
                    WHERE id = ?
                ");
                $stmt_organizer->execute([$invitation_data['club_responsable']]);
                $organizer_club_data = $stmt_organizer->fetch();
                
                $tournament_data = [
                    'id' => $invitation_data['torneo_id'],
                    'nombre' => $invitation_data['tournament_name'],
                    'fechator' => $invitation_data['fechator'],
                    'clase' => $invitation_data['clase'],
                    'modalidad' => $invitation_data['modalidad']
                ];
                
                $club_data = [
                    'id' => $invitation_data['club_id'],
                    'nombre' => $invitation_data['club_name'],
                    'direccion' => $invitation_data['direccion'],
                    'delegado' => $invitation_data['delegado'],
                    'telefono' => $invitation_data['telefono'],
                    'email' => $invitation_data['email'],
                    'logo' => $invitation_data['club_logo']
                ];
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al validar invitación: " . $e->getMessage();
    }
}

// Procesar autenticación del club
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'club_login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error_message = "Usuario y contraseña son requeridos";
    } else {
        try {
            // Verificar credenciales del usuario invitado
            $stmt = DB::pdo()->prepare("
                SELECT u.*, c.nombre as club_name 
                FROM users u 
                LEFT JOIN clubes c ON u.email = c.email 
                WHERE u.username = ? AND u.role = 'admin_club'
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            require_once __DIR__ . '/../lib/security.php';
            
            // Usar autenticación centralizada para admin_club
            $authenticatedUser = Security::authenticateClubAdmin($username, $password, $club_data['email']);
            
            if ($authenticatedUser) {
                $_SESSION['club_authenticated'] = true;
                $_SESSION['authenticated_club_id'] = $club_data['id'];
                $_SESSION['authenticated_username'] = $username;
                $success_message = "Autenticación exitosa";
            } else {
                $error_message = "Usuario o contraseña incorrectos";
            }
        } catch (Exception $e) {
            $error_message = "Error en la autenticación: " . $e->getMessage();
        }
    }
}

// Procesar logout del club
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'club_logout') {
    unset($_SESSION['club_authenticated']);
    unset($_SESSION['authenticated_club_id']);
    unset($_SESSION['authenticated_username']);
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Procesar formulario de inscripción de jugador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_player') {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    if (!RateLimiter::canSubmit('invitation_register', 15)) {
        $error_message = 'Por favor espera 15 segundos antes de inscribir otro jugador.';
    } else {
    CSRF::validate();
    try {
        $cedula = trim($_POST['cedula'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $sexo = trim($_POST['sexo'] ?? '');
        $fechnac = trim($_POST['fechnac'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Limpiar cédula: remover nacionalidad si viene pegada (V12345678 ? 12345678)
        $cedula = preg_replace('/^[VEJP]/', '', $cedula);
        
        if (empty($cedula) || empty($nombre) || empty($sexo) || empty($telefono)) {
            throw new Exception('Los campos cédula, nombre, sexo y teléfono son requeridos');
        }
        
        // Verificar si ya existe una inscripción para esta cédula en este torneo
        $stmt = DB::pdo()->prepare("
            SELECT id FROM inscripciones 
            WHERE cedula = ? AND torneo_id = ?
        ");
        $stmt->execute([$cedula, $torneo_id]);
        
        if ($stmt->fetch()) {
            throw new Exception('Ya existe una inscripción para esta cédula en este torneo');
        }
        
        // Insertar inscripción (solo números en cédula, sin nacionalidad)
        $stmt = DB::pdo()->prepare("
            INSERT INTO inscripciones (
                torneo_id, cedula, nombre, sexo, fechnac, celular, email, club_id, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $torneo_id, $cedula, $nombre, $sexo, $fechnac, $telefono, $email, $club_id
        ]);
        
        $success_message = "Jugador inscrito exitosamente";
        RateLimiter::recordSubmit('invitation_register');
        
        // Limpiar formulario
        $_POST = [];
        
    } catch (Exception $e) {
        $error_message = "Error al inscribir jugador: " . $e->getMessage();
    }
    }
}

// Verificar si el club est• autenticado
$club_authenticated = isset($_SESSION['club_authenticated']) && $_SESSION['club_authenticated'] === true;
$authenticated_club_id = $_SESSION['authenticated_club_id'] ?? null;

// Verificar que el club autenticado coincida con el club de la invitación
if ($club_authenticated && $authenticated_club_id != $club_id) {
    $club_authenticated = false;
    unset($_SESSION['club_authenticated']);
    unset($_SESSION['authenticated_club_id']);
    unset($_SESSION['authenticated_username']);
}

// Obtener inscripciones existentes del club para este torneo
$existing_registrations = [];
if ($invitation_data && !$error_message && $club_authenticated) {
    try {
        $stmt = DB::pdo()->prepare("
            SELECT cedula, nombre, sexo, celular, email, created_at
            FROM inscripciones 
            WHERE torneo_id = ? AND club_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$torneo_id, $club_id]);
        $existing_registrations = $stmt->fetchAll();
    } catch (Exception $e) {
        // Error al obtener inscripciones existentes
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Jugadores - <?= htmlspecialchars($tournament_data['nombre'] ?? 'Torneo') ?></title>
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
    </style>
    
    <!-- App Configuration -->
    <script>
        window.APP_CONFIG = {
            publicPath: '<?= AppHelpers::getPublicPath() ?>',
            apiPath: '<?= AppHelpers::getPublicPath() ?>api/',
            isProduction: <?= AppHelpers::isProduction() ? 'true' : 'false' ?>
        };
    </script>
    <script src="<?= AppHelpers::getPublicPath() ?>assets/app-config.js" defer></script>
    <script src="<?= AppHelpers::getPublicPath() ?>assets/form-utils.js" defer></script>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8 col-xl-7">
                <?php if ($error_message): ?>
                    <!-- Error -->
                    <div class="card">
                        <div class="card-header text-center">
                            <h3 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Acceso Denegado
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
                    <!-- Header con Logos y T�tulo -->
                    <div class="header-section p-4">
                        <div class="row align-items-center">
                            <!-- Logo del club responsable (izquierda) -->
                            <div class="col-md-3">
                                <div class="logo-container">
                                    <?php if ($organizer_club_data && $organizer_club_data['logo']): ?>
                                        <img src="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::imageUrl($organizer_club_data['logo']) : $organizer_club_data['logo']) ?>"
                                             alt="Club Organizador"
                                             class="img-fluid">
                                    <?php else: ?>
                                        <i class="fas fa-building text-muted fs-1"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Información central -->
                            <div class="col-md-6 text-center">
                                <h2 class="h3 text-primary mb-2">
                                    <?= htmlspecialchars($organizer_club_data['nombre'] ?? 'Club Organizador') ?>
                                </h2>
                                
                                <p class="invitation-text mb-3">
                                    Le invitamos a compartir con nosotros este magno evento:
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
                            
                            <!-- Logo del club invitado (derecha) -->
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
                        </div>
                        
                        <!-- Per�odo de inscripción -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-clock me-2"></i>
                                    <strong>Per�odo de Inscripción:</strong> 
                                    Desde <?= date('d/m/Y H:i', strtotime($invitation_data['acceso1'])) ?> 
                                    hasta <?= date('d/m/Y H:i', strtotime($invitation_data['acceso2'])) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario de Autenticación del Club -->
                    <?php if (!$club_authenticated): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h4 class="mb-0">
                                <i class="fas fa-lock me-2"></i>
                                Acceso al Sistema de Registro
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Club:</strong> <?= htmlspecialchars($club_data['nombre']) ?><br>
                                <strong>Para acceder al formulario de inscripciones, debe autenticarse con las credenciales del club.</strong>
                            </div>
                            
                            <form method="POST" id="clubAuthForm">
                                <input type="hidden" name="action" value="club_login">
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="username" class="form-label">Usuario</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="username" 
                                               name="username" 
                                               placeholder="Ingrese el usuario del club"
                                               required>
                                        <small class="text-muted">Usuario: <strong><?= htmlspecialchars($invitation_data['usuario']) ?></strong></small>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="password" class="form-label">Contraseña</label>
                                        <div class="input-group">
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="password" 
                                                   name="password" 
                                                   placeholder="Ingrese la contraseña"
                                                   required>
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    onclick="togglePasswordVisibility()">
                                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Contraseña: <strong>usuario</strong></small>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-sign-in-alt me-2"></i>Acceder al Sistema
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Panel de Control del Club Autenticado -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h4 class="mb-0">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Club Autenticado
                                </h4>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="club_logout">
                                    <button type="submit" class="btn btn-outline-light btn-sm">
                                        <i class="fas fa-sign-out-alt me-1"></i>Cerrar Sesión
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-success">
                                <i class="fas fa-user-check me-2"></i>
                                <strong>Bienvenido:</strong> <?= htmlspecialchars($club_data['nombre']) ?><br>
                                <strong>Delegado:</strong> <?= htmlspecialchars($club_data['delegado']) ?><br>
                                <strong>Teléfono:</strong> <?= htmlspecialchars($club_data['telefono']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Formulario de Inscripción de Jugadores -->
                    <?php if ($club_authenticated): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4 class="mb-0">
                                <i class="fas fa-user-plus me-2"></i>
                                Inscribir Jugador
                            </h4>
                        </div>
                        <div class="card-body">
                            <?php if ($success_message): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <?php if ($error_message): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST" id="registrationForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                                <input type="hidden" name="action" value="register_player">
                                <input type="hidden" name="torneo_id" value="<?= htmlspecialchars($torneo_id) ?>">
                                <input type="hidden" name="club_id" value="<?= htmlspecialchars($club_id) ?>">
                                
                                <div class="row g-3">
                                    <!-- Nacionalidad -->
                                    <div class="col-md-6">
                                        <label for="nacionalidad" class="form-label">Nacionalidad <span class="text-danger">*</span></label>
                                        <select class="form-select" id="nacionalidad" name="nacionalidad" required>
                                            <option value="">Seleccionar nacionalidad...</option>
                                            <option value="V" <?= ($_POST['nacionalidad'] ?? '') == 'V' ? 'selected' : '' ?>>Venezolano (V)</option>
                                            <option value="E" <?= ($_POST['nacionalidad'] ?? '') == 'E' ? 'selected' : '' ?>>Extranjero (E)</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Cédula -->
                                    <div class="col-md-6">
                                        <label for="cedula" class="form-label">Cédula <span class="text-danger">*</span></label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="cedula" 
                                               name="cedula" 
                                               value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>"
                                               placeholder="Ingrese: 12345678 o V12345678"
                                               maxlength="9"
                                               pattern="[VEJP]?[0-9]+"
                                               onblur="debouncedSearchPersona()"
                                               required>
                                        <small class="text-muted">Al salir del campo se buscar�n automáticamente los datos (acepta V, E, J, P + número)</small>
                                    </div>
                                    
                                    <!-- Nombre -->
                                    <div class="col-md-6">
                                        <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="nombre" 
                                               name="nombre" 
                                               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                                               required>
                                    </div>
                                    
                                    <!-- Sexo -->
                                    <div class="col-md-6">
                                        <label for="sexo" class="form-label">Sexo <span class="text-danger">*</span></label>
                                        <select class="form-select" id="sexo" name="sexo" required>
                                            <option value="">Seleccionar sexo...</option>
                                            <option value="M" <?= ($_POST['sexo'] ?? '') == 'M' ? 'selected' : '' ?>>Masculino</option>
                                            <option value="F" <?= ($_POST['sexo'] ?? '') == 'F' ? 'selected' : '' ?>>Femenino</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Fecha de Nacimiento (oculto) -->
                                    <input type="hidden" 
                                           id="fechnac" 
                                           name="fechnac" 
                                           value="<?= htmlspecialchars($_POST['fechnac'] ?? '') ?>">
                                    
                                    <!-- Teléfono -->
                                    <div class="col-md-6">
                                        <label for="telefono" class="form-label">Número de Teléfono <span class="text-danger">*</span></label>
                                        <input type="tel" 
                                               class="form-control" 
                                               id="telefono" 
                                               name="telefono" 
                                               value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>"
                                               placeholder="0424-1234567"
                                               required>
                                    </div>
                                    
                                    <!-- Email -->
                                    <div class="col-md-12">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" 
                                               class="form-control" 
                                               id="email" 
                                               name="email" 
                                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                               placeholder="ejemplo@correo.com">
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Inscribir Jugador
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="clearForm()">
                                        <i class="fas fa-eraser me-2"></i>Limpiar Formulario
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Inscripciones Existentes -->
                    <?php if (!empty($existing_registrations)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h4 class="mb-0">
                                    <i class="fas fa-list me-2"></i>
                                    Jugadores Inscritos (<?= count($existing_registrations) ?>)
                                </h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Cédula</th>
                                                <th>Nombre</th>
                                                <th>Sexo</th>
                                                <th>Teléfono</th>
                                                <th>Email</th>
                                                <th>Fecha de Inscripción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($existing_registrations as $registration): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($registration['cedula']) ?></td>
                                                    <td><?= htmlspecialchars($registration['nombre']) ?></td>
                                                    <td>
                                                        <span class="badge bg-info"><?= htmlspecialchars($registration['sexo']) ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($registration['celular']) ?></td>
                                                    <td><?= htmlspecialchars($registration['email'] ?: '-') ?></td>
                                                    <td><?= date('d/m/Y H:i', strtotime($registration['created_at'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Widget de Consulta de Credenciales con QR -->
                    <div class="mb-4">
                        <?php include __DIR__ . '/includes/credential_qr_widget.php'; ?>
                    </div>
                    
                    <?php endif; ?>
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
        
        function clearForm() {
            if (confirm('¿Estás seguro de que deseas limpiar todos los campos del formulario?')) {
                document.getElementById('registrationForm').reset();
            }
        }
        
        // Validar solo números en cédula
        const debouncedSearchPersona = typeof debounce === 'function' ? debounce(searchPersona, 400) : searchPersona;
        document.addEventListener('DOMContentLoaded', function() {
            const regForm = document.getElementById('registrationForm');
            if (regForm && typeof preventDoubleSubmit === 'function') preventDoubleSubmit(regForm);
            const cedulaEl = document.getElementById('cedula');
            if (cedulaEl && typeof initCedulaValidation === 'function') initCedulaValidation('cedula');
            const emailEl = document.getElementById('email');
            if (emailEl && typeof initEmailValidation === 'function') initEmailValidation('email');
        });
        const cedulaInput = document.getElementById('cedula');
        if (cedulaInput) cedulaInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9VEJPvejp]/g, '');
        });
        
        // Validar formato de teléfono
        document.getElementById('telefono').addEventListener('input', function() {
            let value = this.value.replace(/[^0-9-]/g, '');
            // Formato: 0424-1234567
            if (value.length > 4 && value.indexOf('-') === -1) {
                value = value.substring(0, 4) + '-' + value.substring(4);
            }
            this.value = value;
        });
        
        // Función para buscar persona por cédula
        async function searchPersona() {
            let cedula = document.getElementById('cedula')?.value.trim().toUpperCase();
            let nacionalidad = document.getElementById('nacionalidad')?.value;
            
            console.log('• searchPersona() - Cédula ingresada:', cedula, 'Nacionalidad:', nacionalidad);
            
            if (!cedula || cedula.length < 5) {
                console.log('• Cédula muy corta o vacía');
                return;
            }
            
            // Detectar si la cédula viene con nacionalidad pegada (V12345678)
            const match = cedula.match(/^([VEJP])(\d+)$/);
            if (match) {
                nacionalidad = match[1];
                cedula = match[2];
                
                // Actualizar campos
                document.getElementById('nacionalidad').value = nacionalidad;
                document.getElementById('cedula').value = cedula;
                console.log('• Nacionalidad extraída:', nacionalidad, 'Cédula:', cedula);
            }
            
            if (!nacionalidad) {
                showMessage('Por favor seleccione la nacionalidad', 'warning');
                return;
            }
            
            try {
                // Mostrar indicador de carga
                showLoadingIndicator();
                
                // Buscar en la base de datos (local y externa)
                const torneoId = '<?= (int)($torneo_id ?? 0) ?>';
                const url = apiUrl(`search_persona.php?nacionalidad=${nacionalidad}&cedula=${encodeURIComponent(cedula)}&torneo_id=${torneoId}`);
                console.log('• URL de búsqueda:', url);
                
                const response = await fetch(url);
                console.log('• Response status:', response.status);
                
                const result = await response.json();
                
                if (result.status === 'ya_inscrito') {
                    showMessage(result.mensaje || 'Ya está inscrito en este torneo. Puede iniciar una nueva inscripción.', 'info');
                    clearFormFields();
                    const nac = document.getElementById('nacionalidad');
                    if (nac) nac.focus();
                    hideLoadingIndicator();
                    return;
                }
                
                if (result.encontrado && result.persona) {
                    
                    // Llenar campos automáticamente
                    document.getElementById('nombre').value = result.persona.nombre || '';
                    document.getElementById('sexo').value = result.persona.sexo || '';
                    document.getElementById('fechnac').value = result.persona.fechnac || '';
                    
                    console.log('• Campos llenados:', {
                        nombre: document.getElementById('nombre').value,
                        sexo: document.getElementById('sexo').value,
                        fechnac: document.getElementById('fechnac').value
                    });
                    
                    // Destacar campos llenados
                    [document.getElementById('nombre'), document.getElementById('sexo'), document.getElementById('fechnac')].forEach(el => {
                        if (el && el.value) {
                            el.style.backgroundColor = '#d4edda';
                            setTimeout(() => el.style.backgroundColor = '', 2000);
                        }
                    });
                    
                    showMessage(`? Datos encontrados: ${result.persona.nombre}`, 'success');
                    
                    // Verificar si ya existe en el sistema
                    await checkExistingCedula(cedula);
                    
                } else {
                    console.log('• Persona no encontrada');
                    showMessage('No se encontraron datos para esta cédula. Complete manualmente', 'info');
                }
                
            } catch (error) {
                console.error('Error en la búsqueda:', error);
                showMessage('Error al buscar datos de la cédula', 'danger');
            } finally {
                hideLoadingIndicator();
            }
        }
        
        // Funciones auxiliares para mostrar/ocultar indicadores y mensajes
        function showLoadingIndicator() {
            // Crear o mostrar indicador de carga
            let indicator = document.getElementById('loadingIndicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.id = 'loadingIndicator';
                indicator.className = 'alert alert-info';
                indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando datos...';
                document.querySelector('.container').insertBefore(indicator, document.querySelector('.container').firstChild);
            }
            indicator.style.display = 'block';
        }
        
        function hideLoadingIndicator() {
            const indicator = document.getElementById('loadingIndicator');
            if (indicator) {
                indicator.style.display = 'none';
            }
        }
        
        function showMessage(message, type) {
            // Crear o actualizar mensaje
            let messageDiv = document.getElementById('messageDiv');
            if (!messageDiv) {
                messageDiv = document.createElement('div');
                messageDiv.id = 'messageDiv';
                messageDiv.className = 'alert';
                document.querySelector('.container').insertBefore(messageDiv, document.querySelector('.container').firstChild);
            }
            messageDiv.className = `alert alert-${type}`;
            messageDiv.textContent = message;
            messageDiv.style.display = 'block';
            
            // Ocultar mensaje después de 5 segundos
            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 5000);
        }
        
        // Función para verificar si la cédula ya existe
        async function checkExistingCedula(cedula) {
            try {
                const response = await fetch(`api/check_cedula.php?cedula=${encodeURIComponent(cedula)}&torneo=<?= $torneo_id ?>`);
                const result = await response.json();
                
                if (result.success && result.exists) {
                    showMessage(`Ya está registrado (${result.data.nombre}). Puede iniciar una nueva inscripción.`, 'info');
                    clearFormFields();
                }
            } catch (error) {
                console.error('Error verificando cédula:', error);
            }
        }
        
        // Función para limpiar campos del formulario (tras cédula ya registrada)
        function clearFormFields() {
            const nac = document.getElementById('nacionalidad');
            if (nac) nac.value = '';
            document.getElementById('cedula').value = '';
            document.getElementById('nombre').value = '';
            document.getElementById('sexo').value = '';
            document.getElementById('fechnac').value = '';
            if (nac) nac.focus();
        }
    </script>
</body>
</html>



