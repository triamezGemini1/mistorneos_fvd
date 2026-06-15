<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
if (empty($_SESSION['user'])) { header('Location: ' . AppHelpers::url('login.php')); exit; }
require_once __DIR__ . '/../../config/db.php';

// Obtener opciones de entidad
function getEntidadesOptions(): array {
    try {
        $pdo = DB::pdo();
        $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) return [];
        $codeCandidates = ['codigo','cod_entidad','id','code'];
        $nameCandidates = ['nombre','descripcion','entidad','nombre_entidad'];
        $codeCol = null; $nameCol = null;
        foreach ($cols as $c) {
            $f = strtolower($c['Field'] ?? $c['field'] ?? '');
            if (!$codeCol && in_array($f, $codeCandidates, true)) $codeCol = $c['Field'] ?? $c['field'];
            if (!$nameCol && in_array($f, $nameCandidates, true)) $nameCol = $c['Field'] ?? $c['field'];
        }
        if (!$codeCol && isset($cols[0]['Field'])) $codeCol = $cols[0]['Field'];
        if (!$nameCol && isset($cols[1]['Field'])) $nameCol = $cols[1]['Field'];
        if (!$codeCol || !$nameCol) return [];
        $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Perfil: no se pudo obtener entidades: " . $e->getMessage());
        return [];
    }
}

$entidades = getEntidadesOptions();
$entidad_actual = (int)($_SESSION['user']['entidad'] ?? 0);
$email_actual = (string)($_SESSION['user']['email'] ?? '');
$telegram_chat_id_actual = '';
$photo_actual = (string)($_SESSION['user']['photo_path'] ?? '');
$numfvd_actual = (int)($_SESSION['user']['numfvd'] ?? 0);
try {
    $pdoPerfil = DB::pdo();
    $tieneNumfvd = (bool)$pdoPerfil->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetch();
    $sqlPerfil = $tieneNumfvd
        ? 'SELECT email, entidad, photo_path, telegram_chat_id, numfvd FROM usuarios WHERE id = ? LIMIT 1'
        : 'SELECT email, entidad, photo_path, telegram_chat_id FROM usuarios WHERE id = ? LIMIT 1';
    $stmt = $pdoPerfil->prepare($sqlPerfil);
    $stmt->execute([$_SESSION['user']['id']]);
    $rowPerfil = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rowPerfil) {
        if (!empty($rowPerfil['email'])) {
            $email_actual = (string)$rowPerfil['email'];
            $_SESSION['user']['email'] = $email_actual;
        }
        if (array_key_exists('entidad', $rowPerfil)) {
            $entidad_actual = (int)$rowPerfil['entidad'];
            $_SESSION['user']['entidad'] = $entidad_actual;
        }
        if (!empty($rowPerfil['photo_path'])) {
            $photo_actual = (string)$rowPerfil['photo_path'];
            $_SESSION['user']['photo_path'] = $photo_actual;
        }
        $numfvd_actual = (int)($rowPerfil['numfvd'] ?? 0);
        $_SESSION['user']['numfvd'] = $numfvd_actual;
        $telegram_chat_id_actual = trim((string)($rowPerfil['telegram_chat_id'] ?? ''));
    }
} catch (Exception $e) {
    error_log('Perfil: no se pudo cargar datos de usuario: ' . $e->getMessage());
}
$carnet_fvd_label = $numfvd_actual > 0 ? (string)$numfvd_actual : 'Sin asignar';
$photo_url = AppHelpers::userPhotoUrl($photo_actual);
if ($photo_url !== '' && isset($_GET['photo_ok'])) {
    $photo_url .= (strpos($photo_url, '?') !== false ? '&' : '?') . 't=' . time();
}
$telegram_bot_username = trim((string)($_ENV['TELEGRAM_BOT_USERNAME'] ?? ''));
$telegram_bot_link = $telegram_bot_username ? 'https://t.me/' . ltrim($telegram_bot_username, '@') : '';
$tiene_telegram = !empty(trim($telegram_chat_id_actual));
$profile_asset_base = rtrim(AppHelpers::getPublicBaseHref(), '/');
$profile_script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$form_action = (str_ends_with($profile_script, '/profile.php') || str_ends_with($profile_script, '/public/profile.php'))
    ? 'profile_save.php'
    : AppHelpers::url('profile_save.php');
