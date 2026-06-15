<?php

declare(strict_types=1);

/**
 * Hotfix: redirects y URL_BASE unificados a mistorneos_fvd.
 * Uso: php scripts/build_hotfix_redirects_mistorneos_fvd.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_hotfix_redirects_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'index.php',
    'lib/app_helpers.php',
    'core/includes/header_meta.php',
    'config/auth_service.php',
    'config/bootstrap.php',
    'config/deploy_build.php',
    'public/login.php',
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
HOTFIX — Redirects unificados a mistorneos_fvd
==============================================

Extraer en: public_html/mistorneos_fvd/

ARCHIVOS
--------
- index.php                    → siempre redirige a /mistorneos_fvd/public/landing-spa.php
- lib/app_helpers.php          → canoniza rutas legacy (pruebas, mistorneos_beta, monorepo)
- core/includes/header_meta.php
- config/auth_service.php
- config/bootstrap.php
- public/login.php

NO SOBRESCRIBIR en servidor
--------------------------
- config/.env (revisar manualmente los valores abajo)
- config/config.production.php

REVISAR .env EN SERVIDOR
------------------------
APP_URL=https://laestaciondeldominohoy.com/mistorneos_fvd
BASE_PATH=/mistorneos_fvd/public/

VERIFICAR
---------
1) https://laestaciondeldominohoy.com/mistorneos_fvd/
   → debe ir a .../mistorneos_fvd/public/landing-spa.php

2) Login sin sesión en panel admin
   → URL de login debe contener /mistorneos_fvd/public/login.php

3) Tras login, dashboard en /mistorneos_fvd/public/index.php (no /pruebas/ ni /mistorneos_beta/)

Build: 2026-06-09-redirects-mistorneos-fvd
TXT;

$zip->addFromString('LEEME_HOTFIX_REDIRECTS.txt', $readme);
++$added;
$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Faltan:\n  - " . implode("\n  - ", $missing) . "\n");
    exit(1);
}

echo "ZIP hotfix:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo 'Tamaño: ' . round(filesize($zipPath) / 1024, 1) . " KB\n";

exit(0);
