<?php

declare(strict_types=1);

/**
 * ZIP parche: corrección rutas URL (mistorneos_fvd1 → detección SCRIPT / standalone).
 * Uso: php scripts/build_patch_url_base_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipPath = $distDir . DIRECTORY_SEPARATOR . "mistorneos_fvd_patch_url_base_{$timestamp}.zip";

$files = [
    'config/deploy_build.php',
    'config/bootstrap.php',
    'config/env.production.example',
    'lib/app_helpers.php',
    'lib/IntegralUrl.php',
    'public/index.php',
    'public/config.php',
    'public/components/header.php',
    'public/evento_resultados.php',
    'public/verificar_produccion.php',
    'robots.txt',
    'scripts/audit_hardcoded_urls.php',
    'scripts/fix_standalone_public_urls.php',
    'config/auth.php',
    'lib/ResumenJugadorNavigation.php',
    'lib/InvitationPDFGenerator.php',
    'modules/torneo_gestion.php',
    'modules/tournaments.php',
    'modules/cuentas_bancarias.php',
    'modules/finances.php',
    'modules/reportes_pago_usuarios.php',
    'modules/notificaciones_masivas/send.php',
    'modules/notificaciones_masivas/list.php',
    'modules/public_portal.php',
    'modules/affiliate_requests/list.php',
    'modules/affiliate_requests/send_whatsapp.php',
    'modules/player_invitations/send_whatsapp.php',
    'modules/tournament_admin/generar_qr.php',
    'modules/tournament_admin/generar_qr_general.php',
    'modules/gestion_torneos/resumen-individual.php',
    'modules/gestion_torneos/posiciones.php',
    'resources/views/tournament/parts/resumen-individual.php',
    'resources/views/tournament/parts/posiciones.php',
    'public/tournament_register.php',
    'public/inscribir_evento_masivo.php',
    'public/register_by_club.php',
    'public/user_portal.php',
    'public/torneo_detalle.php',
    'public/resultados_detalle.php',
    'public/resultados.php',
    'public/includes/credential_qr_widget.php',
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

$zip->addFromString('LEEME_PARCHE.txt', <<<'TXT'
MISTORNEOS FVD — Parche rutas URL (standalone)
==============================================

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/).

PROBLEMA
--------
Enlaces a /mistorneos_fvd1/public/… cuando la app está en /mistorneos_fvd/public/

CAUSA HABITUAL
--------------
.env con BASE_PATH o APP_URL del monorepo, o INTEGRAL_WEB_ROOT=mistorneos_fvd1

CORRECCIÓN EN CÓDIGO
--------------------
- URL_BASE: si BASE_PATH .env ≠ SCRIPT_NAME, gana la ruta real del script
- APP_URL se alinea con URL_BASE
- IntegralUrl solo activo en monorepo real (no por .env suelto)

REVISAR EN SERVIDOR (.env)
--------------------------
APP_URL=https://laestaciondeldominohoy.com/mistorneos_fvd
BASE_PATH=/mistorneos_fvd/public/
# NO definir INTEGRAL_WEB_ROOT

VERIFICAR
---------
Build: 2026-06-02-standalone-urls
URL: public/verificar_produccion.php
Auditoría local: php scripts/audit_hardcoded_urls.php
TXT);
++$added;

$zip->close();

echo "ZIP: {$zipPath}\nArchivos: {$added}\n";
exit($missing !== [] ? 2 : 0);
