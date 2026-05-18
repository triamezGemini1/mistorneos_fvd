<?php
/**
 * Inscripción Pública para Eventos Masivos
 * Permite a cualquier usuario inscribirse en eventos masivos desde su dispositivo móvil
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';
require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';

/**
 * Busca usuario por cédula (variantes con/sin nacionalidad).
 *
 * @return array<string,mixed>|null
 */
function inscripcion_publica_buscar_usuario(PDO $pdo, string $cedula_num, string $nacionalidad): ?array
{
    if ($cedula_num === '') {
        return null;
    }
    $nac = strtoupper($nacionalidad);
    if (!in_array($nac, ['V', 'E', 'J', 'P'], true)) {
        $nac = 'V';
    }
    $variantes = array_values(array_unique(array_filter([
        $cedula_num,
        $nac . $cedula_num,
    ])));

    foreach ($variantes as $c) {
        $stmt = $pdo->prepare(
            'SELECT id, nombre, email, celular, sexo, fechnac, nacionalidad, entidad, club_id
             FROM usuarios WHERE cedula = ? LIMIT 1'
        );
        $stmt->execute([$c]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT id, nombre, email, celular, sexo, fechnac, nacionalidad, entidad, club_id
         FROM usuarios
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), '-', ''), '.', ''), ' ', ''), '/', '') = ?
         LIMIT 1"
    );
    $stmt->execute([$cedula_num]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Limpia el nombre del torneo (palabras tipo "Masivos").
 */
function limpiarNombreTorneo(?string $nombre): string {
    if (empty($nombre)) {
        return '';
    }
    $nombre = preg_replace('/\bmasivos?\b/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos\s*/i', ' ', $nombre);
    $nombre = preg_replace('/^Masivos\s+/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos$/i', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);

    return trim($nombre);
}

/**
 * Etiquetas de modalidad torneo → texto (landing).
 *
 * @return array<int, string>
 */
function inscripcion_landing_etiquetas_modalidad(): array
{
    return [
        0 => 'No definido',
        1 => 'Individual',
        2 => 'Parejas',
        3 => 'Equipos',
        4 => 'Parejas fijas',
    ];
}

$pdo = DB::pdo();
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

$error = '';
$success = '';
$torneo = null;
$es_hoy_bloqueo = false;
$inscripcion_tarjeta = null;
$inscripcion_tarjeta_replay = false;
$tarjeta_replay_pedida = false;

// Obtener información del torneo
if ($torneo_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                o.nombre as club_nombre,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                COALESCE(o.entidad, 0) as entidad_torneo,
                cb.banco as cuenta_banco,
                cb.numero_cuenta as cuenta_numero,
                cb.tipo_cuenta as cuenta_tipo,
                cb.telefono_afiliado as cuenta_telefono,
                cb.nombre_propietario as cuenta_propietario,
                cb.cedula_propietario as cuenta_cedula
            FROM tournaments t
            {$org_join}
            LEFT JOIN cuentas_bancarias cb ON t.cuenta_id = cb.id
            WHERE t.id = ? AND t.estatus = 1
        ");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$torneo) {
            $error = 'Evento no encontrado o no disponible para inscripción pública';
        } elseif (date('Y-m-d', strtotime($torneo['fechator'])) === date('Y-m-d')) {
            $es_hoy_bloqueo = true;
            $error = 'Las inscripciones en línea están deshabilitadas el día del torneo. Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.';
        } elseif (strtotime($torneo['fechator']) < strtotime('today')) {
            // Torneo ya finalizado: redirigir a resultados con mensaje informativo
            $resultados_url = app_base_url() . '/public/evento_resultados.php?torneo_id=' . $torneo_id . '&msg=' . urlencode('Este torneo ha finalizado. Consulta los resultados oficiales aquí.');
            header('Location: ' . $resultados_url);
            exit;
        } elseif ((int)($torneo['permite_inscripcion_linea'] ?? 1) !== 1) {
            $error = 'Este torneo no acepta inscripciones en línea. Contacta al administrador del club para inscribirte en el sitio del evento.';
        }
    } catch (Exception $e) {
        error_log("Error obteniendo torneo: " . $e->getMessage());
        $error = 'Error al cargar la información del evento';
    }
} else {
    $error = 'Debe especificar un evento';
}

