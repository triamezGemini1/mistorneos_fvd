<?php

declare(strict_types=1);

/**
 * Parche producción: asignación lineal FVD (R1 posi_rnk, R2+ columnas G/E/P, última 1+3 vs 2+4).
 * Uso: php scripts/build_patch_lineal_clasificacion_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_lineal_clasificacion_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/InscritosHelper.php',
    'desktop/core/InscritosHelper.php',
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaAsignacion/MesaAsignacionAlgorithm.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLinealClasificacionTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionConflictos2Trait.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'lib/PartiresulEstatusSql.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'docs/PROCEDIMIENTO_ASIGNACION_RONDAS_Y_EVALUACION.md',
];

if (!is_dir($distDir) && !@mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "No se pudo crear dist/\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el ZIP\n");
    exit(1);
}

$added = 0;
$missing = [];
foreach ($files as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($full, $rel);
    ++$added;
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche asignación lineal (mayo 2026)
=====================================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

BUILD: 2026-05-21-lineal-clasificacion-columnas

Verificar: public/verificar_despliegue_version.php
Deben salir OK:
- Motor: R2+ esquema columnas G/E/P
- Rondas 2..N-1: bloques lineales + reparación
- Guardar ronda SIN abort por pareja repetida

PROCEDIMIENTO INDIVIDUAL
------------------------
R1: posi_rnk lineal → bloques consecutivos de 4 por mesa [A,C,B,D].
R2: clasificación G/E/P; columnas 1..n=A, n+1..2n=C, 2n+1..3n=B, 3n+1..4n=D;
    sin repetir pareja; sin enfrentar compañero ganador R1; reparar sobrantes.
R3..N-1: igual que R2 sin restricción compañero R1; solo no repetir parejas.
Última: patrón 1+3 vs 2+4 fluido por clasificación G/E/P.

POST-DESPLIEGUE
---------------
Eliminar y REGENERAR rondas ya guardadas para aplicar el nuevo algoritmo.
El reporte lee partiresul existente; sin regenerar verá asignación antigua.

TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
$zip->close();

echo "ZIP: {$zipPath}\n";
echo "Archivos: {$added}\n";
if ($missing !== []) {
    echo "Faltantes:\n- " . implode("\n- ", $missing) . "\n";
    exit(1);
}
exit(0);