$form_photo_action = AppHelpers::url('profile.php');
$role_original = (string)($_SESSION['user']['role_original'] ?? ($_SESSION['user']['role'] ?? ''));
$role_mode_actual = (int)($_SESSION['user']['role_switch_mode'] ?? (($role_original === 'admin_general') ? 0 : 0));
$url_switch_role = AppHelpers::url('switch_role.php');
$current_uri = AppHelpers::returnToForPost();
$role_labels = [
  0 => '0 - Admin General',
  1 => '1 - Admin Organización',
  2 => '2 - Admin Torneo',
  3 => '3 - Operador',
  4 => '4 - Usuario Común',
];
?>

<?php
$ok = isset($_GET['ok']) || isset($_GET['pwd_ok']) || isset($_GET['photo_ok']);
$photo_ok = isset($_GET['photo_ok']);
$profile_error = isset($_GET['error']) ? (string) $_GET['error'] : '';
?>

<style>
.profile-card {
  font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
}
.profile-card .profile-layout {
  --fvd-profile-cyan: #00CAF9;
  --profile-photo-size: 168px;
}
.profile-card .profile-form-panel {
  background: var(--fvd-profile-cyan);
  border-radius: 12px;
  padding: 1.25rem 1.35rem 1.35rem;
  color: #053a47;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35);
}
.profile-card .profile-form-panel .panel-title {
  font-size: 0.94rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #000;
  margin-bottom: 1rem;
}
.profile-card .profile-form-panel .form-label {
  font-size: 0.86rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #000;
  margin-bottom: 0.35rem;
}
.profile-card .profile-form-panel .form-control,
.profile-card .profile-form-panel .form-select {
  font-size: 1.14rem;
  font-weight: 700;
  border: 0;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.92);
  color: #000;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}
.profile-card .profile-form-panel .form-control:disabled {
  background: rgba(255, 255, 255, 0.72);
  color: #000;
  font-weight: 700;
}
.profile-card .profile-side-card {
  font-size: 1.02rem;
}
.profile-card .profile-side-card .card-header {
  font-size: 0.9rem;
  font-weight: 700;
  color: #000;
}
.profile-card .profile-side-card .form-label {
  font-size: 0.86rem;
  font-weight: 700;
  color: #000;
}
.profile-card .profile-side-card .form-control {
  font-size: 1.08rem;
  font-weight: 700;
  color: #000;
}
.profile-card .profile-side-card .btn,
.profile-card .profile-side-card a {
  font-weight: 700;
}
.profile-card .profile-form-panel .form-control:focus,
.profile-card .profile-form-panel .form-select:focus {
  background: #fff;
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.55);
}
.profile-card .profile-form-panel .carnet-fvd-value {
  font-size: 1.26rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #000;
}
.profile-card .profile-photo-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
}
.profile-card .profile-photo-container {
  position: relative;
  width: var(--profile-photo-size);
  height: var(--profile-photo-size);
  margin: 0 auto 0.75rem;
}
.profile-card .profile-photo {
  width: var(--profile-photo-size);
  height: var(--profile-photo-size);
  object-fit: cover;
  border-radius: 50%;
  border: 4px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 8px 24px rgba(3, 66, 82, 0.18);
}
.profile-card .profile-photo-placeholder {
  width: var(--profile-photo-size);
  height: var(--profile-photo-size);
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 58px;
  color: #067d99;
  border: 4px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 8px 24px rgba(3, 66, 82, 0.12);
}
.profile-card .photo-upload-btn {
  position: absolute;
  bottom: 10px;
  right: 10px;
  background: #034252;
  color: #fff;
  border-radius: 50%;
  width: 50px;
  height: 50px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border: 2px solid #fff;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.profile-card .photo-upload-btn:hover {
  background: #022f3a;
}
@media (max-width: 991.98px) {
  .profile-card .profile-photo-col {
    order: -1;
    margin-bottom: 1.25rem;
  }
}
</style>

