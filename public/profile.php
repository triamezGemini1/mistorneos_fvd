<?php
declare(strict_types=1);

if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/ProfilePhotoService.php';

AuthService::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (($_POST['action'] ?? '') === 'upload_photo')) {
    $profileSelf = 'profile.php';
    try {
        $userId = (int)(Auth::id() ?? ($_SESSION['user']['id'] ?? 0));
        ProfilePhotoService::saveForUser(
            $userId,
            $_FILES['photo'] ?? [],
            $_SESSION['user']['photo_path'] ?? null
        );
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $profileSelf . '?photo_ok=1', true, 303);
        exit;
    } catch (Throwable $e) {
        error_log('profile.php upload_photo: ' . $e->getMessage());
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $profileSelf . '?error=' . rawurlencode($e->getMessage()), true, 303);
        exit;
    }
}

$user = Auth::user();
$page = 'users/profile';
include __DIR__ . '/includes/layout.php';
