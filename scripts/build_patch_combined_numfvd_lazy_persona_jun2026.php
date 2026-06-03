<?php

declare(strict_types=1);

/**
 * ZIP combinado: hotfix Numfvd + BD personas bajo demanda.
 * Uso: php scripts/build_patch_combined_numfvd_lazy_persona_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_combined_numfvd_lazy_persona_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    // Build / bootstrap
    'config/deploy_build.php',
    'config/bootstrap.php',
    'config/utf8.php',
    'config/db_config.php',
    'config/persona_database.php',
    // Helpers / servicios
    'lib/app_helpers.php',
    'lib/NumfvdHelper.php',
    'lib/InscritosHelper.php',
    'lib/BusquedaJugadorInscripcionService.php',
    'lib/InscribirSitioBusquedaService.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    // APIs inscripción sitio
    'api/tournament_admin_toggle_inscripcion.php',
    'public/api/inscribir_sitio_buscar.php',
    // APIs búsqueda persona (lazy)
    'api/search_persona.php',
    'api/search_user_persona.php',
    'public/api/search_persona.php',
    'public/api/search_user_persona.php',
    'modules/invitations/inscripciones/buscar_persona.php',
    // Verificación
    'public/verificar_despliegue_version.php',
    'public/verificar_produccion.php',
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
MISTORNEOS FVD — Parche combinado (Numfvd + BD personas lazy)
=============================================================

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/).

INCLUYE DOS CORRECCIONES
------------------------

A) Hotfix Numfvd / bootstrap / inscripción en sitio
   - NumfvdHelper::resolverDesdeCedula() y resolverParaUsuario()
   - AppHelpers::detectPublicPathFromScript()
   - InscritosHelper fallback si NumfvdHelper es viejo en servidor
   - InscribirSitioBusquedaService + inscribir_sitio_buscar.php
   - RegistrationHandler + toggle_inscripcion

B) BD personas bajo demanda
   - La app abre solo con BD principal (mistorneos)
   - BD fvdadmin/personas SOLO en búsquedas por cédula
   - Sin DB_SECONDARY_* en .env: no conecta ni usa root por defecto
   - db_config.php: tryPdoSecondary(), credenciales alineadas con PersonaDatabase

ARCHIVOS CLAVE
- lib/NumfvdHelper.php, lib/InscritosHelper.php, lib/app_helpers.php
- config/persona_database.php, config/db_config.php
- lib/BusquedaJugadorInscripcionService.php
- public/api/search_persona.php, public/api/inscribir_sitio_buscar.php

OPCIONAL EN .env (enriquecer búsquedas por cédula)
- DB_SECONDARY_HOST, DB_SECONDARY_PORT, DB_SECONDARY_DATABASE
- DB_SECONDARY_USERNAME, DB_SECONDARY_PASSWORD

VERIFICAR TRAS DESPLIEGUE
- Build: 2026-06-02-lazy-persona-db
- public/verificar_despliegue_version.php
- public/verificar_produccion.php → BD secundaria "opcional"
- page=home carga sin error
- Inscripción en sitio + búsqueda por cédula
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
