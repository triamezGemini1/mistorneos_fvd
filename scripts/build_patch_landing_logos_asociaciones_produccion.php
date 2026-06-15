<?php

declare(strict_types=1);

/**
 * Parche: logos de asociaciones en landing (URLs, rutas, caché v2).
 * Extraer en public_html/mistorneos_fvd/ (sin config/ ni .env).
 *
 * Uso: php scripts/build_patch_landing_logos_asociaciones_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_landing_logos_asociaciones_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

if (is_file($root . '/package.json')) {
    echo "Compilando assets (npm run build:assets)...\n";
    passthru('npm run build:assets 2>&1', $npmCode);
    if ($npmCode !== 0) {
        fwrite(STDERR, "Advertencia: build:assets falló.\n");
    }
}

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/app_helpers.php',
    'lib/AsociacionesActivasLandingService.php',
    'public/api/landing_data.php',
    'public/view_image.php',
    'public/landing-spa.php',
    'public/assets/landing-spa.js',
    'public/assets/css/landing-precompiled.css',
    'storage/cache/.gitkeep',
];

if (!is_dir($distDir) && !@mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "No se pudo crear dist/\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el ZIP\n");
    exit(1);
}

$added = 0;
$missing = [];

foreach (array_unique($files) as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($full, $rel);
    ++$added;
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche logos asociaciones en landing
=====================================================

EXTRAER EN: public_html/mistorneos_fvd/

INCLUYE
-------
- URLs de logo con baseUrl correcta (view_image.php)
- Normalización de rutas upload/logos/
- Solo muestra logo si el archivo existe en disco
- Caché landing v2 (invalida JSON antiguo)
- Fallback en Vue si la imagen falla al cargar

POST-DESPLIEGUE
---------------
1. Permisos 755 en upload/logos/
2. Borrar storage/cache/landing_data_*.json (opcional; v2 ya renueva)
3. Probar: .../public/api/landing_data.php?nocache=1
4. Ctrl+F5 en landing-spa.php
5. Si falta logo: re-subir desde Asociaciones de la organización
TXT;

$zip->addFromString('LEEME_PARCHE_LANDING_LOGOS_ASOCIACIONES.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Faltan archivos:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";
echo "\n>>> Extraer en: public_html/mistorneos_fvd/ <<<\n";

exit($missing !== [] ? 2 : 0);
