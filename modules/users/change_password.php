<?php
if (empty($_SESSION['user'])) {
    $login = class_exists('AppHelpers') ? AppHelpers::url('login.php') : 'login.php';
    header('Location: ' . $login);
    exit;
}

require_once __DIR__ . '/../../config/csrf.php';
if (!class_exists('AppHelpers')) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}

$isForced = isset($_GET['force']) && $_GET['force'] == '1';
$reason = $_SESSION['password_change_reason'] ?? '';
$embedded_in_layout = isset($current_page) && $current_page === 'users/change_password';
$form_action = AppHelpers::url('change_password_save.php');
$cancel_url = AppHelpers::url('profile.php');
$pwd_ok = isset($_GET['pwd_ok']) || isset($_SESSION['password_success']);

if (!$embedded_in_layout): ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cambiar contraseña</title>
  <link href="<?= htmlspecialchars(AppHelpers::publicAssetUrl('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
  <link href="<?= htmlspecialchars(AppHelpers::publicAssetUrl('assets/vendor/fontawesome/css/all.min.css')) ?>" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .pwd-standalone-card { max-width: 450px; border: none; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    .pwd-standalone-card .card-header {
      background: linear-gradient(135deg, #e94560 0%, #ff6b6b 100%);
      border-radius: 16px 16px 0 0 !important;
      padding: 1.5rem;
    }
    .pwd-standalone-card .btn-primary {
      background: linear-gradient(135deg, #e94560 0%, #ff6b6b 100%);
      border: none;
    }
  </style>
</head>
<body>
<div class="container">
<?php else: ?>
<div class="container-fluid">
  <div class="row justify-content-center">
    <div class="col-xl-6 col-lg-7">
<?php endif; ?>

  <div class="card mx-auto <?= $embedded_in_layout ? 'shadow-sm' : 'pwd-standalone-card' ?>">
    <div class="card-header text-white text-center <?= $embedded_in_layout ? 'bg-primary' : '' ?>">
      <h4 class="mb-0 h5">
        <?php if ($isForced): ?>
          <i class="fas fa-shield-alt me-1"></i> Cambio de contraseña obligatorio
        <?php else: ?>
          <i class="fas fa-key me-1"></i> Cambiar contraseña
        <?php endif; ?>
      </h4>
    </div>
    <div class="card-body p-4">

      <?php if ($isForced && $reason): ?>
      <div class="alert alert-warning">
        <strong><i class="fas fa-exclamation-triangle me-1"></i> Atención:</strong><br>
        <?= htmlspecialchars($reason) ?>
      </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['password_error'])): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($_SESSION['password_error']) ?>
        <?php unset($_SESSION['password_error']); ?>
      </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['password_success'])): ?>
      <div class="alert alert-success">
        <?= htmlspecialchars($_SESSION['password_success']) ?>
        <?php unset($_SESSION['password_success']); ?>
      </div>
      <?php elseif ($pwd_ok && !$embedded_in_layout): ?>
      <div class="alert alert-success">Contraseña actualizada correctamente.</div>
      <?php endif; ?>

      <form method="post" action="<?= htmlspecialchars($form_action) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
        <input type="hidden" name="forced" value="<?= $isForced ? '1' : '0' ?>">

        <div class="mb-3">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="new_password" class="form-control" minlength="8" required
                 placeholder="Mínimo 8 caracteres" autocomplete="new-password">
          <div class="form-text">La contraseña debe tener al menos 8 caracteres.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Confirmar contraseña</label>
          <input type="password" name="confirm_password" class="form-control" minlength="8" required
                 placeholder="Repite la contraseña" autocomplete="new-password">
        </div>

        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">Guardar nueva contraseña</button>
          <?php if (!$isForced): ?>
          <a href="<?= htmlspecialchars($cancel_url) ?>" class="btn btn-outline-secondary">Volver al perfil</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

<?php if ($embedded_in_layout): ?>
    </div>
  </div>
</div>
<?php else: ?>
</div>
<script src="<?= htmlspecialchars(AppHelpers::publicAssetUrl('assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>" defer></script>
</body>
</html>
<?php endif; ?>
