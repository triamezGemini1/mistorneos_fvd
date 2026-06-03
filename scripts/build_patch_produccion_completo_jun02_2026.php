<?php

declare(strict_types=1);

/**
 * ZIP parche PRODUCCIÓN COMPLETA — consolida todo el trabajo del 02-Jun-2026.
 * Uso: php scripts/build_patch_produccion_completo_jun02_2026.php
 *
 * Recomendado para subir a producción en un solo paso (extraer en raíz mistorneos_fvd/).
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_produccion_completo_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    // Arranque / BD / build
    'config/deploy_build.php',
    'config/bootstrap.php',
    'config/utf8.php',
    'config/db_config.php',
    'config/persona_database.php',
    // Helpers core
    'lib/app_helpers.php',
    'lib/IntegralUrl.php',
    'public/index.php',
    'deploy/mistorneos_fvd1_public/index.php',
    'lib/FvdBranding.php',
    'lib/DashboardData.php',
    'lib/NumfvdHelper.php',
    'lib/InscritosHelper.php',
    'lib/AsociacionAdminHelper.php',
    'lib/InscripcionPagoService.php',
    'lib/BusquedaJugadorInscripcionService.php',
    'lib/InscribirSitioBusquedaService.php',
    'lib/InscribirSitioDisponiblesService.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    // APIs
    'api/tournament_admin_toggle_inscripcion.php',
    'api/search_persona.php',
    'api/search_user_persona.php',
    'public/api/inscribir_sitio_buscar.php',
    'public/api/inscribir_sitio_disponibles.php',
    'public/api/inscripcion_admin.php',
    'public/api/search_persona.php',
    'public/api/search_user_persona.php',
    // Módulos
    'modules/torneo_gestion.php',
    'modules/gestion_torneos/inscribir-sitio.php',
    'modules/registrants.php',
    'modules/registrants/_fila_listado_inscrito.php',
    'modules/invitations/inscripciones/buscar_persona.php',
    'modules/admin_general/views/home.php',
    // Layout / dashboard
    'public/includes/layout.php',
    'public/includes/views/dashboard/home.php',
    'public/includes/views/dashboard/_fvd_dashboard_header.php',
    'public/includes/views/dashboard/_fvd_kpi_compact.php',
    'public/includes/views/dashboard/_fvd_torneos_home_section.php',
    'public/includes/views/dashboard/_fvd_torneos_table.php',
    'public/includes/views/dashboard/_fvd_torneos_org_helper.php',
    'public/includes/views/dashboard/_fvd_dashboard_acceso_torneos.php',
    // Assets
    'public/assets/css/fvd-tokens.css',
    'public/assets/css/fvd-dashboard-home-page.css',
    'public/assets/css/inscribir-sitio-page.css',
    'public/assets/registrants-inscripciones.js',
    'public/assets/vendor/img/logofvd.png',
    'public/assets/img/logo-fvd.png',
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

$build = '2026-06-02-bootstrap-utf8-fix';
if (is_file($root . '/config/deploy_build.php')) {
    require_once $root . '/config/deploy_build.php';
    $build = FVD_DEPLOY_BUILD;
}

$readme = <<<TXT
MISTORNEOS FVD — PARCHE PRODUCCIÓN COMPLETA (02-Jun-2026)
=========================================================

Extraer en la raíz del proyecto en el servidor:
  public_html/mistorneos_fvd/   (o mistorneos_fvd1/mistorneos_fvd/ en monorepo)

BUILD: {$build}

INCLUYE (TODO LO DE HOY)
------------------------
1) Arranque: bootstrap.php + utf8.php (sin fatal require)
2) BD personas lazy (no obligatoria al abrir la app)
3) Hotfix Numfvd + app_helpers detectPublicPathFromScript
4) Inscripción en sitio + desinscribir DELETE + retirados eliminar
5) Dashboard home (logo, KPIs, estilos corporativos)

VERIFICAR TRAS SUBIR
--------------------
- public/verificar_despliegue_version.php
- public/verificar_produccion.php
- index.php?page=home
- Inscripción en sitio + gestión torneos

NO SOBRESCRIBE
--------------
- .env (credenciales del servidor)
- upload/ (archivos subidos)
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
