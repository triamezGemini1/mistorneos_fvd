<?php

declare(strict_types=1);

/**
 * Parche landing SPA completa para producción (sin config/ ni .env).
 * Uso: php scripts/build_patch_landing_spa_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_landing_spa_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

if (is_file($root . '/package.json')) {
    echo "Compilando assets (npm run build:assets)...\n";
    passthru('npm run build:assets 2>&1', $npmCode);
    if ($npmCode !== 0) {
        fwrite(STDERR, "Advertencia: build:assets falló.\n");
    }
}

$files = [
    'index.php',
    'public/go_landing.php',
    'public/landing-spa.php',
    'public/api/landing_data.php',
    'public/assets/landing-spa.js',
    'public/assets/css/landing-precompiled.css',
    'public/assets/css/fvd-landing-shell.css',
    'public/assets/vendor/vue/vue.global.prod.js',
    'public/assets/vendor/fontawesome/css/all.min.css',
    'public/assets/vendor/img/logofvd.png',
    'public/assets/vendor/img/logoled.png',
    'public/assets/img/logo-fvd.png',
    'public/includes/landing_ranking_oficial_section.php',
    'public/includes/landing_podios_desglose_tabla.php',
    'public/includes/landing_static_shell.php',
    'public/asociacion_detalle.php',
    'public/ranking_atletas.php',
    'public/ranking_atletas_detalle.php',
    'public/ranking_atletas_detalle_pdf.php',
    'public/ranking_atletas_pdf.php',
    'public/includes/ranking_atletas_context.php',
    'lib/LandingDataService.php',
    'lib/PodiosAsociacionesLandingService.php',
    'lib/AsociacionesActivasLandingService.php',
    'lib/RankingCategoriaFvdHelper.php',
    'lib/RankingAtletasPublicoService.php',
    'lib/RankingAtletasPdfAccesoHelper.php',
    'lib/FvdConfig.php',
    'lib/app_helpers.php',
    'lib/UrlHelper.php',
    'lib/ClubHelper.php',
    'lib/InscritosHelper.php',
    'lib/CampeonatoTorneoHelper.php',
    'modules/ranking_numfvd/_encabezado_reporte.php',
    'modules/ranking_numfvd/_linea_nombre_atleta.php',
    'modules/ranking_numfvd/_badges_resumen_atleta.php',
    'modules/ranking_numfvd/_tabla_detalle_torneos.php',
    'storage/cache/.gitkeep',
];

$faWebfonts = glob($root . '/public/assets/vendor/fontawesome/webfonts/*') ?: [];
foreach ($faWebfonts as $wf) {
    if (is_file($wf)) {
        $files[] = 'public/assets/vendor/fontawesome/webfonts/' . basename($wf);
    }
}

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
MISTORNEOS FVD — Parche Landing SPA
===================================

EXTRAER EN: public_html/mistorneos_fvd/

NO incluye config/ ni .env (conservar los del servidor).

INCLUYE
-------
- landing-spa.php + API landing_data.php + Vue + CSS precompilado (landing-precompiled.css)
- Ranking oficial, podios asociaciones, directorio Asociaciones Activas, enlaces a ranking_atletas
- Redirección raíz index.php → public/landing-spa.php

PRODUCCIÓN (.env del servidor, verificar):
  URL_BASE=/mistorneos_fvd/public
  APP_URL=https://laestaciondeldominohoy.com/mistorneos_fvd

URLS
----
Landing:
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/landing-spa.php

Raíz (redirige a landing):
  https://laestaciondeldominohoy.com/mistorneos_fvd/

API (debe responder JSON):
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/landing_data.php

CSS (debe ser 200, no HTML):
  .../public/assets/css/landing-precompiled.css

CACHÉ: borrar storage/cache/landing_data_*.json si los datos no actualizan.
Permisos 755 en storage/cache/
TXT;

$zip->addFromString('LEEME_PARCHE_LANDING_SPA.txt', $readme);
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
