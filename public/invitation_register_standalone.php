<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/image_helper.php';
require_once __DIR__ . '/simple_image_config.php';

// Función helper para manejar valores que pueden ser enteros o NULL
function safe_htmlspecialchars($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value);
}

// Obtener parámetros de la URL
$token = $_GET['token'] ?? '';
$torneo_id = $_GET['torneo'] ?? '';
$club_id = $_GET['club'] ?? '';

// Procesar logout del usuario (debe ir antes de cualquier salida)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'user_logout') {
    Auth::logout();
    $login_url = AppHelpers::url("invitation_login.php?token=") . urlencode($token) . "&torneo=" . urlencode($torneo_id) . "&club=" . urlencode($club_id);
    header('Location: ' . $login_url);
    exit;
}

// Verificar si el usuario est• autenticado (viene del login de invitación)
$user_authenticated = isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin_club';

// Si no est• autenticado, redirigir al login de invitación (antes de cualquier salida)
if (!$user_authenticated) {
    // Limpiar sesión previa y redirigir a login con mensaje
    Auth::logout();
    $login_url = AppHelpers::url("invitation_login.php?token=") . urlencode($token) . "&torneo=" . urlencode($torneo_id) . "&club=" . urlencode($club_id) . "&error=requiere_autenticacion";
    header('Location: ' . $login_url);
    exit;
}

$error_message = '';
$success_message = '';
$invitation_data = null;
$tournament_data = null;
$club_data = null;
$organizer_club_data = null;

// Validar invitación
if (empty($torneo_id) || empty($club_id)) {
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
            WHERE i.torneo_id = ? AND i.club_id = ? AND i.estado = 'activa'
        ");
        $stmt->execute([$torneo_id, $club_id]);
        $invitation_data = $stmt->fetch();

        if (!$invitation_data) {
            $error_message = "Invitación no v�lida o expirada";
        } else {
            $tournament_data = $invitation_data;
            $club_data = $invitation_data;
            
            // Obtener datos del club organizador
            if ($invitation_data['club_responsable']) {
                $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id = ?");
                $stmt->execute([$invitation_data['club_responsable']]);
                $organizer_club_data = $stmt->fetch();
            }
        }
    } catch (Exception $e) {
        $error_message = "Error al validar invitación: " . $e->getMessage();
    }
}

