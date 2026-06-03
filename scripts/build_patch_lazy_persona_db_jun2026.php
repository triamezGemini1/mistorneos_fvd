<?php

declare(strict_types=1);

/**
 * ZIP: BD personas bajo demanda (no obligatoria al abrir la app).
 * Uso: php scripts/build_patch_lazy_persona_db_jun2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_lazy_persona_db_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'config/db_config.php',
    'config/persona_database.php',
    'lib/BusquedaJugadorInscripcionService.php',
    'api/search_persona.php',
    'api/search_user_persona.php',
    'public/api/search_persona.php',
    'public/api/search_user_persona.php',
    'modules/invitations/inscripciones/buscar_persona.php',
    'public/verificar_produccion.php',
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
MISTORNEOS FVD — Parche BD personas bajo demanda
=================================================

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/).

QUÉ HACE
--------
- La app abre solo con la BD principal (mistorneos).
- La BD de personas (fvdadmin) se usa SOLO en búsquedas por cédula.
- Si no hay DB_SECONDARY_* en .env, no intenta conectar ni usa root por defecto.
- Inscripción en sitio y altas manuales siguen funcionando sin BD externa.

ARCHIVOS CLAVE
- config/persona_database.php — isConfigured(), buscarPorCedula()
- config/db_config.php — tryPdoSecondary(), sin fallback root
- lib/BusquedaJugadorInscripcionService.php — búsqueda externa lazy
- public/api/search_persona.php — bloque personas opcional

OPCIONAL EN .env (solo si quieren enriquecer búsquedas)
- DB_SECONDARY_HOST
- DB_SECONDARY_PORT
- DB_SECONDARY_DATABASE
- DB_SECONDARY_USERNAME
- DB_SECONDARY_PASSWORD

VERIFICAR
- Build: 2026-06-02-lazy-persona-db
- public/verificar_produccion.php → BD secundaria "opcional" en verde
- page=home carga sin error de personas
TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
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

exit($missing !== [] ? 2 : 0);
