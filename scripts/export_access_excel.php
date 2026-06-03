<?php

declare(strict_types=1);

/**
 * Genera Excel para importación en Microsoft Access.
 * Uso: php scripts/export_access_excel.php [--torneo_id=1] [--output=dist]
 */

$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/lib/AccessExportService.php';

$torneoId = 1;
$outputDir = $root . DIRECTORY_SEPARATOR . 'dist';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--torneo_id=')) {
        $torneoId = max(1, (int) substr($arg, 12));
    }
    if (str_starts_with($arg, '--output=')) {
        $outputDir = substr($arg, 9);
        if (!str_starts_with($outputDir, $root) && !preg_match('#^[A-Za-z]:\\\\#', $outputDir)) {
            $outputDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($outputDir, '/\\'));
        }
    }
}

try {
    $pdo = DB::pdo();
    $res = AccessExportService::generarArchivosAccess($pdo, $torneoId, $outputDir);

    echo "Torneo: #{$res['torneo']['id']} — {$res['torneo']['nombre']}\n";
    echo "Inscritos exportados: {$res['n_inscritos']}\n";
    echo "Filas partiresul: {$res['n_partidas']}\n";
    echo "Archivo: {$res['inscritos']}\n";
    echo "Archivo: {$res['partidas']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
