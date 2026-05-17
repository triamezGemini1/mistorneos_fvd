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
$entidad_actual = $_SESSION['user']['entidad'] ?? 0;
$email_actual = $_SESSION['user']['email'] ?? '';
$telegram_chat_id_actual = '';
try {
    $stmt = DB::pdo()->prepare("SELECT telegram_chat_id FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $telegram_chat_id_actual = $stmt->fetchColumn() ?: '';
} catch (Exception $e) { }
$photo_actual = $_SESSION['user']['photo_path'] ?? '';
$photo_url = '';
if ($photo_actual) {
    // Si viene con subruta, úsala; si no, asumir carpeta upload (legacy)
    $photo_url = (str_contains($photo_actual, '/'))
        ? AppHelpers::getBaseUrl() . '/' . ltrim($photo_actual, '/')
        : AppHelpers::getBaseUrl() . '/upload/' . $photo_actual;
}
$form_action = AppHelpers::url('profile_save.php');
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
$ok = isset($_GET['ok']) || isset($_GET['pwd_ok']);
$profile_error = isset($_GET['error']) ? (string) $_GET['error'] : '';
?>

<style>
.profile-card .profile-photo-container {
  position: relative;
  width: 140px;
  height: 140px;
  margin: 0 auto 1rem;
}
.profile-card .profile-photo {
  width: 140px;
  height: 140px;
  object-fit: cover;
  border-radius: 50%;
  border: 4px solid #e9ecef;
}
.profile-card .profile-photo-placeholder {
  width: 140px;
  height: 140px;
  border-radius: 50%;
  background: #f1f3f5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  color: #9aa0a6;
  border: 4px solid #e9ecef;
}
.profile-card .photo-upload-btn {
  position: absolute;
  bottom: 8px;
  right: 8px;
  background: #0d6efd;
  color: white;
  border-radius: 50%;
  width: 42px;
  height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border: 0;
}
</style>

<div class="container-fluid">
  <div class="row justify-content-center">
    <div class="col-xl-8 col-lg-9">
      <?php if ($ok): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fas fa-check-circle me-2"></i><?= isset($_GET['pwd_ok']) ? 'Contraseña actualizada correctamente.' : 'Perfil actualizado correctamente.' ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
      <?php if ($profile_error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars(urldecode($profile_error)) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="card shadow-sm profile-card mb-4">
        <div class="card-header bg-primary text-white d-flex align-items-center">
          <i class="fas fa-user-cog me-2"></i>
          <span>Mi Perfil</span>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4 text-center mb-4">
              <div class="profile-photo-container">
                <?php if ($photo_url): ?>
                  <img src="<?= htmlspecialchars($photo_url) ?>" alt="Foto" class="profile-photo">
                <?php else: ?>
                  <div class="profile-photo-placeholder">
                    <i class="fas fa-user"></i>
                  </div>
                <?php endif; ?>
                <label for="photo-input" class="photo-upload-btn" title="Cambiar foto">
                  <i class="fas fa-camera"></i>
                </label>
              </div>
              <form method="post" action="<?= htmlspecialchars($form_action) ?>" enctype="multipart/form-data" id="photo-form">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email_actual) ?>">
                <input type="hidden" name="entidad" value="<?= htmlspecialchars($entidad_actual) ?>">
                <input type="hidden" name="telegram_chat_id" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>">
                <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none" data-preview-target="profile-photo-preview">
                <div id="profile-photo-preview"></div>
                <div class="mt-2">
                  <button type="submit" class="btn btn-sm btn-primary">Guardar foto</button>
                </div>
              </form>
              <small class="text-muted">Clic en la cámara para cambiar foto</small>
            </div>

            <div class="col-md-8">
              <div class="card mb-3">
                <div class="card-header bg-light">
                  <strong><i class="fas fa-id-card me-2"></i>Información personal</strong>
                </div>
                <div class="card-body">
                  <form method="post" action="<?= htmlspecialchars($form_action) ?>" enctype="multipart/form-data">
                    <input type="hidden" name="telegram_chat_id" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>">
                    <div class="row">
                      <div class="col-md-6 mb-3">
                        <label class="form-label">ID Usuario</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user']['id'] ?? '') ?>" disabled>
                      </div>
                      <div class="col-md-6 mb-3">
                        <label class="form-label">Usuario</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user']['username'] ?? '') ?>" disabled>
                      </div>
                    </div>

                    <div class="row">
                      <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($email_actual) ?>">
                      </div>
                      <div class="col-md-6 mb-3">
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

                    <div class="d-flex justify-content-between">
                      <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(AppHelpers::url('modules/users/change_password.php')) ?>">Cambiar contraseña</a>
                      <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                  </form>
                </div>
              </div>

              <?php
              $telegram_bot_username = trim((string)($_ENV['TELEGRAM_BOT_USERNAME'] ?? ''));
              $telegram_bot_link = $telegram_bot_username ? 'https://t.me/' . ltrim($telegram_bot_username, '@') : '';
              $tiene_telegram = !empty(trim($telegram_chat_id_actual));
              ?>
              <div class="card mb-4 border-primary" id="telegram">
                <div class="card-header text-white" style="background: linear-gradient(135deg, #0088cc 0%, #229ED9 100%);">
                  <strong><i class="fab fa-telegram-plane me-2"></i>Recibe notificaciones por Telegram</strong>
                  <?php if ($tiene_telegram): ?>
                    <span class="badge bg-success ms-2"><i class="fas fa-check me-1"></i>Vinculado</span>
                  <?php endif; ?>
                </div>
                <div class="card-body">
                  <p class="mb-2"><strong>Ventajas:</strong> Recibe al instante avisos de nuevas rondas, torneos y resultados en tu celular.</p>
                  <p class="mb-2"><strong>Instrucciones (3 pasos):</strong></p>
                  <ol class="mb-3 small">
                    <li>Abre Telegram. <?php if ($telegram_bot_link): ?>
                      <a href="<?= htmlspecialchars($telegram_bot_link) ?>" class="btn btn-sm btn-outline-primary ms-1"><i class="fab fa-telegram-plane me-1"></i>Abrir bot</a> y envía <code>/start</code>
                    <?php else: ?>
                      Busca el bot del sistema y envía <code>/start</code>
                    <?php endif; ?>
                    </li>
                    <li>Busca <a href="https://t.me/userinfobot">@userinfobot</a>, inicia conversación y copia el número <strong>Id</strong>.</li>
                    <li>Pega el número abajo y Guardar.</li>
                  </ol>
                  <form method="POST" action="<?= htmlspecialchars($form_action) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email_actual) ?>">
                    <input type="hidden" name="entidad" value="<?= htmlspecialchars($entidad_actual) ?>">
                    <input type="hidden" name="photo_path" value="<?= htmlspecialchars($photo_actual) ?>">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-6">
                        <label class="form-label">Telegram Chat ID</label>
                        <input type="text" name="telegram_chat_id" class="form-control" value="<?= htmlspecialchars($telegram_chat_id_actual) ?>" placeholder="Ej: 123456789">
                      </div>
                      <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Guardar</button>
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

              <div class="card">
                <div class="card-header bg-light">
                  <strong><i class="fas fa-key me-2"></i>Cambiar contraseña</strong>
                </div>
                <div class="card-body">
                  <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(AppHelpers::dashboard('users/change_password')) ?>">
                    <i class="fas fa-key me-1"></i>Ir a cambiar contraseña
                  </a>
                  <div class="mt-2">
                    <a href="<?= htmlspecialchars(AppHelpers::url('modules/auth/forgot_password.php')) ?>" class="small">Olvidé mi contraseña</a>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
