<?php
if (!defined('APP_BOOTSTRAPPED')) {
    define('APP_BOOTSTRAPPED', true);
}

/** Raíz real del proyecto (mistorneos_fvd), independiente del host o monorepo padre. */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_CONFIG_DIR')) {
    define('APP_CONFIG_DIR', __DIR__);
}

require_once APP_CONFIG_DIR . '/php_polyfills.php';

$utf8Bootstrap = APP_CONFIG_DIR . DIRECTORY_SEPARATOR . 'utf8.php';
if (is_file($utf8Bootstrap)) {
    require_once $utf8Bootstrap;
} else {
    // Fallback inline: mismo contenido que config/utf8.php (evita fatal si falta en el servidor).
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
        mb_http_output('UTF-8');
        mb_regex_encoding('UTF-8');
        if (function_exists('mb_language')) {
            mb_language('uni');
        }
    }
    ini_set('default_charset', 'UTF-8');
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isHtmlRequest = $accept === '' || stripos($accept, 'text/html') !== false;
        if ($isHtmlRequest && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: text/html; charset=UTF-8');
        }
    }
}

// Nota: El autoloader de Composer debe regenerarse con 'composer dump-autoload'
// if (file_exists(APP_ROOT . '/vendor/autoload.php')) {
//     require_once APP_ROOT . '/vendor/autoload.php';
// }

// Cargar clase Env para variables de entorno
require_once APP_ROOT . '/lib/Env.php';
Env::load(APP_ROOT . '/.env');

$envProductionPhp = APP_CONFIG_DIR . '/env.production.php';
if (is_file($envProductionPhp)) {
    $productionVars = require $envProductionPhp;
    if (is_array($productionVars)) {
        // En localhost no mezclar plantilla de producción (TU_USUARIO_AQUI rompe WAMP sin .env).
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $isLocalHost = ($host === 'localhost' || $host === '127.0.0.1'
            || str_starts_with($host, 'localhost:')
            || str_starts_with($host, '127.0.0.1:'));
        if (! $isLocalHost) {
            Env::mergeMissing($productionVars);
        }
    }
}

// Load environment configuration
require_once APP_CONFIG_DIR . '/environment.php';
$GLOBALS['APP_CONFIG'] = Environment::getConfig();

// Load centralized app helpers
require_once APP_ROOT . '/lib/app_helpers.php';
require_once APP_ROOT . '/lib/FvdConfig.php';
require_once APP_ROOT . '/lib/FvdBranding.php';

if (!defined('ORGANIZACION_ID')) {
    define('ORGANIZACION_ID', FvdConfig::ORGANIZACION_ID);
}
if (!defined('ORGANIZACION_NOMBRE')) {
    define('ORGANIZACION_NOMBRE', FvdConfig::ORGANIZACION_NOMBRE);
}

// Load logging helper
require_once APP_ROOT . '/lib/Log.php';

// =================================================================
// DETECCIÓN DE HTTPS (debe estar antes de configurar sesiones)
// =================================================================
// Detectar si estamos en HTTPS (necesario para configuración de cookies)
$is_https = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
);

// =================================================================
// VERIFICACIÓN Y REDIRECCIÓN HTTPS (selector de ambiente de trabajo)
// =================================================================
// Usa el mismo selector que el resto de la app: Environment (APP_ENV en .env o auto-detección).
// development → no redirigir a HTTPS. production → forzar HTTPS (salvo localhost).
$is_production = Environment::isProduction();
$is_web_request = isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
$host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
$is_localhost = ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0 || strpos($host, '127.0.0.1:') === 0);

// Opcional: FORCE_HTTPS=false en .env desactiva la redirección incluso en production
$force_https = !Env::has('FORCE_HTTPS') || Env::bool('FORCE_HTTPS', true);
$must_force_https = $force_https && $is_production && $is_web_request && !$is_https && !$is_localhost && !headers_sent();
if ($must_force_https) {
    $https_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header("Location: $https_url", true, 302); // 302 para que Edge y otros no cacheen la redirección
    exit;
}

// En desarrollo con localhost: si entraron por HTTPS, redirigir a HTTP con 302 (evita 301 cacheado y problemas de certificado)
if ($is_web_request && $is_localhost && $is_https && !$is_production && !headers_sent()) {
    $http_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header("Location: $http_url", true, 302);
    exit;
}

// =================================================================
// URL_BASE: ruta de la aplicación (subcarpeta en producción)
// =================================================================
// Producción FVD: BASE_PATH=/mistorneos_fvd/public/ en .env (véase config/env.production.php).
// Todas las redirecciones y enlaces deben usar: header("Location: " . URL_BASE . "index.php?page=...");
if (!defined('URL_BASE')) {
    $url_base_path = class_exists('AppHelpers', false) && method_exists('AppHelpers', 'resolveUrlBasePath')
        ? AppHelpers::resolveUrlBasePath()
        : FvdConfig::BASE_PATH;
    define('URL_BASE', $url_base_path);
}

// =================================================================
// SESIÓN: una sola fuente de verdad (session_start_early.php: path '/', nombre y tiempos desde .env).
// Scripts que solo incluían bootstrap (p. ej. public/api/index.php) abrían sesión con path=URL_BASE y
// lifetime=0, distinto de login/index → cookie distinta / sesión vacía en fetch ("Sesión expirada").
// =================================================================
if (session_status() === PHP_SESSION_ACTIVE && (getenv('SESSION_DEBUG') || defined('SESSION_DEBUG'))) {
    error_log('[SESSION_DEBUG] bootstrap.php | sesión ya activa | id=' . session_id());
}
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    require_once APP_CONFIG_DIR . '/session_start_early.php';
    if (session_status() === PHP_SESSION_NONE) {
        error_log('[SESSION] bootstrap.php: session_start_early no dejó sesión activa (¿headers enviados antes?).');
    }
}

FvdConfig::ensureSessionAnchorIfAuthenticated();

// =================================================================
// HEADERS DE SEGURIDAD Y ANTI-CACHÉ (evitar 304 en desarrollo)
// =================================================================
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Evitar 304 Not Modified: el navegador no revalidará con If-Modified-Since
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // CSP básico (Content Security Policy) - ajustar según necesidades
    // header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;");
}

function app_base_url(): string {
  // Usar el helper centralizado si está disponible
  if (class_exists('AppHelpers')) {
    return AppHelpers::getBaseUrl();
  }
  
  // Fallback al sistema anterior
  $base_url = $GLOBALS['APP_CONFIG']['app']['base_url'];
  
  // Si la URL base es solo '/', generar URL completa
  if ($base_url === '/') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
  }
  
  return rtrim($base_url, '/');
}
