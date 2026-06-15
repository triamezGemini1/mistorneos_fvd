<?php
/**
 * Fragmento de head: favicon con ruta absoluta. Solo PNG (rendimiento ~88ms). Nunca .ico (363KB).
 */
if (!function_exists('core_favicon_path')) {
    function core_favicon_path(): string {
        if (defined('APP_FAVICON_PATH') && APP_FAVICON_PATH !== '') {
            return APP_FAVICON_PATH;
        }
        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $base = rtrim(URL_BASE, '/');
            return ($base === '' || $base === '/') ? '/favicon.png' : $base . '/favicon.png';
        }
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            if ($scriptDir !== '.' && $scriptDir !== '/' && (str_ends_with($scriptDir, 'public') || strpos($scriptDir, '/public') !== false)) {
                $path = rtrim($scriptDir, '/');
                if ($path !== '' && $path[0] === '/') {
                    return $path . '/favicon.png';
                }
            }
        }
        if (class_exists('FvdConfig', false)) {
            return rtrim(FvdConfig::BASE_PATH, '/') . '/favicon.png';
        }
        return '/mistorneos_fvd/public/favicon.png';
    }
}
$favicon_href = core_favicon_path();
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($favicon_href) ?>">
