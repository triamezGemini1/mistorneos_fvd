<?php
if (!ob_get_level()) {
    ob_start();
}
require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
AuthService::requireAuth();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: profile.php', true, 302);
    exit;
}
require_once __DIR__ . '/../modules/users/profile_save.php';
