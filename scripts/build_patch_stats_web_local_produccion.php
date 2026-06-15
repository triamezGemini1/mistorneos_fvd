<?php

declare(strict_types=1);

/**
 * Parche: estadísticas web locales (detalle diario + histórico mensual por URL).
 * Extraer en public_html/mistorneos_fvd/ (sin config/ ni .env).
 *
 * Uso: php scripts/build_patch_stats_web_local_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_stats_web_local_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'config/bootstrap.php',
    'public/verificar_despliegue_version.php',
    'modules/estadisticas_web.php',
    'modules/analytics_uso.php',
    'lib/Env.php',
    'lib/UmamiAnalyticsHelper.php',
    'lib/WebStatsService.php',
    'public/modules/cron_analytics.php',
    'public/includes/analytics-tracker.php',
    'sql/create_stats_web_analytics_tables.sql',
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

foreach (array_unique($files) as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($full, $rel);
    ++$added;
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche Estadísticas web locales (consolidación mensual por URL)
===============================================================================

EXTRAER EN: public_html/mistorneos_fvd/

INCLUYE
-------
- modules/estadisticas_web.php — panel con desglose por URL (mes actual / histórico)
- lib/WebStatsService.php — sync Umami → BD local + consultas del panel
- lib/UmamiAnalyticsHelper.php — API Umami + lectura UMAMI_SHARE_URL (aliases, .env raíz)
- lib/Env.php + config/bootstrap.php — carga .env y respaldo config/env.production.php
- public/modules/cron_analytics.php — cron diario + cierre mensual (día 1)
- sql/create_stats_web_analytics_tables.sql — tablas en mistorneos_fvd
- public/includes/analytics-tracker.php — script Umami en páginas públicas

PASO 1 — BASE DE DATOS (mistorneos_fvd)
---------------------------------------
Ejecutar en la BD principal mistorneos_fvd:

  sql/create_stats_web_analytics_tables.sql

PASO 2 — VARIABLES (.env en la RAÍZ del proyecto, NO config/env.production.php)
-------------------------------------------------------------------------------
UMAMI_WEBSITE_ID=tu_website_uuid
UMAMI_SCRIPT_URL="https://cloud.umami.is/analytics/US/websites/TU_WEBSITE_ID/mistorneos_fvd"
UMAMI_API_KEY=tu_clave_api_umami
ANALYTICS_CRON_KEY=una_clave_secreta_larga
# (alternativa iframe) UMAMI_SHARE_URL="https://cloud.umami.is/share/tu_token/mistorneos_fvd"
# Si UMAMI_SCRIPT_URL es ruta /analytics/…/websites/… se usa para el iframe;
# el tracker público sigue usando https://cloud.umami.is/script.js por defecto.

PASO 3 — CRON DIARIO (cPanel)
-----------------------------
0 2 * * * php /home/USUARIO/public_html/mistorneos_fvd/public/modules/cron_analytics.php

Alternativa HTTP:
curl "https://tudominio.com/mistorneos_fvd/public/modules/cron_analytics.php?key=TU_ANALYTICS_CRON_KEY"

El día 1 de cada mes el cron consolida el mes anterior en stats_historico_mensual_url
y limpia stats_detalle_diario del mes cerrado.

ACCESO PANEL
------------
index.php?page=estadisticas_web
Solo rol admin_general.

VERIFICAR
---------
1. verificar_despliegue_version.php muestra build: 2026-06-11-stats-web-umami-script-url-dashboard
2. Estadísticas web → iframe con UMAMI_SHARE_URL o UMAMI_SCRIPT_URL (/analytics/…/websites/…)
3. Estadísticas web → sección "Desglose por URL (base local)"
4. Tras el primer cron: datos del mes en curso desde stats_detalle_diario
5. Meses pasados: stats_historico_mensual_url
TXT;

$zip->addFromString('LEEME_PARCHE_STATS_WEB_LOCAL.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Faltan archivos:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP creado:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";
echo "\n>>> Extraer en: public_html/mistorneos_fvd/ <<<\n";

exit($missing !== [] ? 2 : 0);
