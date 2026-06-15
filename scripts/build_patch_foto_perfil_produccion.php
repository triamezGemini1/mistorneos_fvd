<?php

declare(strict_types=1);

/**
 * Parche: foto de perfil (previsualización, carga y visualización tras guardar).
 * Extraer en public_html/mistorneos_fvd/ (sin config/ ni .env).
 *
 * Uso: php scripts/build_patch_foto_perfil_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_foto_perfil_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/app_helpers.php',
    'lib/ProfilePhotoService.php',
    'modules/users/profile.php',
    'modules/users/profile_save.php',
    'public/includes/layout.php',
    'public/assets/image-preview.js',
    'public/user_portal.php',
    'public/profile.php',
    'public/profile_save.php',
    'public/modules/users/profile_save.php',
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

$stripBomFor = ['public/profile_save.php', 'public/profile.php'];

foreach (array_unique($files) as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    if (in_array($rel, $stripBomFor, true)) {
        $content = (string) file_get_contents($full);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        $zip->addFromString($rel, $content);
    } else {
        $zip->addFile($full, $rel);
    }
    ++$added;
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche foto de perfil
======================================

EXTRAER EN: public_html/mistorneos_fvd/

NO incluye config/ ni .env (conservar los del servidor).

CORRIGE
-------
1) Foto de perfil: previsualización, carga y visualización (view_image.php)
2) UI perfil: Carnet FVD (NUMFVD), fondo #00CAF9, tipografía mejorada
3) Foto a la derecha (+20% tamaño), ID usuario al 50% con Carnet FVD al lado
4) Foto se guarda en profile.php (sin pasar por profile_save.php)
5) profile_save.php sin BOM; Telegram debajo de info personal

VERIFICAR
---------
1. .../public/profile.php → Carnet FVD, formulario cyan, foto a la derecha
2. Cámara → previsualizar → Guardar foto → recargar
3. Permisos 755 en upload/
4. .../public/verificar_despliegue_version.php
   (build: 2026-06-11-perfil-foto-carnet-fvd-ui)
TXT;

$zip->addFromString('LEEME_PARCHE_FOTO_PERFIL.txt', $readme);
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
