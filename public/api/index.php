<?php

require_once __DIR__ . '/../../config/session_start_early.php';
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/validation.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../../lib/invitation_helpers.php';

// CORS (misma-origin; ajustar si se expone p�blicamente)
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/api';
$base = parse_url($path, PHP_URL_PATH);
$base = preg_replace('#/+#', '/', $base);

// Normaliza base en /api/*
$pos = strpos($base, '/api/');
if ($pos === false) { json_err('Invalid API base', 404); }
$endpoint = substr($base, $pos + 5); // después de "/api/"
$segments = array_values(array_filter(explode('/', $endpoint), 'strlen'));

// Helpers
function require_roles(array $roles) {
  $u = Auth::user();
  if (!$u || !in_array($u['role'], $roles, true)) {
    json_err('Forbidden', 403);
  }
}
function allow_methods(array $allowed) {
  global $method;
  if (!in_array($method, $allowed, true)) {
    header('Allow: ' . implode(', ', $allowed));
    json_err('Method Not Allowed', 405);
  }
}

try {
  // Public utility endpoints
  if ($segments === ['csrf']) {
    if ($method !== 'GET') { allow_methods(['GET']); }
    json_ok(['token' => CSRF::token()]);
  }
  if ($segments === ['auth','session']) {
    if ($method !== 'GET') { allow_methods(['GET']); }
    json_ok(['user' => Auth::user()]);
  }
  if ($segments === ['auth','login']) {
    allow_methods(['POST']);
    $b = get_json_body();
    $ok = Auth::login(trim($b['username'] ?? ''), (string)($b['password'] ?? ''));
    if ($ok) json_ok(['user'=>Auth::user(),'csrf'=>CSRF::token()]);
    json_err('Credenciales inválidas', 401);
  }
  if ($segments === ['auth','logout']) {
    allow_methods(['POST']);
    Auth::logout();
    json_ok(['message'=>'bye']);
  }

  // Rutas protegidas (validar CSRF en métodos mutadores)
  if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    CSRF::validateApi();
  }

  // /api/clubs
  if ($segments[0] === 'clubs') {
    if (count($segments) === 1) {
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club','usuario']);
        [$page,$per,$offset] = page_params();
        $q = trim($_GET['q'] ?? '');
        $estatus = $_GET['estatus'] ?? null;
        $where = []; $args = [];
        if ($q !== '') {
          $where[] = "(c.nombre LIKE :q OR c.delegado LIKE :q OR c.telefono LIKE :q)";
          $args[':q'] = '%' . $q . '%';
        }
        if ($estatus !== null && $estatus !== '') {
          $where[] = "c.estatus = :s"; $args[':s'] = (int)$estatus;
        }
        $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $total = DB::pdo()->prepare("SELECT COUNT(*) FROM clubes c $wsql"); $total->execute($args);
        $count = (int)$total->fetchColumn();
        $stmt = DB::pdo()->prepare("SELECT c.* FROM clubes c $wsql ORDER BY c.nombre LIMIT :lim OFFSET :off");
        foreach ($args as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        json_ok($stmt->fetchAll(), 200, ['page'=>$page,'per_page'=>$per,'total'=>$count,'total_pages'=>ceil($count/$per)]);
      }
      if ($method === 'POST') {
        require_roles(['admin_general','admin_torneo']);
        $b = get_json_body();
        
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        
        try {
          // 1. Crear el club
          $stmt = $pdo->prepare("INSERT INTO clubes (nombre,direccion,delegado,telefono,email,estatus) VALUES (:n,:d,:del,:t,:e,:s)");
          $stmt->execute([
            ':n'=>V::str($b['nombre'] ?? '',1,255),
            ':d'=>$b['direccion'] ?? null,
            ':del'=>$b['delegado'] ?? null,
            ':t'=>V::phone($b['telefono'] ?? null),
            ':e'=>V::email($b['email'] ?? null),
            ':s'=>V::int($b['estatus'] ?? 1,0,1),
          ]);
          $club_id = (int)$pdo->lastInsertId();
          
          // 2. Crear usuario único para el club
          $username_invitado = Security::defaultClubUsername($club_id); // 'invitado' + club_id
          $password_hash = Security::hashPassword(Security::defaultClubPassword()); // hash de 'invitado123'
          
          // Verificar si el usuario ya existe (por seguridad)
          $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = :username");
          $stmt_check->execute([':username' => $username_invitado]);
          $user_exists = $stmt_check->fetchColumn();
          
          if ($user_exists == 0) {
            // Usar función centralizada para crear usuario
            $userData = [
              'username' => $username_invitado,
              'password' => Security::defaultClubPassword(),
              'email' => V::email($b['email'] ?? null),
              'role' => 'admin_club',
              'club_id' => $club_id,
              'status' => 'approved'
            ];
            
            $result = Security::createUser($userData);
            if (!$result['success']) {
              throw new Exception('Error al crear usuario del club: ' . implode(', ', $result['errors']));
            }
          }
          
          $pdo->commit();
          json_ok(['id'=>$club_id, 'username'=>$username_invitado], 201);
          
        } catch (Exception $e) {
          $pdo->rollBack();
          json_err('Error al crear club: ' . $e->getMessage(), 500);
        }
      }
      allow_methods(['GET','POST']);
    } else {
      $id = (int)$segments[1];
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club','usuario']);
        $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $r = $stmt->fetch(); if (!$r) json_err('Not found',404);
        json_ok($r);
      }
      if (in_array($method, ['PUT','PATCH'], true)) {
        require_roles(['admin_general','admin_torneo','admin_club']);
        $b = get_json_body();
        $stmt = DB::pdo()->prepare("UPDATE clubes SET nombre=:n, direccion=:d, delegado=:del, telefono=:t, email=:e, estatus=:s WHERE id=:id");
        $stmt->execute([
          ':n'=>V::str($b['nombre'] ?? '',1,255),
          ':d'=>$b['direccion'] ?? null,
          ':del'=>$b['delegado'] ?? null,
          ':t'=>V::phone($b['telefono'] ?? null),
          ':e'=>V::email($b['email'] ?? null),
          ':s'=>V::int($b['estatus'] ?? 1,0,1),
          ':id'=>$id
        ]);
        json_ok(['updated'=>true]);
      }
      if ($method === 'DELETE') {
        require_roles(['admin_general','admin_torneo']);
        $stmt = DB::pdo()->prepare("DELETE FROM clubes WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
      }
      allow_methods(['GET','PUT','PATCH','DELETE']);
    }
  }

  // /api/tournaments
  if ($segments[0] === 'tournaments') {
    if (count($segments) === 1) {
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club','usuario']);
        [$page,$per,$offset] = page_params();
        $q = trim($_GET['q'] ?? '');
        $estatus = $_GET['estatus'] ?? null;
        $club_resp = $_GET['club_responsable'] ?? null;
        $where = []; $args = [];
        if ($q !== '') { $where[] = "t.nombre LIKE :q"; $args[':q'] = '%'.$q.'%'; }
        if ($estatus !== null && $estatus !== '') { $where[] = "t.estatus=:s"; $args[':s']=(int)$estatus; }
        if ($club_resp !== null && $club_resp !== '') { $where[] = "t.club_responsable=:cr"; $args[':cr']=(int)$club_resp; }
        $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $total = DB::pdo()->prepare("SELECT COUNT(*) FROM tournaments t $wsql"); $total->execute($args);
        $count = (int)$total->fetchColumn();
        $stmt = DB::pdo()->prepare("SELECT t.* FROM tournaments t $wsql ORDER BY t.fechator DESC LIMIT :lim OFFSET :off");
        foreach ($args as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':lim',$per,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        json_ok($stmt->fetchAll(),200,['page'=>$page,'per_page'=>$per,'total'=>$count,'total_pages'=>ceil($count/$per)]);
      }
      if ($method === 'POST') {
        require_roles(['admin_general','admin_torneo']);
        $b = get_json_body();
        $tournamentCols = DB::pdo()->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
        $hasParentEventId = is_array($tournamentCols) && in_array('parent_event_id', $tournamentCols, true);
        $insT = "INSERT INTO tournaments (nombre,fechator,clase,modalidad,tiempo,puntos,rondas,estatus,costo,ranking,pareclub,club_responsable";
        $insV = "VALUES (:n,:f,:cl,:mo,:ti,:po,:ro,:es,:co,:ra,:pc,:cr";
        if ($hasParentEventId) {
          $insT .= ",parent_event_id";
          $insV .= ",0";
        }
        $stmt = DB::pdo()->prepare($insT . ") " . $insV . ")");
        $stmt->execute([
          ':n'=>V::str($b['nombre'] ?? '',1,255),
          ':f'=>V::date($b['fechator'] ?? null),
          ':cl'=>V::int($b['clase'] ?? 0,0),
          ':mo'=>V::int($b['modalidad'] ?? 0,0),
          ':ti'=>V::int($b['tiempo'] ?? 0,0),
          ':po'=>V::int($b['puntos'] ?? 0,0),
          ':ro'=>V::int($b['rondas'] ?? 0,0),
          ':es'=>V::int($b['estatus'] ?? 1,0,1),
          ':co'=>V::int($b['costo'] ?? 0,0),
          ':ra'=>V::int($b['ranking'] ?? 0,0),
          ':pc'=>V::int($b['pareclub'] ?? 0,0),
          ':cr'=>($b['club_responsable'] !== null && $b['club_responsable'] !== '') ? V::int($b['club_responsable'],1) : null
        ]);
        json_ok(['id'=>(int)DB::pdo()->lastInsertId()], 201);
      }
      allow_methods(['GET','POST']);
    } else {
      $id = (int)$segments[1];
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club','usuario']);
        $stmt = DB::pdo()->prepare("SELECT * FROM tournaments WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $r = $stmt->fetch(); if (!$r) json_err('Not found',404);
        json_ok($r);
      }
      if (in_array($method, ['PUT','PATCH'], true)) {
        require_roles(['admin_general','admin_torneo']);
        $b = get_json_body();
        $stmt = DB::pdo()->prepare("UPDATE tournaments SET nombre=:n,fechator=:f,clase=:cl,modalidad=:mo,tiempo=:ti,puntos=:po,rondas=:ro,estatus=:es,costo=:co,ranking=:ra,pareclub=:pc,club_responsable=:cr WHERE id=:id");
        $stmt->execute([
          ':n'=>V::str($b['nombre'] ?? '',1,255),
          ':f'=>V::date($b['fechator'] ?? null),
          ':cl'=>V::int($b['clase'] ?? 0,0),
          ':mo'=>V::int($b['modalidad'] ?? 0,0),
          ':ti'=>V::int($b['tiempo'] ?? 0,0),
          ':po'=>V::int($b['puntos'] ?? 0,0),
          ':ro'=>V::int($b['rondas'] ?? 0,0),
          ':es'=>V::int($b['estatus'] ?? 1,0,1),
          ':co'=>V::int($b['costo'] ?? 0,0),
          ':ra'=>V::int($b['ranking'] ?? 0,0),
          ':pc'=>V::int($b['pareclub'] ?? 0,0),
          ':cr'=>($b['club_responsable'] !== null && $b['club_responsable'] !== '') ? V::int($b['club_responsable'],1) : null,
          ':id'=>$id
        ]);
        json_ok(['updated'=>true]);
      }
      if ($method === 'DELETE') {
        require_roles(['admin_general','admin_torneo']);
        $stmt = DB::pdo()->prepare("DELETE FROM tournaments WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
      }
      allow_methods(['GET','PUT','PATCH','DELETE']);
    }
  }

  // /api/registrants
  if ($segments[0] === 'registrants') {
    if (count($segments) === 1) {
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club']);
        [$page,$per,$offset] = page_params();
        $q = trim($_GET['q'] ?? '');
        $torneo = $_GET['torneo_id'] ?? null;
        $club = $_GET['club_id'] ?? null;
        $sexo = $_GET['sexo'] ?? null;
        $where=[]; $args=[];
        if ($q !== '') { $where[]="(r.cedula LIKE :q OR r.nombre LIKE :q)"; $args[':q']='%'+$q+'%'; }
        if ($torneo) { $where[]="r.torneo_id=:t"; $args[':t']=(int)$torneo; }
        if ($club) { $where[]="r.club_id=:c"; $args[':c']=(int)$club; }
        if ($sexo) { $where[]="r.sexo=:sx"; $args[':sx']=V::enum($sexo,['M','F','O']); }
        $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $total = DB::pdo()->prepare("SELECT COUNT(*) FROM inscripciones r $wsql"); foreach($args as $k=>$v){ $total->bindValue($k,$v); } $total->execute();
        $count = (int)$total->fetchColumn();
        $stmt = DB::pdo()->prepare("SELECT r.* FROM inscripciones r $wsql ORDER BY r.created_at DESC LIMIT :lim OFFSET :off");
        foreach ($args as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':lim',$per,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        json_ok($stmt->fetchAll(),200,['page'=>$page,'per_page'=>$per,'total'=>$count,'total_pages'=>ceil($count/$per)]);
      }
      if ($method === 'POST') {
        require_roles(['admin_general','admin_torneo','admin_club']);
        $b = get_json_body();
        // Unique composite check
        $chk = DB::pdo()->prepare("SELECT id FROM inscripciones WHERE torneo_id=:t AND cedula=:c");
        $chk->execute([':t'=>(int)($b['torneo_id'] ?? 0), ':c'=>V::str($b['cedula'] ?? '',1,20)]);
        if ($chk->fetch()) json_err('Ya existe un inscrito con esa cédula para este torneo', 409);
        $stmt = DB::pdo()->prepare("INSERT INTO inscripciones (cedula,nombre,sexo,fechnac,club_id,estatus,torneo_id,categ,celular,email) VALUES (:cedula,:nombre,:sexo,:fechnac,:club_id,:estatus,:torneo_id,:categ,:celular,:email)");
        $stmt->execute([
          ':cedula'=>V::str($b['cedula'] ?? '',1,20),
          ':nombre'=>V::str($b['nombre'] ?? '',1,255),
          ':sexo'=>V::enum($b['sexo'] ?? 'M',['M','F','O']),
          ':fechnac'=>V::date($b['fechnac'] ?? null),
          ':club_id'=>($b['club_id']!==null && $b['club_id']!=='')?V::int($b['club_id'],1):null,
          ':estatus'=>V::int($b['estatus'] ?? 1,0,1),
          ':torneo_id'=>V::int($b['torneo_id'] ?? 0,1),
          ':categ'=>V::int($b['categ'] ?? 0,0),
          ':celular'=>V::phone($b['celular'] ?? null),
          ':email'=>V::email($b['email'] ?? null),
        ]);
        json_ok(['id'=>(int)DB::pdo()->lastInsertId()], 201);
      }
      allow_methods(['GET','POST']);
    } else {
      $id = (int)$segments[1];
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club']);
        $stmt = DB::pdo()->prepare("SELECT * FROM inscripciones WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $r = $stmt->fetch(); if (!$r) json_err('Not found',404);
        json_ok($r);
      }
      if (in_array($method, ['PUT','PATCH'], true)) {
        require_roles(['admin_general','admin_torneo','admin_club']);
        $b = get_json_body();
        // ensure uniqueness
        $chk = DB::pdo()->prepare("SELECT id FROM inscripciones WHERE torneo_id=:t AND cedula=:c AND id<>:id");
        $chk->execute([':t'=>V::int($b['torneo_id'] ?? 0,1), ':c'=>V::str($b['cedula'] ?? '',1,20), ':id'=>$id]);
        if ($chk->fetch()) json_err('Conflicto: cédula ya existe en el torneo',409);
        $stmt = DB::pdo()->prepare("UPDATE registrants SET cedula=:cedula, nombre=:nombre, sexo=:sexo, fechnac=:fechnac, club_id=:club_id, estatus=:estatus, torneo_id=:torneo_id, categ=:categ, celular=:celular, email=:email WHERE id=:id");
        $stmt->execute([
          ':id'=>$id,
          ':cedula'=>V::str($b['cedula'] ?? '',1,20),
          ':nombre'=>V::str($b['nombre'] ?? '',1,255),
          ':sexo'=>V::enum($b['sexo'] ?? 'M',['M','F','O']),
          ':fechnac'=>V::date($b['fechnac'] ?? null),
          ':club_id'=>($b['club_id']!==null && $b['club_id']!=='')?V::int($b['club_id'],1):null,
          ':estatus'=>V::int($b['estatus'] ?? 1,0,1),
          ':torneo_id'=>V::int($b['torneo_id'] ?? 0,1),
          ':categ'=>V::int($b['categ'] ?? 0,0),
          ':celular'=>V::phone($b['celular'] ?? null),
          ':email'=>V::email($b['email'] ?? null),
        ]);
        json_ok(['updated'=>true]);
      }
      if ($method === 'DELETE') {
        require_roles(['admin_general','admin_torneo']);
        $stmt = DB::pdo()->prepare("DELETE FROM inscripciones WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
      }
      allow_methods(['GET','PUT','PATCH','DELETE']);
    }
  }

  // /api/invitations and revoke
  if ($segments[0] === 'invitations') {
    if (count($segments) === 1) {
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo']);
        [$page,$per,$offset] = page_params();
        $torneo = $_GET['torneo_id'] ?? null;
        $club = $_GET['club_id'] ?? null;
        $estado = $_GET['estado'] ?? null;
        $where=[]; $args=[];
        if ($torneo) { $where[]="i.torneo_id=:t"; $args[':t']=(int)$torneo; }
        if ($club) { $where[]="i.club_id=:c"; $args[':c']=(int)$club; }
        if ($estado) { $where[]="i.estado=:e"; $args[':e']=$estado; }
        $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $total = DB::pdo()->prepare("SELECT COUNT(*) FROM invitations i $wsql"); foreach($args as $k=>$v){ $total->bindValue($k,$v); } $total->execute();
        $count = (int)$total->fetchColumn();
        $stmt = DB::pdo()->prepare("SELECT i.* FROM invitations i $wsql ORDER BY i.fecha_creacion DESC LIMIT :lim OFFSET :off");
        foreach ($args as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':lim',$per,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        json_ok($stmt->fetchAll(),200,['page'=>$page,'per_page'=>$per,'total'=>$count,'total_pages'=>ceil($count/$per)]);
      }
      if ($method === 'POST') {
        require_roles(['admin_general','admin_torneo']);
        $b = get_json_body();
        $a1 = V::date($b['acceso1'] ?? null);
        $a2 = V::date($b['acceso2'] ?? null);
        if (strtotime($a2) < strtotime($a1)) json_err('Rango de fechas inválido',422);
        // Sistema SIN tokens - solo almacenamos link simple
        $token = ''; // Token vacío - ya no generamos tokens complejos
        $club_id = V::int($b['club_id'] ?? 0,1);
        $usuario_invitado = "usuario" . $club_id;
        
        try {
            $stmt = DB::pdo()->prepare("INSERT INTO invitations (torneo_id, club_id, acceso1, acceso2, usuario, token, estado) VALUES (:t,:c,:a1,:a2,:u,:tk,'activa')");
            $stmt->execute([
              ':t'=>V::int($b['torneo_id'] ?? 0,1),
              ':c'=>$club_id,
              ':a1'=>$a1, ':a2'=>$a2,
              ':u'=>$usuario_invitado, ':tk'=>$token
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Integrity constraint violation
                // Verificar si es un error de duplicado
                $stmt_check = DB::pdo()->prepare("SELECT id, estado FROM invitations WHERE torneo_id = :t AND club_id = :c");
                $stmt_check->execute([':t' => V::int($b['torneo_id'] ?? 0,1), ':c' => $club_id]);
                $existing = $stmt_check->fetch();
                
                if ($existing) {
                    // Actualizar la invitación existente SIN cambiar token (lo mantenemos vacío)
                    $stmt_update = DB::pdo()->prepare("UPDATE invitations SET acceso1 = :a1, acceso2 = :a2, token = '', estado = 'activa', fecha_modificacion = NOW() WHERE id = :id");
                    $stmt_update->execute([':a1' => $a1, ':a2' => $a2, ':id' => $existing['id']]);
                } else {
                    throw $e; // Re-lanzar si no es un duplicado
                }
            } else {
                throw $e; // Re-lanzar otros errores
            }
        }
        
        // Crear usuario invitado automáticamente
        $username_invitado = Security::defaultClubUsername($club_id);
        $password_hash = Security::hashPassword(Security::defaultClubPassword());
        
        // Obtener email del club
        $stmt_club = DB::pdo()->prepare("SELECT email FROM clubes WHERE id = :club_id");
        $stmt_club->execute([':club_id' => $club_id]);
        $club_email = $stmt_club->fetchColumn();
        
        // Verificar si el usuario ya existe
        $stmt_check = DB::pdo()->prepare("SELECT COUNT(*) FROM usuarios WHERE username = :username");
        $stmt_check->execute([':username' => $username_invitado]);
        $user_exists = $stmt_check->fetchColumn();
        
        if ($user_exists == 0) {
            // Crear el usuario invitado
            $stmt_user = DB::pdo()->prepare("
                INSERT INTO usuarios (username, password_hash, email, role, status) 
                VALUES (:username, :password_hash, :email, 'admin_club', 0)
            ");
            $stmt_user->execute([
                ':username' => $username_invitado,
                ':password_hash' => $password_hash,
                ':email' => $club_email
            ]);
        }
        
        // Generar ruta de acceso SIN token (sistema simplificado) y validar archivo
        $ruta = InvitationHelpers::buildSimpleInvitationUrl(V::int($b['torneo_id'] ?? 0,1), $club_id);
        if (!InvitationHelpers::simpleLoginExists()) {
            // No abortamos, pero informamos en la respuesta
            $ruta_warning = 'simple_invitation_login.php no existe en public';
        }
        
        json_ok([
            'id'=>(int)DB::pdo()->lastInsertId(),
            'token'=>$token,
            'usuario'=>$usuario_invitado,
            'ruta'=>$ruta,
            'ruta_warning'=>$ruta_warning ?? null
        ],201);
      }
      allow_methods(['GET','POST']);
    } else {
      $id = (int)$segments[1];
      if (count($segments) === 3 && $segments[2] === 'revoke') {
        allow_methods(['POST']);
        require_roles(['admin_general','admin_torneo']);
        $stmt = DB::pdo()->prepare("UPDATE invitations SET estado='cancelada' WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        json_ok(['revoked'=>true]);
      } else {
        if ($method === 'GET') {
          require_roles(['admin_general','admin_torneo']);
          $stmt = DB::pdo()->prepare("SELECT * FROM invitations WHERE id=:id");
          $stmt->execute([':id'=>$id]);
          $r = $stmt->fetch(); if (!$r) json_err('Not found',404);
          json_ok($r);
        }
        allow_methods(['GET','POST']);
      }
    }
  }

  // /api/payments
  if ($segments[0] === 'payments') {
    if (count($segments) === 1) {
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club']);
        [$page,$per,$offset] = page_params();
        $torneo = $_GET['torneo_id'] ?? null;
        $club = $_GET['club_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $where=[]; $args=[];
        if ($torneo) { $where[]="p.torneo_id=:t"; $args[':t']=(int)$torneo; }
        if ($club) { $where[]="p.club_id=:c"; $args[':c']=(int)$club; }
        if ($status) { $where[]="p.status=:s"; $args[':s']=$status; }
        $wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $total = DB::pdo()->prepare("SELECT COUNT(*) FROM payments p $wsql");
        foreach($args as $k => $v) { $total->bindValue($k,$v); }
        $total->execute();
        $count = (int)$total->fetchColumn();
        $stmt = DB::pdo()->prepare("SELECT p.* FROM payments p $wsql ORDER BY p.created_at DESC LIMIT :lim OFFSET :off");
        foreach ($args as $k => $v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':lim',$per,PDO::PARAM_INT);
        $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
        $stmt->execute();
        json_ok($stmt->fetchAll(),200,['page'=>$page,'per_page'=>$per,'total'=>$count,'total_pages'=>ceil($count/$per)]);
      }
      if ($method === 'POST') {
        require_roles(['admin_general','admin_torneo','admin_club']);
        $b = get_json_body();
        $stmt = DB::pdo()->prepare("INSERT INTO payments (torneo_id, club_id, amount, method, reference, status) VALUES (:t,:c,:a,:m,:r,:s)");
        $stmt->execute([
          ':t'=>V::int($b['torneo_id'] ?? 0,1),
          ':c'=>V::int($b['club_id'] ?? 0,1),
          ':a'=>(float)($b['amount'] ?? 0),
          ':m'=>V::str($b['method'] ?? 'transferencia',1,30),
          ':r'=>$b['reference'] ?? null,
          ':s'=>V::enum($b['status'] ?? 'pendiente',['pendiente','confirmado','rechazado'])
        ]);
        json_ok(['id'=>(int)DB::pdo()->lastInsertId()],201);
      }
      allow_methods(['GET','POST']);
    } else {
      $id = (int)$segments[1];
      if ($method === 'GET') {
        require_roles(['admin_general','admin_torneo','admin_club']);
        $stmt = DB::pdo()->prepare("SELECT * FROM payments WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $r = $stmt->fetch(); if (!$r) json_err('Not found',404);
        json_ok($r);
      }
      allow_methods(['GET']);
    }
  }

  // Not found
  json_err('Not Found', 404);

} catch (Throwable $e) {
  json_err('Server error: '.$e->getMessage(), 500);
}
