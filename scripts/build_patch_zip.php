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
    // Versión de despliegue
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    // Rutas / perfil / contraseña
    'lib/app_helpers.php',
    'public/switch_role.php',
    'public/includes/user_menu_dropdown.php',
    'modules/users/profile.php',
    'modules/users/profile_save.php',
    'modules/users/change_password.php',
    'modules/users/change_password_save.php',
    'public/change_password_save.php',
    // Inscritos / reporte torneo
    'lib/InscritosHelper.php',
    'lib/InscripcionPagoService.php',
    'modules/registrants.php',
    'public/api/inscripcion_admin.php',
    'public/assets/registrants-inscripciones.js',
    // Landing SPA
    'public/landing-spa.php',
    'public/assets/landing-spa.js',
    'public/assets/dist/output.css',
    'lib/app_helpers.php',
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
    'modules/gestion_torneos/panel-moderno.php',
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

INSCRITOS / REPORTE POR TORNEO (registrants)
- Filtros Todos / Pendientes / Confirmados / Retirados: botones separados, una fila, alineados con el buscador; sin botón Aplicar (auto al elegir).
- Columna Pago: switch pendiente ↔ confirmado.
- Columna Retirado: switch aparte (estatus 9).
- Acciones: enviar mensaje (web push + Telegram) y recibo al confirmar.
- Vista compacta torneo: oculta estadísticas globales y panel de filtros antiguo.
- Redirect correcto al cambiar rol o salir del perfil.

PERFIL / CONTRASEÑA
- Rutas AppHelpers; guardado de contraseña embebido en layout.

OTROS (paquetes anteriores)
- Finanzas, reportes de pago, landing, inscripción en línea, orden usuarios por rol.

LANDING
- Recompilar CSS: npm run build:css (incluido output.css en este parche).
- Hero sin recorte de título; menú responsive md:flex; logo + nombre en barra.

No requiere migración SQL obligatoria (estatus retirado = 9 ya documentado en migrate si aplica).

Verificar: public/verificar_despliegue_version.php y landing-spa.php (Ctrl+F5).
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
