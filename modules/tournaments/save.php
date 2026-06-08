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
    if (empty(trim((string) ($_POST['hora_torneo'] ?? '')))) {
        throw new Exception('La hora del torneo es requerida');
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

    // Guardar valores numéricos directamente (la tabla usa INT, no ENUM)
    $clase = (int)$_POST['clase']; // 1 = Torneo, 2 = Campeonato
    $modalidad = (int)$_POST['modalidad']; // 1 = Individual, 2 = Parejas, 3 = Equipos
    $campeonato_tipo = trim((string) ($_POST['campeonato_tipo'] ?? ''));
    if ($clase === 2) {
        require_once __DIR__ . '/../../lib/CampeonatoTorneoHelper.php';
        if (!in_array($campeonato_tipo, [CampeonatoTorneoHelper::TIPO_GENERO, CampeonatoTorneoHelper::TIPO_CATEGORIA_SUB], true)) {
            throw new Exception('Para campeonato debe seleccionar el tipo: por género o por categoría SUB');
        }
    } else {
        $campeonato_tipo = '';
    }
    $tiempo = (int)($_POST['tiempo'] ?? 0);
    $puntos = (int)($_POST['puntos'] ?? 0);
    if ($puntos <= 0) {
        $puntos = 200; // El torneo no puede tener 0 puntos; por defecto 200
    }
    $rondas = (int)($_POST['rondas'] ?? 0);
    if ($rondas <= 0) {
        throw new Exception('Las rondas del torneo son requeridas (mínimo 1)');
    }

    // Evitar duplicados: mismo nombre, misma fecha, mismo lugar (solo torneo único)
    require_once __DIR__ . '/../../lib/CampeonatoTorneoHelper.php';
    $variantes_campeonato = CampeonatoTorneoHelper::variantes($clase, $campeonato_tipo, $modalidad, $rondas);
    if ($variantes_campeonato === null) {
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
    }
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
    // Hora del torneo (requerida; NOT NULL en BD)
    require_once __DIR__ . '/../../lib/TournamentCreateHelper.php';
    $hora_torneo = TournamentCreateHelper::normalizeHoraTorneo($_POST['hora_torneo'] ?? null);
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
    
    // Insertar torneo(s) en BD
    require_once __DIR__ . '/../../lib/TournamentCreateHelper.php';
    $pdo = DB::pdo();
    $owner_user_id = $user_id;

    $baseData = [
        'fechator' => $fechator,
        'lugar' => $lugar,
        'clase' => $clase,
        'modalidad' => $modalidad,
        'tiempo' => $tiempo,
        'puntos' => $puntos,
        'rondas' => $rondas,
        'costo' => $costo,
        'ranking' => $ranking,
        'pareclub' => $pareclub,
        'estatus' => $estatus,
        'es_evento_masivo' => $es_evento_masivo,
        'club_responsable' => $club_responsable,
        'organizacion_id' => $organizacion_id,
        'owner_user_id' => $owner_user_id,
        'entidad' => $entidad,
        'cuenta_id' => $cuenta_id,
        'permite_inscripcion_linea' => $permite_inscripcion_linea,
        'publicar_landing' => $publicar_landing,
        'hora_torneo' => $hora_torneo,
        'tipo_torneo' => $tipo_torneo,
        'parent_event_id' => 0,
        'genero_requerido' => null,
        'edad_maxima' => null,
        'campeonato_grupo' => null,
    ];

    $tournament_ids = [];
    if ($variantes_campeonato !== null) {
        $parentId = 0;
        foreach ($variantes_campeonato as $idx => $variante) {
            $row = $baseData;
            $row['nombre'] = CampeonatoTorneoHelper::nombreConSufijo($nombre, (string) $variante['suffix']);
            $row['rondas'] = $variante['rondas'] ?? $rondas;
            $row['genero_requerido'] = $variante['genero_requerido'];
            $row['edad_maxima'] = $variante['edad_maxima'];
            $row['campeonato_grupo'] = $variante['campeonato_grupo'];
            $row['parent_event_id'] = $idx === 0 ? 0 : $parentId;
            $newId = TournamentCreateHelper::create($pdo, $row);
            if ($idx === 0) {
                $parentId = $newId;
            }
            $tournament_ids[] = $newId;
        }
    } else {
        $row = $baseData;
        $row['nombre'] = $nombre;
        $tournament_ids[] = TournamentCreateHelper::create($pdo, $row);
    }

    $tournament_id = (int) ($tournament_ids[0] ?? 0);

    // Notificar a delegados por cada torneo creado
    if (!empty($tournament_ids) && in_array($user_role, ['admin_club', 'admin_general'], true)) {
        try {
            require_once __DIR__ . '/../../lib/TournamentCreatedNotifier.php';
            foreach ($tournament_ids as $tidNotify) {
                TournamentCreatedNotifier::notifyAssociationDelegates($pdo, (int) $tidNotify, $user_id);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('notifyAssociationDelegates: ' . $e->getMessage());
            }
        }
    }

    // PDF de invitación para el torneo principal (y sub-torneos si aplica)
    foreach ($tournament_ids as $tidPdf) {
        try {
            require_once __DIR__ . '/../../lib/InvitationPDFGenerator.php';
            $pdf_result = InvitationPDFGenerator::generateTournamentInvitationPDF((int) $tidPdf);
            if (!$pdf_result['success']) {
                error_log("Error generando PDF de invitación para torneo {$tidPdf}: " . ($pdf_result['error'] ?? 'Error desconocido'));
            }
        } catch (Exception $e) {
            error_log("Excepción al generar PDF de invitación para torneo {$tidPdf}: " . $e->getMessage());
        }
    }

    // Procesar archivos: aplicar a todos los torneos del campeonato
    $file_updates = [];
    $file_fields = ['invitacion', 'normas', 'afiche'];
    foreach ($file_fields as $field) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            try {
                $file_path = FileUpload::uploadTournamentFile($_FILES[$field], $field, $tournament_id);
                $file_updates[$field] = $file_path;
            } catch (Exception $e) {
                error_log("Error al subir $field para torneo $tournament_id: " . $e->getMessage());
            }
        }
    }
    if (!empty($file_updates)) {
        $update_parts = [];
        foreach ($file_updates as $field => $path) {
            $update_parts[] = "$field = :$field";
        }
        $update_sql = 'UPDATE tournaments SET ' . implode(', ', $update_parts) . ' WHERE id = :id';
        $stmt_update = $pdo->prepare($update_sql);
        foreach ($tournament_ids as $tidFiles) {
            $paramsFiles = $file_updates;
            $paramsFiles['id'] = (int) $tidFiles;
            $stmt_update->execute($paramsFiles);
        }
    }

    // Redirigir con éxito
    if (count($tournament_ids) > 1) {
        $success_msg = 'Campeonato creado: ' . count($tournament_ids) . ' torneos vinculados.';
    } else {
        $success_msg = 'Torneo creado exitosamente';
    }
    $_SESSION['success'] = $success_msg;
    $script_path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $redirect_url = $script_path . '?page=tournaments&success=' . urlencode($success_msg);
    if (ob_get_level()) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: ' . $redirect_url, true, 302);
        exit;
    }
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '"><p>' . htmlspecialchars($success_msg) . ' Redirigiendo...</p>';
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

