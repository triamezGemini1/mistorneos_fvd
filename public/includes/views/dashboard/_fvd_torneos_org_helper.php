<?php
/**
 * ¿Mostrar nombre de organización bajo el torneo? Oculta la org. maestra (FVD).
 */
if (!function_exists('fvd_dashboard_mostrar_org_torneo')) {
    function fvd_dashboard_mostrar_org_torneo(string $orgNombre): bool
    {
        $orgNombre = trim($orgNombre);
        if ($orgNombre === '') {
            return false;
        }
        if (!class_exists('FvdBranding', false) && is_file(dirname(__DIR__, 4) . '/lib/FvdBranding.php')) {
            require_once dirname(__DIR__, 4) . '/lib/FvdBranding.php';
        }
        if (class_exists('FvdBranding', false)) {
            if (strcasecmp($orgNombre, FvdBranding::nombre()) === 0) {
                return false;
            }
            if (strcasecmp($orgNombre, FvdBranding::siglas()) === 0) {
                return false;
            }
        }
        $upper = mb_strtoupper($orgNombre, 'UTF-8');

        return !(
            str_contains($upper, 'FEDERACION VENEZOLANA')
            || str_contains($upper, 'FEDERACIÓN VENEZOLANA')
            || preg_match('/\bFVD\b/u', $upper) === 1
        );
    }
}
