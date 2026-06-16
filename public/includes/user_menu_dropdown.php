<?php
/**
 * Menú desplegable del usuario (perfil) — único punto de definición.
 * Se incluye desde el layout principal. Todas las URLs se generan con AppHelpers
 * para que el menú sea idéntico en cualquier página (usuarios, organizaciones,
 * inscripción en sitio, etc.) y el logout siempre funcione.
 *
 * Requiere: $user (array con username, role, etc.) — ya definido en layout.php
 */
if (!isset($user) || !is_array($user)) {
    return;
}
// Base absoluta para que el menú funcione en cualquier subruta (ej. /mistorneos_beta/public/)
$base = '';
if (class_exists('AppHelpers')) {
    $base = rtrim(AppHelpers::getPublicUrl(), '/');
    if ($base === '' && method_exists('AppHelpers', 'getRequestEntryUrl')) {
        $base = rtrim(AppHelpers::getRequestEntryUrl(), '/');
    }
}
$url_profile = $base ? $base . '/profile.php' : 'profile.php';
$url_change_password = class_exists('AppHelpers') ? AppHelpers::dashboard('users/change_password') : ($base ? $base . '/index.php?page=users/change_password' : 'index.php?page=users/change_password');
$url_logout = $base ? $base . '/logout.php' : 'logout.php';
$url_mi_organizacion = null;
if (class_exists('Auth') && Auth::isOperativoSoloAsociacion()) {
    if (!class_exists('AsociacionAdminHelper', false)) {
        require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
    }
    if (class_exists('DB', false)) {
        $url_mi_organizacion = AsociacionAdminHelper::urlMiOrganizacion(
            DB::pdo(),
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? '')
        );
    }
}
if ($url_mi_organizacion === null) {
    $orgIdMenu = class_exists('Auth') ? (Auth::getUserOrganizacionId() ?? 0) : 0;
    $url_mi_organizacion = ($orgIdMenu > 0)
        ? (class_exists('AppHelpers') ? AppHelpers::dashboard('organizaciones', ['id' => $orgIdMenu]) : ('index.php?page=organizaciones&id=' . (int)$orgIdMenu))
        : (class_exists('AppHelpers') ? AppHelpers::dashboard('mi_organizacion') : 'index.php?page=mi_organizacion');
}
$url_switch_role = $base ? $base . '/switch_role.php' : 'switch_role.php';
$role_original = (string)($user['role_original'] ?? $user['role'] ?? '');
$role_mode_actual = (int)($user['role_switch_mode'] ?? (($user['role'] ?? '') === 'admin_general' ? 0 : 0));
$role_labels = [
  0 => '0 - Admin General',
  1 => '1 - Admin Organización',
  2 => '2 - Admin Torneo',
  3 => '3 - Operador',
  4 => '4 - Usuario Común',
];
$current_uri = class_exists('AppHelpers') ? AppHelpers::returnToForPost() : ($_SERVER['REQUEST_URI'] ?? 'index.php?page=home');
?>
<!-- Menú usuario: centralizado para que todas las opciones (incl. logout) estén siempre disponibles -->
<div class="dropdown" id="user-menu-dropdown" data-bs-boundary="viewport">
  <button class="btn btn-outline-primary fvd-topbar-chip fvd-topbar-chip--user dropdown-toggle" type="button" id="userMenuButton" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true">
    <i class="fas fa-user me-2"></i>
    <?= htmlspecialchars($user['username'] ?? 'Usuario') ?>
  </button>
  <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenuButton">
    <li><span class="dropdown-item-text text-muted">Rol activo: <?= htmlspecialchars($user['role'] ?? '') ?></span></li>
    <?php if ($role_original === 'admin_general'): ?>
    <li><span class="dropdown-item-text text-muted">Rol base: admin_general</span></li>
    <li><hr class="dropdown-divider"></li>
    <li class="px-3 py-2">
      <form method="POST" action="<?= htmlspecialchars($url_switch_role) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($current_uri) ?>">
        <label class="form-label small text-muted mb-1">Switch perfil operativo</label>
        <div class="d-flex gap-2">
          <select class="form-select form-select-sm" name="role_mode">
            <?php foreach ($role_labels as $k => $lbl): ?>
              <option value="<?= (int)$k ?>" <?= $role_mode_actual === (int)$k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary">Aplicar</button>
        </div>
      </form>
    </li>
    <?php endif; ?>
    <?php if (in_array(($user['role'] ?? ''), ['admin_club', 'admin_general', 'admin_torneo'], true)): ?>
    <li><a class="dropdown-item" href="<?= htmlspecialchars($url_mi_organizacion) ?>"><i class="fas fa-building me-2"></i>Mi organización</a></li>
    <?php endif; ?>
    <li><a class="dropdown-item" href="<?= htmlspecialchars($url_profile) ?>"><i class="fas fa-id-card me-2"></i>Mi Perfil</a></li>
    <li><a class="dropdown-item" href="<?= htmlspecialchars($url_change_password) ?>"><i class="fas fa-key me-2"></i>Cambiar Contraseña</a></li>
    <li><hr class="dropdown-divider"></li>
    <li><a class="dropdown-item text-danger" href="<?= htmlspecialchars($url_logout) ?>" target="_self"><i class="fas fa-sign-out-alt me-2"></i>Cerrar sesión</a></li>
  </ul>
</div>
