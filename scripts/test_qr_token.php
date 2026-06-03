<?php
declare(strict_types=1);
/**
 * Prueba local del token QR: php scripts/test_qr_token.php [token]
 */
$token = $argv[1] ?? 'MjoxMjA.fqWjjYpf3cQdZg';
$_GET['t'] = $token;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REQUEST_URI'] = '/mistorneos_fvd/public/torneo_qr_jugador.php';
$_SERVER['SCRIPT_NAME'] = '/mistorneos_fvd/public/torneo_qr_jugador.php';
chdir(dirname(__DIR__) . '/public');
ob_start();
include 'torneo_qr_jugador.php';
$out = ob_get_clean();
echo 'HTTP=' . (http_response_code() ?: 0) . ' bytes=' . strlen($out) . PHP_EOL;
if (preg_match('/<title>([^<]+)</', $out, $m)) {
    echo 'title=' . trim($m[1]) . PHP_EOL;
}
if (preg_match('/<h1>([^<]+)</', $out, $m)) {
    echo 'h1=' . trim($m[1]) . PHP_EOL;
}
