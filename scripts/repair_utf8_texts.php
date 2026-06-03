<?php

declare(strict_types=1);

/**
 * Repara mojibake UTF-8 en archivos de la aplicación.
 * Uso: php scripts/repair_utf8_texts.php [--dry-run] [--path=modules/foo.php]
 */

$root = dirname(__DIR__);
require_once $root . '/config/php_polyfills.php';
require_once $root . '/lib/FvdUtf8.php';

$dryRun = in_array('--dry-run', $argv, true);
$singlePath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $singlePath = substr($arg, 7);
    }
}

$skipDirs = ['vendor', 'node_modules', 'dist', '.git', 'upload', 'uploads', 'storage', 'tests', '_LEGACY_RAW'];
$skipFiles = [
    'lib/FvdUtf8.php',
    'scripts/repair_utf8_texts.php',
    'scripts/fix_null_coalescing_bullet.php',
];
$extensions = FvdUtf8::defaultScanExtensions();

$files = [];
if ($singlePath !== null) {
    $full = str_starts_with($singlePath, $root) ? $singlePath : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($singlePath, '/'));
    if (is_file($full)) {
        $files[] = $full;
    }
} else {
    foreach (FvdUtf8::defaultScanDirs($root) as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
            foreach ($skipDirs as $skip) {
                if (str_contains($rel, $skip . '/')) {
                    continue 2;
                }
            }
            $ext = strtolower($fileInfo->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }
    }
}

$changed = 0;
$scanned = 0;

foreach ($files as $path) {
    ++$scanned;
    $relCheck = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (in_array($relCheck, $skipFiles, true)) {
        continue;
    }
    $original = file_get_contents($path);
    if ($original === false || $original === '') {
        continue;
    }

    // Normalizar saltos de línea; conservar BOM si existía
    $hadBom = str_starts_with($original, "\xEF\xBB\xBF");
    if ($hadBom) {
        $original = substr($original, 3);
    }

    $repaired = FvdUtf8::repair($original);
    if ($repaired === $original) {
        continue;
    }

    // No aplicar si rompe el operador null coalescing de PHP (?? → •)
    if (preg_match('/\?\?/', $original) === 0 && preg_match('/ \xE2\x80\xA2 /', $repaired)) {
        continue;
    }
    if (preg_match('/\$[a-zA-Z_][\w\[\]\'"]* \xE2\x80\xA2 /', $repaired)) {
        continue;
    }

    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    echo ($dryRun ? '[dry-run] ' : '') . "FIX: {$rel}\n";
    ++$changed;

    if (!$dryRun) {
        $out = ($hadBom ? "\xEF\xBB\xBF" : '') . $repaired;
        file_put_contents($path, $out);
    }
}

echo "\nEscaneados: {$scanned} | Corregidos: {$changed}" . ($dryRun ? ' (simulación)' : '') . "\n";
