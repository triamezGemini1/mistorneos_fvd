<?php

declare(strict_types=1);

/**
 * Restaura el operador PHP ?? dañado por reemplazos erróneos ( ?? → ?? ).
 * Uso: php scripts/fix_null_coalescing_bullet.php [--dry-run]
 */

$root = dirname(__DIR__);
require_once $root . '/config/php_polyfills.php';
$dryRun = in_array('--dry-run', $argv, true);
$skipDirs = ['vendor', 'node_modules', 'dist', '.git', 'upload', 'uploads', 'storage', 'tests', '_LEGACY_RAW'];
$skipFiles = [
    'lib/FvdUtf8.php',
];

$dirs = ['modules', 'public', 'lib', 'resources', 'includes', 'config', 'desktop', 'scripts', 'actions'];
$changed = 0;
$scanned = 0;

foreach ($dirs as $dir) {
    $base = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }
        $rel = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
        if (in_array($rel, $skipFiles, true)) {
            continue;
        }
        foreach ($skipDirs as $skip) {
            if (str_contains($rel, $skip . '/')) {
                continue 2;
            }
        }

        ++$scanned;
        $original = file_get_contents($fileInfo->getPathname());
        if ($original === false || strpos($original, ' ?? ') === false) {
            continue;
        }

        $repaired = str_replace(' ?? ', ' ?? ', $original);
        if ($repaired === $original) {
            continue;
        }

        echo ($dryRun ? '[dry-run] ' : '') . "FIX ?? {$rel}\n";
        ++$changed;

        if (!$dryRun) {
            file_put_contents($fileInfo->getPathname(), $repaired);
        }
    }
}

echo "\nEscaneados: {$scanned} | Corregidos: {$changed}" . ($dryRun ? ' (simulación)' : '') . "\n";
