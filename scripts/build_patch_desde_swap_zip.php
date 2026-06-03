<?php

declare(strict_types=1);

/**
 * ZIP parcial: cambios desde Swap + Cuadrícula y mejoras posteriores.
 * Uso: php scripts/build_patch_desde_swap_zip.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_desde_swap_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    'config/deploy_build.php',
    'public/verificar_despliegue_version.php',
    // Entrada sin sidebar (cuadrícula / cronómetro / hojas)
    'public/index.php',
    'public/includes/layout.php',
    'public/assets/css/fvd-identidad.css',
    // Flash → SweetAlert2
    'lib/FvdFlashSwal.php',
    'public/assets/fvd-flash-swal.js',
    'public/includes/fvd_flash_swal_footer.php',
    'public/includes/layout.php',
    'public/includes/admin_torneo_layout.php',
    'public/panel_torneo.php',
    'public/assets/dashboard-init.js',
    // Cuadrícula + panel torneo
    'modules/torneo_gestion.php',
    'lib/CuadriculaFilasHelper.php',
    'modules/gestion_torneos/cuadricula.php',
    'modules/gestion_torneos/panel-moderno.php',
    'modules/gestion_torneos/panel_equipos.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'lib/Tournament/Handlers/TournamentStatusHandler.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'modules/gestion_torneos/registrar-resultados-v2.php',
    'modules/gestion_torneos/registrar-resultados-v2.php',
    'public/assets/fvd-flash-swal.js',
    'resources/views/tournament/partials/grid_display.php',
    'public/assets/css/custom-13inch.css',
    'public/assets/css/torneo-context-switch.css',
    'public/assets/css/modern-panel.css',
    'public/assets/css/fvd-panel-institutional.css',
    'public/assets/css/panel-control-14in.css',
    'public/includes/views/dashboard/_fvd_kpi_compact.php',
    'public/includes/views/dashboard/_atletas_stat_cards.php',
    'public/includes/views/dashboard/_fvd_panel_badge.php',
    'public/includes/views/dashboard/_fvd_panel_operativo_render.php',
    'public/includes/views/dashboard/_fvd_support_credit.php',
    'public/assets/css/fvd-tokens.css',
    'modules/admin_general/views/_panel_operativo.php',
    'modules/admin_general/views/home.php',
    'modules/asociacion_panel.php',
    // Swap / reemplazo (op_especiales)
    'lib/Tournament/OpEspecialesHelper.php',
    'modules/op_especiales.php',
    'modules/op_especiales/_panel_ubicacion.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/NumfvdHelper.php',
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'lib/Core/MesaAsignacionService.php',
    'lib/Core/TorneoMesaAsignacionResolver.php',
    // QR jugador (numfvd / joins)
    'public/torneo_qr_jugador.php',
    'public/diag_qr_jugador.php',
    'lib/PublicInfoTorneoMesasService.php',
    'lib/PublicTorneoPortalHelper.php',
    'lib/TorneoJugadorQrToken.php',
    'lib/TorneoQrJugadorMesaPartial.php',
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

$build = 'desconocido';
$deployFile = $root . '/config/deploy_build.php';
if (is_file($deployFile)) {
    $c = file_get_contents($deployFile);
    if (preg_match("/FVD_DEPLOY_BUILD',\s*'([^']+)'/", $c, $m)) {
        $build = $m[1];
    }
}

$readme = <<<TXT
MISTORNEOS FVD — Parche (desde Swap + acumulado)
================================================
Build: {$build}

Extraer en la raíz del proyecto (public_html/mistorneos_fvd/).

SWAP Y REEMPLAZO (Op. Especiales)
---------------------------------
- Ubicar atletas → confirmar intercambio/reemplazo (numfvd + id_usuario).
- Observaciones en partiresul y auditoría.

CUADRÍCULA
----------
- Pantalla sin menú lateral del dashboard; márgenes 5% a cada lado.
- Selector «Filas» en cabecera: 15 a 22 (default 20); altura auto por fila.
- Selector de torneo centrado; tras «Generar Ronda» abre la cuadrícula.

MENSAJES FLASH
--------------
- Éxito/error de operaciones (generar ronda, etc.) con SweetAlert2.

PANEL / DASHBOARD KPI Y PANEL OPERATIVO
---------------------------------------
- Panel torneo y dashboard FVD: panel-top-strip + panel-badge-med.
- Estadísticas actuales: Inactivos/Activos por género.
- Panel operativo admin y asociación: tw-panel / tw-column (mismo esquema que gestión torneos).
- Logo La Estación al pie (tamaño ampliado).
- Panel torneo: títulos pastel +60%, botones en degradado por columna.

QR JUGADOR
----------
- Correcciones numfvd / joins en producción.

REGISTRAR RESULTADOS (RÁPIDO)
-----------------------------
- Sin SweetAlert «Operación exitosa» al guardar mesa.
- Tras guardar: abre la siguiente mesa y enfoca el buscador (#input_ir_mesa).
- No recalcula estadísticas globales en cada guardado (usar Actualizar estadísticas en el panel).
- Notificaciones/cierre en segundo plano (shutdown).

VERIFICACIÓN
------------
public/verificar_despliegue_version.php

.env (si aplica):
FVD_PARTIRESUL_SOLO_NUMFVD=1

Limpiar OPcache en cPanel tras subir.
TXT;

$zip->addFromString('LEEME_PARCHE_DESDE_SWAP.txt', $readme);
++$added;

$zip->close();

if ($missing !== []) {
    fwrite(STDERR, "Advertencia: archivos no encontrados:\n  - " . implode("\n  - ", $missing) . "\n");
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "El ZIP no se generó.\n");
    exit(1);
}

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "ZIP parche desde swap:\n  {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";

exit($missing !== [] ? 2 : 0);
