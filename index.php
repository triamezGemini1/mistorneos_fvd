<?php
/**
 * Punto de entrada raíz.
 * Redirige al portal público (landing SPA) bajo mistorneos_fvd.
 * Ej.: http://localhost/mistorneos_fvd/ → /mistorneos_fvd/public/landing-spa.php
 */
require_once __DIR__ . '/lib/FvdConfig.php';

$target = rtrim(FvdConfig::BASE_PATH, '/') . '/landing-spa.php';
if (!headers_sent()) {
    header('Location: ' . $target, true, 302);
}
exit;
