<?php

declare(strict_types=1);

/**
 * Corrige • dentro de palabras (daño de script anterior: ?? → • en medio de verbos).
 * Uso: php scripts/fix_bullet_in_words.php [--dry-run]
 */

$root = dirname(__DIR__);
require_once $root . '/config/php_polyfills.php';

$dryRun = in_array('--dry-run', $argv, true);
$dirs = ['modules', 'public', 'lib', 'resources', 'config', 'desktop'];
$skip = ['vendor', 'node_modules', 'dist', '.git', 'upload', 'lib/FvdUtf8.php'];
$changed = 0;

$patterns = [
    '/descontar•/u' => 'descontará',
    '/pag• /u' => 'pagó ',
    '/pag• en/u' => 'pagó en',
    '/abri• /u' => 'abrió ',
    '/abri•ndose/u' => 'abriéndose',
    '/finaliz•/u' => 'finalizó',
    '/autom•tica/u' => 'automática',
    '/autom•tico/u' => 'automático',
    '/problem•ticos/u' => 'problemáticos',
    '/DESPU•S/u' => 'DESPUÉS',
    '/CR•TICO/u' => 'CRÍTICO',
    '/cr•tico/u' => 'crítico',
    '/Bot•n/u' => 'Botón',
    '/bot•n/u' => 'botón',
    '/v•lido/u' => 'válido',
    '/v•lida/u' => 'válida',
    '/pesta•a/u' => 'pestaña',
    '/P•gina/u' => 'Página',
    '/p•gina/u' => 'página',
    '/M•vil/u' => 'Móvil',
    '/m•vil/u' => 'móvil',
    '/D•lares/u' => 'Dólares',
    '/d•lares/u' => 'dólares',
    '/Bol•vares/u' => 'Bolívares',
    '/bol•vares/u' => 'bolívares',
    '/�rea/u' => 'Área',
    '/SECCI•N/u' => 'SECCIÓN',
    '/Versi•n/u' => 'Versión',
    '/versi•n/u' => 'versión',
    '/Aseg•rese/u' => 'Asegúrese',
    '/F•cil/u' => 'Fácil',
    '/f•cil/u' => 'fácil',
    '/�bralo/u' => 'ábralo',
    '/�Copiado/u' => '¡Copiado',
    '/�Enlace/u' => '¡Enlace',
    '/�No/u' => '¿No',
    '/r•pido/u' => 'rápido',
    '/m•s /u' => 'más ',
    '/M•s /u' => 'Más ',
    '/raz•n/u' => 'razón',
    '/abrir• directamente/u' => 'abrirá directamente',
    '/abrir• /u' => 'abrió ',
];

foreach ($dirs as $dir) {
    $base = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        foreach ($skip as $s) {
            if (strpos($rel, $s) !== false) {
                continue 2;
            }
        }
        $original = file_get_contents($file->getPathname());
        if ($original === false || (strpos($original, '•') === false && strpos($original, '�') === false)) {
            continue;
        }
        $repaired = $original;
        foreach ($patterns as $p => $r) {
            $repaired = preg_replace($p, $r, $repaired) ?? $repaired;
        }
        if ($repaired !== $original) {
            echo ($dryRun ? '[dry-run] ' : '') . "FIX: {$rel}\n";
            ++$changed;
            if (!$dryRun) {
                file_put_contents($file->getPathname(), $repaired);
            }
        }
    }
}

echo "\nCorregidos: {$changed}\n";
