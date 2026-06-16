<?php
/**
 * Login: patrón en bloque (configuración → conexión única). Sin requireAuth por ser página pública.
 * Usa includes/header.php e includes/footer.php unificados.
 */
require_once __DIR__ . '/../config/session_start_early.php';
ob_start();
try {
    require_once __DIR__ . '/../config/bootstrap.php';
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../lib/TournamentAdminAccess.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
} catch (Throwable $e) {
    error_log("login.php: Error cargando conexión - " . $e->getMessage());
    ob_end_clean();
    http_response_code(503);
    include __DIR__ . '/error_service_unavailable.php';
    exit;
}

// URL de retorno permitida (solo rutas internas, sin protocolo externo)
$return_url = '';
if (!empty($_GET['return_url'])) {
    $raw = trim($_GET['return_url']);
    if (preg_match('#^[a-zA-Z0-9_\-/\.\?=&]+$#', $raw) && !preg_match('#^(https?|javascript|data):#i', $raw)) {
        $return_url = $raw; // Mantener relativa: tournament_register.php?torneo_id=2
    }
}

// Si ya hay sesión activa, redirigir al dashboard o return_url (302). Usar base de la petición para no ir a raíz del dominio.
if (isset($_SESSION['user'])) {
    require __DIR__ . '/../modules/auth/after_login_check.php';
    ob_end_clean();
    $entry_base = AppHelpers::getRequestEntryUrl();
    if ($return_url && (strpos($return_url, '?') !== false || strpos($return_url, '.php') !== false)) {
        $target = (strpos($return_url, 'http') === 0 || strpos($return_url, '/') === 0)
            ? AppHelpers::canonicalizeStandaloneUrl($return_url)
            : $entry_base . '/' . ltrim($return_url, '/');
        header("Location: " . $target, true, 302);
    } else {
        $default = TournamentAdminAccess::postLoginUrl($entry_base);
        header("Location: " . $default, true, 302);
    }
    exit;
}

// Solo tratar como "acceso por invitación" si la petición viene del flujo de invitación (return_url con invitation/register o join)
$from_invitation_flow = $return_url && (strpos($return_url, 'invitation/register') !== false || strpos($return_url, 'join') !== false);

// Captura del token de invitación desde cookie o return_url cuando vino del flujo de invitación (evitar confundir con login normal o admin)
if ($from_invitation_flow) {
    require_once __DIR__ . '/../lib/app_helpers.php';
    $entry_base = AppHelpers::getRequestEntryUrl();
    if ($return_url !== '') {
        $_SESSION['url_retorno'] = (strpos($return_url, 'http') === 0 || strpos($return_url, '/') === 0)
            ? AppHelpers::canonicalizeStandaloneUrl($return_url)
            : rtrim($entry_base, '/') . '/' . ltrim($return_url, '/');
    }
    if (empty($_SESSION['invitation_token']) && !empty($_COOKIE['invitation_token']) && strlen(trim($_COOKIE['invitation_token'])) >= 32) {
        $_SESSION['invitation_token'] = trim($_COOKIE['invitation_token']);
        if (empty($_SESSION['url_retorno'])) {
            $_SESSION['url_retorno'] = rtrim($entry_base, '/') . '/invitation/register?token=' . urlencode($_SESSION['invitation_token']);
        }
        $_SESSION['invitation_club_name'] = 'Club';
    }
}

if (!$from_invitation_flow) {
    unset($_SESSION['invitation_token'], $_SESSION['invitation_club_name']);
    if (isset($_SESSION['url_retorno']) && strpos((string)$_SESSION['url_retorno'], 'invitation/register') !== false) {
        unset($_SESSION['url_retorno']);
    }
}

