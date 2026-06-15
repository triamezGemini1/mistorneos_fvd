<?php
/**
 * Action: Dashboard Home para Admin General
 * Solo tarjetas de estadísticas. Datos vía OrganizacionesData (Entidades, Orgs, Clubes, Usuarios por género).
 */

require_once __DIR__ . '/../../../config/admin_general_auth.php';
requireAdminGeneral();

require_once __DIR__ . '/../../../lib/OrganizacionesData.php';
require_once __DIR__ . '/../../../lib/ActasPendientesHelper.php';
require_once __DIR__ . '/../../../lib/FvdConfig.php';
require_once __DIR__ . '/../../../lib/FvdAdminGate.php';

$current_user = Auth::user();
$torneosSolo = FvdAdminGate::isRestricted();

if ($torneosSolo) {
    $stats = [];
    $panel_badges = [];
} else {
    $stats = OrganizacionesData::loadStatsGlobales();
    $panel_badges = OrganizacionesData::loadAdminGeneralPanelBadges();
}
$actas_pendientes = ActasPendientesHelper::contar();
$actas_ultimo_envio = ActasPendientesHelper::ultimoEnvio();

extract([
    'stats' => $stats,
    'actas_pendientes' => $actas_pendientes,
    'actas_ultimo_envio' => $actas_ultimo_envio,
    'panel_badges' => $panel_badges,
    'success_message' => $_GET['success'] ?? null,
    'error_message' => $_GET['error'] ?? null,
    'user_role' => 'admin_general',
    'current_user' => $current_user,
    'fvd_org_id' => (int) FvdConfig::ORGANIZACION_ID,
    'torneos_solo' => $torneosSolo,
]);

include __DIR__ . '/../views/home.php';
