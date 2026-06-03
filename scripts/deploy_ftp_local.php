<?php
/**
 * Despliegue FTP desde tu PC (sin GitHub Actions).
 *
 * 1. Copia .env.ftp.local.example → .env.ftp.local y rellena credenciales.
 * 2. Prueba login: php scripts/diagnose_ftp.php
 * 3. Simulación:  php scripts/deploy_ftp_local.php --dry-run
 * 4. Subida real: php scripts/deploy_ftp_local.php
 *
 * Opciones:
 *   --dry-run       Solo lista archivos (no sube)
 *   --build         Ejecuta npm run build:assets antes
 *   --yes           No pide confirmación
 *   --only=ruta     Solo archivos bajo esa ruta (ej. public/)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/config/php_polyfills.php';
require_once __DIR__ . '/deploy_ftp_lib.php';

$opts = parseDeployCliOptions($argv ?? []);
$envFile = $root . DIRECTORY_SEPARATOR . '.env.ftp.local';
$config = loadFtpEnvFile($envFile);

if ($config === []) {
    fwrite(STDERR, "Falta {$envFile}\n");
    fwrite(STDERR, "Copia .env.ftp.local.example → .env.ftp.local y configura FTP_*.\n");
    exit(1);
}

$host = $config['FTP_HOST'] ?? '';
$user = $config['FTP_USER'] ?? $config['FTP_USERNAME'] ?? '';
$pass = $config['FTP_PASS'] ?? $config['FTP_PASSWORD'] ?? '';
$port = (int) ($config['FTP_PORT'] ?? 21);
$remoteDir = normalizeFtpRemoteDir($config['FTP_REMOTE_DIR'] ?? './');
$passive = !in_array(strtolower((string) ($config['FTP_PASSIVE'] ?? '1')), ['0', 'false', 'no'], true);

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Completa FTP_HOST, FTP_USER y FTP_PASS en .env.ftp.local\n");
    exit(1);
}

if ($opts['build']) {
    echo "Compilando assets (npm run build:assets)...\n";
    passthru('npm run build:assets 2>&1', $code);
    if ($code !== 0) {
        fwrite(STDERR, "Advertencia: build:assets falló. Continúa bajo tu responsabilidad.\n");
    }
}

if (!is_file($root . '/vendor/autoload.php')) {
    echo "Instalando dependencias PHP (composer install --no-dev)...\n";
    passthru('composer install --no-dev --optimize-autoloader --no-interaction 2>&1', $code);
    if ($code !== 0 || !is_file($root . '/vendor/autoload.php')) {
        fwrite(STDERR, "Error: falta vendor/. Ejecuta: composer install --no-dev\n");
        exit(1);
    }
}

$files = collectDeployFiles($root, $opts['only']);
if ($files === []) {
    fwrite(STDERR, "No hay archivos para subir.\n");
    exit(1);
}

$totalBytes = 0;
foreach ($files as $f) {
    $totalBytes += (int) $f['size'];
}
echo "\n=== Despliegue FTP local ===\n";
echo "Host: {$host}:{$port}\n";
echo "Usuario: {$user}\n";
echo "Remoto: {$remoteDir}\n";
echo "Archivos: " . count($files) . " (" . formatDeployBytes($totalBytes) . ")\n";

if ($opts['dry_run']) {
    echo "\n--dry-run: no se subió nada.\n";
    foreach (array_slice($files, 0, 40) as $f) {
        echo "  " . $f['rel'] . "\n";
    }
    if (count($files) > 40) {
        echo "  ... y " . (count($files) - 40) . " más\n";
    }
    exit(0);
}

if (!$opts['yes']) {
    echo "\n¿Subir a producción? [s/N]: ";
    $answer = strtolower(trim((string) fgets(STDIN)));
    if (!in_array($answer, ['s', 'si', 'sí', 'y', 'yes'], true)) {
        echo "Cancelado.\n";
        exit(0);
    }
}

if (!function_exists('ftp_connect')) {
    fwrite(STDERR, "Habilita extension=ftp en php.ini (WAMP → PHP → php.ini).\n");
    exit(2);
}

echo "\nConectando...\n";
$conn = @ftp_connect($host, $port, 30);
if ($conn === false) {
    fwrite(STDERR, "No se pudo conectar a {$host}:{$port}\n");
    exit(1);
}

if (!@ftp_login($conn, $user, $pass)) {
    @ftp_close($conn);
    fwrite(STDERR, "Login falló (530). Prueba: php scripts/diagnose_ftp.php\n");
    exit(3);
}

@ftp_pasv($conn, $passive);

$deployRoot = ftpResolveDeployRoot($conn, $remoteDir);
if ($deployRoot === null) {
    @ftp_close($conn);
    fwrite(STDERR, "No se pudo acceder o crear la ruta remota: {$remoteDir}\n");
    exit(4);
}
echo "Carpeta remota (PWD): {$deployRoot}\n\n";

$uploaded = 0;
$failed = 0;
$start = microtime(true);

foreach ($files as $i => $file) {
    if (!ftpUploadFile($conn, $deployRoot, $file['rel'], $file['abs'])) {
        fwrite(STDERR, "FALLO: {$file['rel']}\n");
        $failed++;
        continue;
    }
    $uploaded++;
    if (($i + 1) % 50 === 0 || $i === count($files) - 1) {
        $pct = (int) round((($i + 1) / count($files)) * 100);
        echo "  [{$pct}%] " . ($i + 1) . '/' . count($files) . " — último: {$file['rel']}\n";
    }
}

@ftp_close($conn);

$elapsed = round(microtime(true) - $start, 1);
echo "\nListo en {$elapsed}s — subidos: {$uploaded}, fallos: {$failed}\n";
echo "Verifica: https://laestaciondeldominohoy.com/mistorneos_fvd/public/verificar_produccion.php\n";
exit($failed > 0 ? 5 : 0);