$error = null;
$success = !empty($_GET['registered']) ? 'Registro exitoso. Ya puedes iniciar sesión.' : null;
$invitation_message = null;
if ($from_invitation_flow && !empty($_SESSION['invitation_club_name'])) {
    $invitation_message = 'Has accedido mediante una invitación. Por favor, inicia sesión o regístrate para vincular tu cuenta al Club ' . htmlspecialchars($_SESSION['invitation_club_name']) . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    error_log("Login intent - username: " . ($username === '' ? '(vacío)' : "'" . $username . "'"));

    try {
        require_once __DIR__ . '/../config/auth.php';
        $login_ok = Auth::login($username, $password);
    } catch (Throwable $e) {
        error_log("login.php POST: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
        $error = 'Error al procesar el inicio de sesión. Intenta de nuevo.';
        $login_ok = false;
    }
    error_log('[SESSION] Auth::login resultado=' . ($login_ok ? 'true' : 'false') . ' | username=' . $username);
    if ($login_ok) {
        if (getenv('SESSION_DEBUG')) error_log('[SESSION_DEBUG] login.php | login OK | session_id=' . session_id() . ' | user_id=' . (Auth::user()['id'] ?? '') . ' | username=' . (Auth::user()['username'] ?? ''));
        ob_end_clean();

        // Reclamación de token de invitación: vincular usuario y redirigir al formulario
        if (!empty($_SESSION['invitation_token'])) {
            require_once __DIR__ . '/../config/db_config.php';
            $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
            $token = $_SESSION['invitation_token'];
            $return_url = $_SESSION['url_retorno'] ?? '';
            $user_id = (int) (Auth::user()['id'] ?? 0);
            try {
                $stmt = DB::pdo()->prepare("SELECT * FROM {$tb_inv} WHERE token = ? LIMIT 1");
                $stmt->execute([$token]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv) {
                    $id_vinculado = isset($inv['id_usuario_vinculado']) ? (int) $inv['id_usuario_vinculado'] : 0;
                    if ($id_vinculado > 0 && $id_vinculado !== $user_id) {
                        $_SESSION['login_error'] = 'Esta invitación ya está siendo gestionada por otro delegado.';
                        $return_url = '';
                    } else {
                        $up = DB::pdo()->prepare("UPDATE {$tb_inv} SET id_usuario_vinculado = ?, estado = 'activa' WHERE token = ?");
                        $up->execute([$user_id, $token]);
                        $club_id_inv = (int)($inv['club_id'] ?? 0);
                        if ($club_id_inv > 0) {
                            $upClub = DB::pdo()->prepare("UPDATE clubes SET delegado_user_id = ? WHERE id = ?");
                            $upClub->execute([$user_id, $club_id_inv]);
                        }
                        $id_dir = (int)($inv['id_directorio_club'] ?? 0);
                        $cols = @DB::pdo()->query("SHOW COLUMNS FROM directorio_clubes LIKE 'id_usuario'")->fetch();
                        if ($cols) {
                            if ($id_dir > 0) {
                                $upDir = DB::pdo()->prepare("UPDATE directorio_clubes SET id_usuario = ? WHERE id = ?");
                                $upDir->execute([$user_id, $id_dir]);
                            } else {
                                $stmtNom = DB::pdo()->prepare("SELECT nombre FROM clubes WHERE id = ?");
                                $stmtNom->execute([$club_id_inv]);
                                $nom = $stmtNom->fetchColumn();
                                if ($nom !== false && trim((string)$nom) !== '') {
                                    $upDir = DB::pdo()->prepare("UPDATE directorio_clubes SET id_usuario = ? WHERE nombre = ?");
                                    $upDir->execute([$user_id, $nom]);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Columna id_usuario_vinculado puede no existir aún
            }
            unset($_SESSION['invitation_token'], $_SESSION['invitation_club_name']);
            if ($return_url !== '' && !headers_sent()) {
                header('Location: ' . $return_url, true, 302);
                unset($_SESSION['url_retorno']);
                exit;
            }
            unset($_SESSION['url_retorno']);
        }

        $redirect = !empty($_POST['return_url']) ? trim($_POST['return_url']) : ($return_url ?: '');
        $entry_base = AppHelpers::getRequestEntryUrl();
        if ($entry_base === '' && defined('URL_BASE') && URL_BASE !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $entry_base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(URL_BASE, '/');
        }
        $target_url = ($redirect && preg_match('#^[a-zA-Z0-9_\-/\.\?=&]+$#', $redirect) && !preg_match('#^(https?|javascript|data):#i', $redirect) && (strpos($redirect, '?') !== false || strpos($redirect, '.php') !== false))
            ? ((strpos($redirect, 'http') === 0 || strpos($redirect, '/') === 0) ? $redirect : $entry_base . '/' . ltrim($redirect, '/'))
            : $entry_base . '/index.php';
        if ($target_url === '' || $target_url === '/index.php') {
            $target_url = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(AppHelpers::getPublicUrl(), '/') . '/index.php' : $entry_base . '/index.php';
        }
        error_log('[SESSION] login OK -> redirect | target=' . $target_url . ' | entry_base=' . $entry_base . ' | session_id=' . session_id());
        if (getenv('SESSION_DEBUG')) error_log('[SESSION_DEBUG] login.php | entry_base=' . $entry_base . ' | session_id=' . session_id());
        $params = session_get_cookie_params();
        $sname = session_name();
        $sid = session_id();
        if (function_exists('session_write_close')) {
            session_write_close();
        }
        $cookie_opts = ['expires' => 0, 'path' => '/', 'domain' => $params['domain'] ?? '', 'secure' => $params['secure'] ?? false, 'httponly' => $params['httponly'] ?? true, 'samesite' => $params['samesite'] ?? 'Lax'];
        // 1) Borrar cookie antigua con path de subcarpeta (si existe) para que no se envíe en la siguiente petición
        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            setcookie($sname, '', array_merge($cookie_opts, ['expires' => time() - 3600, 'path' => URL_BASE]));
        }
        // 2) Enviar cookie con el session_id actual y path=/ (única cookie para todo el dominio)
        if ($sid !== '') {
            setcookie($sname, $sid, $cookie_opts);
        }
        $redirect_target = TournamentAdminAccess::postLoginUrl($entry_base);
        if ($redirect && preg_match('#^[a-zA-Z0-9_\-/\.\?=&]+$#', $redirect) && !preg_match('#^(https?|javascript|data):#i', $redirect)) {
            if (strpos($redirect, '?') !== false || strpos($redirect, '.php') !== false) {
                $redirect_target = (strpos($redirect, 'http') === 0 || strpos($redirect, '/') === 0)
                    ? $redirect
                    : $entry_base . '/' . ltrim($redirect, '/');
            }
        }
        if (! TournamentAdminAccess::canAccessTorneoPanel()) {
            $rtLower = strtolower((string) $redirect_target);
            if (strpos($rtLower, 'index.php') !== false
                && strpos($rtLower, 'users/profile') === false
                && strpos($rtLower, 'users/change_password') === false
                && strpos($rtLower, 'page=torneo') === false) {
                $redirect_target = AppHelpers::publicPortalUrl();
            }
        }
        if ($redirect_target !== '' && !headers_sent()) {
            header("Location: " . $redirect_target, true, 302);
        }
        exit;
    } else {
        error_log('[SESSION] Login fallido - mostrando motivo (usuario=' . $username . ')');
        // Mensaje al usuario según motivo real (username = valor enviado en ESTA petición en el campo del formulario)
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT id, username, status, password_hash FROM usuarios WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $password_ok = !empty($user['password_hash']) && password_verify($password, $user['password_hash']);
                if ($password_ok && (int)$user['status'] !== 0) {
                    $error = "Tu cuenta está inactiva. Contacta al administrador.";
                    error_log("Login fallido (usuario enviado en petición: '{$username}'): cuenta inactiva status=" . ($user['status'] ?? ''));
                } elseif (!$password_ok && !empty($user['password_hash'])) {
                    $error = "Contraseña incorrecta";
                    error_log("Login fallido (usuario enviado en petición: '{$username}'): contraseña incorrecta");
                } elseif (empty($user['password_hash'])) {
                    $error = "Tu cuenta no tiene contraseña configurada. Contacta al administrador.";
                    error_log("Login fallido (usuario enviado en petición: '{$username}'): sin password_hash");
                } else {
                    $error = "Credenciales incorrectas.";
                    error_log("Login fallido (usuario enviado en petición: '{$username}')");
                }
            } else {
                $error = "El usuario o correo no existe en el sistema";
                error_log("Login fallido (usuario enviado en petición: '{$username}'): no existe");
            }
        } catch (Exception $e) {
            $error = "Error al verificar credenciales. Por favor intenta de nuevo.";
            error_log("Login error exception: " . $e->getMessage());
        }
    }
}
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es">
<?php
require_once __DIR__ . '/../lib/FvdBranding.php';
$header_title = 'Iniciar Sesión - ' . FvdBranding::nombre();
include_once __DIR__ . '/../includes/header.php';
?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      padding: 1rem;
    }
    .login-card {
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
      overflow: hidden;
    }
    .login-header {
      background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
      color: white;
      padding: 2rem;
      text-align: center;
    }
    .login-header i {
      font-size: 3rem;
      margin-bottom: 1rem;
      color: #48bb78;
    }
    .card-body {
      padding: 2rem;
    }
    @media (max-width: 576px) {
      .card-body {
        padding: 1.5rem;
      }
      .login-header {
        padding: 1.5rem;
      }
      .login-header i {
        font-size: 2.5rem;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-5 col-lg-4">
        <div class="card login-card border-0">
          <div class="login-header">
            <?php 
            require_once __DIR__ . '/../lib/app_helpers.php';
            $logo_url = AppHelpers::getAppLogo();
            ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars(FvdBranding::nombre()) ?>" style="height: 60px; margin-bottom: 1rem;">
            <h4 class="mb-1"><?= htmlspecialchars(FvdBranding::siglas()) ?></h4>
            <p class="small mb-0 opacity-75"><?= htmlspecialchars(FvdBranding::nombre()) ?></p>
            <p class="mb-0 opacity-75">Iniciar Sesión</p>
          </div>
          <div class="card-body">
            <?php if ($success): ?>
              <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['invitation_club_name']) && $invitation_message): ?>
              <div class="alert alert-info">
                <i class="fas fa-envelope-open-text me-2"></i><?= $invitation_message ?>
              </div>
            <?php endif; ?>
            <?php if ($error || !empty($_SESSION['login_error'])): ?>
              <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error ?: $_SESSION['login_error']) ?>
              </div>
              <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>
            <form method="POST">
              <?php if ($return_url): ?>
              <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url) ?>">
              <?php endif; ?>
              <div class="mb-3">
                <label class="form-label fw-semibold">Usuario o Email</label>
                <input type="text" name="username" class="form-control form-control-lg" required autofocus placeholder="Ingresa tu usuario">
              </div>
              <div class="mb-4">
                <label class="form-label fw-semibold">Contraseña</label>
                <div class="input-group input-group-lg">
                  <input type="password" name="password" id="password" class="form-control" required placeholder="Ingresa tu contraseña">
                  <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                    <i class="fas fa-eye" id="toggleIcon"></i>
                  </button>
                </div>
              </div>
              <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
                <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
              </button>
              <div class="d-flex justify-content-between mb-3">
                <a class="small text-decoration-none fw-semibold" href="<?= htmlspecialchars(AppHelpers::url('recover_user.php')) ?>">
                  <i class="fas fa-user-circle me-1"></i>Recuperar usuario
                </a>
                <a class="small text-decoration-none fw-semibold" href="<?= htmlspecialchars(AppHelpers::url('reset_password_no_email.php')) ?>">
                  <i class="fas fa-key me-1"></i>Restablecer contraseña sin correo
                </a>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-12">
                  <a class="btn btn-outline-primary w-100 btn-sm" href="<?= htmlspecialchars(AppHelpers::url('recover_user.php')) ?>">
                    <i class="fas fa-user-circle me-1"></i> Recuperar usuario
                  </a>
                </div>
                <div class="col-12">
                  <a class="btn btn-outline-secondary w-100 btn-sm" href="<?= htmlspecialchars(AppHelpers::url('reset_password_no_email.php')) ?>">
                    <i class="fas fa-key me-1"></i> Restablecer contraseña sin correo
                  </a>
                </div>
              </div>
            </form>
            <div class="text-center mt-3">
              <a href="<?= htmlspecialchars(rtrim(AppHelpers::getRequestEntryUrl(), '/') . '/landing.php') ?>" class="text-muted text-decoration-none small">
                <i class="fas fa-arrow-left me-1"></i>Volver al inicio
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    function togglePassword() {
      const pwd = document.getElementById('password');
      const icon = document.getElementById('toggleIcon');
      if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        pwd.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }
  </script>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
