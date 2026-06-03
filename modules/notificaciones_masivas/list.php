<?php
/**
 * Notificaciones Masivas - Lista y formulario
 * Admin general: enviar a admins de club (uno/varios/todos), usuarios de admin(s) (uno/varios/todos), inscritos en torneo
 * Admin club: usuarios del club, inscritos en torneo
 * Canales: WhatsApp, Email, Telegram
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';
require_once __DIR__ . '/../../lib/NotificationSender.php';
require_once __DIR__ . '/../../lib/NotificationManager.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();
$user = Auth::user();
$user_club_id = (int)($user['club_id'] ?? 0);
$is_admin_general = ($user['role'] ?? '') === 'admin_general';
$is_admin_club = ($user['role'] ?? '') === 'admin_club';
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join_expr = $has_cod_org
    ? "(t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "t.club_responsable = o.id";

// ========== ADMIN GENERAL: formulario por filtro (admins club, usuarios de admins, inscritos torneo) ==========
if ($is_admin_general) {
    require_once __DIR__ . '/resolve_destinatarios_admin_general.php';
    $lista_admins_club = [];
    $stmt = $pdo->query("
        SELECT u.id, u.nombre, u.username, u.club_id, c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.role = 'admin_club' AND u.status = 0
        ORDER BY c.nombre, u.nombre ASC
    ");
    $lista_admins_club = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $torneos_todos = [];
    $has_hora = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'hora_torneo'")->rowCount() > 0;
    $has_lugar = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'lugar'")->rowCount() > 0;
    $sel_t = "t.id, t.nombre, t.fechator, COALESCE(o.nombre, c.nombre) as club_nombre";
    if ($has_hora) $sel_t .= ", t.hora_torneo";
    if ($has_lugar) $sel_t .= ", t.lugar";
    $stmt = $pdo->query("
        SELECT $sel_t
        FROM tournaments t
        LEFT JOIN organizaciones o ON {$org_join_expr}
        LEFT JOIN clubes c ON t.club_responsable = c.id
        WHERE t.estatus = 1
        ORDER BY t.fechator DESC
    ");
    $torneos_todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tipo_ag = $_GET['tipo_ag'] ?? '';
    $alcance_ag = $_GET['alcance_ag'] ?? '';
    $admin_ids_ag = isset($_GET['admin_ids']) ? (array) $_GET['admin_ids'] : [];
    $admin_ids_ag = array_map('intval', array_filter($admin_ids_ag));
    $torneo_id_ag = isset($_GET['torneo_id_ag']) ? (int)$_GET['torneo_id_ag'] : 0;
    $destinatarios = [];
    $mostrar_formulario_mensaje = false;
    if (in_array($tipo_ag, ['admins_club', 'usuarios_admins', 'inscritos_torneo'], true)) {
        if ($tipo_ag === 'inscritos_torneo' && $torneo_id_ag > 0) {
            $destinatarios = notif_resolve_destinatarios_admin_general($tipo_ag, '', [], $torneo_id_ag);
            $mostrar_formulario_mensaje = true;
        } elseif (in_array($tipo_ag, ['admins_club', 'usuarios_admins'], true) && in_array($alcance_ag, ['uno', 'varios', 'todos'], true)) {
            if ($alcance_ag === 'todos' || !empty($admin_ids_ag)) {
                $destinatarios = notif_resolve_destinatarios_admin_general($tipo_ag, $alcance_ag, $admin_ids_ag, 0);
                $mostrar_formulario_mensaje = true;
            }
        }
    }
    $csrf_token = CSRF::token();
    $telegram_habilitado = !empty($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
    $resultados = $_SESSION['notif_resultados'] ?? null;
    $notif_canal = $_SESSION['notif_canal'] ?? '';
    if ($resultados !== null) {
        unset($_SESSION['notif_resultados'], $_SESSION['notif_canal']);
    }
    $nm = new NotificationManager($pdo);
    $plantillas_bd = $nm->listarPlantillas(null);
    $plantillas_por_categoria = ['torneo' => [], 'afiliacion' => [], 'general' => []];
    foreach ($plantillas_bd as $p) {
        $cat = $p['categoria'] ?? 'general';
        $plantillas_por_categoria[$cat][] = $p;
    }
    $torneo_seleccionado_ag = null;
    if ($torneo_id_ag > 0) {
        foreach ($torneos_todos as $t) {
            if ((int)$t['id'] === $torneo_id_ag) { $torneo_seleccionado_ag = $t; break; }
        }
    }
    $torneo_nombre_preview = $torneo_seleccionado_ag['nombre'] ?? 'Torneo Ejemplo';
    $torneo_fecha_preview = $torneo_seleccionado_ag && !empty($torneo_seleccionado_ag['fechator']) ? date('d/m/Y', strtotime($torneo_seleccionado_ag['fechator'])) : date('d/m/Y');
    $torneo_hora_preview = '1:00 pm';
    if ($torneo_seleccionado_ag && !empty($torneo_seleccionado_ag['hora_torneo'] ?? null)) {
        $torneo_hora_preview = date('g:i a', strtotime($torneo_seleccionado_ag['hora_torneo']));
    }
    // Vista para admin_general (se incluye más abajo)
    $vista_admin_general = true;
} else {
    // ========== ADMIN CLUB / ADMIN TORNEO: lógica con soporte organizaciones ==========
    $vista_admin_general = false;
    $user_id = Auth::id();
    $is_admin_torneo = ($user['role'] ?? '') === 'admin_torneo';

    // IDs de responsables: admin_torneo por club; admin_club usa Auth::getTournamentFilterForRole (alcance org)
    $responsable_ids = [];
    if (! $is_admin_club) {
        if ($user_club_id > 0) {
            $responsable_ids = ClubHelper::getClubesSupervised($user_club_id);
            if (empty($responsable_ids)) {
                $responsable_ids = [$user_club_id];
            }
        }
    }

    $placeholders = ! empty($responsable_ids) ? implode(',', array_fill(0, count($responsable_ids), '?')) : null;

    $torneos = [];
    $has_lugar = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'lugar'")->rowCount() > 0;
    $has_hora = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'hora_torneo'")->rowCount() > 0;
    $sel_t = "t.id, t.nombre, t.fechator, COALESCE(o.nombre, c.nombre) as club_nombre";
    if ($has_lugar) {
        $sel_t .= ', t.lugar';
    }
    if ($has_hora) {
        $sel_t .= ', t.hora_torneo';
    }

    if ($is_admin_club) {
        $tf = Auth::getTournamentFilterForRole('t');
        if (! empty($tf['where'])) {
            $stmt = $pdo->prepare("
                SELECT {$sel_t}
                FROM tournaments t
                LEFT JOIN organizaciones o ON {$org_join_expr}
                LEFT JOIN clubes c ON t.club_responsable = c.id
                WHERE t.estatus = 1 AND ({$tf['where']})
                ORDER BY t.fechator DESC
            ");
            $stmt->execute($tf['params']);
            $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($placeholders) {
        $stmt = $pdo->prepare("
            SELECT {$sel_t}
            FROM tournaments t
            LEFT JOIN organizaciones o ON {$org_join_expr}
            LEFT JOIN clubes c ON t.club_responsable = c.id
            WHERE t.club_responsable IN ({$placeholders}) AND t.estatus = 1
            ORDER BY t.fechator DESC
        ");
        $stmt->execute($responsable_ids);
        $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
    $tipo_destino = $_GET['tipo'] ?? ($torneo_id > 0 ? 'torneo' : 'club');
    $from_origin = $_GET['from'] ?? '';

    // Verificar acceso al torneo si viene por URL
    if ($torneo_id > 0 && $is_admin_club && !Auth::canAccessTournament($torneo_id)) {
        $_SESSION['error_notif'] = 'No tiene permisos para enviar notificaciones de ese torneo.';
        $torneo_id = 0;
    }

    // club_id opcional: filtrar a un solo club (desde listado de clubes)
    $club_id_filter = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
    if ($club_id_filter > 0 && $is_admin_club && ! Auth::canManageClub($club_id_filter)) {
        $club_id_filter = 0;
    }

    // Obtener destinatarios según tipo
    $destinatarios = [];
    if ($tipo_destino === 'club' && ($placeholders || $is_admin_club)) {
        // Usuarios: clubes de la org (Auth::getUserClubes) o alcance admin_torneo
        $club_ids_usuarios = $is_admin_club ? Auth::getUserClubes() : $responsable_ids;
        if (empty($club_ids_usuarios) && $user_club_id > 0) {
            $club_ids_usuarios = ClubHelper::getClubesSupervised($user_club_id);
            if (empty($club_ids_usuarios)) {
                $club_ids_usuarios = [$user_club_id];
            }
        }
        if ($club_id_filter > 0) {
            $club_ids_usuarios = [$club_id_filter];
        }
        if (!empty($club_ids_usuarios)) {
            $ph = implode(',', array_fill(0, count($club_ids_usuarios), '?'));
            $stmt = $pdo->prepare("
                SELECT u.id, u.nombre, u.email, u.celular, u.telegram_chat_id, u.sexo, c.nombre as club_nombre
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.role = 'usuario' AND u.status = 0 AND u.club_id IN ($ph)
                ORDER BY c.nombre, u.nombre ASC
            ");
            $stmt->execute($club_ids_usuarios);
            $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($tipo_destino === 'torneo' && $torneo_id > 0) {
        if ($is_admin_club) {
            // Alcance ya validado con canAccessTournament (torneos por organización)
            if (Auth::canAccessTournament($torneo_id)) {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.nombre, u.email, u.celular, u.telegram_chat_id, u.sexo, c.nombre as club_nombre,
                           i.id as inscrito_id, u.id as identificador
                    FROM inscritos i
                    INNER JOIN usuarios u ON i.id_usuario = u.id
                    LEFT JOIN clubes c ON u.club_id = c.id
                    WHERE i.torneo_id = ?
                    ORDER BY u.nombre ASC
                ");
                $stmt->execute([$torneo_id]);
                $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($placeholders) {
            $stmt = $pdo->prepare("
                SELECT u.id, u.nombre, u.email, u.celular, u.telegram_chat_id, u.sexo, c.nombre as club_nombre,
                       i.id as inscrito_id, u.id as identificador
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                LEFT JOIN clubes c ON u.club_id = c.id
                INNER JOIN tournaments t ON i.torneo_id = t.id
                WHERE i.torneo_id = ? AND t.club_responsable IN ($placeholders)
                ORDER BY u.nombre ASC
            ");
            $stmt->execute(array_merge([$torneo_id], $responsable_ids));
            $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
// Para club: agregar identificador = id
foreach ($destinatarios as &$d) {
    if (!isset($d['identificador'])) $d['identificador'] = $d['id'] ?? '';
    if (!isset($d['sexo'])) $d['sexo'] = 'M';
}
$csrf_token = CSRF::token();
$telegram_habilitado = !empty($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
$resultados = $_SESSION['notif_resultados'] ?? null;
$notif_canal = $_SESSION['notif_canal'] ?? '';
if ($resultados !== null) {
    unset($_SESSION['notif_resultados'], $_SESSION['notif_canal'], $_SESSION['notif_tipo'], $_SESSION['notif_torneo_id']);
}
$torneo_seleccionado = null;
if ($torneo_id > 0) {
    foreach ($torneos as $t) {
        if ((int)$t['id'] === $torneo_id) { $torneo_seleccionado = $t; break; }
    }
}
$nm = new NotificationManager($pdo);
$plantillas_bd = $nm->listarPlantillas(null);
$plantillas_por_categoria = ['torneo' => [], 'afiliacion' => [], 'general' => []];
foreach ($plantillas_bd as $p) {
    $cat = $p['categoria'] ?? 'general';
    $plantillas_por_categoria[$cat][] = $p;
}
$torneo_nombre_preview = ($torneo_seleccionado && isset($torneo_seleccionado['nombre'])) ? $torneo_seleccionado['nombre'] : 'Torneo Ejemplo';
$torneo_fecha_preview = ($torneo_seleccionado && !empty($torneo_seleccionado['fechator'])) ? date('d/m/Y', strtotime($torneo_seleccionado['fechator'])) : date('d/m/Y');
$torneo_hora_preview = '1:00 pm';
if ($torneo_seleccionado && !empty($torneo_seleccionado['hora_torneo'])) {
    $torneo_hora_preview = date('g:i a', strtotime($torneo_seleccionado['hora_torneo']));
} elseif ($torneo_id > 0) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'hora_torneo'")->fetch();
        if ($cols) {
            $stmt = $pdo->prepare("SELECT hora_torneo FROM tournaments WHERE id = ?");
            $stmt->execute([$torneo_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['hora_torneo'])) $torneo_hora_preview = date('g:i a', strtotime($row['hora_torneo']));
        }
    } catch (Exception $e) {}
}
}
// Unificar variables para rama admin_general (usa _ag) y admin_club/torneo (usa sin sufijo)
if (!isset($torneo_seleccionado)) {
    $torneo_seleccionado = $torneo_seleccionado_ag ?? null;
}
if (!isset($torneo_id)) {
    $torneo_id = $torneo_id_ag ?? 0;
}
$torneo_lugar_preview = ($torneo_seleccionado && isset($torneo_seleccionado['lugar']) && trim((string)$torneo_seleccionado['lugar']) !== '') ? trim($torneo_seleccionado['lugar']) : 'Centro de eventos';
$organizacion_nombre_preview = ($torneo_seleccionado && isset($torneo_seleccionado['club_nombre']) && trim((string)$torneo_seleccionado['club_nombre']) !== '') ? trim($torneo_seleccionado['club_nombre']) : 'Mi Organización';
$base_preview = function_exists('app_base_url') ? app_base_url() : ($_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd');
$url_inscripcion_preview = AppHelpers::url('tournament_register.php', ['torneo_id' => $torneo_id ?: 1]);

// Variables comunes para resultado (admin_club las tiene en el else; admin_general las tiene arriba)
if (!isset($csrf_token)) { $csrf_token = CSRF::token(); }
if (!isset($telegram_habilitado)) { $telegram_habilitado = !empty($_ENV['TELEGRAM_BOT_TOKEN'] ?? ''); }
if (!isset($resultados)) { $resultados = $_SESSION['notif_resultados'] ?? null; }
if (!isset($notif_canal)) { $notif_canal = $_SESSION['notif_canal'] ?? ''; }
if (isset($resultados) && $resultados !== null) {
    unset($_SESSION['notif_resultados'], $_SESSION['notif_canal'], $_SESSION['notif_tipo'], $_SESSION['notif_torneo_id']);
}
?>
<div class="fade-in">
    <?php if ($resultados !== null): ?>
    <div class="alert alert-<?= ($resultados['encolados'] ?? false) ? 'info' : ($resultados['error'] > 0 ? 'warning' : 'success') ?> alert-dismissible fade show mb-4">
        <h5><i class="fas fa-<?= ($resultados['encolados'] ?? false) ? 'inbox' : 'check-circle' ?> me-2"></i>Resultado del envio</h5>
        <?php if (!empty($resultados['encolados'])): ?>
        <p class="mb-1">Mensajes encolados: <strong><?= $resultados['ok'] ?></strong>. Se enviarán por Telegram en breve y aparecerán en la campanita web.</p>
        <?php else: ?>
        <p class="mb-1">Enviados: <strong><?= $resultados['ok'] ?></strong></p>
        <?php if ($resultados['error'] > 0): ?><p class="mb-1 text-danger">Errores: <?= $resultados['error'] ?></p><?php endif; ?>
        <?php if ($resultados['omitidos'] > 0): ?><p class="mb-1 text-muted">Omitidos (sin contacto): <?= $resultados['omitidos'] ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($resultados['links_wa']) && ($notif_canal ?? '') === 'whatsapp'): ?>
        <hr>
        <p class="mb-2"><strong>Enlaces de WhatsApp (clic para enviar):</strong></p>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($resultados['links_wa'] as $link): ?>
            <a href="<?= htmlspecialchars($link['url']) ?>" class="btn btn-success btn-sm">
                <i class="fab fa-whatsapp me-1"></i><?= htmlspecialchars($link['nombre']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_notif'])): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4">
        <?= htmlspecialchars($_SESSION['error_notif']) ?>
        <?php unset($_SESSION['error_notif']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-bell me-2"></i>Notificaciones Masivas
            </h1>
            <p class="text-muted mb-0">Envia mensajes por WhatsApp, Email o Telegram</p>
        </div>
    </div>

    <?php if ($vista_admin_general): ?>
    <!-- ========== FORMULARIO ADMIN GENERAL: filtro por tipo y destinatarios ========== -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-users-cog me-2"></i>Seleccionar Destinatarios
        </div>
        <div class="card-body">
            <form method="GET" action="" id="form-filtro-ag">
                <input type="hidden" name="page" value="notificaciones_masivas">
                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label fw-bold">Enviar a</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_ag" id="tipo_admins" value="admins_club" <?= ($tipo_ag ?? '') === 'admins_club' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="tipo_admins">Administradores de club</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_ag" id="tipo_usuarios" value="usuarios_admins" <?= ($tipo_ag ?? '') === 'usuarios_admins' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="tipo_usuarios">Usuarios de administrador(es)</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_ag" id="tipo_inscritos" value="inscritos_torneo" <?= ($tipo_ag ?? '') === 'inscritos_torneo' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="tipo_inscritos">Inscritos en un torneo</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12" id="alcance-container" style="display: <?= in_array($tipo_ag ?? '', ['admins_club', 'usuarios_admins']) ? 'block' : 'none' ?>;">
                        <label class="form-label">Alcance</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="alcance_ag" id="alcance_uno" value="uno" <?= ($alcance_ag ?? '') === 'uno' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alcance_uno">Un administrador</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="alcance_ag" id="alcance_varios" value="varios" <?= ($alcance_ag ?? '') === 'varios' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alcance_varios">Varios administradores</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="alcance_ag" id="alcance_todos" value="todos" <?= ($alcance_ag ?? '') === 'todos' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alcance_todos">Todos</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12" id="selector-admins-container" style="display: <?= in_array($tipo_ag ?? '', ['admins_club', 'usuarios_admins']) && in_array($alcance_ag ?? '', ['uno', 'varios']) ? 'block' : 'none' ?>;">
                        <label class="form-label">Seleccione administrador(es) de club</label>
                        <select name="admin_ids[]" class="form-select" multiple size="8">
                            <?php foreach ($lista_admins_club as $ac): ?>
                            <option value="<?= (int)$ac['id'] ?>" <?= in_array((int)$ac['id'], $admin_ids_ag) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ac['nombre']) ?> (<?= htmlspecialchars($ac['club_nombre'] ?? 'Sin club') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Ctrl+clic para seleccionar varios</small>
                    </div>
                    <div class="col-md-12" id="selector-torneo-container" style="display: <?= ($tipo_ag ?? '') === 'inscritos_torneo' ? 'block' : 'none' ?>;">
                        <label class="form-label">Torneo</label>
                        <select name="torneo_id_ag" class="form-select">
                            <option value="0">-- Seleccione torneo --</option>
                            <?php foreach ($torneos_todos as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $torneo_id_ag === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['nombre']) ?> (<?= date('d/m/Y', strtotime($t['fechator'])) ?>) - <?= htmlspecialchars($t['club_nombre'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Actualizar / Ver destinatarios</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mostrar_formulario_mensaje && !empty($destinatarios)): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <i class="fas fa-paper-plane me-2"></i>Mensaje (<?= count($destinatarios) ?> destinatario(s))
        </div>
        <div class="card-body">
            <form method="POST" action="index.php?page=notificaciones_masivas&action=send" id="form-notif-masivas-ag">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="tipo_filtro" value="<?= htmlspecialchars($tipo_ag) ?>">
                <input type="hidden" name="alcance_filtro" value="<?= htmlspecialchars($alcance_ag) ?>">
                <input type="hidden" name="torneo_id_filtro" value="<?= $torneo_id_ag ?>">
                <?php foreach ($admin_ids_ag as $aid): ?>
                <input type="hidden" name="admin_ids_filtro[]" value="<?= (int)$aid ?>">
                <?php endforeach; ?>

                <div class="mb-4">
                    <label class="form-label fw-bold">Plantilla</label>
                    <select name="plantilla_clave" id="plantilla_clave_ag" class="form-select form-select-lg">
                        <option value="">-- Elija una plantilla --</option>
                        <?php if (!empty($plantillas_por_categoria['torneo'])): ?>
                        <optgroup label="Torneo">
                            <?php foreach ($plantillas_por_categoria['torneo'] as $p): ?>
                            <option value="<?= htmlspecialchars($p['nombre_clave']) ?>" data-cuerpo="<?= htmlspecialchars($p['cuerpo_mensaje']) ?>"><?= htmlspecialchars($p['titulo_visual']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($plantillas_por_categoria['general'])): ?>
                        <optgroup label="General">
                            <?php foreach ($plantillas_por_categoria['general'] as $p): ?>
                            <option value="<?= htmlspecialchars($p['nombre_clave']) ?>" data-cuerpo="<?= htmlspecialchars($p['cuerpo_mensaje']) ?>"><?= htmlspecialchars($p['titulo_visual']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold">Canal</label>
                    <div class="form-check"><input class="form-check-input" type="radio" name="canal" value="whatsapp" checked> <label class="form-check-label">WhatsApp</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="canal" value="email"> <label class="form-check-label">Email</label></div>
                    <?php if ($telegram_habilitado): ?>
                    <div class="form-check"><input class="form-check-input" type="radio" name="canal" value="telegram"> <label class="form-check-label">Telegram</label></div>
                    <?php endif; ?>
                </div>
                <div class="mb-4" id="preview-container-ag" style="display: none;">
                    <label class="form-label fw-bold"><i class="fas fa-mobile-alt me-1"></i>Vista en dispositivo del receptor</label>
                    <div id="preview-canal-badge-ag" class="small text-muted mb-2"></div>
                    <div class="notif-preview-device">
                        <div class="notif-preview-screen">
                            <div id="preview-mensaje-ag"></div>
                        </div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold">Editar mensaje (opcional)</label>
                    <textarea name="mensaje" id="mensaje_manual_ag" class="form-control" rows="4" placeholder="Deje vacío para usar la plantilla."></textarea>
                </div>
                <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-paper-plane me-2"></i>Enviar a <?= count($destinatarios) ?> destinatario(s)</button>
            </form>
        </div>
    </div>
    <script>
    (function() {
        var sel = document.getElementById('plantilla_clave_ag');
        var preview = document.getElementById('preview-mensaje-ag');
        var container = document.getElementById('preview-container-ag');
        var torneoNombre = <?= json_encode($torneo_nombre_preview) ?>;
        var torneoFecha = <?= json_encode($torneo_fecha_preview ?? date('d/m/Y')) ?>;
        var torneoLugar = <?= json_encode(($torneo_seleccionado_ag && isset($torneo_seleccionado_ag['lugar']) ? $torneo_seleccionado_ag['lugar'] : 'Centro de eventos')) ?>;
        var organizacionNombre = <?= json_encode(($torneo_seleccionado_ag && isset($torneo_seleccionado_ag['club_nombre']) ? $torneo_seleccionado_ag['club_nombre'] : 'Mi Organización')) ?>;
        var urlInscripcion = <?= json_encode(AppHelpers::url('tournament_register.php', ['torneo_id' => $torneo_id_ag ?: 1])) ?>;
        function escapeHtml(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        function getCanalAg() { var r = document.querySelector('#form-notif-masivas-ag input[name="canal"]:checked'); return r ? r.value : 'whatsapp'; }
        function updatePreviewAg() {
            if (!sel || !preview || !container) return;
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) { container.style.display = 'none'; return; }
            var plantillaClave = opt.value;
            var cuerpo = opt.getAttribute('data-cuerpo') || '';
            var text = cuerpo.replace(/\{nombre\}/g, 'Juan').replace(/\{torneo\}/g, torneoNombre).replace(/\{club\}/g, 'Club')
                .replace(/\{fecha_torneo\}/g, torneoFecha).replace(/\{tratamiento\}/g, 'Estimado')
                .replace(/\{organizacion_nombre\}/g, organizacionNombre).replace(/\{url_logo\}/g, '')
                .replace(/\{lugar_torneo\}/g, torneoLugar).replace(/\{url_inscripcion\}/g, urlInscripcion);
            var canal = getCanalAg();
            var html = '';
            if (plantillaClave === 'invitacion_torneo_formal' && canal === 'telegram') {
                html = '<div class="notification-card notification-card-invitacion-formal notif-preview-card">' +
                    '<div class="notification-card-content">' +
                    '<div class="notif-invitacion-org text-center">' + escapeHtml(organizacionNombre) + '</div>' +
                    '<div class="notif-invitacion-saludo text-center">Estimado Juan</div>' +
                    '<div class="notif-invitacion-torneo text-center">' + escapeHtml(torneoNombre) + '</div>' +
                    '<div class="notif-invitacion-lugar text-center">' + escapeHtml(torneoLugar) + ' · ' + escapeHtml(torneoFecha) + '</div>' +
                    '<div class="notification-card-actions justify-content-center mt-2">' +
                    '<a href="#" class="btn btn-sm btn-primary"><i class="fas fa-pen-fancy me-1"></i>Inscribirse en línea</a>' +
                    '</div></div></div>';
            } else if (canal === 'whatsapp') {
                html = '<div class="notif-preview-whatsapp"><div class="notif-preview-wa-bubble">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div></div>';
            } else if (canal === 'email') {
                html = '<div class="notif-preview-email"><div class="notif-preview-email-body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div></div>';
            } else {
                html = '<div class="notification-card notif-preview-card"><div class="notification-card-content">' +
                    '<p class="notification-card-message mb-0">' + escapeHtml(text).replace(/\n/g, '<br>') + '</p></div></div>';
            }
            preview.innerHTML = html;
            container.style.display = 'block';
            var badge = document.getElementById('preview-canal-badge-ag');
            if (badge) badge.textContent = canal === 'whatsapp' ? 'Así se verá en WhatsApp' : (canal === 'email' ? 'Así se verá en el correo electrónico' : 'Así se verá en Telegram y en la campanita del panel');
        }
        if (sel) sel.addEventListener('change', updatePreviewAg);
        document.querySelectorAll('#form-notif-masivas-ag input[name="canal"]').forEach(function(r) { r.addEventListener('change', updatePreviewAg); });
        if (sel && sel.value) updatePreviewAg();
        var t = document.getElementById('tipo_inscritos');
        var t2 = document.getElementById('tipo_admins');
        var t3 = document.getElementById('tipo_usuarios');
        var alcanceContainer = document.getElementById('alcance-container');
        var selectorAdmins = document.getElementById('selector-admins-container');
        var selectorTorneo = document.getElementById('selector-torneo-container');
        function toggleVis() {
            var esInscritos = document.getElementById('tipo_inscritos').checked;
            var esAdmins = document.getElementById('tipo_admins').checked || document.getElementById('tipo_usuarios').checked;
            alcanceContainer.style.display = esAdmins ? 'block' : 'none';
            selectorTorneo.style.display = esInscritos ? 'block' : 'none';
            var alcanceUnoVarios = document.getElementById('alcance_uno').checked || document.getElementById('alcance_varios').checked;
            selectorAdmins.style.display = (esAdmins && alcanceUnoVarios) ? 'block' : 'none';
        }
        if (alcanceContainer) { document.querySelectorAll('input[name="tipo_ag"]').forEach(function(r){ r.addEventListener('change', toggleVis); }); document.querySelectorAll('input[name="alcance_ag"]').forEach(function(r){ r.addEventListener('change', toggleVis); }); toggleVis(); }
    })();
    </script>
    <?php elseif ($vista_admin_general && !$mostrar_formulario_mensaje): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>Seleccione tipo de destinatarios, alcance (y administrador(es) o torneo) y pulse Actualizar.
    </div>
    <?php elseif ($vista_admin_general && $mostrar_formulario_mensaje && empty($destinatarios)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>No hay destinatarios para la selección actual.
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- ========== FORMULARIO ADMIN CLUB: tipo organización / inscritos torneo ========== -->
    <?php
    $url_retorno = ($from_origin === 'torneo_gestion' || $torneo_id > 0) ? 'index.php?page=torneo_gestion&action=index' : '';
    ?>
    <?php if ($url_retorno): ?>
    <div class="mb-3">
        <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver al origen
        </a>
    </div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-users me-2"></i>Seleccionar Destinatarios
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="notificaciones_masivas">
                <?php if ($from_origin): ?><input type="hidden" name="from" value="<?= htmlspecialchars($from_origin) ?>"><?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label">Tipo de destinatarios</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="club" <?= ($tipo_destino ?? '') === 'club' ? 'selected' : '' ?>>Usuarios de la organización</option>
                        <option value="torneo" <?= ($tipo_destino ?? '') === 'torneo' ? 'selected' : '' ?>>Inscritos en torneo</option>
                    </select>
                </div>
                <?php if (($tipo_destino ?? '') === 'torneo'): ?>
                <div class="col-md-4">
                    <label class="form-label">Torneo</label>
                    <select name="torneo_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">-- Seleccione torneo --</option>
                        <?php foreach ($torneos as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($torneo_id ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['nombre']) ?> (<?= date('d/m/Y', strtotime($t['fechator'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif (($tipo_destino ?? '') === 'club' && !empty($torneos)): ?>
                <div class="col-md-4">
                    <label class="form-label">Torneo (para plantillas)</label>
                    <select name="torneo_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">-- Ninguno --</option>
                        <?php foreach ($torneos as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($torneo_id ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['nombre']) ?> (<?= date('d/m/Y', strtotime($t['fechator'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Actualizar</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($destinatarios)): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <i class="fas fa-paper-plane me-2"></i>Enviar a <?= count($destinatarios) ?> destinatario(s)
        </div>
        <div class="card-body">
            <form method="POST" action="index.php?page=notificaciones_masivas&action=send" id="form-notif-masivas">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo_destino) ?>">
                <input type="hidden" name="torneo_id" value="<?= $torneo_id ?>">
                <?php if ($from_origin): ?><input type="hidden" name="from" value="<?= htmlspecialchars($from_origin) ?>"><?php endif; ?>
                <?php foreach ($destinatarios as $d):
                    $tratamiento = 'Estimado/a';
                    if (isset($d['sexo'])) {
                        $tratamiento = ($d['sexo'] === 'F') ? 'Estimada' : (($d['sexo'] === 'M') ? 'Estimado' : 'Estimado/a');
                    }
                    $id_usuario = $d['identificador'] ?? $d['id'] ?? '';
                ?>
                <input type="hidden" name="destinatarios[]" value="<?= htmlspecialchars(json_encode([
                    'id' => $d['id'],
                    'nombre' => $d['nombre'],
                    'email' => $d['email'] ?? '',
                    'celular' => $d['celular'] ?? '',
                    'telegram_chat_id' => $d['telegram_chat_id'] ?? '',
                    'club_nombre' => $d['club_nombre'] ?? '',
                    'sexo' => $d['sexo'] ?? 'M',
                    'identificador' => $id_usuario,
                    'tratamiento' => $tratamiento
                ])) ?>">
                <?php endforeach; ?>

                <div class="mb-4">
                    <label class="form-label fw-bold">Seleccione una plantilla</label>
                    <select name="plantilla_clave" id="plantilla_clave" class="form-select form-select-lg">
                        <option value="">-- Elija una plantilla --</option>
                        <?php if (!empty($plantillas_por_categoria['torneo'])): ?>
                        <optgroup label="Torneo">
                            <?php foreach ($plantillas_por_categoria['torneo'] as $p): ?>
                            <option value="<?= htmlspecialchars($p['nombre_clave']) ?>" data-cuerpo="<?= htmlspecialchars($p['cuerpo_mensaje']) ?>"><?= htmlspecialchars($p['titulo_visual']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($plantillas_por_categoria['afiliacion'])): ?>
                        <optgroup label="Afiliación">
                            <?php foreach ($plantillas_por_categoria['afiliacion'] as $p): ?>
                            <option value="<?= htmlspecialchars($p['nombre_clave']) ?>" data-cuerpo="<?= htmlspecialchars($p['cuerpo_mensaje']) ?>"><?= htmlspecialchars($p['titulo_visual']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($plantillas_por_categoria['general'])): ?>
                        <optgroup label="General">
                            <?php foreach ($plantillas_por_categoria['general'] as $p): ?>
                            <option value="<?= htmlspecialchars($p['nombre_clave']) ?>" data-cuerpo="<?= htmlspecialchars($p['cuerpo_mensaje']) ?>"><?= htmlspecialchars($p['titulo_visual']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    <p class="small text-muted mb-2 mt-1">Elija una plantilla o escriba un mensaje personalizado abajo.</p>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Canal de envio</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="canal" id="canal_wa" value="whatsapp" checked>
                        <label class="form-check-label" for="canal_wa">WhatsApp (enlaces wa.me - envio manual)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="canal" id="canal_email" value="email">
                        <label class="form-check-label" for="canal_email">Email (envio automatico en lote)</label>
                    </div>
                    <?php if ($telegram_habilitado): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="canal" id="canal_tg" value="telegram">
                        <label class="form-check-label" for="canal_tg">Telegram (solo usuarios vinculados)</label>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mb-4" id="preview-container" style="display: none;">
                    <label class="form-label fw-bold"><i class="fas fa-mobile-alt me-1"></i>Vista en dispositivo del receptor</label>
                    <div id="preview-canal-badge" class="small text-muted mb-2"></div>
                    <div class="notif-preview-device">
                        <div class="notif-preview-screen">
                            <div id="preview-mensaje"></div>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">Variables: {nombre}, {torneo}, {fecha_torneo}, {hora_torneo}, {id_usuario}, {ronda}, {club}, {tratamiento}, {organizacion_nombre}, {url_logo}, {lugar_torneo}, {url_inscripcion}</small>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Editar mensaje (opcional)</label>
                    <textarea name="mensaje" id="mensaje_manual" class="form-control" rows="4" placeholder="Deje vacío para usar el texto de la plantilla. Puede personalizar aquí."></textarea>
                    <small class="text-muted">Si escribe aquí, se usará este texto en lugar de la plantilla. Variables: {nombre}, {torneo}, {fecha_torneo}, {hora_torneo}, {id_usuario}, {club}</small>
                </div>

                <p class="text-muted mb-3"><i class="fas fa-info-circle me-1"></i>Se enviará a <strong><?= count($destinatarios) ?></strong> destinatario(s). Los datos personales no se muestran.</p>

                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-paper-plane me-2"></i>Enviar Notificaciones
                </button>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var sel = document.getElementById('plantilla_clave');
        var preview = document.getElementById('preview-mensaje');
        var container = document.getElementById('preview-container');
        var torneoNombre = <?= json_encode($torneo_nombre_preview) ?>;
        var torneoFecha = <?= json_encode($torneo_fecha_preview) ?>;
        var torneoHora = <?= json_encode($torneo_hora_preview) ?>;
        var torneoLugar = <?= json_encode($torneo_lugar_preview ?? 'Centro de eventos') ?>;
        var organizacionNombre = <?= json_encode($organizacion_nombre_preview ?? 'Mi Organización') ?>;
        var urlInscripcion = <?= json_encode($url_inscripcion_preview ?? '') ?>;
        var mensajeManual = document.getElementById('mensaje_manual');

        function escapeHtml(t) {
            if (!t) return '';
            var d = document.createElement('div');
            d.textContent = t;
            return d.innerHTML;
        }

        function getCanal() {
            var r = document.querySelector('input[name="canal"]:checked');
            return r ? r.value : 'whatsapp';
        }

        function updatePreview() {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) {
                container.style.display = 'none';
                return;
            }
            var plantillaClave = opt.value;
            var cuerpo = (mensajeManual && mensajeManual.value.trim()) ? mensajeManual.value : (opt.getAttribute('data-cuerpo') || '');
            var text = cuerpo
                .replace(/\{nombre\}/g, 'Juan Pérez')
                .replace(/\{torneo\}/g, torneoNombre)
                .replace(/\{fecha_torneo\}/g, torneoFecha)
                .replace(/\{hora_torneo\}/g, torneoHora)
                .replace(/\{id_usuario\}/g, '123')
                .replace(/\{ronda\}/g, '1')
                .replace(/\{club\}/g, 'Mi Club')
                .replace(/\{tratamiento\}/g, 'Estimado')
                .replace(/\{organizacion_nombre\}/g, organizacionNombre)
                .replace(/\{url_logo\}/g, '')
                .replace(/\{lugar_torneo\}/g, torneoLugar)
                .replace(/\{url_inscripcion\}/g, urlInscripcion)
                .replace(/\{ganados\}/g, '0').replace(/\{perdidos\}/g, '0').replace(/\{efectividad\}/g, '0').replace(/\{puntos\}/g, '0')
                .replace(/\{mesa\}/g, '—').replace(/\{pareja\}/g, '—');

            var canal = getCanal();
            var html = '';

            if (plantillaClave === 'invitacion_torneo_formal' && canal === 'telegram') {
                html = '<div class="notification-card notification-card-invitacion-formal notif-preview-card">' +
                    '<div class="notification-card-content">' +
                    '<div class="notif-invitacion-org text-center">' + escapeHtml(organizacionNombre) + '</div>' +
                    '<div class="notif-invitacion-saludo text-center">Estimado Juan Pérez</div>' +
                    '<div class="notif-invitacion-torneo text-center">' + escapeHtml(torneoNombre) + '</div>' +
                    '<div class="notif-invitacion-lugar text-center">' + escapeHtml(torneoLugar) + ' · ' + escapeHtml(torneoFecha) + '</div>' +
                    '<div class="notification-card-actions justify-content-center mt-2">' +
                    '<a href="' + escapeHtml(urlInscripcion) + '" class="btn btn-sm btn-primary"><i class="fas fa-pen-fancy me-1"></i>Inscribirse en línea</a>' +
                    '</div></div></div>';
            } else if (canal === 'whatsapp') {
                html = '<div class="notif-preview-whatsapp">' +
                    '<div class="notif-preview-wa-bubble">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>' +
                    '</div>';
            } else if (canal === 'email') {
                html = '<div class="notif-preview-email">' +
                    '<div class="notif-preview-email-body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>' +
                    '</div>';
            } else {
                html = '<div class="notification-card notif-preview-card">' +
                    '<div class="notification-card-content">' +
                    '<p class="notification-card-message mb-0">' + escapeHtml(text).replace(/\n/g, '<br>') + '</p>' +
                    '</div></div>';
            }

            preview.innerHTML = html;
            container.style.display = 'block';
            var badge = document.getElementById('preview-canal-badge');
            if (badge) badge.textContent = canal === 'whatsapp' ? 'Así se verá en WhatsApp' : (canal === 'email' ? 'Así se verá en el correo electrónico' : 'Así se verá en Telegram y en la campanita del panel');
            if (mensajeManual && !mensajeManual.value) mensajeManual.placeholder = 'Opcional: edite aquí para sobreescribir la plantilla.';
        }

        sel.addEventListener('change', updatePreview);
        if (mensajeManual) mensajeManual.addEventListener('input', updatePreview);
        document.querySelectorAll('input[name="canal"]').forEach(function(r) { r.addEventListener('change', updatePreview); });
        if (sel.value) updatePreview();
    })();
    </script>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        No hay destinatarios para la seleccion actual. Cambia el tipo o el torneo.
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
