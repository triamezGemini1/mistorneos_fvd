<?php
/**
 * Detecta la instalación (mistorneos_fvd, mistorneos_beta, etc.) para aislar sesión y front entre copias en el mismo dominio.
 */
declare(strict_types=1);

if (!function_exists('fvd_detect_install_slug')) {
    function fvd_detect_install_slug(): string
    {
        static $slug = null;
        if ($slug !== null) {
            return $slug;
        }

        $envSlug = getenv('APP_INSTALL_SLUG');
        if (is_string($envSlug) && $envSlug !== '') {
            $slug = preg_replace('/[^a-z0-9_-]/i', '', strtolower($envSlug)) ?: 'default';

            return $slug;
        }

        $haystack = implode(' ', array_filter([
            $_SERVER['SCRIPT_NAME'] ?? '',
            $_SERVER['PHP_SELF'] ?? '',
            parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
        ]));

        if (preg_match('#/(mistorneos_[a-z0-9_-]+)(?:/|$)#i', $haystack, $m)) {
            $slug = strtolower($m[1]);

            return $slug;
        }

        $slug = 'default';

        return $slug;
    }
}

if (!function_exists('fvd_session_cookie_path')) {
    /**
     * Path de la cookie de sesión PHP: una por instalación (/mistorneos_fvd/, /mistorneos_beta/, …).
     */
    function fvd_session_cookie_path(): string
    {
        $envPath = getenv('SESSION_COOKIE_PATH');
        if (is_string($envPath) && $envPath !== '') {
            $p = '/' . trim($envPath, '/') . '/';

            return $p === '//' ? '/' : $p;
        }

        $slug = fvd_detect_install_slug();
        if ($slug !== 'default' && str_starts_with($slug, 'mistorneos_')) {
            return '/' . $slug . '/';
        }

        return '/';
    }
}

if (!function_exists('fvd_client_storage_scope')) {
    /** Prefijo para localStorage / BroadcastChannel (misma instalación que la sesión). */
    function fvd_client_storage_scope(): string
    {
        return fvd_detect_install_slug();
    }
}
