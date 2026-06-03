<?php

// Evitar salida previa que impida el redirect (pantalla blanca)
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/file_upload.php';

Auth::requireRole(['admin_general','admin_torneo','admin_club']);
CSRF::validate();

// Obtener usuario actual y permisos
$current_user = Auth::user();
$user_id = Auth::id();
$user_role = $current_user['role'];
$user_club_id = $current_user['club_id'] ?? null;
$is_admin_general = Auth::isAdminGeneral();

$resolveOrgRef = static function (PDO $pdo, int $orgRawId): array {
    if ($orgRawId <= 0) {
        return ['id' => 0, 'ref' => 0, 'entidad' => 0];
    }
    $hasCodOrg = false;
    try {
        $hasCodOrg = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {
        $hasCodOrg = false;
    }
    if ($hasCodOrg) {
        $st = $pdo->prepare("SELECT id, entidad, COALESCE(NULLIF(cod_org,0), id) AS ref FROM organizaciones WHERE id = ? OR cod_org = ? LIMIT 1");
        $st->execute([$orgRawId, $orgRawId]);
    } else {
        $st = $pdo->prepare("SELECT id, entidad, id AS ref FROM organizaciones WHERE id = ? LIMIT 1");
        $st->execute([$orgRawId]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'id' => (int)($row['id'] ?? 0),
        'ref' => (int)($row['ref'] ?? 0),
        'entidad' => (int)($row['entidad'] ?? 0),
    ];
};

try {
    // owner_user_id: debe ser el ID del admin que registra el torneo (no puede ser 0 ni diferente al admin)
    if ($user_id <= 0) {
        throw new Exception('Usuario no válido. No se puede registrar el torneo.');
    }
    // Validar campos requeridos
    if (empty($_POST['nombre'])) {
        throw new Exception('El nombre del torneo es requerido');
    }
    if (empty($_POST['fechator'])) {
        throw new Exception('La fecha del torneo es requerida');
    }
    if (empty($_POST['clase']) || !in_array((int)$_POST['clase'], [1, 2])) {
        throw new Exception('La clase del torneo es inválida');
    }
    if (empty($_POST['modalidad']) || !in_array((int)$_POST['modalidad'], [1, 2, 3, 4])) {
        throw new Exception('La modalidad del torneo es inválida');
    }
    
    // Preparar datos
    $nombre = trim($_POST['nombre']);
    $fechator = $_POST['fechator'];
    $lugar = !empty($_POST['lugar']) ? trim($_POST['lugar']) : null;

    // Evitar duplicados: mismo nombre, misma fecha, mismo lugar
    if ($lugar === null || $lugar === '') {
        $stmt_dup = DB::pdo()->prepare("SELECT id FROM tournaments WHERE nombre = ? AND fechator = ? AND (lugar IS NULL OR lugar = '') LIMIT 1");
        $stmt_dup->execute([$nombre, $fechator]);
    } else {
        $stmt_dup = DB::pdo()->prepare("SELECT id FROM tournaments WHERE nombre = ? AND fechator = ? AND lugar = ? LIMIT 1");
        $stmt_dup->execute([$nombre, $fechator, $lugar]);
    }
    if ($stmt_dup->fetch()) {
        throw new Exception('Ya existe un torneo con el mismo nombre, fecha y lugar. No se permiten torneos duplicados. Verifique los datos o edite el torneo existente.');
    }

    // Guardar valores numéricos directamente (la tabla usa INT, no ENUM)
    $clase = (int)$_POST['clase']; // 1 = Torneo, 2 = Campeonato
    $modalidad = (int)$_POST['modalidad']; // 1 = Individual, 2 = Parejas, 3 = Equipos
    $tiempo = (int)($_POST['tiempo'] ?? 0);
    $puntos = (int)($_POST['puntos'] ?? 0);
    if ($puntos <= 0) {
        $puntos = 200; // El torneo no puede tener 0 puntos; por defecto 200
    }
    $rondas = (int)($_POST['rondas'] ?? 0);
    $costo = (float)($_POST['costo'] ?? 0);
    $ranking = (int)($_POST['ranking'] ?? 0);
    // pareclub ahora es un entero desde 1 en adelante (jugadores por club)
    $pareclub = !empty($_POST['pareclub']) ? max(1, (int)$_POST['pareclub']) : 0;
    $estatus = (int)($_POST['estatus'] ?? 1);
    require_once __DIR__ . '/../../lib/FvdConfig.php';
    $club_responsable = FvdConfig::clubResponsableTorneo(
        !empty($_POST['club_responsable']) ? (int)$_POST['club_responsable'] : null
    );
    $es_evento_masivo = isset($_POST['es_evento_masivo']) ? (int)$_POST['es_evento_masivo'] : 0;
    
    // Validar que es_evento_masivo sea válido (0, 1, 2, 3, o 4)
    if (!in_array($es_evento_masivo, [0, 1, 2, 3, 4])) {
        $es_evento_masivo = 0;
    }
    
    // Si es Evento Nacional (código 1), no genera ranking (tipo polla)
    if ($es_evento_masivo == 1) {
        $ranking = 0;
    }
    // Evento Regional (2) o Local (3): puede generar ranking (se mantiene el valor del formulario)
    // Evento Privado (4): se muestra pero no permite inscripción en línea
    
    $cuenta_id = !empty($_POST['cuenta_id']) ? (int)$_POST['cuenta_id'] : null;
    $permite_inscripcion_linea = isset($_POST['permite_inscripcion_linea']) ? 1 : 0;
    // publicar_landing: el admin decide cuándo publicar (por defecto 0 para torneos nuevos)
    $publicar_landing = isset($_POST['publicar_landing']) ? 1 : 0;
    // Hora y tipo de torneo (opcionales)
    $hora_torneo = !empty($_POST['hora_torneo']) ? trim($_POST['hora_torneo']) : null;
    if ($hora_torneo !== null && !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $hora_torneo)) {
        $hora_torneo = null;
    }
    // tipo_torneo: entero (índice) 0=no definido, 1=interclubes, 2=suizo_puro, 3=suizo_sin_repetir
    $tipo_torneo_raw = isset($_POST['tipo_torneo']) ? trim((string)$_POST['tipo_torneo']) : '';
    $tipo_torneo = null;
    if ($tipo_torneo_raw !== '' && in_array((int)$tipo_torneo_raw, [1, 2, 3], true)) {
        $tipo_torneo = (int)$tipo_torneo_raw;
    }
    
    // La organización del torneo se obtiene del admin_club que lo crea
    $organizacion_id = null;
    
    // Validar permisos según rol
    // IMPORTANTE: club_responsable almacena el ID de la ORGANIZACIÓN, no del club
    if (!$is_admin_general) {
        // admin_club trabaja a nivel de organización
        if ($user_role === 'admin_club') {
            // Obtener la organización del admin_club (fallback robusto por Auth)
            $organizacion_id = (int)(Auth::getUserOrganizacionRef() ?? Auth::getUserOrganizacionId() ?? 0);
            
            if (!$organizacion_id) {
                throw new Exception('No tiene una organización asignada. Contacte al administrador.');
            }
            $orgRefData = $resolveOrgRef(DB::pdo(), $organizacion_id);
            $organizacion_id = (int)$orgRefData['ref'];
            $club_responsable = (int)$orgRefData['ref'];
            
        } else {
            // admin_torneo requiere club_id asignado
            if (!$user_club_id) {
                throw new Exception('Su usuario no tiene un club asignado');
            }
            
            // Obtener organización del club del admin_torneo
            $stmt_org = DB::pdo()->prepare("SELECT cod_org FROM clubes WHERE id = ?");
            $stmt_org->execute([$user_club_id]);
            $organizacion_id = (int)$stmt_org->fetchColumn();
            
            if (!$organizacion_id) {
                throw new Exception('Su club no tiene una organización asignada. Contacte al administrador.');
            }
            $orgRefData = $resolveOrgRef(DB::pdo(), $organizacion_id);
            $organizacion_id = (int)$orgRefData['ref'];
            $club_responsable = (int)$orgRefData['ref'];
        }
    } else {
        $organizacion_id = FvdConfig::organizacionId();
        $club_responsable = FvdConfig::clubResponsableTorneo();
    }

    $entidad = !empty($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
    if ($entidad <= 0 && $organizacion_id > 0) {
        $orgRefData = $resolveOrgRef(DB::pdo(), (int)$organizacion_id);
        $entidad = (int)$orgRefData['entidad'];
    }
    if ($entidad <= 0 && $is_admin_general) {
        $entidad = 0;
    }
    
    // Insertar en la base de datos (primero sin archivos para obtener el ID)
    // Verificar columnas existentes (cod_org, owner_user_id, entidad)
    $tiene_organizacion = false;
    $tiene_owner = false;
    $tiene_entidad = false;
    $tiene_permite_inscripcion = false;
    $tiene_publicar_landing = false;
    $tiene_parent_event_id = false;
    try {
        $cols = DB::pdo()->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
        $tiene_organizacion = in_array('cod_org', $cols);
        $tiene_owner = in_array('owner_user_id', $cols);
        $tiene_entidad = in_array('entidad', $cols);
        $tiene_permite_inscripcion = in_array('permite_inscripcion_linea', $cols);
        $tiene_publicar_landing = in_array('publicar_landing', $cols);
        $tiene_hora_torneo = in_array('hora_torneo', $cols);
        $tiene_tipo_torneo = in_array('tipo_torneo', $cols);
        $tiene_parent_event_id = in_array('parent_event_id', $cols);
    } catch (Exception $e) {
        $tiene_hora_torneo = false;
        $tiene_tipo_torneo = false;
    }
    
    // owner_user_id: SIEMPRE el ID del admin que registra (no aceptar desde POST)
    $owner_user_id = $user_id;
    
    if ($tiene_organizacion && $tiene_owner && $tiene_entidad) {
        $ins_cols = "nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, cod_org, owner_user_id, entidad, cuenta_id, invitacion, normas, afiche";
        $ins_vals = ":nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas, :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :cod_org, :owner_user_id, :entidad, :cuenta_id, '', '', ''";
        if ($tiene_permite_inscripcion) {
            $ins_cols .= ", permite_inscripcion_linea";
            $ins_vals .= ", :permite_inscripcion_linea";
        }
        if ($tiene_publicar_landing) {
            $ins_cols .= ", publicar_landing";
            $ins_vals .= ", :publicar_landing";
        }
        if ($tiene_hora_torneo) {
            $ins_cols .= ", hora_torneo";
            $ins_vals .= ", :hora_torneo";
        }
        if ($tiene_tipo_torneo) {
            $ins_cols .= ", tipo_torneo";
            $ins_vals .= ", :tipo_torneo";
        }
        if ($tiene_parent_event_id) {
            $ins_cols .= ", parent_event_id";
            $ins_vals .= ", :parent_event_id";
        }
        $stmt = DB::pdo()->prepare("INSERT INTO tournaments ($ins_cols) VALUES ($ins_vals)");
        
        $exec_params = [
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':cod_org' => $organizacion_id,
            ':owner_user_id' => $owner_user_id,
            ':entidad' => $entidad,
            ':cuenta_id' => $cuenta_id
        ];
        if ($tiene_permite_inscripcion) {
            $exec_params[':permite_inscripcion_linea'] = $permite_inscripcion_linea;
        }
        if ($tiene_publicar_landing) {
            $exec_params[':publicar_landing'] = $publicar_landing;
        }
        if ($tiene_hora_torneo) {
            $exec_params[':hora_torneo'] = $hora_torneo;
        }
        if ($tiene_tipo_torneo) {
            $exec_params[':tipo_torneo'] = $tipo_torneo === null ? 0 : $tipo_torneo;
        }
        if ($tiene_parent_event_id) {
            $exec_params[':parent_event_id'] = 0;
        }
        $stmt->execute($exec_params);
    } elseif ($tiene_organizacion && $tiene_owner) {
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, cod_org, owner_user_id, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :cod_org, :owner_user_id, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':cod_org' => $organizacion_id,
            ':owner_user_id' => $owner_user_id,
            ':cuenta_id' => $cuenta_id
        ]);
    } elseif ($tiene_owner && $tiene_entidad) {
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, owner_user_id, entidad, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :owner_user_id, :entidad, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':owner_user_id' => $owner_user_id,
            ':entidad' => $entidad,
            ':cuenta_id' => $cuenta_id
        ]);
    } elseif ($tiene_owner) {
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, owner_user_id, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :owner_user_id, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':owner_user_id' => $owner_user_id,
            ':cuenta_id' => $cuenta_id
        ]);
    } elseif ($tiene_organizacion && $tiene_entidad) {
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, cod_org, entidad, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :cod_org, :entidad, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':cod_org' => $organizacion_id,
            ':entidad' => $entidad,
            ':cuenta_id' => $cuenta_id
        ]);
    } elseif ($tiene_organizacion) {
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, cod_org, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :cod_org, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':cod_org' => $organizacion_id,
            ':cuenta_id' => $cuenta_id
        ]);
    } elseif ($tiene_entidad) {
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, entidad, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :entidad, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':entidad' => $entidad,
            ':cuenta_id' => $cuenta_id
        ]);
    } else {
        // Sin columna cod_org ni owner_user_id (esquema antiguo)
        $stmt = DB::pdo()->prepare("
            INSERT INTO tournaments (
                nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, 
                costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable, cuenta_id" . ($tiene_parent_event_id ? ', parent_event_id' : '') . ", invitacion, normas, afiche
            ) VALUES (
                :nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas,
                :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable, :cuenta_id" . ($tiene_parent_event_id ? ', 0' : '') . ", '', '', ''
            )
        ");
        
        $stmt->execute([
            ':nombre' => $nombre,
            ':fechator' => $fechator,
            ':lugar' => $lugar,
            ':clase' => $clase,
            ':modalidad' => $modalidad,
            ':tiempo' => $tiempo,
            ':puntos' => $puntos,
            ':rondas' => $rondas,
            ':costo' => $costo,
            ':ranking' => $ranking,
            ':pareclub' => $pareclub,
            ':estatus' => $estatus,
            ':es_evento_masivo' => $es_evento_masivo,
            ':club_responsable' => $club_responsable,
            ':cuenta_id' => $cuenta_id
        ]);
    }
    
    // Obtener el ID del torneo reción creado
    $tournament_id = (int)DB::pdo()->lastInsertId();

    // Notificar a delegados de cada asociación (club) para inscripción / afiliación / carnets
    if ($tournament_id > 0 && in_array($user_role, ['admin_club', 'admin_general'], true)) {
        try {
            require_once __DIR__ . '/../../lib/TournamentCreatedNotifier.php';
            TournamentCreatedNotifier::notifyAssociationDelegates(DB::pdo(), $tournament_id, $user_id);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('notifyAssociationDelegates: ' . $e->getMessage());
            }
        }
    }

    // Actualizar permite_inscripcion_linea y publicar_landing si las columnas existen (para branches que no las incluyen en INSERT)
    if ($tiene_permite_inscripcion) {
        $stmt_perm = DB::pdo()->prepare("UPDATE tournaments SET permite_inscripcion_linea = ? WHERE id = ?");
        $stmt_perm->execute([$permite_inscripcion_linea, $tournament_id]);
    }
    if ($tiene_publicar_landing) {
        $stmt_pub = DB::pdo()->prepare("UPDATE tournaments SET publicar_landing = ? WHERE id = ?");
        $stmt_pub->execute([$publicar_landing, $tournament_id]);
    }
    
    // Generar PDF de invitación automáticamente
    try {
        require_once __DIR__ . '/../../lib/InvitationPDFGenerator.php';
        $pdf_result = InvitationPDFGenerator::generateTournamentInvitationPDF($tournament_id);
        if ($pdf_result['success']) {
            error_log("PDF de invitación generado para torneo {$tournament_id}: " . $pdf_result['pdf_path']);
        } else {
            error_log("Error generando PDF de invitación para torneo {$tournament_id}: " . ($pdf_result['error'] ?? 'Error desconocido'));
        }
    } catch (Exception $e) {
        error_log("Excepción al generar PDF de invitación para torneo {$tournament_id}: " . $e->getMessage());
        // No fallar la creación del torneo si falla el PDF
    }
    
    // Procesar archivos si se subieron
    $file_updates = [];
    $file_fields = ['invitacion', 'normas', 'afiche'];
    
    foreach ($file_fields as $field) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            try {
                // Subir archivo con el ID del torneo
                $file_path = FileUpload::uploadTournamentFile($_FILES[$field], $field, $tournament_id);
                $file_updates[$field] = $file_path;
            } catch (Exception $e) {
                // Si falla la subida, continuar con los demás archivos
                error_log("Error al subir $field para torneo $tournament_id: " . $e->getMessage());
            }
        }
    }
    
    // Actualizar torneo con las rutas de archivos si se subieron
    if (!empty($file_updates)) {
        $update_parts = [];
        foreach ($file_updates as $field => $path) {
            $update_parts[] = "$field = :$field";
        }
        
        $update_sql = "UPDATE tournaments SET " . implode(', ', $update_parts) . " WHERE id = :id";
        $stmt_update = DB::pdo()->prepare($update_sql);
        
        $file_updates['id'] = $tournament_id;
        $stmt_update->execute($file_updates);
    }
    
    // Redirigir con éxito a la lista de torneos
    $success_msg = 'Torneo creado exitosamente';
    $_SESSION['success'] = $success_msg;
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $redirect_url = $script_path . '?page=tournaments&success=' . urlencode($success_msg);
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) {
        header('Location: ' . $redirect_url, true, 302);
        exit;
    }
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '"><p>Torneo creado. Redirigiendo...</p>';
    exit;

} catch (Exception $e) {
    $error_msg = $e->getMessage();
    error_log('Tournaments save error: ' . $error_msg);
    $_SESSION['error'] = $error_msg;
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $redirect_url = $script_path . '?page=tournaments&action=new&error=' . urlencode($error_msg);
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) {
        header('Location: ' . $redirect_url, true, 302);
        exit;
    }
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '"><p>Error: ' . htmlspecialchars($error_msg) . '. Redirigiendo...</p>';
    exit;
}

