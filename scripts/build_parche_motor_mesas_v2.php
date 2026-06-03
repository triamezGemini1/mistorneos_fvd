<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$zipPath = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'parche_motor_mesas_v2.zip';

$files = [
    'lib/Core/MesaAsignacion/MesaAsignacionQueueTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLimiteClubMesaTrait.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
];

if (!is_dir(dirname($zipPath)) && !@mkdir(dirname($zipPath), 0755, true)) {
    fwrite(STDERR, "No se pudo crear dist/\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el ZIP\n");
    exit(1);
}

foreach ($files as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        fwrite(STDERR, "Falta: {$rel}\n");
        exit(1);
    }
    $zip->addFile($full, $rel);
}

$readme = <<<'TXT'
PARCHE MOTOR MESAS v2
=====================

Extraer en la raiz del proyecto mistorneos_fvd en produccion.

Archivos:
- lib/Core/MesaAsignacion/MesaAsignacionQueueTrait.php (Paso 1: hash + geometria)
- lib/Core/MesaAsignacion/MesaAsignacionLimiteClubMesaTrait.php (Paso 2: hill-climbing clubes)
- lib/Core/MesaRepositoryPersistTrait.php (Paso 3: batch insert)

No requiere migraciones SQL.
Probar generando una ronda intermedia en un torneo de prueba.
TXT;
$zip->addFromString('LEEME_PARCHE_MOTOR_MESAS_v2.txt', $readme);
$zip->close();

echo $zipPath . PHP_EOL;
echo round(filesize($zipPath) / 1024, 1) . ' KB' . PHP_EOL;
