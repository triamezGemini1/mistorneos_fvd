<?php

declare(strict_types=1);

/**
 * Parche producción: generar ronda (estatus), hojas anotación, sanciones/efectividad.
 * Uso: php scripts/build_patch_correcciones_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_correcciones_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/InscritosHelper.php',
    'desktop/core/InscritosHelper.php',
    'lib/InscritosPartiresulHelper.php',
    'lib/GestionTorneosViewsData.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'config/MesaAsignacionParejasFijasService.php',
    'modules/torneo_gestion.php',
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
MISTORNEOS FVD — Parche correcciones (mayo 2026)
==============================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

CONTENIDO
---------
1. Generar ronda — error SQL "Truncated incorrect DOUBLE value: 'confirmado'"
   - lib/InscritosHelper.php, desktop/core/InscritosHelper.php
   - Comparación de estatus vía CAST (compatible INT/ENUM en MySQL estricto).

2. Hojas de anotación — estadísticas en ronda 2+ en cero
   - lib/GestionTorneosViewsData.php
   - JOIN inscritos por NUMFVD/id real; stats desde columnas del JOIN.

3. Sanciones y efectividad al guardar resultados (ej. mesa 3: 170 vs 234, sanción 80)
   - lib/Tournament/Handlers/TournamentActionHandler.php
   - Puntos de pareja contraria antes de calcular; evaluarSancionIndividual correcto.
   - actualizarEstadisticasInscritos tras guardar cada mesa.

4. Ganados/perdidos en inscritos con sanción
   - modules/torneo_gestion.php (actualizarEstadisticasInscritos)
   - Regla: (resultado1 - sanción) vs pareja contraria; no solo resultado1 > resultado2.

5. Aprobar acta QR — misma lógica de sanción
   - modules/torneo_gestion.php (verificarActaAprobar)

6. Parejas fijas — SQL estatus sin mezcla INT/texto
   - config/MesaAsignacionParejasFijasService.php

POST-DESPLIEGUE
---------------
1. Subir y extraer el ZIP sobre la instalación actual (respaldar antes).
2. Abrir: https://tu-dominio/.../public/verificar_despliegue_version.php
   Debe mostrar build: 2026-05-20-correcciones-rondas-sanciones-hojas
3. Mesas ya guardadas con datos incorrectos: volver a GUARDAR la mesa o
   ejecutar «Actualizar estadísticas» en el panel del torneo.

No requiere migración SQL obligatoria.

TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Advertencia: archivos no encontrados:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";

exit($missing !== [] ? 2 : 0);
