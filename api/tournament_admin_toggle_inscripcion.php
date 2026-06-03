<?php
/**
 * API para inscribir/desinscribir jugadores en tiempo real
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/RegistrationHandler.php';

/**
 * @param array<string, mixed> $payload
 */
function toggle_inscripcion_responder(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    toggle_inscripcion_responder(['success' => false, 'error' => 'Método no permitido']);
}

$u = Auth::user();
if (!$u) {
    toggle_inscripcion_responder(['success' => false, 'error' => 'Debe iniciar sesión para realizar esta acción.']);
}

// Autorización por organización: quien puede inscribir es quien pertenece a la organización que gestiona el torneo (los clubes son solo informativos).
$torneo_id = (int)($_POST['torneo_id'] ?? 0);
$permiso = Auth::isAdminGeneral()
    || ($torneo_id > 0 && (
        Auth::canAccessTournament($torneo_id)
        || (($org_torneo = Auth::getTournamentOrganizacionId($torneo_id)) && Auth::userIsInOrganizacion($org_torneo))
    ));
if (!$permiso) {
    toggle_inscripcion_responder(['success' => false, 'error' => 'No autorizado para esta sección. Debe pertenecer a la organización que gestiona el torneo.']);
}

// Validar CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';
if (!$csrf_token || !$session_token || !hash_equals($session_token, $csrf_token)) {
    toggle_inscripcion_responder(['success' => false, 'error' => 'Token CSRF inválido']);
}

try {
    $pdo = DB::pdo();
    $action = $_POST['action'] ?? ''; // 'inscribir', 'desinscribir', 'registrar_inscribir'
    $id_usuario = (int)($_POST['id_usuario'] ?? 0);
    $id_club = !empty($_POST['id_club']) ? (int)$_POST['id_club'] : null;
    if (Auth::isOperativoSoloAsociacion()) {
        require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
        $clubForzado = AsociacionAdminHelper::idClubForzadoInscripcion($pdo);
        if ($clubForzado !== null) {
            $id_club = $clubForzado;
        }
    }
    // Inscripción en sitio: pendiente de pago (0). Pagado=1, retirado=4.
    $estatus = InscritosHelper::ESTATUS_PENDIENTE_NUM;

    // Registrar nuevo usuario e inscribir (registro manual / persona externa).
    if ($action === 'registrar_inscribir') {
        if ($torneo_id <= 0) {
            toggle_inscripcion_responder(['success' => false, 'error' => 'Torneo inválido']);
        }
        if (!Auth::canAccessTournament($torneo_id)) {
            toggle_inscripcion_responder(['success' => false, 'error' => 'Sin permiso para este torneo']);
        }
        $out = \Tournament\Handlers\RegistrationHandler::apiRegistrarEInscribir($pdo, $torneo_id, $_POST, Auth::id());
        toggle_inscripcion_responder($out);
    }

    if ($torneo_id <= 0 || $id_usuario <= 0) {
        toggle_inscripcion_responder(['success' => false, 'error' => 'Parámetros inválidos']);
    }
    
    // Verificar acceso al torneo
    if (!Auth::canAccessTournament($torneo_id)) {
        toggle_inscripcion_responder(['success' => false, 'error' => 'No tiene permisos para acceder a este torneo']);
    }
    
    $current_user = Auth::user();
    $user_club_id = $current_user['club_id'] ?? null;
    
    if ($action === 'inscribir') {
        $out = \Tournament\Handlers\RegistrationHandler::apiInscribirUsuarioExistente(
            $pdo,
            $torneo_id,
            $id_usuario,
            $id_club,
            $estatus,
            Auth::id(),
            $user_club_id !== null ? (int) $user_club_id : null
        );
        toggle_inscripcion_responder($out);
    } elseif ($action === 'desinscribir') {
        $out = \Tournament\Handlers\RegistrationHandler::apiDesinscribirUsuario($pdo, $torneo_id, $id_usuario);
        toggle_inscripcion_responder($out);
    } elseif ($action === 'cambiar_club') {
        $nuevoClub = (int) ($_POST['id_club'] ?? 0);
        if ($torneo_id <= 0 || $id_usuario <= 0) {
            toggle_inscripcion_responder(['success' => false, 'error' => 'Parámetros inválidos']);
        }
        if (!Auth::canAccessTournament($torneo_id)) {
            toggle_inscripcion_responder(['success' => false, 'error' => 'No tiene permisos para acceder a este torneo']);
        }
        $out = \Tournament\Handlers\RegistrationHandler::apiCambiarClubInscrito(
            $pdo,
            $torneo_id,
            $id_usuario,
            $nuevoClub,
            Auth::id()
        );
        toggle_inscripcion_responder($out);
    } else {
        toggle_inscripcion_responder(['success' => false, 'error' => 'Acción inválida']);
    }
    
} catch (Throwable $e) {
    error_log('Error en toggle_inscripcion: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    toggle_inscripcion_responder(['success' => false, 'error' => 'Error al procesar la inscripción. ' . $e->getMessage()]);
}

