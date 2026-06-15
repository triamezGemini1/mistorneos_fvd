<?php



declare(strict_types=1);



/**

 * Paquete completo junio 2026: foto perfil + portal credencial + reportes personales PDF.

 *

 * Uso: php scripts/build_patch_jun11_completo_produccion.php

 */



$root = dirname(__DIR__);

$distDir = $root . DIRECTORY_SEPARATOR . 'dist';

$timestamp = date('Y-m-d_His');

$zipName = "mistorneos_fvd_patch_jun11_completo_{$timestamp}.zip";

$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;



$files = [

    'config/deploy_build.php',

    'public/verificar_despliegue_version.php',

    // SQL

    'sql/migrate_usuarios_permite_reportes_personales.sql',

    // Foto perfil

    'lib/app_helpers.php',

    'lib/ProfilePhotoService.php',

    'modules/users/profile.php',

    'modules/users/profile_save.php',

    'public/includes/layout.php',

    'public/assets/image-preview.js',

    'public/profile.php',

    'public/profile_save.php',

    'public/modules/users/profile_save.php',

    // Portal credencial

    'public/entrar_credencial.php',

    'public/generate_credential.php',

    // Reportes personales PDF

    'lib/RankingAtletasPdfAccesoHelper.php',

    'modules/users.php',

    'modules/users/list.php',

    'public/ranking_atletas_detalle.php',

    'public/ranking_atletas_detalle_pdf.php',

    'public/user_portal.php',

];



$stripBomFor = ['public/profile_save.php', 'public/profile.php'];



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

MISTORNEOS FVD — Parche completo 11-jun-2026

============================================



EXTRAER EN: public_html/mistorneos_fvd/



Incluye:

  A) Foto de perfil (preview, carga, Carnet FVD, UI cyan)

  B) Portal credencial (QR → entrar_credencial, ranking landing)

  C) Reportes personales PDF (permiso por usuario en admin)



SQL OBLIGATORIO (solo para reportes personales)

------------------------------------------------

  sql/migrate_usuarios_permite_reportes_personales.sql



PASOS

-----

1. Backup del sitio y base de datos

2. Extraer ZIP en public_html/mistorneos_fvd/

3. Ejecutar SQL de migrate_usuarios_permite_reportes_personales.sql

4. Permisos 755 en upload/

5. Admin → Usuarios → habilitar "Reportes personales en PDF" por usuario

6. verificar_despliegue_version.php → build 2026-06-11-usuarios-entidad-reportes-pdf
7. Lista usuarios: columna Entidad + interruptor Reportes PDF en tabla

7. Vaciar OPcache en cPanel



PRUEBAS RÁPIDAS

---------------

- profile.php: subir foto, ver Carnet FVD

- user_portal.php?section=credencial: QR abre portal perfil

- user_portal.php?section=reportes_personales (usuario habilitado)

- ranking_atletas_detalle_pdf.php solo con id_usuario propio

TXT;



$zip->addFromString('LEEME_PARCHE_JUN11_COMPLETO.txt', $readme);

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

echo "ZIP completo creado:\n  {$zipPath}\n";

echo "Archivos: {$added}\n";

echo "Tamaño: {$sizeKb} KB\n";

echo "\n>>> Extraer en: public_html/mistorneos_fvd/ <<<\n";



exit($missing !== [] ? 2 : 0);

