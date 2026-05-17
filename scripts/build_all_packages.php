<?php

declare(strict_types=1);

/**
 * Genera parche + ZIP de producción en dist/
 * Uso: php scripts/build_all_packages.php
 */

$root = dirname(__DIR__);
$scripts = [
    'build_patch_zip.php',
    'build_production_zip.php',
];

foreach ($scripts as $script) {
    $path = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) {
        fwrite(STDERR, "No existe: {$script}\n");
        exit(1);
    }
    echo "\n========== {$script} ==========\n";
    passthru('php ' . escapeshellarg($path), $code);
    if ($code !== 0 && $code !== 2) {
        fwrite(STDERR, "Falló: {$script} (código {$code})\n");
        exit($code);
    }
}

echo "\nListo. Revisa la carpeta dist/\n";
exit(0);
