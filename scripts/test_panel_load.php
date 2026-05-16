<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$_GET['page'] = 'asociacion_panel';
$page = 'asociacion_panel';

try {
    ob_start();
    include __DIR__ . '/../modules/asociacion_panel.php';
    $out = ob_get_clean();
    echo 'OK len=' . strlen($out) . "\n";
} catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
}
