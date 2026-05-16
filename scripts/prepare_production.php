<?php
/**
 * Pre-flight antes de subir a producción (public_html/mistorneos_fvd).
 * Uso local: php scripts/prepare_production.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$warnings = [];

$requiredDirs = [
    'upload',
    'uploads',
    'storage/cache',
    'logs',
    'public',
    'config',
    'lib',
    'vendor',
];

foreach ($requiredDirs as $rel) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_dir($path) && $rel !== 'vendor') {
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            $errors[] = "No se pudo crear carpeta: {$rel}";
        }
    }
    if ($rel === 'vendor' && !is_file($path . '/autoload.php')) {
        $warnings[] = 'Falta vendor/ — ejecuta: composer install --no-dev --optimize-autoloader';
    }
}

$envExample = $root . '/.env.production.example';
if (!is_file($envExample)) {
    $warnings[] = 'Falta .env.production.example';
}

$htaccessRoot = $root . '/.htaccess';
if (is_file($htaccessRoot) && strpos((string) file_get_contents($htaccessRoot), '/mistorneos_fvd/') === false) {
    $errors[] = '.htaccess raíz no apunta a /mistorneos_fvd/';
}

$htaccessPublic = $root . '/public/.htaccess';
if (is_file($htaccessPublic) && strpos((string) file_get_contents($htaccessPublic), '/mistorneos_fvd/public/') === false) {
    $errors[] = 'public/.htaccess no apunta a /mistorneos_fvd/public/';
}

$migrations = [
    'sql/migrate_inscritos_estatus_pago_fvd.sql',
    'sql/migrate_movimiento_torneo_asociacion_id_to_id_club.sql',
];
foreach ($migrations as $sql) {
    if (!is_file($root . '/' . $sql)) {
        $warnings[] = "Migración no encontrada (ejecutar en BD si aplica): {$sql}";
    }
}

$excludeFromFtp = [
    '.git',
    '.github',
    '.env',
    '.env.local',
    'database/*.sql',
    'tests',
    'node_modules',
    '.idea',
    '.vscode',
    'scripts/test_panel_load.php',
    'storage/cache/*.json',
];

echo "=== Preparación producción — mistorneos_fvd ===\n\n";
echo "Destino: https://laestaciondeldominohoy.com/mistorneos_fvd/\n";
echo "Entrada app: .../mistorneos_fvd/public/\n\n";

if ($errors !== []) {
    echo "ERRORES:\n";
    foreach ($errors as $e) {
        echo "  [X] {$e}\n";
    }
}
if ($warnings !== []) {
    echo "AVISOS:\n";
    foreach ($warnings as $w) {
        echo "  [!] {$w}\n";
    }
}
if ($errors === []) {
    echo "Estructura local: OK\n\n";
}

echo "En el servidor (cPanel), crear manualmente:\n";
echo "  1. Copiar .env.production.example → .env y completar credenciales\n";
echo "  2. Permisos 755 en upload/, uploads/, storage/cache/, logs/\n";
echo "  3. Ejecutar migraciones SQL en phpMyAdmin\n";
echo "  4. Verificar: .../public/verificar_produccion.php (luego eliminar)\n\n";

echo "No subir por FTP/Git:\n";
foreach ($excludeFromFtp as $x) {
    echo "  - {$x}\n";
}

exit($errors === [] ? 0 : 1);
