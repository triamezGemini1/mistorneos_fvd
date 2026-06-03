<?php

declare(strict_types=1);

/**
 * Genera TODOS los paquetes de despliegue del 02-Jun-2026 en dist/
 * Uso: php scripts/build_deploy_jun02_2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';

if (!is_dir($distDir) && !@mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "No se pudo crear dist/\n");
    exit(1);
}

$scripts = [
    'Parche BD personas lazy' => 'build_patch_lazy_persona_db_jun2026.php',
    'Parche hotfix Numfvd' => 'build_patch_hotfix_numfvd_jun2026.php',
    'Parche combinado Numfvd+lazy' => 'build_patch_combined_numfvd_lazy_persona_jun2026.php',
    'Parche inscripción sitio' => 'build_patch_inscribir_sitio_jun2026.php',
    'Parche dashboard home' => 'build_patch_dashboard_home_jun2026.php',
    'Parche PRODUCCIÓN COMPLETA (recomendado)' => 'build_patch_produccion_completo_jun02_2026.php',
    'ZIP producción COMPLETO (app entera)' => 'build_production_zip.php',
];

$results = [];
$errors = 0;

echo "=== DESPLIEGUE 02-Jun-2026 — generación de paquetes ===\n\n";

foreach ($scripts as $label => $script) {
    $path = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script;
    echo "--- {$label} ({$script}) ---\n";
    if (!is_file($path)) {
        echo "  OMITIDO: no existe {$script}\n\n";
        ++$errors;
        continue;
    }
    passthru('php ' . escapeshellarg($path), $code);
    if ($code !== 0 && $code !== 2) {
        echo "  ERROR código {$code}\n";
        ++$errors;
    }
    echo "\n";
}

// Manifest
$zips = glob($distDir . DIRECTORY_SEPARATOR . '*.zip') ?: [];
usort($zips, static fn ($a, $b) => filemtime($b) <=> filemtime($a));

$manifest = "MANIFEST DESPLIEGUE 02-Jun-2026\n";
$manifest .= 'Generado: ' . date('Y-m-d H:i:s') . "\n";
$manifest .= 'Build: ' . (defined('FVD_DEPLOY_BUILD') ? FVD_DEPLOY_BUILD : '(ver config/deploy_build.php)') . "\n\n";

require_once $root . '/config/deploy_build.php';
$manifest = "MANIFEST DESPLIEGUE 02-Jun-2026\n";
$manifest .= 'Generado: ' . date('Y-m-d H:i:s') . "\n";
$manifest .= 'Build activo: ' . FVD_DEPLOY_BUILD . "\n\n";

$manifest .= "RECOMENDACIÓN PRODUCCIÓN\n";
$manifest .= "------------------------\n";
$manifest .= "Opción A (rápida): parche mistorneos_fvd_patch_produccion_completo_*.zip\n";
$manifest .= "Opción B (instalación limpia): mistorneos_fvd_produccion_*.zip\n\n";

$manifest .= "ARCHIVOS EN dist/\n";
$manifest .= "-----------------\n";
foreach ($zips as $zip) {
    $name = basename($zip);
    $kb = round(filesize($zip) / 1024, 1);
    $when = date('Y-m-d H:i:s', filemtime($zip));
    $manifest .= sprintf("%-55s %8s KB  %s\n", $name, (string) $kb, $when);
}

file_put_contents($distDir . DIRECTORY_SEPARATOR . 'MANIFEST_DESPLIEGUE_2026-06-02.txt', $manifest);

echo "=== MANIFEST ===\n";
echo $manifest;

if ($errors > 0) {
    fwrite(STDERR, "Completado con {$errors} error(es).\n");
    exit(1);
}

echo "Listo. Carpeta: {$distDir}\n";
exit(0);
