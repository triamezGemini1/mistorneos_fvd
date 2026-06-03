<?php
// layout.php
// La autenticación ya se verificó en index.php. Usar $page pasado por index.php para no perder la página en entornos donde $_GET se pierde (proxy/beta).
$user = $_SESSION['user'] ?? null;
if (!$user || !is_array($user)) {
    if (!headers_sent()) {
        require_once __DIR__ . '/../../config/auth_service.php';
        AuthService::requireAuth();
        exit;
    }
    echo '<div class="container p-4"><div class="alert alert-warning">Sesión no válida. <a href="' . (class_exists('AppHelpers') ? AppHelpers::getPublicUrl() . '/login.php' : 'login.php') . '">Iniciar sesión</a>.</div></div>';
    return;
}
require_once __DIR__ . '/../../config/auth.php';
if (!class_exists('FvdConfig', false) && is_file(__DIR__ . '/../../lib/FvdConfig.php')) {
    require_once __DIR__ . '/../../lib/FvdConfig.php';
}
$current_page = (isset($page) && $page !== '') ? $page : ($_GET['page'] ?? 'home');
/** Panel operativo asociación: sin barra lateral (ancho completo) */
$layout_operativo_asoc = Auth::isOperativoSoloAsociacion();
$layout_hide_sidebar = ($current_page === 'asociacion_panel') || $layout_operativo_asoc;
$layout_nav_action = trim((string) ($_GET['action'] ?? ''));
require_once __DIR__ . '/../../lib/ReportReturnNavigation.php';
ReportReturnNavigation::updateSessionFromRequest($current_page, $layout_nav_action);

require_once __DIR__ . '/../../lib/app_helpers.php';
if (!class_exists('FvdBranding', false) && is_file(__DIR__ . '/../../lib/FvdBranding.php')) {
    require_once __DIR__ . '/../../lib/FvdBranding.php';
}

// Base web de public/: path para <base> y assets; URL completa para fetch/AJAX
$layout_public_href = class_exists('AppHelpers') ? AppHelpers::getPublicBaseHref() : '/';
$layout_asset_base = class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : '';
if ($layout_asset_base === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $layout_asset_base = $scheme . '://' . $host . rtrim($layout_public_href, '/');
}
$layout_asset_href = static function (string $rel) use ($layout_public_href): string {
    return htmlspecialchars(AppHelpers::assetHref($rel, rtrim($layout_public_href, '/')));
};
$layout_logo_href = rtrim($layout_public_href, '/');

// Base del menú: usar URL_BASE (path) para que enlaces no apunten a la raíz del dominio y la sesión persista en subcarpeta
if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
    // Enlaces con path absoluto desde raíz del servidor: /pruebas/public/index.php?page=...
    $dashboard_href = function ($page, array $params = []) {
        $params['page'] = $page;
        return URL_BASE . 'index.php?' . http_build_query($params);
    };
    $menu_url = function ($path) {
        return URL_BASE . ltrim($path, '/');
    };
} else {
    $menu_base = '';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $menu_script_dir = dirname($_SERVER['SCRIPT_NAME']);
        if ($menu_script_dir !== '.' && $menu_script_dir !== '') {
            $menu_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $menu_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $menu_base = $menu_scheme . '://' . $menu_host . str_replace('\\', '/', $menu_script_dir);
        }
    }
    if ($menu_base === '') {
        $menu_base = rtrim($layout_asset_base, '/');
    }
    $dashboard_href = function ($page, array $params = []) use ($menu_base) {
        $params['page'] = $page;
        return $menu_base . '/index.php?' . http_build_query($params);
    };
    $menu_url = function ($path) use ($menu_base) {
        return $menu_base . '/' . ltrim($path, '/');
    };
}

// Logo y nombre para el identificador del dashboard (organización cuando no es admin_general)
$dashboard_org = Auth::getDashboardOrganizacion();

// Rol base real (útil cuando admin_general usa switch de perfil)
$role_original_layout = (string)($user['role_original'] ?? $user['role'] ?? '');
$role_activo_layout = (string)($user['role'] ?? '');
$is_admin_general_base = ($role_original_layout === 'admin_general');

$solicitudes_pendientes = 0;

// Contar actas pendientes de verificación (admin_club, admin_general y admin_torneo)
$actas_pendientes_count = 0;
if (in_array($user['role'], ['admin_club', 'admin_general', 'admin_torneo'], true)) {
    try {
        require_once __DIR__ . '/../../lib/ActasPendientesHelper.php';
        $actas_pendientes_count = ActasPendientesHelper::contar();
    } catch (Exception $e) {
        $actas_pendientes_count = 0;
    }
}

$show_link_panel_asociacion = false;
try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
    $show_link_panel_asociacion = (Auth::isOperativoSoloAsociacion() || AsociacionAdminHelper::usuarioEsDelegadoAsociacion(DB::pdo(), (int) ($user['id'] ?? 0)))
        && ($current_page ?? '') !== 'asociacion_panel';
} catch (Throwable $e) {
    $show_link_panel_asociacion = false;
}

