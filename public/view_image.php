<?php
/**
 * Visor centralizado de imágenes.
 * Sirve archivos de imagen de forma segura desde upload/ y evita rutas rotas.
 * Usar siempre la función central image_url() o AppHelpers::imageUrl() para enlazar.
 */

$path = $_GET['path'] ?? '';

// Solo permitir rutas bajo upload/ (logos, fotos, etc.) y sin directory traversal
$path = str_replace(['../', '..\\'], '', $path);
$path = str_replace('\\', '/', ltrim($path, '/\\'));
if (strpos($path, 'public/') === 0) {
    $path = substr($path, 7);
}

// upload/ y uploads/ (raíz del proyecto): logos y adjuntos locales (WAMP: …\mistorneos\upload y …\uploads)
$allowed_prefixes = ['upload/', 'uploads/', 'lib/Assets/'];
$allowed = false;
foreach ($allowed_prefixes as $prefix) {
    if (strpos($path, $prefix) === 0) {
        $allowed = true;
        break;
    }
}

if (!$allowed || $path === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Imagen no encontrada';
    exit;
}

$base_dir = dirname(__DIR__);
$full_path = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

// Asegurar que la ruta resuelta sigue dentro de base_dir
$real_base = realpath($base_dir);
$real_path = realpath($full_path);
if ($real_path === false || $real_base === false || strpos($real_path, $real_base) !== 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Imagen no encontrada';
    exit;
}

if (!is_file($real_path)) {
    // Fallback: logo principal; si no existe en lib/Assets, usar favicon de public/
    if ($path === 'lib/Assets/mislogos/logo4.png') {
        $fallback = __DIR__ . '/favicon.png';
        if (is_file($fallback)) {
            $real_path = realpath($fallback);
        }
    }
    if (!is_file($real_path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Imagen no encontrada';
        exit;
    }
}

$ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real_path));
header('Cache-Control: public, max-age=86400');
readfile($real_path);
exit;
