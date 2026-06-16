<?php

declare(strict_types=1);

/**
 * Permisos de acceso por rol (no bloquea el login).
 * - Todos: landing, perfil y consultas públicas.
 * - admin_general / superadmin: panel de torneos completo.
 * - operador: solo ingreso de resultados en torneo_gestion.
 */
final class TournamentAdminAccess
{
    /** @var list<string> */
    public const TORNEO_PANEL_ROLES = ['admin_general', 'superadmin', 'operador'];

    /** @var list<string> */
    private const FULL_TORNEO_ADMIN_ROLES = ['admin_general', 'superadmin'];

    /** @var list<string> */
    private const ROLE_ALIASES = [
        'admin gral' => 'admin_general',
        'admin_gral' => 'admin_general',
        'admin general' => 'admin_general',
        'admingral' => 'admin_general',
        'super_admin' => 'superadmin',
        'super admin' => 'superadmin',
    ];

    /** Dashboard: cualquier usuario autenticado */
    /** @var list<string> */
    private const PAGES_ANY_AUTHENTICATED = [
        'users/profile',
        'users/change_password',
        'user_notificaciones',
    ];

    /** Páginas / módulos de administración de torneos */
    /** @var list<string> */
    private const TORNEO_ADMIN_PAGES = [
        'torneo_gestion',
        'tournament_admin',
        'tournaments',
        'registrants',
        'op_especiales',
        'estadisticas_torneos',
        'notificaciones_masivas',
        'importacion_torneo_externo',
        'admin_torneo_operadores',
        'invitations',
        'player_invitations',
        'tournaments/invitation_link',
        'invitacion_clubes',
        'torneo_split_ranking',
    ];

