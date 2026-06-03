<?php

declare(strict_types=1);

/**
 * Parche producción: R2 por zonas (ganadores / media / perdedores) + reporte estructura mesas.
 * Uso: php scripts/build_patch_r2_zonas_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_r2_zonas_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/MesaEstructuraReporteService.php',
    'lib/InscritosHelper.php',
    'lib/PartiresulEstatusSql.php',
    'lib/GestionTorneosViewsData.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'modules/torneo_gestion.php',
    'modules/gestion_torneos/reporte_estructura_mesas.php',
    'modules/gestion_torneos/_reporte_estructura_jugador.php',
    'modules/gestion_torneos/panel-moderno.php',
    'modules/gestion_torneos/rondas.php',
    'public/assets/css/reporte-estructura-mesas.css',
    'public/includes/layout.php',
    'docs/PROCEDIMIENTO_ASIGNACION_RONDAS_Y_EVALUACION.md',
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
MISTORNEOS FVD — Parche R2 por zonas + reporte mesas (mayo 2026)
================================================================

Extraer en la raíz del proyecto (ej. public_html/mistorneos_fvd/).

CAMBIO PRINCIPAL — Segunda ronda
--------------------------------
lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php

- Zona ALTA: solo ganadores R1, patrón 1-5-3-7 (mejores primero).
- Zona MEDIA: peores ganadores R1 + mejores perdedores R1 (transición).
- Zona BAJA: resto de perdedores R1, patrón 1-5-3-7.
- Ya no se mezclan G y P en mesas altas salvo en la zona media.

REPORTE DE VERIFICACIÓN
-----------------------
action=reporte_estructura_mesas&torneo_id=ID

- Muestra todas las rondas, procedimiento y marcas P×n / vs×n.
- En R2 etiqueta Zona alta / media / baja por mesa.
- Panel y Rondas: enlace «Estructura de mesas (reporte)».

OTROS (si no estaban desplegados)
---------------------------------
- Correcciones estatus inscritos, hojas anotación, sanciones (ver archivos en ZIP).

POST-DESPLIEGUE
---------------
1. public/verificar_despliegue_version.php — build esperado.
2. Torneos con R2 ya generada: eliminar R2 y regenerar para aplicar zonas.
3. Reporte estructura mesas para validar zonas en R2.

TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
$zip->close();

echo "ZIP: {$zipPath}\n";
echo "Archivos: {$added}\n";
if ($missing !== []) {
    echo "Faltantes:\n- " . implode("\n- ", $missing) . "\n";
    exit(1);
}

exit(0);
