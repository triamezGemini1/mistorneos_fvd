<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Panel de Control del Torneo. Patrón en bloque: db_config → auth_service → requireAuth.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
AuthService::requireAuth();
require_once __DIR__ . '/../lib/TournamentAdminAccess.php';
require_once __DIR__ . '/../lib/FvdInstitutionalScope.php';
FvdInstitutionalScope::rejectStandaloneOperationalEntry();
TournamentAdminAccess::requireTorneoPanelAccess();
$user = Auth::user();

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

// Si hay torneo_id y no se especifica acción, mostrar el panel principal
if ($torneo_id > 0 && empty($_GET['action'])) {
    $_GET['action'] = 'panel';
}
if ($torneo_id <= 0 && ($_GET['action'] ?? '') === 'panel') {
    header('Location: ' . AppHelpers::dashboard());
    exit;
}

// Incluir el módulo; no renderiza cuando detecta panel_torneo (evita duplicación)
require_once __DIR__ . '/../modules/torneo_gestion.php';

if (!isset($view_file) || !isset($view_data)) {
    header('Location: ' . AppHelpers::dashboard());
    exit;
}

// Vistas que se renderizan de forma independiente (sin layout del panel)
$vistas_independientes = ['hojas_anotacion', 'cuadricula', 'cronometro'];
$action = $_GET['action'] ?? '';
if (in_array($action, $vistas_independientes)) {
    extract($view_data);
    include $view_file;
    exit;
}

$dashboard_url = AppHelpers::dashboard();
$page_title = in_array($action, ['registrar_resultados', 'registrar_resultados_v2']) 
    ? 'Registrar Resultados' 
    : (($view_data['torneo']['nombre'] ?? 'Panel') . ' - Panel de Control');

$torneo_data = $view_data['torneo'] ?? [];
$org_nombre = $torneo_data['organizacion_nombre'] ?? 'N/A';
$org_logo = $torneo_data['organizacion_logo'] ?? null;
$org_logo_url = $org_logo ? AppHelpers::url('view_image.php', ['path' => $org_logo]) : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/dashboard.css">
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; }
        /* Panel de Control de Torneos: +1pt en todo el contenido */
        body.panel-torneo-page { font-size: 1.083rem; }
        .panel-contenedor {
            overflow: hidden; display: flex; flex-direction: column;
            height: 100vh; box-sizing: border-box;
        }
        .panel-contenedor .panel-content {
            flex: 1; overflow-y: auto; min-height: 0;
        }
    </style>
</head>
<body class="panel-torneo-page">
    <main class="panel-contenedor" style="width:90%;max-width:100%;margin:0 auto;background:#005c44;padding:0.85rem;">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-shrink-0 gap-3">
            <?php if ($org_logo_url): ?>
                <img src="<?= htmlspecialchars($org_logo_url) ?>" alt="<?= htmlspecialchars($org_nombre) ?>" class="panel-org-logo" style="height:42px;max-width:120px;object-fit:contain;">
            <?php else: ?>
                <div style="width:42px;height:42px;min-width:42px;"></div>
            <?php endif; ?>
            <div class="flex-grow-1 text-center">
                <span class="panel-org-nombre" style="font-size:calc(1.5rem * 1.3);font-weight:bold;color:#000;"><?= htmlspecialchars($org_nombre) ?></span>
            </div>
            <a href="<?= htmlspecialchars($dashboard_url) ?>" class="btn btn-dark btn-sm panel-btn-ir" style="white-space:nowrap;padding:0.4rem 0.8rem;">
                <i class="fas fa-arrow-left me-1"></i> Ir al dashboard
            </a>
        </div>
        <div class="panel-content">
            <?php extract($view_data); include $view_file; ?>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
