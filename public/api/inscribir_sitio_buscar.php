<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/InscribirSitioBusquedaService.php';

$input = array_merge($_GET, $_POST);
$torneoId = (int) ($input['torneo_id'] ?? 0);
$clubPost = (int) ($input['id_club'] ?? $input['club_id'] ?? 0);
$nacionalidad = isset($input['nacionalidad']) ? strtoupper(trim((string) $input['nacionalidad'])) : 'V';
if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
    $nacionalidad = 'V';
}

$raw = trim((string) ($input['busqueda'] ?? $input['cedula'] ?? ''));
$userIdParam = (int) ($input['user_id'] ?? 0);
$qNombre = trim((string) ($input['q'] ?? $input['nombre'] ?? ''));
$modo = trim((string) ($input['modo'] ?? ''));

if ($modo === 'disponibles') {
    if ($torneoId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Indique torneo']);
        exit;
    }
    Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
    if (!Auth::canAccessTournament($torneoId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permiso para este torneo']);
        exit;
    }
    $pdo = DB::pdo();
    $clubId = null;
    if ($clubPost > 0) {
        $clubId = AsociacionAdminHelper::clubFiltroInscripcionSitio($pdo, $clubPost) ?? $clubPost;
    }
    try {
        $out = InscribirSitioBusquedaService::listarDisponiblesPorClub($pdo, $torneoId, $clubId);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('inscribir_sitio_buscar disponibles: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al listar disponibles']);
    }
    exit;
}

$cedula = preg_replace('/^[VEJP]/i', '', $raw);
$cedula = preg_replace('/\D/', '', $cedula);
if ($qNombre === '' && $cedula === '' && $raw !== '') {
    $soloDig = preg_replace('/\D/', '', $raw);
    if ($soloDig === '') {
        $qNombre = $raw;
    }
}
if ($raw !== '' && preg_match('/^[0-9]{1,6}$/', $raw)) {
    $userIdParam = (int) $raw;
}

if ($torneoId <= 0 || $raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Indique torneo y texto de búsqueda']);
    exit;
}

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
if (!Auth::canAccessTournament($torneoId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permiso para este torneo']);
    exit;
}

$pdo = DB::pdo();
$clubId = null;
if ($clubPost > 0) {
    $clubId = AsociacionAdminHelper::clubFiltroInscripcionSitio($pdo, $clubPost) ?? $clubPost;
}

try {
    $out = InscribirSitioBusquedaService::buscar(
        $pdo,
        $torneoId,
        $clubId,
        $nacionalidad,
        $raw,
        $cedula,
        $userIdParam,
        $qNombre,
        Auth::id()
    );
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('inscribir_sitio_buscar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en la búsqueda']);
}
