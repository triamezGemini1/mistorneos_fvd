<?php

declare(strict_types=1);

/**
 * ZIP producción completo: torneo + UTF-8 + Access + asociación/afiliación + reportes.
 * Uso: php scripts/build_patch_produccion_completo_mayo2026.php
 */

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$timestamp = date('Y-m-d_His');
$zipName = "mistorneos_fvd_patch_produccion_{$timestamp}.zip";
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;

$files = [
    // Versión / bootstrap
    'config/deploy_build.php',
    'config/bootstrap.php',
    'config/utf8.php',
    'config/session_install.php',
    'config/auth.php',
    'public/verificar_despliegue_version.php',
    // UTF-8
    'lib/FvdUtf8.php',
    'scripts/repair_utf8_texts.php',
    'scripts/fix_null_coalescing_bullet.php',
    'scripts/fix_bullet_in_words.php',
    // Export Access
    'lib/AccessExportService.php',
    'scripts/export_access_excel.php',
    'modules/gestion_torneos/export_access_excel.php',
    'modules/gestion_torneos/export_access_portal.php',
    // Panel torneo + landing
    'modules/gestion_torneos/panel-moderno.php',
    'modules/gestion_torneos/reporte_estructura_mesas.php',
    'modules/gestion_torneos/reporte_parejas_repetidas.php',
    'modules/gestion_torneos/resultados_por_asociacion.php',
    'modules/gestion_torneos/_reporte_estructura_jugador.php',
    'modules/torneo_gestion.php',
    'public/index.php',
    'modules/tournament_admin/resultados_reportes.php',
    'modules/op_especiales.php',
    'modules/op_especiales/_panel_ubicacion.php',
    'lib/TorneoOrganizacionHelper.php',
    'lib/FvdBranding.php',
    'lib/app_helpers.php',
    'lib/security.php',
    'lib/Core/MesaAsignacion/MesaAsignacionAlgorithm.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLimiteClubMesaTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLinealClasificacionTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionClubInterclubTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionQueueTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionConflictos2Trait.php',
    'lib/Tournament/Handlers/TournamentDataHandler.php',
    'lib/Tournament/Handlers/TournamentStatusHandler.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'lib/Tournament/Handlers/TeamPerformanceHandler.php',
    'lib/Tournament/OpEspecialesHelper.php',
    'public/assets/img/logo-fvd.png',
    'public/assets/vendor/img/logofvd.png',
    'public/assets/vendor/img/logoled.png',
    'public/torneo_detalle.php',
    'public/panel_torneo.php',
    'public/api/landing_data.php',
    'public/landing-spa.php',
    'public/assets/landing-spa.js',
    'public/includes/layout.php',
    'public/includes/admin_torneo_layout.php',
    'public/includes/landing_static_shell.php',
    'lib/DashboardData.php',
    'modules/admin_dashboard.php',
    'modules/admin_general/actions/home.php',
    'modules/admin_general/views/home.php',
    'public/includes/views/dashboard/home.php',
    'public/includes/views/dashboard/_fvd_kpi_compact.php',
    'public/includes/views/dashboard/_atletas_stat_cards.php',
    'public/includes/views/dashboard/_fvd_torneos_table.php',
    'public/includes/views/dashboard/_fvd_dashboard_acceso_torneos.php',
    'public/includes/views/dashboard/_fvd_support_credit.php',
    'public/includes/partials/asoc_torneo_header.php',
    'public/assets/css/fvd-identidad.css',
    'public/assets/css/fvd-panel-institutional.css',
    'public/assets/css/fvd-tokens.css',
    'public/assets/css/reporte-estructura-mesas.css',
    'public/assets/css/asociacion-panel-operativo.css',
    // Motor mesas + partiresul
    'lib/InscritosHelper.php',
    'lib/InscritosPartiresulHelper.php',
    'lib/TorneoCampoNumerico.php',
    'lib/SancionesHelper.php',
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'lib/PartiresulEstatusSql.php',
    'lib/PartiresulJugadorHelper.php',
    'lib/PartiresulAsignacionWriter.php',
    'lib/MesaEstructuraReporteService.php',
    'lib/CargaAutomaticaResultadosRondaService.php',
    'lib/CuadriculaFilasHelper.php',
    'lib/HistorialParejasService.php',
    'lib/ReporteParejasRepetidasService.php',
    'lib/TorneoIntegridadService.php',
    'lib/TorneoInscripcionesResetService.php',
    'lib/ResumenJugadorNavigation.php',
    'lib/NumfvdHelper.php',
    'lib/TorneoJugadorQrToken.php',
    'public/diag_qr_jugador.php',
    'public/torneo_qr_jugador.php',
    'docs/PROCEDIMIENTO_ASIGNACION_RONDAS_Y_EVALUACION.md',
    'docs/CARGA_AUTOMATICA_RESULTADOS_RONDA.md',
    'scripts/carga_automatica_resultados_ronda.php',
    'scripts/migrate_partiresul_numfvd.php',
    'scripts/sync_posi_rnk_desde_atletas.php',
    'scripts/auditar_torneo_integridad.php',
    // Inscripción en sitio (clubes.id = código asociación)
    'modules/gestion_torneos/inscribir-sitio.php',
    'public/api/search_persona.php',
    'lib/BusquedaJugadorInscripcionService.php',
    'lib/Tournament/Handlers/RegistrationHandler.php',
    'api/tournament_admin_toggle_inscripcion.php',
    'public/tournament_admin_toggle_inscripcion.php',
    'public/assets/registrants-inscripciones.js',
    'lib/InscripcionPagoService.php',
    'public/api/inscripcion_admin.php',
    'modules/registrants.php',
    'modules/registrants/_fila_listado_inscrito.php',
    'modules/registrants/export_excel_inscritos.php',
    'modules/users/list.php',
    'modules/users.php',
    // Asociación / afiliación FVD
    'modules/asociacion_panel.php',
    'modules/asociacion/afiliar_atleta.php',
    'modules/asociacion/informes.php',
    'modules/asociacion/solicitud.php',
    'modules/asociacion/torneo_ver.php',
    'modules/asociacion/reportes/_bootstrap.php',
    'modules/asociacion/reportes/afiliaciones.php',
    'modules/asociacion/reportes/carnets.php',
    'modules/asociacion/reportes/traspasos.php',
    'modules/solicitudes_asociacion.php',
    'modules/solicitudes_asociacion/list.php',
    'lib/FvdAfiliacionAtletaService.php',
    'lib/FvdDelegadoMovimientoService.php',
    'lib/FvdDelegadoReporteService.php',
    'lib/FvdInformeAsociacionService.php',
    'lib/FvdSupervisionMovimientoService.php',
    'lib/FvdMovimientoTorneoHelper.php',
    'lib/FvdFlashSwal.php',
    'lib/SolicitudesAsociacionService.php',
    'lib/LandingInscripcionPublicaHelper.php',
    'lib/LandingInscripcionCredencialesHelper.php',
    'lib/LandingDocumentosMeta.php',
    'lib/AsociacionAdminHelper.php',
    'lib/ClubHelper.php',
    'lib/ImportacionMasivaService.php',
    'lib/OrganizacionesData.php',
    'public/api/fvd_afiliacion_check_cedula.php',
    'public/api/fvd_solicitar_carnet.php',
    'public/api/fvd_solicitar_traspaso.php',
    'public/assets/css/fvd-afiliacion-forms.css',
    'public/assets/fvd-flash-swal.js',
    'public/assets/inscripcion-publica-busqueda.js',
    'public/includes/fvd_flash_swal_footer.php',
    'public/includes/inscripcion_tarjeta_publica.php',
    'public/inscribir_evento_masivo.php',
    // SQL opcional post-despliegue
    'sql/add_asignacion_por_posicion_tournaments.sql',
    'sql/add_mesa_historial_parejas.sql',
    'sql/add_numfvd_partiresul.sql',
    'sql/add_numfvd_defaults.sql',
    'sql/add_usuarios_imagenes_afiliacion.sql',
    'sql/sync_posi_rnk_desde_atletas_categ.sql',
];

