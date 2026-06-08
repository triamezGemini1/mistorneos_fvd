<?php
/**
 * Punto de entrada principal de la aplicación
 * - Rutas modernas: /auth/login, /dashboard, /api/... (usando Router)
 * - Rutas legacy: ?page=xxx (compatibilidad hacia atrás)
 */
$configDir = __DIR__ . '/../config';
if (!is_dir($configDir) || !is_readable($configDir . '/bootstrap.php')) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;padding:2rem;max-width:600px;margin:0 auto;">';
    echo '<h1>Error de configuración</h1><p>No se encuentra la carpeta <code>config/</code> junto a <code>public/</code>. En producción hay que desplegar el proyecto completo (raíz con config/, public/, includes/), no solo la carpeta public.</p>';
    echo '<p><a href="check_pruebas.php">Diagnóstico</a></p></body></html>';
    exit;
}

// Patrón en bloque: carga mínima → conexión única → seguridad → validación inmediata
try {
    require_once $configDir . '/session_start_early.php';
    require_once $configDir . '/bootstrap.php';
    require_once $configDir . '/csrf.php';
    require_once $configDir . '/auth.php';
} catch (Throwable $e) {
    error_log("index.php: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Error al cargar la aplicación. Revisa el log del servidor.';
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;padding:2rem;max-width:600px;margin:0 auto;">';
    echo '<h1>No se pudo cargar la aplicación</h1><p>' . htmlspecialchars($msg) . '</p></body></html>';
    exit;
}

try {
    require_once $configDir . '/db_config.php';
} catch (Throwable $e) {
    error_log("index.php db_config: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Error al conectar. Revisa el log del servidor.';
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;padding:2rem;max-width:600px;margin:0 auto;">';
    echo '<h1>No se pudo cargar la aplicación</h1><p>' . htmlspecialchars($msg) . '</p></body></html>';
    exit;
}

try {
    require_once $configDir . '/auth_service.php';
    AuthService::requireAuth();
} catch (Throwable $e) {
    error_log("index.php requireAuth: " . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    include __DIR__ . '/error_service_unavailable.php';
    exit;
}

// Constante para el directorio raíz de la aplicación
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/lib/IntegralUrl.php';
if (IntegralUrl::isEnabled()) {
    IntegralUrl::redirectHubTorneosRequestsIfNeeded();
}

// Normalizar REQUEST_URI cuando la app está bajo un subpath
// Así el Router recibe /join o /auth/login y no "Ruta no encontrada" (path con subcarpeta no coincide con rutas registradas)
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = '';
$scriptDir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '';
$scriptDir = ($scriptDir === '.' || $scriptDir === '') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');
if ($scriptDir !== '' && $scriptDir !== '/') {
    $basePath = $scriptDir;
}
if ($basePath === '') {
    $appBaseUrl = $GLOBALS['APP_CONFIG']['app']['base_url'] ?? '';
    if ($appBaseUrl === '' && class_exists('Env')) {
        $appBaseUrl = (string) Env::get('APP_URL', '');
    }
    $pathFromUrl = $appBaseUrl !== '' ? parse_url($appBaseUrl, PHP_URL_PATH) : '';
    $basePath = ($pathFromUrl !== null && $pathFromUrl !== '' && $pathFromUrl !== '/') ? rtrim($pathFromUrl, '/') : '';
}
if ($basePath !== '' && strpos($currentUri, $basePath) === 0) {
    $afterBase = substr($currentUri, strlen($basePath)) ?: '/';
    $pathOnly = (($q = strpos($afterBase, '?')) !== false) ? substr($afterBase, 0, $q) : $afterBase;
    if ($pathOnly === '') {
        $pathOnly = '/';
    }
    $queryString = (($pos = strpos($currentUri, '?')) !== false) ? substr($currentUri, $pos) : '';
    $_SERVER['REQUEST_URI'] = $pathOnly . $queryString;
}

// =================================================================
// MODO 1: RUTAS MODERNAS (Router)
// =================================================================

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($uri, PHP_URL_PATH);

// Lista de rutas que usa el Router moderno (sin ?page=)
$modernRoutes = [
    '/auth/',
    '/invitation/',
    '/join',
    '/actions/',
    '/dashboard',
    '/api/',
    '/admin/',
];

$useModernRouter = false;
foreach ($modernRoutes as $prefix) {
    if ($prefix === '/join' && ($uriPath === '/join' || $uriPath === '/join/' || substr($uriPath, -5) === '/join' || substr($uriPath, -6) === '/join/')) {
        $useModernRouter = true;
        break;
    }
    if (strpos($uriPath, $prefix) === 0) {
        $useModernRouter = true;
        break;
    }
}

if ($useModernRouter) {
    // Cargar autoloader de Composer si existe
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    // Si no hay vendor o el autoload no incluye Core, cargar clases a mano
    if (!class_exists(\Core\Container\Container::class, false)) {
        $coreRoot = __DIR__ . '/../core';
        $libRoot = __DIR__ . '/../lib';
        require_once $coreRoot . '/Container/Container.php';
        require_once $coreRoot . '/Http/Request.php';
        require_once $coreRoot . '/Http/Response.php';
        require_once $libRoot . '/Security/RateLimiter.php';
        require_once $coreRoot . '/Middleware/Middleware.php';
        require_once $coreRoot . '/Middleware/AuthMiddleware.php';
        require_once $coreRoot . '/Middleware/RateLimitMiddleware.php';
        require_once $coreRoot . '/Routing/Router.php';
    }

    // Asegurar que Lib\Security\RateLimiter exista (usado por RateLimitMiddleware en rutas)
    if (!class_exists(\Lib\Security\RateLimiter::class, false)) {
        require_once __DIR__ . '/../lib/Security/RateLimiter.php';
    }

    // Inicializar Container y Router
    $container = new \Core\Container\Container();
    $router = new \Core\Routing\Router($container);
    
    // Cargar definiciones de rutas
    $routeDefinitions = require __DIR__ . '/../config/routes.php';
    $routeDefinitions($router);
    
    // Capturar request y despachar
    $request = \Core\Http\Request::capture();
    $response = $router->dispatch($request);
    $response->send();
    exit;
}

// =================================================================
// MODO 2: RUTAS LEGACY (?page=xxx) - Compatibilidad
// =================================================================
// Sesión ya verificada arriba (requireAuth antes de db_config). Obtener usuario para restricciones.
$user = Auth::user();
if (getenv('SESSION_DEBUG')) error_log('[SESSION_DEBUG] index.php | usuario OK | id=' . ($user['id'] ?? '') . ' | role=' . ($user['role'] ?? ''));

// Restringir panel admin a roles válidos del sistema
$allowed_roles = ['admin_general', 'admin_torneo', 'admin_club', 'usuario', 'operador'];
if (!in_array($user['role'] ?? '', $allowed_roles, true)) {
    Auth::logout();
    $redirect_login = defined('URL_BASE') ? (URL_BASE . 'login.php?error=requiere_autenticacion') : AppHelpers::url('login.php', ['error' => 'requiere_autenticacion']);
    header('Location: ' . $redirect_login, true, 302);
    exit;
}

// Redirigir usuarios normales al portal de jugador, salvo vistas permitidas (perfil, cambio contraseña, resumen/posiciones)
if ($user['role'] === 'usuario' && (($user['role_original'] ?? '') !== 'admin_general')) {
    $page = $_GET['page'] ?? '';
    $action = $_GET['action'] ?? '';
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    $inscrito_id = (int)($_GET['inscrito_id'] ?? 0);
    $allow_profile = in_array($page, ['users/profile', 'users/change_password'], true);
    $allow_view = ($page === 'torneo_gestion' && $torneo_id > 0 && in_array($action, ['resumen_individual', 'posiciones']));
    if ($allow_view && $action === 'resumen_individual') {
        $allow_view = ($inscrito_id > 0 && $inscrito_id === Auth::id());
    }
    if (!$allow_profile && !$allow_view) {
        $redirect_portal = defined('URL_BASE') ? (URL_BASE . 'user_portal.php') : AppHelpers::url('user_portal.php');
        header('Location: ' . $redirect_portal, true, 302);
        exit;
    }
}

// Obtener página solicitada
$page = $_GET['page'] ?? 'home';

// Sanitizar nombre de página (solo letras, números, guiones y barras)
$page = preg_replace('/[^a-zA-Z0-9_\/\-]/', '', $page);

require_once APP_ROOT . '/lib/FvdAdminGate.php';
FvdAdminGate::rejectPageIfDisabled($page);

// Admin operativo de asociación: inicio = panel acotado (sin dashboard general)
if (Auth::isOperativoSoloAsociacion()) {
    require_once __DIR__ . '/../lib/app_helpers.php';
    require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
    if ($page === 'home' || $page === '') {
        header('Location: ' . AppHelpers::dashboard('asociacion_panel'));
        exit;
    }
    if (!AsociacionAdminHelper::paginaPermitidaOperativo($page)) {
        header('Location: ' . AppHelpers::dashboard('asociacion_panel', [
            'error' => 'No tiene permiso para acceder a esa sección. Use el panel de asociación.',
        ]));
        exit;
    }
}

// Pre-despacho para solicitudes POST y GET con acciones que requieren redirección
$action = trim((string)($_GET['action'] ?? ''));
$actions_requiring_redirect = ['delete', 'save', 'update'];

// Operativo asociación: validar torneo_gestion / tournament_admin ANTES del layout (evita headers already sent)
if (Auth::isOperativoSoloAsociacion() && in_array($page, ['torneo_gestion', 'tournament_admin'], true)) {
    $accionOper = $action !== '' ? $action : trim((string) ($_GET['action'] ?? 'index'));
    if ($page === 'torneo_gestion' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $accionOper = trim((string) ($_POST['action'] ?? $accionOper));
    }
    if ($page === 'torneo_gestion' && in_array($accionOper, ['', 'index', 'panel'], true)) {
        $redirParams = [];
        $tidIdx = (int) ($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
        if ($tidIdx > 0) {
            $redirParams['torneo_id'] = $tidIdx;
        }
        header('Location: ' . AppHelpers::dashboard('asociacion_panel', $redirParams));
        exit;
    }
    $permitida = $page === 'torneo_gestion'
        ? AsociacionAdminHelper::accionTorneoGestionPermitida($accionOper)
        : AsociacionAdminHelper::accionTournamentAdminPermitida($accionOper);
    if (!$permitida) {
        $redirParams = ['error' => 'No tiene permiso para gestionar el torneo. Use el panel de asociación.'];
        $tidDeny = (int) ($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
        if ($tidDeny > 0) {
            $redirParams['torneo_id'] = $tidDeny;
        }
        header('Location: ' . AppHelpers::dashboard('asociacion_panel', $redirParams));
        exit;
    }
    $torneoOper = (int) ($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
    if ($torneoOper > 0 && !Auth::canAccessTournament($torneoOper)) {
        header('Location: ' . AppHelpers::dashboard('asociacion_panel', [
            'error' => 'Torneo fuera del ámbito de su asociación.',
        ]));
        exit;
    }
    if (!defined('TORNEO_GESTION_OPERATIVO_VALIDATED')) {
        define('TORNEO_GESTION_OPERATIVO_VALIDATED', true);
    }
}

// torneo_gestion action=new/view/edit: redirigir a tournaments ANTES de cualquier output (evita "headers already sent" y página en blanco)
if ($page === 'torneo_gestion' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && in_array($action, ['new', 'view', 'edit'], true)) {
    $params = ['page' => 'tournaments', 'action' => $action];
    if ($action !== 'new' && isset($_GET['id']) && (int)$_GET['id'] > 0) {
        $params['id'] = (int)$_GET['id'];
    }
    $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') : '';
    $redirect_url = ($base !== '' ? $base . '/' : '') . 'index.php?' . http_build_query($params);
    header('Location: ' . $redirect_url);
    exit;
}

// invitations: redirects que requieren ejecutarse ANTES del layout (evita "headers already sent")
if ($page === 'invitations' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $inv_action = $_GET['action'] ?? 'list';
    $inv_filter = $_GET['filter_torneo'] ?? $_GET['torneo_id'] ?? '';
    $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') : '';
    $prefix = ($base !== '' ? $base . '/' : '');
    $torneo_gestion_url = $prefix . 'index.php?page=torneo_gestion&action=index';
    if ($inv_action === 'list' && $inv_filter === '') {
        header('Location: ' . $torneo_gestion_url . '&error=' . urlencode('Abra Invitaciones desde la fila de un torneo o desde el panel del torneo seleccionado.'));
        exit;
    }
    if ($inv_action === 'new') {
        $torneo_id_new = (int)($_GET['torneo_id'] ?? $_GET['filter_torneo'] ?? 0);
        if ($torneo_id_new <= 0 || !Auth::canAccessTournament($torneo_id_new)) {
            header('Location: ' . $torneo_gestion_url . '&error=' . urlencode('Seleccione un torneo en Gestión de Torneos e indique el torneo al crear la invitación.'));
            exit;
        }
    }
}

// organizaciones: admin_club sin id debe redirigir ANTES del layout (evita "headers already sent")
if ($page === 'organizaciones' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !Auth::isAdminGeneral()) {
    $org_id_get = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($org_id_get <= 0) {
        $user_org_id = Auth::getUserOrganizacionId();
        $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') : '';
        $prefix = ($base !== '' ? $base . '/' : '');
        if ($user_org_id) {
            header('Location: ' . $prefix . 'index.php?page=organizaciones&id=' . (int)$user_org_id);
            exit;
        }
        header('Location: ' . $prefix . 'index.php?page=mi_organizacion');
        exit;
    }
}

// Acciones que redirigen sin layout (evitan acceso directo a modules/ bloqueado por .htaccess)
if ($page === 'torneo_gestion' && in_array($action, ['export_resultados_pdf', 'export_resultados_excel'], true)) {
    if ($action === 'export_resultados_pdf') {
        require_once __DIR__ . '/../modules/tournament_admin/resultados_export_pdf.php';
    } else {
        require_once __DIR__ . '/../modules/tournament_admin/resultados_export_excel.php';
    }
    exit;
}

if ($page === 'torneo_gestion' && $action === 'resumen_individual_pdf') {
    require_once __DIR__ . '/../modules/tournament_admin/resumen_individual_export_pdf.php';
    exit;
}

// Descargas / exportaciones torneo_gestion: ejecutar antes del layout (cabeceras HTTP)
$torneo_gestion_sin_layout = [
    'inscripciones_export_xls',
    'inscripciones_gestor_excel',
    'inscripciones_export_pdf',
    'inscripciones_reporte_detallado_pdf',
    'inscripciones_reporte_detallado_xls',
    'retirados_export_pdf',
    'retirados_export_xls',
    'carga_masiva_equipos_plantilla',
    'carga_masiva_parejas_plantilla',
    'carga_masiva_equipos_reporte_pdf',
    'carga_masiva_parejas_reporte_pdf',
];
if ($page === 'torneo_gestion' && in_array($action, $torneo_gestion_sin_layout, true)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    require_once __DIR__ . '/../modules/torneo_gestion.php';
    exit;
}

if ($page === 'admin_clubs' && $action === 'send_notification') {
    require_once __DIR__ . '/../modules/admin_clubs/send_notification.php';
    exit;
}

// Desactivar/Reactivar organización: delegado a admin_org (centraliza responsabilidades)
if ($page === 'mi_organizacion' && isset($_GET['id']) && in_array($action, ['desactivar', 'reactivar'], true)) {
    require_once __DIR__ . '/../modules/admin_org/organizacion/actions/' . $action . '.php';
    exit;
}

// API de búsqueda usuario/persona por cédula: misma sesión que index.php (evita "sesión expirada" en fetch)
if ($page === 'api_search_user_persona') {
    require_once __DIR__ . '/api/search_user_persona.php';
    exit;
}

// Manejar endpoints especiales (sin layout)
$special_endpoints = [
    'invitations_send_email',
    'send_invitation_email',
    'send_invitation_whatsapp',
    'send_invitation_whatsapp_pdf',
    'whatsapp_templates',
    'whatsapp_config',
    'generate_invitation_pdf',
    'clubs/send_friend_invitation',
];

if (in_array($page, $special_endpoints, true)) {
    $module = __DIR__ . "/../modules/{$page}.php";
    if (file_exists($module)) {
        include $module;
        exit;
    }
}

// POST clubs: actualizar o guardar club (evita 404 cuando la URL base no es public/)
if (Auth::isOperativoSoloAsociacion() && $page === 'clubs' && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    require_once __DIR__ . '/../lib/app_helpers.php';
    header('Location: ' . AppHelpers::dashboard('asociacion_panel', ['error' => 'No puede crear ni editar clubes.']));
    exit;
}

if ($page === 'clubs' && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    $club_action = $_GET['action'] ?? '';
    if ($club_action === 'update') {
        require_once __DIR__ . '/../modules/clubs/update.php';
        exit;
    }
    if ($club_action === 'save') {
        require_once __DIR__ . '/../modules/clubs/save.php';
        exit;
    }
}

// Directorio de clubes: exportación (solo GET, sin layout)
if ($page === 'directorio_clubes') {
    $dc_action = $_GET['action'] ?? '';
    if ($dc_action === 'export_excel') {
        require_once __DIR__ . '/../modules/directorio_clubes/export_excel.php';
        exit;
    }
    if ($dc_action === 'report_pdf') {
        require_once __DIR__ . '/../modules/directorio_clubes/report_pdf.php';
        exit;
    }
}
// POST directorio_clubes: guardar o actualizar registro en tabla directorio_clubes
if ($page === 'directorio_clubes' && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    $dc_action = $_GET['action'] ?? '';
    if ($dc_action === 'update') {
        require_once __DIR__ . '/../modules/directorio_clubes/update.php';
        exit;
    }
    if ($dc_action === 'save') {
        require_once __DIR__ . '/../modules/directorio_clubes/save.php';
        exit;
    }
}

// Invitación clubes: acción "Invitar" un solo club (GET) — ejecutar antes de enviar output para poder redirigir
$invitar_uno_club = isset($_GET['club_id']) && (int)$_GET['club_id'] > 0;
$invitar_uno_dir = isset($_GET['directorio_id']) && (int)$_GET['directorio_id'] > 0;
if ($page === 'invitacion_clubes' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'invitar_uno' && isset($_GET['torneo_id']) && (int)$_GET['torneo_id'] > 0 && ($invitar_uno_club || $invitar_uno_dir)) {
    $module = __DIR__ . '/../modules/invitacion_clubes.php';
    if (file_exists($module)) {
        include $module;
        exit;
    }
}

// POST / acciones que redirigen: se incluye solo el módulo (sin layout). El módulo DEBE hacer header(Location) y exit
// para que el usuario no vea salida sin formato. Tras el redirect, el GET cae más abajo e incluye layout (con CSS) + módulo.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || in_array($action, $actions_requiring_redirect, true)) {
    $module = __DIR__ . "/../modules/{$page}.php";
    if (file_exists($module)) {
        include $module;
        exit;
    }
}

// Manejar sub-rutas que son endpoints de procesamiento (POST o terminan en /save, /delete, etc.)
$processing_endpoints = ['save', 'delete', 'update', 'send', 'upload', 'process'];
$is_processing_endpoint = false;
if (strpos($page, '/') !== false) {
    $parts = explode('/', $page);
    $last_part = end($parts);
    if (in_array($last_part, $processing_endpoints, true) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $sub_module = __DIR__ . "/../modules/{$page}.php";
        if (file_exists($sub_module)) {
            include $sub_module;
            exit;
        }
    }
}

// Podios / Podios equipos / Resultados por club: mostrar vista dedicada (sin header ni sidebar del dashboard)
if ($page === 'torneo_gestion') {
    $action = $_GET['action'] ?? '';
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    if ($torneo_id > 0 && in_array($action, ['podios', 'podios_equipos', 'resultados_por_club'], true)) {
        include __DIR__ . '/includes/layout_podios.php';
        exit;
    }
}

// Organizaciones: si se pide detalle (id) y la organización no existe o el usuario no tiene acceso, redirigir antes de enviar output
if ($page === 'organizaciones') {
    $org_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($org_id > 0) {
        $stmt = DB::pdo()->prepare("SELECT id, estatus FROM organizaciones WHERE id = ? LIMIT 1");
        $stmt->execute([$org_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            header('Location: index.php?page=organizaciones');
            exit;
        }
        if (!Auth::isAdminGeneral()) {
            if (empty($row['estatus']) || (int)$row['estatus'] !== 1) {
                header('Location: index.php?page=organizaciones');
                exit;
            }
            $user_org = Auth::getUserOrganizacionId();
            if ($user_org === null || (int)$user_org !== $org_id) {
                header('Location: index.php?page=organizaciones');
                exit;
            }
        }
    }
}

// Unificar Torneos: solo usar la vista del menú lateral (torneo_gestion). Acceso GET a page=tournaments redirige al panel.
// Excepción: acciones new, view, edit se manejan en tournaments (crear/ver/editar torneo)
if (Auth::isOperativoSoloAsociacion() && $page === 'tournaments') {
    require_once __DIR__ . '/../lib/app_helpers.php';
    header('Location: ' . AppHelpers::dashboard('asociacion_panel', ['error' => 'No puede crear ni administrar torneos.']));
    exit;
}

if ($page === 'tournaments' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $tg_action = $_GET['action'] ?? 'index';
    if ($tg_action === '' || $tg_action === 'index' || $tg_action === 'list') {
        header('Location: index.php?page=torneo_gestion&action=index' . (isset($_GET['error']) ? '&error=' . urlencode($_GET['error']) : '') . (isset($_GET['success']) ? '&success=' . urlencode($_GET['success']) : ''));
        exit;
    }
}

// torneo_gestion POST: procesar ANTES del layout para evitar "headers already sent" al redirigir
if ($page === 'torneo_gestion' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (Auth::isOperativoSoloAsociacion() && !defined('TORNEO_GESTION_OPERATIVO_VALIDATED')) {
        define('TORNEO_GESTION_OPERATIVO_VALIDATED', true);
    }
    require_once __DIR__ . '/../modules/torneo_gestion.php';
    exit;
}

// op_especiales POST: redirecciones y CSRF sin layout
if ($page === 'op_especiales' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/../modules/op_especiales.php';
    exit;
}

// torneo_gestion GET — acciones que solo envían cabeceras o redirigen: sin layout (el módulo dentro del <main> ya imprimió HTML arriba)
if ($page === 'torneo_gestion' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $tg_get = trim((string)($_GET['action'] ?? ''));
    $tg_tid = (int)($_GET['torneo_id'] ?? 0);
    $tg_switch = (int)($_GET['switch_torneo_id'] ?? 0);
    $tg_early = $tg_switch > 0
        || ($tg_get === 'carga_masiva_equipos_plantilla' && $tg_tid > 0)
        || ($tg_get === 'carga_masiva_equipos_reporte_pdf' && $tg_tid > 0)
        || ($tg_get === 'carga_masiva_parejas_plantilla' && $tg_tid > 0)
        || ($tg_get === 'carga_masiva_parejas_reporte_pdf' && $tg_tid > 0)
        || ($tg_get === 'inscripciones_export_xls' && $tg_tid > 0)
        || ($tg_get === 'inscripciones_gestor_excel' && $tg_tid > 0)
        || ($tg_get === 'inscripciones_export_pdf' && $tg_tid > 0)
        || ($tg_get === 'inscripciones_reporte_detallado_pdf' && $tg_tid > 0)
        || ($tg_get === 'inscripciones_reporte_detallado_xls' && $tg_tid > 0)
        || ($tg_get === 'retirados_export_pdf' && $tg_tid > 0)
        || ($tg_get === 'retirados_export_xls' && $tg_tid > 0)
        || ($tg_get === 'export_access_excel' && $tg_tid > 0)
        || in_array($tg_get, ['panel_equipos', 'dashboard'], true);
    if ($tg_early) {
        if (Auth::isOperativoSoloAsociacion() && !defined('TORNEO_GESTION_OPERATIVO_VALIDATED')) {
            define('TORNEO_GESTION_OPERATIVO_VALIDATED', true);
        }
        require_once __DIR__ . '/../modules/torneo_gestion.php';
        exit;
    }
}

// torneo_gestion GET: el módulo se incluye desde layout.php (después de que el sidebar/top ya imprimió HTML).
// Buffer ayuda con redirecciones internas del switch (p. ej. registrar_resultados sin ronda); las rutas críticas van despachadas arriba.
if ($page === 'torneo_gestion' && ob_get_level() === 0) {
    ob_start();
}

// clubes_asociados GET: validar club_id antes del layout (header() no puede enviarse tras HTML del layout)
if ($page === 'clubes_asociados' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $cid = (int) ($_GET['club_id'] ?? 0);
    if ($cid > 0) {
        $u = Auth::user();
        if (is_array($u) && ($u['role'] ?? '') === 'admin_club') {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $admin_club_user_id = (int) Auth::id();
            if (!ClubHelper::isClubManagedByAdmin($admin_club_user_id, $cid)) {
                $loc = class_exists('AppHelpers') ? AppHelpers::dashboard('clubes_asociados') : 'index.php?page=clubes_asociados';
                header('Location: ' . $loc);
                exit;
            }
        }
    }
}

// Incluir layout principal (para GET normal y páginas de visualización). $page ya está definida y saneada; el layout la usa para incluir el módulo correcto.
include __DIR__ . "/includes/layout.php";
