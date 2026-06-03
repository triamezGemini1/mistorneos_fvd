<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/validation.php';
Auth::requireRole(['admin_general','admin_torneo','admin_club']);
CSRF::validate();

// Obtener información del usuario actual
$current_user = Auth::user();
$user_role = $current_user['role'] ?? '';
$user_club_id = !empty($current_user['club_id']) ? (int)$current_user['club_id'] : null;
$is_admin_torneo = ($user_role === 'admin_torneo');
$is_admin_club = ($user_role === 'admin_club');

$id = V::int($_POST['id'] ?? 0,1);
$athlete_id = V::int($_POST['athlete_id'] ?? 0,1);
$torneo_id = V::int($_POST['torneo_id'] ?? 0,1);

// Verificar que el usuario (atleta) existe en usuarios
$stmt = DB::pdo()->prepare("SELECT id, cedula, nombre, club_id, sexo, celular FROM usuarios WHERE id = ?");
$stmt->execute([$athlete_id]);
$athlete = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$athlete) {
    die('Error: Usuario no encontrado');
}

$club_id = (int)($athlete['club_id'] ?? 0);
$cedula = $athlete['cedula'];

// Si es admin_torneo o admin_club, verificar permisos y clubes permitidos
if ($is_admin_torneo || $is_admin_club) {
    if (!$user_club_id) {
        die('Error: Su usuario no tiene un club asignado. Contacte al administrador.');
    }

    $clubes_permitidos = [$user_club_id];
    if ($is_admin_club) {
        require_once __DIR__ . '/../../lib/ClubHelper.php';
        $supervised = ClubHelper::getClubesSupervised($user_club_id);
        $clubes_permitidos = array_values(array_unique(array_merge($clubes_permitidos, $supervised)));
    }

    // Verificar que el inscrito pertenezca a un torneo permitido y activo
    $stmt = DB::pdo()->prepare("
        SELECT t.club_responsable, t.estatus
        FROM inscripciones r
        INNER JOIN tournaments t ON r.torneo_id = t.id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $registrant_torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registrant_torneo) {
        die('Error: Inscrito no encontrado');
    }
    
    if (!in_array((int)$registrant_torneo['club_responsable'], $clubes_permitidos, true)) {
        die('Error: No tiene permisos para modificar este inscrito');
    }
    
    if ((int)$registrant_torneo['estatus'] !== 1) {
        die('Error: No puede modificar inscritos de torneos inactivos');
    }
    
    // Verificar que el nuevo torneo (si cambió) también pertenezca a sus clubes permitidos
    $stmt = DB::pdo()->prepare("
        SELECT club_responsable, estatus
        FROM tournaments
        WHERE id = ?
    ");
    $stmt->execute([$torneo_id]);
    $new_torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$new_torneo) {
        die('Error: Torneo no encontrado');
    }
    
    if (!in_array((int)$new_torneo['club_responsable'], $clubes_permitidos, true)) {
        die('Error: No puede mover inscritos a torneos de otros clubs');
    }
    
    if ((int)$new_torneo['estatus'] !== 1) {
        die('Error: No puede mover inscritos a torneos inactivos');
    }
    
    // Verificar que el atleta pertenezca a sus clubes permitidos
    if (!in_array($club_id, $clubes_permitidos, true)) {
        die('Error: Solo puede asignar atletas de sus clubes supervisados');
    }
}

// Ensure composite uniqueness on update
$check = DB::pdo()->prepare("SELECT id FROM inscripciones WHERE torneo_id=:t AND cedula=:c AND id<>:id");
$check->execute([':t'=>$torneo_id, ':c'=>$cedula, ':id'=>$id]);
if ($check->fetch()) { die('Conflicto: cédula ya existe en el torneo'); }

// Datos completos del usuario (atleta)
$athlete_data = [
    'cedula' => $athlete['cedula'],
    'nombre' => $athlete['nombre'],
    'sexo' => $athlete['sexo'] ?? null,
    'celular' => $athlete['celular'] ?? null,
];

$data = [
  ':id' => $id,
  ':athlete_id' => $athlete_id,
  ':cedula' => $athlete_data['cedula'],
  ':nombre' => $athlete_data['nombre'],
  ':sexo' => $athlete_data['sexo'],
  ':club_id' => $club_id,
  ':estatus' => V::int($_POST['estatus'] ?? 1,0,1),
  ':torneo_id' => $torneo_id,
  ':categ' => 0, // Se puede calcular después si es necesario
  ':celular' => $athlete_data['celular'] ?: null,
];

$stmt = DB::pdo()->prepare("UPDATE registrants SET athlete_id=:athlete_id, cedula=:cedula, nombre=:nombre, sexo=:sexo, club_id=:club_id, estatus=:estatus, torneo_id=:torneo_id, categ=:categ, celular=:celular WHERE id=:id");
$stmt->execute($data);

header('Location: list.php');

