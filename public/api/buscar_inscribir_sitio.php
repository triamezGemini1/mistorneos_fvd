<?php
/**
 * API: Búsqueda definitiva para inscripción en sitio (una sola petición).
 *
 * NIVEL 1: Validación en tabla inscritos (nacionalidad + cedula + torneo_id). Si existe → ya_inscrito (abortar).
 * NIVEL 2: Búsqueda en usuarios. Si existe → autocompletar y permitir inscripción.
 * NIVEL 3: Base de datos externa. Si existe → completar y foco Teléfono.
 * NIVEL 4: No encontrado → registro manual.
 *
 * Parámetros: torneo_id, nacionalidad, cedula (solo número).
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/BusquedaJugadorInscripcionService.php';
require_once __DIR__ . '/../../lib/FvdAdminGate.php';

FvdAdminGate::rejectApiIfDisabled();

header('Content-Type: application/json; charset=utf-8');

$torneo_id = (int)($_GET['torneo_id'] ?? 0);
$nacionalidad = strtoupper(trim($_GET['nacionalidad'] ?? 'V'));
$cedula = trim($_GET['cedula'] ?? '');

if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
    $nacionalidad = 'V';
}
$cedula = preg_replace('/^[VEJP]/i', '', $cedula);
$cedula = preg_replace('/\D/', '', $cedula);

if ($cedula === '' || $torneo_id <= 0) {
    echo json_encode(['success' => false, 'resultado' => 'error', 'mensaje' => 'Faltan torneo_id, nacionalidad o cédula.']);
    exit;
}

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
if (!Auth::canAccessTournament($torneo_id)) {
    echo json_encode(['success' => false, 'resultado' => 'error', 'mensaje' => 'Sin permiso para este torneo.']);
    exit;
}

$pdo = DB::pdo();

// ─── NIVEL 1: Validación en inscritos (coincidencia exacta: torneo_id + nacionalidad + cedula) ───
try {
    $stmtInscrito = $pdo->prepare("
        SELECT id FROM inscritos
        WHERE torneo_id = ? AND nacionalidad = ? AND cedula = ?
        LIMIT 1
    ");
    $stmtInscrito->execute([$torneo_id, $nacionalidad, $cedula]);
    if ($stmtInscrito->fetch()) {
        echo json_encode([
            'success' => true,
            'resultado' => 'ya_inscrito',
            'mensaje' => 'Esta cédula ya está registrada en este torneo.'
        ]);
        exit;
    }
} catch (Throwable $e) {
    // Si la tabla no tiene nacionalidad/cedula, ejecutar: php scripts/add_nacionalidad_cedula_inscritos.php
    if (strpos($e->getMessage(), 'nacionalidad') !== false || strpos($e->getMessage(), 'cedula') !== false) {
        error_log('buscar_inscribir_sitio: tabla inscritos sin nacionalidad/cedula. Ejecute: php scripts/add_nacionalidad_cedula_inscritos.php');
    }
    // Continuar con NIVEL 2 (búsqueda en usuarios)
}

// ─── NIVEL 2–3: usuarios (variantes + normalizado) y afiliados — BusquedaJugadorInscripcionService ───
$usuario = BusquedaJugadorInscripcionService::buscarUsuarioPorCedula($pdo, $nacionalidad, $cedula);
if ($usuario) {
    $user_id = (int) $usuario['id'];
    $fechnac = $usuario['fechnac'] ?? '';
    if ($fechnac && !preg_match('/^\d{4}-\d{2}-\d{2}/', $fechnac) && strtotime($fechnac) !== false) {
        $fechnac = date('Y-m-d', strtotime($fechnac));
    }
    echo json_encode([
        'success' => true,
        'resultado' => 'usuario',
        'usuario' => [
            'id' => $user_id,
            'username' => $usuario['username'] ?? '',
            'nombre' => $usuario['nombre'] ?? '',
            'cedula' => $usuario['cedula'] ?? $cedula,
            'email' => $usuario['email'] ?? '',
            'celular' => $usuario['celular'] ?? '',
            'telefono' => $usuario['celular'] ?? '',
            'fechnac' => $fechnac,
            'sexo' => $usuario['sexo'] ?? 'M',
            'nacionalidad' => $usuario['nacionalidad'] ?? $nacionalidad,
            'club_id' => (int) ($usuario['club_id'] ?? 0),
        ],
    ]);
    exit;
}

$ext = BusquedaJugadorInscripcionService::buscarPersonaExternaPorCedula($nacionalidad, $cedula);
if ($ext !== null) {
    $p = $ext['persona'];
    echo json_encode([
        'success' => true,
        'resultado' => 'persona_externa',
        'persona' => [
            'nacionalidad' => $p['nacionalidad'] ?? $nacionalidad,
            'cedula' => $cedula,
            'nombre' => $p['nombre'] ?? '',
            'sexo' => $p['sexo'] ?? '',
            'fechnac' => $p['fechnac'] ?? '',
            'telefono' => $p['celular'] ?? $p['telefono'] ?? '',
            'email' => $p['email'] ?? '',
        ],
    ]);
    exit;
}

// No encontrado: registro manual
echo json_encode([
    'success' => true,
    'resultado' => 'no_encontrado',
    'mensaje' => 'No encontrado. Complete los datos para registrar e inscribir.'
]);
