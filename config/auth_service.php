<?php
/**
 * Gestión centralizada de sesiones y protección de rutas.
 * Encapsula session_start() (vía session_start_early) y la verificación de identidad para que
 * páginas como torneo_gestion sean seguras y ligeras: verificación antes de cargar BD/layout.
 *
 * Uso:
 *   require_once __DIR__ . '/../config/auth_service.php';
 *   AuthService::requireAuth();  // redirige a login si no hay sesión
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_start_early.php';
}
require_once __DIR__ . '/auth.php';

class AuthService {

    /**
     * Exige sesión válida; si no hay usuario logueado, redirige a login y termina.
     * Llamar al inicio de las páginas de gestión (antes de cargar BD/layout).
     */
    public static function requireAuth(): void {
        $user = Auth::user();
        if ($user !== null && is_array($user) && !empty($user)) {
            if (class_exists('FvdConfig', false)) {
                FvdConfig::anchorSession();
            }
            return;
        }
        if (headers_sent()) {
            return;
        }
        $login_url = self::loginUrl();
        $returnRel = self::buildLoginReturnUrl();
        if ($returnRel !== '') {
            $login_url .= (strpos($login_url, '?') !== false ? '&' : '?') . 'return_url=' . rawurlencode($returnRel);
        }
        if (function_exists('getenv') && getenv('SESSION_DEBUG')) {
            error_log('[SESSION] AuthService::requireAuth -> redirect a login | url=' . $login_url);
        }
        header('Location: ' . $login_url, true, 302);
        exit;
    }

    /**
     * URL relativa segura para login.php?return_url=? (misma validaci?n de caracteres que en login.php).
     * Solo front controller index.php con query no vac?o (ej. page=torneo_gestion).
     */
    private static function buildLoginReturnUrl(): string {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== 'index.php') {
            return '';
        }
        $q = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($q === '') {
            return '';
        }
        $candidate = 'index.php?' . $q;
        if (!preg_match('#^[a-zA-Z0-9_\-/\.\?=&]+$#', $candidate)) {
            return '';
        }
        return $candidate;
    }

    /**
     * URL absoluta o con base para login (subcarpeta respetada).
     */
    public static function loginUrl(): string {
        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $base = class_exists('AppHelpers', false)
                ? AppHelpers::canonicalizeStandalonePublicPath((string) URL_BASE)
                : rtrim((string) URL_BASE, '/') . '/';
            return rtrim($base, '/') . '/login.php';
        }
        $base = '';
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = dirname($_SERVER['SCRIPT_NAME']);
            if ($dir !== '.' && $dir !== '' && $dir !== '/') {
                $base = rtrim(str_replace('\\', '/', $dir), '/') . '/';
            }
        }
        return $base !== '' ? $base . 'login.php' : '/login.php';
    }

    /**
     * Indica si hay un usuario con sesión válida.
     */
    public static function isLoggedIn(): bool {
        $u = Auth::user();
        return $u !== null && is_array($u) && !empty($u);
    }
}
