<?php

declare(strict_types=1);

/**
 * ZIP parcial para despliegue (solo archivos del parche actual).
 * Uso: php scripts/build_patch_zip.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'lib/DashboardData.php',
    'lib/OrganizacionesData.php',
    'lib/UserAccessNotifier.php',
    'modules/users.php',
    'modules/users/list.php',
    'modules/users/send_access_notification.php',
    'modules/users/send_access_notification_batch.php',
    'modules/finances.php',
    'modules/finances/actualizar_deudas.php',
    'modules/registrants_report.php',
    'modules/registrants_report_retirados.php',
    'modules/admin_torneo_operadores/_form_registro_rol.php',
    'modules/admin_org/organizacion/views/mi_organizacion_form_activar.php',
    'modules/gestion_torneos/sustituir-jugador.php',
    'modules/gestion_torneos/inscribir_equipo_sitio.php',
    'public/includes/layout.php',
    'public/api/finances_actualizar_deudas.php',
    'public/assets/app-search.js',
    'public/assets/app-search.css',
    'public/assets/users-bulk-notify.css',
    'public/assets/users-bulk-notify.js',
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
MISTORNEOS FVD — Parche de despliegue
=====================================

Extraer el contenido en la raíz del proyecto en el servidor
(ej. public_html/mistorneos_fvd/) respetando las carpetas:

  lib/
  modules/
  public/assets/

Contenido:
  • Dashboard FVD (DashboardData, OrganizacionesData)
  • Notificación de credenciales individual y masiva
  • Búsqueda activa al salir del campo (blur), sin botón Buscar
  • Finanzas: actualizar deudas vía API JSON (public/api/finances_actualizar_deudas.php)
  • Usuarios: listado ordenado por rol (admin general → admin org. → … → usuario)

Pasos:
  1. Subir y extraer este ZIP sobre la instalación existente.
  2. En el navegador: Ctrl+F5 en Finanzas y Administración de Usuarios.
  3. Probar actualizar deudas al elegir torneo y orden del listado de usuarios.

No requiere migración SQL para este parche.
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
