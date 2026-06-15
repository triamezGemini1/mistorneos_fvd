<?php

declare(strict_types=1);

/**
 * Audita rutas hardcodeadas obsoletas en PHP/JS del proyecto.
 * Uso: php scripts/audit_hardcoded_urls.php
 */

$root = dirname(__DIR__);
require_once $root . '/config/php_polyfills.php';
$patterns = [
    'mistorneos_fvd1' => 'Monorepo obsoleto (standalone usa mistorneos_fvd)',
    '/mistorneos/public' => 'Ruta antigua sin _fvd',
    '/mistorneos_beta/' => 'Ruta beta obsoleta (usar mistorneos_fvd)',
    '/pruebas/public' => 'Ruta de pruebas obsoleta (usar mistorneos_fvd/public)',
    'INTEGRAL_WEB_ROOT=mistorneos_fvd1' => 'Variable de monorepo en .env',
    '/public/public/' => 'Doble segmento public',
];

$scanDirs = ['lib', 'public', 'modules', 'config', 'resources'];
$skipPaths = [
    DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . '_LEGACY_RAW' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'audit_hardcoded_urls.php',
];

$extensions = ['php', 'js', 'html', 'css', 'json', 'md'];

$findings = [];

foreach ($scanDirs as $dir) {
    $abs = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $skip = false;
        foreach ($skipPaths as $sp) {
            if (str_contains($path, $sp)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions, true)) {
            continue;
        }
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        $lines = @file($path);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $num => $line) {
            foreach ($patterns as $needle => $desc) {
                if (stripos($line, $needle) !== false) {
                    $findings[] = [
                        'file' => str_replace('\\', '/', $rel),
                        'line' => $num + 1,
                        'pattern' => $needle,
                        'desc' => $desc,
                        'snippet' => trim($line),
                    ];
                }
            }
        }
    }
}

if ($findings === []) {
    echo "OK: no se encontraron patrones sospechosos en lib/public/modules/config/resources.\n";
    exit(0);
}

echo "Hallazgos (" . count($findings) . "):\n\n";
foreach ($findings as $f) {
    echo $f['file'] . ':' . $f['line'] . ' [' . $f['pattern'] . "]\n";
    echo '  ' . $f['desc'] . "\n";
    echo '  ' . $f['snippet'] . "\n\n";
}

exit(1);
