<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_general', 'admin_club', 'admin_torneo']);

header('Content-Type: application/json');

$file_type = $_GET['type'] ?? 'invitation'; // invitation, norms, poster
$search = $_GET['search'] ?? '';

$allowed_types = ['invitation', 'norms', 'poster'];
if (!in_array($file_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo de archivo no válido']);
    exit;
}

$upload_dir = __DIR__ . '/../../upload/tournaments/' . $file_type . 's/';
$base_url = app_base_url() . '/upload/tournaments/' . $file_type . 's/';
$files_data = [];

if (is_dir($upload_dir)) {
    $files = array_diff(scandir($upload_dir), ['.', '..', 'README.md']);
    
    foreach ($files as $file) {
        // Filtrar por búsqueda si se especifica
        if (!empty($search) && stripos($file, $search) === false) {
            continue;
        }
        
        $file_path = $upload_dir . $file;
        if (is_file($file_path)) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $file_type_detected = 'other';
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                $file_type_detected = 'image';
            } elseif ($extension === 'pdf') {
                $file_type_detected = 'pdf';
            } elseif (in_array($extension, ['doc', 'docx'])) {
                $file_type_detected = 'document';
            }

            $files_data[] = [
                'name' => $file,
                'path' => 'upload/tournaments/' . $file_type . 's/' . $file,
                'url' => $base_url . $file,
                'size' => filesize($file_path),
                'type' => $file_type_detected,
                'modified' => filemtime($file_path),
                'extension' => $extension
            ];
        }
    }
}

// Ordenar por fecha de modificación (más reciente primero)
usort($files_data, function($a, $b) {
    return $b['modified'] - $a['modified'];
});

echo json_encode(['files' => $files_data]);
?>
