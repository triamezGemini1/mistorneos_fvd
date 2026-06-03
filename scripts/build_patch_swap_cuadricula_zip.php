<?php

declare(strict_types=1);

/**
 * ZIP parcial: Swap/reemplazo (op_especiales) + Cuadrícula standalone.
 * Uso: php scripts/build_patch_swap_cuadricula_zip.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_swap_cuadricula_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    // Cuadrícula pantalla dedicada + auto tras generar ronda
    'modules/torneo_gestion.php',
    'modules/gestion_torneos/cuadricula.php',
    'modules/gestion_torneos/panel-moderno.php',
    'modules/gestion_torneos/panel_equipos.php',
    'resources/views/tournament/parts/panel-moderno.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'public/assets/css/custom-13inch.css',
    'public/assets/css/torneo-context-switch.css',
    'resources/views/tournament/partials/grid_display.php',
    // Swap y reemplazo (ubicar → confirmar + observaciones)
    'lib/Tournament/OpEspecialesHelper.php',
    'modules/op_especiales.php',
    'modules/op_especiales/_panel_ubicacion.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/NumfvdHelper.php',
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

$build = 'desconocido';
$deployFile = $root . '/config/deploy_build.php';
if (is_file($deployFile)) {
    $c = file_get_contents($deployFile);
    if (preg_match("/FVD_DEPLOY_BUILD',\s*'([^']+)'/", $c, $m)) {
        $build = $m[1];
    }
}

$readme = <<<TXT
MISTORNEOS FVD — Parche Swap + Cuadrícula
=========================================
Build: {$build}

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/).

CUADRÍCULA (operaciones del torneo)
-----------------------------------
- Pantalla independiente sin menú del dashboard.
- Header: torneo+ronda | selector de torneo CENTRADO | rotación/páginas + Volver.
- Sin botón Imprimir.
- 15 filas de datos por segmento (antes 12).
- Tras «Generar Ronda» abre automáticamente la cuadrícula de la ronda nueva.
- Botón Cuadrícula del panel abre en pestaña nueva.

SWAP Y REEMPLAZO (Op. Especiales)
---------------------------------
- Paso 1: «Ubicar atletas» — muestra mesa/ronda de cada jugador; si falta alguno, suspende.
- Paso 2: «Confirmar» — intercambia o reemplaza numfvd + id_usuario en partiresul.
- Observación registrada en partiresul (y auditoría si existe la tabla).

VERIFICACIÓN
------------
public/verificar_despliegue_version.php
Debe mostrar build {$build} y OK en cuadricula, OpEspecialesHelper, panel-moderno.

.env recomendado (si aplica QR/numfvd):
FVD_PARTIRESUL_SOLO_NUMFVD=1

Limpiar OPcache en cPanel tras subir.
TXT;

$zip->addFromString('LEEME_PARCHE_SWAP_CUADRICULA.txt', $readme);
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
echo "ZIP parche swap+cuadricula:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";

exit($missing !== [] ? 2 : 0);
