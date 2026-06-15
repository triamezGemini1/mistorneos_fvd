<?php
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
require_once __DIR__ . '/../../config/db.php';
if (!function_exists('profileSaveBuildRedirectUrl')) {
    function profileSaveBuildRedirectUrl(array $params = []): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_ends_with($script, '/profile_save.php')) {
            $base = rtrim(dirname($script), '/');
            $url = $base . '/profile.php';
        } elseif (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $url = rtrim((string) URL_BASE, '/') . '/profile.php';
        } else {
            $url = AppHelpers::url('profile.php');
        }
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }
}

if (!function_exists('profileSaveRedirect')) {
    function profileSaveRedirect(array $params = []): void
    {
        $redirect = profileSaveBuildRedirectUrl($params);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: ' . $redirect, true, 302);
            exit;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        $safe = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="es"><head><meta charset="UTF-8">';
        echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
        echo '<title>Redirigiendo…</title></head><body>';
        echo '<p><a href="' . $safe . '">Continuar al perfil</a></p>';
        echo '<script>window.location.replace(' . json_encode($redirect) . ');</script>';
        echo '</body></html>';
        exit;
    }
}

if (!function_exists('profileSaveUploadErrorMessage')) {
    function profileSaveUploadErrorMessage(int $code): string
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return 'La imagen supera el tamaño máximo permitido (2 MB)';
        }
        if ($code === UPLOAD_ERR_PARTIAL) {
            return 'La imagen se subió solo parcialmente; intente de nuevo';
        }
        if ($code === UPLOAD_ERR_NO_FILE) {
            return 'Seleccione una imagen';
        }

        return 'Error al subir la imagen (código ' . $code . ')';
    }
}

if (!function_exists('profileSaveStorePhoto')) {
    function profileSaveStorePhoto(array $file, ?string $oldPhotoPath): string
    {
        $uploadCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadCode !== UPLOAD_ERR_OK) {
            throw new Exception(profileSaveUploadErrorMessage($uploadCode));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('No se recibió el archivo de imagen');
        }

        $root = defined('APP_ROOT') ? APP_ROOT : dirname(dirname(__DIR__));
        $upload_dir = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR;
        if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            throw new Exception('No se pudo crear la carpeta upload/ en el servidor');
        }
        if (!is_writable($upload_dir)) {
            throw new Exception('La carpeta upload/ no tiene permisos de escritura (use 755 o 775)');
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('Tipo de archivo no permitido. Use JPG, PNG, GIF o WebP');
        }
        if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new Exception('La imagen no debe superar 2 MB');
        }

        $new_name = uniqid('profile_', true) . '.' . $ext;
        $target = $upload_dir . $new_name;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new Exception('Error al guardar la imagen en el servidor');
        }

        $photo_path = 'upload/' . $new_name;
        if ($oldPhotoPath) {
            $old_resolved = $oldPhotoPath;
            if (method_exists('AppHelpers', 'resolveUserPhotoStoragePath')) {
                $old_resolved = AppHelpers::resolveUserPhotoStoragePath($oldPhotoPath);
            }
            if ($old_resolved !== '' && $old_resolved !== $photo_path) {
                $old_full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_resolved);
                if (is_file($old_full)) {
                    @unlink($old_full);
                }
            }
        }

        return $photo_path;
    }
}

try {
    if (empty($_SESSION['user'])) {
        header('Location: ' . AppHelpers::url('login.php'), true, 302);
        exit;
    }

    $pdo = DB::pdo();
    $userId = (int)$_SESSION['user']['id'];
    $action = trim((string)($_POST['action'] ?? 'save_profile'));

    if ($action === 'upload_photo') {
        require_once __DIR__ . '/../../lib/ProfilePhotoService.php';
        ProfilePhotoService::saveForUser($userId, $_FILES['photo'] ?? [], $_SESSION['user']['photo_path'] ?? null);
        profileSaveRedirect(['photo_ok' => 1]);
    }

    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        throw new Exception('Email inválido');
    }

    $entidad = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
    if ($entidad < 0) {
        throw new Exception('Entidad inválida');
    }
    $telegram_chat_id = isset($_POST['telegram_chat_id']) ? (trim($_POST['telegram_chat_id']) ?: null) : null;

    $photo_path = $_SESSION['user']['photo_path'] ?? null;
    if (!empty($_FILES['photo']['name'])) {
        $photo_path = profileSaveStorePhoto($_FILES['photo'], $photo_path);
    }

    $cols = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->fetch();
    $update_tg = (bool)$cols && array_key_exists('telegram_chat_id', $_POST);

    try {
        if ($update_tg) {
            $u = $pdo->prepare('UPDATE usuarios SET email=:e, photo_path=:p, entidad=:ent, telegram_chat_id=:tg WHERE id=:id');
            $u->execute([':e' => $email, ':p' => $photo_path, ':ent' => $entidad, ':tg' => $telegram_chat_id, ':id' => $userId]);
        } else {
            $u = $pdo->prepare('UPDATE usuarios SET email=:e, photo_path=:p, entidad=:ent WHERE id=:id');
            $u->execute([':e' => $email, ':p' => $photo_path, ':ent' => $entidad, ':id' => $userId]);
        }
    } catch (Exception $e) {
        error_log('profile_save UPDATE error: ' . $e->getMessage());
        $u = $pdo->prepare('UPDATE usuarios SET email=:e, photo_path=:p, entidad=:ent WHERE id=:id');
        $u->execute([':e' => $email, ':p' => $photo_path, ':ent' => $entidad, ':id' => $userId]);
        if ($update_tg) {
            try {
                $ut = $pdo->prepare('UPDATE usuarios SET telegram_chat_id=:tg WHERE id=:id');
                $ut->execute([':tg' => $telegram_chat_id, ':id' => $userId]);
                $_SESSION['user']['telegram_chat_id'] = $telegram_chat_id;
            } catch (Exception $e2) {
                error_log('profile_save telegram fallback: ' . $e2->getMessage());
            }
        }
    }

    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['photo_path'] = $photo_path;
    $_SESSION['user']['entidad'] = $entidad;
    if ($update_tg) {
        $_SESSION['user']['telegram_chat_id'] = $telegram_chat_id;
    }

    profileSaveRedirect(['ok' => 1]);
} catch (Throwable $e) {
    error_log('Perfil save error: ' . $e->getMessage());
    try {
        profileSaveRedirect(['error' => $e->getMessage()]);
    } catch (Throwable $e2) {
        error_log('Perfil save redirect error: ' . $e2->getMessage());
    }
    if (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    $back = profileSaveBuildRedirectUrl(['error' => $e->getMessage()]);
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $safeBack = htmlspecialchars($back, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="UTF-8"><title>Error al guardar</title></head><body>';
    echo '<p><strong>No se pudo guardar el perfil:</strong> ' . $msg . '</p>';
    echo '<p><a href="' . $safeBack . '">Volver al perfil</a></p></body></html>';
    exit;
}