$excludeRelPatterns = [
    '#^public/upload/#',
    '#^scripts/test_#',
    '#^scripts/diag_#',
    '#^scripts/_#',
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
$seen = [];

$addFile = static function (ZipArchive $zip, string $root, string $rel) use (&$added, &$missing, &$seen, $excludeRelPatterns): void {
    $rel = str_replace('\\', '/', $rel);
    if (isset($seen[$rel])) {
        return;
    }
    foreach ($excludeRelPatterns as $pat) {
        if (preg_match($pat, $rel)) {
            return;
        }
    }
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        $missing[] = $rel;
        return;
    }
    $zip->addFile($full, $rel);
    $seen[$rel] = true;
    ++$added;
};

foreach ($files as $rel) {
    $addFile($zip, $root, $rel);
}

$readme = <<<'TXT'
MISTORNEOS FVD — Parche producción completo (junio 2026)
========================================================

BUILD: 2026-06-01-inscripcion-sitio-club-id

Extraer en la raíz del proyecto (misma carpeta que public/).

VERIFICAR
---------
public/verificar_despliegue_version.php

INCLUYE
-------
• UTF-8 global (config/utf8.php, lib/FvdUtf8.php)
• Export Excel para Microsoft Access (inscritos + partidas; columna asociacion = código club)
• Inscripción en sitio: resuelve club por clubes.id, preselecciona asociación, cambiar club inscritos
• Panel torneo, motor mesas greedy, carga automática resultados
• Módulo asociación: afiliación, carnets, traspasos, solicitudes
• APIs públicas afiliación (cédula, carnet, traspaso)
• Inscripciones en línea / landing / flash SweetAlert
• Reintegro retirado → confirmado (estatus 1)

EXPORT ACCESS
-------------
  php scripts/export_access_excel.php --torneo_id=1
  index.php?page=torneo_gestion&action=export_access_excel&torneo_id=1

SQL OPCIONAL (ejecutar si la columna/tabla no existe)
-----------------------------------------------------
  sql/add_numfvd_partiresul.sql
  sql/add_usuarios_imagenes_afiliacion.sql
  sql/add_mesa_historial_parejas.sql
  sql/sync_posi_rnk_desde_atletas_categ.sql

POST-DESPLIEGUE
---------------
1. php scripts/migrate_partiresul_numfvd.php (si aplica)
2. Regenerar rondas si cambió el motor de mesas
3. Borrar verificar_despliegue_version.php cuando confirme OK

TXT;

$zip->addFromString('LEEME_PARCHE.txt', $readme);
$zip->close();

$sizeKb = is_file($zipPath) ? round(filesize($zipPath) / 1024, 1) : 0;
echo "ZIP: {$zipPath}\n";
echo "Archivos: {$added}\n";
echo "Tamaño: {$sizeKb} KB\n";
if ($missing !== []) {
    echo "Faltantes (" . count($missing) . "):\n- " . implode("\n- ", $missing) . "\n";
    exit(1);
}
exit(0);
