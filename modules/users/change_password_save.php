<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

if (empty($_SESSION['user'])) {
    AppHelpers::redirect(AppHelpers::url('login.php'));
}

CSRF::validate();

$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$isForced = ($_POST['forced'] ?? '0') === '1';

$pwdPageParams = $isForced ? ['page' => 'users/change_password', 'force' => 1] : ['page' => 'users/change_password'];
$pwdPageUrl = AppHelpers::url('index.php', $pwdPageParams);

if (strlen($new_password) < 8) {
    $_SESSION['password_error'] = 'La contraseña debe tener al menos 8 caracteres';
    AppHelpers::redirect($pwdPageUrl);
}

if ($new_password !== $confirm_password) {
    $_SESSION['password_error'] = 'Las contraseñas no coinciden';
    AppHelpers::redirect($pwdPageUrl);
}

$weakPasswords = ['password', '12345678', 'admin123', 'password123', 'qwerty123'];
if (in_array(strtolower($new_password), $weakPasswords, true)) {
    $_SESSION['password_error'] = 'Por favor, elige una contraseña más segura. Evita contraseñas comunes.';
    AppHelpers::redirect($pwdPageUrl);
}

try {
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    $pdo = DB::pdo();
    $checkColumn = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'must_change_password'");
    if ($checkColumn->rowCount() > 0) {
        $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = :hash, must_change_password = 0, updated_at = NOW() WHERE id = :id");
    } else {
        $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
    }
    $stmt->execute([':hash' => $hash, ':id' => $_SESSION['user']['id']]);

    Auth::clearPasswordChangeFlag();

    $_SESSION['password_success'] = 'Contraseña actualizada correctamente';

    if ($isForced) {
        AppHelpers::redirectToDashboard('home');
    }

    AppHelpers::redirect(AppHelpers::url('profile.php', ['pwd_ok' => 1]));
} catch (Exception $e) {
    error_log('change_password_save: ' . $e->getMessage());
    $_SESSION['password_error'] = 'Error al actualizar la contraseña. Por favor, intenta de nuevo.';
    AppHelpers::redirect($pwdPageUrl);
}
