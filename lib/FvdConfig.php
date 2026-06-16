<?php

declare(strict_types=1);

/**
 * Identidad institucional única: Federación Venezolana de Dominó (FVD).
 * Punto de anclaje global; no usar valores de GET/POST para organizacion_id.
 */
final class FvdConfig
{
    public const ORGANIZACION_ID = 1;
    public const ORGANIZACION_NOMBRE = 'FEDERACION VENEZOLANA DE DOMINO';
    public const ORGANIZACION_SIGLAS = 'FVD';

    /**
     * Fila territorial en `entidad` para la FVD: alcance etiquetado como "Nacional".
     * No se usa como filtro estricto de torneos (véase {@see entidadTerritorioEfectivaOrganizacion()}).
     */
    public const ENTIDAD_AMBITO_NACIONAL_ID = 999;

    /**
     * ID simbólica en `inscritos.inscrito_por` para inscripciones hechas en línea
     * desde el landing público (sin operador humano en sesión).
     */
    public const INSCRITO_POR_LANDING_PUBLICO = 9999;

    /**
     * Modo restringido (FVD_ADMIN_ENABLED=false): apaga afiliación, finanzas, supervisión
     * e inscripción en línea. Activos: estadísticas (home) y torneo_gestion.
     * Env: FVD_ADMIN_ENABLED=true reactiva todo lo anterior.
     */
    public static function adminModuleEnabled(): bool
    {
        $raw = $_ENV['FVD_ADMIN_ENABLED'] ?? getenv('FVD_ADMIN_ENABLED');
        if ($raw === false || $raw === null || $raw === '') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Panel institucional FVD (default true): sin operación en vivo de torneos.
     * false = reactivar mesas, rondas, resultados y módulos operativos en esta instalación.
     * Env: FVD_INSTITUTIONAL_ONLY=false
     */
    public static function institutionalOnly(): bool
    {
        $raw = $_ENV['FVD_INSTITUTIONAL_ONLY'] ?? getenv('FVD_INSTITUTIONAL_ONLY');
        if ($raw === false || $raw === null || $raw === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /** Carpeta del proyecto bajo el document root (WAMP: /mistorneos_fvd). */
    public const APP_FOLDER = 'mistorneos_fvd';

    public const BASE_PATH = '/mistorneos_fvd/public/';

    public static function localAppUrl(): string
    {
        return 'http://localhost/' . self::APP_FOLDER;
    }

    public static function localPublicUrl(): string
    {
        return self::localAppUrl() . '/public';
    }

    /** URL base de la app (respeta APP_URL / app_base_url / localhost FVD). */
    public static function resolveAppUrl(): string
    {
        $env = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        if (function_exists('app_base_url')) {
            return app_base_url();
        }
        if (class_exists('AppHelpers', false)) {
            return AppHelpers::getBaseUrl();
        }
        return self::localAppUrl();
    }

    /** URL de public/ (assets, APIs, view_file, etc.). */
    public static function resolvePublicUrl(): string
    {
        if (class_exists('AppHelpers', false)) {
            return AppHelpers::getPublicUrl();
        }
        if (defined('URL_BASE') && is_string(URL_BASE) && URL_BASE !== '' && URL_BASE !== '/') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $scheme . '://' . $host . rtrim(URL_BASE, '/');
        }
        return rtrim(self::resolveAppUrl(), '/') . '/public';
    }

    /** @var array<string, mixed>|null */
    private static ?array $maestraCache = null;

    private static bool $maestraWarmAttempted = false;

    public static function organizacionId(): int
    {
        return self::ORGANIZACION_ID;
    }

    /** La organización canónica FVD es federación de ámbito nacional. */
    public static function organizacionEsAmbitoNacional(?array $organizacion): bool
    {
        return is_array($organizacion)
            && (int) ($organizacion['id'] ?? 0) === self::ORGANIZACION_ID;
    }

    /**
     * Para SQL de alcance territorial (torneos/usuarios): la FVD se trata como 0 (nacional).
     */
    public static function entidadTerritorioEfectivaOrganizacion(?array $organizacion): int
    {
        if ($organizacion === null) {
            return 0;
        }
        if (self::organizacionEsAmbitoNacional($organizacion)) {
            return 0;
        }

        return (int) ($organizacion['entidad'] ?? 0);
    }

    /**
     * Ignora cualquier entrada externa (formulario, URL, API).
     */
    public static function resolveOrganizacionId(mixed $ignored = null): int
    {
        return self::ORGANIZACION_ID;
    }

    public static function clubResponsableTorneo(mixed $ignored = null): int
    {
        return self::ORGANIZACION_ID;
    }

    /**
     * Fija la organización en sesión tras login o en cada petición autenticada.
     */
    public static function anchorSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION['organizacion_id'] = self::ORGANIZACION_ID;
        $_SESSION['organizacion_nombre'] = self::ORGANIZACION_NOMBRE;
        $_SESSION['organizacion_siglas'] = self::ORGANIZACION_SIGLAS;
        $_SESSION['fvd_anchor'] = true;

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['organizacion_id'] = self::ORGANIZACION_ID;
        }
    }

