<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

Auth::requireRole(['admin_general','admin_torneo','admin_club']);
CSRF::validate();

// Obtener información del usuario actual
$current_user = Auth::user();
$user_role = $current_user['role'] ?? '';
$user_club_id = !empty($current_user['club_id']) ? (int)$current_user['club_id'] : null;
$is_admin_torneo = ($user_role === 'admin_torneo');
$is_admin_club = ($user_role === 'admin_club');

try {
    // Validar campos requeridos
    if (empty($_POST['athlete_id'])) {
        throw new Exception('Debe seleccionar un atleta registrado');
    }
    if (empty($_POST['torneo_id'])) {
        throw new Exception('El torneo es requerido');
    }
    
    $athlete_id = (int)$_POST['athlete_id'];
    $torneo_id = (int)$_POST['torneo_id'];
    
    // Verificar que el usuario (atleta) existe en usuarios
    $stmt = DB::pdo()->prepare("SELECT id, cedula, nombre, club_id, sexo, celular FROM usuarios WHERE id = ?");
    $stmt->execute([$athlete_id]);
    $athlete = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$athlete) {
        throw new Exception('Usuario no encontrado');
    }
    
    $club_id = (int)($athlete['club_id'] ?? 0);
    
    // Verificar permisos según rol
    if ($is_admin_torneo || $is_admin_club) {
        if (!$user_club_id) {
            throw new Exception('Su usuario no tiene un club asignado. Contacte al administrador.');
        }
        // Clubes permitidos
        $clubes_permitidos = [$user_club_id];
        if ($is_admin_club) {
            $supervised = ClubHelper::getClubesSupervised($user_club_id);
            $clubes_permitidos = array_values(array_unique(array_merge($clubes_permitidos, $supervised)));
        }

        // Verificar torneo pertenece a club permitido y activo
        $stmt = DB::pdo()->prepare("
            SELECT club_responsable, estatus 
            FROM tournaments 
            WHERE id = ?
        ");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$torneo) {
            throw new Exception('Torneo no encontrado');
        }
        
        if (!in_array((int)$torneo['club_responsable'], $clubes_permitidos, true)) {
            throw new Exception('No tiene permisos para inscribir jugadores en este torneo');
        }
        
        if ((int)$torneo['estatus'] !== 1) {
            throw new Exception('No puede inscribir jugadores en torneos inactivos');
        }
        
        // Verificar que el atleta pertenezca a un club permitido
        if (!in_array($club_id, $clubes_permitidos, true)) {
            throw new Exception('Solo puede inscribir atletas de sus clubes supervisados');
        }
    }
    
    // Preparar datos del atleta
    $cedula = $athlete['cedula'];
    $nombre = $athlete['nombre'];
    $estatus = (int)($_POST['estatus'] ?? 1);
    
    // Verificar duplicado (cédula única por torneo)
    $stmt = DB::pdo()->prepare("
        SELECT id FROM inscripciones 
        WHERE torneo_id = ? AND cedula = ?
    ");
    $stmt->execute([$torneo_id, $cedula]);
    if ($stmt->fetch()) {
        throw new Exception('Ya existe un jugador con esta cédula inscrito en este torneo');
    }
    
    // Obtener el siguiente identificador consecutivo para este torneo
    $stmt = DB::pdo()->prepare("
        SELECT MAX(identificador) as max_id FROM inscripciones WHERE torneo_id = ?
    ");
    $stmt->execute([$torneo_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $siguiente_identificador = $result['max_id'] ? ((int)$result['max_id'] + 1) : 1;
    
    // Datos del atleta vienen de usuarios
    $athlete_data = [
        'cedula' => $athlete['cedula'],
        'nombre' => $athlete['nombre'],
        'sexo' => $athlete['sexo'] ?? null,
        'celular' => $athlete['celular'] ?? null,
    ];

    // Insertar en la base de datos con identificador automático
    $stmt = DB::pdo()->prepare("
        INSERT INTO inscripciones (
            athlete_id, cedula, nombre, sexo, club_id, torneo_id, 
            celular, categ, estatus, identificador, team_id
        ) VALUES (
            :athlete_id, :cedula, :nombre, :sexo, :club_id, :torneo_id,
            :celular, :categ, :estatus, :identificador, :team_id
        )
    ");
    
    $stmt->execute([
        ':athlete_id' => $athlete_id,
        ':cedula' => $athlete_data['cedula'],
        ':nombre' => $athlete_data['nombre'],
        ':sexo' => $athlete_data['sexo'],
        ':club_id' => $club_id,
        ':torneo_id' => $torneo_id,
        ':celular' => $athlete_data['celular'] ?: null,
        ':categ' => 0, // Se puede calcular después si es necesario
        ':estatus' => $estatus,
        ':identificador' => $siguiente_identificador,
        ':team_id' => null // For now, set to null; UI can set for teams
    ]);
    
    // Redirigir con éxito y mostrar deuda actualizada
    header('Location: ../../public/index.php?page=registrants&success=' . urlencode('Inscrito creado exitosamente') . '&show_deuda=1&club_id=' . $club_id . '&torneo_id=' . $torneo_id);
    exit;
    
} catch (Exception $e) {
    // Redirigir con error
    header('Location: ../../public/index.php?page=registrants&action=new&error=' . urlencode($e->getMessage()));
    exit;
}

