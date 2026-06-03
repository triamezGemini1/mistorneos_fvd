<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/FvdAfiliacionAtletaService.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';

if (!Auth::user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autenticado']);
    exit;
}

$cedula = trim((string) ($_GET['cedula'] ?? ''));
if ($cedula === '') {
    echo json_encode(['ok' => false, 'message' => 'Cédula requerida']);
    exit;
}

$pdo = DB::pdo();
$user = Auth::user();
$role = (string) ($user['role'] ?? '');
$club = AsociacionAdminHelper::clubOperativo($pdo, (int) Auth::id(), $role);
$esAdmin = Auth::isAdminGeneral();

$ver = FvdAfiliacionAtletaService::verificarAccesoConsultaCedula($pdo, $cedula, $club, $esAdmin);
if (!$ver['allowed']) {
    echo json_encode(['ok' => false, 'message' => $ver['message'] ?? 'Acceso denegado']);
    exit;
}

$u = $ver['user'] ?? null;
echo json_encode([
    'ok' => true,
    'exists' => $u !== null,
    'user' => $u,
], JSON_UNESCAPED_UNICODE);
