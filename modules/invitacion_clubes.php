<?php
/**
 * Invitación de clubes al torneo actual.
 * Lista clubes (tabla clubes) para que el usuario seleccione a cuáles invitar.
 * Requiere torneo_id en la URL (desde el panel de control).
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/Pagination.php';
require_once __DIR__ . '/../lib/InvitationJoinResolver.php';
require_once __DIR__ . '/../public/simple_image_config.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

/** Estatus de club que procede del directorio (pendiente aceptación/completar datos al loguearse) */
const CLUB_ESTATUS_DIRECTORIO = 9;

/**
 * Obtiene o crea un club en tabla clubes a partir de un registro de directorio_clubes.
 * Homologación: nombre, direccion, delegado, telefono, email, logo → clubes; estatus = 9.
 * admin_club_id del club = id del admin/organización que hace la invitación (hasta que el club acepte).
 * @param PDO $pdo
 * @param array $dir fila de directorio_clubes (id, nombre, direccion, delegado, telefono, email, logo)
 * @param int $id_directorio_club id del registro en directorio_clubes
 * @param int|null $id_invitador id del usuario admin que hace la invitación (para admin_club_id del club). Si null, se usa 0.
 * @return int club id
 */
function invitacion_find_or_create_club_from_directorio(PDO $pdo, array $dir, $id_directorio_club, $id_invitador = null) {
    $id_directorio_club = (int) $id_directorio_club;
    $id_invitador = (int) ($id_invitador ?? 0);
    $cols_clubes = $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_COLUMN);
    $has_id_directorio = in_array('id_directorio_club', $cols_clubes, true);

    if ($has_id_directorio && $id_directorio_club > 0) {
        $st = $pdo->prepare("SELECT id FROM clubes WHERE id_directorio_club = ? LIMIT 1");
        $st->execute([$id_directorio_club]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int) $row['id'];
        }
    }

    $nombre = trim($dir['nombre'] ?? '');
    if ($nombre === '') {
        throw new Exception('El registro del directorio no tiene nombre.');
    }
    $ins = [
        'nombre' => $nombre,
        'direccion' => trim($dir['direccion'] ?? '') ?: null,
        'delegado' => trim($dir['delegado'] ?? '') ?: null,
        'telefono' => trim($dir['telefono'] ?? '') ?: null,
        'email' => trim($dir['email'] ?? '') ?: null,
        'logo' => !empty($dir['logo']) ? $dir['logo'] : null,
        'estatus' => CLUB_ESTATUS_DIRECTORIO,
    ];
    if ($has_id_directorio && $id_directorio_club > 0) {
        $ins['id_directorio_club'] = $id_directorio_club;
    }
    if (in_array('entidad', $cols_clubes, true)) {
        $ins['entidad'] = 0;
    }
    if (in_array('admin_club_id', $cols_clubes, true)) {
        $ins['admin_club_id'] = $id_invitador;
    }
    $cols = array_keys($ins);
    $placeholders = array_map(function ($c) { return ':' . $c; }, $cols);
    $params = [];
    foreach ($ins as $k => $v) {
        $params[':' . $k] = $v;
    }
    $stmt = $pdo->prepare("INSERT INTO clubes (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")");
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$success_message = (isset($_GET['success']) && $_GET['success'] === '1') ? ($_GET['msg'] ?? 'Operación correcta.') : ($_GET['success'] ?? null);
$error_message = $_GET['error'] ?? null;
$torneo = null;
$clubs_list = [];
$pagination = null;
$ya_invitados = [];
$invitaciones_por_club = [];

