<?php

/**
 * Env - Carga y gestión de variables de entorno
 * 
 * Carga variables desde archivo .env y las hace disponibles
 * a través de getenv() y $_ENV
 */
class Env
{
    private static bool $loaded = false;
    private static array $variables = [];

    /**
     * Carga variables de entorno desde archivo .env
     */
    public static function load(string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $path ?? dirname(__DIR__) . '/.env';

        if (!file_exists($path)) {
            self::$loaded = true;
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if (strpos($host, 'laestaciondeldominohoy.com') !== false) {
                error_log('[ENV] .env no encontrado en producción: ' . $path . ' — copie .env.production.example a .env');
            }

            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parsear KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = self::normalizeKey(trim($key));
                if ($key === '') {
                    continue;
                }
                $value = self::parseValue(trim($value));

                // Establecer en entorno
                putenv("$key=$value");
                $_ENV[$key] = $value;
                self::$variables[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    /**
     * Completa variables faltantes (sin sobrescribir .env).
     * Útil para config/env.production.php cuando el valor solo está ahí.
     */
    public static function mergeMissing(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $key = self::normalizeKey($key);
            if ($key === '' || self::hasNonEmpty($key)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || self::isPlaceholderValue($value)) {
                continue;
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
            self::$variables[$key] = $value;
        }
    }

    private static function hasNonEmpty(string $key): bool
    {
        if (isset(self::$variables[$key]) && trim((string) self::$variables[$key]) !== '') {
            return true;
        }
        if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
            return true;
        }
        $fromGetenv = getenv($key);
        if ($fromGetenv !== false && trim((string) $fromGetenv) !== '') {
            return true;
        }

        return false;
    }

    /** Valores de plantilla que no deben usarse como credenciales reales. */
    private static function isPlaceholderValue(string $value): bool
    {
        static $needles = [
            'TU_USUARIO_AQUI',
            'TU_PASSWORD_AQUI',
            'TU_PASSWORD_CORREO',
            'cambiar_esta_clave',
        ];
        foreach ($needles as $needle) {
            if (stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (str_starts_with($key, "\xEF\xBB\xBF")) {
            $key = substr($key, 3);
        }

        return trim($key);
    }

    /**
     * Obtiene una variable de entorno
     */
    public static function get(string $key, $default = null)
    {
        // Primero intentar de las variables cargadas
        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }

        // Luego de $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Finalmente de getenv
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Verifica si una variable existe
     */
    public static function has(string $key): bool
    {
        return isset(self::$variables[$key]) 
            || isset($_ENV[$key]) 
            || getenv($key) !== false;
    }

    /**
     * Obtiene variable como booleano
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Obtiene variable como entero
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== null ? (int) $value : $default;
    }

    /**
     * Parsea el valor eliminando comillas y procesando caracteres especiales
     * Solo expande ${VAR}; el $ suelto no se interpreta (evita que contraseñas con $ fallen)
     */
    private static function parseValue(string $value): string
    {
        // Eliminar comillas al inicio y final
        if ((strpos($value, '"') === 0 && substr($value, -1) === '"') ||
            (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }

        // Escapar \$ a $ literal
        $value = str_replace('\\$', '$', $value);

        // Solo expandir ${VAR}, no $VAR suelto (para que contraseñas como npi$Ya2026 funcionen)
        $value = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
            return self::get($matches[1], '');
        }, $value);

        return trim($value, " \t\n\r\0\x0B,;");
    }

    /**
     * Obtiene todas las variables cargadas (para debug, no usar en producción)
     */
    public static function all(): array
    {
        return self::$variables;
    }

    /**
     * Verifica si estamos en producción
     */
    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /**
     * Verifica si estamos en desarrollo
     */
    public static function isDevelopment(): bool
    {
        return in_array(self::get('APP_ENV'), ['development', 'local', 'dev'], true);
    }

    /**
     * Obtiene el ámbito actual para variables con prefijo (development | production).
     */
    public static function scope(): string
    {
        $env = self::get('APP_ENV', 'development');
        return (strtolower($env) === 'production') ? 'production' : 'development';
    }

    /**
     * Obtiene una variable de BD según el ámbito (APP_ENV).
     * Permite tener en .env: DB_DEV_HOST, DB_PROD_HOST y seleccionar con APP_ENV.
     * Si no existe la variable con prefijo, usa la genérica (ej. DB_HOST) para compatibilidad.
     *
     * @param string $key Sin prefijo DB_: HOST, PORT, DATABASE, USERNAME, PASSWORD
     * @param mixed $default
     * @return mixed
     */
    public static function getDb(string $key, $default = null)
    {
        $scope = self::scope();
        $prefix = ($scope === 'production') ? 'DB_PROD_' : 'DB_DEV_';
        $scopedKey = $prefix . $key;
        $legacyKey = 'DB_' . $key;
        return self::get($scopedKey) ?? self::get($legacyKey) ?? $default;
    }

    /**
     * Igual que getDb pero para la conexión secundaria (fvdadmin).
     * Claves: SECONDARY_HOST, SECONDARY_PORT, SECONDARY_DATABASE, SECONDARY_USERNAME, SECONDARY_PASSWORD
     */
    public static function getDbSecondary(string $key, $default = null)
    {
        $scope = self::scope();
        $prefix = ($scope === 'production') ? 'DB_PROD_SECONDARY_' : 'DB_DEV_SECONDARY_';
        $scopedKey = $prefix . $key;
        $legacyKey = 'DB_SECONDARY_' . $key;
        return self::get($scopedKey) ?? self::get($legacyKey) ?? $default;
    }

    /**
     * Obtiene URL base de la aplicación
     */
    public static function appUrl(): string
    {
        return rtrim(self::get('APP_URL', 'http://localhost'), '/');
    }
}

