<?php

declare(strict_types=1);

/**
 * Envío masivo de credenciales (JSON).
 * POST: user_ids[]=1&user_ids[]=2&canal=web|telegram|whatsapp
 */

require_once __DIR__ . '/../../lib/UserAccessNotifier.php';

header('Content-Type: application/json; charset=utf-8');

$sessionUser = Auth::user();
if (!Auth::isAdminGeneral() && (!is_array($sessionUser) || ($sessionUser['role'] ?? '') !== 'admin_club')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$canalFromJson = '';
$rawIds = $_POST['user_ids'] ?? null;
if ($rawIds === null) {
    $body = file_get_contents('php://input');
    if (is_string($body) && $body !== '') {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $rawIds = $json['user_ids'] ?? [];
            $canalFromJson = (string) ($json['canal'] ?? '');
        }
    }
}

$userIds = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $id) {
        $userIds[] = (int) $id;
    }
} elseif (is_numeric($rawIds)) {
    $userIds[] = (int) $rawIds;
}

$canal = trim((string) ($_POST['canal'] ?? ($canalFromJson ?? '')));

if ($canal === '' || $userIds === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos (user_ids, canal)'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::pdo();
    $actor = Auth::user() ?: [];
    $result = UserAccessNotifier::dispatchBatch($pdo, $userIds, $canal, $actor);

    if (!$result['ok'] && ($result['succeeded'] ?? 0) === 0 && empty($result['whatsapp_queue'])) {
        http_response_code(422);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('send_access_notification_batch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno al procesar el lote'], JSON_UNESCAPED_UNICODE);
}
