<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/persona_database.php';

// Obtener parámetros de la URL
$token = $_GET['token'] ?? '';
$torneo_id = $_GET['torneo'] ?? '';
$club_id = $_GET['club'] ?? '';

$error_message = '';
$success_message = '';
$invitation_data = null;
$tournament_data = null;
$club_data = null;

// Validar invitación
if (empty($token) || empty($torneo_id) || empty($club_id)) {
    $error_message = "Parámetros de acceso inválidos";
} else {
    try {
        // Verificar invitación v�lida (sin restricción de estado por ahora)
        $stmt = DB::pdo()->prepare("
            SELECT i.*, t.nombre as tournament_name, t.fechator, t.clase, t.modalidad,
                   c.nombre as club_name, c.direccion, c.delegado, c.telefono, c.email
            FROM invitations i 
            LEFT JOIN tournaments t ON i.torneo_id = t.id 
            LEFT JOIN clubes c ON i.club_id = c.id 
            WHERE i.token = ? AND i.torneo_id = ? AND i.club_id = ?
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
                    'email' => $invitation_data['email']
                ];
                
                // Guardar datos de la invitación en sesión
                $_SESSION['invitation_data'] = $invitation_data;
                $_SESSION['tournament_data'] = $tournament_data;
                $_SESSION['club_data'] = $club_data;
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al validar invitación: " . $e->getMessage();
    }
}

