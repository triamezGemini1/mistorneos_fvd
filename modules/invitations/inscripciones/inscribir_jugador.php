<?php
/**
 * Inscribir Jugador en Torneo
 * Ahora recibe todos los datos del formulario
 */

session_start();

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/db.php';

header('Content-Type: application/json');

try {
    $pdo = DB::pdo();
    
    // Obtener datos del formulario
    $cedula = trim($_POST['cedula'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $sexo = trim($_POST['sexo'] ?? '');
    $fechnac = trim($_POST['fechnac'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $categ = (int)($_POST['categ'] ?? 0);
    
    // Limpiar cédula: remover nacionalidad si viene pegada (V12345678 ? 12345678)
    $cedula = preg_replace('/^[VEJP]/i', '', $cedula);
    
    $torneo_id = $_SESSION['torneo_id'];
    $club_id = $_SESSION['club_id'];
    
    // Validaciones
    if (empty($cedula)) {
        echo json_encode(['success' => false, 'message' => 'Debe ingresar la cédula']);
        exit;
    }
    
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'Debe ingresar el nombre completo']);
        exit;
    }
    
    if (empty($sexo)) {
        echo json_encode(['success' => false, 'message' => 'Debe seleccionar el sexo']);
        exit;
    }
    
    // Fecha de nacimiento ya no es requerida (est• oculta)
    // if (empty($fechnac)) {
    //     echo json_encode(['success' => false, 'message' => 'Debe ingresar la fecha de nacimiento']);
    //     exit;
    // }
    
    // Verificar si ya est• inscrito en este torneo
    $stmt = $pdo->prepare("
        SELECT id FROM inscripciones 
        WHERE cedula = ? AND torneo_id = ? AND club_id = ?
    ");
    $stmt->execute([$cedula, $torneo_id, $club_id]);
    
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false, 
            'message' => 'El jugador ya est• inscrito en este torneo'
        ]);
        exit;
    }
    
    // Convertir nombre a may�sculas
    $nombre = strtoupper($nombre);
    
    // Convertir sexo de letra a número para la base de datos
    // Tabla registrants usa: 1 = Masculino, 2 = Femenino
    $sexoNumerico = 1; // Por defecto Masculino
    if (strtoupper($sexo) === 'F' || $sexo == '2') {
        $sexoNumerico = 2;
    } elseif (strtoupper($sexo) === 'M' || $sexo == '1') {
        $sexoNumerico = 1;
    }
    
    // Inscribir jugador con los datos del formulario
    $stmt = $pdo->prepare("
        INSERT INTO inscripciones 
        (cedula, nombre, sexo, fechnac, club_id, torneo_id, celular, identificador, estatus, categ)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    if ($stmt->execute([
        $cedula,
        $nombre,
        $sexoNumerico, // Usar valor num�rico
        $fechnac,
        $club_id,
        $torneo_id,
        $celular ?: null,
        0, // identificador por defecto
        1, // estatus activo
        $categ
    ])) {
        echo json_encode([
            'success' => true, 
            'message' => '? Jugador inscrito exitosamente: ' . htmlspecialchars($nombre)
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Error al inscribir jugador'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}