// Procesar inscripción (pública, sin autenticación ni CSRF de sesión)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $torneo) {
    if (!RateLimiter::canSubmit('inscribir_evento_masivo_' . $torneo_id, 10)) {
        $error = 'Espere unos segundos antes de enviar otra inscripción.';
    } elseif (date('Y-m-d', strtotime($torneo['fechator'])) === date('Y-m-d')) {
        $es_hoy_bloqueo = true;
        $error = 'Las inscripciones en línea están deshabilitadas el día del torneo. Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.';
    }
    // Rechazar si el torneo no permite inscripción en línea
    elseif ((int)($torneo['permite_inscripcion_linea'] ?? 1) !== 1) {
        $error = 'Este torneo no acepta inscripciones en línea. Contacta al administrador del club para inscribirte en el sitio del evento.';
    } else {
    $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
    // Cédula solo numérica (nunca concatenar con nacionalidad)
    $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $sexo = strtoupper(trim($_POST['sexo'] ?? ''));
    $fechnac = trim($_POST['fechnac'] ?? '');
    $entidad = (int)($_POST['entidad'] ?? 0);

    if ($apellido !== '') {
        $nombre = trim($nombre . ' ' . $apellido);
    }

    if (empty($cedula) || trim($_POST['nombre'] ?? '') === '' || empty($apellido) || $entidad <= 0) {
        $error = 'Cédula, nombre, apellido y asociación son obligatorios';
    } elseif (!in_array($sexo, ['M', 'F', 'O'], true)) {
        $error = 'Seleccione el sexo';
    } elseif (empty($celular)) {
        $error = 'El teléfono/celular es obligatorio';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido';
    } else {
        try {
            $pdo->beginTransaction();
            
            $nac = in_array(strtoupper($nacionalidad), ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V';
            $usuario_row = inscripcion_publica_buscar_usuario($pdo, $cedula, $nac);
            $es_nuevo_usuario = false;
            $id_usuario = null;
            $id_club_inscripcion = null;

            $credencial_temporal_usuario = null;
            $credencial_temporal_password = null;

            if ($usuario_row) {
                $id_usuario = (int)$usuario_row['id'];

                $stmt = $pdo->prepare('SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
                $stmt->execute([$torneo_id, $id_usuario]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya estás inscrito en este evento');
                }

                if (!empty($usuario_row['club_id']) && (int)$usuario_row['club_id'] > 0) {
                    $id_club_inscripcion = (int)$usuario_row['club_id'];
                } elseif ($entidad > 0) {
                    $id_club_inscripcion = AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $entidad);
                }

                $stmtUpd = $pdo->prepare(
                    'UPDATE usuarios SET nombre = ?, email = ?, celular = ?, sexo = ?, fechnac = ?, entidad = ?, nacionalidad = ?
                     WHERE id = ?'
                );
                $stmtUpd->execute([
                    $nombre,
                    $email !== '' ? $email : null,
                    $celular !== '' ? $celular : null,
                    $sexo,
                    $fechnac !== '' ? $fechnac : null,
                    $entidad > 0 ? $entidad : (int)($usuario_row['entidad'] ?? 0),
                    $nac,
                    $id_usuario,
                ]);
            } else {
                if (!TournamentScopeHelper::pasaAmbitoTerritorialInscripcion($torneo, null, $entidad)) {
                    throw new Exception('Este torneo está fuera de tu ámbito. Puedes inscribirte en el sitio del evento el día del torneo.');
                }

                // Crear usuario nuevo usando Security::createUser
                $es_nuevo_usuario = true;
                
                // Generar username único
                $username = 'user_' . time() . '_' . rand(1000, 9999);
                $password = uniqid('pwd_', true);
                
                // Obtener sexo desde BD externa si no se proporcionó
                if (empty($sexo)) {
                    if (file_exists(__DIR__ . '/../config/persona_database.php')) {
                        require_once __DIR__ . '/../config/persona_database.php';
                        try {
                            $personaDb = new PersonaDatabase();
                            $nacionalidad_busqueda = $nacionalidad;
                            $cedula_numero = $cedula;
                            if (preg_match('/^([VEJP])(\d+)$/i', $cedula, $matches)) {
                                $nacionalidad_busqueda = strtoupper($matches[1]);
                                $cedula_numero = $matches[2];
                            }
                            $result = $personaDb->searchPersonaById($nacionalidad_busqueda, $cedula_numero);
                            if (isset($result['persona']['sexo']) && !empty($result['persona']['sexo'])) {
                                $sexo_raw = strtoupper(trim($result['persona']['sexo']));
                                if ($sexo_raw === 'M' || $sexo_raw === '1' || $sexo_raw === 'MASCULINO') {
                                    $sexo = 'M';
                                } elseif ($sexo_raw === 'F' || $sexo_raw === '2' || $sexo_raw === 'FEMENINO') {
                                    $sexo = 'F';
                                } else {
                                    $sexo = 'O';
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Error al obtener sexo desde BD persona: " . $e->getMessage());
                        }
                    }
                }
                
                $club_id_nuevo = AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $entidad);
                if ($club_id_nuevo === null || $club_id_nuevo <= 0) {
                    throw new Exception('La asociación seleccionada no está registrada como club en el sistema. Contacte a la FVD.');
                }
                $userData = [
                    'username' => $username,
                    'password' => $password,
                    'email' => $email ?: null,
                    'role' => 'usuario',
                    'cedula' => $cedula,
                    'nacionalidad' => in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                    'nombre' => $nombre,
                    'celular' => $celular ?: null,
                    'fechnac' => $fechnac ?: null,
                    'sexo' => $sexo,
                    'entidad' => $entidad,
                    'club_id' => $club_id_nuevo,
                    'status' => 'approved',
                    '_allow_club_for_usuario' => true,
                ];
                if ($club_id_nuevo !== null && $club_id_nuevo > 0) {
                    $id_club_inscripcion = $club_id_nuevo;
                }
                
                $result = Security::createUser($userData);
                
                if (!$result['success']) {
                    throw new Exception('Error al crear usuario: ' . implode(', ', $result['errors']));
                }
                
                $id_usuario = $result['user_id'];
                $credencial_temporal_usuario = $username;
                $credencial_temporal_password = $password;
            }
            
            // Inscribir en el torneo usando función centralizada
            require_once __DIR__ . '/../lib/InscritosHelper.php';
            
            if ($id_club_inscripcion === null && $entidad > 0) {
                $id_club_inscripcion = AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $entidad);
            }

            $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $id_usuario,
                'torneo_id' => $torneo_id,
                'id_club' => $id_club_inscripcion,
                'entidad_id' => $entidad,
                'nacionalidad' => $nac,
                'cedula' => $cedula,
                'estatus' => 0,
                'inscrito_por' => FvdConfig::INSCRITO_POR_LANDING_PUBLICO,
                'numero' => 0,
            ]);
            if (file_exists(__DIR__ . '/../lib/UserActivationHelper.php')) {
                require_once __DIR__ . '/../lib/UserActivationHelper.php';
                UserActivationHelper::activateUser($pdo, $id_usuario);
            }
            $pdo->commit();

            // Resumen visible para el atleta (landing, sin sesión)
            $ent_nombre_inscripcion = '';
            if ($entidad > 0) {
                $stmt_ent_card = $pdo->prepare('SELECT nombre FROM entidad WHERE id = ?');
                $stmt_ent_card->execute([$entidad]);
                $ent_nombre_inscripcion = (string)($stmt_ent_card->fetchColumn() ?: '');
            }

            $stmt_u_card = $pdo->prepare('SELECT username, cedula FROM usuarios WHERE id = ? LIMIT 1');
            $stmt_u_card->execute([$id_usuario]);
            $u_card_row = $stmt_u_card->fetch(PDO::FETCH_ASSOC) ?: [];

            $modalidad_map_card = inscripcion_landing_etiquetas_modalidad();
            $modalidad_int_card = (int)($torneo['modalidad'] ?? 0);

            $raw_fechator_card = (string)($torneo['fechator'] ?? '');
            $ts_tor_card = $raw_fechator_card !== '' ? strtotime($raw_fechator_card) : false;
            $fecha_tarjeta = $ts_tor_card ? date('d/m/Y', $ts_tor_card) : '—';
            $hora_tarjeta = '—';
            if ($raw_fechator_card !== '' && strlen($raw_fechator_card) > 10 && $ts_tor_card) {
                $hora_tarjeta = date('H:i', $ts_tor_card);
            }

            $base_public_card = rtrim(AppHelpers::getPublicUrl(), '/');
            $perfil_acceso_url = $base_public_card . '/entrar_credencial.php?id=' . (int)$id_usuario;
            $qr_perfil_url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
                'size' => '160x160',
                'ecc' => 'M',
                'data' => $perfil_acceso_url,
            ], '', '&', PHP_QUERY_RFC3986);

            $inscripcion_tarjeta = [
                'id_inscripcion' => (int)$id_inscrito,
                'torneo_nombre' => limpiarNombreTorneo($torneo['nombre']),
                'fecha' => $fecha_tarjeta,
                'hora' => $hora_tarjeta,
                'lugar' => trim((string)($torneo['lugar'] ?? '')),
                'modalidad' => $modalidad_map_card[$modalidad_int_card] ?? 'No definido',
                'rondas' => (int)($torneo['rondas'] ?? 0),
                'puntos' => (int)($torneo['puntos'] ?? 0),
                'tiempo' => (int)($torneo['tiempo'] ?? 0),
                'user_id' => (int)$id_usuario,
                'username' => (string)($u_card_row['username'] ?? $credencial_temporal_usuario ?? ''),
                'cedula_mostrar' => $nac . $cedula,
                'atleta_nombre' => $nombre,
                'entidad_nombre' => $ent_nombre_inscripcion,
                'perfil_url' => $perfil_acceso_url,
                'qr_url' => $qr_perfil_url,
                'portal_url' => $base_public_card . '/user_portal.php',
                'password_temporal' => $credencial_temporal_password,
                'es_usuario_nuevo' => $es_nuevo_usuario,
            ];

            $_SESSION['inscripcion_evento_masivo_tarjeta'] = [
                'torneo_id' => $torneo_id,
                'saved_at' => time(),
                'data' => $inscripcion_tarjeta,
            ];
            
            // Enviar notificación por WhatsApp si es nuevo usuario
            if ($es_nuevo_usuario && !empty($torneo['club_telefono'])) {
                try {
                    $telefono = preg_replace('/[^0-9]/', '', $torneo['club_telefono']);
                    if ($telefono && $telefono[0] == '0') {
                        $telefono = substr($telefono, 1);
                    }
                    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
                        $telefono = '58' . $telefono;
                    }
                    
                    $mensaje = "🔔 *NUEVA INSCRIPCIÓN PÚBLICA*\n\n";
                    $mensaje .= "Se ha registrado un nuevo participante en el evento:\n\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                    $mensaje .= "📋 *INFORMACIÓN DEL PARTICIPANTE*\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                    $mensaje .= "👤 *Nombre:* " . $nombre . "\n";
                    $mensaje .= "🆔 *Cédula:* " . $nacionalidad . $cedula . "\n";
                    if ($celular) {
                        $mensaje .= "📱 *Celular:* " . $celular . "\n";
                    }
                    if ($email) {
                        $mensaje .= "📧 *Email:* " . $email . "\n";
                    }
                    $mensaje .= "⚧️ *Sexo:* " . ($sexo === 'M' ? 'Masculino' : ($sexo === 'F' ? 'Femenino' : 'Otro')) . "\n";
                    if ($entidad > 0) {
                        $stmt_ent = $pdo->prepare("SELECT nombre FROM entidad WHERE id = ?");
                        $stmt_ent->execute([$entidad]);
                        $ent_nombre = $stmt_ent->fetchColumn();
                        if ($ent_nombre) {
                            $mensaje .= "📍 *Entidad:* " . $ent_nombre . "\n";
                        }
                    }
                    $mensaje .= "\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                    $mensaje .= "🏆 *INFORMACIÓN DEL EVENTO*\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                    $mensaje .= "📅 *Evento:* " . limpiarNombreTorneo($torneo['nombre']) . "\n";
                    $mensaje .= "📆 *Fecha:* " . date('d/m/Y', strtotime($torneo['fechator'])) . "\n";
                    if ($torneo['lugar']) {
                        $mensaje .= "📍 *Lugar:* " . $torneo['lugar'] . "\n";
                    }
                    $mensaje .= "\n";
                    $mensaje .= "⚠️ *Este es un nuevo usuario registrado en el sistema.*\n";
                    $mensaje .= "Revisa la inscripción en el panel de administración.\n";
                    
                    $mensaje_encoded = urlencode($mensaje);
                    $whatsapp_url = "https://wa.me/{$telefono}?text={$mensaje_encoded}";
                    
                    // Guardar URL para mostrar al usuario
                    $_SESSION['whatsapp_notification_url'] = $whatsapp_url;
                    $_SESSION['whatsapp_notification_telefono'] = $telefono;
                } catch (Exception $e) {
                    error_log("Error al generar notificación WhatsApp: " . $e->getMessage());
                }
            }
            
            $success = 'Te has registrado al torneo ' . limpiarNombreTorneo($torneo['nombre']) . '. Debes formalizar tu inscripción haciendo el pago correspondiente a través de la notificación de pagos o al presentarte al evento.';
            
            // Limpiar formulario
            $_POST = [];
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    }
}

