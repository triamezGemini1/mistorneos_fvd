<?php
/**
 * Conexión única y optimizada a la base de datos (PDO; compatible con uso tipo MySQLi vía PDO).
 * Punto central: require_once __DIR__ . '/../config/db_config.php'; luego DB::pdo().
 *
 * Preparada para consultas rápidas sobre tablas extensas (p. ej. 32M registros) sin degradar
 * el tiempo de respuesta: conexión lazy, timeouts 5s, prepared statements, fetch asociativo.
 * Consultas pesadas deben usar LIMIT/índices en la capa de aplicación.
 *
 * Soporta dos conexiones:
 * - Principal (mistorneos): torneos, usuarios, inscripciones, resultados
 * - Secundaria (fvdadmin): datos de apoyo para búsquedas
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

require_once __DIR__ . '/persona_database.php';

class DB {
    private static $pdo = null;
    private static $pdoSecondary = null;

    public static function pdo(): PDO {
        if (self::$pdo === null) {
            self::$pdo = self::createConnection('primary');
            if (class_exists('FvdConfig', false)) {
                FvdConfig::warmOrganizacionMaestra();
            }
        }
        return self::$pdo;
    }

    public static function pdoSecondary(): PDO {
        if (!self::isSecondaryConfigured()) {
            throw new PDOException(
                'Base de datos de personas no configurada. Solo se usa en consultas puntuales (búsqueda por cédula).'
            );
        }
        if (self::$pdoSecondary === null) {
            self::$pdoSecondary = self::createConnection('secondary');
        }

        return self::$pdoSecondary;
    }

    /**
     * Conexión secundaria opcional (null si no hay credenciales o falla).
     */
    public static function tryPdoSecondary(): ?PDO {
        if (!self::isSecondaryConfigured()) {
            return null;
        }
        try {
            return self::pdoSecondary();
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function isSecondaryConfigured(): bool {
        if (class_exists('PersonaDatabase', false)) {
            return PersonaDatabase::isConfigured();
        }
        $cfg = $GLOBALS['APP_CONFIG']['db'] ?? [];
        $persona = $GLOBALS['APP_CONFIG']['persona_db'] ?? [];
        if (trim((string) ($persona['user'] ?? '')) !== '') {
            return true;
        }
        if (trim((string) ($cfg['secondary_user'] ?? '')) !== '') {
            return true;
        }
        if (class_exists('Env', false)) {
            return trim((string) (Env::get('DB_SECONDARY_USERNAME') ?? '')) !== '';
        }

        return false;
    }

    public static function fvdadmin(): PDO {
        return self::pdoSecondary();
    }

    private static function createConnection(string $type = 'primary'): PDO {
        $cfg = $GLOBALS['APP_CONFIG']['db'] ?? [];

        if ($type === 'secondary') {
            $settings = PersonaDatabase::connectionSettings();
            if ($settings === null) {
                throw new PDOException(
                    'Base de datos de personas no configurada. Solo se usa en consultas puntuales (búsqueda por cédula).'
                );
            }
            $host = $settings['host'];
            $port = (string) $settings['port'];
            $name = $settings['dbname'];
            $user = $settings['user'];
            $pass = $settings['pass'];
            $charset = $cfg['secondary_charset'] ?? 'utf8mb4';
            $dbLabel = 'fvdadmin (secundaria)';
        } else {
            $host = Env::getDb('HOST') ?: ($cfg['host'] ?? 'localhost');
            $port = Env::getDb('PORT') ?: ($cfg['port'] ?? '3306');
            $name = Env::getDb('DATABASE') ?: ($cfg['name'] ?? 'mistorneos_fvd');
            $user = Env::getDb('USERNAME') ?: ($cfg['user'] ?? 'root');
            $pass = Env::getDb('PASSWORD') ?: ($cfg['pass'] ?? '');
            $charset = $cfg['charset'] ?? 'utf8mb4';
            $dbLabel = 'mistorneos (principal)';
        }

        if (trim((string) $user) === '') {
            $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            $hint = is_file($envPath)
                ? 'Revise DB_USERNAME y DB_PASSWORD en .env'
                : 'Cree .env en la raíz (copie .env.production.example) con DB_USERNAME y DB_PASSWORD';
            throw new PDOException(
                "Credenciales de base de datos vacías ({$dbLabel}). {$hint}",
                1045
            );
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $opt);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            return $pdo;
        } catch (PDOException $e) {
            self::handleConnectionError($e, $dbLabel);
            throw $e;
        }
    }

    private static function handleConnectionError(PDOException $e, string $dbLabel): void {
        if (strpos($e->getMessage(), '1045') !== false || stripos($e->getMessage(), 'Access denied') !== false) {
            $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            $envHint = is_file($envPath)
                ? 'Usuario o contraseña incorrectos en .env'
                : 'Falta el archivo .env en la raíz del proyecto (use .env.production.example)';
            if (php_sapi_name() !== 'cli') {
                error_log("[DB] {$dbLabel}: {$envHint} — " . $e->getMessage());
            }
        }
        if (strpos($e->getMessage(), '2002') !== false || strpos($e->getMessage(), 'denegó') !== false) {
            $error_msg = "No se puede conectar a MySQL ({$dbLabel}).";
            if (php_sapi_name() !== 'cli') {
                http_response_code(503);
                die("
          <html>
            <head><title>Error de Conexión</title></head>
            <body style='font-family: Arial; padding: 40px; text-align: center;'>
              <h1>⚠️ Error de Conexión a la Base de Datos</h1>
              <p style='font-size: 18px; color: #666;'>Base de datos: <strong>{$dbLabel}</strong></p>
              <p style='color: #999;'>MySQL no está disponible o las credenciales son incorrectas.</p>
            </body>
          </html>
        ");
            }
            throw new PDOException($error_msg, (int) $e->getCode(), $e);
        }
    }

    public static function isConnected(): bool {
        try {
            self::pdo();
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function isSecondaryConnected(): bool {
        if (!self::isSecondaryConfigured()) {
            return false;
        }

        return self::tryPdoSecondary() !== null;
    }

    public static function queryBoth(string $sql, array $params = []): array {
        $results = ['primary' => [], 'secondary' => []];
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
            $results['primary'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['primary_error'] = $e->getMessage();
        }
        if (self::isSecondaryConfigured()) {
            try {
                $pdoSecondary = self::tryPdoSecondary();
                if ($pdoSecondary !== null) {
                    $stmt = $pdoSecondary->prepare($sql);
                    $stmt->execute($params);
                    $results['secondary'] = $stmt->fetchAll();
                } else {
                    $results['secondary_error'] = 'Conexión secundaria no disponible';
                }
            } catch (PDOException $e) {
                $results['secondary_error'] = $e->getMessage();
            }
        } else {
            $results['secondary_skipped'] = true;
        }
        return $results;
    }
}

if (!defined('TABLE_INVITATIONS')) {
    define('TABLE_INVITATIONS', (class_exists('Env') && Env::has('TABLE_INVITATIONS')) ? (string) Env::get('TABLE_INVITATIONS') : 'invitaciones');
}
