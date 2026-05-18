<?php

/**
 * Helper centralizado para la aplicaci�n
 * Detecta autom�ticamente el entorno y simplifica la generaci�n de URLs
 */
class AppHelpers {
    public static ?bool $is_production = null;
    public static ?string $base_url = null;
    
    /**
     * Detecta si estamos en producci�n
     */
    public static function isProduction(): bool {
        if (self::$is_production === null) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $server_name = $_SERVER['SERVER_NAME'] ?? '';
            
            // Indicadores de producci�n
            self::$is_production = (
                strpos($host, 'laestacion') !== false ||
                strpos($host, 'laestaciondeldomino.com') !== false ||
                strpos($host, 'laestaciondeldominohoy.com') !== false ||
                strpos($host, 'mistorneos.com') !== false ||
                strpos($server_name, 'laestacion') !== false ||
                (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' && !strpos($host, 'localhost'))
            );
        }
        
        return self::$is_production;
    }
    
    /**
     * Obtiene la URL base de la aplicación (raíz del proyecto, sin /public).
     * Detecta automáticamente localhost vs producción: en localhost usa /mistorneos_fvd
     * si APP_URL no está definida; en producción se recomienda definir APP_URL en .env.
     */
    public static function getProjectFolder(): string
    {
        return class_exists('FvdConfig', false) ? FvdConfig::APP_FOLDER : 'mistorneos_fvd';
    }

