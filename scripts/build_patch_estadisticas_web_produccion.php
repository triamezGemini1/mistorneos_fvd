<?php

declare(strict_types=1);

/**
 * Parche: acceso visible a Estadísticas web (Umami) + módulo analytics.
 * Extraer en public_html/mistorneos_fvd/ (sin config/ ni .env).
 *
 * Uso: php scripts/build_patch_estadisticas_web_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_estadisticas_web_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'public/includes/layout.php',
    'public/includes/analytics-tracker.php',
    'public/includes/views/dashboard/_fvd_dashboard_header.php',
    'modules/admin_general/views/home.php',
    'modules/admin_general/views/_panel_operativo.php',
    'modules/estadisticas_web.php',
    'modules/analytics_uso.php',
    'public/index.php',
    'lib/UmamiAnalyticsHelper.php',
    'public/landing-spa.php',
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
MISTORNEOS FVD — Parche Estadísticas web (Umami)
================================================

EXTRAER EN: public_html/mistorneos_fvd/

INCLUYE
-------
- Módulo modules/estadisticas_web.php (panel Umami)
- lib/UmamiAnalyticsHelper.php
- Menú lateral + topbar (layout.php)
- Botón "Estadísticas web" en dashboard Admin General
- Panel operativo restaurado en home
- Script analytics-tracker.php (landing + panel)

CONFIGURACIÓN (.env del servidor)
---------------------------------
UMAMI_API_KEY=tu_clave_api_umami
(opcional) UMAMI_SHARE_URL=...
(opcional) UMAMI_DASHBOARD_URL=...

ACCESO
------
index.php?page=estadisticas_web
(page=analytics_uso redirige al mismo módulo)
Solo rol admin_general (cuenta real).

VERIFICAR
---------
1. Dashboard → botón "Estadísticas web" arriba a la derecha
2. Menú lateral → "Estadísticas web"
3. Recursos adicionales → "Estadísticas web (Umami)"
TXT;

$zip->addFromString('LEEME_PARCHE_ESTADISTICAS_WEB.txt', $readme);
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
