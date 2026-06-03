<?php
/**
 * Procesa el envio de notificaciones masivas
 * Soporta: admin_general (filtro por tipo/alcance/admin_ids/torneo) y admin_club (destinatarios[]).
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/NotificationSender.php';
require_once __DIR__ . '/../../lib/NotificationManager.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?page=notificaciones_masivas');
    exit;
}

CSRF::validate();

$user = Auth::user();
$is_admin_general = ($user['role'] ?? '') === 'admin_general';

$tipo = $_POST['tipo'] ?? 'club';
$torneo_id = (int)($_POST['torneo_id'] ?? 0);
$plantilla_clave = trim((string)($_POST['plantilla_clave'] ?? ''));
$mensaje_manual = trim((string)($_POST['mensaje'] ?? ''));
$canal = $_POST['canal'] ?? 'whatsapp';
$destinatarios_raw = $_POST['destinatarios'] ?? [];

// Flujo admin_general: resolver destinatarios desde filtro
if ($is_admin_general && !empty($_POST['tipo_filtro'])) {
    require_once __DIR__ . '/resolve_destinatarios_admin_general.php';
    $tipo_filtro = $_POST['tipo_filtro'] ?? '';
    $alcance_filtro = $_POST['alcance_filtro'] ?? '';
    $admin_ids_filtro = isset($_POST['admin_ids_filtro']) ? array_map('intval', array_filter((array)$_POST['admin_ids_filtro'])) : [];
    $torneo_id_filtro = (int)($_POST['torneo_id_filtro'] ?? 0);
    $destinatarios_resueltos = notif_resolve_destinatarios_admin_general($tipo_filtro, $alcance_filtro, $admin_ids_filtro, $torneo_id_filtro);
    $destinatarios_raw = [];
    foreach ($destinatarios_resueltos as $d) {
        $tratamiento = ($d['sexo'] ?? 'M') === 'F' ? 'Estimada' : (($d['sexo'] ?? 'M') === 'M' ? 'Estimado' : 'Estimado/a');
        $destinatarios_raw[] = json_encode([
            'id' => $d['id'],
            'nombre' => $d['nombre'],
            'email' => $d['email'] ?? '',
            'celular' => $d['celular'] ?? '',
            'telegram_chat_id' => $d['telegram_chat_id'] ?? '',
            'club_nombre' => $d['club_nombre'] ?? '',
            'sexo' => $d['sexo'] ?? 'M',
            'identificador' => $d['identificador'] ?? $d['id'],
            'tratamiento' => $tratamiento,
        ]);
    }
    $tipo = $tipo_filtro;
    $torneo_id = $torneo_id_filtro;
}

// Validar que admin_club/admin_torneo tenga acceso al torneo si aplica
if ($torneo_id > 0 && !$is_admin_general && !Auth::canAccessTournament($torneo_id)) {
    $_SESSION['error_notif'] = 'No tiene permisos para enviar notificaciones de ese torneo.';
    $redir_err = 'index.php?page=notificaciones_masivas&tipo=torneo&torneo_id=' . $torneo_id;
    if (!empty($_POST['from'])) $redir_err .= '&from=' . urlencode($_POST['from']);
    header('Location: ' . $redir_err);
    exit;
}

if (empty($destinatarios_raw)) {
    $_SESSION['error_notif'] = 'No hay destinatarios seleccionados.';
    $redir = 'index.php?page=notificaciones_masivas';
    if ($is_admin_general && !empty($_POST['tipo_filtro'])) {
        $redir .= '&tipo_ag=' . urlencode($_POST['tipo_filtro'] ?? '') . '&alcance_ag=' . urlencode($_POST['alcance_filtro'] ?? '') . '&torneo_id_ag=' . (int)($_POST['torneo_id_filtro'] ?? 0);
        if (!empty($_POST['admin_ids_filtro'])) foreach ((array)$_POST['admin_ids_filtro'] as $aid) { $redir .= '&admin_ids[]=' . (int)$aid; }
    } else {
        $redir .= '&tipo=' . urlencode($tipo) . '&torneo_id=' . $torneo_id;
        if (!empty($_POST['from'])) {
            $redir .= '&from=' . urlencode($_POST['from']);
        }
    }
    header('Location: ' . $redir);
    exit;
}

$torneo_nombre = '';
$torneo_fecha = '';
$torneo_hora = '1:00 pm';
$torneo_lugar = '';
$organizacion_nombre = '';
$url_logo = '';
$url_inscripcion = '';
if ($torneo_id > 0) {
    try {
        $pdo_t = DB::pdo();
        $has_cod_org_t = false;
        try {
            $has_cod_org_t = (bool)$pdo_t->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $has_cod_org_t = false;
        }
        $org_join_expr_t = $has_cod_org_t
            ? "(t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
            : "t.club_responsable = o.id";
        $has_hora = $pdo_t->query("SHOW COLUMNS FROM tournaments LIKE 'hora_torneo'")->rowCount() > 0;
        $has_lugar = $pdo_t->query("SHOW COLUMNS FROM tournaments LIKE 'lugar'")->rowCount() > 0;
        $sel = "t.nombre, t.fechator";
        if ($has_hora) $sel .= ", t.hora_torneo";
        if ($has_lugar) $sel .= ", t.lugar";
        $sel .= ", t.club_responsable";
        $stmt = $pdo_t->prepare("
            SELECT $sel,
                   COALESCE(o.nombre, c.nombre) as organizacion_nombre,
                   COALESCE(o.logo, c.logo) as logo_path
            FROM tournaments t
            LEFT JOIN organizaciones o ON {$org_join_expr_t}
            LEFT JOIN clubes c ON t.club_responsable = c.id
            WHERE t.id = ?
        ");
        $stmt->execute([$torneo_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $torneo_nombre = $row['nombre'] ?? '';
            $torneo_fecha = $row['fechator'] ? date('d/m/Y', strtotime($row['fechator'])) : '';
            if ($has_hora && !empty($row['hora_torneo'])) {
                $torneo_hora = date('g:i a', strtotime($row['hora_torneo']));
            }
            if ($has_lugar && !empty($row['lugar'])) {
                $torneo_lugar = trim($row['lugar']);
            }
            $organizacion_nombre = trim($row['organizacion_nombre'] ?? '');
            $logo_path = trim($row['logo_path'] ?? '');
            if ($logo_path !== '') {
                $base = function_exists('app_base_url') ? app_base_url() : ($_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd');
                $url_logo = AppHelpers::imageUrl($logo_path);
            }
            $base = function_exists('app_base_url') ? app_base_url() : ($_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd');
            $url_inscripcion = AppHelpers::url('tournament_register.php', ['torneo_id' => $torneo_id]);
        }
    } catch (Exception $e) {}
}

$pdo = DB::pdo();
$nm = new NotificationManager($pdo);

if ($plantilla_clave !== '') {
    $plantilla = $nm->obtenerPlantilla($plantilla_clave);
    if (!$plantilla) {
        $_SESSION['error_notif'] = 'Plantilla no encontrada.';
        $redir = 'index.php?page=notificaciones_masivas';
        if ($is_admin_general && !empty($_POST['tipo_filtro'])) {
            $redir .= '&tipo_ag=' . urlencode($_POST['tipo_filtro'] ?? '') . '&alcance_ag=' . urlencode($_POST['alcance_filtro'] ?? '') . '&torneo_id_ag=' . (int)($_POST['torneo_id_filtro'] ?? 0);
            if (!empty($_POST['admin_ids_filtro'])) foreach ((array)$_POST['admin_ids_filtro'] as $aid) { $redir .= '&admin_ids[]=' . (int)$aid; }
        } else {
            $redir .= '&tipo=' . urlencode($tipo) . '&torneo_id=' . $torneo_id;
            if (!empty($_POST['from'])) $redir .= '&from=' . urlencode($_POST['from']);
        }
        header('Location: ' . $redir);
        exit;
    }
    $mensaje_base = $mensaje_manual !== '' ? $mensaje_manual : $plantilla['cuerpo_mensaje'];
} else {
    if ($mensaje_manual === '') {
        $_SESSION['error_notif'] = 'Seleccione una plantilla o escriba un mensaje.';
        $redir = 'index.php?page=notificaciones_masivas';
        if ($is_admin_general && !empty($_POST['tipo_filtro'])) {
            $redir .= '&tipo_ag=' . urlencode($_POST['tipo_filtro'] ?? '') . '&alcance_ag=' . urlencode($_POST['alcance_filtro'] ?? '') . '&torneo_id_ag=' . (int)($_POST['torneo_id_filtro'] ?? 0);
            if (!empty($_POST['admin_ids_filtro'])) foreach ((array)$_POST['admin_ids_filtro'] as $aid) { $redir .= '&admin_ids[]=' . (int)$aid; }
        } else {
            $redir .= '&tipo=' . urlencode($tipo) . '&torneo_id=' . $torneo_id;
            if (!empty($_POST['from'])) $redir .= '&from=' . urlencode($_POST['from']);
        }
        header('Location: ' . $redir);
        exit;
    }
    $mensaje_base = $mensaje_manual;
}

$resultados = ['ok' => 0, 'error' => 0, 'omitidos' => 0, 'links_wa' => [], 'encolados' => false];
$items_telegram = [];

foreach ($destinatarios_raw as $d_json) {
    $d = json_decode($d_json, true);
    if (!$d) continue;

    $tratamiento = ($d['sexo'] ?? 'M') === 'F' ? 'Estimada' : (($d['sexo'] ?? 'M') === 'M' ? 'Estimado' : 'Estimado/a');
    $vars = [
        'nombre' => $d['nombre'] ?? '',
        'torneo' => $torneo_nombre,
        'club' => $d['club_nombre'] ?? $d['club'] ?? '',
        'id_usuario' => (string)($d['identificador'] ?? $d['id'] ?? ''),
        'fecha_torneo' => $torneo_fecha,
        'hora_torneo' => $torneo_hora,
        'tratamiento' => $tratamiento,
        'ronda' => '',
        'ganados' => '0',
        'perdidos' => '0',
        'efectividad' => '0',
        'puntos' => '0',
        'mesa' => '—',
        'pareja' => '—',
        'organizacion_nombre' => $organizacion_nombre,
        'url_logo' => $url_logo,
        'lugar_torneo' => $torneo_lugar ?: 'lugar por confirmar',
        'url_inscripcion' => $url_inscripcion,
    ];
    $mensaje = $nm->procesarMensaje($mensaje_base, $vars);

    if ($canal === 'whatsapp') {
        $cel = $d['celular'] ?? '';
        if (empty($cel)) {
            $resultados['omitidos']++;
            continue;
        }
        $url = NotificationSender::whatsappLink($cel, $mensaje);
        $resultados['links_wa'][] = ['nombre' => $d['nombre'], 'url' => $url];
        $resultados['ok']++;
    } elseif ($canal === 'email') {
        $email = $d['email'] ?? '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resultados['omitidos']++;
            continue;
        }
        $asunto = 'Notificacion - La Estacion del Domino';
        $r = NotificationSender::sendEmail($email, $asunto, $mensaje, $d['nombre'] ?? '');
        if ($r['ok']) $resultados['ok']++;
        else $resultados['error']++;
    } elseif ($canal === 'telegram') {
        // Cola de alta velocidad: encolar Telegram + Web (campanita) para procesamiento en segundo plano
        $item = [
            'id' => (int)($d['id'] ?? $d['identificador'] ?? 0),
            'telegram_chat_id' => trim((string)($d['telegram_chat_id'] ?? '')),
            'mensaje' => $mensaje,
            'url_destino' => ($plantilla_clave === 'invitacion_torneo_formal' && $url_inscripcion !== '') ? $url_inscripcion : '#',
        ];
        if ($plantilla_clave === 'invitacion_torneo_formal') {
            $item['datos_json'] = [
                'tipo' => 'invitacion_torneo_formal',
                'organizacion_nombre' => $organizacion_nombre,
                'url_logo' => $url_logo,
                'tratamiento' => $tratamiento,
                'nombre' => $d['nombre'] ?? '',
                'torneo' => $torneo_nombre,
                'lugar_torneo' => $torneo_lugar ?: 'lugar por confirmar',
                'fecha_torneo' => $torneo_fecha,
                'url_inscripcion' => $url_inscripcion,
            ];
        }
        $items_telegram[] = $item;
    }
}

// Procesar cola Telegram+Web si se eligió canal telegram
if ($canal === 'telegram' && !empty($items_telegram)) {
    $pdo = DB::pdo();
    $nm = new NotificationManager($pdo);
    $nm->programarMasivoPersonalizado($items_telegram);
    $resultados['ok'] = count($items_telegram);
    $resultados['encolados'] = true; // Para mostrar mensaje en list.php
}

$_SESSION['notif_resultados'] = $resultados;
$_SESSION['notif_canal'] = $canal;
$_SESSION['notif_tipo'] = $tipo;
$_SESSION['notif_torneo_id'] = $torneo_id;

$redir = 'index.php?page=notificaciones_masivas&action=resultado';
if ($is_admin_general && !empty($_POST['tipo_filtro'])) {
    $redir .= '&tipo_ag=' . urlencode($_POST['tipo_filtro'] ?? '') . '&alcance_ag=' . urlencode($_POST['alcance_filtro'] ?? '') . '&torneo_id_ag=' . (int)($_POST['torneo_id_filtro'] ?? 0);
    if (!empty($_POST['admin_ids_filtro'])) foreach ((array)$_POST['admin_ids_filtro'] as $aid) { $redir .= '&admin_ids[]=' . (int)$aid; }
} else {
    $redir .= '&tipo=' . urlencode($tipo) . '&torneo_id=' . $torneo_id;
    if (!empty($_POST['from'])) $redir .= '&from=' . urlencode($_POST['from']);
}
header('Location: ' . $redir);
exit;
