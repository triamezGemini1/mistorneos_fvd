<?php

declare(strict_types=1);

/**
 * ZIP producción: inscripción en sitio (ClubHelper, disponibles, retirar) + admin Pagar/recibo.
 * Uso: php scripts/build_patch_inscripcion_sitio_jun02_2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_inscripcion_sitio_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'config/env.production.example',
    // URLs (standalone mistorneos_fvd/public + monorepo opcional)
    'lib/IntegralUrl.php',
    'lib/app_helpers.php',
    // Backend inscripción
    'lib/InscritosHelper.php',
    'lib/InscripcionPagoService.php',
    'lib/ReciboInscripcionRenderer.php',
    'lib/InscribirSitioBusquedaService.php',
    'lib/BusquedaJugadorInscripcionService.php',
    'lib/ReciboPagoQrHelper.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    // APIs
    'api/tournament_admin_toggle_inscripcion.php',
    'public/tournament_admin_toggle_inscripcion.php',
    'public/api/inscribir_sitio_buscar.php',
    'public/api/inscripcion_admin.php',
    // Vistas
    'modules/gestion_torneos/inscribir-sitio.php',
    'modules/registrants.php',
    'modules/registrants/_fila_listado_inscrito.php',
    'public/includes/layout.php',
    'public/assets/registrants-inscripciones.js',
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

$build = '2026-06-02-inscripcion-sitio-pagar-retirar';
if (is_file($root . '/config/deploy_build.php')) {
    require_once $root . '/config/deploy_build.php';
    $build = FVD_DEPLOY_BUILD;
}

$readme = <<<TXT
MISTORNEOS FVD — Parche inscripción en sitio + admin Pagar/Retirar
==================================================================

Extraer en la raíz del proyecto desplegado (instalación STANDALONE):
  public_html/mistorneos_fvd/

BUILD: {$build}

.env en producción (standalone — NO monorepo)
-------------------------------------------
APP_URL=https://tudominio.com/mistorneos_fvd
BASE_PATH=/mistorneos_fvd/public/
# Dejar vacío o no definir INTEGRAL_WEB_ROOT

CAMBIOS
-------
1) FIX ClubHelper en RegistrationHandler (error al inscribir/cambiar asociación)
2) Inscribir en sitio: listar atletas al elegir asociación (modo=disponibles)
3) Búsqueda: autocompleta asociación y muestra en Disponibles si está libre
4) Botón Retirar explícito en listado de inscritos (inscribir-sitio)
5) Admin registrants: botón Pagar → modal recibo (Bootstrap wait fix)
6) Retirar en admin elimina inscripción y libera atleta

ARCHIVOS CLAVE
- lib/Tournament/Handlers/RegistrationHandler.php
- modules/gestion_torneos/inscribir-sitio.php
- public/api/inscribir_sitio_buscar.php
- public/assets/registrants-inscripciones.js
- modules/registrants.php

VERIFICAR
- public/verificar_despliegue_version.php debe mostrar BUILD: {$build}

PRUEBAS
1) Inscribir en sitio → elegir asociación → ver disponibles
2) Buscar atleta → inscribir → sin error ClubHelper
3) Retirar en sitio → vuelve a Disponibles
4) registrants → Pagar → modal recibo con QR
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
echo "BUILD: {$build}\n";

exit($missing !== [] ? 2 : 0);
