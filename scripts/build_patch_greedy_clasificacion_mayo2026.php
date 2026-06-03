<?php

declare(strict_types=1);

/**
 * Parche producción: greedy por clasificación (G/E/P), reporte mesas, correcciones.
 * Uso: php scripts/build_patch_greedy_clasificacion_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_greedy_clasificacion_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/InscritosHelper.php',
    'desktop/core/InscritosHelper.php',
    'lib/InscritosPartiresulHelper.php',
    'lib/PartiresulEstatusSql.php',
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionAlgorithm.php',
    'lib/Core/MesaAsignacion/MesaAsignacionQueueTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionConflictos1Trait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionConflictos2Trait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLimiteClubMesaTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionClubInterclubTrait.php',
    'lib/MesaEstructuraReporteService.php',
    'lib/GestionTorneosViewsData.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'config/MesaAsignacionParejasFijasService.php',
    'lib/ResumenJugadorNavigation.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/PartiresulAsignacionWriter.php',
    'lib/HistorialParejasService.php',
    'lib/TorneoIntegridadService.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'config/MesaAsignacionEquiposService.php',
    'scripts/auditar_torneo_integridad.php',
    'modules/torneo_gestion.php',
    'public/resumen_jugador.php',
    'modules/gestion_torneos/resumen-individual.php',
    'modules/gestion_torneos/posiciones.php',
    'modules/tournament_admin/resultados_general.php',
    'modules/tournament_admin/resultados_por_club.php',
    'modules/tournament_admin/resultados_equipos_detallado.php',
    'modules/tournament_admin/resultados_reportes.php',
    'modules/gestion_torneos/reporte_estructura_mesas.php',
    'lib/ReporteParejasRepetidasService.php',
    'modules/gestion_torneos/reporte_parejas_repetidas.php',
    'modules/tournament_admin/resultados_reportes.php',
    'modules/gestion_torneos/_reporte_estructura_jugador.php',
    'modules/gestion_torneos/panel-moderno.php',
    'modules/gestion_torneos/rondas.php',
    'public/assets/css/reporte-estructura-mesas.css',
    'public/includes/layout.php',
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
MISTORNEOS FVD — Parche producción (mayo 2026)
=============================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

BUILD: 2026-05-21-greedy-diferir-completar-ronda

OBLIGATORIO tras extraer: abrir verificar_despliegue_version.php — deben salir OK:
- Guardar ronda SIN abort por pareja repetida
- Motor: reparar parejas + diferir (sin abort)
Si falla, el ZIP no se aplicó sobre la ruta correcta.

REPORTE PAREJAS REPETIDAS
-------------------------
action=reporte_parejas_repetidas&torneo_id=ID&min_veces=2
Duplas que jugaron juntas (pareja AC o BD) 2+ veces; por fila: ronda, mesa, rivales.

RESUMEN INDIVIDUAL
------------------
- Consulta partiresul por NUMFVD; pareja y contrarios por secuencia (1-2 / 3-4).
- Clave de vista corregida: compañero (antes compaÃ±ero en datos).

RESUMEN INDIVIDUAL (enlaces)
-----------------------------
- Helper lib/ResumenJugadorNavigation.php: URL unificada y «Volver» con filtros.
- Clic en nombre: Posiciones, Resultados general, por club, equipos detallado, reporte mesas.
- Hub «Reportes de resultados»: usar vistas de origen (nombres enlazados).
Verificar: public/verificar_despliegue_version.php

ASIGNACIÓN DE MESAS
-------------------
Clasificación: ganados DESC → efectividad DESC → puntos DESC (posición solo desempate).
R2 adicional: ganadores R1 → BYE ganadores → luego G/E/P.

Greedy por ronda:
- Mejor disponible → cabeza pareja A (sec. 1). Mesa 1 = #1 del ranking.
- Siguiente válido → C (no repetir pareja).
- Siguiente → B; R2: no enfrentar en contra al compañero de R1.
- Siguiente → D (compañero B).
- Sin tope de jugadores del mismo club por mesa (no hill-climbing por club).

Rondas especiales:
- Penúltima (N>3): patrón 1+3 vs 2+4.
- Última (N>3): greedy. Última si N≤3: 1+3 vs 2+4.

REPORTE ESTRUCTURA MESAS
------------------------
Menú: Panel → "Estructura de mesas (reporte)"
URL: index.php?page=torneo_gestion&action=reporte_estructura_mesas&torneo_id=ID&ronda=2&pagina=1

- Selector de ronda y paginador de mesas.
- Por jugador: #orden clasificación, NUMFVD, nombre, G/E/P.
- Marcas P×n (pareja previa) y vs×n (enfrentamiento repetido).
- Aviso si mesa 1 no tiene #1 en cabeza A.

OTRAS CORRECCIONES INCLUIDAS
----------------------------
- Estatus inscritos (CAST) al generar ronda.
- Hojas anotación ronda 2+ (stats inscritos).
- Sanciones / efectividad al guardar resultados.
- Ganados/perdidos con regla de sanción.

POST-DESPLIEGUE OBLIGATORIO
---------------------------
Eliminar y REGENERAR las rondas ya creadas para aplicar el nuevo algoritmo.
El reporte lee partiresul guardado; sin regenerar verá la asignación antigua.

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
