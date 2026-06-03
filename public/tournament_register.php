<?php
/**
 * Página Pública de Inscripción en Torneo
 * Permite a usuarios registrados inscribirse en un torneo
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';

$torneo_id = (int)($_GET['torneo_id'] ?? 0);
$user_id = (int)($_GET['user_id'] ?? 0);

if ($torneo_id <= 0) {
    die('Torneo no válido');
}

$pdo = DB::pdo();
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

// Obtener información del torneo (incluye entidad para validar ámbito)
$stmt = $pdo->prepare("
    SELECT t.*, o.nombre as organizacion_nombre, o.responsable as organizacion_responsable, o.telefono as organizacion_telefono,
           COALESCE(o.entidad, 0) as entidad_torneo
    FROM tournaments t
    {$org_join}
    WHERE t.id = ? AND t.estatus = 1
");
$stmt->execute([$torneo_id]);
$torneo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$torneo) {
    die('Torneo no encontrado o inactivo');
}

// Si el torneo ya finalizó, redirigir a resultados
if ($torneo['fechator'] && strtotime($torneo['fechator']) < strtotime('today')) {
    $resultados_url = AppHelpers::url('evento_resultados.php', [
        'torneo_id' => $torneo_id,
        'msg' => 'Este torneo ha finalizado. Consulta los resultados oficiales aquí.',
    ]);
    header('Location: ' . $resultados_url);
    exit;
}

// Inscripción deshabilitada el día del torneo: deben inscribirse antes o presentarse al evento
$es_hoy_torneo = $torneo['fechator'] && (date('Y-m-d', strtotime($torneo['fechator'])) === date('Y-m-d'));

// Verificar si el torneo permite inscripción en línea
$permite_torneo_online = (int)($torneo['permite_inscripcion_linea'] ?? 1) === 1;

// Obtener información del usuario si está autenticado o si se pasa user_id
$usuario = null;
$usuario_autenticado = false;

if (Auth::user()) {
    $usuario = Auth::user();
    $usuario_autenticado = true;
} elseif ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND status = 'approved'");
    $stmt->execute([$user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Verificar si ya está inscrito
$ya_inscrito = false;
$puede_inscribirse_online = false;
$mensaje_solo_sitio = '';

if ($usuario) {
    $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ?");
    $stmt->execute([$torneo_id, $usuario['id']]);
    $ya_inscrito = $stmt->fetch() !== false;
    
    // Validar si puede inscribirse en línea: torneo permite + club permite + mismo ámbito (entidad)
    if (!$ya_inscrito) {
        $club_id = (int)($usuario['club_id'] ?? 0);

        if (!$permite_torneo_online) {
            $puede_inscribirse_online = false;
            $mensaje_solo_sitio = 'Este torneo no acepta inscripciones en línea. Contacta al administrador del club para inscribirte en el sitio del evento.';
        } else {
            $permite_club = true;
            if ($club_id > 0) {
                $stmt = $pdo->prepare("SELECT permite_inscripcion_linea FROM clubes WHERE id = ?");
                $stmt->execute([$club_id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);
                $permite_club = $club && ((int)($club['permite_inscripcion_linea'] ?? 1) === 1);
            }
            
            if ($club_id <= 0) {
                $puede_inscribirse_online = false;
                $mensaje_solo_sitio = 'No tienes un club asignado. Debes estar afiliado a un club para inscribirte. Contacta al administrador de tu organización o solicita tu afiliación.';
            } elseif (!$permite_club) {
                $puede_inscribirse_online = false;
                $mensaje_solo_sitio = 'Tu club no permite inscripciones en línea. Puedes inscribirte en el sitio del evento.';
            } elseif (!TournamentScopeHelper::pasaAmbitoTerritorialInscripcion($torneo, $usuario)) {
                $puede_inscribirse_online = false;
                $mensaje_solo_sitio = 'Este torneo está fuera de tu ámbito. Puedes inscribirte en el sitio del evento el día del torneo.';
            } else {
                $puede_inscribirse_online = true;
            }
        }
    }
}

// Asociaciones de la organización (para formulario de registro+inscripción)
$clubes_organizacion = [];
$mostrar_form_registro = !$usuario && isset($_GET['registrarse']) && $_GET['registrarse'] == '1';

if (!$usuario && $torneo) {
    $org_id = (int)($torneo['club_responsable'] ?? 0);
    if ($org_id > 0) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.nombre
            FROM clubes c
            WHERE c.cod_org = ? AND (c.estatus = 1 OR c.estatus = '1')
              AND (COALESCE(c.permite_inscripcion_linea, 1) = 1)
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$org_id]);
        $clubes_organizacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($clubes_organizacion)) {
            $stmt = $pdo->prepare("SELECT cod_org FROM clubes WHERE id = ?");
            $stmt->execute([$org_id]);
            $club_org = $stmt->fetchColumn();
            if ($club_org) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.nombre FROM clubes c
                    WHERE c.cod_org = ? AND (c.estatus = 1 OR c.estatus = '1')
                      AND (COALESCE(c.permite_inscripcion_linea, 1) = 1)
                    ORDER BY c.nombre ASC
                ");
                $stmt->execute([$club_org]);
                $clubes_organizacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}

// Procesar inscripción
$success_message = '';
$error_message = '';
$inscripcion_id = null;

// Procesar registro + inscripción (solicitante no registrado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'registrar_e_inscribir') {
    if ($es_hoy_torneo) {
        $error_message = 'Las inscripciones en línea están deshabilitadas el día del torneo. Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.';
    } else {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    require_once __DIR__ . '/../lib/security.php';
    require_once __DIR__ . '/../lib/InscritosHelper.php';
    if (!RateLimiter::canSubmit('tournament_register_new', 30)) {
        $error_message = 'Por favor espera 30 segundos antes de intentar de nuevo.';
    } else {
        CSRF::validate();
        try {
            $cedula = preg_replace('/^[VEJP]/i', '', trim($_POST['cedula'] ?? ''));
            $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
            $nombre = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $celular = trim($_POST['celular'] ?? '');
            $fechnac = trim($_POST['fechnac'] ?? '');
            $sexo = strtoupper(trim($_POST['sexo'] ?? ''));
            $club_id = (int)($_POST['club_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $password = trim((string)($_POST['password'] ?? ''));
            $password_confirm = trim((string)($_POST['password_confirm'] ?? ''));
            $entidad_torneo = (int)($torneo['entidad_torneo'] ?? 0);

            if (empty($cedula) || empty($nombre) || empty($username) || empty($password) || $club_id <= 0) {
                throw new Exception('Cédula, nombre, usuario, contraseña y club son obligatorios.');
            }
            if (strlen($password) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres.');
            }
            if ($password !== $password_confirm) {
                throw new Exception('Las contraseñas no coinciden.');
            }
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El email no es válido.');
            }
            if (!in_array($sexo, ['M', 'F', 'O'])) {
                $sexo = null;
            }

            $club_valido = false;
            foreach ($clubes_organizacion as $c) {
                if ((int)$c['id'] === $club_id) {
                    $club_valido = true;
                    break;
                }
            }
            if (!$club_valido) {
                throw new Exception('Debe seleccionar un club de la organización.');
            }

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? OR username = ?");
            $stmt->execute([$cedula, $username]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe un usuario con esa cédula o nombre de usuario. Inicia sesión o recupera tu contraseña.');
            }

            $userData = [
                'username' => $username,
                'password' => $password,
                'email' => $email ?: null,
                'role' => 'usuario',
                'cedula' => $cedula,
                'nacionalidad' => in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                'nombre' => $nombre,
                'celular' => $celular ?: null,
                'fechnac' => $fechnac ?: null,
                'sexo' => $sexo,
                'club_id' => $club_id,
                'entidad' => $entidad_torneo > 0 ? $entidad_torneo : 0,
                'status' => 'approved',
                '_allow_club_for_usuario' => true
            ];
            $result = Security::createUser($userData);
            if (!$result['success']) {
                throw new Exception($result['errors'][0] ?? 'Error al crear el usuario.');
            }
            $nuevo_user_id = (int)$result['user_id'];

            $pdo->beginTransaction();
            $inscripcion_id = InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $nuevo_user_id,
                'torneo_id' => $torneo_id,
                'id_club' => $club_id,
                'estatus' => 0, // pendiente: formalizar con pago o en sitio
                'inscrito_por' => FvdConfig::INSCRITO_POR_LANDING_PUBLICO,
                'numero' => 0
            ]);
            if (file_exists(__DIR__ . '/../lib/UserActivationHelper.php')) {
                require_once __DIR__ . '/../lib/UserActivationHelper.php';
                UserActivationHelper::activateUser($pdo, $nuevo_user_id);
            }
            $pdo->commit();
            RateLimiter::recordSubmit('tournament_register_new');
            $success_message = 'Te has registrado al torneo ' . htmlspecialchars($torneo['nombre']) . '. Debes formalizar tu inscripción haciendo el pago correspondiente a través de la notificación de pagos o al presentarte al evento.';
            $usuario = ['id' => $nuevo_user_id, 'nombre' => $nombre, 'username' => $username, 'celular' => $celular];
            $ya_inscrito = true;
            if ($torneo['costo'] > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (torneo_id, club_id, amount, method, status, created_at)
                    VALUES (?, ?, ?, 'pendiente', 'pendiente', NOW())
                ");
                $stmt->execute([$torneo_id, $club_id, $torneo['costo']]);
                $payment_id = $pdo->lastInsertId();
                require_once __DIR__ . '/../lib/whatsapp_sender.php';
                $telefono = preg_replace('/[^0-9]/', '', $celular ?? '');
                if (strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
                    $telefono = '58' . $telefono;
                }
                $app_url = $_ENV['APP_URL'] ?? (function_exists('app_base_url') ? app_base_url() : '');
                $pago_link = AppHelpers::url("report_payment.php?payment_id=") . $payment_id;
                $mensaje = "✅ *INSCRIPCIÓN EXITOSA*\n\nHola *" . htmlspecialchars($nombre) . "*\n\nTu registro e inscripción en el torneo han sido exitosos.\n\n🏆 *Torneo:* " . htmlspecialchars($torneo['nombre']) . "\n💰 *Costo:* $" . number_format($torneo['costo'], 2) . "\n\n🔗 *Reportar Pago:*\n" . $pago_link;
                $_SESSION['payment_notification'] = ['mensaje' => $mensaje, 'telefono' => $telefono, 'payment_id' => $payment_id];
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = $e->getMessage();
            $mostrar_form_registro = true;
        }
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inscribir') {
    if ($es_hoy_torneo) {
        $error_message = 'Las inscripciones en línea están deshabilitadas el día del torneo. Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.';
    } else {
    try {
        if (!$usuario) {
            throw new Exception('Debes estar autenticado o tener un enlace válido para inscribirte');
        }
        
        if ($ya_inscrito) {
            throw new Exception('Ya estás inscrito en este torneo');
        }
        
        if (!$puede_inscribirse_online) {
            throw new Exception($mensaje_solo_sitio ?: 'Solo puedes inscribirte en sitio para este torneo.');
        }
        
        $pdo->beginTransaction();
        
        // Obtener club del usuario (refrescar desde BD para tener afiliación actualizada)
        require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
        $club_post = (int) ($_POST['club_id'] ?? 0);
        $club_id = AsociacionAdminHelper::resolverIdClubInscripcion(
            $pdo,
            (int) $usuario['id'],
            (int) ($usuario['id'] ?? 0),
            $club_post > 0 ? $club_post : null,
            null,
            null
        );
        if (!$club_id) {
            throw new Exception('Debe seleccionar su asociación/club o tener uno asignado en su perfil.');
        }
        
        // Insertar inscripción usando función centralizada
        require_once __DIR__ . '/../lib/InscritosHelper.php';
        
        $inscripcion_id = InscritosHelper::insertarInscrito($pdo, [
            'id_usuario' => $usuario['id'],
            'torneo_id' => $torneo_id,
            'id_club' => $club_id,
            'estatus' => 0, // pendiente: formalizar con pago o en sitio
            'inscrito_por' => FvdConfig::INSCRITO_POR_LANDING_PUBLICO,
            'numero' => 0
        ]);
        if (file_exists(__DIR__ . '/../lib/UserActivationHelper.php')) {
            require_once __DIR__ . '/../lib/UserActivationHelper.php';
            UserActivationHelper::activateUser($pdo, (int)$usuario['id']);
        }
        // Generar notificación de pago si el torneo tiene costo
        if ($torneo['costo'] > 0) {
            // Crear registro de pago pendiente
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    torneo_id, club_id, amount, method, status, created_at
                ) VALUES (?, ?, ?, 'pendiente', 'pendiente', NOW())
            ");
            $stmt->execute([
                $torneo_id,
                $club_id,
                $torneo['costo']
            ]);
            $payment_id = $pdo->lastInsertId();
            
            // Generar y enviar notificación de pago
            require_once __DIR__ . '/../lib/whatsapp_sender.php';
            
            $telefono = preg_replace('/[^0-9]/', '', $usuario['celular'] ?? '');
            if ($telefono) {
                if ($telefono[0] == '0') {
                    $telefono = substr($telefono, 1);
                }
                if (strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
                    $telefono = '58' . $telefono;
                }
                
                $app_url = $_ENV['APP_URL'] ?? (function_exists('app_base_url') ? app_base_url() : FvdConfig::resolveAppUrl());
                $pago_link = AppHelpers::url("report_payment.php?payment_id=") . $payment_id;
                
                $mensaje = "✅ *INSCRIPCIÓN EXITOSA*\n\n";
                $mensaje .= "Hola *" . htmlspecialchars($usuario['nombre'] ?? $usuario['username']) . "*\n\n";
                $mensaje .= "Tu inscripción en el torneo ha sido registrada exitosamente.\n\n";
                $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                $mensaje .= "📋 *DETALLES DE INSCRIPCIÓN*\n";
                $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                $mensaje .= "🏆 *Torneo:* " . htmlspecialchars($torneo['nombre']) . "\n";
                $mensaje .= "📅 *Fecha:* " . date('d/m/Y', strtotime($torneo['fechator'])) . "\n";
                if ($torneo['lugar']) {
                    $mensaje .= "📍 *Lugar:* " . htmlspecialchars($torneo['lugar']) . "\n";
                }
                $mensaje .= "💰 *Costo:* $" . number_format($torneo['costo'], 2) . "\n\n";
                $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                $mensaje .= "💳 *INFORMACIÓN DE PAGO*\n";
                $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                $mensaje .= "Para completar tu inscripción, realiza el pago de *$" . number_format($torneo['costo'], 2) . "*\n\n";
                $mensaje .= "📞 *Contacto para pago:*\n";
                if ($torneo['club_telefono']) {
                    $mensaje .= "Teléfono: " . htmlspecialchars($torneo['club_telefono']) . "\n";
                }
                if ($torneo['delegado']) {
                    $mensaje .= "Delegado: " . htmlspecialchars($torneo['delegado']) . "\n";
                }
                $mensaje .= "\n";
                $mensaje .= "🔗 *Reportar Pago:*\n";
                $mensaje .= $pago_link . "\n\n";
                $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                $mensaje .= "¡Gracias por participar! 🎲\n\n";
                $mensaje .= "_" . htmlspecialchars($torneo['organizacion_nombre']) . "_";
                
                // Guardar mensaje para mostrar en la página
                $_SESSION['payment_notification'] = [
                    'mensaje' => $mensaje,
                    'telefono' => $telefono,
                    'payment_id' => $payment_id
                ];
            }
        }
        
        $pdo->commit();
        $success_message = 'Te has registrado al torneo ' . htmlspecialchars($torneo['nombre']) . '. Debes formalizar tu inscripción haciendo el pago correspondiente a través de la notificación de pagos o al presentarte al evento.';
        $ya_inscrito = true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = 'Error al inscribirse: ' . $e->getMessage();
    }
    }
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Torneo', 2 => 'Campeonato'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripción en Torneo - <?= htmlspecialchars($torneo['nombre']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .container-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 2rem;
            max-width: 800px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="container-card">
            <div class="text-center mb-4">
                <i class="fas fa-trophy fa-3x text-warning mb-3"></i>
                <h2>Inscripción en Torneo</h2>
            </div>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Información del Torneo -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>Información del Torneo
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <strong>Nombre:</strong><br>
                            <?= htmlspecialchars($torneo['nombre']) ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Fecha:</strong><br>
                            <?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                        </div>
                        <?php if ($torneo['lugar']): ?>
                        <div class="col-md-6 mb-3">
                            <strong>Lugar:</strong><br>
                            <?= htmlspecialchars($torneo['lugar']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6 mb-3">
                            <strong>Organizador:</strong><br>
                            <?= htmlspecialchars($torneo['organizacion_nombre']) ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Clase:</strong><br>
                            <?php 
                            $clase_num = is_numeric($torneo['clase']) ? (int)$torneo['clase'] : (strtolower($torneo['clase']) === 'torneo' ? 1 : (strtolower($torneo['clase']) === 'campeonato' ? 2 : 1));
                            echo $clases[$clase_num] ?? 'N/A';
                            ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Modalidad:</strong><br>
                            <?php 
                            $modalidad_num = is_numeric($torneo['modalidad']) ? (int)$torneo['modalidad'] : 1;
                            echo $modalidades[$modalidad_num] ?? 'N/A';
                            ?>
                        </div>
                        <?php if ($torneo['costo'] > 0): ?>
                        <div class="col-md-6 mb-3">
                            <strong>Costo:</strong><br>
                            <span class="text-success fw-bold">$<?= number_format($torneo['costo'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Información del Usuario -->
            <?php if ($usuario): ?>
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-user me-2"></i>Tus Datos
                    </h5>
                </div>
                <div class="card-body">
                    <p><strong>Nombre:</strong> <?= htmlspecialchars($usuario['nombre'] ?? $usuario['username']) ?></p>
                    <?php if ($usuario['cedula']): ?>
                        <p><strong>Cédula:</strong> <?= htmlspecialchars($usuario['cedula']) ?></p>
                    <?php endif; ?>
                    <?php if ($usuario['celular']): ?>
                        <p><strong>Teléfono:</strong> <?= htmlspecialchars($usuario['celular']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Condiciones requeridas (info general) -->
            <div class="card mb-4 border-info">
                <div class="card-header bg-info text-white py-2">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Condiciones para inscripción en línea</h6>
                </div>
                <div class="card-body py-3">
                    <ul class="mb-0 small">
                        <li>Estar registrado e iniciar sesión</li>
                        <li>Estar afiliado a un club que permita inscripción en línea</li>
                        <li>El torneo debe aceptar inscripciones en línea</li>
                        <li>FVD: inscripción nacional (sin límite por entidad territorial)</li>
                    </ul>
                </div>
            </div>
            
            <?php if ($es_hoy_torneo && !$ya_inscrito): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Las inscripciones en línea están deshabilitadas el día del torneo.</strong>
                <p class="mb-0 mt-2">Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.</p>
            </div>
            <?php elseif (!$ya_inscrito && $usuario && $puede_inscribirse_online): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-user-plus me-2"></i>Confirmar Inscripción
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="tournamentInscribeForm">
                        <input type="hidden" name="action" value="inscribir">
                        <p>¿Deseas inscribirte en este torneo?</p>
                        <?php if ($torneo['costo'] > 0): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-dollar-sign me-2"></i>
                                <strong>Costo de inscripción:</strong> $<?= number_format($torneo['costo'], 2) ?>
                                <br><small>Después de inscribirte recibirás información para realizar el pago.</small>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success btn-lg w-100">
                            <i class="fas fa-check me-2"></i>Confirmar Inscripción
                        </button>
                    </form>
                </div>
            </div>
            <?php elseif ($ya_inscrito): ?>
            <div class="alert alert-info">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Ya estás inscrito en este torneo.</strong>
            </div>
            <?php elseif ($usuario && !$puede_inscribirse_online && $mensaje_solo_sitio): ?>
            <div class="alert alert-warning">
                <i class="fas fa-map-marker-alt me-2"></i>
                <strong><?= htmlspecialchars($mensaje_solo_sitio) ?></strong>
                <p class="mb-0 mt-2 small">Contacta al organizador para inscribirte el día del evento.</p>
                <p class="mb-0 mt-2 small"><strong>Contacto:</strong> <?= htmlspecialchars($torneo['organizacion_nombre'] ?? '') ?> <?php if (!empty($torneo['organizacion_telefono'])): ?> · Tel: <?= htmlspecialchars($torneo['organizacion_telefono']) ?><?php endif; ?></p>
            </div>
            <?php elseif ($usuario && !$puede_inscribirse_online && !$mensaje_solo_sitio): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No puedes inscribirte en línea para este torneo.</strong>
                <p class="mb-0 mt-2 small">Contacta al administrador de tu club o al organizador para inscribirte en el sitio del evento.</p>
            </div>
            <?php elseif (!$usuario): ?>
            <?php 
            $login_return = 'tournament_register.php?torneo_id=' . $torneo_id;
            $hay_clubes = count($clubes_organizacion) > 0;
            ?>
            <?php if ($es_hoy_torneo): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Las inscripciones en línea están deshabilitadas el día del torneo.</strong>
                <p class="mb-0 mt-2">Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.</p>
            </div>
            <?php elseif (!$mostrar_form_registro): ?>
            <div class="alert alert-warning">
                <i class="fas fa-user-plus me-2"></i>
                <strong>Para inscribirte debes estar registrado en el sistema.</strong>
                <p class="mb-2 mt-2">¿Desea registrarse ahora para inscribirse en este torneo?</p>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="tournament_register.php?torneo_id=<?= $torneo_id ?>&registrarse=1" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Sí, registrarme e inscribirme
                    </a>
                    <a href="login.php?return_url=<?= urlencode($login_return) ?>" class="btn btn-outline-primary">
                        <i class="fas fa-sign-in-alt me-1"></i>Ya tengo cuenta, iniciar sesión
                    </a>
                </div>
            </div>
            <?php elseif ($mostrar_form_registro && !$hay_clubes): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No hay clubes disponibles para inscribirse.</strong>
                <p class="mb-2 mt-2">Contacta al organizador para registrarte: <?= htmlspecialchars($torneo['organizacion_nombre'] ?? '') ?></p>
                <a href="tournament_register.php?torneo_id=<?= $torneo_id ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
            </div>
            <?php elseif ($mostrar_form_registro && $hay_clubes): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-user-plus me-2"></i>Registro e Inscripción
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Completa tus datos y selecciona tu club para registrarte e inscribirte en el torneo.</p>
                    <form method="POST" id="formRegistrarInscribir">
                        <input type="hidden" name="action" value="registrar_e_inscribir">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Nacionalidad *</label>
                                <select name="nacionalidad" class="form-select" required>
                                    <option value="V" selected>V</option>
                                    <option value="E">E</option>
                                </select>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Cédula *</label>
                                <input type="text" name="cedula" class="form-control" required placeholder="Solo números">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nombre completo *</label>
                                <input type="text" name="nombre" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sexo *</label>
                                <select name="sexo" id="sexo" class="form-select" required>
                                    <option value="">-- Seleccionar --</option>
                                    <option value="M" <?= ($_POST['sexo'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                                    <option value="F" <?= ($_POST['sexo'] ?? '') === 'F' ? 'selected' : '' ?>>Femenino</option>
                                    <option value="O" <?= ($_POST['sexo'] ?? '') === 'O' ? 'selected' : '' ?>>Otro</option>
                                </select>
                                <small class="text-muted">Verifique que coincida con su documento.</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono *</label>
                                <input type="text" name="celular" class="form-control" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Club de la organización *</label>
                                <select name="club_id" class="form-select" required>
                                    <option value="">— Seleccione su club —</option>
                                    <?php
                                    $asociacionesFvd = [];
                                    $clubesAfiliados = [];
                                    foreach ($clubes_organizacion as $c) {
                                        if ((int)($c['id'] ?? 0) >= 1 && (int)($c['id'] ?? 0) <= 39) {
                                            $asociacionesFvd[] = $c;
                                        } else {
                                            $clubesAfiliados[] = $c;
                                        }
                                    }
                                    ?>
                                    <optgroup label="Asociaciones Estadales (FVD)">
                                        <?php if (empty($asociacionesFvd)): ?>
                                            <option value="" disabled>Sin asociaciones disponibles</option>
                                        <?php else: ?>
                                            <?php foreach ($asociacionesFvd as $c): ?>
                                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </optgroup>
                                    <optgroup label="Clubes Afiliados">
                                        <?php if (empty($clubesAfiliados)): ?>
                                            <option value="" disabled>Sin clubes afiliados disponibles</option>
                                        <?php else: ?>
                                            <?php foreach ($clubesAfiliados as $c): ?>
                                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Usuario (para iniciar sesión) *</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contraseña *</label>
                                <input type="password" name="password" class="form-control" required minlength="6">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Confirmar contraseña *</label>
                                <input type="password" name="password_confirm" class="form-control" required minlength="6">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha de nacimiento</label>
                                <input type="date" name="fechnac" class="form-control">
                            </div>
                        </div>
                        <?php if ($torneo['costo'] > 0): ?>
                            <div class="alert alert-info py-2 mb-3">
                                <i class="fas fa-dollar-sign me-2"></i>Costo de inscripción: $<?= number_format($torneo['costo'], 2) ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-user-plus me-1"></i>Registrarme e inscribirme
                            </button>
                            <a href="tournament_register.php?torneo_id=<?= $torneo_id ?>" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No cumples las condiciones para inscribirte en línea. Contacta al organizador.
            </div>
            <?php endif; ?>
            
            <!-- Notificación de Pago -->
            <?php if (isset($_SESSION['payment_notification'])): 
                $payment_notif = $_SESSION['payment_notification'];
                unset($_SESSION['payment_notification']);
            ?>
            <div class="card border-success mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fab fa-whatsapp me-2"></i>Notificación de Pago
                    </h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">Se ha generado un mensaje con la información de pago. Puedes copiarlo y enviarlo por WhatsApp o abrirlo directamente:</p>
                    <div class="mb-3">
                        <textarea class="form-control" rows="10" readonly><?= htmlspecialchars($payment_notif['mensaje']) ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-success" onclick="copiarMensaje()">
                            <i class="fas fa-copy me-2"></i>Copiar Mensaje
                        </button>
                        <?php if ($payment_notif['telefono']): ?>
                        <a href="https://wa.me/<?= $payment_notif['telefono'] ?>?text=<?= urlencode($payment_notif['mensaje']) ?>" 
                           class="btn btn-success">
                            <i class="fab fa-whatsapp me-2"></i>Abrir WhatsApp
                        </a>
                        <?php endif; ?>
                        <a href="report_payment.php?payment_id=<?= $payment_notif['payment_id'] ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-money-bill me-2"></i>Reportar Pago
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="text-center mt-4">
                <a href="landing.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </a>
            </div>
        </div>
    </div>
    
    <?php $tr_base = (function_exists('app_base_url') ? app_base_url() : '/'); ?>
    <script src="<?= htmlspecialchars(rtrim($tr_base, '/') . '/assets/form-utils.js') ?>" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var form1 = document.getElementById('tournamentInscribeForm');
        if (form1 && typeof preventDoubleSubmit === 'function') preventDoubleSubmit(form1);
        var form2 = document.getElementById('formRegistrarInscribir');
        if (form2 && typeof preventDoubleSubmit === 'function') preventDoubleSubmit(form2);
    });
    function copiarMensaje() {
        const textarea = document.querySelector('textarea');
        textarea.select();
        textarea.setSelectionRange(0, 99999);
        document.execCommand('copy');
        alert('Mensaje copiado al portapapeles');
    }
    </script>
</body>
</html>