$fvd_nombre_layout = FvdBranding::nombre();
$header_title = 'Dashboard - ' . htmlspecialchars($fvd_nombre_layout);
$modo_prueba_activo = ($role_original_layout === 'admin_general' && $role_activo_layout !== 'admin_general');
$role_human = [
  'admin_general' => 'Admin General',
  'admin_club' => 'Admin Organización',
  'admin_torneo' => 'Admin Torneo',
  'operador' => 'Operador',
  'usuario' => 'Usuario Común',
];
$role_activo_human = $role_human[$role_activo_layout] ?? $role_activo_layout;
$role_badge_class = [
  'admin_club' => 'bg-primary text-white',
  'admin_torneo' => 'bg-info text-dark',
  'operador' => 'bg-danger text-white',
  'usuario' => 'bg-success text-white',
];
$modo_prueba_badge_class = $role_badge_class[$role_activo_layout] ?? 'bg-warning text-dark';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php $header_embedded = true; include_once __DIR__ . '/../../includes/header.php'; ?>
  <base href="<?= htmlspecialchars($layout_public_href) ?>">
  <meta name="description" content="Panel de administración de <?= htmlspecialchars(class_exists('FvdBranding') ? FvdBranding::nombre() : 'FVD') ?> — Gestión de torneos, inscripciones y resultados">
  <?php if (class_exists('FvdBranding', false)): ?>
  <style><?= FvdBranding::inlineCssBlock() ?></style>
  <?php endif; ?>
  <meta name="robots" content="noindex, nofollow">
  <meta name="language" content="es">
  <?php require_once __DIR__ . '/vendor_assets.php'; ?>
  <link href="<?= $layout_asset_href('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= $layout_asset_href('assets/vendor/fontawesome/css/all.min.css') ?>" rel="stylesheet">
  <link href="<?= $layout_asset_href('assets/vendor/sweetalert2/sweetalert2.min.css') ?>" rel="stylesheet">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/dist/output.css') ?>">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/dashboard.css') ?>">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/app-search.css') ?>">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/custom-13inch.css') ?>">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/fvd-dashboard-compact.css') ?>">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/fvd-tokens.css') ?>">
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/fvd-identidad.css') ?>">
  <?php if (($current_page ?? '') === 'home'): ?>
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/fvd-dashboard-home-page.css') ?>">
  <?php endif; ?>
  <?php if (($current_page ?? '') === 'registrants'): ?>
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/registrants-page.css') ?>">
  <?php endif; ?>
  <?php if (($current_page ?? '') === 'torneo_gestion' && ($_GET['action'] ?? '') === 'inscribir_sitio'): ?>
  <link rel="stylesheet" href="<?= $layout_asset_href('assets/css/inscribir-sitio-page.css') ?>">
  <?php endif; ?>
