<?php

declare(strict_types=1);

/**
 * Parche: directorio Asociaciones Activas + ficha pública + retorno a landing.
 * Extraer en public_html/mistorneos_fvd/ (sin config/ ni .env).
 *
 * Uso: php scripts/build_patch_asociaciones_activas_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_asociaciones_activas_{$timestamp}.zip";
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
    'lib/AsociacionesActivasLandingService.php',
    'lib/ClubHelper.php',
    'lib/app_helpers.php',
    'public/asociacion_detalle.php',
    'public/api/landing_data.php',
    'public/landing-spa.php',
    'public/assets/landing-spa.js',
    'public/assets/css/landing-precompiled.css',
    'public/assets/css/fvd-landing-shell.css',
    'public/includes/landing_static_shell.php',
    'public/assets/vendor/vue/vue.global.prod.js',
    'storage/cache/.gitkeep',
];

if (is_file($root . '/lib/FvdBranding.php')) {
    $files[] = 'lib/FvdBranding.php';
}

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
MISTORNEOS FVD — Parche Asociaciones Activas
==============================================

EXTRAER EN: public_html/mistorneos_fvd/

NO incluye config/ ni .env (conservar los del servidor).

INCLUYE
-------
- Sección "Asociaciones Activas" en landing-spa.php
- Ficha pública asociacion_detalle.php?id={club_id}
- API landing_data.php (campo asociaciones_activas)
- Enlaces de retorno → landing-spa.php?section=asociaciones-activas#asociaciones-activas
- landing-spa.js con scroll automático al bloque tras cargar Vue

URLS
----
Landing (bloque asociaciones):
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/landing-spa.php#asociaciones-activas

Ficha ejemplo:
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/asociacion_detalle.php?id=1

API:
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/landing_data.php

POST-DESPLIEGUE
---------------
- Borrar storage/cache/landing_data_*.json si no aparecen las asociaciones
- Permisos 755 en storage/cache/
- Verificar: .../public/verificar_despliegue_version.php
TXT;

$zip->addFromString('LEEME_PARCHE_ASOCIACIONES_ACTIVAS.txt', $readme);
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