$tarjeta_replay_pedida = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    && isset($_GET['ver_tarjeta'])
    && (string)$_GET['ver_tarjeta'] === '1';
if (
    $tarjeta_replay_pedida && $torneo_id > 0 && $torneo
    && is_array($_SESSION['inscripcion_evento_masivo_tarjeta'] ?? null)
) {
    $snap = $_SESSION['inscripcion_evento_masivo_tarjeta'];
    $snap_ttl = 86400 * 7;
    $snap_ok = (int)($snap['torneo_id'] ?? 0) === $torneo_id
        && isset($snap['data']) && is_array($snap['data'])
        && (($snap_ttl <= 0) || ((time() - (int)($snap['saved_at'] ?? 0)) <= $snap_ttl));
    if ($snap_ok) {
        $inscripcion_tarjeta = $snap['data'];
        $inscripcion_tarjeta_replay = true;
    }
}

// Obtener entidades para el select
$entidades = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM entidad WHERE id > 0 ORDER BY nombre ASC");
    $entidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo entidades: " . $e->getMessage());
}

$hay_tarjeta_guardada_mismo_evento = false;
if ($torneo_id > 0) {
    $snap_chk = $_SESSION['inscripcion_evento_masivo_tarjeta'] ?? null;
    $chk_ttl = 86400 * 7;
    if (
        is_array($snap_chk)
        && (int)($snap_chk['torneo_id'] ?? 0) === $torneo_id
        && !empty($snap_chk['data']) && is_array($snap_chk['data'])
        && (($chk_ttl <= 0) || ((time() - (int)($snap_chk['saved_at'] ?? 0)) <= $chk_ttl))
    ) {
        $hay_tarjeta_guardada_mismo_evento = true;
    }
}
$mostrar_tarjeta_inscripcion = is_array($inscripcion_tarjeta)
    && ($success !== '' || $inscripcion_tarjeta_replay);

