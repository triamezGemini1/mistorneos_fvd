<?php

declare(strict_types=1);

/**
 * Hotfix: resumen_jugador público (acceso + layout + solo stats generales).
 * Uso: php scripts/build_hotfix_resumen_jugador.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipPath = $distDir . DIRECTORY_SEPARATOR . "mistorneos_fvd_hotfix_resumen_jugador_{$timestamp}.zip";

$files = [
    'public/resumen_jugador.php',
    'config/deploy_build.php',
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

foreach ($files as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        fwrite(STDERR, "Falta: {$rel}\n");
        exit(1);
    }
    $zip->addFile($full, $rel);
}

$zip->addFromString('LEEME_HOTFIX_RESUMEN_JUGADOR.txt', <<<'TXT'
HOTFIX — resumen_jugador.php público
====================================

Extraer en: public_html/mistorneos_fvd/

CORRECCIONES
------------
- Fatal error por declare(strict_types) mal ubicado (página en blanco)
- Filtro de inscrito alineado con evento_resultados (no solo estatus confirmado)
- Acceso al torneo vía TournamentScopeHelper (publicar_landing)
- Layout centrado con cabecera (estilo evento_resultados)
- Solo estadísticas generales; sin detalle partida a partida

VERIFICAR
---------
https://laestaciondeldominohoy.com/mistorneos_fvd/public/resumen_jugador.php?torneo_id=1&id_usuario=1241

Build: 2026-06-10-resumen-jugador-publico
TXT);

$zip->close();
echo "ZIP: {$zipPath}\n";