</head>
<?php
$is_panel_control_torneos = ($current_page === 'torneo_gestion' && ($_GET['action'] ?? '') === 'panel');
$nav_origin = '';
$from_url = $_GET['from'] ?? $_GET['return_to'] ?? '';
if ($from_url !== '') {
    $decoded = rawurldecode($from_url);
    $safe = false;
    if (strpos($decoded, 'http') !== 0) {
        $safe = true;
    } elseif (isset($_SERVER['HTTP_HOST'])) {
        $host = parse_url($decoded, PHP_URL_HOST);
        $safe = ($host === null || $host === $_SERVER['HTTP_HOST']);
    }
    if ($safe) {
        $nav_origin = htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    }
}
if ($nav_origin === '' && ReportReturnNavigation::isReportView($current_page, $layout_nav_action)) {
    $storedReturn = ReportReturnNavigation::getStoredReturnRelativeUrl();
    $nav_origin = htmlspecialchars($storedReturn !== '' ? $storedReturn : AppHelpers::landingUrl(), ENT_QUOTES, 'UTF-8');
}
$body_page_extra = '';
if ($current_page === 'torneo_gestion') {
    $body_page_extra .= ' page-torneo-gestion';
    $tg_action_layout = trim((string)($_GET['action'] ?? 'index'));
    if ($tg_action_layout === '') {
        $tg_action_layout = 'index';
    }
    if ($tg_action_layout === 'index') {
        $body_page_extra .= ' page-torneo-gestion-index';
    }
    if ($tg_action_layout === 'registrar_resultados' || $tg_action_layout === 'registrar_resultados_v2') {
        $body_page_extra .= ' page-registrar-resultados';
    }
    if ($tg_action_layout === 'inscribir_sitio') {
        $body_page_extra .= ' page-inscribir-sitio';
    }
}
if ($current_page === 'estadisticas_torneos') {
    $body_page_extra .= ' page-estadisticas-torneos';
}
if ($current_page === 'registrants') {
    $body_page_extra .= ' page-registrants';
}
if ($current_page === 'home') {
    $body_page_extra .= ' page-dashboard-home';
}
?>
<body class="bg-light fvd-dashboard-compact<?= $is_panel_control_torneos ? ' page-panel-control-torneos' : '' ?><?= !empty($layout_hide_sidebar) ? ' layout-no-sidebar' : '' ?><?= htmlspecialchars($body_page_extra, ENT_QUOTES, 'UTF-8') ?>"<?= $nav_origin !== '' ? ' data-nav-origin="' . $nav_origin . '"' : '' ?>>
  <!-- Contenedor para notificaciones toast (Push + tarjeta visual) -->
  <div id="notification-container" aria-live="polite"></div>

  <!-- Mensajes flash (éxito/error) superpuestos, no desplazan el contenido -->
  <div id="app-flash-messages" class="app-flash-messages" aria-live="polite">
    <?php
    $flash_success = $_SESSION['success'] ?? $_SESSION['success_message'] ?? null;
    $flash_error   = $_SESSION['error'] ?? $_SESSION['error_message'] ?? null;
    $flash_warning = $_SESSION['warning'] ?? $_SESSION['warning_message'] ?? null;
    $flash_info    = $_SESSION['info'] ?? $_SESSION['info_message'] ?? null;
    if ($flash_success) { unset($_SESSION['success'], $_SESSION['success_message']); ?>
    <div class="alert alert-success alert-dismissible fade show app-flash-item" role="alert">
      <?= htmlspecialchars($flash_success) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php }
    if ($flash_error) { unset($_SESSION['error'], $_SESSION['error_message']); ?>
    <div class="alert alert-danger alert-dismissible fade show app-flash-item" role="alert">
      <?= htmlspecialchars($flash_error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php }
    if ($flash_warning) { unset($_SESSION['warning'], $_SESSION['warning_message']); ?>
    <div class="alert alert-warning alert-dismissible fade show app-flash-item" role="alert">
      <?= htmlspecialchars($flash_warning) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php }
    if ($flash_info) { unset($_SESSION['info'], $_SESSION['info_message']); ?>
    <div class="alert alert-info alert-dismissible fade show app-flash-item" role="alert">
      <?= htmlspecialchars($flash_info) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php } ?>
  </div>

  <div class="d-flex fvd-app-shell" id="wrapper">
    
    <?php if (empty($layout_hide_sidebar)): ?>
    <!-- Sidebar -->
    <nav id="sidebar" class="bg-dark text-white border-end shadow d-flex flex-column">
      <div class="sidebar-header p-3 border-bottom">
        <h4 class="mb-0 text-center d-flex align-items-center justify-content-center flex-nowrap">
          <?= AppHelpers::appLogo('me-2', $fvd_nombre_layout, 35, true, $layout_logo_href) ?>
          <span class="sidebar-brand text-truncate" title="<?= htmlspecialchars($fvd_nombre_layout) ?>"><?= htmlspecialchars(FvdBranding::siglas()) ?></span>
        </h4>
      </div>
      
      <ul class="list-unstyled px-2 py-2 flex-grow-1 overflow-y-auto min-h-0">
        <?php if ($user['role'] !== 'admin_general'): ?>
        <!-- Inicio (estadísticas) y Calendario -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('home')) ?>" class="nav-link <?= $current_page === 'home' ? 'active' : '' ?>">
            <i class="fas fa-chart-line me-3"></i>
            <span class="nav-text">Estadísticas</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('calendario')) ?>" class="nav-link <?= $current_page === 'calendario' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt me-3"></i>
            <span class="nav-text">Calendario</span>
          </a>
        </li>
        <?php endif; ?>
        
        <?php if ($user['role'] === 'admin_club'): ?>
        <?php
        // Detectar si estamos en una página de gestión de torneos
        $is_torneo_gestion = ($current_page === 'torneo_gestion');
        
        // Obtener torneo_id desde diferentes fuentes
        $torneo_id_selected = (int)($_GET['torneo_id'] ?? $_REQUEST['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
        if ($torneo_id_selected === 0 && isset($_SESSION['current_torneo_id'])) {
          $torneo_id_selected = (int)$_SESSION['current_torneo_id'];
        }
        
        $torneo_action = $_GET['action'] ?? $_REQUEST['action'] ?? '';
        $is_torneo_menu_active = $is_torneo_gestion || in_array($torneo_action, ['index', 'panel', 'panel_equipos', 'reportes_inscritos', 'mesas', 'rondas', 'posiciones', 'galeria_fotos', 'inscripciones', 'notificaciones', 'inscribir_sitio', 'inscribir_equipo_sitio', 'carga_masiva_equipos_sitio', 'carga_masiva_equipos_plantilla', 'carga_masiva_parejas_sitio', 'carga_masiva_parejas_plantilla', 'gestionar_inscripciones_equipos', 'sustituir_jugador', 'cuadricula', 'hojas_anotacion', 'registrar_resultados', 'registrar_resultados_v2', 'agregar_mesa', 'reasignar_mesa', 'podio', 'podios', 'podios_equipos', 'resultados_por_club', 'resultados_reportes', 'resultados_general', 'resultados_equipos_resumido', 'resultados_equipos_detallado', 'resumen_individual', 'equipos', 'verificar_actas', 'verificar_acta', 'verificar_actas_index', 'verificar_resultados']) || in_array($current_page, ['invitations', 'notificaciones_masivas']);
        $is_torneo_submenu_open = $torneo_id_selected > 0 || $is_torneo_menu_active;
        
        if ($torneo_id_selected > 0) {
          if ($current_page === 'registrants') { $is_torneo_menu_active = true; $is_torneo_submenu_open = true; $torneo_action = 'inscripciones'; }
          elseif ($current_page === 'player_invitations' || $current_page === 'tournaments/invitation_link') { $is_torneo_menu_active = true; $is_torneo_submenu_open = true; }
        }
        
        $filtro_actual_ac = $_GET['filtro'] ?? '';
        $admin_club_org_id = Auth::getUserOrganizacionId();
        ?>
        
        <!-- Mi Organización: acceso único y canónico -->
        <?php if ($admin_club_org_id): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('organizaciones', ['id' => $admin_club_org_id])) ?>" class="nav-link <?= ($current_page === 'organizaciones' && (int)($_GET['id'] ?? 0) === $admin_club_org_id) ? 'active' : '' ?>">
            <i class="fas fa-building me-3"></i>
            <span class="nav-text">Mi Organización</span>
          </a>
        </li>
        <?php endif; ?>
        <!-- Menú al mismo nivel (sin agrupación Organizaciones) -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('torneo_gestion', ['action' => 'index'])) ?>" class="nav-link <?= ($current_page === 'torneo_gestion' && ($_GET['action'] ?? '') === 'index') ? 'active' : '' ?>">
            <i class="fas fa-trophy me-3"></i>
            <span class="nav-text">Torneos</span>
          </a>
        </li>
        <?php if (!empty($show_link_panel_asociacion) || (($user['role'] ?? '') === 'admin_club' && (int) ($user['entidad'] ?? 0) > 0)): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('finanzas/resumen_asociacion')) ?>" class="nav-link <?= $current_page === 'finanzas/resumen_asociacion' ? 'active' : '' ?>">
            <i class="fas fa-coins me-3"></i>
            <span class="nav-text">Finanzas asociación</span>
          </a>
        </li>
        <?php endif; ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('admin_torneo_operadores')) ?>" class="nav-link <?= $current_page === 'admin_torneo_operadores' ? 'active' : '' ?>">
            <i class="fas fa-user-cog me-3"></i>
            <span class="nav-text">Admin Torneo y Operadores</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('cuentas_bancarias')) ?>" class="nav-link <?= $current_page === 'cuentas_bancarias' ? 'active' : '' ?>">
            <i class="fas fa-university me-3"></i>
            <span class="nav-text">Cuentas Bancarias</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('bannerclock')) ?>" class="nav-link <?= $current_page === 'bannerclock' ? 'active' : '' ?>">
            <i class="fas fa-bullhorn me-3"></i>
            <span class="nav-text">Banner Reloj</span>
          </a>
        </li>
        <!-- Comentarios -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('comments_public')) ?>" class="nav-link <?= $current_page === 'comments_public' ? 'active' : '' ?>">
            <i class="fas fa-comment-dots me-3"></i>
            <span class="nav-text">Comentarios</span>
          </a>
        </li>
        <!-- 1. Portal Público -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <!-- 1. Manual de Usuario -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('manuales_web/manual_usuario.php')) ?>" class="nav-link">
            <i class="fas fa-book me-3"></i>
            <span class="nav-text">Manual de Usuario</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php endif; ?>
        
        <?php if (Auth::isAdminGeneral()): ?>
        <?php
        $is_inicio_open = in_array($current_page, ['home', 'calendario']);
        $is_estructura_open = in_array($current_page, ['clubs', 'directorio_clubes']);
        $is_comunicacion_open = in_array($current_page, ['notificaciones_masivas', 'whatsapp_config', 'comments']);
        $is_integraciones_open = in_array($current_page, ['admin_atletas_sync', 'importacion_torneo_externo', 'torneo_split_ranking']);
        ?>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_inicio_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('inicio-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-home me-3"></i>
            <span class="nav-text">Inicio</span>
            <i class="fas fa-chevron-<?= $is_inicio_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_inicio_open ? 'show' : '' ?>" id="inicio-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('home')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'home' ? 'active' : '' ?>">
                <i class="fas fa-chart-line me-2"></i>
                <span>Estadísticas</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('calendario')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'calendario' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt me-2"></i>
                <span>Calendario</span>
              </a>
            </li>
          </ul>
        </li>
        <?php
        $nav_fvd_org_id = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_ID : 1;
        $nav_mi_org_href = $dashboard_href('organizaciones', ['id' => $nav_fvd_org_id]);
        $nav_mi_org_active = in_array($current_page, ['organizaciones', 'mi_organizacion'], true)
            && (int) ($_GET['id'] ?? $nav_fvd_org_id) === $nav_fvd_org_id;
        ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($nav_mi_org_href) ?>" class="nav-link <?= $nav_mi_org_active ? 'active' : '' ?>">
            <i class="fas fa-building me-3"></i>
            <span class="nav-text">Mi organización</span>
          </a>
        </li>
        <!-- 2. Estructura (acordeón: entidades / asociaciones) -->
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_estructura_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('estructura-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-sitemap me-3"></i>
            <span class="nav-text">Estructura</span>
            <i class="fas fa-chevron-<?= $is_estructura_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_estructura_open ? 'show' : '' ?>" id="estructura-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('clubs')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'clubs' ? 'active' : '' ?>">
                <i class="fas fa-building me-2"></i>
                <span>Asociaciones</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('clubs', ['action' => 'new'])) ?>" class="nav-link nav-sub-sub-link <?= ($current_page === 'clubs' && ($_GET['action'] ?? '') === 'new') ? 'active' : '' ?>">
                <i class="fas fa-plus-circle me-2"></i>
                <span>Nueva asociación</span>
              </a>
            </li>
            <li class="nav-item mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('directorio_clubes')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'directorio_clubes' ? 'active' : '' ?>">
                <i class="fas fa-address-book me-2"></i>
                <span>Directorio de asociaciones</span>
              </a>
            </li>
          </ul>
        </li>
        <!-- Torneos -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('torneo_gestion', ['action' => 'index'])) ?>" class="nav-link <?= ($current_page === 'torneo_gestion' && ($_GET['action'] ?? '') === 'index') ? 'active' : '' ?>">
            <i class="fas fa-trophy me-3"></i>
            <span class="nav-text">Torneos</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('finanzas/resumen_asociacion')) ?>" class="nav-link <?= $current_page === 'finanzas/resumen_asociacion' ? 'active' : '' ?>">
            <i class="fas fa-coins me-3"></i>
            <span class="nav-text">Finanzas por asociación</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('bannerclock')) ?>" class="nav-link <?= $current_page === 'bannerclock' ? 'active' : '' ?>">
            <i class="fas fa-bullhorn me-3"></i>
            <span class="nav-text">Banner Reloj</span>
          </a>
        </li>
        <?php if (($user['role'] ?? '') === 'admin_general'): ?>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_integraciones_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('integraciones-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-plug me-3"></i>
            <span class="nav-text">Integraciones</span>
            <i class="fas fa-chevron-<?= $is_integraciones_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_integraciones_open ? 'show' : '' ?>" id="integraciones-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('admin_atletas_sync')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'admin_atletas_sync' ? 'active' : '' ?>">
                <i class="fas fa-database me-2"></i>
                <span>Atletas → Usuarios</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('importacion_torneo_externo')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'importacion_torneo_externo' ? 'active' : '' ?>">
                <i class="fas fa-file-import me-2"></i>
                <span>Importar torneo externo</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('torneo_split_ranking')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'torneo_split_ranking' ? 'active' : '' ?>">
                <i class="fas fa-code-branch me-2"></i>
                <span>Segmentar torneo (equipos)</span>
              </a>
            </li>
          </ul>
        </li>
        <?php endif; ?>
        <!-- Usuarios -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('users')) ?>" class="nav-link <?= $current_page === 'users' ? 'active' : '' ?>">
            <i class="fas fa-user-cog me-3"></i>
            <span class="nav-text">Gestión de Usuarios y Roles</span>
          </a>
        </li>
        <?php if (($user['role'] ?? '') === 'admin_general'): ?>
        <!-- Archivos descargables: documentos, logos clientes, invitaciones FVD -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('archivos_web')) ?>" class="nav-link <?= $current_page === 'archivos_web' ? 'active' : '' ?>">
            <i class="fas fa-folder-open me-3"></i>
            <span class="nav-text">Archivos descargables</span>
          </a>
        </li>
        <!-- Reporte de actividad (Auditoría) - Solo Super Admin -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('auditoria')) ?>" class="nav-link <?= $current_page === 'auditoria' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list me-3"></i>
            <span class="nav-text">Reporte de actividad</span>
          </a>
        </li>
        <?php endif; ?>
        <!-- 4. Comunicación (acordeón) -->
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_comunicacion_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('comunicacion-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-bullhorn me-3"></i>
            <span class="nav-text">Comunicación</span>
            <i class="fas fa-chevron-<?= $is_comunicacion_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_comunicacion_open ? 'show' : '' ?>" id="comunicacion-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('notificaciones_masivas')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'notificaciones_masivas' ? 'active' : '' ?>">
                <i class="fas fa-bell me-2"></i>
                <span>Notificaciones Masivas</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('whatsapp_config')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'whatsapp_config' ? 'active' : '' ?>">
                <i class="fab fa-whatsapp me-2"></i>
                <span>Mensajes WhatsApp</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('comments')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'comments' ? 'active' : '' ?>">
                <i class="fas fa-comments me-2"></i>
                <span>Comentarios (Aprobación)</span>
                <?php
                try {
                    $pendientes = DB::pdo()->query("SELECT COUNT(*) FROM comentariossugerencias WHERE estatus = 'pendiente'")->fetchColumn();
                    if ($pendientes > 0):
                ?>
                  <span class="badge bg-danger rounded-pill ms-2"><?= $pendientes ?></span>
                <?php endif;
                } catch (Exception $e) {}
                ?>
              </a>
            </li>
          </ul>
        </li>
        <!-- Herramientas -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('control_admin')) ?>" class="nav-link <?= $current_page === 'control_admin' ? 'active' : '' ?>">
            <i class="fas fa-tools me-3"></i>
            <span class="nav-text">Control Especial</span>
            <span class="badge bg-danger ms-2" style="font-size: 0.65rem;">Admin</span>
          </a>
        </li>
        <!-- Enlaces -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('manuales_web/manual_usuario.php')) ?>" class="nav-link">
            <i class="fas fa-book me-3"></i>
            <span class="nav-text">Manual de Usuario</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php endif; ?>
        
        <?php if ($user['role'] === 'admin_torneo'): ?>
        <?php
        $nav_fvd_torneo = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_ID : 1;
        $href_mi_org_at = $dashboard_href('organizaciones', ['id' => $nav_fvd_torneo]);
        $active_mi_org_at = in_array($current_page, ['organizaciones', 'mi_organizacion'], true)
            && (int) ($_GET['id'] ?? $nav_fvd_torneo) === $nav_fvd_torneo;
        ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($href_mi_org_at) ?>" class="nav-link <?= $active_mi_org_at ? 'active' : '' ?>">
            <i class="fas fa-building me-3"></i>
            <span class="nav-text">Mi organización</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('torneo_gestion', ['action' => 'index'])) ?>" class="nav-link <?= ($current_page === 'torneo_gestion' && ($_GET['action'] ?? '') === 'index') ? 'active' : '' ?>">
            <i class="fas fa-trophy me-3"></i>
            <span class="nav-text">Torneos</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('cuentas_bancarias')) ?>" class="nav-link <?= $current_page === 'cuentas_bancarias' ? 'active' : '' ?>">
            <i class="fas fa-university me-3"></i>
            <span class="nav-text">Cuentas Bancarias</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('notificaciones_masivas')) ?>" class="nav-link <?= $current_page === 'notificaciones_masivas' ? 'active' : '' ?>">
            <i class="fas fa-bell me-3"></i>
            <span class="nav-text">Notificaciones</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('manuales_web/manual_usuario.php')) ?>" class="nav-link">
            <i class="fas fa-book me-3"></i>
            <span class="nav-text">Manual de Usuario</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </nav>
    <?php endif; ?>

    <!-- Contenido principal -->
    <div id="page-content-wrapper" class="flex-grow-1"<?= !empty($layout_hide_sidebar) ? ' style="min-width:0; width:100%;"' : '' ?>>
      
      <!-- Topbar -->
      <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm">
        <div class="container-fluid">
          <?php if (empty($layout_hide_sidebar)): ?>
          <button class="btn btn-outline-secondary me-3" id="menu-toggle" type="button">
            <i class="fas fa-bars"></i>
          </button>
          <?php endif; ?>
          
          <div class="navbar-nav me-auto d-flex align-items-center">
            <?php
            $topbar_logo_src = FvdBranding::logoHref($layout_logo_href);
            $topbar_nombre = htmlspecialchars($fvd_nombre_layout);
            ?>
            <img src="<?= htmlspecialchars($topbar_logo_src) ?>" alt="<?= $topbar_nombre ?>" height="39" class="me-2 fvd-topbar-logo" style="object-fit: contain; width: auto; max-height: 39px;">
            <h5 class="mb-0 text-muted d-none d-md-block"><?= $topbar_nombre ?></h5>
            <h6 class="mb-0 text-muted d-md-none"><?= strlen($topbar_nombre) > 20 ? 'Dashboard' : $topbar_nombre ?></h6>
            <?php if (!empty($layout_operativo_asoc) && ($current_page ?? '') !== 'asociacion_panel'): ?>
            <a href="<?= htmlspecialchars($dashboard_href('asociacion_panel')) ?>" class="btn btn-sm btn-primary ms-2 ms-md-3">
              <i class="fas fa-city me-1"></i><span class="d-none d-sm-inline">Panel</span> asociación
            </a>
            <?php elseif (!empty($show_link_panel_asociacion)): ?>
            <a href="<?= htmlspecialchars($dashboard_href('asociacion_panel')) ?>" class="btn btn-sm btn-outline-primary ms-2 ms-md-3">
              <i class="fas fa-city me-1"></i><span class="d-none d-sm-inline">Panel</span> asociación
            </a>
            <?php endif; ?>
          </div>
          
          <div class="d-flex align-items-center">
            <?php if (false): ?>
            <!-- Indicador de Solicitudes Pendientes (FVD: deshabilitado) -->
            <div class="me-3">
              <a href="<?= htmlspecialchars($dashboard_href('affiliate_requests')) ?>" class="btn btn-warning position-relative" title="Solicitudes de Afiliación Pendientes">
                <i class="fas fa-user-clock"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                  <?= $solicitudes_pendientes ?>
                  <span class="visually-hidden">solicitudes pendientes</span>
                </span>
              </a>
            </div>
            <?php endif; ?>
            
            <!-- Barra de búsqueda -->
            <div class="search-box me-3 d-none d-lg-block">
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0">
                  <i class="fas fa-search text-muted"></i>
                </span>
                <input type="text" class="form-control border-start-0 app-search-blur-input" placeholder="Buscar (mín. 3 caracteres; al salir del campo)…" id="topbarSearchInput" minlength="3" autocomplete="off">
              </div>
            </div>
            
            <!-- Botón búsqueda móvil -->
            <button class="btn btn-outline-secondary d-lg-none me-2" onclick="toggleMobileSearch()">
              <i class="fas fa-search"></i>
            </button>

            <!-- Campanita: notificaciones web pendientes -->
            <a href="<?= htmlspecialchars($dashboard_href('user_notificaciones')) ?>" class="btn btn-outline-secondary position-relative me-2" id="campana-link" title="Notificaciones">
              <i class="fas fa-bell"></i>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="campana-badge" style="display: none;">0</span>
            </a>

            <?php if ($modo_prueba_activo): ?>
            <span class="badge <?= htmlspecialchars($modo_prueba_badge_class) ?> me-2" title="Estás simulando permisos de otro perfil">
              <i class="fas fa-vial me-1"></i>MODO PRUEBA: Actuando como <?= htmlspecialchars($role_activo_human) ?>
            </span>
            <?php endif; ?>
            
            <?php if (in_array($user['role'] ?? '', ['admin_club', 'admin_general', 'admin_torneo'], true)): ?>
            <?php
              $topbar_org_id = (int) (Auth::getUserOrganizacionId() ?: (class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_ID : 1));
              $topbar_org_href = $topbar_org_id > 0
                ? $dashboard_href('organizaciones', ['id' => $topbar_org_id])
                : $dashboard_href('mi_organizacion');
            ?>
            <!-- Mismo destino que «Mi organización» en el menú lateral -->
            <a href="<?= htmlspecialchars($topbar_org_href) ?>" class="btn btn-outline-primary me-2" title="Mi organización: resumen, asociaciones y afiliados">
              <i class="fas fa-building me-1"></i>
              <span class="d-none d-md-inline">Mi organización</span>
            </a>
            <?php endif; ?>
            
            <?php include __DIR__ . '/user_menu_dropdown.php'; ?>
          </div>
        </div>
      </nav>

      <?php if ($actas_pendientes_count > 0 && in_array($user['role'], ['admin_club', 'admin_general', 'admin_torneo'], true)): ?>
      <!-- Banner de alerta: actas pendientes de validación -->
      <div class="alert alert-warning alert-dismissible fade show rounded-0 mb-0 border-0 border-bottom border-warning" role="alert">
        <div class="container-fluid d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span><i class="fas fa-exclamation-triangle me-2"></i><strong>Atención:</strong> Tienes actas de mesa esperando validación visual.</span>
          <a href="<?= htmlspecialchars($dashboard_href('torneo_gestion', ['action' => 'verificar_actas_index'])) ?>" class="btn btn-warning btn-sm">
            <i class="fas fa-qrcode me-1"></i>Abrir Verificador
          </a>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
      </div>
      <?php endif; ?>

      <!-- Contenido dinámico (CSS/head ya cargados arriba; el módulo se incluye dentro del body con formato) -->
      <main class="container-fluid fvd-main-scroll max-w-full px-0">
        <?php
        $layout_skip_global_volver = ($current_page === 'torneo_gestion' && in_array(($_GET['action'] ?? ''), [
            'registrar_resultados',
            'registrar_resultados_v2',
            'cuadricula',
            'hojas_anotacion',
        ], true));
        ?>
        <?php if ($current_page !== 'home' && !$layout_skip_global_volver): ?>
        <div id="global-volver-container"></div>
        <?php endif; ?>
        <?php
        $content = __DIR__ . "/../../modules/$current_page.php";
        $action_get = $_GET['action'] ?? '';
        try {
          ob_start();
          if (file_exists($content)) {
            include $content;
          } else {
            if (function_exists('error_log')) {
              error_log("layout: Página no reconocida page=" . ($current_page ?: '(vacío)') . ", 404.");
            }
            include __DIR__ . "/../../modules/404.php";
          }
          $main_output = ob_get_clean();
          // Si el módulo hizo exit() (p. ej. redirect que falló por headers ya enviados), el buffer puede estar vacío
          if ($main_output === '' || $main_output === false) {
            if ($current_page === 'torneo_gestion' && $action_get === 'inscribir_sitio') {
              $torneo_id = (int)($_GET['torneo_id'] ?? 0);
              $panel_url = (function_exists('dashboard_href') && is_callable($dashboard_href))
                ? $dashboard_href('torneo_gestion', $torneo_id > 0 ? ['action' => 'panel', 'torneo_id' => $torneo_id] : ['action' => 'index'])
                : 'index.php?page=torneo_gestion&action=index';
              echo '<div class="alert alert-warning mx-3"><strong>No se pudo cargar el formulario de Inscripción en sitio.</strong> ';
              echo 'Compruebe que ha seleccionado un torneo y que tiene permisos. ';
              echo '<a href="' . htmlspecialchars($panel_url) . '" class="alert-link">Volver al panel de torneos</a>.</div>';
            } else {
              echo '<div class="alert alert-info mx-3">Contenido no disponible. <a href="' . htmlspecialchars($dashboard_href('home')) . '">Ir a Estadísticas</a>.</div>';
            }
          } else {
            echo $main_output;
          }
        } catch (Throwable $e) {
          if (ob_get_level()) ob_end_clean();
          error_log("layout: Error en página '{$current_page}': " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
          echo '<div class="alert alert-danger mx-3"><strong>Error al cargar la página.</strong> ';
          echo (defined('APP_DEBUG') && APP_DEBUG) ? htmlspecialchars($e->getMessage()) : 'Revisa el log del servidor o contacta al administrador.';
          echo '</div>';
        }
        ?>
      </main>
    </div>
  </div>

  <!-- Bootstrap JS (una sola carga; footer.php no lo repite si $layout_already_loaded_bootstrap está definido) -->
  <script src="<?= $layout_asset_href('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>" defer></script>
  <script src="<?= $layout_asset_href('assets/vendor/sweetalert2/sweetalert2.min.js') ?>" defer></script>
  <?php
$app_base_for_js = $layout_asset_base;
if (str_ends_with($app_base_for_js, '/public')) {
    $app_base_for_js = rtrim(substr($app_base_for_js, 0, -7), '/');
} else {
    $app_base_for_js = rtrim($app_base_for_js, '/');
}
?>
  <script>window.APP_BASE_URL = '<?= htmlspecialchars($app_base_for_js) ?>'; window.APP_PUBLIC_BASE = '<?= htmlspecialchars(rtrim($layout_asset_base, '/')) ?>'; window.notifAjaxUrl = '<?= htmlspecialchars($layout_asset_base . "/notificaciones_ajax.php") ?>';</script>

  <?php
  $pages_needing_image_preview = ['mi_organizacion', 'admin_org', 'tournaments', 'tournament_admin', 'users', 'clubs', 'clubes_asociados', 'admin_clubs', 'directorio_clubes'];
  $action = $_GET['action'] ?? '';
  $needs_image_preview = in_array($current_page, $pages_needing_image_preview)
    || ($current_page === 'torneo_gestion' && in_array($action, ['galeria_fotos', 'index']));
  if ($needs_image_preview): ?>
  <script src="<?= $layout_asset_href('assets/image-preview.js') ?>" defer></script>
  <?php endif; ?>
  <script src="<?= $layout_asset_href('assets/notifications-toast.js') ?>" defer></script>
  <script src="<?= $layout_asset_href('assets/breadcrumb-back.js') ?>" defer></script>
  <script src="<?= $layout_asset_href('assets/single-tab-enforcer.js') ?>" defer></script>
  <script src="<?= $layout_asset_href('assets/app-search.js') ?>" defer></script>
  <script src="<?= $layout_asset_href('assets/dashboard-init.js') ?>" defer></script>
  <?php if ($current_page === 'registrants'): ?>
  <script src="<?= $layout_asset_href('assets/registrants-inscripciones.js') ?>" defer></script>
  <?php endif; ?>
<?php
$layout_asset_base = $layout_asset_base ?? '';
$layout_already_loaded_bootstrap = true; // Evitar doble carga de Bootstrap (rompe dropdown del usuario)
include_once __DIR__ . '/../../includes/footer.php';
