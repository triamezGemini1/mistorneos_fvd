<?php

declare(strict_types=1);

/**
 * Genera en dist/ el parche acumulado y el ZIP de producción completo.
 * Uso: php scripts/build_deploy_packages.php
 */

$root = dirname(__DIR__);
$php = PHP_BINARY ?: 'php';

echo "=== MISTORNEOS FVD — Paquetes de despliegue ===\n\n";

$patchScript = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build_patch_desde_swap_zip.php';
$prodScript = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build_production_zip.php';

foreach ([$patchScript, $prodScript] as $script) {
    if (!is_file($script)) {
        fwrite(STDERR, "No existe: {$script}\n");
        exit(1);
    }
}

echo "[1/2] Parche acumulado (desde swap)...\n";
passthru('"' . $php . '" "' . $patchScript . '"', $codePatch);
echo "\n";

echo "[2/2] Producción completa...\n";
passthru('"' . $php . '" "' . $prodScript . '"', $codeProd);
echo "\n";

$dist = $root . DIRECTORY_SEPARATOR . 'dist';
$patches = glob($dist . DIRECTORY_SEPARATOR . 'mistorneos_fvd_patch_desde_swap_*.zip') ?: [];
$prods = glob($dist . DIRECTORY_SEPARATOR . 'mistorneos_fvd_produccion_*.zip') ?: [];

usort($patches, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
usort($prods, static fn ($a, $b) => filemtime($b) <=> filemtime($a));

$latestPatch = $patches[0] ?? null;
$latestProd = $prods[0] ?? null;

echo "=== Resumen ===\n";
if ($latestPatch && is_file($latestPatch)) {
    echo 'Parche:      ' . $latestPatch . ' (' . round(filesize($latestPatch) / 1024, 1) . " KB)\n";
} else {
    echo "Parche:      (no generado)\n";
}
if ($latestProd && is_file($latestProd)) {
    echo 'Producción:  ' . $latestProd . ' (' . round(filesize($latestProd) / 1024 / 1024, 2) . " MB)\n";
} else {
    echo "Producción:  (no generado)\n";
}

$build = 'desconocido';
$deployFile = $root . '/config/deploy_build.php';
if (is_file($deployFile) && preg_match("/FVD_DEPLOY_BUILD',\s*'([^']+)'/", (string) file_get_contents($deployFile), $m)) {
    $build = $m[1];
}
echo "Build:       {$build}\n";
echo "\nSubir parche en cPanel (rápido) o producción completa (reemplazo total).\n";
echo "Verificar: public/verificar_despliegue_version.php\n";

exit(($codePatch !== 0 || $codeProd !== 0) ? 1 : 0);
