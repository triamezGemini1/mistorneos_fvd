<?php

declare(strict_types=1);

/**
 * ZIP hotfix producción: NumfvdHelper, bootstrap detectPublicPathFromScript, inscripción sitio.
 * Uso: php scripts/build_patch_hotfix_numfvd_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_hotfix_numfvd_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'config/bootstrap.php',
    'public/verificar_despliegue_version.php',
    'lib/app_helpers.php',
    'lib/NumfvdHelper.php',
    'lib/InscritosHelper.php',
    'config/persona_database.php',
    'lib/BusquedaJugadorInscripcionService.php',
    'lib/InscribirSitioBusquedaService.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    'api/tournament_admin_toggle_inscripcion.php',
    'public/api/inscribir_sitio_buscar.php',
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
MISTORNEOS FVD — Hotfix Numfvd + bootstrap (producción)
======================================================

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/).

CORRIGE
-------
1) Call to undefined method NumfvdHelper::resolverDesdeCedula()
2) Call to undefined method NumfvdHelper::resolverParaUsuario()
3) Call to undefined method AppHelpers::detectPublicPathFromScript()
4) Inscripción en sitio / toggle_inscripcion sin depender de NumfvdHelper antiguo

ARCHIVOS CLAVE
- lib/NumfvdHelper.php (métodos resolverDesdeCedula / resolverParaUsuario)
- lib/InscritosHelper.php (fallback si NumfvdHelper es viejo)
- lib/app_helpers.php (detectPublicPathFromScript)
- config/bootstrap.php

NOTA PersonaDatabase "Access denied root@localhost"
- No es por cambiar .env: el código nuevo (inscribir_sitio) intenta BD externa en altas;
  si APP_ENV=development en servidor real, antes usaba user root por defecto.
- persona_database.php ahora detecta host laestacion* y usa credenciales de producción.

VERIFICAR: build 2026-06-02-hotfix-numfvd-persona-host
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
