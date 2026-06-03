<?php

declare(strict_types=1);

/**
 * Parche: R2+ greedy por clasificación G/E/P (probar asignación ronda 2).
 * Uso: php scripts/build_patch_greedy_r2_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_greedy_r2_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/InscritosHelper.php',
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaAsignacion/MesaAsignacionAlgorithm.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLinealClasificacionTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionQueueTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionConflictos2Trait.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'lib/PartiresulEstatusSql.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/MesaEstructuraReporteService.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'docs/PROCEDIMIENTO_ASIGNACION_RONDAS_Y_EVALUACION.md',
    'scripts/test_generar_ronda2_torneo2.php',
    'scripts/diag_mesa1_r2.php',
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
MISTORNEOS FVD — Parche greedy ronda 2 (mayo 2026)
=================================================

BUILD: 2026-05-21-greedy-clasificacion-gep

Extraer en la raíz del proyecto. Verificar:
  public/verificar_despliegue_version.php

RONDAS 2 .. N-1 (greedy)
------------------------
- Clasificación: ganados DESC, efectividad DESC, puntos DESC (único criterio).
- Mejor disponible → A mesa 1; siguiente válido en ranking → C (sin repetir pareja).
- Si #1 y #2 ya fueron pareja: #3 con #1; #2 encabeza mesa 2.
- R2: no enfrentar en la mesa a compañeros ganadores de R1.
- R3+: solo no repetir pareja.

R1: posi_rnk, parejas de a dos en dos pasadas (mesas 1..n, luego 1..n).
Última ronda: 1+3 vs 2+4 por clasificación G/E/P.

PROBAR EN SERVIDOR (CLI, opcional)
----------------------------------
  php scripts/test_generar_ronda2_torneo2.php
  php scripts/diag_mesa1_r2.php ID_TORNEO 2

POST-DESPLIEGUE
---------------
1. Eliminar ronda 2 (y siguientes si aplica) en el panel.
2. Regenerar ronda 2 con resultados de R1 ya cargados.
3. Revisar cuadrícula / reporte estructura mesas: mesa 1 debe tener #1 en A.

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
