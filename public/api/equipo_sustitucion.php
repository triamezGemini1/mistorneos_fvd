<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/session_start_early.php';
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/EquipoSustitucionService.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireRoleJson(['admin_general', 'admin_torneo', 'admin_club']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$torneoId = (int) ($_POST['torneo_id'] ?? 0);
$codigoEquipo = trim((string) ($_POST['codigo_equipo'] ?? ''));

if ($torneoId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Torneo inválido.']);
    exit;
}

$pdo = DB::pdo();

try {
    if ($action === 'listar_plantilla') {
        if ($codigoEquipo === '') {
            throw new RuntimeException('Código de equipo requerido.');
        }
        $plantilla = EquipoSustitucionService::listarPlantillaEquipo($pdo, $torneoId, $codigoEquipo);
        echo json_encode(['success' => true, 'plantilla' => $plantilla]);
        exit;
    }

    if ($action === 'sustituir') {
        $idSale = (int) ($_POST['id_usuario_sale'] ?? 0);
        $idEntra = (int) ($_POST['id_usuario_entra'] ?? 0);
        $obs = trim((string) ($_POST['observacion'] ?? ''));
        if ($codigoEquipo === '' || $idSale <= 0 || $idEntra <= 0) {
            throw new RuntimeException('Complete equipo, titular que sale y suplente que entra.');
        }
        $res = EquipoSustitucionService::sustituir(
            $pdo,
            $torneoId,
            $codigoEquipo,
            $idSale,
            $idEntra,
            (int) (Auth::id() ?: 0),
            $obs !== '' ? $obs : null
        );
        echo json_encode([
            'success' => !empty($res['ok']),
            'message' => $res['message'] ?? '',
            'detalle' => $res['detalle'] ?? null,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
