<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/admin_general_auth.php';

requireAdminGeneral();
CSRF::validate();

try {
    // Validar ID
    if (empty($_POST['id'])) {
        throw new Exception('ID del club es requerido');
    }
    $id = (int)$_POST['id'];
    
    // Validar campos requeridos
    if (empty($_POST['nombre'])) {
        throw new Exception('El nombre del club es requerido');
    }
    
    // Verificar que el club existe
    $stmt = DB::pdo()->prepare("SELECT id, logo FROM clubes WHERE id = ?");
    $stmt->execute([$id]);
    $current_club = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_club) {
        throw new Exception('Club no encontrado');
    }
    
    $current_user = Auth::user();

    // Preparar datos
    $nombre = trim($_POST['nombre']);
    $direccion = trim($_POST['direccion'] ?? '');
    $delegado = trim($_POST['delegado'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $estatus = (int)($_POST['estatus'] ?? 1);
    $permite_inscripcion_linea = isset($_POST['permite_inscripcion_linea']) ? 1 : 0;
    $logo = $current_club['logo']; // Mantener logo actual por defecto
    
    // Manejar upload de nuevo logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowed)) {
            throw new Exception('Solo se permiten im�genes JPG, PNG o GIF');
        }
        
        if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
            throw new Exception('El logo no puede superar 5MB');
        }
        
        // Crear directorio si no existe
        $upload_dir = __DIR__ . '/../../upload/logos';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Eliminar logo anterior si existe
        if ($current_club['logo'] && file_exists(__DIR__ . '/../../' . $current_club['logo'])) {
            @unlink(__DIR__ . '/../../' . $current_club['logo']);
        }
        
        // Nombre único para el archivo
        $logo_name = 'logo_' . $id . '_' . time() . '.' . $extension;
        $logo_path = $upload_dir . '/' . $logo_name;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path)) {
            $logo = 'upload/logos/' . $logo_name;
        } else {
            throw new Exception('Error al subir el logo');
        }
    }
    
    // Actualizar en la base de datos (incluir permite_inscripcion_linea si existe)
    $upd = "nombre = :nombre, direccion = :direccion, delegado = :delegado, telefono = :telefono, email = :email, logo = :logo, estatus = :estatus";
    $params = [
        ':id' => $id,
        ':nombre' => $nombre,
        ':direccion' => $direccion,
        ':delegado' => $delegado,
        ':telefono' => $telefono,
        ':email' => $email ?: null,
        ':logo' => $logo,
        ':estatus' => $estatus
    ];
    try {
        $chk = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'permite_inscripcion_linea'");
        if ($chk && $chk->rowCount() > 0) {
            $upd .= ", permite_inscripcion_linea = :permite_inscripcion_linea";
            $params[':permite_inscripcion_linea'] = $permite_inscripcion_linea;
        }
    } catch (Exception $e) { /* columna no existe */ }
    $stmt = DB::pdo()->prepare("UPDATE clubes SET {$upd} WHERE id = :id");
    $result = $stmt->execute($params);
    
    if (!$result) {
        throw new Exception('Error al actualizar el club');
    }
    
    // Redirigir con éxito
    header('Location: index.php?page=clubs&success=' . urlencode('Club actualizado exitosamente'));
    exit;
    
} catch (Exception $e) {
    // Redirigir con error
    $id = isset($id) ? $id : ($_POST['id'] ?? 0);
    header('Location: index.php?page=clubs&action=edit&id=' . $id . '&error=' . urlencode($e->getMessage()));
    exit;
}

