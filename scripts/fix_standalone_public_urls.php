<?php

declare(strict_types=1);

/**
 * Reemplaza enlaces app_base_url()/getBaseUrl() + '/public/' por AppHelpers::url().
 * App standalone mistorneos_fvd — sin monorepo.
 *
 * Uso: php scripts/fix_standalone_public_urls.php [--dry-run]
 */

require_once dirname(__DIR__) . '/config/php_polyfills.php';

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);

$scanDirs = ['config', 'lib', 'modules', 'public', 'resources'];
$skip = ['_LEGACY_RAW', 'vendor', 'deploy', 'scripts/fix_standalone_public_urls.php'];

$patterns = [
    // app_base_url() . '/public/foo.php' (+ optional concat)
    [
        'regex' => '/app_base_url\(\)\s*\.\s*[\'"](\/public\/([^\'"?]+))[\'"]/',
        'replace' => static function (array $m): string {
            return "AppHelpers::url('" . $m[2] . "')";
        },
    ],
    [
        'regex' => '/app_base_url\(\)\s*\.\s*"\/public\/([^"]+)"/',
        'replace' => static function (array $m): string {
            return 'AppHelpers::url("' . $m[1] . '")';
        },
    ],
    [
        'regex' => '/rtrim\s*\(\s*AppHelpers::getBaseUrl\(\)\s*,\s*[\'"]\/[\'"]\s*\)\s*\.\s*[\'"]\/public\/([^\'"?]+)[\'"]/',
        'replace' => static function (array $m): string {
            return "AppHelpers::url('" . $m[1] . "')";
        },
    ],
    [
        'regex' => '/rtrim\s*\(\s*\$app_url\s*,\s*[\'"]\/[\'"]\s*\)\s*\.\s*"\/public\/([^"]+)"/',
        'replace' => static function (array $m): string {
            return 'AppHelpers::url("' . $m[1] . '")';
        },
    ],
    [
        'regex' => '/\$app_url\s*\.\s*"\/public\/([^"]+)"/',
        'replace' => static function (array $m): string {
            return 'AppHelpers::url("' . $m[1] . '")';
        },
    ],
    [
        'regex' => '/\$app_url\s*\.\s*\'\/public\/([^\']+)\'/',
        'replace' => static function (array $m): string {
            return "AppHelpers::url('" . $m[1] . "')";
        },
    ],
    [
        'regex' => '/rtrim\s*\(\s*\$base(?:_preview)?\s*,\s*[\'"]\/[\'"]\s*\)\s*\.\s*[\'"]\/public\/([^\'"?]+)[\'"]/',
        'replace' => static function (array $m): string {
            return "AppHelpers::url('" . $m[1] . "')";
        },
    ],
];

$changed = 0;
$filesTouched = 0;

foreach ($scanDirs as $dir) {
    $abs = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        foreach ($skip as $s) {
            if (str_contains($path, $s)) {
                continue 2;
            }
        }
        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }
        $original = $content;
        foreach ($patterns as $p) {
            $content = preg_replace_callback($p['regex'], $p['replace'], $content) ?? $content;
        }
        if ($content !== $original) {
            ++$filesTouched;
            if (!$dryRun) {
                file_put_contents($path, $content);
            }
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
            echo ($dryRun ? '[dry-run] ' : '') . "updated: {$rel}\n";
            ++$changed;
        }
    }
}

echo "\nArchivos modificados: {$filesTouched}\n";
exit(0);
