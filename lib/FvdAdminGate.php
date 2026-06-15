<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdConfig.php';

/**
 * Modo restringido (FVD_ADMIN_ENABLED=false): apaga módulos de administración FVD
 * (afiliación, delegados, finanzas, comentarios portal, inscripción pública en línea).
 * La gestión de torneos (torneo_gestion y acciones asociadas) permanece habilitada.
 */
final class FvdAdminGate
{
    /** Páginas del dashboard fuera del ámbito torneos. */
    /** @var list<string> */
    private const BLOCKED_PAGES = [
        'asociacion_panel',
        'solicitudes_asociacion',
        'affiliate_requests',
        'admin_atletas_sync',
        'finanzas/resumen_asociacion',
        'finances',
        'payments',
        'cuentas_bancarias',
        'reportes_pago_usuarios',
        'reportes_pago_donacion',
        'ranking_numfvd_admin',
        'ranking_numfvd_detalle',
        'estadisticas_web',
        'analytics_uso',
        'control_admin',
        'fvd_guia_ui',
        'auditoria',
        'comments',
        'comments_public',
        'users',
        'clubs',
        'organizaciones',
        'mi_organizacion',
        'entidades',
        'calendario',
        'bannerclock',
    ];

    /** APIs públicas de afiliación FVD e inscripción en línea (landing / portal). */
    /** @var list<string> */
    private const BLOCKED_API_BASENAMES = [
        'fvd_afiliacion_check_cedula.php',
        'fvd_solicitar_carnet.php',
        'fvd_solicitar_traspaso.php',
        'reporte_donacion_admin.php',
    ];

    /** Scripts públicos de afiliación, donación FVD e inscripción en línea. */
    /** @var list<string> */
    private const BLOCKED_PUBLIC_SCRIPTS = [
        'inscribir_evento_masivo.php',
        'tournament_register.php',
        'affiliate_request.php',
        'reportar_donacion_reportes.php',
    ];

    public static function isEnabled(): bool
    {
        return FvdConfig::adminModuleEnabled();
    }

    public static function isRestricted(): bool
    {
        return !self::isEnabled();
    }

    public static function isBlockedPage(string $page): bool
    {
        $page = trim($page);
        if ($page === '') {
            return false;
        }
        if (in_array($page, self::BLOCKED_PAGES, true)) {
            return true;
        }

        foreach (['asociacion/', 'affiliate_requests/', 'finanzas/', 'finances/'] as $prefix) {
            if (str_starts_with($page, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isBlockedPublicScript(string $scriptBasename): bool
    {
        return in_array($scriptBasename, self::BLOCKED_PUBLIC_SCRIPTS, true);
    }

    public static function isBlockedApiScript(string $scriptBasename): bool
    {
        return in_array($scriptBasename, self::BLOCKED_API_BASENAMES, true);
    }

    public static function disabledMessage(): string
    {
        return 'Módulo de administración FVD desactivado. La gestión de torneos sigue disponible.';
    }

    public static function rejectPageIfDisabled(string $page): void
    {
        if (self::isEnabled() || !self::isBlockedPage($page)) {
            return;
        }

        self::redirectDashboardHome(self::disabledMessage());
    }

    public static function rejectApiIfDisabled(?string $scriptBasename = null): void
    {
        if (self::isEnabled()) {
            return;
        }

        $base = $scriptBasename ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($base !== '' && !self::isBlockedApiScript($base)) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'ok' => false,
            'message' => self::disabledMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function rejectPublicScriptIfDisabled(?string $scriptBasename = null): void
    {
        if (self::isEnabled()) {
            return;
        }

        $base = $scriptBasename ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($base !== '' && !self::isBlockedPublicScript($base)) {
            return;
        }

        self::renderPublicDisabledPage();
    }

    /** @deprecated alias */
    public static function rejectPublicAffiliateFormIfDisabled(): void
    {
        self::rejectPublicScriptIfDisabled('affiliate_request.php');
    }

    private static function redirectDashboardHome(string $msg): void
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        if (!headers_sent()) {
            header('Location: ' . AppHelpers::dashboard('home', ['error' => $msg]));
            exit;
        }

        http_response_code(403);
        echo '<div class="alert alert-warning m-4">' . htmlspecialchars($msg) . '</div>';
        exit;
    }

    private static function renderPublicDisabledPage(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $msg = htmlspecialchars(self::disabledMessage(), ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>No disponible</title></head>';
        echo '<body style="font-family:sans-serif;padding:2rem;max-width:520px;margin:0 auto;">';
        echo '<h1>Función desactivada</h1><p>' . $msg . '</p></body></html>';
        exit;
    }
}

