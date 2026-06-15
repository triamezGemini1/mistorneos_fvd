<?php

/**
 * Helper centralizado para la aplicación
 * Detecta automáticamente el entorno y simplifica la generación de URLs
 */
class AppHelpers {
    public static ?bool $is_production = null;
    public static ?string $base_url = null;

    private static function integralUrlLoaded(): bool
    {
        $path = __DIR__ . '/IntegralUrl.php';
        if (!is_file($path)) {
            return false;
        }
        if (!class_exists('IntegralUrl', false)) {
            require_once $path;
        }

        return IntegralUrl::isEnabled();
    }

    /**
     * Normaliza path web a public/ (con barras inicial/final).
     */
    public static function normalizePublicWebPath(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        if (!str_ends_with($path, '/public')) {
            if (str_ends_with($path, '/public/')) {
                return $path;
            }
            if (!str_contains($path, '/public/')) {
                $path = rtrim($path, '/') . '/public';
            }
        }

        return rtrim($path, '/') . '/';
    }

    /**
     * En instalación standalone FVD, reemplaza rutas legacy (pruebas, mistorneos_beta, monorepo)
     * por /mistorneos_fvd/public/.
     */
    public static function canonicalizeStandalonePublicPath(string $path): string
    {
        if (self::integralUrlLoaded()) {
            return self::normalizePublicWebPath($path);
        }

        $pathNorm = self::normalizePublicWebPath($path);
        $folder = self::getProjectFolder();
        if (preg_match('#/' . preg_quote($folder, '#') . '/public/#', $pathNorm)) {
            return $pathNorm;
        }

        $canonical = class_exists('FvdConfig', false)
            ? self::normalizePublicWebPath(FvdConfig::BASE_PATH)
            : '/mistorneos_fvd/public/';

        $legacyPrefixes = [
            '/pruebas/public/',
            '/mistorneos_beta/public/',
            '/mistorneos_fvd1/mistorneos_fvd/public/',
            '/mistorneos_fvd1/public/',
        ];
        foreach ($legacyPrefixes as $legacy) {
            if (str_starts_with($pathNorm, $legacy)) {
                $suffix = substr($pathNorm, strlen(rtrim($legacy, '/')));
                $suffix = ltrim(str_replace('\\', '/', $suffix), '/');
                $result = rtrim($canonical, '/') . ($suffix !== '' ? '/' . $suffix : '/');
                error_log('[URL] Ruta legacy canonizada: ' . $pathNorm . ' → ' . $result);
                return $result;
            }
        }

        return $pathNorm;
    }

    /**
     * Canoniza la parte path de una URL absoluta standalone (scheme + host + path).
     */
    public static function canonicalizeStandaloneUrl(string $url): string
    {
        if (self::integralUrlLoaded() || $url === '') {
            return $url;
        }

        if (!preg_match('#^https?://#i', $url)) {
            if (isset($url[0]) && $url[0] === '/') {
                if (preg_match('#^(.+?/public)(/.*)?$#', $url, $m)) {
                    $prefix = self::canonicalizeStandalonePublicPath($m[1] . '/');
                    return rtrim($prefix, '/') . ($m[2] ?? '');
                }
                return self::canonicalizeStandalonePublicPath($url);
            }
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['path'])) {
            return $url;
        }

