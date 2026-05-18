<?php
/**
 * Enlaces locales a CSS/JS de vendor (sin CDN).
 * Requiere $layout_asset_base (prefijo usado con <base href=".../">).
 */
declare(strict_types=1);

if (!function_exists('fvd_vendor_href')) {
    function fvd_vendor_href(string $relFromPublic, ?string $base = null): string
    {
        $rel = ltrim(str_replace('\\', '/', $relFromPublic), '/');
        $disk = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $v = is_file($disk) ? (string) filemtime($disk) : (string) time();
        $href = $rel . '?v=' . $v;
        if ($base !== null && $base !== '') {
            return rtrim($base, '/') . '/' . $href;
        }
        return $href;
    }
}

$__fvd_base = $layout_asset_base ?? '';
