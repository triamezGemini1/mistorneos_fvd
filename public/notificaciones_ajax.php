<?php
/**
 * Endpoint para la campanita: devuelve el número de notificaciones web pendientes del usuario.
 * Con ?format=json devuelve también la última notificación pendiente (para toast/Push).
 * Sesión debe iniciarse antes de cualquier salida (evitar BOM/espacios antes de <?php).
 */
require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/TournamentAppScope.php';
AuthService::requireAuth();

function notif_wants_json() {
    return (isset($_GET['format']) && $_GET['format'] === 'json')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

$user = $_SESSION['user'] ?? null;
$uid = $user ? Auth::id() : 0;
if ($uid <= 0) {
    if (!headers_sent()) {
        if (notif_wants_json()) {
            header('Content-Type: application/json; charset=UTF-8');
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
        }
    }
    echo notif_wants_json() ? json_encode(['count' => 0, 'latest' => null]) : '0';
    exit;
}

$pdo = DB::pdo();
$sqlFiltro = TournamentAppScope::sqlExcluirNotificacionesFvd('nq');
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications_queue nq WHERE nq.usuario_id = ? AND nq.canal = 'web' AND nq.estado = 'pendiente'{$sqlFiltro}"
);
$stmt->execute([$uid]);
$count = (int) $stmt->fetchColumn();

if (notif_wants_json()) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    $latest = null;
    if ($count > 0) {
        $hasDatosJson = true;
        $stmtLatest = $pdo->prepare("
            SELECT id, mensaje, url_destino, datos_json
            FROM notifications_queue nq
            WHERE nq.usuario_id = ? AND nq.canal = 'web' AND nq.estado = 'pendiente'{$sqlFiltro}
            ORDER BY nq.fecha_creacion DESC
            LIMIT 1
        ");
        $stmtLatest->execute([$uid]);
        $row = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $titulo = 'Nueva notificación';
            $mensaje = $row['mensaje'] ?? '';
            $datosEstructurados = null;
            if ($hasDatosJson && !empty($row['datos_json'])) {
                $datosEstructurados = @json_decode($row['datos_json'], true);
            }
            if (!$datosEstructurados) {
                if (mb_strlen($mensaje) > 80) {
                    $titulo = mb_substr($mensaje, 0, 50) . '…';
                } elseif (preg_match('/^(.{1,50})(?:\s|$)/u', $mensaje, $m)) {
                    $titulo = trim($m[1]);
                }
            }
            $latest = [
                'id' => (int) $row['id'],
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'url_destino' => $row['url_destino'] ?? '#',
                'datos_estructurados' => $datosEstructurados,
            ];
        }
    }
    echo json_encode(['count' => $count, 'latest' => $latest]);
} else {
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo $count;
}
