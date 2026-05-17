<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/AppSearchService.php';

if (!Auth::user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$scope = strtolower(trim((string) ($_GET['scope'] ?? 'global')));
$q = trim((string) ($_GET['q'] ?? $_GET['query'] ?? ''));
$clubId = isset($_GET['club_id']) ? (int) $_GET['club_id'] : null;

$validation = AppSearchService::validateActiveQuery($q);
if (!$validation['ok']) {
    echo json_encode([
        'ok' => true,
        'active' => false,
        'min_chars' => AppSearchService::MIN_QUERY_LENGTH,
        'message' => $validation['message'],
        'results' => [],
        'items' => [],
    ]);
    exit;
}

try {
    $pdo = DB::pdo();
    $payload = [
        'ok' => true,
        'active' => true,
        'min_chars' => AppSearchService::MIN_QUERY_LENGTH,
        'query' => $q,
        'scope' => $scope,
    ];

    switch ($scope) {
        case 'usuarios':
            $items = AppSearchService::searchUsuarios($pdo, $q, 20, $clubId);
            $payload['items'] = $items;
            $payload['results'] = array_map(static function (array $u): array {
                return [
                    'id' => (int) ($u['id'] ?? 0),
                    'title' => (string) ($u['nombre'] ?? $u['username'] ?? ''),
                    'subtitle' => 'ID ' . (int) ($u['id'] ?? 0) . ' · ' . (string) ($u['cedula'] ?? ''),
                    'meta' => $u,
                ];
            }, $items);
            break;
        case 'global':
        default:
            $results = AppSearchService::globalSearch($pdo, $q, 12);
            $payload['results'] = $results;
            $payload['items'] = $results;
            break;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('app_search.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error en la búsqueda']);
}
