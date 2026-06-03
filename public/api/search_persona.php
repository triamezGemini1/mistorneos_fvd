<?php
/**
 * Búsqueda de persona por nacionalidad + cédula. Cuatro bloques separados, cada uno con una acción clara.
 * Usado por: Formulario de Invitación e Inscripción en Sitio.
 *
 * Parámetros: cedula, nacionalidad, torneo_id (opcional; si > 0 se ejecuta bloque INSCRITO).
 *
 * BLOQUE 1 - INSCRITO: Buscar en inscritos. Si existe → accion "ya_inscrito": mensaje, front limpia formulario y foco nacionalidad.
 * BLOQUE 2 - USUARIO: Buscar en usuarios. Si existe → accion "encontrado_usuario": persona con id; front rellena y permite inscribir.
 * BLOQUE 3 - PERSONAS: Buscar en base externa. Si existe → accion "encontrado_persona": persona sin id; front rellena y permite inscribir (al enviar se crea usuario).
 * BLOQUE 4 - NUEVO: No encontrado → accion "nuevo": front mantiene nacionalidad y cédula, limpia resto, foco nombre; al enviar se crea usuario e inscribe.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../config/bootstrap.php';
    require_once __DIR__ . '/../../config/db_config.php';
    require_once __DIR__ . '/../../config/auth.php';
    require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
} catch (Throwable $e) {
    error_log("search_persona.php init: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'accion' => 'error',
        'status' => 'error',
        'encontrado' => false,
        'mensaje' => 'Error al conectar. Intente de nuevo.',
        'error' => $e->getMessage()
    ]);
    exit;
}

$input = array_merge($_GET, $_POST);
$nacionalidad = isset($input['nacionalidad']) ? strtoupper(trim((string) $input['nacionalidad'])) : 'V';
if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
    $nacionalidad = 'V';
}
$torneo_id = isset($input['torneo_id']) ? (int) $input['torneo_id'] : (isset($input['torneo']) ? (int) $input['torneo'] : 0);
$cedula_raw = isset($input['cedula']) ? trim((string) $input['cedula']) : '';
if ($cedula_raw === '' && isset($input['busqueda'])) {
    $cedula_raw = trim((string) $input['busqueda']);
}
$userIdParam = (int) ($input['user_id'] ?? 0);
$qNombre = trim((string) ($input['q'] ?? $input['nombre'] ?? ''));

error_log("search_persona.php - ENTRADA: nacionalidad=" . $nacionalidad . ", raw=" . $cedula_raw . ", torneo_id=" . $torneo_id . ", user_id=" . $userIdParam . ", q=" . $qNombre);

$cedula = preg_replace('/^[VEJP]/i', '', $cedula_raw);
$cedula = preg_replace('/\D/', '', $cedula);
// Campo único "busqueda": si no hay dígitos, tratar como nombre (no como cédula vacía).
if ($qNombre === '' && $cedula === '' && $cedula_raw !== '') {
    $soloDig = preg_replace('/\D/', '', $cedula_raw);
    if ($soloDig === '') {
        $qNombre = trim($cedula_raw);
    }
}

try {
    $pdo = DB::pdo();

    $inscribirSitio = !empty($input['inscribir_sitio']) || !empty($input['sitio']);
    $clubFiltroPost = (int) ($input['id_club'] ?? $input['club_id'] ?? 0);
    $clubFiltro = AsociacionAdminHelper::clubFiltroInscripcionSitio(
        $pdo,
        $clubFiltroPost > 0 ? $clubFiltroPost : null
    );
    $filtroAmbitoSql = static function (string $alias = 'u') use ($pdo, $clubFiltro): array {
        if ($clubFiltro !== null && $clubFiltro > 0) {
            return AsociacionAdminHelper::filtroSqlUsuariosPorClub($clubFiltro, $alias);
        }

        return AsociacionAdminHelper::filtroSqlUsuariosAsociacion($pdo, $alias);
    };
    $rechazarSiFueraAsociacion = static function (int $uid) use ($pdo, $clubFiltro, $inscribirSitio): bool {
        if ($uid <= 0) {
            return false;
        }
        if ($clubFiltro !== null && $clubFiltro > 0) {
            return !AsociacionAdminHelper::usuarioPerteneceAClub($pdo, $uid, $clubFiltro);
        }

        return $inscribirSitio && !AsociacionAdminHelper::usuarioEnAmbitoAsociacion($pdo, $uid);
    };

    $emitirEncontradoUsuario = static function (array $persona, string $cRef) use ($pdo, $rechazarSiFueraAsociacion, $clubFiltro): void {
        $uid = (int) ($persona['id'] ?? 0);
        if ($rechazarSiFueraAsociacion($uid)) {
            $msg = ($clubFiltro !== null && $clubFiltro > 0)
                ? 'El atleta no pertenece a la asociación seleccionada.'
                : 'El atleta no pertenece a su asociación.';
            echo json_encode([
                'accion' => 'error',
                'status' => 'error',
                'mensaje' => $msg,
                'error' => 'fuera_ambito',
                'encontrado' => false,
            ]);
            exit;
        }
        $clubRes = AsociacionAdminHelper::resolverClubAtletaDesdeUsuario($pdo, $persona);
        $fechnac = $persona['fechnac'] ?? '';
        if ($fechnac && preg_match('/^\d{4}-\d{2}-\d{2}/', $fechnac) === false && strtotime($fechnac) !== false) {
            $fechnac = date('Y-m-d', strtotime($fechnac));
        }
        $celular = $persona['celular'] ?? '';
        echo json_encode([
            'accion' => 'encontrado_usuario',
            'status' => 'encontrado',
            'encontrado' => true,
            'fuente' => 'usuarios',
            'existe_en_usuarios' => true,
            'mensaje' => 'Datos encontrados en la plataforma. Revise la asociación y pulse Inscribir.',
            'persona' => [
                'id' => (int) ($persona['id'] ?? 0),
                'username' => $persona['username'] ?? '',
                'nacionalidad' => $persona['nacionalidad'] ?? 'V',
                'nombre' => $persona['nombre'] ?? '',
                'cedula' => $persona['cedula'] ?? $cRef,
                'sexo' => $persona['sexo'] ?? '',
                'fechnac' => $fechnac,
                'celular' => $celular,
                'telefono' => $celular,
                'email' => $persona['email'] ?? '',
                'club_id' => (int) ($clubRes['club_id'] ?? 0),
                'club_nombre' => (string) ($clubRes['club_nombre'] ?? ''),
            ],
        ]);
        exit;
    };

    $exigirClubInscripcionSitio = static function () use ($inscribirSitio, $torneo_id, $clubFiltro): ?array {
        if (!$inscribirSitio || $torneo_id <= 0 || $clubFiltro !== null) {
            return null;
        }

        return [
            'accion' => 'error',
            'status' => 'error',
            'mensaje' => 'Seleccione la asociación en el panel antes de buscar.',
            'error' => 'club_requerido',
            'encontrado' => false,
        ];
    };

    // ─── BLOQUE 0a: ID de usuario (inscripción en sitio: búsqueda por ID) ───
    if ($userIdParam > 0) {
        if ($errClub = $exigirClubInscripcionSitio()) {
            echo json_encode($errClub);
            exit;
        }
        if ($torneo_id <= 0) {
            http_response_code(400);
            echo json_encode([
                'accion' => 'error',
                'status' => 'error',
                'mensaje' => 'Indique torneo_id para buscar por ID de usuario.',
                'error' => 'torneo_id requerido',
            ]);
            exit;
        }
        $stmtI = $pdo->prepare('SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
        $stmtI->execute([$torneo_id, $userIdParam]);
        if ($stmtI->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                'accion' => 'ya_inscrito',
                'status' => 'ya_inscrito',
                'mensaje' => 'El jugador ya está en este torneo.',
                'encontrado' => false,
            ]);
            exit;
        }
        [$sqlAmbitoId, $paramsAmbitoId] = $filtroAmbitoSql('u');
        $stmtU = $pdo->prepare(
            'SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad
             FROM usuarios u WHERE u.id = ?' . $sqlAmbitoId . ' LIMIT 1'
        );
        $stmtU->execute(array_merge([$userIdParam], $paramsAmbitoId));
        $persona = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($persona) {
            error_log('search_persona.php - BLOQUE ID: ENCONTRADO id=' . $userIdParam);
            $emitirEncontradoUsuario($persona, (string) ($persona['cedula'] ?? ''));
        }
        // Sin fila con ese PK: seguir (p. ej. número largo era cédula, no id).
    }

    // ─── BLOQUE 0b: Nombre o usuario (fragmento, mín. 3 caracteres) ───
    $lenQ = function_exists('mb_strlen') ? mb_strlen($qNombre, 'UTF-8') : strlen($qNombre);
    if ($qNombre !== '' && $lenQ >= 3) {
        if ($errClub = $exigirClubInscripcionSitio()) {
            echo json_encode($errClub);
            exit;
        }
        if ($torneo_id <= 0) {
            http_response_code(400);
            echo json_encode([
                'accion' => 'error',
                'status' => 'error',
                'mensaje' => 'Indique torneo_id para buscar por nombre.',
                'error' => 'torneo_id requerido',
            ]);
            exit;
        }
        $like = '%' . addcslashes($qNombre, '%_\\') . '%';
        [$sqlAmbito, $paramsAmbito] = $filtroAmbitoSql('u');
        $stmtN = $pdo->prepare("
            SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad
            FROM usuarios u
            WHERE (u.nombre LIKE ? OR u.username LIKE ?)
            {$sqlAmbito}
            LIMIT 2
        ");
        $stmtN->execute(array_merge([$like, $like], $paramsAmbito));
        $rows = $stmtN->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            error_log('search_persona.php - BLOQUE NOMBRE: ENCONTRADO (' . ($rows[0]['nombre'] ?? '') . ')');
            $emitirEncontradoUsuario($rows[0], (string) ($rows[0]['cedula'] ?? ''));
        }
        if (count($rows) > 1) {
            echo json_encode([
                'accion' => 'error',
                'status' => 'error',
                'mensaje' => 'Hay varios jugadores con ese criterio. Use cédula o ID de usuario.',
                'error' => 'multiple',
            ]);
            exit;
        }
        echo json_encode([
            'accion' => 'error',
            'status' => 'error',
            'mensaje' => 'No se encontró un jugador con ese nombre. Pruebe con cédula o ID.',
            'error' => 'nombre no encontrado',
        ]);
        exit;
    }

    if ($cedula === '') {
        http_response_code(400);
        echo json_encode([
            'accion' => 'error',
            'status' => 'error',
            'mensaje' => 'Indique cédula (números), ID de usuario o al menos 3 letras del nombre/apellido.',
            'error' => 'Parámetro de búsqueda requerido',
        ]);
        exit;
    }

    // ─── BLOQUE 1: INSCRITO. Si está inscrito → ya_inscrito.
    // 1a) Por torneo_id + nacionalidad + cédula (réplica en inscritos).
    // 1b) Si no hay fila: muchas inscripciones tienen cedula vacía en inscritos; entonces por id_usuario
    //     resuelto desde usuarios (mismas variantes que BLOQUE USUARIO).
    if ($torneo_id > 0) {
        error_log("search_persona.php - BLOQUE INSCRITO: Buscando en inscritos (torneo_id=" . $torneo_id . ", nac=" . $nacionalidad . ", cedula=" . $cedula . ")");
        try {
            $row = null;
            $stmt = $pdo->prepare("
                SELECT id FROM inscritos
                WHERE torneo_id = ? AND nacionalidad = ? AND cedula = ?
                LIMIT 1
            ");
            $stmt->execute([$torneo_id, $nacionalidad, $cedula]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row && $cedula !== '') {
                $stmt2 = $pdo->prepare("
                    SELECT id FROM inscritos
                    WHERE torneo_id = ? AND cedula = ?
                    LIMIT 1
                ");
                $stmt2->execute([$torneo_id, $cedula]);
                $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$row) {
                $variantes = array_unique([$cedula, $nacionalidad . $cedula]);
                foreach ($variantes as $c) {
                    if ($c === '') {
                        continue;
                    }
                    $stmtU = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
                    $stmtU->execute([$c]);
                    $uid = (int) ($stmtU->fetchColumn() ?: 0);
                    if ($uid > 0) {
                        $stmtI = $pdo->prepare('SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
                        $stmtI->execute([$torneo_id, $uid]);
                        $row = $stmtI->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            error_log("search_persona.php - BLOQUE INSCRITO: YA_INSCRITO por id_usuario=" . $uid . " (inscritos.cedula puede estar vacía)");
                            break;
                        }
                    }
                }
            }
            if ($row) {
                error_log("search_persona.php - BLOQUE INSCRITO: YA_INSCRITO (id=" . ($row['id'] ?? '') . ")");
                echo json_encode([
                    'accion' => 'ya_inscrito',
                    'status' => 'ya_inscrito',
                    'mensaje' => 'El jugador ya está en este torneo. Puede ingresar otra cédula.',
                    'encontrado' => false
                ]);
                exit;
            }
            error_log("search_persona.php - BLOQUE INSCRITO: no encontrado, continuar a BLOQUE USUARIO");
        } catch (Throwable $e) {
            error_log("search_persona.php - BLOQUE INSCRITO excepcion: " . $e->getMessage());
        }
    } else {
        error_log("search_persona.php - BLOQUE INSCRITO omitido (torneo_id=0), continuar a BLOQUE USUARIO");
    }

    // ─── BLOQUE 2: USUARIO. Si existe en usuarios → una sola acción: encontrado_usuario (persona con id; front rellena y permite inscribir). ───
    if ($errClub = $exigirClubInscripcionSitio()) {
        echo json_encode($errClub);
        exit;
    }
    error_log("search_persona.php - BLOQUE USUARIO: Buscando en usuarios (cedula variantes)");
    $procesarPersonaCedula = static function (array $persona, string $cRef) use (
        $emitirEncontradoUsuario,
        $rechazarSiFueraAsociacion,
        $clubFiltro
    ): void {
        $uid = (int) ($persona['id'] ?? 0);
        if ($rechazarSiFueraAsociacion($uid)) {
            $msg = ($clubFiltro !== null && $clubFiltro > 0)
                ? 'El atleta está registrado en otra asociación. Seleccione la asociación correcta en el panel si desea inscribirlo con otro código.'
                : 'El atleta no pertenece a su asociación.';
            echo json_encode([
                'accion' => 'error',
                'status' => 'error',
                'mensaje' => $msg,
                'error' => 'fuera_ambito',
                'encontrado' => false,
            ]);
            exit;
        }
        $emitirEncontradoUsuario($persona, $cRef);
    };
    $cedula_variantes = array_unique([$cedula, $nacionalidad . $cedula]);
    foreach ($cedula_variantes as $c) {
        if ($c === '') continue;
        try {
            $stmt = $pdo->prepare(
                'SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad
                 FROM usuarios WHERE cedula = ? LIMIT 1'
            );
            $stmt->execute([$c]);
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($persona) {
                error_log("search_persona.php - BLOQUE USUARIO: ENCONTRADO (" . ($persona['nombre'] ?? '') . ")");
                $procesarPersonaCedula($persona, $c);
            }
        } catch (Throwable $e) {
            error_log("search_persona.php - BLOQUE USUARIO excepcion: " . $e->getMessage());
        }
    }
    try {
        $stmtNorm = $pdo->prepare("
            SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad
            FROM usuarios
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), '-', ''), '.', ''), ' ', ''), '/', '') = ?
            LIMIT 1
        ");
        $stmtNorm->execute([$cedula]);
        $personaNorm = $stmtNorm->fetch(PDO::FETCH_ASSOC);
        if ($personaNorm) {
            error_log("search_persona.php - BLOQUE USUARIO (normalizado): ENCONTRADO (" . ($personaNorm['nombre'] ?? '') . ")");
            $procesarPersonaCedula($personaNorm, (string) ($personaNorm['cedula'] ?? $cedula));
        }
    } catch (Throwable $e) {
        error_log("search_persona.php - BLOQUE USUARIO normalizado: " . $e->getMessage());
    }
    error_log("search_persona.php - BLOQUE USUARIO: no encontrado, continuar a BLOQUE PERSONAS");

    // ─── BLOQUE 3: PERSONAS (solo si DB_SECONDARY_* está configurada; conexión bajo demanda). ───
    if (file_exists(__DIR__ . '/../../config/persona_database.php')) {
        require_once __DIR__ . '/../../config/persona_database.php';
        if (PersonaDatabase::isConfigured()) {
            error_log('search_persona.php - BLOQUE PERSONAS: Buscando en base externa');
            @set_time_limit(15);
            try {
                $p = PersonaDatabase::buscarPorCedula($nacionalidad, $cedula);
                if ($p !== null) {
                    error_log('search_persona.php - BLOQUE PERSONAS: ENCONTRADO en base externa');
                    $cel = $p['celular'] ?? $p['telefono'] ?? '';
                    $fechnac = $p['fechnac'] ?? '';
                    if ($fechnac && preg_match('/^\d{4}-\d{2}-\d{2}/', $fechnac) === false && strtotime($fechnac) !== false) {
                        $fechnac = date('Y-m-d', strtotime($fechnac));
                    }
                    echo json_encode([
                        'accion' => 'encontrado_persona',
                        'status' => 'encontrado',
                        'encontrado' => true,
                        'fuente' => 'externa',
                        'existe_en_usuarios' => false,
                        'mensaje' => 'Datos encontrados en base externa. Revise y pulse Inscribir (se creará usuario al inscribir).',
                        'persona' => [
                            'nacionalidad' => $p['nacionalidad'] ?? $nacionalidad,
                            'nombre' => $p['nombre'] ?? '',
                            'sexo' => $p['sexo'] ?? '',
                            'fechnac' => $fechnac,
                            'celular' => $cel,
                            'telefono' => $cel,
                            'email' => $p['email'] ?? '',
                        ],
                    ]);
                    exit;
                }
                error_log('search_persona.php - BLOQUE PERSONAS: no encontrado');
            } catch (Throwable $e) {
                error_log('search_persona.php - BLOQUE PERSONAS excepcion: ' . $e->getMessage());
            }
        } else {
            error_log('search_persona.php - BLOQUE PERSONAS omitido (BD personas no configurada)');
        }
    } else {
        error_log('search_persona.php - BLOQUE PERSONAS omitido (sin config), continuar a BLOQUE NUEVO');
    }

    // ─── BLOQUE 4: NUEVO. No encontrado en ninguno → una sola acción: nuevo (front mantiene nacionalidad y cédula, limpia resto, foco nombre; al enviar se crea usuario e inscribe). ───
    error_log("search_persona.php - BLOQUE NUEVO: no encontrado en inscritos, usuarios ni personas");
    echo json_encode([
        'accion' => 'nuevo',
        'status' => 'no_encontrado',
        'encontrado' => false,
        'mensaje' => 'No encontrado. Complete nombre y el resto de datos; al pulsar Inscribir se creará el usuario y se inscribirá en el torneo.'
    ]);

} catch (Throwable $e) {
    error_log("search_persona.php - Error general: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'accion' => 'error',
        'status' => 'error',
        'encontrado' => false,
        'mensaje' => 'Error interno del servidor.',
        'error' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
}
