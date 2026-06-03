<?php
/**
 * Controlador: Envío público de resultados de mesa vía QR
 *
 * Recibe por POST: torneo_id, mesa_id, ronda, token, jugadores (puntos, sancion), image, origen (qr|admin).
 * - Origen 'qr': imagen OBLIGATORIA, estatus = pendiente_verificacion, origen_dato = qr.
 * - Valida token vía QrMesaTokenHelper.
 * - Guarda imagen en upload/actas_torneos/acta_T{id}_R{ronda}_M{mesa}_{uniqid}.jpg
 * - UPDATE partiresul con resultados, efectividad, estatus, origen_dato, foto_acta.
 *
 * require_once apuntan a la raíz del proyecto (__DIR__ . '/../').
 */
@set_time_limit(90);
@ignore_user_abort(false);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/QrMesaTokenHelper.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';
require_once __DIR__ . '/../lib/TorneoCampoNumerico.php';
require_once __DIR__ . '/../lib/PartiresulJugadorHelper.php';
require_once __DIR__ . '/../lib/NumfvdHelper.php';

$torneo_id = (int)($_POST['torneo_id'] ?? $_POST['t'] ?? 0);
$mesa_id = (int)($_POST['mesa_id'] ?? $_POST['mesa'] ?? $_POST['m'] ?? 0);
$ronda = (int)($_POST['ronda'] ?? $_POST['partida'] ?? $_POST['r'] ?? 0);
$token = trim((string)($_POST['token'] ?? ''));
$origen = trim((string)($_POST['origen'] ?? 'qr'));
$jugadores_raw = $_POST['jugadores'] ?? [];
$registrado_por = (int)($_POST['registrado_por'] ?? 1);

if (!in_array($origen, ['admin', 'qr'], true)) {
    $origen = 'qr';
}

$jugadores = is_array($jugadores_raw) ? $jugadores_raw : [];

if ($torneo_id <= 0 || $mesa_id <= 0 || $ronda <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan torneo_id, mesa_id o ronda']);
    exit;
}

if ($origen === 'qr' && !QrMesaTokenHelper::validar($torneo_id, $mesa_id, $ronda, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Enlace inválido o expirado. Use el código QR de la hoja de anotación oficial.']);
    exit;
}

if (count($jugadores) !== 4) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debe enviar exactamente 4 jugadores']);
    exit;
}

$imagen_ok = !empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;
if ($origen === 'qr' && !$imagen_ok) {
    $err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msgs = [
        UPLOAD_ERR_INI_SIZE => 'Archivo excede tamaño máximo',
        UPLOAD_ERR_FORM_SIZE => 'Archivo demasiado grande',
        UPLOAD_ERR_PARTIAL => 'Archivo subido parcialmente',
        UPLOAD_ERR_NO_FILE => 'La foto del acta es obligatoria para envíos vía QR',
    ];
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $msgs[$err] ?? 'La foto del acta es obligatoria para envíos vía QR',
    ]);
    exit;
}

$ruta_relativa = null;
if ($imagen_ok) {
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Formato de imagen no permitido. Use JPG o PNG.']);
        exit;
    }
}

