<?php

declare(strict_types=1);

/**
 * ZIP parche: dashboard home — logo, colores corporativos, sin nombre FVD en torneos.
 * Uso: php scripts/build_patch_dashboard_home_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_dashboard_home_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/FvdBranding.php',
    'lib/app_helpers.php',
    'lib/DashboardData.php',
    'public/includes/layout.php',
    'public/assets/css/fvd-tokens.css',
    'public/assets/css/fvd-dashboard-home-page.css',
    'public/assets/vendor/img/logofvd.png',
    'public/assets/img/logo-fvd.png',
    'public/includes/views/dashboard/home.php',
    'public/includes/views/dashboard/_fvd_dashboard_header.php',
    'public/includes/views/dashboard/_fvd_kpi_compact.php',
    'public/includes/views/dashboard/_fvd_torneos_home_section.php',
    'public/includes/views/dashboard/_fvd_torneos_table.php',
    'public/includes/views/dashboard/_fvd_torneos_org_helper.php',
    'modules/admin_general/views/home.php',
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

foreach ($files as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($full, $rel);
    ++$added;
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche dashboard home (branding)
================================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

CAMBIOS
-------
1) Logo institucional visible en cabecera del dashboard y barra superior.
2) Colores corporativos (--fvd-primary, acento ámbar) en estadísticas y torneos.
3) Tabla de torneos: no muestra el nombre de la organización FVD bajo cada torneo.
4) Título "Estadísticas" sin sufijo FVD.

LOGOS (incluidos en el ZIP)
- public/assets/vendor/img/logofvd.png
- public/assets/img/logo-fvd.png

VERIFICAR
---------
Build: 2026-06-02-dashboard-home-branding
URL: public/verificar_despliegue_version.php
TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Advertencia — no encontrados:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";

exit($missing !== [] ? 2 : 0);