if ($torneo_id <= 0) {
    $error_message = 'Debe indicar un torneo. Use el panel de control del torneo para acceder a Invitación de clubes.';
} else {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT id, nombre, fechator, club_responsable FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            $error_message = 'Torneo no encontrado.';
        } else {
            if (!Auth::canAccessTournament($torneo_id)) {
                $error_message = 'No tiene permisos para invitar clubes a este torneo.';
                $torneo = null;
            } else {
                $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
                $stmt = $pdo->prepare("SELECT i.id, i.club_id, i.token FROM {$tb_inv} i WHERE i.torneo_id = ? AND i.club_id IS NOT NULL AND i.club_id > 0");
                $stmt->execute([$torneo_id]);
                $rows_inv = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $ya_invitados = [];
                $invitaciones_por_club = [];
                $club_ids_inv = array_unique(array_filter(array_map(function ($r) { return (int)$r['club_id']; }, $rows_inv)));
                if (!empty($club_ids_inv)) {
                    $cols_club_inv = ['id', 'delegado_user_id'];
                    $cols_clubes_check = $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array('id_directorio_club', $cols_clubes_check, true)) {
                        $cols_club_inv[] = 'id_directorio_club';
                    }
                    $placeholders = implode(',', array_fill(0, count($club_ids_inv), '?'));
                    $st_clubs = $pdo->prepare("SELECT " . implode(', ', $cols_club_inv) . " FROM clubes WHERE id IN ($placeholders)");
                    $st_clubs->execute(array_values($club_ids_inv));
                    $clubs_inv_data = [];
                    $dir_ids = [];
                    while ($row = $st_clubs->fetch(PDO::FETCH_ASSOC)) {
                        $clubs_inv_data[(int)$row['id']] = $row;
                        if (!empty($row['id_directorio_club'])) {
                            $dir_ids[(int)$row['id_directorio_club']] = true;
                        }
                    }
                    $dir_usuarios = [];
                    if (!empty($dir_ids)) {
                        $cols_dc = @$pdo->query("SHOW COLUMNS FROM directorio_clubes LIKE 'id_usuario'")->fetchAll();
                        if (!empty($cols_dc)) {
                            $st_dir = $pdo->prepare("SELECT id, id_usuario FROM directorio_clubes WHERE id IN (" . implode(',', array_map('intval', array_keys($dir_ids))) . ")");
                            $st_dir->execute();
                            while ($d = $st_dir->fetch(PDO::FETCH_ASSOC)) {
                                $dir_usuarios[(int)$d['id']] = isset($d['id_usuario']) && $d['id_usuario'] !== null && (string)$d['id_usuario'] !== '';
                            }
                        }
                    }
                    foreach ($rows_inv as $r) {
                        $cid = (int)$r['club_id'];
                        if ($cid <= 0) continue;
                        $ya_invitados[] = $cid;
                        $req_registro = true;
                        $club_data = $clubs_inv_data[$cid] ?? null;
                        if ($club_data) {
                            if (!empty($club_data['delegado_user_id'])) {
                                $req_registro = false;
                            } elseif (!empty($club_data['id_directorio_club']) && !empty($dir_usuarios[(int)$club_data['id_directorio_club']])) {
                                $req_registro = false;
                            }
                        }
                        $invitaciones_por_club[$cid] = [
                            'id' => (int)$r['id'],
                            'token' => $r['token'],
                            'requiere_registro' => $req_registro
                        ];
                    }
                }

                $org_id = isset($torneo['club_responsable']) && (int)$torneo['club_responsable'] > 0 ? (int)$torneo['club_responsable'] : null;
                $cols_clubes_list = ['id', 'nombre', 'direccion', 'delegado', 'telefono', 'email', 'logo', 'estatus'];
                $has_organizacion_id = in_array('cod_org', $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_COLUMN), true);
                if ($has_organizacion_id) {
                    $cols_clubes_list[] = 'cod_org';
                }
                if (in_array('id_directorio_club', $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_COLUMN), true)) {
                    $cols_clubes_list[] = 'id_directorio_club';
                }
                $total = (int) $pdo->query("SELECT COUNT(*) FROM clubes")->fetchColumn();
                $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
                $per_page = Pagination::DEFAULT_PER_PAGE;
                $pagination = new Pagination($total, $current_page, $per_page);
                // Orden: primero los clubes de la organización que organiza el torneo, luego el resto; dentro de cada grupo por nombre
                $order_sql = $has_organizacion_id && $org_id !== null
                    ? "ORDER BY (CASE WHEN cod_org = " . (int)$org_id . " THEN 0 ELSE 1 END), nombre ASC"
                    : "ORDER BY nombre ASC";
                $stmt = $pdo->prepare("
                    SELECT " . implode(', ', $cols_clubes_list) . "
                    FROM clubes
                    " . $order_sql . "
                    LIMIT " . $pagination->getLimit() . " OFFSET " . $pagination->getOffset()
                );
                $stmt->execute();
                $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {
        $error_message = 'Error al cargar datos: ' . $e->getMessage();
    }
}

