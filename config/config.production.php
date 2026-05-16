<?php
/**
 * Configuración de Producción
 * laestaciondeldominohoy.com - public_html/mistorneos_fvd/
 * BD principal: mistorneos_fvd (o laestaci1_mistorneos según cPanel)
 * BD auxiliar: laestaci1_fvdadmin (tabla dbo.persona)
 */

if (!class_exists('Env') && file_exists(__DIR__ . '/../lib/Env.php')) {
    require_once __DIR__ . '/../lib/Env.php';
}

$envValue = function($key, $default = '') {
    if (!is_string($key) || $key === '') {
        return $default;
    }
    return class_exists('Env') ? (Env::get($key) ?? $default) : $default;
};

return [
    'db' => [
        'host' => $envValue('DB_HOST', 'localhost'),
        'port' => $envValue('DB_PORT', '3306'),
        'name' => $envValue('DB_DATABASE', 'mistorneos_fvd'),
        'user' => $envValue('DB_USERNAME', ''),
        'pass' => $envValue('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'secondary_host' => $envValue('DB_SECONDARY_HOST', 'localhost'),
        'secondary_port' => $envValue('DB_SECONDARY_PORT', '3306'),
        'secondary_name' => $envValue('DB_SECONDARY_DATABASE', 'laestaci1_fvdadmin'),
        'secondary_user' => $envValue('DB_SECONDARY_USERNAME', ''),
        'secondary_pass' => $envValue('DB_SECONDARY_PASSWORD', ''),
        'secondary_charset' => 'utf8mb4',
    ],

    'persona_db' => [
        'host' => $envValue('DB_SECONDARY_HOST', 'localhost'),
        'port' => $envValue('DB_SECONDARY_PORT', '3306'),
        'name' => $envValue('DB_SECONDARY_DATABASE', 'laestaci1_fvdadmin'),
        'user' => $envValue('DB_SECONDARY_USERNAME', ''),
        'pass' => $envValue('DB_SECONDARY_PASSWORD', ''),
        'table' => 'dbo.persona',
        'table_dev' => 'dbo.persona',
    ],

    'security' => [
        'session_name' => 'mistorneos_fvd_session_prod',
        'csrf_key' => 'replace_with_random_32_chars_production',
        'password_algo' => PASSWORD_DEFAULT,
    ],

    'app' => [
        'base_url' => $envValue('APP_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/'),
        'debug' => false,
        'environment' => 'production',
    ],

    'whatsapp' => [
        'base_url' => 'https://laestaciondeldominohoy.com/mistorneos_fvd',
    ],
];