<div class="container-fluid">
  <div class="row justify-content-center">
    <div class="col-xl-8 col-lg-9">
      <?php if ($ok): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fas fa-check-circle me-2"></i><?= isset($_GET['pwd_ok']) ? 'Contraseña actualizada correctamente.' : ($photo_ok ? 'Foto actualizada correctamente.' : 'Perfil actualizado correctamente.') ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
      <?php if ($profile_error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($profile_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="card shadow-sm profile-card mb-4">
        <div class="card-header bg-primary text-white d-flex align-items-center">
          <i class="fas fa-user-cog me-2"></i>
          <span>Mi Perfil</span>
        </div>
        <div class="card-body profile-layout">
          <div class="row g-4 align-items-start">
            <div class="col-lg-8">
              <div class="profile-form-panel mb-3">
                <div class="panel-title"><i class="fas fa-id-card me-2"></i>Información personal</div>
                <form method="post" action="<?= htmlspecialchars($form_action) ?>" enctype="multipart/form-data">
                  <input type="hidden" name="telegram_chat_id" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>">
                  <div class="row g-3">
                    <div class="col-6 col-md-3 mb-1">
                      <label class="form-label">ID Usuario</label>
                      <input type="text" class="form-control" value="<?= htmlspecialchars((string)($_SESSION['user']['id'] ?? '')) ?>" disabled>
                    </div>
                    <div class="col-6 col-md-3 mb-1">
                      <label class="form-label">Carnet FVD</label>
                      <input type="text" class="form-control carnet-fvd-value" value="<?= htmlspecialchars($carnet_fvd_label) ?>" disabled>
                    </div>
                    <div class="col-12 col-md-6 mb-1">
                      <label class="form-label">Usuario</label>
                      <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user']['username'] ?? '') ?>" disabled>
                    </div>
                    <div class="col-md-6 mb-1">
                      <label class="form-label">Email</label>
                      <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($email_actual) ?>">
                    </div>
                    <div class="col-md-6 mb-1">
                      <label class="form-label">Entidad (Ubicación)</label>
                      <select name="entidad" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <?php if (!empty($entidades)): ?>
                          <?php foreach ($entidades as $ent): ?>
                            <option value="<?= htmlspecialchars($ent['codigo']) ?>" <?= ($entidad_actual == $ent['codigo']) ? 'selected' : '' ?>>
                              <?= htmlspecialchars($ent['nombre'] ?? $ent['codigo']) ?>
                            </option>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <option value="" disabled>No hay entidades disponibles</option>
                        <?php endif; ?>
                      </select>
                    </div>
                  </div>

                  <div class="d-flex justify-content-end mt-3 pt-1">
                    <button type="submit" class="btn btn-sm btn-dark px-4 fw-bold">Guardar</button>
                  </div>
                </form>
              </div>

              <div class="card mb-3 border-primary profile-side-card" id="telegram">
                <div class="card-header text-white" style="background: linear-gradient(135deg, #0088cc 0%, #229ED9 100%);">
                  <strong><i class="fab fa-telegram-plane me-2"></i>Recibe notificaciones por Telegram</strong>
                  <?php if ($tiene_telegram): ?>
                    <span class="badge bg-success ms-2"><i class="fas fa-check me-1"></i>Vinculado</span>
                  <?php endif; ?>
                </div>
                <div class="card-body">
                  <p class="mb-2 fw-bold text-dark">Recibe al instante avisos de nuevas rondas, torneos y resultados en tu celular.</p>
                  <ol class="mb-3">
                    <li>Abre Telegram. <?php if ($telegram_bot_link): ?>
                      <a href="<?= htmlspecialchars($telegram_bot_link) ?>" class="btn btn-sm btn-outline-primary ms-1" target="_blank" rel="noopener"><i class="fab fa-telegram-plane me-1"></i>Abrir bot</a> y envía <code>/start</code>
                    <?php else: ?>
                      Busca el bot del sistema y envía <code>/start</code>
                    <?php endif; ?>
                    </li>
                    <li>Busca <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a>, inicia conversación y copia el número <strong>Id</strong>.</li>
                    <li>Pega el número abajo y Guardar.</li>
                  </ol>
                  <form method="POST" action="<?= htmlspecialchars($form_action) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email_actual) ?>">
                    <input type="hidden" name="entidad" value="<?= htmlspecialchars((string)$entidad_actual) ?>">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-8">
                        <label class="form-label">Telegram Chat ID</label>
                        <input type="text" name="telegram_chat_id" class="form-control" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>" placeholder="Ej: 123456789">
                      </div>
                      <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">Guardar</button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>

              <?php if ($role_original === 'admin_general'): ?>
              <div class="card mb-4 border-warning">
                <div class="card-header bg-warning-subtle">
                  <strong><i class="fas fa-user-shield me-2"></i>Selector de perfil operativo</strong>
                </div>
                <div class="card-body">
                  <p class="mb-2 text-muted">
                    Como admin general puedes simular permisos de otros perfiles para pruebas.
                  </p>
                  <form method="POST" action="<?= htmlspecialchars($url_switch_role) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($current_uri) ?>">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-8">
                        <label class="form-label">Rol operativo</label>
                        <select class="form-select" name="role_mode">
                          <?php foreach ($role_labels as $k => $lbl): ?>
                            <option value="<?= (int)$k ?>" <?= $role_mode_actual === (int)$k ? 'selected' : '' ?>>
                              <?= htmlspecialchars($lbl) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-4">
                        <button type="submit" class="btn btn-warning w-100">
                          <i class="fas fa-vial me-1"></i>Aplicar perfil
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <div class="col-lg-4 profile-photo-col">
              <form method="post" action="<?= htmlspecialchars($form_photo_action) ?>" enctype="multipart/form-data" id="photo-form" class="w-100 text-center">
                <input type="hidden" name="action" value="upload_photo">
                <div class="profile-photo-container">
                  <?php if ($photo_url): ?>
                    <img src="<?= htmlspecialchars($photo_url) ?>" alt="Foto" class="profile-photo" id="profile-photo-img">
                  <?php else: ?>
                    <div class="profile-photo-placeholder" id="profile-photo-placeholder">
                      <i class="fas fa-user"></i>
                    </div>
                  <?php endif; ?>
                  <label for="photo-input" class="photo-upload-btn" title="Cambiar foto">
                    <i class="fas fa-camera"></i>
                  </label>
                </div>
                <input type="file" name="photo" id="photo-input" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" style="display:none" data-preview-mode="inline" data-preview-inline="#profile-photo-img, #profile-photo-placeholder">
                <div class="mt-2">
                  <button type="submit" class="btn btn-sm btn-dark px-3 fw-semibold">Guardar foto</button>
                </div>
              </form>
              <small class="text-muted d-block mt-2 fw-bold" style="font-size:0.96rem;color:#000;">JPG, PNG, GIF o WebP · máx. 2 MB</small>

              <div class="card w-100 mt-3 profile-side-card">
                <div class="card-header bg-light py-2">
                  <strong><i class="fas fa-key me-1"></i>Cambiar contraseña</strong>
                </div>
                <div class="card-body p-3 d-flex flex-column text-center">
                  <p class="mb-3 fw-bold text-dark">Actualiza tu clave de acceso al sistema.</p>
                  <a class="btn btn-outline-primary w-100 fw-bold" href="<?= htmlspecialchars(AppHelpers::dashboard('users/change_password')) ?>">
                    <i class="fas fa-key me-1"></i>Ir a cambiar contraseña
                  </a>
                  <a href="<?= htmlspecialchars(AppHelpers::url('modules/auth/forgot_password.php')) ?>" class="small fw-bold text-dark mt-2">Olvidé mi contraseña</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= htmlspecialchars(AppHelpers::assetHref('assets/image-preview.js', $profile_asset_base)) ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.FvdImagePreview) {
    window.FvdImagePreview.init();
  }
});
</script>