// Helper: redirección segura (evita "headers already sent" con meta refresh)
$invitacion_safe_redirect = function ($url) {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '"><p>Redirigiendo...</p>';
    exit;
};

// GET: invitar un solo club por línea (acción "Invitar" en cada fila)
if ($torneo && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'invitar_uno' && isset($_GET['club_id'])) {
    $club_id_one = (int)$_GET['club_id'];
    if ($club_id_one > 0 && Auth::canAccessTournament($torneo_id)) {
        $sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
        $build_redirect_one = function (array $params) use ($torneo_id, $sp) {
            $base = ['page' => 'invitacion_clubes', 'torneo_id' => $torneo_id];
            if (isset($_GET['p']) && (int)$_GET['p'] >= 1) {
                $base['p'] = (int)$_GET['p'];
            }
            if (isset($_GET['per_page']) && (int)$_GET['per_page'] >= 10) {
                $base['per_page'] = (int)$_GET['per_page'];
            }
            return $sp . '?' . http_build_query(array_merge($base, $params));
        };
        try {
            $pdo = DB::pdo();
            $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
            $cols_inv = $pdo->query("SHOW COLUMNS FROM {$tb_inv}")->fetchAll(PDO::FETCH_COLUMN);
            $has_inv_id_directorio = in_array('id_directorio_club', $cols_inv, true);
            $has_inv_id_usuario_vinculado = in_array('id_usuario_vinculado', $cols_inv, true);

            $cols_club_one = ['id', 'nombre', 'direccion', 'delegado', 'telefono', 'email'];
            if (in_array('id_directorio_club', $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_COLUMN), true)) {
                $cols_club_one[] = 'id_directorio_club';
            }
            $stmt = $pdo->prepare("SELECT " . implode(', ', $cols_club_one) . " FROM clubes WHERE id = ?");
            $stmt->execute([$club_id_one]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($club) {
                $stmt = $pdo->prepare("SELECT id FROM {$tb_inv} WHERE torneo_id = ? AND club_id = ?");
                $stmt->execute([$torneo_id, $club_id_one]);
                if ($stmt->fetch()) {
                    $invitacion_safe_redirect($build_redirect_one(['msg' => 'Ya estaba invitado.']));
                }
                $admin_club_id = (int) (Auth::id() ?? 0);
                $fechator = $torneo['fechator'] ?? date('Y-m-d');
                $acceso1 = date('Y-m-d', strtotime($fechator . ' -30 days'));
                $acceso2 = date('Y-m-d', strtotime($fechator . ' +7 days'));
                $token = bin2hex(random_bytes(32));
                $usuario_creador = (Auth::user() && isset(Auth::user()['id'])) ? (string) Auth::user()['id'] : '';
                $inv_delegado = $club['delegado'] ?? null;
                $inv_email = $club['email'] ?? null;
                $club_tel = $club['telefono'] ?? null;

                $inv_cols = ['torneo_id', 'club_id', 'admin_club_id', 'invitado_delegado', 'invitado_email', 'acceso1', 'acceso2', 'usuario', 'club_email', 'club_telefono', 'club_delegado', 'token', 'estado'];
                $inv_vals = [$torneo_id, $club_id_one, $admin_club_id, $inv_delegado, $inv_email, $acceso1, $acceso2, $usuario_creador, $inv_email, $club_tel, $inv_delegado, $token, 'activa'];
                if ($has_inv_id_directorio && isset($club['id_directorio_club']) && (int)$club['id_directorio_club'] > 0) {
                    $inv_cols[] = 'id_directorio_club';
                    $inv_vals[] = (int)$club['id_directorio_club'];
                }
                if ($has_inv_id_usuario_vinculado) {
                    $inv_cols[] = 'id_usuario_vinculado';
                    $inv_vals[] = null;
                }
                $placeholders = array_fill(0, count($inv_vals), '?');
                $stmt = $pdo->prepare("INSERT INTO {$tb_inv} (" . implode(', ', $inv_cols) . ") VALUES (" . implode(', ', $placeholders) . ")");
                $stmt->execute($inv_vals);
                $invitacion_safe_redirect($build_redirect_one(['success' => '1', 'msg' => 'Invitación creada. Lista para enviar al celular.']));
            }
        } catch (Exception $e) {
            if (function_exists('error_log')) {
                error_log('Invitacion_clubes invitar_uno: ' . $e->getMessage());
            }
            $errMsg = 'Error al crear la invitación.';
            $detail = $e->getMessage();
            if ($detail !== '' && strlen($detail) < 200) {
                $errMsg .= ' ' . $detail;
            }
            $invitacion_safe_redirect($build_redirect_one(['error' => $errMsg]));
        }
    }
}

