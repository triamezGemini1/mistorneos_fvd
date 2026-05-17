<?php

declare(strict_types=1);

/**
 * ZIP parcial: últimos cambios acumulados del parche FVD.
 * Uso: php scripts/build_patch_zip.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    // Finanzas
    'modules/finances.php',
    'modules/finances/actualizar_deudas.php',
    'public/api/finances_actualizar_deudas.php',
    // Usuarios orden por rol
    'modules/users.php',
    // Landing contador inscritos
    'lib/LandingDataService.php',
  // Inscripción en línea
    'public/inscribir_evento_masivo.php',
    // Reportes de pago admin
    'lib/ReportePagoUsuarioService.php',
    'modules/reportes_pago_usuarios.php',
    'public/api/reporte_pago_admin.php',
    'public/assets/reportes-pago-usuarios.js',
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'modules/gestion_torneos/panel-moderno.php',
    'modules/registrants.php',
    'public/api/inscripcion_admin.php',
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

$readme = <<<'TXT'
MISTORNEOS FVD — Parche (últimos cambios)
========================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

1. Finanzas — actualizar deudas (API JSON)
2. Usuarios — orden por rol en listado
3. Landing — contador inscritos (incluye pendiente)
4. Inscripción en línea — cédula/nacionalidad en inscritos
5. Reportes de pago — switch confirmado, recibo imprimible, notificaciones web/Telegram
6. Reportes de pago — buscador (cédula, nombre, ID usuario, NUMFVD) y filtro Todos/Pendientes/Confirmados
7. Gestionar inscripciones (registrants) — buscador, filtro, switch confirmado/pendiente, recibo y notificaciones

Reportes de pago: Panel torneo → Reportes de pago. Al activar el switch se confirma el pago,
muestra recibo para imprimir y notifica al atleta (web push + Telegram).
Use el buscador y los botones de estado en el encabezado del reporte.

No requiere migración SQL.
TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Advertencia: archivos no encontrados:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos en el paquete: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";
echo "\nSube y extrae en cPanel → public_html/mistorneos_fvd/\n";

exit($missing !== [] ? 2 : 0);
