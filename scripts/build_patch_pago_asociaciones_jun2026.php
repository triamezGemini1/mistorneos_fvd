<?php

declare(strict_types=1);

/**
 * ZIP parche: corrección pago inscritos, asociaciones (cód. 6), tipografía, QR en recibo.
 * Uso: php scripts/build_patch_pago_asociaciones_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_pago_asociaciones_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    // Fix error al confirmar pago (status pagado → confirmado)
    'lib/InscripcionPagoService.php',
    'public/api/inscripcion_admin.php',
    // QR personal en recibo
    'lib/ReciboPagoQrHelper.php',
    'lib/TorneoJugadorQrToken.php',
    'lib/ReportePagoUsuarioService.php',
    'public/torneo_qr_jugador.php',
    // Asociaciones: código 6 = Anzoátegui + selector en inscritos
    'lib/EntidadFvdCatalogo.php',
    'lib/ClubHelper.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    'modules/registrants.php',
    'modules/registrants/_fila_listado_inscrito.php',
    'public/assets/registrants-inscripciones.js',
    'public/assets/css/registrants-page.css',
    'public/includes/layout.php',
    'sql/fix_entidad_codigo_06_anzoategui.sql',
    'scripts/reparar_nombres_asociaciones_fvd.php',
    // Tipografía inscripción en sitio
    'public/assets/css/inscribir-sitio-page.css',
    'modules/gestion_torneos/inscribir-sitio.php',
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
MISTORNEOS FVD — Parche pago, asociaciones y recibo con QR
=========================================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

1) ERROR AL CONFIRMAR PAGO (registrants)
   - lib/InscripcionPagoService.php: payments.status = confirmado (no "pagado").
   - Sin esto falla la transacción y muestra "Error al actualizar el estatus de pago".

2) ASOCIACIÓN CÓDIGO 6
   - Catálogo FVD: 6 = ANZOATEGUI, 20 = BARINAS.
   - Ejecutar en servidor (una vez):
     php scripts/reparar_nombres_asociaciones_fvd.php
     o SOURCE sql/fix_entidad_codigo_06_anzoategui.sql

3) REPORTE INSCRIPCIONES
   - Selector para cambiar asociación del atleta.
   - Tipografía +30% (registrants-page.css).

4) RECIBO CON QR PERSONAL
   - Al confirmar pago o ver recibo: QR que abre torneo_qr_jugador.php (mesa/resultados).

5) INSCRIPCIÓN EN SITIO
   - Tipografía +30% (inscribir-sitio-page.css).

Verificar: confirmar un inscrito pendiente y abrir recibo (debe verse el QR).
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
