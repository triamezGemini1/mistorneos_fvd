<?php
/**
 * Bootstrap para módulos admin_org (Administrador de Organización - admin_club).
 * Centraliza autenticación y helpers.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

Auth::requireRole(['admin_club', 'admin_general', 'admin_torneo']);
