<?php
/**
 * Página dedicada: Administrador de Torneo y Operadores.
 * Admin Organización: operadores de su organización.
 * Admin General (ámbito nacional): operadores de cualquier asociación.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/ClubHelper.php';

Auth::requireRole(['admin_general', 'admin_club', 'superadmin']);

$current_user = Auth::user();
$is_admin_club = $current_user['role'] === 'admin_club';
$pdo = DB::pdo();

$return_torneo_id = isset($_GET['return_torneo_id']) ? (int) $_GET['return_torneo_id'] : 0;

// Club a gestionar: admin_club = su organización; admin_general = filtro opcional (0 = todas las asociaciones)
$club_id = null;
if ($is_admin_club) {
    $club_id = (int)($current_user['club_id'] ?? 0);
} else {
    $club_id = isset($_GET['club_id']) ? (int) $_GET['club_id'] : 0;
}

$tab = trim($_GET['tab'] ?? 'admin_torneo');
if (!in_array($tab, ['admin_torneo', 'operadores'], true)) {
    $tab = 'admin_torneo';
}

// Lista de clubes para selector (solo admin_general)
$clubes_options = [];
if (!$is_admin_club) {
    $stmt = $pdo->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
    $clubes_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Usuarios admin_torneo y operadores
$admin_torneo_list = [];
$operadores_list = [];
$club_ids = [];

if ($is_admin_club) {
    $club_ids = ClubHelper::getClubesByAdminClubId((int)$current_user['id']);
    if (empty($club_ids) && Auth::getUserOrganizacionId()) {
        $club_ids = ClubHelper::getClubesByOrganizacionId((int)Auth::getUserOrganizacionId());
    }
} elseif ($club_id > 0) {
    $club_ids = [$club_id];
}

if (!$is_admin_club && $club_id <= 0) {
    $stmt = $pdo->query("
        SELECT u.id, u.cedula, u.nombre, u.username, u.email, u.celular, u.role, u.status, u.created_at,
               c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.role = 'admin_torneo'
        ORDER BY c.nombre ASC, u.nombre ASC
    ");
    $admin_torneo_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT u.id, u.cedula, u.nombre, u.username, u.email, u.celular, u.role, u.status, u.created_at,
               c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.role = 'operador'
        ORDER BY c.nombre ASC, u.nombre ASC
    ");
    $operadores_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($club_ids)) {
    $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
    $params = $club_ids;

    // Solo condición: estar en la organización del admin (club_id en clubes supervisados)
    $stmt = $pdo->prepare("
        SELECT u.id, u.cedula, u.nombre, u.username, u.email, u.celular, u.role, u.status, u.created_at,
               c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.club_id IN ($placeholders) AND u.role = 'admin_torneo'
        ORDER BY u.nombre ASC
    ");
    $stmt->execute($params);
    $admin_torneo_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT u.id, u.cedula, u.nombre, u.username, u.email, u.celular, u.role, u.status, u.created_at,
               c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.club_id IN ($placeholders) AND u.role = 'operador'
        ORDER BY u.nombre ASC
    ");
    $stmt->execute($params);
    $operadores_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$success_message = $_SESSION['success_message'] ?? null;
$errors = $_SESSION['errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
if (isset($_SESSION['success_message'])) unset($_SESSION['success_message']);
if (isset($_SESSION['errors'])) unset($_SESSION['errors']);
if (isset($_SESSION['form_data'])) unset($_SESSION['form_data']);

$entidades_options = [];
try {
    if ($is_admin_club) {
        $organizacion_id = Auth::getUserOrganizacionId();
        if ($organizacion_id) {
            $stmt = $pdo->prepare("SELECT entidad FROM organizaciones WHERE id = ? AND estatus = 1");
            $stmt->execute([$organizacion_id]);
            $entidad_cod = $stmt->fetchColumn();
            if ($entidad_cod !== null && $entidad_cod !== '') {
                $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
                $codeCol = $nameCol = null;
                foreach ($cols as $c) {
                    $f = strtolower($c['Field'] ?? '');
                    if (in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) $codeCol = $c['Field'];
                    if (in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) $nameCol = $c['Field'];
                }
                if ($codeCol && $nameCol) {
                    $stmt = $pdo->prepare("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad WHERE {$codeCol} = ?");
                    $stmt->execute([$entidad_cod]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $entidades_options = $row ? [$row] : [];
                }
            }
        }
    }
    if (empty($entidades_options)) {
        $ent_file = __DIR__ . '/../config/entidades.php';
        if (file_exists($ent_file)) {
            $entidades_options = require $ent_file;
        }
        if (empty($entidades_options)) {
            $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
            if ($cols) {
                $codeCol = $cols[0]['Field'] ?? null;
                $nameCol = isset($cols[1]) ? ($cols[1]['Field'] ?? null) : null;
                foreach ($cols as $c) {
                    $f = strtolower($c['Field'] ?? '');
                    if (in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) $codeCol = $c['Field'];
                    if (in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) $nameCol = $c['Field'];
                }
                if ($codeCol && $nameCol) {
                    $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
                    $entidades_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }
} catch (Exception $e) {}

require_once __DIR__ . '/../lib/app_helpers.php';
$base_url = rtrim(AppHelpers::getBaseUrl(), '/') . '/public';
// URL base para llamadas API: usar /public para que la búsqueda use public/api/search_user_persona.php (misma lógica que api/ raíz)
$api_base = rtrim(AppHelpers::getBaseUrl(), '/') . '/public';
// URL para formularios POST (asignar admin_torneo/operador)
$form_action_users = $base_url . '/index.php?page=users';

include __DIR__ . '/admin_torneo_operadores/list.php';
