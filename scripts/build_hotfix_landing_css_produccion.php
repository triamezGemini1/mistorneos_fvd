<?php

declare(strict_types=1);

/**
 * Parche urgente: CSS landing roto en producción (output.css 404).
 * Uso: php scripts/build_hotfix_landing_css_produccion.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_hotfix_landing_css_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

if (is_file($root . '/package.json')) {
    echo "Compilando CSS (npm run build:css)...\n";
    passthru('npm run build:css 2>&1', $code);
    if ($code !== 0) {
        fwrite(STDERR, "Error: npm run build:css falló.\n");
        exit(1);
    }
}

$files = [
    'public/landing-spa.php',
    'public/assets/css/landing-precompiled.css',
    'public/assets/css/fvd-landing-shell.css',
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
HOTFIX — CSS landing SPA roto
=============================

CAUSA: falta el CSS precompilado de la landing en el servidor.

EXTRAER EN: public_html/mistorneos_fvd/

ARCHIVOS
--------
- public/assets/css/landing-precompiled.css  (Tailwind precompilado)
- public/landing-spa.php
- public/assets/css/fvd-landing-shell.css

VERIFICAR (debe responder 200, tipo text/css):
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/assets/css/landing-precompiled.css

Luego recargar con Ctrl+F5:
  https://laestaciondeldominohoy.com/mistorneos_fvd/public/landing-spa.php
TXT;

$zip->addFromString('LEEME_HOTFIX_CSS.txt', $readme);
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
