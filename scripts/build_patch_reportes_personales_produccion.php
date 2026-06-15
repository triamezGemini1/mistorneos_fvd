<?php



declare(strict_types=1);



/**

 * Parche: permiso por usuario para reportes PDF personales (ranking propio).

 *

 * Uso: php scripts/build_patch_reportes_personales_produccion.php

 */



$root = dirname(__DIR__);

$distDir = $root . DIRECTORY_SEPARATOR . 'dist';

$timestamp = date('Y-m-d_His');

$zipName = "mistorneos_fvd_patch_reportes_personales_{$timestamp}.zip";

$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;



$files = [

    'config/deploy_build.php',

    'public/verificar_despliegue_version.php',

    'sql/migrate_usuarios_permite_reportes_personales.sql',

    'lib/RankingAtletasPdfAccesoHelper.php',

    'modules/users.php',

    'modules/users/list.php',

    'public/ranking_atletas_detalle.php',

    'public/ranking_atletas_detalle_pdf.php',

    'public/user_portal.php',

    'scripts/asignar_acceso_inicial_usuarios_numfvd_cli.php',

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

MISTORNEOS FVD — Parche reportes personales PDF

===============================================



EXTRAER EN: public_html/mistorneos_fvd/



NO sobrescribir config/ ni .env del servidor (solo deploy_build.php si aplica).



SQL OBLIGATORIO (phpMyAdmin o consola MySQL)

--------------------------------------------

Ejecutar una vez:



  sql/migrate_usuarios_permite_reportes_personales.sql



Añade usuarios.permite_reportes_personales (0/1).



FUNCIONALIDAD

-------------

1. Admin General → Usuarios → Editar → checkbox "Habilitar reportes personales en PDF"

2. Usuario habilitado ve menú "Reportes personales" en el portal

3. Solo puede ver/descargar PDF de SU propio id_usuario en el ranking

4. Afiliación/anualidad: desactivada por ahora (REQUIERE_AFILIACION_VIGENTE = false)



VERIFICAR

---------

1. Ejecutar SQL en producción

2. Marcar permiso en un usuario de prueba

3. Login → user_portal.php?section=reportes_personales

4. Descargar PDF solo del propio atleta

5. .../public/verificar_despliegue_version.php

   (build: 2026-06-11-usuarios-entidad-reportes-pdf)

LISTA USUARIOS
--------------
- Columna Entidad (usuarios.entidad), no club
- Interruptor Reportes PDF en la tabla (Admin General)

6. Tras subir: vaciar OPcache en cPanel si aplica

ACCESO INICIAL (rol usuario + NUMFVD)
-------------------------------------
  php scripts/asignar_acceso_inicial_usuarios_numfvd_cli.php
  php scripts/asignar_acceso_inicial_usuarios_numfvd_cli.php --apply

Usuario: user{numfvd}  Clave: {numfvd}  (ej. user2701 / 2701)

TXT;



$zip->addFromString('LEEME_PARCHE_REPORTES_PERSONALES.txt', $readme);

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

echo ">>> Ejecutar SQL antes de probar <<<\n";



exit($missing !== [] ? 2 : 0);

