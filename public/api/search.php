<?php
/**
 * API legacy de búsqueda global del panel.
 * Delega en AppSearchService (mínimo 3 caracteres).
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/AppSearchService.php';

header('Content-Type: application/json; charset=utf-8');

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado', 'results' => []]);
    exit;
}

$search_term = trim((string) ($_GET['q'] ?? ''));

if (!AppSearchService::isQueryActive($search_term)) {
    echo json_encode([
        'results' => [],
        'total' => 0,
        'query' => $search_term,
        'active' => false,
        'min_chars' => AppSearchService::MIN_QUERY_LENGTH,
    ]);
    exit;
}

try {
    $results = AppSearchService::globalSearch(DB::pdo(), $search_term, 12);
    echo json_encode([
        'results' => $results,
        'total' => count($results),
        'query' => $search_term,
        'active' => true,
        'min_chars' => AppSearchService::MIN_QUERY_LENGTH,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('search.php: ' . $e->getMessage());
    echo json_encode([
        'results' => [],
        'total' => 0,
        'query' => $search_term,
        'error' => 'Error en la búsqueda',
    ]);
}
