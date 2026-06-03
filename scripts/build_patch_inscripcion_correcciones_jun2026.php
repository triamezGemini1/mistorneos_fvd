<?php

declare(strict_types=1);

/**
 * ZIP: correcciones inscripción — 404 desinscribir, recibo/QR, revertir pendiente, quitar inscripción.
 * Uso: php scripts/build_patch_inscripcion_correcciones_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_pago_recibo_bloqueo_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    // Rutas monorepo (URLs API correctas)
    'lib/IntegralUrl.php',
    'lib/app_helpers.php',
    // Pago / recibo / revertir / quitar
    'lib/InscripcionPagoService.php',
    'lib/ReciboInscripcionRenderer.php',
    'lib/ReciboPagoQrHelper.php',
    'lib/TorneoJugadorQrToken.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    'public/api/inscripcion_admin.php',
    'public/torneo_qr_jugador.php',
    // Inscribir en sitio (desinscribir sin 404)
    'modules/gestion_torneos/inscribir-sitio.php',
    'resources/views/tournament/parts/inscribir-sitio.php',
    'public/tournament_admin_toggle_inscripcion.php',
    'api/tournament_admin_toggle_inscripcion.php',
    // UI administración inscripciones
    'modules/registrants.php',
    'modules/registrants/_fila_listado_inscrito.php',
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

$build = '2026-06-02-pago-recibo-bloqueo';
if (is_file($root . '/config/deploy_build.php')) {
    require_once $root . '/config/deploy_build.php';
    $build = FVD_DEPLOY_BUILD;
}

$readme = <<<TXT
MISTORNEOS FVD — Correcciones inscripción (desinscribir, recibo, revertir, quitar)
==================================================================================

Extraer en la raíz del proyecto desplegado:
  - Standalone: public_html/mistorneos_fvd/
  - Monorepo:   public_html/mistorneos_fvd1/mistorneos_fvd/

BUILD: {$build}

CAMBIOS
-------
1) DESINSCRIBIR EN SITIO (404 corregido)
   - URLs vía AppHelpers::url() / AppHelpers::api()
   - modules/gestion_torneos/inscribir-sitio.php

2) RECIBO EN ADMINISTRACIÓN DE INSCRIPCIONES
   - API: AppHelpers::api('inscripcion_admin.php')
   - QR del recibo con ruta monorepo correcta (ReciboPagoQrHelper)
   - Recibo solo si inscripción confirmada

3) REVERTIR CONFIRMADO → PENDIENTE
   - Switch de pago: confirmación al desmarcar
   - Revierte reportes_pago_usuarios y payments a pendiente

4) QUITAR INSCRIPCIÓN ERRÓNEA
   - Botón "Quitar" (usuario menos) en listado activo
   - Acción quitar_inscripcion en inscripcion_admin.php

ARCHIVOS CLAVE
- public/api/inscripcion_admin.php
- lib/InscripcionPagoService.php
- public/assets/registrants-inscripciones.js
- modules/gestion_torneos/inscribir-sitio.php
- lib/IntegralUrl.php + lib/app_helpers.php (monorepo)

VERIFICAR (.env monorepo)
- BASE_PATH=/mistorneos_fvd1/mistorneos_fvd/public/
- INTEGRAL_WEB_ROOT=mistorneos_fvd1

PRUEBAS
1) Inscribir en sitio → desinscribir → sin error 404.
2) registrants → confirmar → recibo con QR.
3) Desmarcar confirmado → vuelve a pendiente.
4) Botón Quitar → jugador disponible de nuevo.
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