try {
    $pdo = DB::pdo();

    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_origen = in_array('origen_dato', $cols);
    $has_estatus = in_array('estatus', $cols);
    $has_foto_acta = in_array('foto_acta', $cols);

    $stmt = $pdo->prepare("SELECT id, estatus, locked, puntos FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo_row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Torneo no encontrado']);
        exit;
    }
    if ((int)($torneo_row['estatus'] ?? 1) !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'El torneo no está activo']);
        exit;
    }
    $locked = (int)($torneo_row['locked'] ?? 0) === 1;
    if ($locked) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Torneo finalizado.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT estatus FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? LIMIT 1");
    $stmt->execute([$torneo_id, $ronda, $mesa_id]);
    $pr_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pr_row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Mesa no encontrada']);
        exit;
    }
    $pr_estatus = $pr_row['estatus'] ?? '';
    if ($has_estatus && PartiresulEstatusSql::valueIsConfirmado($pr_estatus, $pdo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Mesa ya procesada.']);
        exit;
    }

    $puntosTorneo = (int)($torneo_row['puntos'] ?? 200);
    $maximoPermitido = (int)round($puntosTorneo * 1.6);

    // Mismas validaciones que el formulario del administrador
    $puntosA = TorneoCampoNumerico::intEstadistica($jugadores[0]['resultado1'] ?? 0);
    $puntosB = TorneoCampoNumerico::intEstadistica($jugadores[2]['resultado1'] ?? 0);
    if ($puntosA > $maximoPermitido) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Puntos Pareja A ($puntosA) exceden el máximo permitido ($maximoPermitido = puntos del torneo + 60%)."]);
        exit;
    }
    if ($puntosB > $maximoPermitido) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Puntos Pareja B ($puntosB) exceden el máximo permitido ($maximoPermitido = puntos del torneo + 60%)."]);
        exit;
    }
    if ($puntosA === $puntosB && $puntosA > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Los puntos no pueden ser iguales. Debe haber un ganador.']);
        exit;
    }
    if ($puntosA >= $puntosTorneo && $puntosB >= $puntosTorneo) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Solo una pareja puede alcanzar los puntos del torneo ($puntosTorneo)."]);
        exit;
    }

    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'actas_torneos' . DIRECTORY_SEPARATOR;
    if ($imagen_ok) {
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $ext = (strpos($_FILES['image']['type'] ?? '', 'png') !== false) ? 'png' : 'jpg';
        $filename = sprintf('acta_T%d_R%d_M%d_%s.%s', $torneo_id, $ronda, $mesa_id, uniqid(), $ext);
        $dest_path = $upload_dir . $filename;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest_path)) {
            throw new Exception('No se pudo guardar la imagen');
        }
        $ruta_relativa = 'upload/actas_torneos/' . $filename;
    }

    $es_qr = ($origen === 'qr');

    $validarPuntos = fn($p, $pt) => min($p, (int)round($pt * 1.6));
    $efAlcanzo = fn($r1, $r2, $pt) => $r1 == $r2 ? 0 : ($r1 > $r2 ? $pt - $r2 : -($pt - $r1));
    $efNoAlcanzo = fn($r1, $r2) => $r1 == $r2 ? 0 : ($r1 > $r2 ? $r1 - $r2 : -($r2 - $r1));
    $calcularEf = function ($r1, $r2, $pt, $ff, $tarjeta) use ($validarPuntos, $efAlcanzo, $efNoAlcanzo) {
        $r1 = $validarPuntos($r1, $pt);
        $r2 = $validarPuntos($r2, $pt);
        if ($ff == 1) return -$pt;
        if (in_array($tarjeta, [3, 4])) return -$pt;
        $mayor = max($r1, $r2);
        return $mayor >= $pt ? $efAlcanzo($r1, $r2, $pt) : $efNoAlcanzo($r1, $r2);
    };

    $estatus = $origen === 'qr' ? 'pendiente_verificacion' : 'confirmado';
    $estatus_db = PartiresulEstatusSql::estatusValorParaPersistencia($pdo, $origen !== 'qr');

    $tarjeta_previa = [];
    if (!$es_qr) {
        require_once __DIR__ . '/../lib/SancionesHelper.php';
        $ids = array_values(array_filter(array_map(function ($x) { return (int)($x['id_usuario'] ?? 0); }, $jugadores)));
        $tarjeta_previa = SancionesHelper::getTarjetaPreviaDesdePartidasAnteriores($pdo, $torneo_id, $ronda, $ids);
    }

    $pdo->beginTransaction();

    foreach ($jugadores as $j) {
        $id_usuario = (int)($j['id_usuario'] ?? 0);
        $secuencia = (int)($j['secuencia'] ?? 0);
        $resultado1 = TorneoCampoNumerico::intEstadistica($j['resultado1'] ?? 0);
        $resultado2 = TorneoCampoNumerico::intEstadistica($j['resultado2'] ?? 0);
        $ff = isset($j['ff']) && ($j['ff'] == '1' || $j['ff'] === true || $j['ff'] === 'on') ? 1 : 0;
        $chancleta = (int)($j['chancleta'] ?? 0);
        $zapato = (int)($j['zapato'] ?? 0);

        if ($es_qr) {
            $tarjeta = 0;
            $sancion_guardar = 0;
            $resultado1_ajust = $resultado1;
        } else {
            $tarjeta_form = TorneoCampoNumerico::codigoTarjeta($j['tarjeta'] ?? 0);
            $sancion_input = TorneoCampoNumerico::intEstadistica($j['sancion'] ?? 0);
            $sancion_input = min(80, max(0, $sancion_input));
            $tarjeta_inscritos = (int)($tarjeta_previa[$id_usuario] ?? 0);
            $procesado = SancionesHelper::procesar($sancion_input, $tarjeta_form, $tarjeta_inscritos);
            $tarjeta = $procesado['tarjeta'];
            $sancion_guardar = $procesado['sancion_guardar'];
            $sancion_calc = $procesado['sancion_para_calculo'];
            $resultado1_ajust = max(0, $resultado1 - $sancion_calc);
        }
        $efectividad = $calcularEf($resultado1_ajust, $resultado2, $puntosTorneo, $ff, $tarjeta);

        $update_cols = [
            'resultado1 = ?', 'resultado2 = ?', 'efectividad = ?', 'ff = ?', 'tarjeta = ?', 'sancion = ?',
            'chancleta = ?', 'zapato = ?', 'fecha_partida = NOW()', 'registrado_por = ?',
            'registrado = 1',
        ];
        $params = [
            $resultado1, $resultado2, $efectividad, $ff, $tarjeta, $sancion_guardar,
            $chancleta, $zapato, $registrado_por,
        ];
        if ($has_origen) {
            $update_cols[] = 'origen_dato = ?';
            $params[] = $origen;
        }
        if ($has_estatus) {
            $update_cols[] = 'estatus = ?';
            $params[] = $estatus_db;
        }
        if ($ruta_relativa !== null && $has_foto_acta) {
            $update_cols[] = 'foto_acta = ?';
            $params[] = $ruta_relativa;
        }

        $clavesJug = PartiresulJugadorHelper::clavesBusquedaDesdeIdentificador($pdo, $torneo_id, $id_usuario);
        $whereJug = PartiresulJugadorHelper::sqlWhereClaveIn('partiresul', $clavesJug);
        $params[] = $torneo_id;
        $params[] = $ronda;
        $params[] = $mesa_id;
        foreach ($clavesJug as $c) {
            $params[] = $c;
        }
        $params[] = $secuencia;

        $sql = 'UPDATE partiresul SET ' . implode(', ', $update_cols)
            . ' WHERE id_torneo = ? AND partida = ? AND mesa = ? AND ' . $whereJug . ' AND secuencia = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $pdo->commit();

    // Notificar a los 4 jugadores de la mesa (Web + Telegram) con enlace a la SPA de perfil
    try {
        require_once __DIR__ . '/../lib/app_helpers.php';
        require_once __DIR__ . '/../lib/NotificationManager.php';
        $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
        $stmt = $pdo->prepare("
            SELECT pr.id_usuario, u.nombre" . ($hasTg ? ", u.telegram_chat_id" : "") . "
            FROM partiresul pr
            INNER JOIN usuarios u ON u.id = pr.id_usuario
            INNER JOIN inscritos i ON " . PartiresulJugadorHelper::sqlOnInscritosPartiresul('i', 'pr') . "
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
            ORDER BY pr.secuencia
        ");
        $stmt->execute([$torneo_id, $ronda, $mesa_id]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $urlPerfil = rtrim(\AppHelpers::getPublicUrl(), '/') . '/perfil_jugador.php?torneo_id=' . $torneo_id;
        $mensaje = 'Resultados de tu mesa registrados. Ronda ' . $ronda . ', Mesa ' . $mesa_id . '. Revisa tu perfil en el torneo.';
        if (count($jugadores) > 0) {
            $nm = new NotificationManager($pdo);
            $items = [];
            foreach ($jugadores as $row) {
                $items[] = [
                    'id' => (int)$row['id_usuario'],
                    'telegram_chat_id' => ($hasTg && !empty(trim((string)($row['telegram_chat_id'] ?? '')))) ? trim((string)$row['telegram_chat_id']) : null,
                    'mensaje' => $mensaje,
                    'url_destino' => $urlPerfil,
                ];
            }
            $nm->programarMasivoPersonalizado($items);
        }
    } catch (Exception $e) {
        error_log('public_score_submit notificaciones: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => $estatus === 'pendiente_verificacion'
            ? 'Resultado enviado correctamente. Recibirá confirmación en breve.'
            : 'Resultados guardados correctamente',
        'foto_acta' => $ruta_relativa,
        'estatus' => $estatus,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('public_score_submit: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
exit;
