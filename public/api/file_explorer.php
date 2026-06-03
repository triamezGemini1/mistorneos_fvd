<?php

session_start();

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/file_upload.php';

// Verificar autenticación
$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Obtener archivos disponibles
try {
    $files = FileUpload::getFileExplorerData();
    
    header('Content-Type: application/json');
    echo json_encode($files);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}























