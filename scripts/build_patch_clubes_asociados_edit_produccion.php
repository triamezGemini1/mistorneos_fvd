<?php

declare(strict_types=1);

/**
 * Parche: edición de asociaciones desde listado (logo + datos).
 * Extraer en public_html/mistorneos_fvd/ (sin config/ ni .env).
 *
 * Uso: php scripts/build_patch_clubes_asociados_edit_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_clubes_asociados_edit_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'modules/clubes_asociados/list.php',
    'modules/clubs/update.php',
    'public/assets/image-preview.js',
    'upload/logos/.gitkeep',
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

foreach (array_unique($files) as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($full, $rel);
    ++$added;
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche edición asociaciones (listado)
======================================================

EXTRAER EN: public_html/mistorneos_fvd/

NO incluye config/ ni .env (conservar los del servidor).

CORRIGE
-------
- Error fatal al guardar edición desde "Asociaciones de la organización"
- Subida y reemplazo de logo (JPG, PNG, GIF, WEBP, máx. 5 MB)
- Vista previa del logo al seleccionar archivo en el modal
- Mensajes de error claros si falla la subida del logo
- Soporte WEBP en edición admin general (clubs/update.php)

VERIFICAR
---------
1. Dashboard → Asociaciones de la organización (page=clubes_asociados)
2. Editar una asociación: cambiar datos y/o subir logo
3. Permisos 755 en upload/logos/
4. .../public/verificar_despliegue_version.php
TXT;

$zip->addFromString('LEEME_PARCHE_CLUBES_ASOCIADOS_EDIT.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Faltan archivos:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";
echo "\n>>> Extraer en: public_html/mistorneos_fvd/ <<<\n";

exit($missing !== [] ? 2 : 0);