// Procesar formulario de inscripción de jugador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_player') {
    // Verificar si el usuario est• autenticado
    $current_user = Auth::user();
    $is_admin_general = $current_user && Auth::isAdminGeneral();
    $is_admin_torneo = $current_user && $current_user['role'] === 'admin_torneo';
    $is_admin_club = $current_user && $current_user['role'] === 'admin_club';

    // Determinar si el formulario debe estar habilitado
    $form_enabled = $is_admin_general || $is_admin_torneo || $is_admin_club;

    if (!$form_enabled) {
        $error_message = "No tiene permisos para inscribir jugadores";
    } else {
        $nacionalidad = trim($_POST['nacionalidad'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $sexo = trim($_POST['sexo'] ?? '');
        $fechnac = $_POST['fechnac'] ?? '';
        $celular = trim($_POST['celular'] ?? '');
        $email = trim($_POST['email'] ?? '');

        // Validaciones básicas
        if (empty($nacionalidad) || empty($cedula) || empty($nombre)) {
            $error_message = "Los campos nacionalidad, cédula y nombre son obligatorios";
        } else {
            try {
                // Verificar si ya existe un jugador con esta cédula en este torneo
                $stmt = DB::pdo()->prepare("
                    SELECT id, nombre FROM inscripciones 
                    WHERE cedula = ? AND torneo_id = ?
                ");
                $stmt->execute([$cedula, $torneo_id]);
                $existing_player = $stmt->fetch();
                
                if ($existing_player) {
                    $error_message = "La cédula {$cedula} ya est• registrada para el jugador: " . $existing_player['nombre'];
                } else {
                    // Insertar nuevo jugador
                    $stmt = DB::pdo()->prepare("
                        INSERT INTO inscripciones (torneo_id, club_id, nombre, cedula, fechnac, celular, email, sexo, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $torneo_id, $club_id, $nombre, $cedula, $fechnac,
                        $celular, $email, $sexo
                    ]);
                    $success_message = "Jugador inscrito exitosamente";
                    
                    // Limpiar variables del formulario después de éxito
                    $nacionalidad = $cedula = $nombre = $sexo = $fechnac = $celular = $email = '';
                }
            } catch (Exception $e) {
                $error_message = "Error al inscribir jugador: " . $e->getMessage();
            }
        }
    }
}

// Procesar eliminación de jugador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_player') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    
    if ($player_id > 0) {
        try {
            $stmt = DB::pdo()->prepare("DELETE FROM inscripciones WHERE id = ? AND torneo_id = ? AND club_id = ?");
            $stmt->execute([$player_id, $torneo_id, $club_id]);
            
            if ($stmt->rowCount() > 0) {
                $success_message = "Jugador eliminado exitosamente";
            } else {
                $error_message = "No se pudo eliminar el jugador";
            }
        } catch (Exception $e) {
            $error_message = "Error al eliminar jugador: " . $e->getMessage();
        }
    } else {
        $error_message = "ID de jugador inválido";
    }
}

// Obtener jugadores ya inscritos
$registered_players = [];
if ($invitation_data && !$error_message) {
    try {
        $stmt = DB::pdo()->prepare("
            SELECT id, nombre, cedula, sexo, fechnac, celular, email, created_at FROM inscripciones 
            WHERE torneo_id = ? AND club_id = ? 
            ORDER BY nombre
        ");
        $stmt->execute([$torneo_id, $club_id]);
        $registered_players = $stmt->fetchAll();
    } catch (Exception $e) {
        // Error silencioso para no interrumpir la visualización
    }
}

// Verificar si el usuario est• autenticado
$current_user = Auth::user();
$is_admin_general = $current_user && Auth::isAdminGeneral();
$is_admin_torneo = $current_user && $current_user['role'] === 'admin_torneo';
$is_admin_club = $current_user && $current_user['role'] === 'admin_club';

// Determinar si el formulario debe estar habilitado
$form_enabled = $is_admin_general || $is_admin_torneo || $is_admin_club;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripción de Jugadores - Club Invitado</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .club-logo {
            max-height: 80px;
            max-width: 120px;
            object-fit: contain;
            border-radius: 8px;
            background: white;
            padding: 5px;
        }

        .header-center {
            text-align: center;
            flex: 1;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 300;
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .header .subtitle {
            font-size: 0.9em;
            opacity: 0.8;
            margin-top: 5px;
            font-style: italic;
        }

        .header-right {
            display: flex;
            align-items: center;
        }

        .station-logo {
            max-height: 80px;
            max-width: 120px;
            object-fit: contain;
            border-radius: 8px;
            background: white;
            padding: 5px;
        }

        .content {
            padding: 40px;
        }

        .tournament-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 5px solid #3498db;
        }

        .tournament-info h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.5em;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
        }

        .info-item strong {
            color: #34495e;
            margin-right: 10px;
            min-width: 120px;
        }

        .info-item span {
            color: #555;
        }

        .club-info {
            background: #e8f5e8;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 5px solid #27ae60;
        }

        .club-info h3 {
            color: #27ae60;
            margin-bottom: 10px;
        }

        .club-flex {
            display: grid;
            grid-template-columns: 140px 1fr auto;
            align-items: center;
            gap: 20px;
        }

        .club-logo-inline {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .club-logo-inline img {
            max-height: 100px;
            max-width: 140px;
            object-fit: contain;
            border-radius: 8px;
            background: white;
            padding: 5px;
            border: 1px solid #dee2e6;
        }

        .info-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: center;
        }

        .info-column {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .info-item {
            display: flex;
            align-items: center;
        }

        .info-item strong {
            color: #34495e;
            margin-right: 10px;
            min-width: 80px;
        }

        .info-item span {
            color: #555;
        }

        .auth-status {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 30px;
            text-align: center;
        }

        .auth-status.authenticated {
            background: #d1ecf1;
            border-color: #bee5eb;
        }

        .auth-status h3 {
            color: #0c5460;
            margin-bottom: 10px;
        }

        .auth-status p {
            color: #0c5460;
            margin: 5px 0;
        }

        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            margin-top: 10px;
        }

        .logout-btn:hover {
            background: #c82333;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-section h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.5em;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-grid-custom {
            display: grid;
            gap: 15px;
        }

        .form-row {
            display: grid;
            gap: 15px;
            align-items: end;
        }

        .form-row-15-30-50 {
            grid-template-columns: 15% 30% 50%;
        }

        .form-row-15-15-25-25 {
            grid-template-columns: 15% 15% 25% 25%;
        }

        .form-group-inline {
            display: flex;
            flex-direction: column;
        }

        .form-group-inline label {
            color: #34495e;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.9em;
        }

        .form-group-inline input,
        .form-group-inline select {
            padding: 6.4px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.9em;
            transition: border-color 0.3s;
        }

        .form-group-inline input:focus,
        .form-group-inline select:focus {
            outline: none;
            border-color: #3498db;
        }

        .form-container {
            display: grid;
            grid-template-columns: 40% 60%;
            gap: 30px;
            align-items: start;
        }

        .form-left {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 30px;
        }

        .form-right {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            color: #34495e;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background-color: #f8f9fa;
            color: #6c757d;
        }

        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s;
            margin-top: 20px;
        }

        .btn:hover {
            background: #2980b9;
        }

        .btn-delete {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8em;
            transition: background 0.3s;
        }

        .btn-delete:hover {
            background: #c0392b;
        }

        .btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }

        .players-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 30px;
        }

        .players-section h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.5em;
        }

        .players-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .players-table th,
        .players-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .players-table th {
            background: #34495e;
            color: white;
            font-weight: 500;
        }

        .players-table tr:hover {
            background: #f8f9fa;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .no-players {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 40px;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                border-radius: 10px;
            }

            .content {
                padding: 20px;
            }

            .header {
                padding: 20px;
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .header-left,
            .header-right {
                justify-content: center;
            }

            .header h1 {
                font-size: 2em;
            }

            .club-logo,
            .station-logo {
                max-height: 60px;
                max-width: 100px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-row-15-30-50,
            .form-row-15-15-25-25 {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .form-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .club-flex {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .club-logo-inline {
                justify-content: center;
            }

            .info-columns {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <?php if ($organizer_club_data && !empty($organizer_club_data['logo'])): ?>
                    <?= displayClubLogoInvitation($organizer_club_data, 'organizador') ?>
                <?php endif; ?>
            </div>
            <div class="header-center">
                <h1>
                    <?= safe_htmlspecialchars($invitation_data['tournament_name'] ?? '') ?>
                </h1>
                <p style="font-size: 1.65em;">Sistema de Inscripciones</p>
                <p class="subtitle" style="font-size: 1.35em;">Servicio exclusivo para clubes afiliados</p>
            </div>
            <div class="header-right">
                <img src="<?= htmlspecialchars(AppHelpers::getAppLogo()) ?>" alt="Logo de la Estación" class="station-logo">
            </div>
        </div>

        <div class="content">
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <strong>éxito:</strong> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($invitation_data): ?>

                <div class="club-info">
                    <div class="club-flex">
                        <div class="club-logo-inline">
                            <?php 
                            $invited_club_for_logo = [
                                'logo' => $invitation_data['club_logo'] ?? '',
                                'nombre' => $invitation_data['club_name'] ?? ''
                            ];
                            echo displayClubLogoInvitation($invited_club_for_logo, 'invitado');
                            ?>
                        </div>
                        <div class="info-columns">
                            <div class="info-column">
                                <div class="info-item">
                                    <strong>Delegado:</strong>
                                    <span><?= safe_htmlspecialchars($invitation_data['delegado']) ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>Email:</strong>
                                    <span><?= safe_htmlspecialchars($invitation_data['email']) ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>Teléfono:</strong>
                                    <span><?= safe_htmlspecialchars($invitation_data['telefono']) ?></span>
                                </div>
                            </div>
                            <div class="info-column">
                                <div class="info-item">
                                    <strong>Usuario:</strong>
                                    <span><?= $user_authenticated ? htmlspecialchars($current_user['username']) : 'No autenticado' ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>Rol:</strong>
                                    <span><?= $user_authenticated ? htmlspecialchars($current_user['role']) : '-' ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>Estado:</strong>
                                    <span><?= $user_authenticated ? 'Activo' : 'Inactivo' ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if ($user_authenticated): ?>
                            <div style="display: flex; align-items: center;">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="user_logout">
                                    <button type="submit" class="logout-btn">Cerrar Sesión</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($form_enabled): ?>
                    <div class="form-section">
                        <h2>?? Inscribir Nuevo Jugador</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="register_player">
                            <div class="form-grid-custom">
                                <div class="form-row form-row-15-30-50">
                                    <div class="form-group-inline">
                                        <label for="nacionalidad">Nacionalidad *</label>
                                        <select id="nacionalidad" name="nacionalidad" required>
                                            <option value="">-- Nacionalidad --</option>
                                            <option value="V">Venezolano (V)</option>
                                            <option value="E">Extranjero (E)</option>
                                        </select>
                                    </div>
                                    <div class="form-group-inline">
                                        <label for="cedula">Cédula *</label>
                                        <input type="text" id="cedula" name="cedula" required onblur="searchPersona()" oninput="checkExistingCedula()">
                                    </div>
                                    <div class="form-group-inline">
                                        <label for="nombre">Nombre y Apellido *</label>
                                        <input type="text" id="nombre" name="nombre" required>
                                    </div>
                                </div>
                                <div class="form-row form-row-15-15-25-25">
                                    <div class="form-group-inline">
                                        <label for="sexo">Sexo</label>
                                        <select id="sexo" name="sexo">
                                            <option value="M">Masculino</option>
                                            <option value="F">Femenino</option>
                                            <option value="O">Otro</option>
                                        </select>
                                    </div>
                                    <div class="form-group-inline">
                                        <label for="fechnac">Fecha de Nacimiento</label>
                                        <input type="date" id="fechnac" name="fechnac">
                                    </div>
                                    <div class="form-group-inline">
                                        <label for="celular">Celular</label>
                                        <input type="tel" id="celular" name="celular">
                                    </div>
                                    <div class="form-group-inline">
                                        <label for="email">Email</label>
                                        <input type="email" id="email" name="email">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn">Inscribir Jugador</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="players-section">
                    <h2>?? Jugadores Inscritos</h2>
                    <?php if (empty($registered_players)): ?>
                        <div class="no-players">
                            <p>No hay jugadores inscritos a�n.</p>
                        </div>
                    <?php else: ?>
                        <table class="players-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Cédula</th>
                                    <th>Sexo</th>
                                    <th>Fecha Nacimiento</th>
                                    <th>Celular</th>
                                    <th>Email</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registered_players as $player): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($player['nombre'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($player['cedula'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($player['sexo'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($player['fechnac'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($player['celular'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($player['email'] ?? '') ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('¿Está seguro de que desea eliminar este jugador?')">
                                                <input type="hidden" name="action" value="delete_player">
                                                <input type="hidden" name="player_id" value="<?= $player['id'] ?>">
                                                <button type="submit" class="btn-delete" title="Eliminar jugador">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Función para buscar persona por cédula
        async function searchPersona() {
            const cedula = document.querySelector('input[name="cedula"]').value.trim();
            const nacionalidad = document.querySelector('select[name="nacionalidad"]').value;
            
            if (!cedula || !nacionalidad) {
                return;
            }
            
            // Construir ID de usuario (nacionalidad + cédula)
            const idusuario = nacionalidad + cedula;
            
            try {
                // Buscar en la base de datos externa
                const response = await fetch(`<?= app_base_url() ?>/public/api/search_persona.php?idusuario=${encodeURIComponent(idusuario)}`);
                const result = await response.json();
                
                if (result.success && result.data) {
                    // Llenar campos automáticamente
                    document.querySelector('input[name="nombre"]').value = result.data.nombre || '';
                    document.querySelector('select[name="sexo"]').value = result.data.sexo || '';
                    document.querySelector('input[name="fechnac"]').value = result.data.fechnac || '';
                    
                    // Verificar si ya existe en el sistema
                    await checkExistingCedula(cedula);
                    
                } else {
                    alert('No se encontraron datos para esta cédula');
                }
                
            } catch (error) {
                console.error('Error en la búsqueda:', error);
                alert('Error al buscar datos de la cédula');
            }
        }

        // Función para verificar si la cédula ya existe
        async function checkExistingCedula(cedula) {
            try {
                const response = await fetch(`<?= app_base_url() ?>/public/api/check_cedula.php?cedula=${encodeURIComponent(cedula)}`);
                const result = await response.json();
                
                if (result.success && result.exists) {
                    alert(`Esta cédula ya est• registrada en el sistema (${result.data.nombre})`);
                    
                    // Limpiar campos para permitir nueva búsqueda
                    clearFormFields();
                }
            } catch (error) {
                console.error('Error verificando cédula:', error);
            }
        }

        // Función para limpiar campos del formulario
        function clearFormFields() {
            document.querySelector('input[name="nacionalidad"]').value = '';
            document.querySelector('input[name="cedula"]').value = '';
            document.querySelector('input[name="nombre"]').value = '';
            document.querySelector('select[name="sexo"]').value = 'M';
            document.querySelector('input[name="fechnac"]').value = '';
            document.querySelector('input[name="celular"]').value = '';
            document.querySelector('input[name="email"]').value = '';
            document.querySelector('input[name="cedula"]').focus();
        }

        // Función para verificar cédula duplicada
        function checkExistingCedula() {
            const cedula = document.querySelector('input[name="cedula"]').value;
            if (cedula.length >= 8) {
                const existingPlayers = <?= json_encode(array_column($registered_players, 'cedula')) ?>;
                if (existingPlayers.includes(cedula)) {
                    document.querySelector('input[name="cedula"]').style.borderColor = '#e74c3c';
                    document.querySelector('input[name="cedula"]').title = 'Esta cédula ya est• registrada';
                } else {
                    document.querySelector('input[name="cedula"]').style.borderColor = '#e9ecef';
                    document.querySelector('input[name="cedula"]').title = '';
                }
            }
        }

        // Limpiar formulario automáticamente después de éxito
        <?php if (isset($success_message) && !empty($success_message)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            clearFormFields();
        });
        <?php endif; ?>
    </script>
</body>
</html>
