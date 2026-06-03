<?php
/**
 * Endpoint para buscar persona en base de datos persona
 * Acepta nacionalidad y cedula por separado
 */

session_start();

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/persona_database.php';

header('Content-Type: application/json');

try {
    $pdo = DB::pdo();
    
    $nacionalidad = trim($_GET['nacionalidad'] ?? 'V');
    $cedula = trim($_GET['cedula'] ?? '');
    
    // Limpiar cedula si viene con nacionalidad pegada
    $cedula = preg_replace('/^[VEJP]/i', '', $cedula);
    
    if (empty($cedula)) {
        echo json_encode([
            'encontrado' => false, 
            'error' => 'Debe proporcionar una cédula'
        ]);
        exit;
    }
    
    // Obtener torneo_id de la sesión
    $torneo_id = $_SESSION['torneo_id'] ?? null;
    $club_id = $_SESSION['club_id'] ?? null;
    
    if (!$torneo_id) {
        echo json_encode([
            'encontrado' => false,
            'error' => 'No se pudo identificar el torneo'
        ]);
        exit;
    }
    
    // 1. PRIMERO: Verificar si ya est• inscrito en ESTE torneo (clave única: cedula + torneo_id)
    $stmt = $pdo->prepare("
        SELECT id, nombre 
        FROM inscripciones 
        WHERE cedula = ? AND torneo_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cedula, $torneo_id]);
    $yaInscrito = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($yaInscrito) {
        echo json_encode([
            'encontrado' => false,
            'ya_inscrito' => true,
            'error' => '• El jugador con cédula ' . $nacionalidad . $cedula . ' ya est• inscrito en este torneo'
        ]);
        exit;
    }
    
    // 2. Si NO est• inscrito en este torneo, buscar datos en registrants (otros torneos)
    $stmt = $pdo->prepare("
        SELECT nombre, sexo, fechnac, celular 
        FROM inscripciones 
        WHERE cedula = ? 
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$cedula]);
    $local = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($local) {
        // Convertir sexo num�rico a letra si es necesario
        if ($local['sexo'] == '1' || strtoupper($local['sexo']) === 'MASCULINO') {
            $local['sexo'] = 'M';
        } elseif ($local['sexo'] == '2' || strtoupper($local['sexo']) === 'FEMENINO') {
            $local['sexo'] = 'F';
        }
        
        echo json_encode([
            'encontrado' => true,
            'fuente' => 'local',
            'persona' => $local
        ]);
        exit;
    }
    
    // 3. Si no está en local, buscar en BD externa (solo si está configurada)
    if (PersonaDatabase::isConfigured()) {
        $persona = PersonaDatabase::buscarPorCedula($nacionalidad, $cedula);
        if ($persona !== null) {
            echo json_encode([
                'encontrado' => true,
                'fuente' => 'externa',
                'persona' => $persona,
            ]);
            exit;
        }
    }

    echo json_encode([
        'encontrado' => false,
        'error' => 'No se encontró persona con esa cédula',
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'encontrado' => false,
        'error' => 'Error al buscar persona: ' . $e->getMessage()
    ]);
}
?>










