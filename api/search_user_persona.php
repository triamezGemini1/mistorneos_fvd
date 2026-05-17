<?php
/**
 * API: Buscar persona por cédula o por usuario (nombre de usuario) para el módulo de usuarios.
 * Para registrar Admin Torneo: buscar en afiliados (usuarios del club) por cédula o usuario;
 * si existe → asignar rol; si no existe pero está en solicitudes_afiliacion → debe registrarse primero.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/AppSearchService.php';

header('Content-Type: application/json; charset=utf-8');

$cedula = trim($_GET['cedula'] ?? '');
$nacionalidad = trim($_GET['nacionalidad'] ?? 'V');
$buscar_por = trim($_GET['buscar_por'] ?? 'cedula'); // 'cedula' | 'usuario' | 'id'
$usuario = trim($_GET['usuario'] ?? '');
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;

if ($buscar_por === 'id') {
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID de usuario requerido']);
        exit;
    }
} elseif ($buscar_por === 'usuario') {
    if (!AppSearchService::isQueryActive($usuario)) {
        echo json_encode([
            'success' => false,
            'error' => 'Nombre de usuario requerido (mín. ' . AppSearchService::MIN_QUERY_LENGTH . ' caracteres)',
        ]);
        exit;
    }
} elseif (empty($cedula)) {
    echo json_encode(['success' => false, 'error' => 'Cédula requerida']);
    exit;
}

if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'])) {
    $nacionalidad = 'V';
}

try {
    $pdo = DB::pdo();

    // Búsqueda por ID de usuario
    if ($buscar_por === 'id') {
        $sql = "SELECT id, username, nombre, cedula, club_id, role, email, celular FROM usuarios WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            $userClubId = $existingUser['club_id'] ? (int)$existingUser['club_id'] : null;
            if ($club_id > 0 && $userClubId !== null && $userClubId !== $club_id) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'encontrado' => true,
                        'existe_usuario' => false,
                        'mensaje' => 'El usuario pertenece a otro club. Solo puede asignar como Admin Torneo u Operador a afiliados de su club.'
                    ]
                ]);
                exit;
            }
            echo json_encode([
                'success' => true,
                'data' => [
                    'encontrado' => true,
                    'existe_usuario' => true,
                    'usuario_existente' => [
                        'id' => (int)$existingUser['id'],
                        'username' => $existingUser['username'],
                        'nombre' => $existingUser['nombre'],
                        'cedula' => $existingUser['cedula'] ?? '',
                        'email' => $existingUser['email'] ?? '',
                        'celular' => $existingUser['celular'] ?? '',
                        'club_id' => $userClubId,
                        'role' => $existingUser['role'] ?? ''
                    ],
                    'mensaje' => 'Usuario encontrado en la plataforma. Puede asignarlo como Admin Torneo u Operador.'
                ]
            ]);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'encontrado' => false,
                'existe_usuario' => false,
                'mensaje' => 'No se encontró ningún usuario con el ID ' . $user_id . '.'
            ]
        ]);
        exit;
    }

    // Búsqueda por nombre de usuario (afiliado)
    if ($buscar_por === 'usuario') {
        $sql = "SELECT id, username, nombre, cedula, club_id, role FROM usuarios WHERE username = ?";
        $params = [$usuario];
        if ($club_id > 0) {
            $sql .= " AND (club_id = ? OR club_id IS NULL)";
            $params[] = $club_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existingUser) {
            $userClubId = $existingUser['club_id'] ? (int)$existingUser['club_id'] : null;
            if ($club_id > 0 && $userClubId !== null && $userClubId !== $club_id) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'encontrado' => true,
                        'existe_usuario' => false,
                        'mensaje' => 'El usuario pertenece a otro club. Solo puede asignar como Admin Torneo u Operador a afiliados de su club.'
                    ]
                ]);
                exit;
            }
            echo json_encode([
                'success' => true,
                'data' => [
                    'encontrado' => true,
                    'existe_usuario' => true,
                    'usuario_existente' => [
                        'id' => (int)$existingUser['id'],
                        'username' => $existingUser['username'],
                        'nombre' => $existingUser['nombre'],
                        'cedula' => $existingUser['cedula'] ?? '',
                        'club_id' => $userClubId,
                        'role' => $existingUser['role'] ?? ''
                    ],
                    'mensaje' => 'Usuario encontrado en la plataforma. Puede asignarlo como Admin Torneo.'
                ]
            ]);
            exit;
        }
        // Buscar en solicitudes de afiliación por username
        $stmt = $pdo->prepare("SELECT id, cedula, nombre, email, celular, fechnac, username, nacionalidad FROM solicitudes_afiliacion WHERE username = ? AND estatus IN ('pendiente', 'aprobada') LIMIT 1");
        $stmt->execute([$usuario]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($solicitud) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'encontrado' => true,
                    'existe_usuario' => false,
                    'en_solicitudes' => true,
                    'solicitud' => [
                        'id' => (int)$solicitud['id'],
                        'cedula' => $solicitud['cedula'],
                        'nombre' => $solicitud['nombre'],
                        'email' => $solicitud['email'] ?? '',
                        'celular' => $solicitud['celular'] ?? '',
                        'username' => $solicitud['username']
                    ],
                    'mensaje' => 'La persona figura en solicitudes de afiliación pero aún no está registrada en la plataforma. Debe registrarla primero y luego asignar el rol Admin Torneo.'
                ]
            ]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'encontrado' => false,
                'existe_usuario' => false,
                'mensaje' => 'No se encontró ningún usuario ni solicitud con ese nombre de usuario. La persona debe registrarse primero en la plataforma.'
            ]
        ]);
        exit;
    }

    // Búsqueda por cédula - normalizar para BD externa (IDUsuario sin prefijo V/E)
    $cedula_externa = preg_replace('/^[VEJP]/i', '', $cedula);
    $cedula_externa = $cedula_externa ?: $cedula;
    // 1. Verificar si ya existe un usuario con esa cédula (probar ambos formatos)
    $stmt = $pdo->prepare("SELECT id, username, nombre, cedula, club_id, role, email, celular, fechnac FROM usuarios WHERE cedula = ? OR cedula = ?");
    $stmt->execute([$cedula, $cedula_externa]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        $userClubId = $existingUser['club_id'] ? (int)$existingUser['club_id'] : null;
        if ($club_id > 0 && $userClubId !== null && $userClubId !== $club_id) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'encontrado' => true,
                    'existe_usuario' => false,
                    'mensaje' => 'El usuario pertenece a otro club. Solo puede asignar como Admin Torneo u Operador a afiliados de su club.'
                ]
            ]);
            exit;
        }
        $out = [
            'id' => (int)$existingUser['id'],
            'username' => $existingUser['username'],
            'nombre' => $existingUser['nombre'],
            'cedula' => $existingUser['cedula'] ?? '',
            'email' => $existingUser['email'] ?? '',
            'celular' => $existingUser['celular'] ?? '',
            'fechnac' => !empty($existingUser['fechnac']) ? $existingUser['fechnac'] : ''
        ];
        if ($club_id !== null) {
            $out['club_id'] = $userClubId;
            $out['role'] = $existingUser['role'] ?? '';
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'encontrado' => true,
                'existe_usuario' => true,
                'usuario_existente' => $out,
                'mensaje' => 'Usuario encontrado en la plataforma. Puede asignarlo como Admin Torneo.'
            ]
        ]);
        exit;
    }

    // 2. Buscar en solicitudes de afiliación (cedula) — no está en usuarios, debe registrarse primero
    $stmt = $pdo->prepare("SELECT id, cedula, nombre, email, celular, fechnac, username, nacionalidad FROM solicitudes_afiliacion WHERE (cedula = ? OR cedula = ?) AND estatus IN ('pendiente', 'aprobada') LIMIT 1");
    $stmt->execute([$cedula, $cedula_externa]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($solicitud) {
        echo json_encode([
            'success' => true,
            'data' => [
                'encontrado' => true,
                'existe_usuario' => false,
                'en_solicitudes' => true,
                'solicitud' => [
                    'id' => (int)$solicitud['id'],
                    'cedula' => $solicitud['cedula'],
                    'nombre' => $solicitud['nombre'],
                    'email' => $solicitud['email'] ?? '',
                    'celular' => $solicitud['celular'] ?? '',
                    'fechnac' => $solicitud['fechnac'] ?? '',
                    'username' => $solicitud['username']
                ],
                'persona' => [
                    'nombre' => $solicitud['nombre'],
                    'fechnac' => $solicitud['fechnac'] ?? '',
                    'celular' => $solicitud['celular'] ?? '',
                    'email' => $solicitud['email'] ?? ''
                ],
                'mensaje' => 'La persona figura en solicitudes de afiliación pero no está registrada en la plataforma. Debe registrarla primero y luego asignar el rol Admin Torneo.'
            ]
        ]);
        exit;
    }

    // 3. Buscar en la base de datos externa 'persona'
    if (file_exists(__DIR__ . '/../config/persona_database.php')) {
        require_once __DIR__ . '/../config/persona_database.php';
        
        try {
            $database = new PersonaDatabase();
            $nacPrimera = strtoupper($nacionalidad);
            $nacionalidadesIntento = [$nacPrimera];
            if ($nacPrimera === 'V') {
                $nacionalidadesIntento[] = 'E';
            }
            $nacionalidadesIntento = array_values(array_unique($nacionalidadesIntento));

            foreach ($nacionalidadesIntento as $nacTry) {
                $result = $database->searchPersonaById($nacTry, $cedula_externa);
                if (!isset($result['encontrado']) || !$result['encontrado'] || !isset($result['persona'])) {
                    continue;
                }
                $persona = $result['persona'];
                $cedulaNum = isset($persona['cedula']) ? preg_replace('/\D/', '', $persona['cedula']) : $cedula_externa;
                $nac = $persona['nacionalidad'] ?? $nacTry;
                if (!in_array(strtoupper($nac), ['V', 'E', 'J', 'P'], true)) {
                    $nac = $nacTry;
                }
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'encontrado' => true,
                        'existe_usuario' => false,
                        'persona' => [
                            'cedula' => $cedulaNum,
                            'nacionalidad' => strtoupper($nac),
                            'nombre' => $persona['nombre'] ?? '',
                            'fechnac' => $persona['fechnac'] ?? '',
                            'sexo' => $persona['sexo'] ?? '',
                            'celular' => $persona['celular'] ?? '',
                            'email' => $persona['email'] ?? ''
                        ]
                    ]
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log("search_user_persona.php - Error en PersonaDatabase: " . $e->getMessage());
        }
    }
    
    // 4. No encontrado en ninguna parte
    echo json_encode([
        'success' => true,
        'data' => [
            'encontrado' => false,
            'existe_usuario' => false,
            'mensaje' => 'Persona no encontrada. Debe registrarse primero en la plataforma (ingrese los datos manualmente).'
        ]
    ]);

} catch (Exception $e) {
    error_log("search_user_persona.php - Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