// Procesar autenticación del club
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'club_login') {
    header('Content-Type: application/json');
    
    $club_password = $_POST['club_password'] ?? '';
    
    if (empty($club_password)) {
        echo json_encode(['success' => false, 'message' => 'Contraseña requerida']);
        exit;
    }
    
    if (!$club_data) {
        echo json_encode(['success' => false, 'message' => 'Invitación no válida']);
        exit;
    }
    
    try {
        // Verificar contraseña del club (usando el email del club como contraseña por ahora)
        if ($club_password === $club_data['email']) {
            $_SESSION['club_authenticated'] = true;
            $_SESSION['authenticated_club_id'] = $club_data['id'];
            echo json_encode(['success' => true, 'message' => 'Autenticación exitosa']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Procesar logout del club
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'club_logout') {
    unset($_SESSION['club_authenticated']);
    unset($_SESSION['authenticated_club_id']);
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Procesar búsqueda por cédula (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_cedula') {
    header('Content-Type: application/json');
    
    try {
        $cedula = $_POST['cedula'] ?? '';
        
        if (empty($cedula)) {
            echo json_encode(['success' => false, 'message' => 'Cédula requerida']);
            exit;
        }
        
        // TODO: Aqu• se implementar• el procedimiento de búsqueda en base de datos externa
        // Por ahora, simularemos una respuesta
        $external_data = searchExternalDatabase($cedula);
        
        if ($external_data) {
            echo json_encode([
                'success' => true, 
                'data' => $external_data
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'No se encontraron datos para esta cédula'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error en la búsqueda: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Procesar formulario de inscripción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error_message && !isset($_POST['action'])) {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    if (!RateLimiter::canSubmit('register_inscription', 15)) {
        $error_message = 'Por favor espera 15 segundos antes de enviar otra inscripcion.';
    } else {
    CSRF::validate();
    try {
        $nacionalidad = $_POST['nacionalidad'] ?? '';
        $cedula = $_POST['cedula'] ?? '';
        $nombre = $_POST['nombre'] ?? '';
        $sexo = $_POST['sexo'] ?? '';
        $fechnac = $_POST['fechnac'] ?? '';
        $identificador = $_POST['identificador'] ?? '';
        $estatus = $_POST['estatus'] ?? '';
        $categ = $_POST['categ'] ?? '';
        $celular = $_POST['celular'] ?? '';
        $email = $_POST['email'] ?? '';
        
        if (empty($cedula) || empty($nombre) || empty($celular)) {
            throw new Exception('Los campos cédula, nombre y celular son requeridos');
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El email no es válido');
        }
        
        // Verificar si ya existe una inscripcion para esta cedula en este torneo
        $stmt = DB::pdo()->prepare("
            SELECT id FROM inscripciones 
            WHERE cedula = ? AND torneo_id = ?
        ");
        $stmt->execute([$cedula, $torneo_id]);
        
        if ($stmt->fetch()) {
            throw new Exception('Ya existe una inscripción para esta cédula en este torneo');
        }
        
        // Insertar inscripción con los campos exactos solicitados
        $stmt = DB::pdo()->prepare("
            INSERT INTO inscripciones (
                torneo_id, cedula, nombre, celular, email, club_id, 
                fecha_inscripcion, nacionalidad, sexo, fechnac, 
                identificador, estatus, categoria
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $torneo_id, $cedula, $nombre, $celular, $email, $club_id,
            $nacionalidad, $sexo, $fechnac, $identificador, $estatus, $categ
        ]);
        
        $success_message = "Inscripcion realizada exitosamente";
        RateLimiter::recordSubmit('register_inscription');
        // Limpiar formulario y mensajes antiguos
        $_POST = [];
        $error_message = '';
        
    } catch (Exception $e) {
        $error_message = "Error al realizar inscripción: " . $e->getMessage();
    }
    }
}

// Procesar retiro de jugador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'retirar_jugador') {
    CSRF::validate();
    try {
        $jugador_id = $_POST['jugador_id'] ?? '';
        
        if (empty($jugador_id)) {
            throw new Exception('ID de jugador requerido');
        }
        
        // Verificar que el jugador pertenece al club autenticado
        $stmt = DB::pdo()->prepare("
            SELECT r.id, r.nombre, r.cedula 
            FROM inscripciones r 
            WHERE r.id = ? AND r.club_id = ? AND r.torneo_id = ?
        ");
        $stmt->execute([$jugador_id, $club_id, $torneo_id]);
        $jugador = $stmt->fetch();
        
        if (!$jugador) {
            throw new Exception('Jugador no encontrado o no autorizado para retirar');
        }
        
        // Eliminar el jugador
        $stmt = DB::pdo()->prepare("DELETE FROM inscripciones WHERE id = ?");
        $stmt->execute([$jugador_id]);
        
        $success_message = "Jugador {$jugador['nombre']} (cédula: {$jugador['cedula']}) retirado exitosamente del torneo";
        
        // Limpiar mensajes antiguos después del éxito
        $error_message = '';
        
    } catch (Exception $e) {
        $error_message = "Error al retirar jugador: " . $e->getMessage();
        $success_message = '';
    }
}

// Función para buscar en base de datos externa
function searchExternalDatabase($cedula) {
    try {
        $database = new PersonaDatabase();
        $result = $database->searchPersonaById($cedula);
        
        return $result['success'] ? $result['data'] : null;
        
    } catch (Exception $e) {
        error_log("Error searching external database: " . $e->getMessage());
        return null;
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
}

// Obtener inscripciones existentes del club para este torneo
$existing_registrations = [];
if ($invitation_data && !$error_message && $club_authenticated) {
    try {
        $stmt = DB::pdo()->prepare("
            SELECT cedula, nombre, sexo, fechnac, identificador, estatus, categoria, celular, fecha_inscripcion
            FROM inscripciones 
            WHERE torneo_id = ? AND club_id = ? 
            ORDER BY fecha_inscripcion DESC
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
    <title>Inscripción de Jugadores - <?= htmlspecialchars($tournament_data['nombre'] ?? 'Torneo') ?></title>
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
        .status-badge {
            font-size: 0.8rem;
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
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
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
                    <div class="card mb-4">
                        <div class="card-body text-center py-4">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <?php 
                                    // Logo del club organizador (desde el torneo)
                                    $stmt = DB::pdo()->prepare("SELECT logo FROM clubes WHERE id = (SELECT club_responsable FROM tournaments WHERE id = ?)");
                                    $stmt->execute([$torneo_id]);
                                    $organizer_logo = $stmt->fetchColumn();
                                    ?>
                                    <?php if ($organizer_logo): ?>
                                        <?php $organizer_logo_url = ImageHelper::getImageUrl($organizer_logo); ?>
                                        <img src="<?= htmlspecialchars($organizer_logo_url) ?>" 
                                             alt="Club Organizador" 
                                             class="img-fluid"
                                             style="max-height: 120px; max-width: 150px; object-fit: contain;">
                                    <?php else: ?>
                                        <div class="bg-light border rounded d-flex align-items-center justify-content-center" style="height: 120px; width: 150px; margin: 0 auto;">
                                            <i class="fas fa-building text-muted fs-1"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 text-center">
                                    <h1 class="display-5 fw-bold text-primary mb-3">
                                        <i class="fas fa-trophy me-3"></i>
                                        <?= htmlspecialchars($tournament_data['nombre']) ?>
                                    </h1>
                                    
                                    <h2 class="h3 text-success mb-2">
                                        <?= htmlspecialchars($club_data['nombre']) ?>
                                    </h2>
                                    
                                    <p class="lead text-muted mb-0">
                                        <i class="fas fa-quote-left me-2"></i>
                                        Fue invitado a celebrar con nosotros nuestro magno evento aniversario
                                        <i class="fas fa-quote-right ms-2"></i>
                                    </p>
                                </div>
                                
                                <div class="col-md-3">
                                    <?php 
                                    // Logo del club invitado
                                    $stmt = DB::pdo()->prepare("SELECT logo FROM clubes WHERE id = ?");
                                    $stmt->execute([$club_id]);
                                    $invited_logo = $stmt->fetchColumn();
                                    ?>
                                    <?php if ($invited_logo): ?>
                                        <?php $invited_logo_url = ImageHelper::getImageUrl($invited_logo); ?>
                                        <img src="<?= htmlspecialchars($invited_logo_url) ?>" 
                                             alt="Club Invitado" 
                                             class="img-fluid"
                                             style="max-height: 120px; max-width: 150px; object-fit: contain;">
                                    <?php else: ?>
                                        <div class="bg-light border rounded d-flex align-items-center justify-content-center" style="height: 120px; width: 150px; margin: 0 auto;">
                                            <i class="fas fa-building text-muted fs-1"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-calendar-alt me-2"></i>
                                        <strong>Per�odo de Inscripción:</strong> 
                                        Desde <?= date('d/m/Y H:i', strtotime($invitation_data['acceso1'])) ?> 
                                        hasta <?= date('d/m/Y H:i', strtotime($invitation_data['acceso2'])) ?>
                                    </div>
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
                                Autenticación del Club
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Club:</strong> <?= htmlspecialchars($club_data['nombre']) ?><br>
                                <strong>Para acceder al formulario de inscripciones, debe autenticarse con la contraseña del club.</strong>
                            </div>
                            
                            <form id="clubAuthForm">
                                <div class="row">
                                    <div class="col-md-8">
                                        <label for="club_password" class="form-label">Contraseña del Club</label>
                                        <div class="input-group">
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="club_password" 
                                                   placeholder="Ingrese la contraseña del club"
                                                   required>
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    onclick="togglePasswordVisibility()">
                                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Use el email del club como contraseña: <strong><?= htmlspecialchars($club_data['email']) ?></strong></small>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-warning w-100">
                                            <i class="fas fa-sign-in-alt me-2"></i>Autenticar
                                        </button>
                                    </div>
                                </div>
                            </form>
                            
                            <div id="authMessage" class="mt-3"></div>
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

                    <!-- Formulario de Inscripción -->
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
                                <div class="alert alert-success alert-dismissible fade show" role="alert" aria-live="polite">
                                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <?php if ($error_message): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert" aria-live="assertive">
                                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

            <!-- Formulario de registro con ancho total -->
            <div class="card">
                <div class="card-body">
                    <form method="POST" id="registrationForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="torneo_id" value="<?= htmlspecialchars($torneo_id) ?>">
                        <input type="hidden" name="club_id" value="<?= htmlspecialchars($club_id) ?>">
                        
                    <div class="row g-2">
                            <!-- Nacionalidad -->
                            <div class="col-auto">
                                <label for="nacionalidad" class="form-label">Nac.</label>
                                <select class="form-select form-select-sm" id="nacionalidad" name="nacionalidad" required style="width: 60px;">
                                    <option value="">-</option>
                                    <option value="V" <?= ($_POST['nacionalidad'] ?? '') == 'V' ? 'selected' : '' ?>>V</option>
                                    <option value="E" <?= ($_POST['nacionalidad'] ?? '') == 'E' ? 'selected' : '' ?>>E</option>
                                </select>
                            </div>
                            
                            <!-- Cédula -->
                            <div class="col-auto">
                                <label for="cedula" class="form-label">Cédula</label>
                                <input type="text" 
                                       class="form-control form-control-sm" 
                                       id="cedula" 
                                       name="cedula" 
                                       value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>"
                                       placeholder="12345678"
                                       maxlength="9"
                                       required
                                       autocomplete="off"
                                       onblur="debouncedSearchPersona()"
                                       aria-required="true"
                                       style="width: 100px;">
                                <small class="text-muted">Auto-búsqueda</small>
                            </div>
                            
                            <!-- Nombre -->
                            <div class="col-auto">
                                <label for="nombre" class="form-label">Nombre Completo</label>
                                <input type="text" 
                                       class="form-control form-control-sm" 
                                       id="nombre" 
                                       name="nombre" 
                                       value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                                       required
                                       autocomplete="name"
                                       aria-required="true"
                                       style="width: 200px;">
                            </div>
                            
                            <!-- Sexo -->
                            <div class="col-auto">
                                <label for="sexo" class="form-label">Sexo</label>
                                <select class="form-select form-select-sm" id="sexo" name="sexo" required style="width: 60px;">
                                    <option value="">-</option>
                                    <option value="M" <?= ($_POST['sexo'] ?? '') == 'M' ? 'selected' : '' ?>>M</option>
                                    <option value="F" <?= ($_POST['sexo'] ?? '') == 'F' ? 'selected' : '' ?>>F</option>
                                </select>
                            </div>
                            
                            <!-- Fecha de Nacimiento -->
                            <div class="col-auto">
                                <label for="fechnac" class="form-label">F. Nac.</label>
                                <input type="date" 
                                       class="form-control form-control-sm" 
                                       id="fechnac" 
                                       name="fechnac" 
                                       value="<?= htmlspecialchars($_POST['fechnac'] ?? '') ?>"
                                       style="width: 140px;">
                            </div>
                            
                            <!-- Teléfono -->
                            <div class="col-auto">
                                <label for="celular" class="form-label">Teléfono</label>
                                <input type="tel" 
                                       class="form-control form-control-sm" 
                                       id="celular" 
                                       name="celular" 
                                       value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>"
                                       placeholder="0424-1234567"
                                       required
                                       autocomplete="tel"
                                       aria-required="true"
                                       style="width: 220px;">
                            </div>
                            
                            <!-- Email -->
                            <div class="col-auto">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" 
                                       class="form-control form-control-sm" 
                                       id="email" 
                                       name="email" 
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                       placeholder="correo@ejemplo.com"
                                       autocomplete="email"
                                       style="width: 280px;">
                            </div>
                            
                            <!-- Botones -->
                            <div class="col-auto">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-1">
                                    <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitRegister">
                                        <i class="fas fa-user-plus"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="clearForm()">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Campos ocultos para compatibilidad -->
                            <input type="hidden" name="identificador" value="">
                            <input type="hidden" name="estatus" value="Activo">
                            <input type="hidden" name="categ" value="0">
                        </div>
                    </form>
                </div>
            </div>

            <!-- Listado de inscritos debajo -->
            <div class="card mt-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Jugadores Inscritos (<?= count($existing_registrations) ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($existing_registrations)): ?>
                        <div class="p-3 text-center text-muted">
                            <i class="fas fa-user-slash fa-2x mb-2"></i>
                            <p>No hay jugadores inscritos a�n</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 10%;">Cédula</th>
                                        <th style="width: 30%;">Nombre</th>
                                        <th style="width: 15%;">Sexo</th>
                                        <th style="width: 15%;">F. Nac.</th>
                                        <th style="width: 15%;">Teléfono</th>
                                        <th style="width: 10%;">N• Asignado</th>
                                        <th style="width: 5%;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($existing_registrations as $index => $registration): ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($registration['cedula']) ?></small>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($registration['nombre']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= htmlspecialchars($registration['sexo'] ?: 'N/A') ?></span>
                                            </td>
                                            <td>
                                                <small><?= $registration['fechnac'] ? date('d/m/Y', strtotime($registration['fechnac'])) : 'N/A' ?></small>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($registration['celular'] ?: 'N/A') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?= $index + 1 ?></span>
                                            </td>
                                            <td>
                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm" 
                                                        onclick="retirarJugador(<?= $registration['id'] ?? $index ?>, '<?= htmlspecialchars($registration['nombre']) ?>')"
                                                        title="Retirar jugador">
                                                    <i class="fas fa-user-times"></i>
                                                </button>
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
                    </div>

                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= AppHelpers::getPublicPath() ?>assets/form-utils.js" defer></script>
    
    <script>
        // Autenticación del Club
        document.getElementById('clubAuthForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const password = document.getElementById('club_password').value;
            const messageDiv = document.getElementById('authMessage');
            
            if (!password) {
                showAuthMessage('Por favor ingrese la contraseña', 'danger');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'club_login');
                formData.append('club_password', password);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAuthMessage('Autenticación exitosa. Redirigiendo...', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAuthMessage(result.message || 'Error en la autenticación', 'danger');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showAuthMessage('Error de conexión', 'danger');
            }
        });
        
        function showAuthMessage(message, type) {
            const messageDiv = document.getElementById('authMessage');
            messageDiv.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
        
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('club_password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
    </script>
    
    <script>
        // Función para buscar persona por cédula
        async function searchPersona() {
            const cedula = document.getElementById('cedula').value.trim();
            const nacionalidad = document.getElementById('nacionalidad').value;
            
            if (!cedula || !nacionalidad) {
                return;
            }
            
            // Construir ID de usuario (nacionalidad + cédula)
            const idusuario = nacionalidad + cedula;
            
            try {
                // Mostrar indicador de carga
                showLoadingIndicator();
                
                // Buscar en la base de datos externa
                const response = await fetch(apiUrl(`search_persona.php?idusuario=${encodeURIComponent(idusuario)}`));
                const result = await response.json();
                
                if (result.success && result.data) {
                    // Llenar campos automáticamente
                    document.getElementById('nombre').value = result.data.nombre || '';
                    document.getElementById('sexo').value = result.data.sexo || '';
                    document.getElementById('fechnac').value = result.data.fechnac || '';
                    
                    // Verificar si ya existe en el sistema
                    await checkExistingCedula(cedula);
                    
                } else {
                    showMessage('No se encontraron datos para esta cédula', 'info');
                }
                
            } catch (error) {
                console.error('Error en la búsqueda:', error);
                showMessage('Error al buscar datos de la cédula', 'danger');
            } finally {
                hideLoadingIndicator();
            }
        }
        
        // Función para verificar si la cédula ya existe
        async function checkExistingCedula(cedula) {
            try {
                const response = await fetch(apiUrl(`check_cedula.php?cedula=${encodeURIComponent(cedula)}&torneo=<?= $torneo_id ?>`));
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
        
        // Función para mostrar indicador de carga
        function showLoadingIndicator() {
            const cedulaInput = document.getElementById('cedula');
            cedulaInput.style.backgroundImage = 'url("data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' view=\'0 0 12 12\' width=\'12\' height=\'12\' fill=\'none\' stroke=\'%23dc3545\'%3e%3ccircle cx=\'6\' cy=\'6\' r=\'4.5\'/%3e%3cpath d=\'M6 3v6\'/%3e%3cpath d=\'m8.25 4.75-4.5 4.5\'/%3e%3c/svg%3e")';
            cedulaInput.style.backgroundRepeat = 'no-repeat';
            cedulaInput.style.backgroundPosition = 'right 12px center';
            cedulaInput.style.backgroundSize = '16px 16px';
        }
        
        // Función para ocultar indicador de carga
        function hideLoadingIndicator() {
            const cedulaInput = document.getElementById('cedula');
            cedulaInput.style.backgroundImage = '';
        }
        
        // Función para mostrar mensajes
        function showMessage(message, type) {
            // Crear alerta temporal
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '9999';
            alertDiv.style.minWidth = '300px';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alertDiv);
            
            // Auto-remover después de 5 segundos
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 5000);
        }
        
        // Función para limpiar formulario
        function clearForm() {
            if (confirm('¿Estás seguro de que deseas limpiar todos los campos del formulario?')) {
                document.getElementById('registrationForm').reset();
                document.getElementById('nacionalidad').value = '';
            }
        }
        
        const debouncedSearchPersona = typeof debounce === 'function' ? debounce(searchPersona, 400) : searchPersona;
        
        // Actualizar prefijo de cédula según nacionalidad
        document.getElementById('nacionalidad').addEventListener('change', function() {
            const prefix = this.value === 'E' ? 'E-' : 'V-';
            const el = document.getElementById('cedulaPrefix'); if (el) el.textContent = prefix;
        });
        
        // Validar solo números en cédula
        document.getElementById('cedula').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Validar formato de celular
        document.getElementById('celular').addEventListener('input', function() {
            let value = this.value.replace(/[^0-9-]/g, '');
            // Formato: 0424-1234567
            if (value.length > 4 && value.indexOf('-') === -1) {
                value = value.substring(0, 4) + '-' + value.substring(4);
            }
            this.value = value;
        });
        
        // Función para retirar jugador
        function retirarJugador(jugadorId, nombreJugador) {
            if (confirm(`¿Está seguro de que desea retirar a ${nombreJugador} del torneo?`)) {
                // Crear formulario para enviar la solicitud de retiro
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'retirar_jugador';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'jugador_id';
                idInput.value = jugadorId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Limpiar mensajes automáticamente después de 5 segundos
        document.addEventListener('DOMContentLoaded', function() {
            const regForm = document.getElementById('registrationForm');
            if (regForm && typeof preventDoubleSubmit === 'function') preventDoubleSubmit(regForm);
            if (typeof initCedulaValidation === 'function') initCedulaValidation('cedula');
            if (typeof initEmailValidation === 'function') initEmailValidation('email');
            setTimeout(function() {
                const alertElements = document.querySelectorAll('.alert');
                alertElements.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>
