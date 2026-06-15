<?php
/**
 * Alias legacy → estadisticas_web (vista unificada Umami).
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../lib/app_helpers.php';

$params = [];
if (isset($_GET['period']) && trim((string) $_GET['period']) !== '') {
    $params['period'] = trim((string) $_GET['period']);
}

header('Location: ' . AppHelpers::dashboard('estadisticas_web', $params), true, 302);
exit;