    /** Operador: solo flujo de ingreso de resultados */
    /** @var list<string> */
    private const OPERADOR_TORNEO_ACTIONS = [
        'index',
        'registrar_resultados',
        'registrar_resultados_v2',
        'cuadricula',
        'hojas_anotacion',
        'guardar_resultados',
        'guardar_mesa_adicional',
        'switch_torneo_id',
    ];

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return '';
        }

        return self::ROLE_ALIASES[$role] ?? $role;
    }

    public static function roleOriginal(?array $user = null): string
    {
        if (!class_exists('Auth', false)) {
            return '';
        }
        $u = $user ?? Auth::user();
        if (!$u || !is_array($u)) {
            return '';
        }

        return self::normalizeRole((string) ($u['role_original'] ?? $u['role'] ?? ''));
    }

    public static function roleActive(?array $user = null): string
    {
        if (!class_exists('Auth', false)) {
            return '';
        }
        $u = $user ?? Auth::user();
        if (!$u || !is_array($u)) {
            return '';
        }

        return self::normalizeRole((string) ($u['role'] ?? ''));
    }

    /** admin_general o superadmin (cuenta real, incl. modo prueba desde admin_general) */
    public static function isFullTorneoAdmin(?array $user = null): bool
    {
        $original = self::roleOriginal($user);
        if (in_array($original, self::FULL_TORNEO_ADMIN_ROLES, true)) {
            return true;
        }

        return in_array(self::roleActive($user), self::FULL_TORNEO_ADMIN_ROLES, true);
    }

    public static function isOperador(?array $user = null): bool
    {
        if (self::isFullTorneoAdmin($user)) {
            return false;
        }

        return self::roleActive($user) === 'operador';
    }

    /** Puede entrar al módulo operativo de torneos (admin completo u operador; asociación en modo institucional). */
    public static function canAccessTorneoPanel(?array $user = null): bool
    {
        if (class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isEnabled()) {
            if (FvdInstitutionalScope::isAsociacionAppUser($user)) {
                return true;
            }

            return false;
        }

        if (self::isFullTorneoAdmin($user)) {
            return true;
        }

        return self::roleActive($user) === 'operador';
    }

    /** @deprecated alias */
    public static function canAccess(?array $user = null): bool
    {
        return self::canAccessTorneoPanel($user);
    }

    public static function isPageAllowedForAllAuthenticated(string $page): bool
    {
        $page = trim($page);
        if (in_array($page, self::PAGES_ANY_AUTHENTICATED, true)) {
            return true;
        }
        if ($page !== '' && strpos($page, 'users/profile') === 0) {
            return true;
        }

        return false;
    }

    public static function isTorneoAdminPage(string $page): bool
    {
        $page = trim($page);
        if ($page === '' || $page === 'home') {
            return false;
        }
        if (class_exists('FvdInstitutionalScope', false)
            && FvdInstitutionalScope::isEnabled()
            && FvdInstitutionalScope::isChampionshipPage($page)) {
            return false;
        }
        if (in_array($page, self::TORNEO_ADMIN_PAGES, true)) {
            return true;
        }
        foreach (['tournaments/', 'registrants/', 'invitations/', 'gestion_torneos/'] as $prefix) {
            if (strpos($page, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isOperadorTorneoActionAllowed(string $action): bool
    {
        $action = trim($action);
        if ($action === '') {
            $action = 'index';
        }

        return in_array($action, self::OPERADOR_TORNEO_ACTIONS, true);
    }

    /**
     * Filtra index.php?page=… según rol (sin bloquear login).
     */
    public static function rejectPageAccess(string $page, string $action = ''): void
    {
        if (self::isPageAllowedForAllAuthenticated($page)) {
            return;
        }

        if ($page === 'home' || $page === '') {
            if (self::isFullTorneoAdmin()) {
                return;
            }
            if (class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isAsociacionAppUser()) {
                return;
            }
            if (self::canAccessTorneoPanel()) {
                return;
            }
            self::redirectToPublicPortal();

            return;
        }

        if (class_exists('FvdInstitutionalScope', false)
            && FvdInstitutionalScope::isEnabled()
            && FvdInstitutionalScope::isChampionshipPage($page)) {
            if (!Auth::isAdminGeneral()) {
                self::redirectToPublicPortal();
            }

            return;
        }

        if ($page === 'admin_torneo_operadores') {
            if (class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isEnabled()) {
                if (Auth::isAdminGeneral()) {
                    FvdInstitutionalScope::redirectDashboardHome(FvdInstitutionalScope::disabledOperationalMessage());
                }
                self::redirectToPublicPortal();

                return;
            }
            if (self::isFullTorneoAdmin() || self::roleActive() === 'admin_club') {
                return;
            }
            self::redirectToPublicPortal();

            return;
        }

        if (self::isTorneoAdminPage($page)) {
            if (class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isEnabled()) {
                if (FvdInstitutionalScope::isAsociacionAppUser()
                    && in_array($page, ['torneo_gestion', 'tournament_admin'], true)) {
                    return;
                }
            }
            if (!self::canAccessTorneoPanel()) {
                self::redirectToPublicPortal();
            }
            if ($page === 'torneo_gestion' || $page === 'tournament_admin') {
                self::enforceOperadorTorneoAction($action);
            }

            return;
        }

        // Resto del dashboard administrativo FVD: admin_general o asociación (modo institucional)
        if (!self::isFullTorneoAdmin()) {
            if (class_exists('FvdInstitutionalScope', false)
                && FvdInstitutionalScope::isEnabled()
                && FvdInstitutionalScope::isAsociacionAppUser()) {
                return;
            }
            self::redirectToPublicPortal();
        }
    }

    public static function enforceOperadorTorneoAction(string $action): void
    {
        if (!self::isOperador()) {
            return;
        }

        $action = trim($action);
        if ($action === '') {
            $action = 'index';
        }

        if ($action === 'panel') {
            self::redirectOperadorToResultados();
        }

        if (!self::isOperadorTorneoActionAllowed($action)) {
            if (!headers_sent()) {
                $_SESSION['error'] = 'Como operador solo puede ingresar resultados de mesa.';
            }
            self::redirectOperadorToResultados(true);
        }
    }

    public static function requireTorneoPanelAccess(): void
    {
        if (!self::canAccessTorneoPanel()) {
            self::redirectToPublicPortal();
        }
    }

    public static function requireFullTorneoAdmin(): void
    {
        if (!self::isFullTorneoAdmin()) {
            self::redirectToPublicPortal();
        }
    }

    public static function requireTorneoPanelJson(): void
    {
        if (!class_exists('Auth', false) || !Auth::user()) {
            self::jsonDenied(401, 'Sesión expirada. Actualice la página e inicie sesión de nuevo.');
        }
        if (!self::canAccessTorneoPanel()) {
            self::jsonDenied(403, 'No tiene permiso para administrar torneos.');
        }
    }

    public static function requireFullTorneoAdminJson(): void
    {
        if (!class_exists('Auth', false) || !Auth::user()) {
            self::jsonDenied(401, 'Sesión expirada. Actualice la página e inicie sesión de nuevo.');
        }
        if (!self::isFullTorneoAdmin()) {
            self::jsonDenied(403, 'Acción reservada a administradores.');
        }
    }

    /** @deprecated */
    public static function requireAccess(): void
    {
        self::requireTorneoPanelAccess();
    }

    /** @deprecated */
    public static function requireAccessJson(): void
    {
        self::requireFullTorneoAdminJson();
    }

    /** URL tras login según rol */
    public static function postLoginUrl(string $entryBase): string
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        $entryBase = rtrim($entryBase, '/');

        if (class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isEnabled()) {
            if (Auth::isAdminGeneral()) {
                return $entryBase . '/index.php?page=home';
            }
            if (FvdInstitutionalScope::isAsociacionAppUser()) {
                return $entryBase . '/index.php?page=asociacion_panel';
            }

            return AppHelpers::publicPortalUrl();
        }

        if (self::canAccessTorneoPanel()) {
            if (self::isOperador()) {
                return $entryBase . '/index.php?page=torneo_gestion&action=index';
            }

            return $entryBase . '/index.php?page=home';
        }

        return AppHelpers::publicPortalUrl();
    }

    public static function redirectToPublicPortal(): void
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        $url = AppHelpers::publicPortalUrl();
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso restringido</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<p>No tiene permiso para acceder a esta sección.</p>';
        echo '<p><a href="' . htmlspecialchars($url) . '">Ir al portal público</a></p></body></html>';
        exit;
    }

    private static function redirectOperadorToResultados(bool $fromDeny = false): void
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        $torneoId = (int) ($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? $_SESSION['current_torneo_id'] ?? 0);
        $params = ['page' => 'torneo_gestion', 'action' => 'registrar_resultados'];
        if ($torneoId > 0) {
            $params['torneo_id'] = $torneoId;
        }
        $url = AppHelpers::dashboard('torneo_gestion', $params);
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }
    }

    private static function jsonDenied(int $code, string $message): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
        }
        echo json_encode([
            'ok' => false,
            'success' => false,
            'error' => $message,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
