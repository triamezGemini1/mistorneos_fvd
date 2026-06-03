<?php

declare(strict_types=1);

/**
 * ZIP producción: asignación mesas (R1 parejas, R2+ greedy G/E/P) + carga automática resultados + PHP 7.4.
 * Uso: php scripts/build_patch_produccion_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_produccion_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/InscritosHelper.php',
    'lib/TorneoCampoNumerico.php',
    'lib/SancionesHelper.php',
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionAlgorithm.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLinealClasificacionTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionQueueTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionConflictos2Trait.php',
    'lib/PartiresulEstatusSql.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/MesaEstructuraReporteService.php',
    'lib/CargaAutomaticaResultadosRondaService.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'docs/PROCEDIMIENTO_ASIGNACION_RONDAS_Y_EVALUACION.md',
    'docs/CARGA_AUTOMATICA_RESULTADOS_RONDA.md',
    'scripts/carga_automatica_resultados_ronda.php',
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
MISTORNEOS FVD — Parche producción (mayo 2026)
==============================================

BUILD: 2026-05-21-produccion-greedy-r1-carga-auto

Extraer en la raíz del proyecto (misma carpeta que public/).

VERIFICAR
---------
Abrir: public/verificar_despliegue_version.php
Deben salir OK:
  - Motor: greedy G/E/P mesa a mesa
  - R2+: greedy + clasificación estricta G/E/P
  - Guardar ronda SIN abort por pareja repetida

ASIGNACIÓN DE MESAS
-------------------
R1 (individual por posición):
  posi_rnk lineal; parejas (1+2),(3+4)… mesas 1..n; segunda pasada B/D mesas 1..n.

R2 .. N-1:
  Clasificación SOLO ganados, efectividad, puntos (desc).
  Greedy: mejor disponible → A; siguiente válido → C (sin repetir pareja);
  #2 no entra en mesa de #1 si ya fueron pareja → encabeza mesa 2.
  R2: no enfrentar compañeros ganadores de R1 en la misma mesa.

Última ronda: 1+3 vs 2+4 por clasificación G/E/P.

CARGA AUTOMÁTICA RESULTADOS (pruebas / demo)
--------------------------------------------
  php scripts/carga_automatica_resultados_ronda.php --dry-run ID_TORNEO RONDA
  php scripts/carga_automatica_resultados_ronda.php ID_TORNEO RONDA

Puntos aleatorios por mesa; ~3% forfait; sanciones -80/-40; amarillas sin puntos.
Reporte HTML en dist/reporte_faltas_*.html

POST-DESPLIEGUE
---------------
1. Regenerar rondas ya creadas para aplicar el nuevo motor de asignación.
2. Tras carga automática de resultados: Actualizar estadísticas en el panel.
3. Borrar verificar_despliegue_version.php cuando confirme OK (opcional).

COMPATIBILIDAD PHP 7.4
----------------------
TorneoCampoNumerico y SancionesHelper sin tipo "mixed" (WAMP/PHP 7.4).

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
