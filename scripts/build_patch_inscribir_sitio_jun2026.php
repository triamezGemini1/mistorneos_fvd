<?php



declare(strict_types=1);



/**

 * ZIP parche producción: inscripción en sitio, desinscribir DELETE, retirados eliminar.

 * Uso: php scripts/build_patch_inscribir_sitio_jun2026.php

 */



$root = dirname(__DIR__);

$distDir = $root . DIRECTORY_SEPARATOR . 'dist';

$timestamp = date('Y-m-d_His');

$zipName = "mistorneos_fvd_patch_inscribir_sitio_{$timestamp}.zip";

$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;



$files = [

    'config/deploy_build.php',

    'public/verificar_despliegue_version.php',

    'lib/AsociacionAdminHelper.php',

    'lib/InscritosHelper.php',

    'lib/InscripcionPagoService.php',

    'lib/NumfvdHelper.php',

    'lib/Tournament/Handlers/RegistrationHandler.php',

    'lib/BusquedaJugadorInscripcionService.php',

    'api/tournament_admin_toggle_inscripcion.php',

    'lib/InscribirSitioBusquedaService.php',

    'lib/InscribirSitioDisponiblesService.php',

    'public/api/inscribir_sitio_buscar.php',

    'public/api/inscribir_sitio_disponibles.php',

    'public/api/inscripcion_admin.php',

    'modules/torneo_gestion.php',

    'modules/gestion_torneos/inscribir-sitio.php',

    'modules/registrants.php',

    'modules/registrants/_fila_listado_inscrito.php',

    'public/assets/registrants-inscripciones.js',

    'public/assets/css/inscribir-sitio-page.css',

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

MISTORNEOS FVD — Parche inscripción en sitio + retirados

========================================================



Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).



INSCRIPCIÓN EN SITIO

--------------------

1) Búsqueda por cédula, ID o nombre (sin elegir asociación antes).

2) Al encontrar al atleta, el selector de asociación se completa solo.

3) Disponibles = no inscritos activos (retirados/eliminados pueden reinscribirse).

4) Clic en fila de Disponibles para inscribir.

5) Desinscribir en sitio: DELETE en inscritos (no estatus retirado).



REPORTE INSCRIPCIONES (registrants)

-----------------------------------

1) Marcar retirado: elimina la fila en inscritos (libera al atleta).

2) Filtro Retirados: botón Eliminar (papelera) para retirados legacy (estatus 4/9).

   Borra el registro y deja al jugador disponible.



ARCHIVOS NUEVOS (crear si no existen)

- lib/InscribirSitioBusquedaService.php

- lib/InscribirSitioDisponiblesService.php

- public/api/inscribir_sitio_buscar.php

- public/api/inscribir_sitio_disponibles.php



VERIFICAR

---------

Build: 2026-06-02-inscripcion-retirados-eliminar

URL: public/verificar_despliegue_version.php



PRUEBA

1) Inscribir en sitio → desinscribir → vuelve a Disponibles.

2) registrants → Retirados → Eliminar → jugador disponible para reinscribir.

TXT;



$zip->addFromString('LEEME_PARCHE.txt', $readme);

++$added;



$zip->close();



if ($missing !== []) {

    fwrite(STDERR, "Advertencia — no encontrados:\n  - " . implode("\n  - ", $missing) . "\n");

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