$tarjeta_replay_sin_datos_msg = '';
if ($tarjeta_replay_pedida && !$inscripcion_tarjeta_replay && $torneo_id > 0 && $torneo && empty($error)) {
    $tarjeta_replay_sin_datos_msg = 'No hay tarjeta de inscripción guardada en este dispositivo para este evento, o bien expiró. Use el mismo navegador con el que completó la inscripción o conserve el enlace.';
}

$url_ver_tarjeta_de_nuevo = 'inscribir_evento_masivo.php?' . http_build_query([
    'torneo_id' => $torneo_id,
    'ver_tarjeta' => '1',
]);

$torneo_nombre_limpio = $torneo ? limpiarNombreTorneo((string)($torneo['nombre'] ?? '')) : 'Evento';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Inscripción - <?= htmlspecialchars($torneo_nombre_limpio) ?></title>
    
    <link rel="stylesheet" href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::assetVersion('assets/dist/output.css') : 'assets/dist/output.css') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::assetVersion('assets/vendor/fontawesome/css/all.min.css') : 'assets/vendor/fontawesome/css/all.min.css') ?>">
    
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
        }
        .inscripcion-tarjeta {
            font-feature-settings: "kern" 1, "liga" 1;
            -webkit-font-smoothing: antialiased;
        }
        .inscripcion-tarjeta .inscripcion-tarjeta-h1 {
            font-weight: 700;
            letter-spacing: -0.02em;
            text-wrap: balance;
        }
        .inscripcion-tarjeta .inscripcion-tarjeta-lead {
            letter-spacing: 0.01em;
        }
        .inscripcion-tarjeta .inscripcion-torneo-meta {
            font-size: 0.8125rem;
            line-height: 1.45;
            color: #374151;
        }
        @media (min-width: 640px) {
            .inscripcion-tarjeta .inscripcion-torneo-meta {
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-600 via-purple-700 to-indigo-800 min-h-screen">
    
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <!-- Header -->
        <div class="text-center mb-8">
            <a href="landing.php#eventos-masivos" class="inline-flex items-center text-white/90 hover:text-white mb-4">
                <i class="fas fa-arrow-left mr-2"></i> Volver a Eventos
            </a>
            <h2 class="text-3xl md:text-4xl font-bold text-white mb-2">
                <i class="fas fa-user-plus mr-3 text-yellow-400"></i>Inscripción Pública
            </h2>
            <?php if ($torneo): ?>
            <p class="text-white/80 text-lg"><?= htmlspecialchars($torneo_nombre_limpio) ?></p>
            <p class="text-white/70 text-sm mt-2">
                <i class="fas fa-calendar mr-1"></i><?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                <?php if ($torneo['lugar']): ?>
                    <span class="ml-4"><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($torneo['lugar']) ?></span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        
        <!-- Mensajes -->
        <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-3"></i>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
            <?php if ($es_hoy_bloqueo): ?>
            <div class="mt-4">
                <a href="landing.php#eventos-masivos" class="inline-flex items-center bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-arrow-left mr-2"></i>Volver a Eventos
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($tarjeta_replay_sin_datos_msg !== ''): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-lg mb-6 text-sm">
            <i class="fas fa-info-circle mr-2"></i><?= htmlspecialchars($tarjeta_replay_sin_datos_msg) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($mostrar_tarjeta_inscripcion): ?>
        <?php $t = $inscripcion_tarjeta; ?>
        <?php
        $lugar_linea = $t['lugar'] !== '' ? htmlspecialchars($t['lugar']) : '—';
        $torneo_nom_esc = htmlspecialchars($t['torneo_nombre']);
        ?>
        <?php if ($inscripcion_tarjeta_replay): ?>
        <p class="text-center text-sm text-white/90 mb-4 max-w-xl mx-auto">
            <i class="fas fa-mobile-alt mr-1"></i>Vuelve a mostrar la tarjeta guardada en este dispositivo (misma inscripción).
        </p>
        <?php endif; ?>
        <div class="inscripcion-tarjeta bg-white rounded-xl shadow-lg overflow-hidden mb-5 border border-green-600/30 max-w-xl mx-auto text-gray-900 ring-1 ring-black/5">
            <div class="bg-green-600 text-white px-4 py-5 sm:py-6 text-center">
                <h1 class="inscripcion-tarjeta-h1 text-xl sm:text-2xl md:text-[1.65rem] leading-snug px-1"><?= $torneo_nom_esc ?></h1>
                <p class="inscripcion-tarjeta-lead mt-3 sm:mt-4 text-sm sm:text-[0.95rem] text-white/95 font-medium leading-relaxed max-w-md mx-auto">
                    Formaliza tu inscripción y cancela el monto a través de los canales habilitados.
                </p>
            </div>
            <div class="px-4 sm:px-5 py-4 sm:py-5 space-y-3 text-gray-800">
                <p class="inscripcion-torneo-meta text-center font-medium text-gray-700">
                    <span class="tabular-nums"><?= htmlspecialchars($t['fecha']) ?></span>
                    <span class="text-gray-300 mx-1.5">·</span>
                    <span><?= $lugar_linea ?></span>
                    <span class="text-gray-300 mx-1.5">·</span>
                    <span class="tabular-nums"><?= htmlspecialchars($t['hora']) ?></span>
                </p>
                <p class="inscripcion-torneo-meta text-center text-gray-600">
                    <span class="font-semibold text-gray-800"><?= htmlspecialchars($t['modalidad']) ?></span>
                    <span class="text-gray-300 mx-1.5">·</span>
                    <span><?= (int)$t['rondas'] ?> rondas</span>
                    <span class="text-gray-300 mx-1.5">·</span>
                    <span><?= (int)$t['tiempo'] ?> min</span>
                    <span class="text-gray-300 mx-1.5">·</span>
                    <span><?= (int)$t['puntos'] ?> pts</span>
                </p>

                <div class="border-t border-gray-200 pt-4 mt-1 space-y-2.5">
                    <p class="text-center text-sm sm:text-base font-bold text-gray-900 tracking-tight">Datos del atleta</p>
                    <p class="text-center text-sm sm:text-[0.9375rem] leading-relaxed text-gray-900">
                        <span class="text-gray-500 font-medium">Cédula</span>
                        <span class="tabular-nums font-semibold text-gray-900 mx-1"><?= htmlspecialchars($t['cedula_mostrar']) ?></span>
                        <span class="text-gray-300 mx-2">·</span>
                        <span class="text-gray-500 font-medium">Nombre</span>
                        <span class="font-semibold text-gray-900 ml-1"><?= htmlspecialchars($t['atleta_nombre']) ?></span>
                    </p>
                    <p class="text-center text-sm sm:text-[0.9375rem] leading-relaxed text-gray-900">
                        <span class="text-gray-500 font-medium">ID usuario</span>
                        <span class="tabular-nums font-semibold text-gray-900 ml-1"><?= (int)$t['user_id'] ?></span>
                        <?php if ($t['username'] !== ''): ?>
                        <span class="text-gray-300 mx-2">·</span>
                        <span class="text-gray-500 font-medium">Usuario</span>
                        <span class="font-mono font-semibold text-gray-900 text-[0.9em] ml-1"><?= htmlspecialchars($t['username']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-400 text-sm ml-1">(sin usuario asignado)</span>
                        <?php endif; ?>
                    </p>
                </div>

                <?php if (!empty($t['password_temporal']) && !empty($t['es_usuario_nuevo'])): ?>
                <div class="rounded-lg border border-amber-200/80 bg-amber-50/90 px-3 py-2.5 text-xs sm:text-sm">
                    <p class="font-semibold text-amber-950"><i class="fas fa-key mr-1.5 opacity-80"></i>Contraseña temporal <span class="font-normal text-amber-900/90">(solo se muestra ahora)</span></p>
                    <p class="font-mono text-sm mt-1.5 tracking-wide text-amber-950 break-all"><?= htmlspecialchars((string)$t['password_temporal']) ?></p>
                </div>
                <?php endif; ?>

                <div class="border-t border-gray-200 pt-4 flex flex-col sm:flex-row sm:items-start sm:justify-center gap-4 sm:gap-5">
                    <div class="text-center flex-1 min-w-0">
                        <p class="text-xs font-bold text-gray-900 uppercase tracking-wider mb-2">Acceso al perfil y notificaciones</p>
                        <img src="<?= htmlspecialchars($t['qr_url']) ?>" alt="Código QR de acceso al perfil" class="w-[128px] h-[128px] mx-auto rounded-md border border-gray-200/80 bg-white p-1 shadow-sm" loading="lazy" width="128" height="128">
                        <a href="<?= htmlspecialchars($t['perfil_url']) ?>" class="inline-block mt-2 text-[11px] sm:text-xs text-purple-700 font-medium underline-offset-2 hover:underline break-all"><?= htmlspecialchars($t['perfil_url']) ?></a>
                    </div>
                    <div class="flex-1 text-xs sm:text-sm text-gray-600 leading-relaxed space-y-2 text-center sm:text-left sm:pt-7">
                        <p class="font-bold text-gray-900 text-sm">Notificaciones web · Telegram</p>
                        <p>Escanee el QR o abra el enlace. <?= !empty($t['password_temporal']) && !empty($t['es_usuario_nuevo'])
                            ? 'Inicie sesión con su usuario y la contraseña temporal indicada arriba.'
                            : 'Inicie sesión con su usuario y su contraseña habitual.'
                        ?></p>
                        <p class="text-gray-600">En el portal puede vincular <strong class="font-semibold text-gray-800">Telegram</strong> y mantener correo y teléfono al día para avisos.</p>
                        <a href="<?= htmlspecialchars($t['portal_url']) ?>" class="inline-flex items-center gap-1.5 text-indigo-600 font-semibold hover:text-indigo-700 hover:underline">Ir al portal</a>
                    </div>
                </div>
            </div>
            <?php if (isset($_SESSION['whatsapp_notification_url'])): ?>
            <div class="px-4 pb-3 pt-0">
                <div class="p-3 bg-blue-50 rounded-lg border border-blue-200">
                    <p class="text-sm text-blue-800 mb-2"><strong>Organizador:</strong> opcionalmente notifique por WhatsApp.</p>
                    <a href="<?= htmlspecialchars($_SESSION['whatsapp_notification_url']) ?>" class="inline-flex items-center bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600 text-sm">
                        <i class="fab fa-whatsapp mr-2"></i> Abrir WhatsApp
                    </a>
                </div>
            </div>
            <?php
                unset($_SESSION['whatsapp_notification_url'], $_SESSION['whatsapp_notification_telefono']);
            endif; ?>
            <div class="px-4 pb-4 flex flex-col sm:flex-row gap-2 justify-center flex-wrap border-t border-gray-100 pt-3">
                <a href="reportar_pago_evento_masivo.php?torneo_id=<?= (int)$torneo_id ?>&cedula=<?= urlencode((string)$t['cedula_mostrar']) ?>" class="inline-flex items-center justify-center bg-blue-500 text-white px-4 py-1.5 rounded-lg hover:bg-blue-600 text-sm">
                    <i class="fas fa-money-bill-wave mr-2"></i>Reportar pago
                </a>
                <a href="ver_recibo_pago.php?torneo_id=<?= (int)$torneo_id ?>&cedula=<?= urlencode((string)$t['cedula_mostrar']) ?>" class="inline-flex items-center justify-center bg-purple-500 text-white px-4 py-1.5 rounded-lg hover:bg-purple-600 text-sm">
                    <i class="fas fa-receipt mr-2"></i>Ver recibo
                </a>
                <a href="<?= htmlspecialchars($url_ver_tarjeta_de_nuevo) ?>" class="inline-flex items-center justify-center border-2 border-green-600 text-green-700 bg-white px-4 py-1.5 rounded-lg hover:bg-green-50 text-sm font-semibold">
                    <i class="fas fa-id-card mr-2"></i>Ver tarjeta de nuevo
                </a>
                <a href="landing.php#eventos-masivos" class="inline-flex items-center justify-center bg-gray-600 text-white px-4 py-1.5 rounded-lg hover:bg-gray-700 text-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Volver al landing
                </a>
            </div>
            <p class="px-4 pb-3 pt-1 text-[11px] text-center text-gray-500 leading-snug max-w-xl mx-auto">
                Guarde esta página o pulse «Ver tarjeta de nuevo» en este mismo equipo; la copia guardada vale unos días (solo este navegador).
            </p>
        </div>
        <?php elseif ($success): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-3"></i>
                <p><?= htmlspecialchars($success) ?></p>
            </div>
            <div class="mt-4 flex flex-col sm:flex-row gap-3 justify-center">
                <a href="landing.php#eventos-masivos" class="inline-flex items-center justify-center bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600 transition-all">
                    <i class="fas fa-arrow-left mr-2"></i>Volver a Eventos
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($torneo && !$es_hoy_bloqueo && !$mostrar_tarjeta_inscripcion): ?>
        <!-- Condiciones requeridas -->
        <?php 
        $em = (int)($torneo['es_evento_masivo'] ?? 0);
        $req_historial = in_array($em, [2, 3]); // Regional o Local
        ?>
        <?php if ($hay_tarjeta_guardada_mismo_evento): ?>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 mb-5 text-emerald-950 text-sm text-center shadow-sm">
            <i class="fas fa-id-card mr-2 opacity-90"></i>
            Hay una tarjeta de inscripción guardada en <strong class="font-semibold">este dispositivo</strong> para este evento.
            <a href="<?= htmlspecialchars($url_ver_tarjeta_de_nuevo) ?>" class="inline-block mt-2 sm:mt-0 sm:ml-2 font-bold text-emerald-800 underline decoration-2 underline-offset-2 hover:text-emerald-950">Ver tarjeta de nuevo</a>
        </div>
        <?php endif; ?>

        <div class="bg-blue-50 border-l-4 border-blue-500 rounded-r-lg p-4 mb-6">
            <p class="font-bold text-blue-800 mb-2"><i class="fas fa-info-circle mr-2"></i>Condiciones para inscribirte</p>
            <ul class="text-sm text-blue-900 space-y-1 mb-0">
                <li>Cédula, nombre, apellido, sexo, teléfono y asociación son obligatorios.</li>
                <li>Si ya estás registrado, verifica que tu cédula coincida con tus datos.</li>
                <?php if ($req_historial): ?>
                <li><strong>Evento <?= $em === 2 ? 'Regional' : 'Local' ?>:</strong> Debes tener historial de participación previa en eventos del sistema.</li>
                <?php endif; ?>
                <li>Tu inscripción será revisada por los organizadores. Mantén tu celular/email actualizado.</li>
            </ul>
        </div>
        
        <!-- Formulario de Inscripción -->
        <div class="bg-white rounded-2xl shadow-2xl p-6 md:p-8">
            <form method="POST" action="" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-1">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nacionalidad <span class="text-red-500">*</span></label>
                        <select name="nacionalidad" id="nacionalidad" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                            <?php $nac_post = strtoupper((string)($_POST['nacionalidad'] ?? 'V')); ?>
                            <option value="V" <?= $nac_post === 'V' ? 'selected' : '' ?>>V</option>
                            <option value="E" <?= $nac_post === 'E' ? 'selected' : '' ?>>E</option>
                            <option value="J" <?= $nac_post === 'J' ? 'selected' : '' ?>>J</option>
                            <option value="P" <?= $nac_post === 'P' ? 'selected' : '' ?>>P</option>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Cédula <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="cedula" id="cedula" value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>" required
                               onblur="buscarPersona()"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="Solo números o V12345678">
                        <div id="busqueda_resultado" class="mt-2 text-sm"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Nombre <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nombre" id="nombre" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="Ej: Juan">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Apellido <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="apellido" id="apellido" value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="Ej: Pérez">
                    </div>
                </div>

                <div id="bloque-datos-registro" class="space-y-4 border border-purple-100 rounded-xl p-4 bg-purple-50/40">
                    <p class="text-sm font-semibold text-purple-900 mb-0"><i class="fas fa-id-card mr-1"></i>Datos personales y contacto</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Sexo <span class="text-red-500">*</span></label>
                            <select name="sexo" id="sexo" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                <option value="">— Seleccionar —</option>
                                <?php $sexo_post = strtoupper((string)($_POST['sexo'] ?? '')); ?>
                                <option value="M" <?= $sexo_post === 'M' ? 'selected' : '' ?>>Masculino</option>
                                <option value="F" <?= $sexo_post === 'F' ? 'selected' : '' ?>>Femenino</option>
                                <option value="O" <?= $sexo_post === 'O' ? 'selected' : '' ?>>Otro</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Fecha de nacimiento</label>
                            <input type="date" name="fechnac" id="fechnac" value="<?= htmlspecialchars($_POST['fechnac'] ?? '') ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Teléfono / Celular <span class="text-red-500">*</span></label>
                            <input type="text" name="celular" id="celular" value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   placeholder="Ej: 04141234567">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                            <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   placeholder="correo@ejemplo.com">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Asociación (estado/región) <span class="text-red-500">*</span>
                    </label>
                    <select name="entidad" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <option value="0">Seleccione asociación...</option>
                        <?php foreach ($entidades as $ent): ?>
                        <option value="<?= $ent['id'] ?>" <?= (int)($_POST['entidad'] ?? 0) === (int)$ent['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ent['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Botón de Envío -->
                <div class="pt-4">
                    <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 text-white py-4 rounded-lg font-bold text-lg hover:from-purple-700 hover:to-indigo-700 transition-all shadow-lg hover:shadow-xl transform hover:scale-105">
                        <i class="fas fa-paper-plane mr-2"></i>Enviar Inscripción
                    </button>
                </div>
                
                <p class="text-sm text-gray-500 text-center">
                    <i class="fas fa-info-circle mr-1"></i>
                    Tu inscripción será revisada por los organizadores del evento.
                </p>
                
                <!-- Enlaces para reportar pago o ver recibo -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <p class="text-sm font-semibold text-gray-700 mb-3 text-center">Después de inscribirte:</p>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <a href="reportar_pago_evento_masivo.php?torneo_id=<?= $torneo_id ?>&cedula=" 
                           onclick="this.href += encodeURIComponent(document.querySelector('input[name=cedula]').value); return true;"
                           class="flex-1 inline-flex items-center justify-center bg-blue-500 text-white px-4 py-3 rounded-lg hover:bg-blue-600 transition-all text-center">
                            <i class="fas fa-money-bill-wave mr-2"></i>Reportar Pago
                        </a>
                        <a href="ver_recibo_pago.php?torneo_id=<?= $torneo_id ?>&cedula=" 
                           onclick="this.href += encodeURIComponent(document.querySelector('input[name=cedula]').value); return true;"
                           class="flex-1 inline-flex items-center justify-center bg-purple-500 text-white px-4 py-3 rounded-lg hover:bg-purple-600 transition-all text-center">
                            <i class="fas fa-receipt mr-2"></i>Ver Recibo
                        </a>
                    </div>
                </div>
            </form>
        </div>
        <?php elseif (!$torneo && !$success): ?>
        <div class="bg-white rounded-2xl shadow-2xl p-8 text-center">
            <i class="fas fa-exclamation-triangle text-5xl text-red-500 mb-4"></i>
            <h2 class="text-2xl font-bold text-gray-900 mb-2">Evento no disponible</h2>
            <p class="text-gray-600 mb-6"><?= htmlspecialchars($error ?: 'El evento seleccionado no está disponible para inscripción pública') ?></p>
            <a href="landing.php#eventos-masivos" class="inline-block bg-purple-600 text-white px-6 py-3 rounded-lg hover:bg-purple-700 transition-all">
                <i class="fas fa-arrow-left mr-2"></i>Volver a Eventos
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    const baseUrl = '<?= app_base_url() ?>';
    const torneoId = <?= (int)$torneo_id ?>;

    function setCampo(id, valor) {
        const el = document.getElementById(id);
        if (!el || valor === null || valor === undefined || valor === '') return;
        el.value = valor;
    }

    function splitNombreCompleto(full) {
        full = (full || '').trim().replace(/\s+/g, ' ');
        if (!full) return { nombre: '', apellido: '' };
        const i = full.indexOf(' ');
        if (i === -1) return { nombre: full, apellido: '' };
        return { nombre: full.slice(0, i), apellido: full.slice(i + 1).trim() };
    }

    function aplicarDatosPersona(datos) {
        if (!datos) return;
        let nombre = datos.nombre || '';
        let apellido = datos.apellido || '';
        if (!apellido && nombre.indexOf(' ') !== -1) {
            const partes = splitNombreCompleto(nombre);
            nombre = partes.nombre;
            apellido = partes.apellido;
        }
        setCampo('nombre', nombre);
        setCampo('apellido', apellido);
        setCampo('email', datos.email);
        setCampo('celular', datos.celular || datos.telefono);
        setCampo('sexo', datos.sexo);
        setCampo('fechnac', datos.fechnac);
        if (datos.nacionalidad) {
            const nac = document.getElementById('nacionalidad');
            if (nac) nac.value = String(datos.nacionalidad).toUpperCase();
        }
        if (datos.entidad) {
            const ent = document.querySelector('select[name="entidad"]');
            if (ent) ent.value = String(datos.entidad);
        }
    }

    async function buscarPersona() {
        const cedulaEl = document.getElementById('cedula');
        const resultadoDiv = document.getElementById('busqueda_resultado');
        const submitButton = document.querySelector('button[type="submit"]');
        const form = document.querySelector('form');
        if (!cedulaEl || !resultadoDiv) return;

        const cedula = cedulaEl.value.trim();
        const nacEl = document.getElementById('nacionalidad');
        let nacionalidad = (nacEl && nacEl.value) ? nacEl.value : 'V';

        if (!cedula || cedula.length < 5) {
            resultadoDiv.innerHTML = '';
            if (submitButton) submitButton.disabled = false;
            if (form) form.style.opacity = '1';
            return;
        }

        let cedula_limpia = cedula.replace(/\D/g, '');
        const match = cedula.match(/^([VEJP])(\d+)$/i);
        if (match) {
            nacionalidad = match[1].toUpperCase();
            cedula_limpia = match[2];
            if (nacEl) nacEl.value = nacionalidad;
            cedulaEl.value = cedula_limpia;
        }

        resultadoDiv.innerHTML = '<span class="text-blue-600"><i class="fas fa-spinner fa-spin mr-1"></i>Buscando...</span>';

        try {
            const verificarResponse = await fetch(
                baseUrl + '/public/api/verificar_inscripcion.php?cedula=' + encodeURIComponent(cedula_limpia)
                + '&nacionalidad=' + encodeURIComponent(nacionalidad)
                + '&torneo_id=' + torneoId
            );
            if (!verificarResponse.ok) {
                throw new Error('HTTP ' + verificarResponse.status);
            }
            const verificarResult = await verificarResponse.json();

            if (verificarResult.inscrito) {
                resultadoDiv.innerHTML = '<span class="text-red-600 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>'
                    + (verificarResult.mensaje || 'Ya estás inscrito en este evento') + '</span>';
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (form) form.style.opacity = '0.6';
                return;
            }

            let datosCargados = null;
            const datosVerificar = verificarResult.usuario || verificarResult.datos;
            if (datosVerificar) {
                aplicarDatosPersona(datosVerificar);
                datosCargados = datosVerificar;
            }

            // 2) Si no está en usuarios (o faltan datos), buscar en padrón personas (BD externa)
            if (!verificarResult.usuario_existe) {
                const responsePersona = await fetch(
                    baseUrl + '/public/api/search_persona.php?cedula=' + encodeURIComponent(cedula_limpia)
                    + '&nacionalidad=' + encodeURIComponent(nacionalidad)
                    + '&torneo_id=' + torneoId
                );
                if (responsePersona.ok) {
                    const resultPersona = await responsePersona.json();
                    const p = resultPersona.persona || null;
                    if (p && (resultPersona.encontrado || resultPersona.accion === 'encontrado_persona' || resultPersona.accion === 'encontrado_usuario')) {
                        aplicarDatosPersona(p);
                        datosCargados = p;
                        resultadoDiv.innerHTML = '<span class="text-amber-700 font-semibold"><i class="fas fa-user-plus mr-1"></i>Datos encontrados en el padrón. Revise y complete el formulario.</span>';
                    }
                }
            }

            if (verificarResult.usuario_existe) {
                resultadoDiv.innerHTML = '<span class="text-green-600 font-semibold"><i class="fas fa-check-circle mr-1"></i>Usuario registrado. Datos cargados. Puede inscribirse.</span>';
            } else if (!datosCargados) {
                resultadoDiv.innerHTML = '<span class="text-amber-700 font-semibold"><i class="fas fa-user-plus mr-1"></i>No encontrado en el sistema. Complete el formulario manualmente para registrarse e inscribirse.</span>';
            }

            if (submitButton) {
                submitButton.disabled = false;
                submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
            }
            if (form) form.style.opacity = '1';
        } catch (error) {
            console.error('Error en la búsqueda:', error);
            resultadoDiv.innerHTML = '<span class="text-red-600"><i class="fas fa-times-circle mr-1"></i>Error al buscar. Intente de nuevo.</span>';
            if (submitButton) submitButton.disabled = false;
            if (form) form.style.opacity = '1';
        }
    }
    </script>
    
</body>
</html>