        $path = $parts['path'];
        if (preg_match('#^(.+?/public)(/.*)?$#', $path, $m)) {
            $path = rtrim(self::canonicalizeStandalonePublicPath($m[1] . '/'), '/') . ($m[2] ?? '');
        } else {
            $path = self::canonicalizeStandalonePublicPath($path);
        }
        $newPath = $path;
        if ($newPath === $parts['path']) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $host . $port . rtrim($newPath, '/') . $query . $fragment;
    }

    /**
     * Resuelve URL_BASE: si BASE_PATH en .env no coincide con SCRIPT_NAME, gana la detección real.
     * Evita enlaces a /mistorneos_fvd1/… cuando la app está en /mistorneos_fvd/public/.
     */
    public static function resolveUrlBasePath(): string
    {
        $detected = self::detectPublicPathFromScript();
        if ($detected === '' || $detected === '/') {
            if (!empty($_SERVER['SCRIPT_NAME'])) {
                $dir = str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME']));
                if (preg_match('#^(.+?/public)/api$#', $dir, $m)) {
                    $dir = $m[1];
                }
                if ($dir !== '.' && $dir !== '' && $dir !== '/') {
                    $detected = '/' . trim($dir, '/') . '/';
                }
            }
        }

        $fromEnv = class_exists('Env', false) ? trim((string) Env::get('BASE_PATH', '')) : '';
        if ($fromEnv !== '') {
            $envPath = self::normalizePublicWebPath($fromEnv);
            if ($detected !== '' && $detected !== '/') {
                $detNorm = self::normalizePublicWebPath($detected);
                if (rtrim($envPath, '/') !== rtrim($detNorm, '/')) {
                    error_log('[URL_BASE] BASE_PATH .env (' . rtrim($envPath, '/') . ') difiere de SCRIPT (' . rtrim($detNorm, '/') . '); se usa canonización FVD.');
                }
            }

            return self::canonicalizeStandalonePublicPath($envPath);
        }

        if ($detected !== '' && $detected !== '/') {
            return self::canonicalizeStandalonePublicPath($detected);
        }

        $fallback = class_exists('FvdConfig', false) ? FvdConfig::BASE_PATH : '/mistorneos_fvd/public/';
        return self::canonicalizeStandalonePublicPath($fallback);
    }

    /**
     * Detecta segmentos de ruta obsoletos o de monorepo en URLs generadas.
     *
     * @return list<string>
     */
    public static function detectSuspiciousUrlSegments(string $url): array
    {
        $issues = [];
        $lower = strtolower($url);
        if (str_contains($lower, 'mistorneos_fvd1') && !str_contains($lower, '/' . self::getProjectFolder() . '/')) {
            $issues[] = 'contiene mistorneos_fvd1 (monorepo) fuera de la app standalone';
        }
        if (preg_match('#/mistorneos/public#', $lower) && !preg_match('#/mistorneos_fvd/public#', $lower)) {
            $issues[] = 'usa ruta antigua /mistorneos/public (debe ser /mistorneos_fvd/public)';
        }
        if (preg_match('#/public/public/#', $lower)) {
            $issues[] = 'doble /public/public/ en la URL';
        }

        return $issues;
    }
    
    /**
     * Detecta si estamos en producción
     */
    public static function isProduction(): bool {
        if (self::$is_production === null) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $server_name = $_SERVER['SERVER_NAME'] ?? '';
            
            // Indicadores de producción
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
                self::$base_url = rtrim(self::canonicalizeStandaloneUrl($fromEnv), '/');
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

            if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $publicPath = rtrim((string) URL_BASE, '/');
                $projectPath = preg_replace('#/public$#', '', $publicPath) ?: $publicPath;
                $expectedBase = rtrim($scheme . '://' . $host . $projectPath, '/');
                $currentPath = parse_url((string) self::$base_url, PHP_URL_PATH) ?: '';
                if ($currentPath !== $projectPath && $projectPath !== '') {
                    error_log('[APP_URL] Ajuste: APP_URL path (' . $currentPath . ') → URL_BASE (' . $projectPath . ')');
                    self::$base_url = $expectedBase;
                }
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
                $url = rtrim($scheme . '://' . $host . str_replace('\\', '/', $dir), '/');
                return rtrim(self::canonicalizeStandaloneUrl($url), '/');
            }
        }
        return rtrim(self::getPublicUrl(), '/');
    }
    
    /**
     * URL absoluta a un recurso bajo public/ (app standalone mistorneos_fvd).
     * Usar siempre este método en lugar de app_base_url() . '/public/…'.
     */
    public static function url(string $path = '', array $params = []): string {
        if (self::integralUrlLoaded()) {
            $page = (string) ($params['page'] ?? '');

            return IntegralUrl::publicUrl($path, $params, $page);
        }

        $base = self::getPublicUrl();
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'public/')) {
            $path = substr($path, 7);
        }
        $url = $base . ($path !== '' ? '/' . $path : '');
        
        // Agregar parámetros si existen
        if (!empty($params)) {
            $query_string = http_build_query($params);
            $url .= '?' . $query_string;
        }
        
        return $url;
    }
    
    /**
     * Página de inicio según rol.
     */
    public static function landingUrl(): string
    {
        if (!class_exists('Auth', false) || !Auth::user()) {
            return self::dashboard('home');
        }
        if (Auth::isOperativoSoloAsociacion()) {
            return self::dashboard('asociacion_panel');
        }

        return self::dashboard('home');
    }

    /**
     * Genera URL para el panel administrativo (index.php?page=…).
     */
    public static function dashboard(string $page = 'home', array $params = []): string {
        if (self::integralUrlLoaded()) {
            return IntegralUrl::dashboardUrl($page, $params);
        }

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
     * Genera URL para archivos específicos
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
     * URL absoluta a un endpoint bajo public/api/ (misma carpeta public/ del entry point).
     * En instalación standalone (mistorneos_fvd/public/) usa getRequestEntryUrl() — no monorepo.
     *
     * @param array<string, scalar> $params Query string opcional (nunca incluye page=…)
     */
    public static function api(string $endpoint, array $params = []): string {
        $endpoint = ltrim(str_replace('\\', '/', $endpoint), '/');
        if (str_starts_with($endpoint, 'api/')) {
            $endpoint = substr($endpoint, 4);
        }
        unset($params['page']);

        $rel = 'api/' . $endpoint;

        // Standalone: anclar al script actual (index.php en public/) — producción mistorneos_fvd original
        if (self::integralUrlLoaded()) {
            if (!IntegralUrl::isMonorepo()) {
                $entry = rtrim(self::getRequestEntryUrl(), '/');
                if ($entry !== '' && !str_ends_with($entry, '/api')) {
                    $url = $entry . '/' . $rel;
                    if ($params !== []) {
                        $url .= '?' . http_build_query($params);
                    }

                    return $url;
                }
            }
            $pageHint = trim((string) ($_GET['page'] ?? ''));

            return IntegralUrl::publicUrl($rel, $params, $pageHint);
        }

        $base = rtrim(self::getPublicUrl(), '/');
        $url = $base . '/' . $rel;
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
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
        if (self::integralUrlLoaded()) {
            $page = (string) ($_GET['page'] ?? '');

            return IntegralUrl::publicWebPathForPage($page);
        }

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

    /**
     * Path web a la carpeta public/ inferido desde SCRIPT_NAME (ej. /mistorneos_fvd/public/).
     * Usado por bootstrap.php para URL_BASE cuando BASE_PATH no está en .env.
     */
    public static function detectPublicPathFromScript(): string
    {
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME']));
            if (preg_match('#^(.+?/public)/api$#', $dir, $m)) {
                $dir = $m[1];
            }
            if (preg_match('#/public$#', $dir) || str_contains($dir, '/public/')) {
                if (!str_ends_with($dir, '/public')) {
                    if (preg_match('#^(.+/public)/#', $dir . '/', $m2)) {
                        $dir = $m2[1];
                    }
                }
                $trimmed = trim($dir, '/');

                return ($trimmed === '') ? '/' : '/' . $trimmed . '/';
            }
            if ($dir !== '.' && $dir !== '' && $dir !== '/') {
                return '/' . trim($dir, '/') . '/';
            }
        }

        $web = self::getPublicWebPath();
        if ($web !== '' && $web !== '/') {
            return rtrim($web, '/') . '/';
        }

        $fallback = class_exists('FvdConfig', false) ? FvdConfig::BASE_PATH : '/mistorneos_fvd/public/';
        return self::canonicalizeStandalonePublicPath($fallback);
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
     * Obtiene información del entorno para debugging
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

        return self::landingUrl();
    }

    /**
     * Resuelve return_to a URL absoluta del mismo host.
     */
    public static function resolveReturnToUrl(string $returnTo, ?string $fallback = null): string
    {
        $fallback = $fallback ?? self::landingUrl();
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
     * Comprueba si existe un archivo bajo la raíz del proyecto (upload/, public/, lib/, etc.).
     */
    public static function projectFileExists(string $relativePath): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return false;
        }
        $root = dirname(__DIR__);
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($full);
    }

    /**
     * Ruta relativa a public/ del logo institucional FVD (archivo estático).
     */
    public static function getBrandLogoRelativePath(): ?string
    {
        $candidates = [
            'public/assets/vendor/img/logofvd.png',
            'public/assets/img/logo-fvd.png',
            'public/assets/logo.png',
        ];
        foreach ($candidates as $rel) {
            if (self::projectFileExists($rel)) {
                return preg_replace('#^public/#', '', $rel) ?: null;
            }
        }

        return null;
    }

    /**
     * Logo institucional embebido (data URI) para PDF y documentos sin acceso HTTP.
     */
    public static function getBrandLogoDataUri(): ?string
    {
        $candidates = [
            'public/assets/vendor/img/logofvd.png',
            'public/assets/img/logo-fvd.png',
            'public/assets/logo.png',
            'lib/Assets/mislogos/logo4.png',
        ];
        $root = dirname(__DIR__);
        foreach ($candidates as $rel) {
            if (! self::projectFileExists($rel)) {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $data = @file_get_contents($full);
            if ($data === false || $data === '') {
                continue;
            }
            $mime = preg_match('/\.jpe?g$/i', $rel) ? 'image/jpeg' : 'image/png';

            return 'data:' . $mime . ';base64,' . base64_encode($data);
        }

        return null;
    }

    /**
     * URL del logo institucional FVD (assets estáticos; no depende de la BD).
     */
    public static function getBrandLogoUrl(bool $versioned = false): string
    {
        $rel = self::getBrandLogoRelativePath();
        if ($rel !== null) {
            $href = $versioned ? self::assetVersion($rel) : $rel;

            return rtrim(self::getPublicUrl(), '/') . '/' . $href;
        }
        $logo4 = __DIR__ . '/Assets/mislogos/logo4.png';
        if (is_file($logo4)) {
            return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png');
        }

        return rtrim(self::getPublicUrl(), '/') . '/' . self::assetVersion('assets/img/logo-fvd.png');
    }

    /**
     * Href del logo para layouts con &lt;base href=".../public/"&gt; (ruta bajo public/).
     */
    public static function getAppLogoHref(?string $basePrefix = null): string
    {
        $rel = self::getBrandLogoRelativePath();
        if ($rel !== null) {
            return self::assetHref($rel, $basePrefix);
        }

        $dbRel = self::resolveDbLogoPublicRelativePath();
        if ($dbRel !== null) {
            return self::assetHref($dbRel, $basePrefix);
        }

        return self::assetHref('assets/img/logo-fvd.png', $basePrefix);
    }

    /**
     * URL absoluta del logo (OG, emails, páginas sin &lt;base&gt;).
     * Prioriza el PNG estático FVD en public/assets/ (evita rutas BD rotas vía view_image).
     */
    public static function getAppLogo(): string
    {
        $rel = self::getBrandLogoRelativePath();
        if ($rel !== null) {
            return rtrim(self::getPublicUrl(), '/') . '/' . self::assetVersion($rel);
        }

        $dbUrl = self::resolveDbLogoAbsoluteUrl();
        if ($dbUrl !== null) {
            return $dbUrl;
        }

        return rtrim(self::getPublicUrl(), '/') . '/' . self::assetVersion('assets/img/logo-fvd.png');
    }

    /**
     * Ruta relativa a public/ si el logo en BD es un archivo bajo public/.
     */
    private static function resolveDbLogoPublicRelativePath(): ?string
    {
        $logoPath = self::resolveDbLogoProjectPath();
        if ($logoPath === null) {
            return null;
        }
        if (str_starts_with($logoPath, 'public/')) {
            $rel = preg_replace('#^public/#', '', $logoPath);

            return ($rel !== null && $rel !== '') ? $rel : null;
        }

        return null;
    }

    /**
     * URL absoluta del logo en BD cuando es servible (view_image o public/).
     */
    private static function resolveDbLogoAbsoluteUrl(): ?string
    {
        $logoPath = self::resolveDbLogoProjectPath();
        if ($logoPath === null) {
            return null;
        }

        $publicRel = self::resolveDbLogoPublicRelativePath();
        if ($publicRel !== null) {
            return rtrim(self::getPublicUrl(), '/') . '/' . self::assetVersion($publicRel);
        }

        $allowedPrefixes = ['upload/', 'uploads/', 'lib/Assets/'];
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($logoPath, $prefix)) {
                return self::imageUrl($logoPath);
            }
        }

        return null;
    }

    /**
     * Ruta del logo en organización maestra (BD), normalizada y verificada en disco.
     */
    private static function resolveDbLogoProjectPath(): ?string
    {
        if (!class_exists('FvdConfig', false)) {
            return null;
        }
        $row = FvdConfig::getOrganizacionMaestra();
        $logoPath = trim((string) ($row['logo'] ?? ''));
        if ($logoPath === '') {
            return null;
        }
        $logoPath = ltrim(str_replace('\\', '/', $logoPath), '/');
        if (!self::projectFileExists($logoPath)) {
            return null;
        }

        return $logoPath;
    }
    
    /**
     * Genera el HTML para mostrar el logo de la aplicación
     * @param string $class Clases CSS adicionales
     * @param string $alt Texto alternativo
     * @param int $height Altura en píxeles (por defecto 40)
     * @param bool $priority Si true, añade fetchpriority="high" para LCP (logo principal del dashboard)
     * @param string|null $basePrefix Prefijo &lt;base href&gt; del layout (public/); usa ruta relativa estable
     */
    public static function appLogo(string $class = '', string $alt = '', int $height = 40, bool $priority = false, ?string $basePrefix = null): string {
        if ($alt === '' && class_exists('FvdBranding', false)) {
            $alt = FvdBranding::nombre();
        } elseif ($alt === '') {
            $alt = 'Federación Venezolana de Dominó';
        }
        $logo_url = ($basePrefix !== null && $basePrefix !== '')
            ? self::getAppLogoHref($basePrefix)
            : self::getAppLogo();
        $class_attr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
        $priority_attr = $priority ? ' fetchpriority="high"' : '';
        return '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($alt) . '" height="' . $height . '"' . $class_attr . $priority_attr . '>';
    }

    /**
     * Normaliza ruta relativa de almacenamiento (upload/, lib/Assets/, etc.).
     */
    public static function normalizeStoragePath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if (strpos($path, 'public/') === 0) {
            $path = substr($path, 7);
        }

        return $path;
    }

    /**
     * Comprueba si existe un archivo bajo la raíz del proyecto (APP_ROOT).
     */
    public static function storageFileExists(?string $path): bool
    {
        $path = self::normalizeStoragePath($path);
        if ($path === '') {
            return false;
        }
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        return is_file($full);
    }

    /**
     * Resuelve photo_path de usuario (legacy: solo nombre de archivo) a ruta de almacenamiento existente.
     */
    public static function resolveUserPhotoStoragePath(?string $photoPath): string
    {
        $photoPath = self::normalizeStoragePath($photoPath);
        if ($photoPath === '') {
            return '';
        }
        if (self::storageFileExists($photoPath)) {
            return $photoPath;
        }
        if (!str_contains($photoPath, '/')) {
            foreach (['upload/' . $photoPath, 'uploads/photos/' . $photoPath] as $candidate) {
                if (self::storageFileExists($candidate)) {
                    return $candidate;
                }
            }
        }

        return $photoPath;
    }

    /**
     * URL pública de la foto de perfil de un usuario (vía view_image.php).
     */
    public static function userPhotoUrl(?string $photoPath): string
    {
        return self::imageUrl(self::resolveUserPhotoStoragePath($photoPath));
    }

    /**
     * URL absoluta para cualquier imagen (logos, fotos, etc.) en todas las pantallas.
     * Usa view_image.php; la URL es absoluta para que funcione con cualquier subpath (/pruebas/public/, /mistorneos_beta/public/, etc.).
     * @param string|null $path Ruta relativa al proyecto, ej: upload/logos/logo_1.jpg o lib/Assets/mislogos/logo4.png
     * @return string URL completa para src="..." o string vacío si no hay path
     */
    public static function imageUrl(?string $path): string {
        return self::publicImageUrl($path, null);
    }

    /**
     * URL pública de imagen vía view_image.php. Si $publicBaseUrl termina en /public/, se usa tal cual.
     * Solo devuelve URL si el archivo existe en disco (evita enlaces rotos en landing).
     *
     * @param string|null $publicBaseUrl Base absoluta de public/ (ej. https://dominio/mistorneos_fvd/public/)
     */
    public static function publicImageUrl(?string $path, ?string $publicBaseUrl = null): string
    {
        if ($path === null || $path === '') {
            return '';
        }
        if (strpos($path, 'http') === 0) {
            return $path;
        }
        $path = self::normalizeStoragePath($path);
        if ($path === '' || !self::storageFileExists($path)) {
            return '';
        }
        if ($publicBaseUrl !== null && $publicBaseUrl !== '') {
            return rtrim($publicBaseUrl, '/') . '/view_image.php?path=' . rawurlencode($path);
        }

        return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode($path);
    }
}

?>
