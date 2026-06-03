<?php

declare(strict_types=1);

/**
 * Configuración UTF-8 para toda la aplicación (PHP, mbstring, salida HTML por defecto).
 */
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
    mb_http_output('UTF-8');
    mb_regex_encoding('UTF-8');
    if (function_exists('mb_language')) {
        mb_language('uni');
    }
}

ini_set('default_charset', 'UTF-8');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isHtmlRequest = $accept === '' || stripos($accept, 'text/html') !== false;
    if ($isHtmlRequest && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: text/html; charset=UTF-8');
    }
}
