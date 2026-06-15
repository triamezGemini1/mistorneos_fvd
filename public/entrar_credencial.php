<?php
/**
 * Entrada por credencial: permite al jugador ingresar escaneando el QR de su credencial.
 * Acepta ?id= (id_usuario) o ?cedula= (cédula) en la URL. Muestra formulario de contraseña
 * y autentica con el usuario correspondiente.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/app_helpers.php';

$error = '';
$jugador = null;
$identificador_tipo = ''; // 'id' o 'cedula'

$id_param = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cedula_param = isset($_GET['cedula']) ? trim((string)$_GET['cedula']) : '';
$uuid_param = isset($_GET['uuid']) ? trim((string)$_GET['uuid']) : '';

if ($id_param > 0) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, username, nombre, cedula, role FROM usuarios WHERE id = ? AND role = 'usuario' LIMIT 1");
    $stmt->execute([$id_param]);
    $jugador = $stmt->fetch(PDO::FETCH_ASSOC);
    $identificador_tipo = 'id';
} elseif ($cedula_param !== '') {
    $cedula_limpia = preg_replace('/\D/', '', $cedula_param);
    if (strlen($cedula_limpia) >= 4) {
        $pdo = DB::pdo();
        foreach ([$cedula_limpia, 'V' . $cedula_limpia, 'E' . $cedula_limpia, $cedula_param] as $valor) {
            if ($valor === '') {
                continue;
            }
            $stmt = $pdo->prepare("SELECT id, username, nombre, cedula, role FROM usuarios WHERE cedula = ? AND role = 'usuario' LIMIT 1");
            $stmt->execute([$valor]);
            $jugador = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($jugador) {
                break;
            }
        }
        $identificador_tipo = 'cedula';
    }
} elseif ($uuid_param !== '') {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, username, nombre, cedula, role FROM usuarios WHERE uuid = ? AND role = 'usuario' LIMIT 1");
    $stmt->execute([$uuid_param]);
    $jugador = $stmt->fetch(PDO::FETCH_ASSOC);
    $identificador_tipo = 'uuid';
}

if (!$jugador) {
    $error = 'No se encontró un jugador con ese identificador. Verifique el enlace de su credencial.';
}

// POST: intentar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $jugador) {
    CSRF::validate();
    $password = $_POST['password'] ?? '';
    $username = $jugador['username'];
    if ($password === '') {
        $error = 'Ingrese su contraseña.';
    } else {
        $user = Security::authenticateUser($username, $password);
        if ($user) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'email' => $user['email'] ?? '',
                'uuid' => $user['uuid'] ?? null,
                'photo_path' => $user['photo_path'] ?? null,
                'club_id' => $user['club_id'] ?? null,
                'entidad' => isset($user['entidad']) ? (int)$user['entidad'] : 0,
                'organizacion_id' => defined('ORGANIZACION_ID') ? ORGANIZACION_ID : 1,
            ];
            if (class_exists('FvdConfig', false)) {
                FvdConfig::anchorSession();
            }
            if (function_exists('session_regenerate_id')) {
                session_regenerate_id(true);
            }
            $redirect = class_exists('AppHelpers')
                ? rtrim(AppHelpers::getPublicUrl(), '/') . '/user_portal.php?section=perfil'
                : 'user_portal.php?section=perfil';
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Contraseña incorrecta.';
    }
}

$csrf_token = class_exists('CSRF') ? CSRF::token() : '';
$base = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar con credencial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Ingresar con credencial</h5>
                </div>
                <div class="card-body">
                    <?php if ($error && !$jugador): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <p class="text-muted small">Puede ingresar con su <strong>ID</strong> o con su <strong>cédula</strong> en la URL, por ejemplo:</p>
                        <ul class="small text-muted">
                            <li><code><?= htmlspecialchars($base) ?>/entrar_credencial.php?id=123</code></li>
                            <li><code><?= htmlspecialchars($base) ?>/entrar_credencial.php?cedula=V12345678</code></li>
                        </ul>
                        <a href="<?= htmlspecialchars($base ?: '.') ?>/login.php" class="btn btn-outline-primary">Ir al inicio de sesión</a>
                        <?php exit; ?>
                    <?php endif; ?>

                    <?php if ($jugador): ?>
                        <?php if ($error): ?>
                            <div class="alert alert-warning"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <p class="mb-3">Hola, <strong><?= htmlspecialchars($jugador['nombre'] ?? $jugador['username']) ?></strong>. Ingrese su contraseña para continuar.</p>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="password" class="form-control" required autofocus placeholder="Contraseña">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Ingresar</button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted">Use el enlace que aparece en el código QR de su credencial (por ID o cédula).</p>
                        <a href="<?= htmlspecialchars($base ?: '.') ?>/login.php" class="btn btn-outline-primary">Inicio de sesión general</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
