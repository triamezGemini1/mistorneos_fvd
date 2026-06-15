<?php

declare(strict_types=1);

/**
 * Parche producción: public_html/mistorneos_fvd/
 * - Restaura CSS landing (output.css) y assets críticos
 * - Ranking resumido (Efect. Σ / Pts Σ, sin cédula ni total pts ranking)
 *
 * Uso: php scripts/build_patch_mistorneos_fvd_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_produccion_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

// Compilar Tailwind antes del ZIP
$packageJson = $root . '/package.json';
if (is_file($packageJson)) {
    echo "Compilando assets (npm run build:assets)...\n";
    passthru('npm run build:assets 2>&1', $npmCode);
    if ($npmCode !== 0) {
        fwrite(STDERR, "Advertencia: build:assets falló. Verifica npm.\n");
    }
}

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    // Landing (formato roto sin output.css)
    'public/landing-spa.php',
    'public/assets/css/landing-precompiled.css',
    'public/assets/css/fvd-landing-shell.css',
    'public/assets/landing-spa.js',
    'public/assets/vendor/vue/vue.global.prod.js',
    'public/assets/vendor/fontawesome/css/all.min.css',
    'public/assets/vendor/img/logofvd.png',
    // Ranking admin + detalle
    'lib/RankingNumfvdAdminService.php',
    'lib/app_helpers.php',
    'lib/IntegralUrl.php',
    'modules/ranking_numfvd_admin.php',
    'modules/ranking_numfvd_detalle.php',
    'modules/ranking_numfvd/_encabezado_reporte.php',
    'modules/ranking_numfvd/_linea_nombre_atleta.php',
    'modules/ranking_numfvd/_badges_resumen_atleta.php',
    'lib/RankingAtletasPublicoService.php',
    'modules/ranking_numfvd/_tabla_detalle_torneos.php',
    'public/ranking_numfvd_detalle_pdf.php',
    'public/includes/layout.php',
    'modules/admin_general/views/_panel_operativo.php',
    // Ranking público
    'public/ranking_atletas.php',
    'public/ranking_atletas_pdf.php',
    'scripts/recalcular_torneos_ranking_cli.php',
    'scripts/sync_inscritos_id_club_desde_usuario_entidad.php',
];

// Font Awesome webfonts (iconos landing)
$faWebfonts = glob($root . '/public/assets/vendor/fontawesome/webfonts/*') ?: [];
foreach ($faWebfonts as $wf) {
    if (is_file($wf)) {
        $rel = 'public/assets/vendor/fontawesome/webfonts/' . basename($wf);
        if (!in_array($rel, $files, true)) {
            $files[] = $rel;
        }
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
MISTORNEOS FVD — Parche producción urgente
==========================================

EXTRAER EN:
  public_html/mistorneos_fvd/

(debe quedar: mistorneos_fvd/public/assets/css/landing-precompiled.css, etc.)

CORRIGE
-------
1) Landing sin formato: restaura public/assets/css/landing-precompiled.css
2) Ranking resumido: Efect. Σ + Pts Σ, sin cédula, sin «Pts ranking Σ»
3) Detalle ranking NUMFVD + PDF

NO SOBRESCRIBIR (si ya existen y funcionan):
- .env
- config/config.production.php
- config/db_config.php

VERIFICAR
---------
Landing:
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/landing-spa.php

CSS (debe responder 200, no 404):
  .../mistorneos_fvd/public/assets/css/landing-precompiled.css

Ranking público:
  .../mistorneos_fvd/public/ranking_atletas.php?genero=F
  (columnas: Efect. Σ y Pts Σ — sin CI)

Admin (logueado):
  .../mistorneos_fvd/public/index.php?page=ranking_numfvd_admin

Build: ver public/verificar_despliegue_version.php
TXT;

$zip->addFromString('LEEME_PARCHE_MISTORNEOS_FVD.txt', $readme);
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