    /**
     * Re-aplica el anclaje si hay usuario en sesión (evita drift por GET/POST legacy).
     */
    public static function ensureSessionAnchorIfAuthenticated(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || empty($user)) {
            return;
        }
        self::anchorSession();
    }

    /**
     * La FVD (id 1) siempre se considera activa y al día; sin validación SaaS.
     */
    public static function isOrganizacionOperativa(int $organizacionId): bool
    {
        return $organizacionId === self::ORGANIZACION_ID;
    }

    /** @alias isOrganizacionOperativa */
    public static function organizacionEstaActiva(int $organizacionId): bool
    {
        return self::isOrganizacionOperativa($organizacionId);
    }

    public static function organizacionAlDiaConPago(int $organizacionId): bool
    {
        return self::isOrganizacionOperativa($organizacionId);
    }

    /**
     * Carga en memoria la fila de organizaciones id = 1 (una vez por petición).
     */
    public static function warmOrganizacionMaestra(): void
    {
        if (self::$maestraWarmAttempted) {
            return;
        }
        self::$maestraWarmAttempted = true;

        if (self::$maestraCache !== null) {
            return;
        }

        self::$maestraCache = [
            'id' => self::ORGANIZACION_ID,
            'nombre' => self::ORGANIZACION_NOMBRE,
            'siglas' => self::ORGANIZACION_SIGLAS,
            'estatus' => 1,
        ];

        if (!class_exists('DB', false)) {
            return;
        }

        try {
            $pdo = DB::pdo();
            $cols = $pdo->query('SHOW COLUMNS FROM organizaciones')->fetchAll(PDO::FETCH_COLUMN);
            $colSet = array_map('strtolower', $cols);
            $select = ['id', 'nombre', 'estatus'];
            if (in_array('siglas', $colSet, true)) {
                $select[] = 'siglas';
            }
            if (in_array('logo', $colSet, true)) {
                $select[] = 'logo';
            }
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM organizaciones WHERE id = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([self::ORGANIZACION_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                self::$maestraCache = array_merge(self::$maestraCache, $row);
                self::$maestraCache['id'] = self::ORGANIZACION_ID;
                if (empty(self::$maestraCache['nombre'])) {
                    self::$maestraCache['nombre'] = self::ORGANIZACION_NOMBRE;
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('FvdConfig::warmOrganizacionMaestra: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getOrganizacionMaestra(): ?array
    {
        self::warmOrganizacionMaestra();
        return self::$maestraCache;
    }

    public static function getOrganizacionNombre(): string
    {
        $row = self::getOrganizacionMaestra();
        $nombre = trim((string)($row['nombre'] ?? ''));
        return $nombre !== '' ? $nombre : self::ORGANIZACION_NOMBRE;
    }

    /**
     * Etiqueta de ámbito territorial para listados (torneos, organizaciones).
     * 0 y {@see ENTIDAD_AMBITO_NACIONAL_ID} se tratan como alcance nacional.
     */
    public static function etiquetaAmbitoTerritorial(int $entidadId = 0, ?PDO $pdo = null): string
    {
        if ($entidadId <= 0 || $entidadId === self::ENTIDAD_AMBITO_NACIONAL_ID) {
            $nacional = self::lookupEntidadNombre(self::ENTIDAD_AMBITO_NACIONAL_ID, $pdo);

            return $nacional !== '' ? $nacional : 'Nacional';
        }

        $nombre = self::lookupEntidadNombre($entidadId, $pdo);

        return $nombre !== '' ? $nombre : ('Entidad ' . $entidadId);
    }

    /**
     * Resuelve el id territorial efectivo de un torneo (columna entidad o la de su organización).
     *
     * @param array<string, mixed> $torneo
     */
    public static function entidadTerritorioEfectivaTorneo(array $torneo, ?PDO $pdo = null): int
    {
        $ent = (int) ($torneo['entidad'] ?? 0);
        if ($ent > 0) {
            return $ent;
        }

        $orgEnt = (int) ($torneo['organizacion_entidad'] ?? 0);
        if ($orgEnt > 0) {
            return $orgEnt;
        }

        $clubRef = (int) ($torneo['club_responsable'] ?? 0);
        if ($clubRef === self::ORGANIZACION_ID || (int) ($torneo['cod_org'] ?? 0) === self::ORGANIZACION_ID) {
            return self::ENTIDAD_AMBITO_NACIONAL_ID;
        }

        if ($clubRef > 0 && $pdo !== null) {
            try {
                $st = $pdo->prepare('SELECT entidad FROM organizaciones WHERE id = ? LIMIT 1');
                $st->execute([$clubRef]);
                $orgEntDb = (int) ($st->fetchColumn() ?: 0);
                if ($orgEntDb > 0) {
                    return $orgEntDb;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        return 0;
    }

    private static function lookupEntidadNombre(int $entidadId, ?PDO $pdo): string
    {
        static $cache = [];

        if ($entidadId <= 0) {
            return '';
        }
        if (isset($cache[$entidadId])) {
            return $cache[$entidadId];
        }

        if ($pdo === null && class_exists('DB', false)) {
            try {
                $pdo = DB::pdo();
            } catch (Throwable $e) {
                $pdo = null;
            }
        }

        if ($pdo === null) {
            return $cache[$entidadId] = '';
        }

        try {
            $st = $pdo->prepare('SELECT nombre FROM entidad WHERE id = ? LIMIT 1');
            $st->execute([$entidadId]);
            $nombre = trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $nombre = '';
        }

        return $cache[$entidadId] = $nombre;
    }
}
