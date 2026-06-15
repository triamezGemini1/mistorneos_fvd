<?php

declare(strict_types=1);

/**
 * Rutas web del monorepo integral_fvd / mistorneos_fvd1.
 *
 * Estructura física en producción:
 *   /{webRoot}/public/index.php              → Hub de entrada
 *   /{webRoot}/public/admin/index.php        → Panel administrativo FVD
 *   /{webRoot}/mistorneos_fvd/public/index.php → App torneos (torneo_gestion, etc.)
 */
final class IntegralUrl
{
    /** @var array<string, mixed>|null */
    private static ?array $ctx = null;

    /** @var bool|null */
    private static ?bool $enabledCache = null;

    /**
     * Monorepo desactivado por defecto. Solo standalone mistorneos_fvd/public/.
     * Activo únicamente si INTEGRAL_WEB_ROOT está definido en .env y la petición
     * corre bajo esa carpeta (caso excepcional; no usar en producción FVD actual).
     */
    public static function isEnabled(): bool
    {
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }

        self::$enabledCache = false;
        if (!class_exists('Env', false)) {
            return false;
        }

        $webRoot = trim((string) Env::get('INTEGRAL_WEB_ROOT', ''));
        if ($webRoot === '') {
            return false;
        }

        $currentPublic = self::detectCurrentPublicPathFromScript();
        if ($currentPublic === '' || !str_contains($currentPublic, '/' . trim($webRoot, '/') . '/')) {
            return false;
        }

        self::$enabledCache = true;

