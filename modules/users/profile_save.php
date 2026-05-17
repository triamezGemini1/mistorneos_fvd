<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../config/db.php';

try {
    if (empty($_SESSION['user'])) {
        $login = AppHelpers::url('login.php');
        header("Location: $login", true, 302);
        exit;
    }

    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if (!$email) { throw new Exception('Email inválido'); }

    $entidad = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
    if ($entidad < 0) { throw new Exception('Entidad inválida'); }
    $telegram_chat_id = isset($_POST['telegram_chat_id']) ? (trim($_POST['telegram_chat_id']) ?: null) : null;

    $pdo = DB::pdo();
    $photo_path = $_SESSION['user']['photo_path'] ?? null;

    if (!empty($_FILES['photo']['name'])) {
        $upload_dir = __DIR__ . '/../../upload/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array(strtolower($ext), $allowed)) { throw new Exception('Tipo de archivo no permitido'); }
        $new_name = uniqid('profile_') . '.' . $ext;
        $target = $upload_dir . $new_name;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            $photo_path = $new_name;
        } else {
            throw new Exception('Error al subir la foto');
        }
    }

    $cols = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->fetch();
    $update_tg = (bool)$cols && array_key_exists('telegram_chat_id', $_POST);

    try {
        if ($update_tg) {
            $u = $pdo->prepare("UPDATE usuarios SET email=:e, photo_path=:p, entidad=:ent, telegram_chat_id=:tg WHERE id=:id");
            $u->execute([':e'=>$email, ':p'=>$photo_path, ':ent'=>$entidad, ':tg'=>$telegram_chat_id, ':id'=>$_SESSION['user']['id']]);
        } else {
            $u = $pdo->prepare("UPDATE usuarios SET email=:e, photo_path=:p, entidad=:ent WHERE id=:id");
            $u->execute([':e'=>$email, ':p'=>$photo_path, ':ent'=>$entidad, ':id'=>$_SESSION['user']['id']]);
        }
    } catch (Exception $e) {
        error_log("profile_save UPDATE error: " . $e->getMessage());
        $u = $pdo->prepare("UPDATE usuarios SET email=:e, photo_path=:p, entidad=:ent WHERE id=:id");
        $u->execute([':e'=>$email, ':p'=>$photo_path, ':ent'=>$entidad, ':id'=>$_SESSION['user']['id']]);
        if ($update_tg) {
            try {
                $ut = $pdo->prepare("UPDATE usuarios SET telegram_chat_id=:tg WHERE id=:id");
                $ut->execute([':tg'=>$telegram_chat_id, ':id'=>$_SESSION['user']['id']]);
                $_SESSION['user']['telegram_chat_id'] = $telegram_chat_id;
            } catch (Exception $e2) {
                error_log("profile_save telegram fallback: " . $e2->getMessage());
            }
        }
    }

    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['photo_path'] = $photo_path;
    $_SESSION['user']['entidad'] = $entidad;
    if ($update_tg) {
        $_SESSION['user']['telegram_chat_id'] = $telegram_chat_id;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $fromProfile = str_ends_with($script, '/profile_save.php')
        || (isset($_SERVER['HTTP_REFERER']) && str_contains((string) $_SERVER['HTTP_REFERER'], 'profile.php'));
    $redirect = $fromProfile
        ? AppHelpers::url('profile.php', ['ok' => 1])
        : AppHelpers::url('index.php', ['page' => 'users/profile', 'ok' => 1]);
    header("Location: $redirect", true, 302);
    echo '<!doctype html><html><head>';
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect, ENT_QUOTES) . '">';
    echo '<script>window.location.href="' . htmlspecialchars($redirect, ENT_QUOTES) . '";</script>';
    echo '</head><body></body></html>';
    exit;
} catch (Throwable $e) {
    error_log("Perfil save error: " . $e->getMessage());
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $fromProfile = str_ends_with($script, '/profile_save.php')
        || (isset($_SERVER['HTTP_REFERER']) && str_contains((string) $_SERVER['HTTP_REFERER'], 'profile.php'));
    $error_redirect = $fromProfile
        ? AppHelpers::url('profile.php', ['error' => urlencode($e->getMessage())])
        : AppHelpers::url('index.php', ['page' => 'users/profile', 'error' => urlencode($e->getMessage())]);
    header("Location: $error_redirect", true, 302);
    echo '<!doctype html><html><head>';
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($error_redirect, ENT_QUOTES) . '">';
    echo '<script>window.location.href="' . htmlspecialchars($error_redirect, ENT_QUOTES) . '";</script>';
    echo '</head><body></body></html>';
    exit;
}
