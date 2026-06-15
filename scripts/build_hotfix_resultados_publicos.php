<?php

declare(strict_types=1);

/**
 * Hotfix: resultados públicos (rendimiento, layout, sin detalle jugador).
 * Uso: php scripts/build_hotfix_resultados_publicos.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_hotfix_resultados_publicos_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'lib/ResultadosPublicHelper.php',
    'lib/ResultadosPublicCache.php',
    'public/evento_resultados.php',
    'public/clasificacion.php',
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
HOTFIX — Resultados públicos (completo)
=======================================

Extraer en: public_html/mistorneos_fvd/

ARCHIVOS
--------
- lib/ResultadosPublicHelper.php   (consultas rápidas, paginación SQL)
- lib/ResultadosPublicCache.php    (caché APCu + storage/cache, TTL 120s)
- public/evento_resultados.php     (layout fijo, paginador, sin enlace a detalle jugador)
- public/clasificacion.php           (sin enlace a detalle jugador)
- public/resumen_jugador.php         (solo stats generales si se accede por URL directa)
- config/deploy_build.php

REQUISITOS EN SERVIDOR
----------------------
- Permisos 755 en storage/cache/

VERIFICAR
---------
1) Landing → "Ver Resultados" → evento_resultados.php?torneo_id=ID
2) Nombres de jugadores en tabla: texto plano (no enlace azul)
3) Página centrada, tabla y paginador con formato
4) Clasificación móvil: nombres sin enlace

Caché: borrar storage/cache/resultados_public_v1_*.json si hace falta refrescar datos.

Build: 2026-06-10-resultados-publicos-sin-detalle-jugador
TXT;

$zip->addFromString('LEEME_HOTFIX_RESULTADOS.txt', $readme);
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
