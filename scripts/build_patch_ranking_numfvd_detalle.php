<?php

declare(strict_types=1);

/**
 * Parche: ranking NUMFVD — listado resumido, detalle web, PDF, badges (Efect. Σ / Pts Σ).
 * Uso: php scripts/build_patch_ranking_numfvd_detalle.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_patch_ranking_numfvd_detalle_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/RankingNumfvdAdminService.php',
    'lib/app_helpers.php',
    'lib/IntegralUrl.php',
    'modules/ranking_numfvd_admin.php',
    'modules/ranking_numfvd_detalle.php',
    'modules/ranking_numfvd/_encabezado_reporte.php',
    'modules/ranking_numfvd/_badges_resumen_atleta.php',
    'modules/ranking_numfvd/_tabla_detalle_torneos.php',
    'public/ranking_numfvd_detalle_pdf.php',
    'public/includes/layout.php',
    'modules/admin_general/views/_panel_operativo.php',
    'public/assets/vendor/img/logofvd.png',
    'public/ranking_atletas.php',
    'public/ranking_atletas_pdf.php',
    'scripts/recalcular_torneos_ranking_cli.php',
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
MISTORNEOS — Parche ranking NUMFVD (detalle + PDF)
==================================================

Extraer en la RAÍZ del proyecto en producción.

Ruta en producción FVD:
  public_html/mistorneos_fvd/

(debe quedar: mistorneos/modules/ranking_numfvd_detalle.php, etc.)

INCLUYE
-------
- Detalle web: logo FVD en panel blanco, subtítulo con año, badges prefijo/valor
- PDF personalizado con logo embebido y mismo diseño
- Servicio RankingNumfvdAdminService (subtituloRankingNacional + año lectivo)
- Menú admin y enlace panel operativo
- Logo: public/assets/vendor/img/logofvd.png

NO SOBRESCRIBIR en servidor (si existen):
- .env
- config/config.production.php
- config/db_config.php

VERIFICAR (logueado como admin_general):
  .../mistorneos_fvd/public/index.php?page=ranking_numfvd_admin
  .../mistorneos_fvd/public/index.php?page=ranking_numfvd_detalle&genero=F&numfvd=XXXX

Marcador build en:
  .../mistorneos/public/verificar_despliegue_version.php
  (buscar: getBrandLogoDataUri y rnk-stat-pill)
TXT;

$zip->addFromString('LEEME_PARCHE_RANKING.txt', $readme);
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
echo "\nExtraer en: public_html/mistorneos_fvd/\n";

exit($missing !== [] ? 2 : 0);