    /**
     * Path del proyecto bajo el host (ej. /mistorneos_fvd).
     */
    public static function getProjectPath(): string
    {
        $folder = self::getProjectFolder();
        if (isset($_SERVER['REQUEST_URI'])) {
            $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($uriPath && preg_match('#/' . preg_quote($folder, '#') . '(/|$)#', $uriPath)) {
                return '/' . $folder;
            }
        }
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            if (preg_match('#/' . preg_quote($folder, '#') . '(/|$)#', $scriptDir)) {
                return '/' . $folder;
            }
            if (str_ends_with($scriptDir, '/public') || strpos($scriptDir, '/public/') !== false) {
                $derived = $scriptDir === '/public' ? '' : rtrim(preg_replace('#/public/?$#', '', $scriptDir), '/');
                if ($derived !== '' && str_contains($derived, $folder)) {
                    return $derived[0] === '/' ? $derived : '/' . $derived;
                }
            }
        }
        return '/' . $folder;
    }

    public static function getBaseUrl(): string {
        if (self::$base_url === null) {
            $fromEnv = class_exists('Env') ? Env::get('APP_URL') : null;
            $fromConfig = $GLOBALS['APP_CONFIG']['app']['base_url'] ?? null;

            if (!empty($fromEnv)) {
                self::$base_url = rtrim($fromEnv, '/');
            } elseif (!empty($fromConfig) && $fromConfig !== '/') {
                $cfg = $fromConfig;
                if (!preg_match('#^https?://#', $cfg)) {
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    self::$base_url = $protocol . '://' . $host . $cfg;
                } else {
                    self::$base_url = rtrim($cfg, '/');
                }
            }
            if (self::$base_url === null) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $hostLower = strtolower($host);
                $isLocalhost = ($hostLower === 'localhost' || $hostLower === '127.0.0.1'
                    || strpos($hostLower, 'localhost:') === 0 || strpos($hostLower, '127.0.0.1:') === 0);
                $path = $isLocalhost ? self::getProjectPath() : self::getProjectPath();
                if ($path === '/' && !empty($_SERVER['SCRIPT_NAME'])) {
                    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                    if ($scriptDir !== '.' && $scriptDir !== '' && $scriptDir !== '/') {
                        if (str_ends_with($scriptDir, '/public') || strpos($scriptDir, '/public/') !== false) {
                            $path = $scriptDir === '/public' ? '' : rtrim(preg_replace('#/public/?$#', '', $scriptDir), '/');
                            if ($path !== '' && $path[0] !== '/') {
                                $path = '/' . $path;
                            }
                        }
                    }
                }
                self::$base_url = $protocol . '://' . $host . $path;
            }
            if (str_ends_with(self::$base_url, '/public')) {
                self::$base_url = rtrim(substr(self::$base_url, 0, -7), '/');
            }
        }
        return self::$base_url;
    }

    /**
     * URL de la carpeta public/ (assets, index.php, etc.)
     * Si está definida URL_BASE (ej. /pruebas/public/), se usa para anclar a la subcarpeta.
     */
    public static function getPublicUrl(): string {
        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = rtrim(URL_BASE, '/');
            // Si la petición es a public/api/..., la base debe ser public/, no public/api/
            if (preg_match('#^(.*/public)/api(/.*)?$#', $path)) {
                $path = preg_replace('#^(.*/public)/api(/.*)?$#', '$1', $path);
            } elseif (preg_match('#^(.*)/api$#', $path)) {
                $path = preg_replace('#^(.*)/api$#', '$1', $path);
            }
            return $scheme . '://' . $host . $path;
        }
        return rtrim(self::getBaseUrl(), '/') . '/public';
    }

    /**
     * Base URL del entry point actual (SCRIPT_NAME), para que redirects no se vayan a la raíz del dominio.
     * Uso: header('Location: ' . AppHelpers::getRequestEntryUrl() . '/index.php');
     * En /pruebas/public/ o /mistorneos_beta/public/ devuelve la URL de esa carpeta.
     */
    public static function getRequestEntryUrl(): string {
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = dirname($_SERVER['SCRIPT_NAME']);
            if ($dir !== '.' && $dir !== '' && $dir !== '/') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                return rtrim($scheme . '://' . $host . str_replace('\\', '/', $dir), '/');
            }
        }
        return rtrim(self::getPublicUrl(), '/');
    }
    
    /**
     * Genera URL para cualquier archivo de la aplicaci�n
     * SIMPLIFICADO: Siempre usar /public/ para archivos PHP (como en desarrollo)
     */
    public static function url(string $path = '', array $params = []): string {
        $base = self::getPublicUrl();
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'public/')) {
            $path = substr($path, 7);
        }
        $url = $base . ($path !== '' ? '/' . $path : '');
        
        // Agregar par�metros si existen
        if (!empty($params)) {
            $query_string = http_build_query($params);
            $url .= '?' . $query_string;
        }
        
        return $url;
    }
    
    /**
     * Genera URL para el dashboard
     */
    public static function dashboard(string $page = 'home', array $params = []): string {
        $params['page'] = $page;
        return self::url('index.php', $params);
    }

    /** URL segura a torneo_gestion (siempre vía public/index.php; evita enlaces rotos a modules/). */
    public static function torneoGestionUrl(string $action, int $torneoId, array $extra = []): string {
        return self::url('index.php', array_merge([
            'page' => 'torneo_gestion',
            'action' => $action,
            'torneo_id' => $torneoId,
        ], $extra));
    }

    /**
     * URL para "Volver al panel" según rol (operativo asociación → asociacion_panel).
     */
    public static function urlPanelTorneoReturn(int $torneoId = 0, array $extra = []): string
    {
        if (class_exists('Auth') && Auth::isOperativoSoloAsociacion()) {
            $params = $extra;
            if ($torneoId > 0) {
                $params['torneo_id'] = $torneoId;
            }

            return self::dashboard('asociacion_panel', $params);
        }

        return self::torneoGestionUrl('panel', $torneoId, $extra);
    }
    
    /**
     * Genera URL para archivos espec�ficos
     */
    public static function file(string $filename, array $params = []): string {
        return self::url($filename, $params);
    }
    
    /**
     * Genera URL para logout
     */
    public static function logout(): string {
        return self::url('logout.php');
    }
    
    /**
     * Genera URL para login
     */
    public static function login(): string {
        return self::url('login.php');
    }
    
    /**
     * Genera URL para invitaciones simples
     */
    public static function simpleInvitation(int $torneoId, int $clubId): string {
        return self::url('simple_invitation_login.php', [
            'torneo' => $torneoId,
            'club' => $clubId
        ]);
    }
    
    /**
     * Genera URL para archivos de torneo
     */
    public static function tournamentFile(string $filePath): string {
        return self::url('view_tournament_files.php', ['file' => $filePath]);
    }
    
    /**
     * Genera URL para endpoints de API
     */
    public static function api(string $endpoint, array $params = []): string {
        return self::url('api/' . ltrim($endpoint, '/'), $params);
    }
    
    /**
     * Obtiene el path relativo correcto para archivos públicos
     * (usado en JavaScript para AJAX calls)
     */
    public static function getPublicPath(): string {
        $base = self::getBaseUrl();
        $parsed = parse_url($base);
        $path = $parsed['path'] ?? self::getProjectPath();
        return rtrim($path, '/') . '/public/';
    }

    /** Ruta absoluta en disco a la carpeta public/ */
    public static function getPublicRootDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
    }

    /**
     * Ruta relativa bajo public/ con cache-busting (?v=filemtime).
     * Ej.: assetVersion('assets/dist/output.css')
     */
    public static function assetVersion(string $relativeFromPublic): string
    {
        $rel = ltrim(str_replace('\\', '/', $relativeFromPublic), '/');
        $full = self::getPublicRootDir() . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $v = is_file($full) ? (string) filemtime($full) : (string) time();
        return $rel . '?v=' . $v;
    }

    /**
     * Ruta web absoluta (solo path) a public/, ej. /mistorneos_fvd/public
     * Prioriza DOCUMENT_ROOT para que en producción los CSS no apunten a la raíz del dominio.
     */
    public static function getPublicWebPath(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $public_dir = rtrim(str_replace('\\', '/', self::getPublicRootDir()), '/');
        if ($doc_root !== '' && str_starts_with(strtolower($public_dir), strtolower($doc_root))) {
            $path = substr($public_dir, strlen($doc_root));
            $cached = ($path === '' || $path === '/') ? '' : '/' . trim($path, '/');
            return $cached;
        }

        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $path = rtrim((string) URL_BASE, '/');
            if (preg_match('#^(.*/public)(/api)?$#', $path, $m)) {
                $path = $m[1];
            }
            $cached = ($path !== '' && $path[0] === '/') ? $path : '/' . ltrim($path, '/');
            return $cached;
        }

        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
            if (preg_match('#^(.*/public)(/api)?$#', $dir, $m)) {
                $dir = $m[1];
            }
            if ($dir !== '' && $dir !== '/') {
                $cached = ($dir[0] === '/') ? $dir : '/' . $dir;
                return $cached;
            }
        }

        $fallback = class_exists('FvdConfig', false) ? rtrim(FvdConfig::BASE_PATH, '/') : '/mistorneos_fvd/public';
        $cached = ($fallback !== '' && $fallback !== '/') ? $fallback : '/mistorneos_fvd/public';
        return $cached;
    }

    /** Base href para &lt;base&gt; (path absoluto con barra final). */
    public static function getPublicBaseHref(): string
    {
        $path = self::getPublicWebPath();
        if ($path === '') {
            return '/';
        }
        return rtrim($path, '/') . '/';
    }

    /** URL absoluta a un asset en public/ */
    public static function publicAssetUrl(string $relativeFromPublic): string
    {
        return rtrim(self::getPublicUrl(), '/') . '/' . self::assetVersion($relativeFromPublic);
    }

    /**
     * Href para layouts con &lt;base href=".../public/"&gt; (prefijo opcional).
     */
    public static function assetHref(string $relativeFromPublic, ?string $basePrefix = null): string
    {
        $href = self::assetVersion($relativeFromPublic);
        if ($basePrefix !== null && $basePrefix !== '') {
            return rtrim($basePrefix, '/') . '/' . $href;
        }
        return $href;
    }
    
    /**
     * Redirige a una URL
     */
    public static function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Redirige al dashboard
     */
    public static function redirectToDashboard(string $page = 'home', array $params = []): void {
        self::redirect(self::dashboard($page, $params));
    }

    /**
     * Redirige al origen (política: siempre regresar al origen salvo navegación expedita).
     * Usa return_to o from (POST/GET); si no hay, usa referrer mismo-origen; si no, fallback.
     */
    public static function redirectToOrigin(string $fallbackPage = 'home', array $fallbackParams = []): void {
        $origin = $_POST['return_to'] ?? $_GET['return_to'] ?? $_GET['from'] ?? '';
        if ($origin !== '') {
            $decoded = rawurldecode($origin);
            $safe = (strpos($decoded, 'http') !== 0);
            if (!$safe && isset($_SERVER['HTTP_HOST'])) {
                $host = parse_url($decoded, PHP_URL_HOST);
                $safe = ($host === null || $host === $_SERVER['HTTP_HOST']);
            }
            if ($safe) {
                self::redirect($decoded);
                return;
            }
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref !== '' && strpos($ref, 'http') === 0) {
            $refHost = parse_url($ref, PHP_URL_HOST);
            $curHost = $_SERVER['HTTP_HOST'] ?? '';
            if ($refHost === $curHost) {
                self::redirect($ref);
                return;
            }
        }
        self::redirectToDashboard($fallbackPage, $fallbackParams);
    }
    
    /**
     * Obtiene informaci�n del entorno para debugging
     */

    /**
     * Path + query seguro para return_to en formularios (switch rol, etc.).
     */
    public static function returnToForPost(): string
    {
        $publicPath = parse_url(self::getPublicUrl(), PHP_URL_PATH) ?: '';
        $publicPath = rtrim($publicPath, '/');

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri !== '') {
            $path = parse_url($uri, PHP_URL_PATH) ?: '';
            $query = parse_url($uri, PHP_URL_QUERY);
            if ($path !== '' && $path !== '/') {
                if ($publicPath !== '' && !str_starts_with($path, $publicPath)) {
                    if (preg_match('#^/index\.php#i', $path)) {
                        $path = $publicPath . $path;
                    } elseif (str_ends_with($path, '/profile.php') || $path === '/profile.php') {
                        $path = $publicPath . '/profile.php';
                    }
                }
                return $path . ($query !== null && $query !== '' ? '?' . $query : '');
            }
        }

        if (!empty($_GET['page'])) {
            $params = $_GET;
            unset($params['page']);
            $q = array_merge(['page' => (string) $_GET['page']], $params);
            return $publicPath . '/index.php?' . http_build_query($q);
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (str_ends_with($script, '/profile.php')) {
            return $publicPath . '/profile.php';
        }

        return $publicPath . '/index.php?page=home';
    }

    /**
     * Resuelve return_to a URL absoluta del mismo host.
     */
    public static function resolveReturnToUrl(string $returnTo, ?string $fallback = null): string
    {
        $fallback = $fallback ?? self::dashboard('home');
        $returnTo = trim($returnTo);
        if ($returnTo === '' || preg_match('#^(javascript|data):#i', $returnTo)) {
            return $fallback;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $hostsMatch = static function (string $urlHost) use ($host): bool {
            $a = strtolower(preg_replace('#:\d+$#', '', $urlHost));
            $b = strtolower(preg_replace('#:\d+$#', '', $host));
            if ($a === $b) {
                return true;
            }
            $local = ['localhost', '127.0.0.1'];
            return in_array($a, $local, true) && in_array($b, $local, true);
        };

        $hasDashboardPath = static function (string $path): bool {
            return str_contains($path, 'index.php') || str_ends_with($path, '/profile.php');
        };

        if (preg_match('#^https?://#i', $returnTo)) {
            $p = parse_url($returnTo);
            if (!$p || empty($p['host']) || !$hostsMatch($p['host'])) {
                return $fallback;
            }
            $path = $p['path'] ?? '';
            $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
            if ($path === '/' || $path === '' || !$hasDashboardPath($path)) {
                return $fallback;
            }
            $publicPath = parse_url(self::getPublicUrl(), PHP_URL_PATH) ?: '';
            if ($publicPath !== '' && preg_match('#^/index\.php#i', $path) && !str_starts_with($path, $publicPath)) {
                $path = rtrim($publicPath, '/') . $path;
            }
            return $scheme . '://' . $host . $path . $query;
        }

        if (str_starts_with($returnTo, '//')) {
            return $fallback;
        }

        if (preg_match('#^index\.php#i', $returnTo)) {
            return rtrim(self::getRequestEntryUrl(), '/') . '/' . ltrim($returnTo, '/');
        }

        if (str_starts_with($returnTo, '/')) {
            $pathOnly = strtok($returnTo, '?') ?: '';
            if ($pathOnly === '/' || !$hasDashboardPath($pathOnly)) {
                return $fallback;
            }
            $publicPath = parse_url(self::getPublicUrl(), PHP_URL_PATH) ?: '';
            if ($publicPath !== '' && preg_match('#^/index\.php#i', $pathOnly) && !str_starts_with($pathOnly, $publicPath)) {
                $returnTo = rtrim($publicPath, '/') . $returnTo;
            }
            return $scheme . '://' . $host . $returnTo;
        }

        if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $returnTo)) {
            return $fallback;
        }

        return rtrim(self::getRequestEntryUrl(), '/') . '/' . ltrim($returnTo, '/');
    }
    public static function getEnvironmentInfo(): array {
        return [
            'is_production' => self::isProduction(),
            'base_url' => self::getBaseUrl(),
            'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'https' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'server_name' => $_SERVER['SERVER_NAME'] ?? '',
        ];
    }
    
    /**
     * Obtiene la URL del logo principal.
     * Prioridad: public/assets/logo.png (estático) si existe; si no, view_image.php con lib/Assets/mislogos/logo4.png.
     */
    public static function getAppLogo(): string {
        if (class_exists('FvdConfig', false)) {
            $row = FvdConfig::getOrganizacionMaestra();
            if (!empty($row['logo'])) {
                $url = self::imageUrl((string) $row['logo']);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        $logo4 = __DIR__ . '/Assets/mislogos/logo4.png';
        if (is_file($logo4)) {
            return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png');
        }
        $publicLogo = __DIR__ . '/../public/assets/logo.png';
        if (is_file($publicLogo)) {
            return rtrim(self::getPublicUrl(), '/') . '/assets/logo.png';
        }
        return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png');
    }
    
    /**
     * Genera el HTML para mostrar el logo de la aplicación
     * @param string $class Clases CSS adicionales
     * @param string $alt Texto alternativo
     * @param int $height Altura en píxeles (por defecto 40)
     * @param bool $priority Si true, añade fetchpriority="high" para LCP (logo principal del dashboard)
     */
    public static function appLogo(string $class = '', string $alt = '', int $height = 40, bool $priority = false): string {
        if ($alt === '' && class_exists('FvdBranding', false)) {
            $alt = FvdBranding::nombre();
        } elseif ($alt === '') {
            $alt = 'Federación Venezolana de Dominó';
        }
        $logo_url = self::getAppLogo();
        $class_attr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
        $priority_attr = $priority ? ' fetchpriority="high"' : '';
        return '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($alt) . '" height="' . $height . '"' . $class_attr . $priority_attr . '>';
    }

    /**
     * URL absoluta para cualquier imagen (logos, fotos, etc.) en todas las pantallas.
     * Usa view_image.php; la URL es absoluta para que funcione con cualquier subpath (/pruebas/public/, /mistorneos_beta/public/, etc.).
     * @param string|null $path Ruta relativa al proyecto, ej: upload/logos/logo_1.jpg o lib/Assets/mislogos/logo4.png
     * @return string URL completa para src="..." o string vacío si no hay path
     */
    public static function imageUrl(?string $path): string {
        if ($path === null || $path === '') {
            return '';
        }
        if (strpos($path, 'http') === 0) {
            return $path;
        }
        $path = ltrim($path, '/\\');
        return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode($path);
    }
}

?>
