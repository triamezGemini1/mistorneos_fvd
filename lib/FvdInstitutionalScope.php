<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdConfig.php';
require_once __DIR__ . '/AsociacionAdminHelper.php';

/**
 * App institucional FVD: organización, afiliados, campeonatos (CRUD) e inscripciones por asociación.
 * Sin operación en vivo de torneos (mesas, rondas, resultados, actas, operadores).
 *
 * Env: FVD_INSTITUTIONAL_ONLY=true (default) desactiva administración operativa de torneos.
 */
final class FvdInstitutionalScope
{
    /** CRUD / ajustes de campeonatos (metadatos). */
    /** @var list<string> */
    private const CHAMPIONSHIP_PAGES = [
        'tournaments',
    ];

    /** Módulos operativos de torneo — bloqueados salvo flujos de asociación acotados. */
    /** @var list<string> */
    private const OPERATIONAL_TORNEO_PAGES = [
        'torneo_gestion',
        'tournament_admin',
        'admin_torneo_operadores',
        'op_especiales',
        'importacion_torneo_externo',
        'estadisticas_torneos',
        'invitations',
        'player_invitations',
        'invitacion_clubes',
        'torneo_split_ranking',
        'registrants',
    ];

    public static function isEnabled(): bool
    {
        return FvdConfig::institutionalOnly();
    }

    public static function isChampionshipPage(string $page): bool
    {
        $page = trim($page);
        if (in_array($page, self::CHAMPIONSHIP_PAGES, true)) {
            return true;
        }

        return str_starts_with($page, 'tournaments/');
    }

    public static function isOperationalTorneoPage(string $page): bool
    {
        $page = trim($page);
        if ($page === '' || self::isChampionshipPage($page)) {
            return false;
        }
        if (in_array($page, self::OPERATIONAL_TORNEO_PAGES, true)) {
            return true;
        }

        foreach (['registrants/', 'invitations/', 'gestion_torneos/'] as $prefix) {
            if (str_starts_with($page, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Usuario de asociación (delegado / admin_club) en modo institucional. */
    public static function isAsociacionAppUser(?array $user = null): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (!class_exists('Auth', false)) {
            return false;
        }
        $u = $user ?? Auth::user();
        if (!$u || !is_array($u)) {
            return false;
        }
        $role = (string) ($u['role'] ?? '');
        if ($role === 'admin_club') {
            return true;
        }

        return Auth::isOperativoSoloAsociacion();
    }

    public static function disabledOperationalMessage(): string
    {
        return 'La administración operativa de torneos (mesas, rondas, resultados) no está disponible en este panel. Use la app de gestión de torneos o el módulo Campeonatos para crear y configurar eventos.';
    }

    /**
     * Gate central (llamar desde index.php antes del layout).
     */
    public static function enforceAccess(string $page, string $action = ''): void
    {
        if (!self::isEnabled() || !class_exists('Auth', false) || !Auth::user()) {
            return;
        }

        $page = trim($page);
        $action = trim($action);
        if ($action === '' && $page === 'torneo_gestion') {
            $action = 'index';
        }

        if (self::isChampionshipPage($page)) {
            if (!Auth::isAdminGeneral()) {
                self::redirectAsociacionPanel('Solo el administrador general puede gestionar campeonatos.');
            }

            return;
        }

        if (self::isAsociacionAppUser()) {
            self::enforceAsociacionAccess($page, $action);

            return;
        }

        if (self::isOperationalTorneoPage($page)) {
            if (Auth::isAdminGeneral()) {
                self::redirectDashboardHome(self::disabledOperationalMessage());
            }
            if (in_array((string) (Auth::user()['role'] ?? ''), ['operador', 'admin_torneo'], true)) {
                self::redirectToPublicPortal();
            }
        }
    }

    private static function enforceAsociacionAccess(string $page, string $action): void
    {
        if ($page === 'home' || $page === '') {
            self::redirectAsociacionPanel();

            return;
        }

        if (str_starts_with($page, 'asociacion/') || $page === 'asociacion_panel') {
            return;
        }

        if (!AsociacionAdminHelper::paginaPermitidaOperativo($page)) {
            self::redirectAsociacionPanel('No tiene permiso para acceder a esa sección. Use el panel de asociación.');
        }

        if ($page === 'organizaciones') {
            if (!class_exists('DB', false)) {
                require_once __DIR__ . '/../config/db.php';
            }
            $clubIdGet = (int) ($_GET['club_id'] ?? 0);
            if ($clubIdGet <= 0) {
                $urlClub = AsociacionAdminHelper::urlMiOrganizacion();
                if ($urlClub !== null && !headers_sent()) {
                    header('Location: ' . $urlClub);
                    exit;
                }
                self::redirectAsociacionPanel('No se encontró la asociación asignada a su usuario.');
            }
            if (!AsociacionAdminHelper::usuarioPuedeVerClubOperativo(DB::pdo(), $clubIdGet)) {
                self::redirectAsociacionPanel('No tiene permiso para ver esa asociación.');
            }
        }

        if (in_array($page, ['torneo_gestion', 'tournament_admin'], true)) {
            if ($page === 'torneo_gestion' && in_array($action, ['', 'index', 'panel', 'dashboard'], true)) {
                self::redirectAsociacionPanel();

                return;
            }
            $permitida = $page === 'torneo_gestion'
                ? AsociacionAdminHelper::accionTorneoGestionPermitida($action)
                : AsociacionAdminHelper::accionTournamentAdminPermitida($action);
            if (!$permitida) {
                self::redirectAsociacionPanel('Acción no permitida. Use inscripciones desde el panel de asociación.');
            }
        }
    }

    /** Bloquea entry points standalone de torneos operativos. */
    public static function rejectStandaloneOperationalEntry(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        if (self::isAsociacionAppUser()) {
            return;
        }
        self::redirectDashboardHome(self::disabledOperationalMessage());
    }

    public static function redirectDashboardHome(string $msg): void
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

    public static function redirectAsociacionPanel(string $msg = ''): void
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        $params = $msg !== '' ? ['error' => $msg] : [];
        if (!headers_sent()) {
            header('Location: ' . AppHelpers::dashboard('asociacion_panel', $params));
            exit;
        }
        http_response_code(403);
        echo '<div class="alert alert-warning m-4">' . htmlspecialchars($msg !== '' ? $msg : 'Redirigiendo al panel de asociación.') . '</div>';
        exit;
    }

    public static function redirectToPublicPortal(): void
    {
        if (!class_exists('TournamentAdminAccess', false)) {
            require_once __DIR__ . '/TournamentAdminAccess.php';
        }
        TournamentAdminAccess::redirectToPublicPortal();
    }

}