        return true;
    }

    /** Páginas que viven en la app anidada mistorneos_fvd/public (no en el hub). */
    private const TORNEOS_APP_PAGES = [
        'torneo_gestion',
        'tournament_admin',
        'registrants',
        'tournaments',
        'invitations',
        'notificaciones_masivas',
        'finances',
        'users',
        'clubs',
        'control_admin',
        'asociacion_panel',
        'solicitudes_asociacion',
        'admin_torneo_operadores',
        'estadisticas_torneos',
        'directorio_clubes',
        'invitacion_clubes',
        'payments',
        'admin_general',
        'admin_atletas_sync',
        'importacion_torneo_externo',
        'ranking_numfvd_admin',
        'ranking_numfvd_detalle',
        'reportes_pago_donacion',
        'op_especiales',
        'user_notificaciones',
    ];

    /**
     * @return array{
     *   is_monorepo: bool,
     *   web_root_segment: string,
     *   app_folder: string,
     *   hub_public_path: string,
     *   torneos_public_path: string,
     *   admin_public_path: string,
     *   current_public_path: string,
     *   running_in_hub: bool,
     *   running_in_torneos: bool
     * }
     */
    public static function context(): array
    {
        if (self::$ctx !== null) {
            return self::$ctx;
        }

        $appFolder = class_exists('FvdConfig', false) ? FvdConfig::APP_FOLDER : 'mistorneos_fvd';
        $webRootSeg = self::detectWebRootSegment();
        $currentPublic = self::detectCurrentPublicPathFromScript();

        $isMonorepo = $webRootSeg !== ''
            && $webRootSeg !== $appFolder
            && str_contains($currentPublic, '/' . $webRootSeg . '/');

        $prefix = $isMonorepo ? '/' . $webRootSeg : '';
        $hubPublic = $isMonorepo ? $prefix . '/public' : $prefix . '/' . $appFolder . '/public';
        $torneosPublic = $isMonorepo
            ? $prefix . '/' . $appFolder . '/public'
            : $prefix . '/' . $appFolder . '/public';
        $adminPublic = $isMonorepo ? $prefix . '/public/admin' : $prefix . '/' . $appFolder . '/public/admin';

        // Instalación plana /mistorneos_fvd/public (sin wrapper mistorneos_fvd1)
        if (!$isMonorepo) {
            if ($currentPublic !== '') {
                $torneosPublic = $currentPublic;
                $hubPublic = $currentPublic;
            } elseif (class_exists('FvdConfig', false)) {
                $torneosPublic = rtrim(FvdConfig::BASE_PATH, '/');
                $hubPublic = $torneosPublic;
            } else {
                $torneosPublic = '/' . $appFolder . '/public';
                $hubPublic = $torneosPublic;
            }
        }

        $runningInTorneos = str_contains($currentPublic, '/' . $appFolder . '/public')
            || ($currentPublic !== '' && $currentPublic === rtrim($torneosPublic, '/'));
        $runningInHub = $isMonorepo
            && str_contains($currentPublic, $prefix . '/public')
            && !$runningInTorneos;

        self::$ctx = [
            'is_monorepo' => $isMonorepo,
            'web_root_segment' => $webRootSeg,
            'app_folder' => $appFolder,
            'hub_public_path' => rtrim($hubPublic, '/'),
            'torneos_public_path' => rtrim($torneosPublic, '/'),
            'admin_public_path' => rtrim($adminPublic, '/'),
            'current_public_path' => rtrim($currentPublic, '/'),
            'running_in_hub' => $runningInHub,
            'running_in_torneos' => $runningInTorneos,
        ];

        return self::$ctx;
    }

    public static function isMonorepo(): bool
    {
        return (bool) (self::context()['is_monorepo'] ?? false);
    }

    /**
     * Path web (sin host) a public/ según la página destino.
     */
    public static function publicWebPathForPage(string $page = ''): string
    {
        $ctx = self::context();
        if (!$ctx['is_monorepo']) {
            return $ctx['current_public_path'] !== ''
                ? $ctx['current_public_path']
                : $ctx['torneos_public_path'];
        }

        if ($page !== '' && self::isTorneosAppPage($page)) {
            return $ctx['torneos_public_path'];
        }

        if ($page !== '' && (str_starts_with($page, 'admin/') || $page === 'admin_fvd')) {
            return $ctx['admin_public_path'];
        }

        // Dashboard home en hub; operativa de torneos en app anidada
        if ($ctx['running_in_torneos'] || ($page !== '' && self::isTorneosAppPage($page))) {
            return $ctx['torneos_public_path'];
        }

        return $ctx['hub_public_path'];
    }

    public static function isTorneosAppPage(string $page): bool
    {
        if ($page === '') {
            return false;
        }
        if (in_array($page, self::TORNEOS_APP_PAGES, true)) {
            return true;
        }

        return str_starts_with($page, 'torneo_')
            || str_starts_with($page, 'gestion_')
            || str_starts_with($page, 'asociacion/');
    }

    /**
     * URL absoluta a index.php?page=… respetando monorepo.
     */
    public static function dashboardUrl(string $page = 'home', array $params = []): string
    {
        $params['page'] = $page;
        return self::publicUrl('index.php', $params, $page);
    }

    /**
     * URL absoluta bajo public/ del destino correcto.
     */
    public static function publicUrl(string $relativePath = '', array $params = [], string $pageHint = ''): string
    {
        $page = $pageHint !== '' ? $pageHint : (string) ($params['page'] ?? '');
        $basePath = self::publicWebPathForPage($page);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (str_starts_with($rel, 'public/')) {
            $rel = substr($rel, 7);
        }

        $url = $scheme . '://' . $host . rtrim($basePath, '/') . ($rel !== '' ? '/' . $rel : '');
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /** Path web absoluto a public/ de la app torneos (para CSS/assets). */
    public static function torneosPublicWebPath(): string
    {
        return self::context()['torneos_public_path'];
    }

    /** Path web absoluto al hub. */
    public static function hubPublicWebPath(): string
    {
        return self::context()['hub_public_path'];
    }

    /**
     * Si la petición llegó al hub con page=torneo_gestion, redirige a la app anidada.
     */
    public static function redirectHubTorneosRequestsIfNeeded(): void
    {
        $ctx = self::context();
        if (!$ctx['is_monorepo'] || !$ctx['running_in_hub']) {
            return;
        }

        $page = trim((string) ($_GET['page'] ?? ''));
        if ($page === '' || !self::isTorneosAppPage($page)) {
            return;
        }

        $target = self::dashboardUrl($page, $_GET);
        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
            exit;
        }
    }

    private static function detectWebRootSegment(): string
    {
        $appFolder = class_exists('FvdConfig', false) ? FvdConfig::APP_FOLDER : 'mistorneos_fvd';
        $currentPublic = self::detectCurrentPublicPathFromScript();

        $haystack = implode(' ', array_filter([
            $_SERVER['SCRIPT_NAME'] ?? '',
            $_SERVER['PHP_SELF'] ?? '',
            parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            $currentPublic,
        ]));

        if (preg_match('#/([^/]+)/' . preg_quote($appFolder, '#') . '/public(?:/|$)#i', $haystack, $m)) {
            $seg = $m[1];
            if ($seg !== $appFolder && $currentPublic !== '' && str_contains($currentPublic, '/' . $seg . '/')) {
                return $seg;
            }
        }

        if (preg_match('#/([^/]+)/public(?:/|$)#i', $haystack, $m)) {
            $seg = $m[1];
            if (!in_array(strtolower($seg), ['public', 'api', $appFolder], true)
                && $currentPublic !== ''
                && str_contains($currentPublic, '/' . $seg . '/public')
                && !str_contains($currentPublic, '/' . $appFolder . '/public')) {
                return $seg;
            }
        }

        if (class_exists('Env', false)) {
            $fromEnv = trim((string) (Env::get('INTEGRAL_WEB_ROOT') ?? ''));
            if ($fromEnv !== '' && $currentPublic !== '' && str_contains($currentPublic, '/' . trim($fromEnv, '/') . '/')) {
                return trim($fromEnv, '/');
            }
        }

        return '';
    }

    private static function detectCurrentPublicPathFromScript(): string
    {
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
            if (preg_match('#^(.*/public)(/api)?$#', $dir, $m)) {
                $dir = $m[1];
            }
            if (preg_match('#/public$#', $dir) || str_contains($dir, '/public/')) {
                return $dir[0] === '/' ? $dir : '/' . $dir;
            }
        }

        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $path = rtrim((string) URL_BASE, '/');
            if (preg_match('#^(.*/public)(/api)?$#', $path, $m)) {
                $path = $m[1];
            }

            return $path[0] === '/' ? $path : '/' . ltrim($path, '/');
        }

        return '';
    }
}
