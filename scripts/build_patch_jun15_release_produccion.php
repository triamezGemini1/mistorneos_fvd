<?php

declare(strict_types=1);

/**
 * Parche de producción — release 15-jun-2026 (desde 29fd752).
 * Uso: php scripts/build_patch_jun15_release_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_jun15_release_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$excludePatterns = [
    '#^\.env#',
    '#^package\.json$#',
    '#^scripts/build_#',
    '#^scripts/build_hotfix_#',
    '#^storage/cache/.+\.json$#',
];

$collectGitFiles = static function (string $cmd) use ($root): array {
    $out = [];
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return [];
    }
    return array_map(static fn ($l) => trim(str_replace('\\', '/', $l)), $out);
};

$gitOut = array_merge(
    $collectGitFiles('git -C ' . escapeshellarg($root) . ' diff --name-only 29fd752..HEAD 2>&1'),
    $collectGitFiles('git -C ' . escapeshellarg($root) . ' diff --name-only HEAD 2>&1'),
    $collectGitFiles('git -C ' . escapeshellarg($root) . ' diff --name-only --cached HEAD 2>&1'),
    $collectGitFiles('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>&1')
);

if ($gitOut === []) {
    fwrite(STDERR, "No se encontraron archivos para el parche.\n");
    exit(1);
}

$files = [];
foreach ($gitOut as $rel) {
    $rel = trim(str_replace('\\', '/', $rel));
    if ($rel === '') {
        continue;
    }
    $skip = false;
    foreach ($excludePatterns as $pat) {
        if (preg_match($pat, $rel)) {
            $skip = true;
            break;
        }
    }
    if (!$skip) {
        $files[] = $rel;
    }
}
$files = array_values(array_unique($files));
sort($files);

// Assets compilados requeridos en producción
if (is_file($root . '/package.json')) {
    echo "Compilando assets (npm run build:assets)...\n";
    passthru('npm run build:assets 2>&1', $npmCode);
    if ($npmCode !== 0) {
        fwrite(STDERR, "Advertencia: build:assets falló. Revisa node/npm.\n");
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

$stripBomFor = [
    'public/perfil_jugador.php',
    'public/api_perfil_jugador.php',
    'public/profile_save.php',
    'public/profile.php',
];

$added = 0;
$missing = [];

foreach ($files as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        continue;
    }
    if (in_array($rel, $stripBomFor, true)) {
        $content = (string) file_get_contents($full);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        $zip->addFromString($rel, $content);
    } else {
        $zip->addFile($full, $rel);
    }
    ++$added;
}

$build = 'desconocido';
$deployFile = $root . '/config/deploy_build.php';
if (is_file($deployFile) && preg_match("/FVD_DEPLOY_BUILD',\s*'([^']+)'/", (string) file_get_contents($deployFile), $m)) {
    $build = $m[1];
}

$readme = <<<TXT
MISTORNEOS FVD — Parche release 15-jun-2026
===========================================
Build: {$build}

EXTRAER EN: public_html/mistorneos_fvd/

INCLUYE (resumen)
-----------------
- Control de acceso por rol: admin_general/superadmin/operador en torneos
- Operadores ámbito nacional: asignar desde panel del torneo, mesas a cualquier asociación
- Cambiar atleta (Op Especiales): intercambio/reemplazo por NUMFVD, layout dual compacto
- Admin Torneo y Operadores: filtro «Todas las asociaciones» para admin general
- Menú superior horizontal (sin sidebar), ancho 90%
- Landing SPA, asociaciones activas, podios y ranking oficial
- Ranking NUMFVD, estadísticas web (Umami), analytics
- Galería de fotos por torneo, detalle público de torneo
- Invitaciones FVD con fecha límite de vigencia
- Importación inscritos desde movimiento_torneo
- Reportes personales / donación reportes, foto de perfil
- Clasificación móvil, perfil jugador, resultados públicos

SQL (ejecutar en phpMyAdmin si no están aplicados)
---------------------------------------------------
- sql/create_archivos_web_invitaciones.sql
- sql/create_stats_web_analytics_tables.sql
- sql/create_reportes_pago_donacion.sql
- sql/migrate_usuarios_permite_reportes_personales.sql

PASOS
-----
1. Backup del sitio y base de datos
2. Extraer ZIP en public_html/mistorneos_fvd/
3. Ejecutar SQL pendientes
4. Permisos 755: upload/, uploads/, storage/cache/, logs/
5. Limpiar OPcache en cPanel
6. Verificar: public/verificar_despliegue_version.php

NO incluye: .env, config/ del servidor (conservar los existentes), upload/uploads.
TXT;

$zip->addFromString('LEEME_PARCHE_JUN15_RELEASE.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Advertencia: archivos no encontrados:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeMb = round(filesize($zipPath) / 1024 / 1024, 2);
echo "ZIP parche release jun15:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeMb} MB\n";
echo "\nSube y extrae en cPanel → public_html/mistorneos_fvd/\n";

exit($missing !== [] ? 2 : 0);