// POST: quitar invitaciones y/o crear invitaciones para los clubes seleccionados
if ($torneo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'invitar_seleccionados') {
    // Evitar que cualquier salida accidental rompa el redirect (regla de oro: no echo/print_r antes de Location)
    ob_start();

    $sp_post = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php';
    $build_redirect = function (array $params) use ($sp_post) {
        return $sp_post . '?' . http_build_query($params);
    };

    try {
        CSRF::validate();
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('Invitacion_clubes CSRF: ' . $e->getMessage());
        }
        @ob_end_clean();
        $invitacion_safe_redirect($build_redirect(['page' => 'invitacion_clubes', 'torneo_id' => $torneo_id, 'error' => 'Sesión inválida o token expirado. Vuelva a intentar.']));
    }

    $messages = [];
    $params = ['page' => 'invitacion_clubes', 'torneo_id' => $torneo_id];

    // Quitar invitaciones (desmarcar): eliminar las seleccionadas
    $quitar_ids = isset($_POST['quitar_inv']) && is_array($_POST['quitar_inv'])
        ? array_map('intval', array_filter($_POST['quitar_inv'])) : [];
    if (!empty($quitar_ids)) {
        try {
            $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
            $pdo = DB::pdo();
            $deleted = 0;
            foreach ($quitar_ids as $inv_id) {
                $stmt = $pdo->prepare("SELECT id, torneo_id FROM {$tb_inv} WHERE id = ? AND torneo_id = ?");
                $stmt->execute([$inv_id, $torneo_id]);
                if ($stmt->fetch() && Auth::canAccessTournament($torneo_id)) {
                    $del = $pdo->prepare("DELETE FROM {$tb_inv} WHERE id = ?");
                    if ($del->execute([$inv_id])) $deleted++;
                }
            }
            if ($deleted > 0) {
                $messages[] = $deleted === 1 ? 'Se quitó 1 invitación.' : "Se quitaron {$deleted} invitaciones.";
            }
        } catch (Exception $e) {
            $messages[] = 'Error al quitar invitaciones: ' . $e->getMessage();
        }
    }

    $ids_club = isset($_POST['club_ids']) && is_array($_POST['club_ids'])
        ? array_map('intval', array_filter($_POST['club_ids'])) : [];
    $acceso1 = $_POST['acceso1'] ?? null;
    $acceso2 = $_POST['acceso2'] ?? null;
    if (empty($acceso1) || empty($acceso2)) {
        $fechator = $torneo['fechator'] ?? date('Y-m-d');
        $acceso1 = date('Y-m-d', strtotime($fechator . ' -30 days'));
        $acceso2 = date('Y-m-d', strtotime($fechator . ' +7 days'));
    }
    if ($acceso1 > $acceso2) {
        $acceso2 = $acceso1;
    }

    // Si solo se quitaron invitaciones (sin agregar nuevas), redirigir ya
    if (empty($ids_club)) {
        $params['success'] = '1';
        if (!empty($messages)) {
            $params['msg'] = implode(' ', $messages);
        }
        @ob_end_clean();
        $invitacion_safe_redirect($build_redirect($params));
    }

    $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
    $creadas = 0;
    $omitidas = 0;
    $errores = [];
    $pdo = DB::pdo();

    $cols_inv = $pdo->query("SHOW COLUMNS FROM {$tb_inv}")->fetchAll(PDO::FETCH_COLUMN);
    $has_inv_id_directorio = in_array('id_directorio_club', $cols_inv, true);
    $has_inv_id_usuario_vinculado = in_array('id_usuario_vinculado', $cols_inv, true);
    $cols_club_sel = ['id', 'nombre', 'direccion', 'delegado', 'telefono', 'email'];
    if (in_array('id_directorio_club', $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_COLUMN), true)) {
        $cols_club_sel[] = 'id_directorio_club';
    }

    // admin_club_id = ID del admin/organización que hace la invitación (valor por defecto 0)
    $admin_club_id = (int) (Auth::id() ?? 0);

    try {
        $pdo->beginTransaction();
        foreach ($ids_club as $club_id) {
            $stmt = $pdo->prepare("SELECT " . implode(', ', $cols_club_sel) . " FROM clubes WHERE id = ?");
            $stmt->execute([$club_id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$club) continue;
            $stmt = $pdo->prepare("SELECT id FROM {$tb_inv} WHERE torneo_id = ? AND club_id = ?");
            $stmt->execute([$torneo_id, $club_id]);
            if ($stmt->fetch()) {
                $omitidas++;
                continue;
            }
            $token = bin2hex(random_bytes(32));
            $usuario_creador = (Auth::user() && isset(Auth::user()['id'])) ? (string) Auth::user()['id'] : '';
            $inv_delegado = $club['delegado'] ?? null;
            $inv_email = $club['email'] ?? null;
            $club_tel = $club['telefono'] ?? null;

            $inv_cols = ['torneo_id', 'club_id', 'admin_club_id', 'invitado_delegado', 'invitado_email', 'acceso1', 'acceso2', 'usuario', 'club_email', 'club_telefono', 'club_delegado', 'token', 'estado'];
            $inv_vals = [$torneo_id, $club_id, $admin_club_id, $inv_delegado, $inv_email, $acceso1, $acceso2, $usuario_creador, $inv_email, $club_tel, $inv_delegado, $token, 'activa'];
            if ($has_inv_id_directorio && isset($club['id_directorio_club']) && (int)$club['id_directorio_club'] > 0) {
                $inv_cols[] = 'id_directorio_club';
                $inv_vals[] = (int)$club['id_directorio_club'];
            }
            if ($has_inv_id_usuario_vinculado) {
                $inv_cols[] = 'id_usuario_vinculado';
                $inv_vals[] = null;
            }
            $placeholders = array_fill(0, count($inv_vals), '?');
            $stmt = $pdo->prepare("INSERT INTO {$tb_inv} (" . implode(', ', $inv_cols) . ") VALUES (" . implode(', ', $placeholders) . ")");
            $stmt->execute($inv_vals);
            $creadas++;
        }
        $pdo->commit();

        $params = ['page' => 'invitacion_clubes', 'torneo_id' => $torneo_id, 'success' => '1'];
        $msg_parts = $messages;
        if ($creadas > 0) {
            $msg_parts[] = $creadas === 1 ? 'Se creó 1 invitación.' : "Se crearon {$creadas} invitaciones.";
        }
        if ($omitidas > 0) {
            $msg_parts[] = "{$omitidas} ya estaban invitados.";
        }
        if (!empty($msg_parts)) {
            $params['msg'] = implode(' ', $msg_parts);
        }
        if (!empty($errores)) {
            $params['error'] = implode(' ', $errores);
        }
        @ob_end_clean();
        $invitacion_safe_redirect($build_redirect($params));
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('Invitacion_clubes: ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']');
        }
        @ob_end_clean();
        $params = ['page' => 'invitacion_clubes', 'torneo_id' => $torneo_id, 'error' => 'Error al crear invitaciones. Revise logs. ' . $e->getMessage()];
        $invitacion_safe_redirect($build_redirect($params));
    }
}

