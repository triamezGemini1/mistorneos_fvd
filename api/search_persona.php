<?php
/**
 * API: Buscar persona por nacionalidad + cédula
 * Busca primero en registrants, luego en base externa
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Api/JsonResponse.php';

use Lib\Api\JsonResponse;

// Validar parámetros
if (!isset($_GET['cedula']) || empty($_GET['cedula'])) {
    JsonResponse::validationError(['cedula' => 'Cédula requerida']);
}

$cedula = trim($_GET['cedula']);
$nacionalidad = isset($_GET['nacionalidad']) ? trim($_GET['nacionalidad']) : 'V';

// Validar nacionalidad
if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'])) {
    $nacionalidad = 'V';
}

try {
    // 1. Buscar en la tabla registrants
    $stmt = DB::pdo()->prepare("
        SELECT nombre, sexo, fechnac, celular 
        FROM inscripciones 
        WHERE cedula = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$cedula]);
    $persona = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($persona) {
        JsonResponse::success([
            'encontrado' => true,
            'fuente' => 'local',
            'persona' => [
                'nombre' => $persona['nombre'],
                'sexo' => $persona['sexo'],
                'fechnac' => $persona['fechnac'],
                'celular' => $persona['celular']
            ]
        ]);
    }
    
    // 2. Buscar en base de datos externa (solo si está configurada; conexión bajo demanda)
    if (file_exists(__DIR__ . '/../config/persona_database.php')) {
        require_once __DIR__ . '/../config/persona_database.php';

        if (PersonaDatabase::isConfigured()) {
            try {
                $persona = PersonaDatabase::buscarPorCedula($nacionalidad, $cedula);
                if ($persona !== null) {
                    JsonResponse::success([
                        'encontrado' => true,
                        'fuente' => 'externa',
                        'persona' => [
                            'nombre' => $persona['nombre'] ?? '',
                            'sexo' => $persona['sexo'] ?? '',
                            'fechnac' => $persona['fechnac'] ?? '',
                            'celular' => $persona['celular'] ?? '',
                        ],
                    ]);
                }
            } catch (Exception $e) {
                if (Env::bool('APP_DEBUG')) {
                    error_log('search_persona.php - Error en PersonaDatabase: ' . $e->getMessage());
                }
            }
        }
    }
    
    // 3. No encontrado
    JsonResponse::success([
        'encontrado' => false,
        'mensaje' => "Persona no encontrada con {$nacionalidad}{$cedula}"
    ]);
    
} catch (Exception $e) {
    if (Env::bool('APP_DEBUG')) {
        JsonResponse::serverError('Error interno: ' . $e->getMessage());
    } else {
        JsonResponse::serverError();
    }
}
