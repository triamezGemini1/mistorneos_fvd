<?php
/**
 * Módulo de Administración de Torneo en Ejecución
 * Panel de control para gestionar un torneo activo
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/InscritosPartiresulHelper.php';
require_once __DIR__ . '/../lib/TournamentAdminHelper.php';
require_once __DIR__ . '/../lib/TournamentPhaseHelper.php';
require_once __DIR__ . '/../lib/TournamentAdminAccess.php';

TournamentAdminAccess::requireTorneoPanelAccess();

// Obtener ID del torneo
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

if ($torneo_id <= 0) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('Debe seleccionar un torneo'));
    exit;
}

// Verificar acceso al torneo
if (!Auth::canAccessTournament($torneo_id)) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('No tiene permisos para acceder a este torneo'));
    exit;
}

if (Auth::isOperativoSoloAsociacion()) {
    require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
    $accionTa = trim((string) ($_GET['action'] ?? ''));
    if (!AsociacionAdminHelper::accionTournamentAdminPermitida($accionTa)) {
        header('Location: ' . AppHelpers::dashboard('asociacion_panel', [
            'torneo_id' => $torneo_id,
            'error' => 'Solo puede emitir carnets / credenciales desde el panel de asociación.',
        ]));
        exit;
    }
}

// API JSON: enlace QR corto para jugador (POST + CSRF; solo staff del torneo)
$early_api_action = $_GET['action'] ?? '';
if ($early_api_action === 'api_torneo_jugador_qr_token' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_once __DIR__ . '/../config/csrf.php';
    require_once __DIR__ . '/../lib/TorneoJugadorQrToken.php';
    require_once __DIR__ . '/../lib/PublicInfoTorneoMesasService.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
    header('Content-Type: application/json; charset=utf-8');
    $posted = (string) ($_POST['csrf_token'] ?? '');
    if ($posted === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $posted)) {
        echo json_encode(['ok' => false, 'message' => 'Sesión expirada. Recargue la página.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tidPost = (int) ($_POST['torneo_id'] ?? 0);
    if ($tidPost !== $torneo_id || $tidPost <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Torneo no válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int) ($_POST['id_usuario'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Indique el ID de jugador (número).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdoApi = DB::pdo();
    if (!PublicInfoTorneoMesasService::estaInscrito($pdoApi, $torneo_id, $uid)) {
        echo json_encode(['ok' => false, 'message' => 'Ese ID no está inscrito en este torneo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $tok = TorneoJugadorQrToken::encode($torneo_id, $uid);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'No se pudo generar el enlace.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pub = rtrim(AppHelpers::getPublicUrl(), '/');
    $url = $pub . '/torneo_qr_jugador.php?t=' . rawurlencode($tok);
    echo json_encode(['ok' => true, 'token' => $tok, 'url' => $url], JSON_UNESCAPED_UNICODE);
    exit;
}

// Obtener información del torneo
$pdo = DB::pdo();
$stmt = $pdo->prepare("
    SELECT 
        t.*,
        c.nombre as club_nombre,
        c.delegado as club_delegado,
        u.username as admin_username,
        u.email as admin_email
    FROM tournaments t
    LEFT JOIN clubes c ON t.club_responsable = c.id
    LEFT JOIN usuarios u ON t.club_responsable = u.club_id AND u.role IN ('admin_torneo', 'admin_club')
    WHERE t.id = ?
");
$stmt->execute([$torneo_id]);
$torneo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$torneo) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('Torneo no encontrado'));
    exit;
}

// Verificar estado de las rondas para habilitar cierre de torneo
$rondas_programadas = (int)($torneo['rondas'] ?? 0);
$rondas_completadas = false;
$mensaje_rondas = '';

if ($rondas_programadas > 0) {
    try {
        // Verificar si la tabla partiresul existe
        $stmt_check = $pdo->query("SHOW TABLES LIKE 'partiresul'");
        $tabla_partiresul_existe = $stmt_check->rowCount() > 0;
        
        if ($tabla_partiresul_existe) {
            // Contar rondas generadas
            $stmt = $pdo->prepare("
                SELECT 
                    MAX(partida) as ultima_ronda_generada,
                    COUNT(DISTINCT partida) as total_rondas_generadas
                FROM partiresul
                WHERE id_torneo = ?
            ");
            $stmt->execute([$torneo_id]);
            $info_rondas = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $ultima_ronda_generada = (int)($info_rondas['ultima_ronda_generada'] ?? 0);
            $total_rondas_generadas = (int)($info_rondas['total_rondas_generadas'] ?? 0);
            
            // Verificar que todas las rondas programadas estén generadas
            if ($total_rondas_generadas >= $rondas_programadas) {
                // Verificar que todas las rondas tengan todos sus resultados registrados
                $stmt = $pdo->prepare("
                    SELECT 
                        partida,
                        COUNT(*) as total_partidas,
                        COUNT(CASE WHEN registrado = 1 THEN 1 END) as partidas_registradas
                    FROM partiresul
                    WHERE id_torneo = ?
                    GROUP BY partida
                    ORDER BY partida ASC
                ");
                $stmt->execute([$torneo_id]);
                $rondas_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $todas_completas = true;
                $ronda_incompleta = 0;
                
                foreach ($rondas_detalle as $ronda) {
                    if ($ronda['total_partidas'] > 0 && $ronda['partidas_registradas'] < $ronda['total_partidas']) {
                        $todas_completas = false;
                        $ronda_incompleta = (int)$ronda['partida'];
                        break;
                    }
                }
                
                if ($todas_completas) {
                    $rondas_completadas = true;
                    $mensaje_rondas = "Todas las {$rondas_programadas} rondas programadas están completas y registradas.";
                } else {
                    $mensaje_rondas = "La ronda #{$ronda_incompleta} aún tiene partidas sin registrar resultados.";
                }
            } else {
                $faltan = $rondas_programadas - $total_rondas_generadas;
                $mensaje_rondas = "Faltan {$faltan} ronda(s) por generar. Se han generado {$total_rondas_generadas} de {$rondas_programadas} rondas programadas.";
            }
        } else {
            $mensaje_rondas = "No se han generado rondas aún. Debe generar al menos {$rondas_programadas} ronda(s).";
        }
    } catch (Exception $e) {
        error_log("Error al verificar rondas: " . $e->getMessage());
        $mensaje_rondas = "Error al verificar el estado de las rondas.";
    }
} else {
    // Si no hay rondas programadas, permitir cierre (torneo sin rondas)
    $rondas_completadas = true;
    $mensaje_rondas = "Este torneo no tiene rondas programadas.";
}

// Obtener información del administrador del club
$admin_club = null;
if ($torneo['club_responsable']) {
    $stmt = $pdo->prepare("
        SELECT u.*, c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.club_id = ? 
          AND u.role IN ('admin_torneo', 'admin_club')
          AND u.status = 1
        ORDER BY u.role DESC, u.id ASC
        LIMIT 1
    ");
    $stmt->execute([$torneo['club_responsable']]);
    $admin_club = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Verificar tablas necesarias PRIMERO
$tablas_estado = TournamentAdminHelper::verificarTablas($pdo);
$tabla_inscritos_existe = $tablas_estado['inscritos'];
$tabla_partiresul_existe = $tablas_estado['partiresul'];

// Obtener estadísticas del torneo
$estadisticas = [
    'total_inscritos' => 0,
    'confirmados' => 0,
    'solventes' => 0,
    'retirados' => 0
];

if ($tabla_inscritos_existe) {
    try {
        // Obtener información del usuario actual para filtrar por territorio
        $current_user = Auth::user();
        $user_club_id = $current_user['club_id'] ?? null;
        $is_admin_general = Auth::isAdminGeneral();
        $is_admin_club = Auth::isAdminClub();
        
        // Construir filtro de territorio
        $where_clause = "torneo_id = ?";
        $params = [$torneo_id];
        
        if (!$is_admin_general && $user_club_id) {
            if ($is_admin_club) {
                // Admin_club: estadísticas de su club y clubes supervisados
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
                $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
                
                if (!empty($clubes_ids)) {
                    $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
                    $where_clause .= " AND id_club IN ($placeholders)";
                    $params = array_merge($params, $clubes_ids);
                } else {
                    $where_clause .= " AND id_club = ?";
                    $params[] = $user_club_id;
                }
            } else {
                // Admin_torneo: solo estadísticas de su club
                $where_clause .= " AND id_club = ?";
                $params[] = $user_club_id;
            }
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_inscritos,
                COUNT(CASE WHEN estatus = 1 THEN 1 END) as confirmados,
                COUNT(CASE WHEN estatus = 2 THEN 2 END) as solventes,
                COUNT(CASE WHEN estatus = 4 THEN 4 END) as retirados
            FROM inscritos
            WHERE $where_clause
        ");
        $stmt->execute($params);
        $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC) ?: $estadisticas;
    } catch (Exception $e) {
        // Usar valores por defecto si hay error
    }
}

// Obtener acción del menú
$menu_action = $_GET['action'] ?? 'dashboard';

// Fase activa del torneo (Workflow: Registro → Preparación → Ejecución → Cierre)
$fase_activa = TournamentPhaseHelper::getFaseActiva($torneo, $pdo);

// Mensajes (GET o flash de sesión tras redirect desde RoundManagerHandler / otros)
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!empty($_SESSION['success'])) {
        $success_message = (string) $_SESSION['success'];
        unset($_SESSION['success']);
    }
    if (!empty($_SESSION['error'])) {
        $error_message = (string) $_SESSION['error'];
        unset($_SESSION['error']);
    }
}
?>

<style>
        .tournament-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .tournament-header .info-item {
            margin-bottom: 0.5rem;
        }
        .tournament-header .info-label {
            font-weight: 600;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .tournament-header .info-value {
            font-size: 1.2rem;
            font-weight: 700;
        }
        .admin-menu {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .menu-item {
            transition: all 0.3s ease;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        .menu-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        .menu-item.active {
            background: #667eea;
            color: white;
        }
        .menu-item.active:hover {
            background: #5568d3;
        }
        .menu-item i {
            width: 30px;
            text-align: center;
        }
        .stats-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
    </style>

<?php
$acciones_solo_reporte = ['generar_qr', 'imprimir_qr_lote', 'reporte_identificacion_jugadores'];
if (in_array($menu_action, $acciones_solo_reporte, true)) {
    $action_file = __DIR__ . '/tournament_admin/' . $menu_action . '.php';
    if (file_exists($action_file)) {
        echo '<div class="container-fluid py-3">';
        require $action_file;
        echo '</div>';
    } else {
        require __DIR__ . '/tournament_admin/dashboard.php';
    }
} else {
?>
<div class="container-fluid">
        <!-- Header de Identificación Superior -->
        <div class="tournament-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-3">
                            <i class="fas fa-trophy me-2"></i>
                            <?= htmlspecialchars($torneo['nombre']) ?>
                        </h1>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-building me-2"></i>Organización Responsable:
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($torneo['club_nombre'] ?? 'No asignada') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-user-shield me-2"></i>Administrador del Club:
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($admin_club['username'] ?? 'No asignado') ?>
                                        <?php if ($admin_club && !empty($admin_club['email'])): ?>
                                            <small class="d-block" style="opacity: 0.8; font-size: 0.9rem;">
                                                <?= htmlspecialchars($admin_club['email']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-calendar me-2"></i>Fecha del Torneo:
                                    </div>
                                    <div class="info-value" style="font-size: 1rem;">
                                        <?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-users me-2"></i>Total Inscritos:
                                    </div>
                                    <div class="info-value" style="font-size: 1rem;">
                                        <?= number_format($estadisticas['total_inscritos'] ?? 0) ?>
                                        <small style="opacity: 0.8;">
                                            (<?= $estadisticas['confirmados'] ?? 0 ?> confirmados, 
                                             <?= $estadisticas['solventes'] ?? 0 ?> solventes)
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <?php 
                        // Verificar si el torneo está finalizado
                        $torneo_finalizado = isset($torneo['finalizado']) && (int)$torneo['finalizado'] == 1;
                        $puede_acceder = Auth::canAccessTournament($torneo_id);
                        
                        // El botón solo se habilita si todas las rondas están completas
                        $puede_finalizar = !$torneo_finalizado && $puede_acceder && $rondas_completadas;
                        ?>
                        <?php
                        $es_reporte_tarjetas = in_array($menu_action, ['imprimir_qr_lote', 'reporte_identificacion_jugadores'], true);
                        ?>
                        <?php if ($torneo_finalizado): ?>
                            <span class="badge bg-danger fs-6 mb-2 d-block">
                                <i class="fas fa-lock me-2"></i>Torneo Finalizado
                            </span>
                        <?php elseif (!$rondas_completadas && !$es_reporte_tarjetas): ?>
                            <div class="alert alert-warning mb-2 p-2" style="font-size: 0.85rem;">
                                <i class="fas fa-info-circle me-1"></i>
                                <small><?= htmlspecialchars($mensaje_rondas) ?></small>
                            </div>
                        <?php endif; ?>
                        <?php
                        $acciones_impresion = ['generar_qr', 'imprimir_qr_lote', 'reporte_identificacion_jugadores'];
                        $mostrar_retorno_layout = !in_array($menu_action, $acciones_impresion, true);
                        if ($mostrar_retorno_layout):
                            $base_retorno = function_exists('app_base_url') ? rtrim(app_base_url(), '/') . '/public' : '';
                            $url_retorno = class_exists('AppHelpers')
                                ? AppHelpers::urlPanelTorneoReturn((int) $torneo_id)
                                : (($base_retorno !== '' ? $base_retorno . '/' : '') . 'index.php?page=torneo_gestion&action=panel&torneo_id=' . (int) $torneo_id);
                        ?>
                        <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn btn-light btn-lg">
                            <i class="fas fa-arrow-left me-2"></i>Volver al panel
                        </a>
                        <?php endif; ?>
                        <?php if ($puede_finalizar): ?>
                            <button type="button" class="btn btn-danger btn-lg ms-2" id="btnCerrarTorneo" title="Finalizar Torneo">
                                <i class="fas fa-lock me-2"></i>Finalizar Torneo
                            </button>
                        <?php elseif (!$torneo_finalizado && $puede_acceder && !$rondas_completadas): ?>
                            <button type="button" class="btn btn-danger btn-lg ms-2" disabled title="Complete todas las rondas para finalizar el torneo">
                                <i class="fas fa-lock me-2"></i>Finalizar Torneo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alertas -->
        <?php 
        // Mostrar alerta de tablas faltantes
        echo TournamentAdminHelper::mostrarAlertaTablasFaltantes($tablas_estado);
        ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Estadísticas Rápidas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Inscritos</h6>
                                <h3 class="mb-0"><?= number_format($estadisticas['total_inscritos'] ?? 0) ?></h3>
                            </div>
                            <div class="text-primary">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Confirmados</h6>
                                <h3 class="mb-0 text-success"><?= number_format($estadisticas['confirmados'] ?? 0) ?></h3>
                            </div>
                            <div class="text-success">
                                <i class="fas fa-check-circle fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Solventes</h6>
                                <h3 class="mb-0 text-info"><?= number_format($estadisticas['solventes'] ?? 0) ?></h3>
                            </div>
                            <div class="text-info">
                                <i class="fas fa-dollar-sign fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Retirados</h6>
                                <h3 class="mb-0 text-danger"><?= number_format($estadisticas['retirados'] ?? 0) ?></h3>
                            </div>
                            <div class="text-danger">
                                <i class="fas fa-user-times fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Menú de Administración (solo herramientas de la fase activa) -->
            <div class="col-md-3">
                <div class="admin-menu">
                    <h5 class="mb-3">
                        <i class="fas fa-cog me-2"></i>Menú de Administración
                    </h5>
                    <div class="mb-2 px-2 py-1 rounded bg-light small">
                        <i class="fas fa-flag-checkered me-1"></i>
                        <strong>Fase:</strong> <?= htmlspecialchars(TournamentPhaseHelper::getEtiquetaFase($fase_activa)) ?>
                    </div>
                    
                    <?php 
                    $torneo_finalizado = isset($torneo['finalizado']) && $torneo['finalizado'] == 1;
                    $admin_general_puede_corregir = Auth::isAdminGeneral();
                    $mostrar = function($action) use ($fase_activa, $admin_general_puede_corregir, $torneo_finalizado) {
                        if (TournamentPhaseHelper::mostrarHerramienta($action, $fase_activa)) return true;
                        if ($action === 'ingreso_resultados' && $torneo_finalizado && $admin_general_puede_corregir) return true;
                        return false;
                    };
                    $bloquear_ingreso = ($torneo_finalizado && !$admin_general_puede_corregir) ? 'disabled' : '';
                    $bloquear_ingreso_style = ($torneo_finalizado && !$admin_general_puede_corregir) ? 'opacity: 0.5; cursor: not-allowed; pointer-events: none;' : '';
                    ?>
                    
                    <?php if ($mostrar('revisar_inscripciones')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=revisar_inscripciones" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'revisar_inscripciones' ? 'active' : '' ?>">
                        <i class="fas fa-list-check"></i>
                        <strong>Revisar Inscripciones</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('inscribir_sitio')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=inscribir_sitio" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'inscribir_sitio' ? 'active' : '' ?>">
                        <i class="fas fa-user-plus"></i>
                        <strong>Inscribir en Sitio</strong>
                    </a>
                    <?php endif; ?>
                    
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=activar_participantes" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'activar_participantes' ? 'active' : '' ?>">
                        <i class="fas fa-user-check"></i>
                        <strong>Activar participantes</strong>
                    </a>
                    
                    <?php if ($mostrar('invitar_whatsapp')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=invitar_whatsapp" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'invitar_whatsapp' ? 'active' : '' ?>">
                        <i class="fab fa-whatsapp"></i>
                        <strong>Invitar por WhatsApp</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('generar_rondas')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=generar_rondas" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'generar_rondas' ? 'active' : '' ?>">
                        <i class="fas fa-shuffle"></i>
                        <strong>Generar Rondas</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('eliminar_ronda')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=eliminar_ronda" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'eliminar_ronda' ? 'active' : '' ?>">
                        <i class="fas fa-trash-alt"></i>
                        <strong>Eliminar Última Ronda</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('hojas_anotacion')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=hojas_anotacion" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'hojas_anotacion' ? 'active' : '' ?>">
                        <i class="fas fa-file-alt"></i>
                        <strong>Listar Hojas de Anotación</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('tabla_asignacion')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=tabla_asignacion" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'tabla_asignacion' ? 'active' : '' ?>">
                        <i class="fas fa-table"></i>
                        <strong>Tabla de Asignación</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('mostrar_resultados')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=mostrar_resultados" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'mostrar_resultados' ? 'active' : '' ?>">
                        <i class="fas fa-chart-bar"></i>
                        <strong>Mostrar Resultados</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('ingreso_resultados')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=ingreso_resultados" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'ingreso_resultados' ? 'active' : '' ?> <?= $bloquear_ingreso ?>"
                       style="<?= $bloquear_ingreso_style ?>">
                        <i class="fas fa-edit"></i>
                        <strong>Ingreso de Resultados</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('galeria_fotos')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=galeria_fotos" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'galeria_fotos' ? 'active' : '' ?>">
                        <i class="fas fa-images"></i>
                        <strong>Galería de Fotos</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('generar_qr')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=generar_qr" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'generar_qr' ? 'active' : '' ?>">
                        <i class="fas fa-qrcode"></i>
                        <strong>Generar e imprimir QR del torneo</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('generar_qr_general')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=generar_qr_general" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'generar_qr_general' ? 'active' : '' ?>">
                        <i class="fas fa-list"></i>
                        <strong>QR General</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('generar_qr_personal')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=generar_qr_personal" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'generar_qr_personal' ? 'active' : '' ?>">
                        <i class="fas fa-user-qrcode"></i>
                        <strong>QR Personal</strong>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar('generar_qr')): ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=reporte_identificacion_jugadores" 
                       class="menu-item d-block text-decoration-none text-dark <?= $menu_action === 'reporte_identificacion_jugadores' ? 'active' : '' ?>">
                        <i class="fas fa-address-card"></i>
                        <strong>Identificación de jugadores</strong>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contenido Principal -->
            <div class="col-md-9">
                <?php
                // Incluir la subpágina correspondiente según la acción
                // Las variables $torneo_id, $torneo, $pdo, $tabla_inscritos_existe, $tabla_partiresul_existe
                // están disponibles para todas las subpáginas
                
                // generar_qr carga la página completa de QRs (generar_qr.php); generar_qr_general y generar_qr_personal son opciones alternativas
                $action_file = __DIR__ . '/tournament_admin/' . $menu_action . '.php';
                
                if (file_exists($action_file)) {
                    // Variables adicionales para invitar_whatsapp
                    if ($menu_action === 'invitar_whatsapp') {
                        $is_admin_general = Auth::isAdminGeneral();
                        $is_admin_club = Auth::isAdminClub();
                        $is_admin_torneo = Auth::isAdminTorneo();
                    }
                    require $action_file;
                } else {
                    // Dashboard por defecto
                    require __DIR__ . '/tournament_admin/dashboard.php';
                }
                ?>
            </div>
        </div>
    </div>

<script>
// Funcionalidad para cerrar torneo
document.addEventListener('DOMContentLoaded', function() {
    const btnCerrarTorneo = document.getElementById('btnCerrarTorneo');
    if (!btnCerrarTorneo) return;
    
    btnCerrarTorneo.addEventListener('click', function() {
        const torneoId = <?= $torneo_id ?>;
        const torneoNombre = <?= json_encode($torneo['nombre'] ?? 'Torneo') ?>;
        const rondasProgramadas = <?= $rondas_programadas ?>;
        
        // Primera confirmación con información detallada
        const mensaje1 = '¿Está seguro de que desea FINALIZAR DEFINITIVAMENTE el torneo "' + torneoNombre + '"?\n\n' +
            'INFORMACIÓN:\n' +
            '- Rondas programadas: ' + rondasProgramadas + '\n' +
            '- Estado: Todas las rondas están completas\n\n' +
            'CONSECUENCIAS DE ESTA ACCIÓN:\n' +
            '✓ Inhabilitará TODOS los botones de acción del torneo\n' +
            '✓ No podrá actualizar, generar rondas, ingresar resultados, eliminar rondas\n' +
            '✓ Solo podrá ver resultados hasta el resumen de jugador\n' +
            '✓ NO podrá acceder nuevamente para realizar ninguna acción\n' +
            '✓ Esta acción es IRREVERSIBLE y NO se puede deshacer\n\n' +
            '¿Desea continuar?';
        
        if (!confirm(mensaje1)) {
            return;
        }
        
        // Segunda confirmación más estricta
        const mensaje2 = '⚠️ ADVERTENCIA FINAL ⚠️\n\n' +
            'Está a punto de FINALIZAR PERMANENTEMENTE el torneo "' + torneoNombre + '".\n\n' +
            'Una vez finalizado:\n' +
            '• NO podrá modificar ningún resultado\n' +
            '• NO podrá generar nuevas rondas\n' +
            '• NO podrá acceder a las funcionalidades de administración\n' +
            '• Solo podrá consultar resultados finales\n\n' +
            'Esta acción NO se puede deshacer.\n\n' +
            '¿CONFIRMA que desea finalizar el torneo?';
        
        if (!confirm(mensaje2)) {
            return;
        }
        
        // Deshabilitar botón mientras se procesa
        btnCerrarTorneo.disabled = true;
        btnCerrarTorneo.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Finalizando...';
        
        // Obtener token CSRF
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        
        // Enviar petición
        const formData = new FormData();
        formData.append('torneo_id', torneoId);
        formData.append('confirmar', 'true');
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        
        fetch('<?= app_base_url() ?>/api/tournament_close.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Torneo finalizado exitosamente. La página se recargará.');
                window.location.reload();
            } else {
                alert('Error: ' + (data.message || 'No se pudo finalizar el torneo'));
                btnCerrarTorneo.disabled = false;
                btnCerrarTorneo.innerHTML = '<i class="fas fa-lock me-2"></i>Finalizar Torneo';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al comunicarse con el servidor. Por favor, intente nuevamente.');
            btnCerrarTorneo.disabled = false;
            btnCerrarTorneo.innerHTML = '<i class="fas fa-lock me-2"></i>Finalizar Torneo';
        });
    });
});
</script>
<?php
}
?>