$page_title = $torneo ? ('Invitación de clubes - ' . ($torneo['nombre'] ?? '')) : 'Invitación de clubes';
$fechator_fmt = $torneo && !empty($torneo['fechator']) ? date('d/m/Y', strtotime($torneo['fechator'])) : '';
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="fas fa-paper-plane me-2"></i>Invitación de clubes
                    </h1>
                    <p class="text-muted mb-0">
                        <?php if ($torneo): ?>
                            Torneo: <strong><?= htmlspecialchars($torneo['nombre'] ?? '') ?></strong>
                            <?php if ($fechator_fmt): ?> — <?= $fechator_fmt ?><?php endif; ?>
                        <?php else: ?>
                            Seleccione clubes para invitarlos al torneo.
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if ($torneo): ?>
                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations')) ?>&filter_torneo=<?= $torneo_id ?>&return_to=invitacion_clubes&torneo_id=<?= $torneo_id ?>" class="btn btn-outline-primary">
                            <i class="fas fa-list me-2"></i>Ver invitaciones del torneo
                        </a>
                        <?php
                        $back_url = 'index.php?page=torneo_gestion&action=panel&torneo_id=' . $torneo_id;
                        if (defined('APP_ROOT') && strpos($_SERVER['REQUEST_URI'] ?? '', 'admin_torneo') !== false) {
                            $back_url = 'admin_torneo.php?action=panel&torneo_id=' . $torneo_id;
                        }
                        ?>
                        <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Volver al panel
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($torneo && !empty($clubs_list)): ?>
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="fas fa-users me-2"></i>Clubes — marque los que desea invitar; en los ya invitados marque el cuadro para <strong>quitar</strong> la invitación</span>
                        <span class="badge bg-secondary"><?= count($ya_invitados) ?> ya invitados a este torneo</span>
                    </div>
                    <?php $form_action_sp = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php'; ?>
                    <form method="post" action="<?= htmlspecialchars($form_action_sp) ?>?page=invitacion_clubes&torneo_id=<?= (int)$torneo_id ?>" id="formInvitar">
                        <?= CSRF::input() ?>
                        <input type="hidden" name="action" value="invitar_seleccionados">
                        <input type="hidden" name="acceso1" value="<?= htmlspecialchars(date('Y-m-d', strtotime(($torneo['fechator'] ?? 'today') . ' -30 days'))) ?>">
                        <input type="hidden" name="acceso2" value="<?= htmlspecialchars(date('Y-m-d', strtotime(($torneo['fechator'] ?? 'today') . ' +7 days'))) ?>">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 44px;" class="text-center">
                                                <input type="checkbox" id="selectAll" class="form-check-input" title="Marcar todos">
                                            </th>
                                            <th style="width: 50px;">Logo</th>
                                            <th>Nombre</th>
                                            <th>Delegado</th>
                                            <th>Teléfono</th>
                                            <th class="text-center">Estado</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $url_base_join = rtrim(AppHelpers::getPublicUrl(), '/') . '/join';
                                        $url_base_inv = rtrim(AppHelpers::getPublicUrl(), '/') . '/invitation/digital?token=';
                                        $torneo_nombre_inv = $torneo['nombre'] ?? '';
                                        foreach ($clubs_list as $row):
                                            $club_id_row = (int)($row['id'] ?? 0);
                                            $ya_invitado = $club_id_row > 0 && in_array($club_id_row, $ya_invitados, true);
                                            $inv_data = $ya_invitado ? ($invitaciones_por_club[$club_id_row] ?? null) : null;
                                            if ($inv_data) {
                                                $url_join = InvitationJoinResolver::buildJoinUrl($inv_data['token']);
                                                $url_tarjeta = $url_base_inv . urlencode($inv_data['token']);
                                                $requiere_reg = !empty($inv_data['requiere_registro']);
                                                $msg_wa = "Estimado delegado de " . ($row['nombre'] ?? '') . ", le invitamos a " . $torneo_nombre_inv . ". Use este enlace para acceder: " . $url_join;
                                                if ($requiere_reg) {
                                                    $msg_wa .= " — Si aún no está registrado, el primer paso es completar el registro en ese mismo enlace.";
                                                } else {
                                                    $msg_wa .= " — Acceso directo al formulario de inscripción.";
                                                }
                                                $url_wa = 'https://api.whatsapp.com/send?text=' . rawurlencode($msg_wa);
                                                $url_tg = 'https://t.me/share/url?url=' . rawurlencode($url_join) . '&text=' . rawurlencode($msg_wa);
                                            }
                                        ?>
                                            <tr class="<?= $ya_invitado ? 'table-warning' : '' ?>">
                                                <td class="text-center align-middle">
                                                    <?php if (!$ya_invitado): ?>
                                                        <input type="checkbox" name="club_ids[]" value="<?= (int)$row['id'] ?>" class="form-check-input cb-club">
                                                    <?php else: ?>
                                                        <input type="checkbox" name="quitar_inv[]" value="<?= (int)$inv_data['id'] ?>" class="form-check-input cb-quitar" title="Marcar para quitar esta invitación">
                                                    <?php endif; ?>
                                                </td>
                                                <td class="align-middle"><?= displayClubLogoTable($row) ?></td>
                                                <td class="align-middle">
                                                    <strong><?= htmlspecialchars($row['nombre'] ?? '') ?></strong>
                                                    <?php if (!empty($row['direccion'])): ?>
                                                        <br><small class="text-muted text-truncate d-inline-block" style="max-width: 220px;"><?= htmlspecialchars($row['direccion']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="align-middle small"><?= htmlspecialchars($row['delegado'] ?? '—') ?></td>
                                                <td class="align-middle small"><?= htmlspecialchars($row['telefono'] ?? '—') ?></td>
                                                <td class="text-center align-middle">
                                                    <?php
                                                    $estatus_club = (int)($row['estatus'] ?? 1);
                                                    $es_de_org = isset($org_id) && isset($row['cod_org']) && (int)$row['cod_org'] === $org_id;
                                                    ?>
                                                    <?php if ($es_de_org): ?>
                                                        <span class="badge bg-primary" title="Club de la organización que organiza este torneo">Mi organización</span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-<?= $estatus_club === 9 ? 'info' : ($estatus_club ? 'success' : 'secondary') ?>"><?= $estatus_club === 9 ? 'Procede del directorio' : ($estatus_club ? 'Activo' : 'Inactivo') ?></span>
                                                    <?php if ($ya_invitado): ?>
                                                        <br><span class="badge bg-info mt-1">Ya invitado</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center align-middle">
                                                    <?php if ($ya_invitado && $inv_data): ?>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <a href="index.php?page=invitations&action=edit&id=<?= $inv_data['id'] ?>&filter_torneo=<?= $torneo_id ?>&return_to=invitacion_clubes&torneo_id=<?= $torneo_id ?>" class="btn btn-outline-warning" title="Editar Invitación"><i class="fas fa-edit"></i></a>
                                                            <button type="button" class="btn btn-outline-primary btn-copy-join" data-url="<?= htmlspecialchars($url_join) ?>" title="Copiar enlace de acceso (registro o inscripción)"><i class="fas fa-link"></i></button>
                                                            <a href="<?= htmlspecialchars($url_wa) ?>" class="btn btn-success" target="_blank" rel="noopener noreferrer" title="Enviar por WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                                            <a href="<?= htmlspecialchars($url_tg) ?>" class="btn btn-info" target="_blank" rel="noopener noreferrer" title="Enviar por Telegram"><i class="fab fa-telegram"></i></a>
                                                        </div>
                                                        <?php if (!empty($inv_data['requiere_registro'])): ?>
                                                            <br><small class="text-muted" title="El delegado debe registrarse primero">Requiere registro</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php
                                                        $url_invitar_uno = 'index.php?page=invitacion_clubes&torneo_id=' . (int)$torneo_id . '&action=invitar_uno&club_id=' . (int)$row['id'];
                                                        if (isset($_GET['p']) && (int)$_GET['p'] >= 1) {
                                                            $url_invitar_uno .= '&p=' . (int)$_GET['p'];
                                                        }
                                                        if (isset($_GET['per_page']) && (int)$_GET['per_page'] >= 10) {
                                                            $url_invitar_uno .= '&per_page=' . (int)$_GET['per_page'];
                                                        }
                                                        ?>
                                                        <a href="<?= htmlspecialchars($url_invitar_uno) ?>" class="btn btn-sm btn-primary" title="Crear invitación para este club"><i class="fas fa-paper-plane me-1"></i>Invitar</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <button type="submit" class="btn btn-primary" id="btnInvitar">
                                    <i class="fas fa-paper-plane me-2"></i>Invitar clubes seleccionados
                                </button>
                            </div>
                            <?php if ($pagination): ?>
                                <div><?= $pagination->render() ?></div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                                                <script>
                                                document.getElementById('selectAll').addEventListener('change', function() {
                                                    document.querySelectorAll('.cb-club').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
                                                });
                                                document.getElementById('formInvitar').addEventListener('submit', function() {
                                                    var n = document.querySelectorAll('.cb-club:checked').length;
                                                    var q = document.querySelectorAll('.cb-quitar:checked').length;
                                                    if (n === 0 && q === 0) {
                                                        alert('Seleccione al menos un club para invitar o marque "Quitar invitación" en los que desee desmarcar.');
                                                        return false;
                                                    }
                                                    document.getElementById('btnInvitar').disabled = true;
                                                });
                                                document.querySelectorAll('.btn-copy-join').forEach(function(btn) {
                                                    btn.addEventListener('click', function() {
                                                        var url = this.getAttribute('data-url');
                                                        if (url && navigator.clipboard && navigator.clipboard.writeText) {
                                                            navigator.clipboard.writeText(url).then(function() { alert('Enlace copiado.'); }).catch(function() { prompt('Copie el enlace:', url); });
                                                        } else {
                                                            prompt('Copie el enlace:', url);
                                                        }
                                                    });
                                                });
                                                </script>
            <?php elseif ($torneo && empty($clubs_list)): ?>
                <div class="card">
                    <div class="card-body text-center text-muted py-5">
                        <i class="fas fa-address-book fa-3x mb-3"></i>
                        <p class="mb-0">No hay clubes registrados. Agregue clubes desde <a href="<?= htmlspecialchars(AppHelpers::dashboard('clubs')) ?>">Clubes</a>.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
