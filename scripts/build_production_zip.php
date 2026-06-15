<?php
/**
 * Genera ZIP listo para extraer en public_html/mistorneos_fvd/
 * Uso: php scripts/build_production_zip.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_produccion_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$excludeDirNames = [
    '.git',
    '.github',
    'node_modules',
    'tests',
    '.idea',
    '.vscode',
    'dist',
    'coverage',
    'config', // producción conserva su config/ y .env en el servidor
];

$excludeFilePatterns = [
    '/^\.env/', // .env, .env.example, .env.production.example, etc.
    '/^vendor \(2\)\.zip$/i',
    '/^vendor\.zip$/i',
    '/^\.gitkeep$/', // se incluyen solo en carpetas vacías clave — ver abajo
];

$excludePathPatterns = [
    '#(^|/)database/.+\.sql$#i',
    '#(^|/)storage/cache/.+\.json$#i',
    '#(^|/)scripts/test_.+\.php$#i',
    '#(^|/)scripts/build_production_zip\.php$#i',
    '#(^|/)upload/.+#', // contenido subido local; no versionar en zip
    '#(^|/)uploads/.+#',
];

/** Rutas que siempre deben incluirse aunque coincidan con exclusiones */
$forceInclude = [
    'upload/.gitkeep',
    'uploads/.gitkeep',
    'storage/cache/.gitkeep',
    'logs/.gitkeep',
];

function normalizeRel(string $root, string $path): string
{
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    return $rel;
}

function shouldExclude(string $rel, array $excludeDirNames, array $excludeFilePatterns, array $excludePathPatterns, array $forceInclude): bool
{
    if (in_array($rel, $forceInclude, true)) {
        return false;
    }

    $parts = explode('/', $rel);
    foreach ($parts as $part) {
        if (in_array($part, $excludeDirNames, true)) {
            return true;
        }
    }

    $base = basename($rel);
    foreach ($excludeFilePatterns as $pat) {
        if (preg_match($pat, $base) && !in_array($rel, $forceInclude, true)) {
            // .gitkeep manejado por forceInclude en rutas concretas
            if ($base === '.gitkeep') {
                continue;
            }
            return true;
        }
    }

    foreach ($excludePathPatterns as $pat) {
        if (preg_match($pat, $rel)) {
            return false === in_array($rel, $forceInclude, true);
        }
    }

    return false;
}

// CSS Tailwind + vendor locales (requerido en producción; el ZIP no incluye node_modules)
$packageJson = $root . '/package.json';
if (is_file($packageJson)) {
    echo "Compilando assets (npm run build:assets)...\n";
    $npmCmd = 'npm run build:assets 2>&1';
    passthru($npmCmd, $npmCode);
    $precompiled = $root . '/public/assets/css/landing-precompiled.css';
    if ($npmCode !== 0 || !is_file($precompiled)) {
        fwrite(STDERR, "Advertencia: build:assets falló o no generó landing-precompiled.css. Ejecuta: npm ci && npm run build:assets\n");
    }
}

// Composer prod si falta vendor
if (!is_file($root . '/vendor/autoload.php')) {
    echo "Instalando dependencias (composer install --no-dev)...\n";
    $cmd = 'composer install --no-dev --optimize-autoloader --no-interaction 2>&1';
    passthru($cmd, $code);
    if ($code !== 0 || !is_file($root . '/vendor/autoload.php')) {
        fwrite(STDERR, "Error: ejecuta manualmente: composer install --no-dev\n");
        exit(1);
    }
}

if (!is_dir($distDir) && !@mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "No se pudo crear dist/\n");
    exit(1);
}

if (is_file($zipPath)) {
    @unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el ZIP\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added = 0;
$skipped = 0;

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $full = $file->getPathname();
    $rel = normalizeRel($root, $full);

    if ($rel === '' || strpos($rel, 'dist/') === 0) {
        continue;
    }

    if (shouldExclude($rel, $excludeDirNames, $excludeFilePatterns, $excludePathPatterns, $forceInclude)) {
        $skipped++;
        continue;
    }

    if ($file->isDir()) {
        $zip->addEmptyDir(str_replace('\\', '/', $rel));
        continue;
    }

    $zip->addFile($full, str_replace('\\', '/', $rel));
    $added++;
}

// LEEME dentro del zip
$readme = <<<'TXT'
MISTORNEOS FVD — Paquete de producción
=====================================

1. Extraer TODO el contenido en: public_html/mistorneos_fvd/
   (debe quedar: index.php, .htaccess, config/, lib/, public/, vendor/, etc.)

2. Este paquete NO incluye .env ni config/ (se conservan los del servidor).
   Si es instalación nueva, copie .env y config/ desde un respaldo de producción.

3. Permisos 755 en: upload/, uploads/, storage/cache/, logs/

4. En phpMyAdmin ejecutar (si aplica):
   - sql/migrate_inscritos_estatus_pago_fvd.sql
   - sql/migrate_movimiento_torneo_asociacion_id_to_id_club.sql

5. Verificar:
   https://laestaciondeldominohoy.com/mistorneos_fvd/public/verificar_produccion.php
   Luego ELIMINAR verificar_produccion.php

URL app: https://laestaciondeldominohoy.com/mistorneos_fvd/public/
TXT;
$zip->addFromString('LEEME_DESPLIEGUE.txt', $readme);
$added++;

$zip->close();

$sizeMb = round(filesize($zipPath) / 1024 / 1024, 2);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos: {$added} | Omitidos: {$skipped} | Tamaño: {$sizeMb} MB\n";
echo "\nSube y extrae en cPanel → public_html/mistorneos_fvd/\n";

exit(0);
