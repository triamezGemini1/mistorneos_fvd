<?php

declare(strict_types=1);

/**
 * ZIP: recibo de pago + QR personal + eliminar al retirar (registrants).
 * Uso: php scripts/build_patch_recibo_qr_retirados_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_recibo_qr_retirados_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    // Pago / recibo
    'lib/InscripcionPagoService.php',
    'lib/InscritosHelper.php',
    'lib/ReciboPagoQrHelper.php',
    'lib/TorneoJugadorQrToken.php',
    'lib/ReportePagoUsuarioService.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    'public/api/inscripcion_admin.php',
    'public/torneo_qr_jugador.php',
    // UI reporte inscripciones
    'modules/registrants.php',
    'modules/registrants/_fila_listado_inscrito.php',
    'public/assets/registrants-inscripciones.js',
    'public/assets/css/registrants-page.css',
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

$build = '2026-06-02-recibo-qr-retirados';
if (is_file($root . '/config/deploy_build.php')) {
    require_once $root . '/config/deploy_build.php';
    $build = FVD_DEPLOY_BUILD;
}

$readme = <<<TXT
MISTORNEOS FVD — Recibo de pago + QR + eliminar retirados
=========================================================

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/ o monorepo).

BUILD: {$build}

1) RECIBO DE PAGO
   - Confirmar inscrito pendiente → modal con recibo imprimible.
   - lib/InscripcionPagoService.php: status payments = confirmado (evita error de transacción).

2) QR PERSONAL EN RECIBO
   - lib/ReciboPagoQrHelper.php + lib/TorneoJugadorQrToken.php
   - public/torneo_qr_jugador.php (destino del QR: mesa / resultados del jugador)
   - public/api/inscripcion_admin.php acción recibo_inscrito

3) ELIMINAR AL RETIRAR
   - Switch retirado: DELETE físico en inscritos (libera al jugador).
   - Filtro Retirados: botón Eliminar (papelera) → accion eliminar_inscripcion.
   - modules/registrants.php + registrants-inscripciones.js

ARCHIVOS CLAVE
- public/api/inscripcion_admin.php
- lib/ReciboPagoQrHelper.php
- public/assets/registrants-inscripciones.js
- modules/registrants/_fila_listado_inscrito.php

VERIFICAR
1) registrants → confirmar pago → recibo con QR visible.
2) Escanear QR → abre torneo_qr_jugador.php del jugador.
3) Marcar retirado → jugador liberado.
4) Filtro Retirados → Eliminar → desaparece de la lista.
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
