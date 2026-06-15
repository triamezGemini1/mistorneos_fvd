<?php
/**
 * Visor y descarga de documentos oficiales (PDF, etc.) desde upload/documentos_oficiales/
 * Uso: view_documento.php?path=nombre.pdf (consulta en línea) o ?path=nombre.pdf&download=1 (descarga)
 * Base path: siempre el directorio padre de public/ (raíz del proyecto).
 */

declare(strict_types=1);

$path = $_GET['path'] ?? '';
$download = isset($_GET['download']) && $_GET['download'] !== '0';

$path = str_replace(['../', '..\\'], '', $path);
$path = ltrim($path, '/\\');

$allowed_prefixes = ['upload/documentos_oficiales/', 'upload/invitaciones_fvd/'];
$allowed = false;
foreach ($allowed_prefixes as $prefix) {
    if (strpos($path, $prefix) === 0) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    if (strpos($path, 'upload/') !== 0) {
        $path = 'upload/documentos_oficiales/' . ltrim($path, '/');
    }
    $allowed = false;
    foreach ($allowed_prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            $allowed = true;
            break;
        }
    }
}
if (!$allowed || $path === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Documento no encontrado';
    exit;
}

if (strpos($path, 'upload/invitaciones_fvd/') === 0) {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/InvitacionesFvdWebService.php';
    try {
        $pdoDoc = DB::pdo();
        if (!InvitacionesFvdWebService::puedeServirPublico($pdoDoc, $path)) {
            http_response_code(410);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Esta invitación ya no está disponible (vigencia vencida)';
            exit;
        }
    } catch (Throwable $e) {
        error_log('view_documento invitaciones: ' . $e->getMessage());
    }
}

// Raíz del proyecto: directorio padre de public/ (este script está en public/)
$base_dir = dirname(__DIR__);
$real_base = realpath($base_dir);
if ($real_base === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error de configuración';
    exit;
}

$path_normalized = str_replace('/', DIRECTORY_SEPARATOR, $path);
$full_path = $real_base . DIRECTORY_SEPARATOR . $path_normalized;
$real_path = is_file($full_path) ? realpath($full_path) : false;

// Si no existe con la ruta exacta, buscar en el mismo directorio (encoding/corrupción Ó/Í → __, etc.)
if ($real_path === false && is_dir(dirname($full_path))) {
    $requested_basename = basename($path);
    $requested_ext = strtolower(pathinfo($requested_basename, PATHINFO_EXTENSION));
    $dir_real = realpath(dirname($full_path));
    if ($dir_real && strpos($dir_real, $real_base) === 0) {
        $normalize = function (string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            $s = preg_replace('/[_\s\-]+/', '_', $s);
            $trans = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u'];
            $s = strtr($s, $trans);
            return preg_replace('/_+/', '_', $s);
        };
        $requested_stem = pathinfo($requested_basename, PATHINFO_FILENAME);
        $requested_normalized = $normalize($requested_stem);
        $prefix_before_corruption = null;
        if (preg_match('/^(.+?)__/', $requested_stem, $m)) {
            $prefix_before_corruption = $normalize($m[1]);
        }
        $candidates = [];
        foreach (new DirectoryIterator($dir_real) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            if (strtolower($f->getExtension()) !== $requested_ext) continue;
            $file_stem = pathinfo($f->getFilename(), PATHINFO_FILENAME);
            $file_normalized = $normalize($file_stem);
            if ($file_normalized === $requested_normalized) {
                $real_path = $f->getRealPath();
                break;
            }
            if ($prefix_before_corruption !== null && $prefix_before_corruption !== '' && strpos($file_normalized, $prefix_before_corruption) === 0) {
                $candidates[] = $f->getRealPath();
            }
        }
        if ($real_path === false && count($candidates) === 1) {
            $real_path = $candidates[0];
        }
    }
}

if ($real_path === false || strpos($real_path, $real_base) !== 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Documento no encontrado';
    exit;
}

$ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
$mimes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

$filename = basename($real_path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real_path));
header('Cache-Control: public, max-age=3600');
if ($download) {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $filename) . '"');
} else {
    header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $filename) . '"');
}
readfile($real_path);
exit;
