<?php
/**
 * Punto de entrada al Dashboard (page=home)
 * Admin General: estadísticas de torneos (gestión de competencias).
 * Otros roles admin: estadísticas vía admin_dashboard.php.
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';

$user = Auth::user();
if ($user && Auth::isAdminGeneral()) {
    require __DIR__ . '/admin_general/actions/home.php';
    return;
}
require __DIR__ . '/admin_dashboard.php';
