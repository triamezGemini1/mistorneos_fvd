<?php
/**
 * Vista Moderna: Panel de Control de Torneo
 * Panel común para todos los tipos de torneo (individual/parejas/equipos)
 * Se adapta dinámicamente según la modalidad del torneo
 * Diseño con Tailwind CSS - 3 columnas organizadas
 */
require_once __DIR__ . '/../../config/db.php';
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
if (!class_exists('CSRF', false)) {
    require_once __DIR__ . '/../../config/csrf.php';
}

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$ctx_switch_base_url = function_exists('torneoGestionContextSwitchBaseUrl') ? torneoGestionContextSwitchBaseUrl() : $base_url;

// Asegurar que $torneo esté disponible (viene de extract($view_data) en torneo_gestion.php)
// Si no está disponible después del extract, intentar obtenerlo
if (!isset($torneo) || empty($torneo)) {
    // Intentar obtenerlo del torneo_id que debería estar disponible
    $torneo_id_local = isset($torneo_id) ? (int)$torneo_id : (int)($_GET['torneo_id'] ?? 0);
    if ($torneo_id_local > 0) {
        try {
            $pdo = DB::pdo();
            $has_cod_org = false;
            try {
                $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $ignored) {
                $has_cod_org = false;
            }
            $org_join = $has_cod_org
                ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
                : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";
            $stmt = $pdo->prepare("SELECT t.*, o.nombre as organizacion_nombre FROM tournaments t {$org_join} WHERE t.id = ?");
            $stmt->execute([$torneo_id_local]);
            $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error obteniendo torneo en panel-moderno.php: " . $e->getMessage());
            $torneo = null;
        }
    }
}

// Asegurar valores por defecto si aún no está disponible
if (!isset($torneo) || empty($torneo) || !is_array($torneo)) {
    $torneo = ['id' => $torneo_id_local ?? 0, 'nombre' => 'Torneo', 'modalidad' => 0];
}

$page_title = 'Panel de Control - ' . htmlspecialchars($torneo['nombre'] ?? 'Torneo');

// Detectar modalidad del torneo (2 = Parejas, 3 = Equipos, 4 = Parejas fijas)
$modalidad_num_panel = (int)($torneo['modalidad'] ?? 0);
$es_modalidad_equipos = ($modalidad_num_panel === 3);
$es_modalidad_parejas = ($modalidad_num_panel === 2); // Parejas por equipos (mismo flujo que equipos de 4)
$es_modalidad_parejas_fijas = ($modalidad_num_panel === 4);
$es_modalidad_equipos_o_parejas = ($es_modalidad_equipos || $es_modalidad_parejas);
$context_switcher = isset($context_switcher) && is_array($context_switcher) ? $context_switcher : ['active_tournament_id' => (int)($torneo['id'] ?? 0), 'items' => []];
$has_panel_context_switch = !empty($context_switcher['items']);
$paired_tournaments_status = isset($paired_tournaments_status) && is_array($paired_tournaments_status) ? $paired_tournaments_status : ['enabled' => false, 'items' => [], 'bloqueo' => null];
$bloqueo_cierre_total = $paired_tournaments_status['bloqueo'] ?? null;

// Variables de estado primero (extract puede no definir claves; no usar $ultima_ronda antes de normalizar)
$ultima_ronda_val = isset($ultima_ronda) && $ultima_ronda !== null ? (int)$ultima_ronda : (isset($ultimaRonda) && $ultimaRonda !== null ? (int)$ultimaRonda : 0);
$proxima_ronda_val = isset($proxima_ronda) && $proxima_ronda !== null ? (int)$proxima_ronda : (isset($proximaRonda) && $proximaRonda !== null ? (int)$proximaRonda : ($ultima_ronda_val + 1));
$ultima_ronda = $ultima_ronda_val;
$proxima_ronda = $proxima_ronda_val;
$proximaRonda = $proxima_ronda_val;

// Lógica de bloqueo de inscripciones: equipos, parejas y parejas fijas bloquean desde ronda >=1; otros desde ronda >=2
$torneo_bloqueado_inscripciones = false;
if ($ultima_ronda > 0) {
    $torneo_bloqueado_inscripciones = ($es_modalidad_equipos || $es_modalidad_parejas || $es_modalidad_parejas_fijas) ? ($ultima_ronda >= 1) : ($ultima_ronda >= 2);
}
$ultima_ronda_tiene_resultados = isset($ultima_ronda_tiene_resultados) ? (bool)$ultima_ronda_tiene_resultados : false;
$totalRondas = isset($torneo['rondas']) ? (int)$torneo['rondas'] : 0;
$puede_generar_ronda = isset($puede_generar_ronda) ? (bool)$puede_generar_ronda : (isset($puedeGenerarRonda) ? (bool)$puedeGenerarRonda : true);
$puedeGenerar = $puede_generar_ronda;
$mesas_incompletas = isset($mesas_incompletas) && $mesas_incompletas !== null ? (int)$mesas_incompletas : (isset($mesasIncompletas) && $mesasIncompletas !== null ? (int)$mesasIncompletas : 0);
$mesasInc = $mesas_incompletas;
$isLocked = isset($torneo['locked']) ? ((int)$torneo['locked'] === 1) : false;
// Finalizar torneo: habilitado cuando torneo completado (todas rondas, 0 mesas pendientes); no se exige esperar 20 min
$correcciones_cierre_at = isset($correcciones_cierre_at) ? $correcciones_cierre_at : null;
$torneo_completado = $totalRondas > 0 && $ultima_ronda >= $totalRondas && $mesasInc == 0;
$puedeCerrar = !$isLocked && $ultima_ronda > 0 && $mesasInc == 0 && $torneo_completado;
// Countdown "correcciones se cierran" desde correcciones_cierre_at (fijado al guardar última mesa; no se resetea)
$countdown_fin_timestamp = null;
$mostrar_aviso_20min = false;
if (!empty($correcciones_cierre_at) && $correcciones_cierre_at !== '0000-00-00 00:00:00') {
    $countdown_fin_timestamp = strtotime($correcciones_cierre_at);
    $mostrar_aviso_20min = !$isLocked && $torneo_completado && (time() < $countdown_fin_timestamp);
}

// Actas pendientes de verificación (QR)
$actas_pendientes_count = isset($actas_pendientes_count) && $actas_pendientes_count !== null ? (int)$actas_pendientes_count : 0;
// Auditoría: mesas Verificadas (QR con foto) vs Digitadas (por admin)
$mesas_verificadas_count = isset($mesas_verificadas_count) && $mesas_verificadas_count !== null ? (int)$mesas_verificadas_count : 0;
$mesas_digitadas_count = isset($mesas_digitadas_count) && $mesas_digitadas_count !== null ? (int)$mesas_digitadas_count : 0;

// Estadísticas adicionales
$total_inscritos = isset($total_inscritos) && $total_inscritos !== null ? (int)$total_inscritos : (isset($totalInscritos) && $totalInscritos !== null ? (int)$totalInscritos : 0);
// Participantes que cuentan para rondas/mesas/BYE = solo confirmados (estatus 1)
$inscritos_para_rondas = isset($inscritos_confirmados) && $inscritos_confirmados !== null ? (int)$inscritos_confirmados : $total_inscritos;
$total_equipos = isset($total_equipos) && $total_equipos !== null ? (int)$total_equipos : (isset($estadisticas['total_equipos']) ? (int)$estadisticas['total_equipos'] : 0);
$estadisticas = isset($estadisticas) && is_array($estadisticas) ? $estadisticas : [];

// Obtener primera mesa para registrar resultados
$primera_mesa = null;
if ($ultima_ronda > 0 && isset($torneo['id'])) {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT MIN(CAST(mesa AS UNSIGNED)) as primera_mesa FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0");
        $stmt->execute([$torneo['id'], $ultima_ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $primera_mesa = $result['primera_mesa'] ?? null;
    } catch (Exception $e) {
        error_log("Error obteniendo primera mesa en panel-moderno.php: " . $e->getMessage());
    }
}

$tid_panel = (int)($torneo['id'] ?? 0);
$es_evento_masivo_panel = (int)($torneo['es_evento_masivo'] ?? 0) > 0;
$url_reportes_inscritos = ($tid_panel > 0 && class_exists('AppHelpers', false))
    ? AppHelpers::torneoGestionUrl('reportes_inscritos', $tid_panel)
    : ($tid_panel > 0 ? 'index.php?page=torneo_gestion&action=reportes_inscritos&torneo_id=' . $tid_panel : '#');
$url_reportes_pago_usuarios = ($tid_panel > 0 && class_exists('AppHelpers', false))
    ? AppHelpers::dashboard('reportes_pago_usuarios', ['torneo_id' => $tid_panel])
    : ($tid_panel > 0 ? 'index.php?page=reportes_pago_usuarios&torneo_id=' . $tid_panel : '#');
?>

<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/modern-panel.css">
<link rel="stylesheet" href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::assetVersion('assets/css/panel-control-14in.css') : 'assets/css/panel-control-14in.css') ?>">
<link rel="stylesheet" href="assets/css/torneo-context-switch.css">
<?php if ($use_standalone): ?>
<!-- Tailwind compilado localmente (colores panel-* en tailwind.config.js) -->
<link rel="stylesheet" href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::assetVersion('assets/dist/output.css') : 'assets/dist/output.css') ?>">
<?php endif; ?>
<?php /* Estilos del panel movidos a assets/css/modern-panel.css (Design System + Panel) */ ?>

<div class="tw-panel ds-root">
    <!-- Breadcrumb: sin repetir el nombre del torneo (el nombre va en el encabezado) -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="flex items-center text-sm text-gray-500">
            <li><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index" class="hover:text-blue-600">Gestión de Torneos</a></li>
        </ol>
    </nav>

    <!-- Header del Torneo (compacto): nombre a la izquierda, selector de torneos asociados a la derecha -->
    <div class="panel-header panel-header--compact fvd-panel-header rounded-xl shadow-lg p-4 mb-3 text-white">
        <div class="panel-header-inner flex items-center flex-wrap gap-4 <?php echo $has_panel_context_switch ? 'panel-header-inner--spread' : 'justify-center'; ?>">
            <div class="panel-header-grow">
                <h2 class="titulo-torneo">
                    <?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?>
                </h2>
                <div class="meta flex flex-wrap gap-4">
                    <span><i class="fas fa-calendar-alt mr-1"></i> <?php echo date('d/m/Y', strtotime($torneo['fechator'] ?? 'now')); ?></span>
                    <span><i class="fas fa-chess mr-1"></i> 
                        <?php 
                        $modalidad_num = (int)($torneo['modalidad'] ?? 0);
                        if ($modalidad_num === 3) {
                            echo 'Equipos';
                        } else if ($modalidad_num === 4) {
                            echo 'Parejas fijas';
                        } else if ($modalidad_num === 2) {
                            echo 'Parejas';
                        } else {
                            echo 'Individual';
                        }
                        ?>
                    </span>
                    <span><i class="fas fa-layer-group mr-1"></i> <?php echo ($torneo['rondas'] ?? 0); ?> rondas</span>
                </div>
            </div>
            <?php if ($has_panel_context_switch): ?>
            <div class="panel-header-context">
                <?php
                $tcs = [
                    'items' => $context_switcher['items'],
                    'active_id' => (int)($context_switcher['active_tournament_id'] ?? 0),
                    'base_url' => $ctx_switch_base_url,
                    'sep' => $use_standalone ? '?' : '&',
                    'ronda_base' => 0,
                    'map_max' => [],
                    'mode' => 'panel',
                    'theme' => 'on_dark',
                    'select_id' => 'torneo-asociado-select-panel',
                    'show_info' => false,
                    'show_select' => true,
                    'aria_label' => 'Selector de torneos asociados',
                    'pill_row_class' => 'panel-header-context__pills',
                ];
                require __DIR__ . '/../../resources/views/partials/torneo_context_switch.php';
                ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mensajes de éxito/error -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4 flex items-center justify-between">
            <span><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
            <button type="button" onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4 flex items-center justify-between">
            <span><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
            <button type="button" onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">&times;</button>
        </div>
    <?php endif; ?>

    <?php
    require_once __DIR__ . '/../../lib/CronometroPublicoToken.php';
    $url_cronometro = class_exists('AppHelpers')
        ? AppHelpers::url('cronometro_publico.php', CronometroPublicoToken::queryParams((int)($torneo['id'] ?? 0)))
        : ('cronometro_publico.php?' . http_build_query(CronometroPublicoToken::queryParams((int)($torneo['id'] ?? 0))));
    $url_verificar_actas_strip = $base_url . ($use_standalone ? '?' : '&') . 'action=verificar_resultados&torneo_id=' . (int)($torneo['id'] ?? 0);
    $paired_items_strip = (!empty($paired_tournaments_status['enabled']) && !empty($paired_tournaments_status['items']))
        ? array_values($paired_tournaments_status['items'])
        : [];
    $jugadores_strip = $es_modalidad_equipos
        ? (int)($estadisticas['total_jugadores_inscritos'] ?? 0)
        : (int)($inscritos_para_rondas ?? 0);
    $equipos_strip = (int)($total_equipos ?? 0);
    $u_banner_strip = class_exists('Auth') ? Auth::user() : null;
    $puede_admin_banner_strip = is_array($u_banner_strip) && in_array(($u_banner_strip['role'] ?? ''), ['admin_general', 'admin_club'], true);
    ?>

    <div class="panel-top-strip" role="region" aria-label="Resumen de ronda, auditoría y cronómetro">
        <!-- Col 1: Auditoría + actas pendientes -->
        <div class="panel-top-strip__col">
            <span class="panel-top-strip__label">Auditoría</span>
            <div class="panel-top-strip__audit-row">
                <div class="panel-badge-med panel-badge-med--emerald" title="Verificadas (QR)">
                    <span class="panel-badge-med__k">QR</span>
                    <span class="panel-badge-med__v"><?php echo (int)$mesas_verificadas_count; ?></span>
                </div>
                <div class="panel-badge-med panel-badge-med--blue" title="Digitadas (admin)">
                    <span class="panel-badge-med__k">ADM</span>
                    <span class="panel-badge-med__v"><?php echo (int)$mesas_digitadas_count; ?></span>
                </div>
            </div>
            <?php if ($actas_pendientes_count > 0): ?>
            <div class="panel-badge-med panel-badge-med--amber" title="Actas pendientes de verificación (QR)">
                <span class="panel-badge-med__k">Actas</span>
                <span class="panel-badge-med__v"><?php echo (int)$actas_pendientes_count; ?></span>
            </div>
            <a href="<?= htmlspecialchars($url_verificar_actas_strip, ENT_QUOTES, 'UTF-8') ?>" class="panel-top-strip__link-verify">Verificar actas</a>
            <?php endif; ?>
        </div>

        <!-- Col 2: Cronómetro + cierre oficial -->
        <div class="panel-top-strip__col panel-top-strip__cron">
            <span class="panel-top-strip__label">Cronómetro</span>
            <?php if ($mostrar_aviso_20min && $countdown_fin_timestamp): ?>
            <div class="cronometro-finalizar-torneo" id="countdown-cierre-torneo-top">
                <p class="cron-finalizar-label">Cierre oficial en</p>
                <p class="countdown-tiempo-restante tabular-nums" data-fin="<?php echo (int)$countdown_fin_timestamp; ?>">--:--</p>
                <p class="cron-finalizar-hint">Luego: Finalizar torneo</p>
            </div>
            <?php elseif ($puedeCerrar): ?>
            <div class="cronometro-finalizar-torneo">
                <p class="cron-finalizar-label">Listo para cerrar</p>
                <p class="cron-finalizar-hint">Finalizar en Operaciones</p>
            </div>
            <?php endif; ?>
            <button type="button" id="btnCronometroVentana" class="panel-top-strip__cron-btn">Activar cronómetro</button>
            <?php if ($puede_admin_banner_strip): ?>
            <a href="index.php?page=bannerclock&torneo_id=<?php echo (int)$torneo['id']; ?>" class="panel-top-strip__banner-link">Banner del reloj</a>
            <?php endif; ?>
        </div>

        <!-- Col 3: Equipos y jugadores / inscritos -->
        <div class="panel-top-strip__col">
            <span class="panel-top-strip__label">Estadísticas</span>
            <div class="panel-top-strip__stats-row">
                <?php if ($es_modalidad_equipos): ?>
                <div class="panel-badge-med panel-badge-med--indigo">
                    <span class="panel-badge-med__k">Equipos</span>
                    <span class="panel-badge-med__v"><?php echo $equipos_strip; ?></span>
                </div>
                <?php endif; ?>
                <div class="panel-badge-med panel-badge-med--rose">
                    <span class="panel-badge-med__k"><?php echo $es_modalidad_equipos ? 'Jugadores' : 'Inscritos'; ?></span>
                    <span class="panel-badge-med__v"><?php echo $jugadores_strip; ?><?php if (!$es_modalidad_equipos && isset($total_inscritos) && (int)$total_inscritos !== $jugadores_strip): ?> <span class="panel-badge-med__sub">/<?php echo (int)$total_inscritos; ?></span><?php endif; ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php if (!empty($bloqueo_cierre_total['mensaje']) && !empty($paired_items_strip)): ?>
    <div class="panel-top-strip__bloqueo-full"><?php echo htmlspecialchars((string)$bloqueo_cierre_total['mensaje'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <script>
    (function() {
        var btnCron = document.getElementById('btnCronometroVentana');
        if (!btnCron) return;
        var urlCronometro = <?php echo json_encode($url_cronometro, JSON_UNESCAPED_SLASHES); ?>;
        btnCron.addEventListener('click', function () {
            var w = 1100, h = 820;
            var features = [
                'popup=yes',
                'width=' + w,
                'height=' + h,
                'left=' + Math.max(0, Math.floor((screen.availWidth - w) / 2)),
                'top=' + Math.max(0, Math.floor((screen.availHeight - h) / 2)),
                'menubar=no',
                'toolbar=no',
                'location=no',
                'status=no',
                'resizable=yes',
                'scrollbars=yes'
            ].join(',');
            var win = window.open(urlCronometro, 'cronometro_torneo_<?php echo (int)$torneo['id']; ?>', features);
            if (win) {
                win.focus();
            } else {
                window.location.href = urlCronometro;
            }
        });
    })();
    </script>
    
    <!-- Alerta de Torneo Cerrado (compacto) -->
    <?php if ($isLocked): ?>
        <div class="bg-gray-100 border-l-4 border-gray-500 rounded-lg p-2 mb-2">
            <div class="flex items-center gap-2 text-gray-700">
                <i class="fas fa-lock text-xl"></i>
                <span class="font-semibold">Torneo cerrado: solo se permite consultar e imprimir. Las acciones de modificación están deshabilitadas.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Panel de Control - 3 Columnas (compacto) -->
    <div class="tw-columns flex gap-3">
            <!-- COLUMNA IZQUIERDA: Gestión de Mesas -->
            <div class="tw-column w-1/3">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden h-full">
                    <div class="bg-gradient-to-r from-emerald-500 to-teal-500 px-4 py-2">
                        <h3 class="text-white text-lg flex items-center mb-0">
                            <i class="fas fa-table mr-2"></i> Gestión de Mesas
                        </h3>
                    </div>
                    <div class="p-5 space-y-4">
                        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=export_access_portal&torneo_id=<?php echo (int)($torneo['id'] ?? 0); ?>"
                           class="tw-btn tw-btn--trans-access w-full text-center"
                           title="Transferencia de inscritos y partidas para Microsoft Access">
                            <i class="fas fa-database mr-2"></i> Trans Access
                        </a>
                        <?php
                        $tid_op = (int)($torneo['id'] ?? 0);
                        $href_op_swap = class_exists('AppHelpers', false)
                            ? AppHelpers::url('index.php', ['page' => 'op_especiales', 'torneo_id' => $tid_op, 'view' => 'swap'])
                            : ('index.php?page=op_especiales&torneo_id=' . $tid_op . '&view=swap');
                        $href_op_carga = class_exists('AppHelpers', false)
                            ? AppHelpers::url('index.php', ['page' => 'op_especiales', 'torneo_id' => $tid_op, 'view' => 'carga'])
                            : ('index.php?page=op_especiales&torneo_id=' . $tid_op . '&view=carga');
                        ?>
                        <a href="<?php echo htmlspecialchars($href_op_swap, ENT_QUOTES, 'UTF-8'); ?>" class="tw-btn bg-purple-800 hover:bg-purple-900 text-white w-full text-center border border-purple-600">
                            <i class="fas fa-exchange-alt mr-2"></i> Cambiar Atleta
                        </a>
                        <?php if ((int)($torneo['estatus'] ?? 0) === 9): ?>
                        <a href="<?php echo htmlspecialchars($href_op_carga, ENT_QUOTES, 'UTF-8'); ?>" class="tw-btn bg-violet-600 hover:bg-violet-700 text-white w-full text-center">
                            <i class="fas fa-flask mr-2"></i> Carga automática y análisis (estatus 9)
                        </a>
                        <?php endif; ?>
                        <!-- Inscripciones: un solo bloque (Gestionar + Inscribir en sitio) -->
                        <?php if ($isLocked): ?>
                            <!-- Torneo finalizado: inscripciones totalmente cerradas -->
                            <button type="button" disabled class="tw-btn bg-gray-400 text-white">
                                <i class="fas fa-lock"></i> Inscripciones (Cerrado)
                            </button>
                        <?php elseif ($torneo_bloqueado_inscripciones): ?>
                            <!-- Torneo ya comenzó pero no finalizado: solo permitir retirar jugadores -->
                            <a href="index.php?page=registrants&torneo_id=<?php echo $torneo['id']; ?><?php echo $use_standalone ? '&return_to=panel_torneo' : ''; ?>" class="tw-btn bg-blue-500 hover:bg-blue-600 text-white">
                                <i class="fas fa-clipboard-list"></i> Gestionar Inscripciones (retirar)
                            </a>
                            <?php if (!$es_modalidad_equipos && $ultima_ronda >= 1): ?>
                            <a href="index.php?page=torneo_gestion&action=sustituir_jugador&torneo_id=<?php echo (int)$torneo['id']; ?>" class="tw-btn bg-amber-500 hover:bg-amber-600 text-white"><i class="fas fa-user-exchange"></i> Sustituir jugador retirado</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="d-flex flex-column panel-card-actions">
                                <?php if ($es_modalidad_equipos_o_parejas): ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=gestionar_inscripciones_equipos&torneo_id=<?php echo $torneo['id']; ?>" class="tw-btn bg-blue-500 hover:bg-blue-600 text-white"><i class="fas fa-clipboard-list"></i> <?php echo $es_modalidad_parejas ? 'Gestionar Inscripciones (Parejas)' : 'Gestionar Inscripciones'; ?></a>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=inscribir_equipo_sitio&torneo_id=<?php echo $torneo['id']; ?><?php echo $es_modalidad_parejas ? '&abrir_form=1' : ''; ?>" class="tw-btn bg-amber-500 hover:bg-amber-600 text-white"><i class="fas fa-user-plus"></i> <?php echo $es_modalidad_parejas ? 'Inscribir pareja' : 'Inscribir en Sitio'; ?></a>
                                    <?php if ($es_modalidad_equipos): ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=carga_masiva_equipos_sitio&torneo_id=<?php echo $torneo['id']; ?>" class="tw-btn bg-amber-700 hover:bg-amber-800 text-white"><i class="fas fa-file-upload"></i> Carga masiva</a>
                                    <?php elseif ($es_modalidad_parejas): ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=carga_masiva_parejas_sitio&torneo_id=<?php echo $torneo['id']; ?>" class="tw-btn bg-amber-700 hover:bg-amber-800 text-white"><i class="fas fa-file-upload"></i> Carga masiva parejas</a>
                                    <?php endif; ?>
                                <?php elseif ($es_modalidad_parejas_fijas): ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=gestionar_inscripciones_parejas_fijas&torneo_id=<?php echo $torneo['id']; ?>" class="tw-btn bg-blue-500 hover:bg-blue-600 text-white"><i class="fas fa-clipboard-list"></i> Gestionar Inscripciones</a>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=inscribir_pareja_sitio&torneo_id=<?php echo $torneo['id']; ?>" class="tw-btn bg-amber-500 hover:bg-amber-600 text-white"><i class="fas fa-user-plus"></i> Inscribir Pareja en Sitio</a>
                                <?php else: ?>
                                    <a href="index.php?page=registrants&torneo_id=<?php echo $torneo['id']; ?><?php echo $use_standalone ? '&return_to=panel_torneo' : ''; ?>" class="tw-btn bg-blue-500 hover:bg-blue-600 text-white"><i class="fas fa-clipboard-list"></i> Gestionar Inscripciones</a>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=inscribir_sitio&torneo_id=<?php echo $torneo['id']; ?>" class="tw-btn bg-amber-500 hover:bg-amber-600 text-white"><i class="fas fa-user-check"></i> Inscripción en Sitio</a>
                                    <button type="button" class="tw-btn bg-indigo-500 hover:bg-indigo-600 text-white" data-bs-toggle="modal" data-bs-target="#modalImportacionMasiva" id="btnAbrirImportacionMasiva"><i class="fas fa-file-csv"></i> Importación masiva</button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Mostrar Asignaciones (solo si hay rondas generadas) -->
                        <?php if ($ultima_ronda > 0): ?>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=mesas&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                               class="tw-btn bg-emerald-500 hover:bg-emerald-600 text-white">
                                <i class="fas fa-eye"></i> Mostrar Asignaciones
                            </a>
                            
                            <!-- Asignar mesas al operador -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=asignar_mesas_operador&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                               class="tw-btn bg-teal-500 hover:bg-teal-600 text-white">
                                <i class="fas fa-user-cog"></i> Asignar mesas al operador
                            </a>
                            
                            <!-- Agregar Mesa: solo habilitado en ronda 1 -->
                            <?php if ($isLocked): ?>
                                <button type="button" disabled class="tw-btn bg-gray-400 text-white">
                                    <i class="fas fa-lock"></i> Agregar Mesa (Cerrado)
                                </button>
                            <?php elseif ($ultima_ronda >= 2): ?>
                                <button type="button" disabled class="tw-btn bg-gray-400 text-white" title="Solo disponible en la ronda 1">
                                    <i class="fas fa-plus-circle"></i> Agregar Mesa (solo ronda 1)
                                </button>
                            <?php else: ?>
                                <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=agregar_mesa&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                                   class="tw-btn bg-cyan-500 hover:bg-cyan-600 text-white">
                                    <i class="fas fa-plus-circle"></i> Agregar Mesa
                                </a>
                            <?php endif; ?>

                            <a href="index.php?page=tournament_admin&torneo_id=<?php echo (int)($torneo['id'] ?? 0); ?>&action=generar_qr" 
                               class="tw-btn bg-violet-600 hover:bg-violet-700 text-white w-full text-center" target="_blank" rel="noopener">
                                <i class="fas fa-qrcode"></i> Generar e imprimir QR del torneo
                            </a>
                        <?php else: ?>
                            <!-- Sin rondas generadas -->
                            <div class="bg-gray-50 rounded-lg p-3 text-center text-gray-500 text-sm">
                                <i class="fas fa-info-circle mr-2"></i>
                                Genera la primera ronda para ver estas opciones
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- COLUMNA CENTRAL: Operaciones Principales -->
            <div class="tw-column w-1/3">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden h-full">
                    <div class="bg-gradient-to-r from-blue-500 to-indigo-500 px-4 py-2">
<h3 class="text-white text-lg flex items-center mb-0">
                        <i class="fas fa-cogs mr-2"></i> Operaciones
                    </h3>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Actualizar Resultados -->
                        <form method="POST" action="<?php echo $use_standalone ? $base_url : 'index.php?page=torneo_gestion'; ?>" 
                              onsubmit="event.preventDefault(); actualizarEstadisticasConfirmar(event);">
                            <input type="hidden" name="action" value="actualizar_estadisticas">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                            <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                            <button type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>
                                    class="tw-btn <?php echo $isLocked ? 'bg-gray-400' : 'bg-cyan-500 hover:bg-cyan-600'; ?> text-white">
                                <i class="fas fa-sync-alt"></i> Actualizar Estadísticas
                            </button>
                        </form>

                        <!-- Verificar Mesas (QR): activo cuando hay actas pendientes -->
                        <?php if ($actas_pendientes_count > 0): ?>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=verificar_resultados&torneo_id=<?php echo (int)$torneo['id']; ?>" 
                               class="tw-btn tw-panel-btn--alert"
                               title="<?php echo (int)$actas_pendientes_count; ?> acta(s) pendiente(s)">
                                <i class="fas fa-check-double"></i> Verificar Mesas QR
                            </a>
                        <?php else: ?>
                            <button type="button" disabled class="tw-btn bg-gray-400 text-white">
                                <i class="fas fa-check-double"></i> Verificar Mesas QR
                            </button>
                        <?php endif; ?>
                        
                        <!-- Generar Ronda -->
                        <?php if ($proximaRonda <= $totalRondas): ?>
                            <form method="POST" action="<?php echo $use_standalone ? ($base_url . '?torneo_id=' . (int)($torneo['id'] ?? 0)) : 'index.php?page=torneo_gestion'; ?>" id="form-generar-ronda">
                                <input type="hidden" name="action" value="generar_ronda">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                                <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                <?php if (!$es_modalidad_equipos_o_parejas && !$es_modalidad_parejas_fijas): ?>
                                <input type="hidden" name="estrategia_ronda2" value="separar">
                                <?php endif; ?>
                                <button type="submit" id="btn-generar-ronda"
                                        data-btn-reset-html="<?php echo htmlspecialchars(
                                            'Generar Ronda ' . (int)$proximaRonda,
                                            ENT_QUOTES, 'UTF-8'
                                        ); ?>"
                                        <?php echo (!$puedeGenerar || $isLocked || !empty($bloqueo_cierre_total)) ? 'disabled' : ''; ?>
                                        class="tw-btn <?php echo ($puedeGenerar && !$isLocked && empty($bloqueo_cierre_total)) ? 'bg-blue-500 hover:bg-blue-600' : 'bg-gray-400'; ?> text-white">
                                    <i class="fas fa-<?php echo ($puedeGenerar && !$isLocked && empty($bloqueo_cierre_total)) ? 'play' : 'lock'; ?>"></i>
                                    Generar Ronda <?php echo $proximaRonda; ?>
                                </button>
                            </form>
                            <?php if (!empty($bloqueo_cierre_total['mensaje'])): ?>
                                <div class="text-xs text-amber-700 font-semibold">
                                    <i class="fas fa-ban mr-1"></i><?php echo htmlspecialchars((string)$bloqueo_cierre_total['mensaje'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="bg-green-100 text-green-700 rounded-lg p-3 text-center font-semibold">
                                <i class="fas fa-check-circle mr-2"></i> Todas las rondas generadas
                            </div>
                        <?php endif; ?>
                        
                        <!-- Registrar Resultados (solo si hay rondas y mesas) -->
                        <?php if ($ultima_ronda > 0 && $primera_mesa): ?>
                            <?php if ($isLocked): ?>
                                <button type="button" disabled class="tw-btn bg-gray-400 text-white">
                                    <i class="fas fa-lock"></i> Ingresar Resultados (Cerrado)
                                </button>
                            <?php else: ?>
                                <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>&mesa=<?php echo $primera_mesa; ?>" 
                                   class="tw-btn bg-amber-500 hover:bg-amber-600 text-white">
                                    <i class="fas fa-keyboard"></i> Ingresar Resultados
                                </a>
                            <?php endif; ?>
                        <?php elseif ($ultima_ronda > 0): ?>
                            <button type="button" disabled class="tw-btn bg-gray-400 text-white">
                                <i class="fas fa-info-circle"></i> Sin mesas registradas
                            </button>
                        <?php endif; ?>
                        
                        <!-- Cuadrícula (solo si hay rondas) -->
                        <?php if ($ultima_ronda > 0): ?>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=cuadricula&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                               class="tw-btn bg-purple-500 hover:bg-purple-600 text-white">
                                <i class="fas fa-th"></i> Cuadrícula
                            </a>
                            
                            <!-- Imprimir Hojas (solo si hay rondas) -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=hojas_anotacion&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                               class="tw-btn bg-indigo-500 hover:bg-indigo-600 text-white">
                                <i class="fas fa-print"></i> Imprimir Hojas
                            </a>
                        <?php endif; ?>
                        
                        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=vincular_torneos&torneo_id=<?php echo (int)$torneo['id']; ?>"
                           class="tw-btn bg-slate-600 hover:bg-slate-700 text-white">
                            <i class="fas fa-sitemap"></i> Vincular Torneos (Evento)
                        </a>

                        <?php if ($ultima_ronda > 0): ?>
                            <!-- Eliminar última ronda al final de Operaciones -->
                            <form method="POST" action="<?php echo $use_standalone ? $base_url : 'index.php?page=torneo_gestion'; ?>"
                                  onsubmit="event.preventDefault(); eliminarRondaConfirmar(event, <?php echo $ultima_ronda; ?>, <?php echo $ultima_ronda_tiene_resultados ? 'true' : 'false'; ?>);">
                                <input type="hidden" name="action" value="eliminar_ultima_ronda">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                                <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                                <input type="hidden" name="confirmar_eliminar_con_resultados" value="">
                                <button type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>
                                        class="tw-btn <?php echo $isLocked ? 'bg-gray-400' : 'bg-red-600 hover:bg-red-700'; ?> text-white"
                                        title="<?php echo $isLocked ? 'Torneo cerrado.' : ($ultima_ronda_tiene_resultados ? 'Eliminar ronda (la ronda tiene resultados en mesas; se pedirá confirmación estricta).' : 'Eliminar la última ronda.'); ?>">
                                    <i class="fas fa-trash-alt"></i> Eliminar Ronda
                                </button>
                            </form>
                        <?php endif; ?>

                        <hr class="border-gray-200 my-2">

                        <?php if ($mostrar_aviso_20min && $countdown_fin_timestamp): ?>
                        <div id="countdown-cierre-torneo" class="mb-3 p-3 rounded-lg border-2" style="background-color: #fce7f3; border-color: #c026d3;">
                            <p class="text-sm font-medium mb-1" style="color: #86198f;">
                                <i class="fas fa-clock"></i> El torneo se cerrará oficialmente en:
                            </p>
                            <p class="countdown-tiempo-restante text-2xl font-bold tabular-nums" style="color: #86198f;" data-fin="<?php echo (int)$countdown_fin_timestamp; ?>">
                                --:--
                            </p>
                            <p class="text-xs mt-1" style="color: #701a75;">Tras este tiempo se habilitará el botón <strong>Finalizar torneo</strong>.</p>
                        </div>
                        <?php endif; ?>
                        <form method="POST" action="<?php echo $use_standalone ? $base_url : 'index.php?page=torneo_gestion'; ?>"
                              onsubmit="event.preventDefault(); confirmarCierreTorneo(event);">
                            <input type="hidden" name="action" value="cerrar_torneo">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                            <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                            <button type="submit" <?php echo $puedeCerrar ? '' : 'disabled'; ?>
                                    class="tw-btn <?php echo $isLocked ? 'bg-gray-500' : 'bg-gray-800 hover:bg-gray-900'; ?> text-white">
                                <i class="fas fa-lock"></i>
                                <?php echo $isLocked ? 'Torneo Finalizado' : 'Finalizar torneo'; ?>
                            </button>
                        </form>

                    </div>
                </div>
            </div>
            
            <!-- COLUMNA DERECHA: Resultados y Cierre -->
            <div class="tw-column w-1/3">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden h-full">
                    <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-2">
<h3 class="text-white text-lg flex items-center mb-0">
                        <i class="fas fa-trophy mr-2"></i> Resultados
                    </h3>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Resultados (Adaptado según modalidad) -->
                        <?php if ($es_modalidad_equipos): ?>
                            <!-- Modalidad Equipos: Pool de reportes específicos para equipos -->
                            <!-- Resultados por Equipos - Resumido (orden de clasificación) -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=resultados_equipos_resumido&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="tw-btn bg-purple-500 hover:bg-purple-600 text-white">
                                <i class="fas fa-list-ol"></i> Resultados Equipos (Resumido)
                            </a>
                            
                            <!-- Resultados por Equipos - Detallado (con rompe control por equipo, orden de clasificación) -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=resultados_equipos_detallado&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="tw-btn bg-indigo-500 hover:bg-indigo-600 text-white">
                                <i class="fas fa-list-ul"></i> Resultados Equipos (Detallado)
                            </a>
                            
                            <!-- Resultados / Posiciones (clasificación individual general, mismo reporte que individuales) -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=posiciones&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="tw-btn bg-rose-500 hover:bg-rose-600 text-white">
                                <i class="fas fa-users-cog"></i> Resultados / Posiciones
                            </a>
                        <?php else: ?>
                            <!-- Modalidad Individual/Parejas: Pool de reportes para individual/parejas -->
                            <!-- Mostrar Resultados / Posiciones -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=posiciones&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="tw-btn bg-purple-500 hover:bg-purple-600 text-white">
                                <i class="fas fa-list-ol"></i> <?php echo ($es_modalidad_parejas || $es_modalidad_parejas_fijas) ? 'Resultados Parejas' : 'Resultados'; ?>
                            </a>
                            
                            <!-- Resultados por Club -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=resultados_por_club&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="tw-btn bg-emerald-500 hover:bg-emerald-600 text-white">
                                <i class="fas fa-building"></i> Resultados Clubes
                            </a>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=resultados_reportes&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="tw-btn bg-slate-600 hover:bg-slate-700 text-white">
                                <i class="fas fa-file-alt"></i> Reportes PDF/Excel
                            </a>
                        <?php endif; ?>
                        <?php
                        $url_ranking_atletas_public = rtrim(AppHelpers::getPublicUrl(), '/') . '/ranking_atletas.php';
                        if (class_exists('Auth') && Auth::user() && (Auth::user()['role'] ?? '') === 'admin_club') {
                            $oidRank = (int) Auth::getUserOrganizacionId();
                            if ($oidRank > 0) {
                                $url_ranking_atletas_public .= '?organizacion_id=' . $oidRank;
                            }
                        }
                        ?>
                        <a href="<?php echo htmlspecialchars($url_ranking_atletas_public, ENT_QUOTES, 'UTF-8'); ?>"
                           target="_blank" rel="noopener noreferrer"
                           class="tw-btn bg-cyan-600 hover:bg-cyan-700 text-white w-full text-center"
                           title="Ranking histórico público (femenino/masculino)">
                            <i class="fas fa-medal"></i> Ranking atletas (web pública)
                        </a>
                        
                        <!-- Podios (Común para ambos tipos - detecta modalidad automáticamente) -->
                        <?php 
                        $podios_action = $es_modalidad_equipos ? 'podios_equipos' : 'podios';
                        $sep = $use_standalone ? '?' : '&';
                        $url_podios = $base_url . $sep . 'action=' . $podios_action . '&torneo_id=' . (int)$torneo['id'];
                        ?>
                        <a href="<?php echo htmlspecialchars($url_podios); ?>" 
                           class="tw-btn bg-amber-500 hover:bg-amber-600 text-white"
                           title="Ver podios del torneo">
                            <i class="fas fa-medal"></i> Podios
                        </a>

                        <?php if ($tid_panel > 0): ?>
                        <a href="<?= htmlspecialchars($url_reportes_inscritos, ENT_QUOTES, 'UTF-8'); ?>"
                           class="tw-btn bg-sky-600 hover:bg-sky-700 text-white w-full text-center">
                            <i class="fas fa-file-invoice"></i> Reportes de inscritos
                        </a>
                        <?php if ($es_evento_masivo_panel): ?>
                        <a href="<?= htmlspecialchars($url_reportes_pago_usuarios, ENT_QUOTES, 'UTF-8'); ?>"
                           class="tw-btn bg-emerald-600 hover:bg-emerald-700 text-white w-full text-center">
                            <i class="fas fa-money-bill-wave"></i> Reportes de pago (usuarios)
                        </a>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        </div>

</div>

<!-- Modal Importación Masiva (solo torneos individuales) -->
<div class="modal fade fvd-import-masiva-modal" id="modalImportacionMasiva" tabindex="-1" aria-labelledby="modalImportacionMasivaLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable fvd-import-masiva-modal__dialog">
        <div class="modal-content fvd-import-masiva-modal__content">
            <div class="modal-header fvd-import-masiva-modal__header">
                <h5 class="modal-title" id="modalImportacionMasivaLabel"><i class="fas fa-file-csv me-2"></i>Importación masiva</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body fvd-import-masiva-modal__body">
                <input type="hidden" id="csrf_token_import_masiva" name="csrf_token_import_masiva" value="<?php echo htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                <p class="text-muted small">Cargue <strong>Excel</strong> (.xlsx, .xls, .xlsm) o <strong>CSV</strong>. En el servidor debe existir <code>vendor/</code> (ejecute <code>composer install</code> en la raíz del proyecto) para los formatos modernos de Excel. Campos obligatorios: <strong>nacionalidad, cédula, nombre, club, organización</strong>. Si falta cualquiera, la fila se rechaza. Si aparece sesión expirada o token CSRF, recargue la página (F5) antes de subir. Si el archivo es muy grande, revise <code>upload_max_filesize</code> y <code>post_max_size</code> en php.ini.</p>
                <p class="small mb-2"><strong>Semáforo (tras Validar):</strong> <span class="badge" style="background:#3b82f6">Azul</span> Ya inscrito (omitir) · <span class="badge" style="background:#eab308;color:#000">Amarillo</span> Usuario existe (solo inscribir) · <span class="badge" style="background:#22c55e">Verde</span> Todo nuevo (crear e inscribir) · <span class="badge bg-danger">Rojo</span> Error de datos</p>
                <div class="mb-3">
                    <label class="form-label">Archivo CSV</label>
                    <input type="file" class="form-control" id="importMasivaFile" accept=".xls,.xlsx,.xlsm,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroEnabled.12,text/csv">
                </div>
                <div id="importMasivaMapping" class="mb-3 d-none">
                    <h6 class="mb-2">Mapeo de columnas</h6>
                    <div class="row g-2 flex-wrap" id="importMasivaMappingRow"></div>
                </div>
                <div id="importMasivaPreviewWrap" class="mb-3 d-none">
                    <h6 class="mb-2">Vista previa <span class="badge bg-secondary" id="importMasivaPreviewCount">0</span> filas</h6>
                    <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                        <table class="table table-sm table-bordered" id="importMasivaPreviewTable"></table>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnImportMasivaValidar"><i class="fas fa-check-double me-1"></i>Validar (semáforo)</button>
                        <button type="button" class="btn btn-success btn-sm ms-2" id="btnImportMasivaProcesar"><i class="fas fa-play me-1"></i>Procesar importación</button>
                    </div>
                </div>
                <div id="importMasivaLoading" class="d-none text-center py-3"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2 mb-0">Procesando...</p></div>
            </div>
        </div>
    </div>
</div>

<script>
function limpiarSwalUI() {
    try {
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('overflow');
        document.body.classList.remove('swal2-shown', 'swal2-height-auto');
        document.documentElement.classList.remove('swal2-shown');
        document.querySelectorAll('body > .swal2-container').forEach(function(el) {
            if (el && el.parentNode) el.parentNode.removeChild(el);
        });
    } catch (e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    const formGenerarRonda = document.getElementById('form-generar-ronda');
    if (formGenerarRonda) {
        formGenerarRonda.addEventListener('submit', function() {
            const btnGenerar = formGenerarRonda.querySelector('button[type="submit"]');
            if (btnGenerar && !btnGenerar.disabled) {
                btnGenerar.disabled = true;
                btnGenerar.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Generando...';
            }
        });
    }

    window.addEventListener('pageshow', function() {
        var f = document.getElementById('form-generar-ronda');
        if (!f) return;
        var btn = f.querySelector('button[type="submit"]');
        if (!btn || !btn.disabled) return;
        var reset = btn.getAttribute('data-btn-reset-html');
        if (reset && btn.querySelector('.fa-spinner')) {
            btn.disabled = false;
            btn.innerHTML = reset;
        }
    });

    // Cuenta regresiva: cierre oficial del torneo en 20 minutos (actualiza todos los .countdown-tiempo-restante)
    const countdownEls = document.querySelectorAll('.countdown-tiempo-restante');
    const countdownEl = countdownEls[0];
    if (countdownEl) {
        const finTimestamp = parseInt(countdownEl.getAttribute('data-fin'), 10);
        function actualizarCuentaRegresiva() {
            const ahora = Math.floor(Date.now() / 1000);
            let restante = finTimestamp - ahora;
            const m = Math.floor(restante / 60);
            const s = restante <= 0 ? 0 : (restante % 60);
            const texto = (restante <= 0 ? '00:00' : (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s);
            countdownEls.forEach(function(el) { el.textContent = texto; });
            if (restante <= 0) {
                var listoHtml = '<p class="text-sm font-medium text-white"><i class="fas fa-check-circle"></i> Listo para finalizar. Recargando…</p>';
                var topBlock = document.getElementById('countdown-cierre-torneo-top') || countdownEl.closest('.mb-4');
                if (topBlock) topBlock.innerHTML = listoHtml;
                var col = document.getElementById('countdown-cierre-torneo');
                if (col) col.innerHTML = listoHtml;
                window.clearInterval(intervalId);
                setTimeout(function() { window.location.reload(); }, 1500);
                return;
            }
        }
        actualizarCuentaRegresiva();
        const intervalId = window.setInterval(actualizarCuentaRegresiva, 1000);
    }
});

async function actualizarEstadisticasConfirmar(event) {
    try {
        const result = await Swal.fire({
            title: '¿Actualizar estadísticas?',
            text: '¿Actualizar estadísticas de todos los inscritos?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, actualizar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#6b7280'
        });

        if (result.isConfirmed) {
            event.target.submit();
        }
    } finally {
        limpiarSwalUI();
    }
}


async function eliminarRondaConfirmar(event, ronda, tieneResultadosMesas) {
    const form = event.target;
    const inputConfirmar = form.querySelector('input[name="confirmar_eliminar_con_resultados"]');
    if (inputConfirmar) inputConfirmar.value = '';

    if (typeof Swal === 'undefined') {
        try {
            if (tieneResultadosMesas) {
                var texto = prompt('La ronda ' + ronda + ' tiene resultados registrados. Para eliminar de todas formas escriba exactamente: ELIMINAR');
                if (texto === 'ELIMINAR' && inputConfirmar) {
                    inputConfirmar.value = 'ELIMINAR';
                    form.submit();
                }
            } else {
                if (confirm('¿Eliminar la ronda ' + ronda + '? Se eliminarán las asignaciones de mesas de esta ronda.')) {
                    form.submit();
                }
            }
        } finally {
            limpiarSwalUI();
        }
        return;
    }

    try {
        if (tieneResultadosMesas) {
            const { value: texto } = await Swal.fire({
                title: 'Confirmación estricta',
                html: '<p class="text-left">La ronda <strong>' + ronda + '</strong> tiene <strong>resultados de mesas registrados</strong>.</p>' +
                      '<p class="text-left text-gray-600">Eliminar borrará todos los resultados y asignaciones de esta ronda. Esta acción no se puede deshacer.</p>' +
                      '<p class="text-left mt-3 font-semibold">Para continuar, escriba exactamente: <code class="bg-gray-200 px-1">ELIMINAR</code></p>',
                icon: 'warning',
                input: 'text',
                inputPlaceholder: 'Escriba ELIMINAR',
                inputValidator: (value) => {
                    if (value !== 'ELIMINAR') return 'Debe escribir exactamente: ELIMINAR';
                },
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar la ronda',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280'
            });
            if (texto === 'ELIMINAR' && inputConfirmar) {
                inputConfirmar.value = 'ELIMINAR';
                form.submit();
            }
            return;
        }

        const result = await Swal.fire({
            title: '¿Eliminar ronda?',
            html: '¿Está seguro de eliminar la ronda <strong>' + ronda + '</strong>?<br><small class="text-gray-500">Se eliminarán las asignaciones de mesas de esta ronda.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280'
        });
        if (result.isConfirmed) {
            form.submit();
        }
    } finally {
        limpiarSwalUI();
    }
}

async function confirmarCierreTorneo(event) {
    await Swal.fire({
        title: '<i class="fas fa-lock text-gray-700"></i> Finalizar torneo',
        html: `
            <div class="text-left text-sm">
                <div class="bg-red-50 border-l-4 border-red-500 p-3 mb-3">
                    <p class="text-red-700 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i> Acción irreversible</p>
                </div>
                <p class="mb-2">Esta acción <strong>finalizará definitivamente</strong> el torneo. A partir de ese momento <strong>no será posible modificar datos</strong>; solo consulta:</p>
                <ul class="list-disc pl-5 mb-3 text-gray-600">
                    <li>Inscripciones</li>
                    <li>Resultados</li>
                    <li>Rondas</li>
                    <li>Reasignaciones</li>
                </ul>
                <div class="bg-amber-50 border-l-4 border-amber-500 p-3">
                    <p class="text-amber-700"><i class="fas fa-info-circle mr-1"></i> Ya han pasado 20 minutos desde el último resultado; puede finalizar para evitar manipulaciones.</p>
                </div>
            </div>
        `,
        icon: null,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-lock mr-1"></i> Sí, finalizar torneo',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#111827',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'rounded-xl'
        }
    }).then((res) => {
        if (res.isConfirmed) {
            event.target.submit();
        }
    });
}

// --- Importación masiva ---
(function() {
    const CAMPOS = ['nacionalidad','cedula','nombre','sexo','fecha_nac','telefono','email','club','organizacion'];
    const CAMPOS_LABEL = { organizacion: 'Organización', club: 'Club (id en tabla clubes o nombre)' };
    const COLORS = { omitir: '#3b82f6', inscribir: '#eab308', crear_inscribir: '#22c55e', error: '#ef4444' };
    const CAMPO_ALIASES = {
        nombre: ['nombre', 'nombres y apellidos', 'nombres', 'nombres y apellido'],
        cedula: ['cedula', 'cédula', 'cedula de identidad'],
        sexo: ['sexo', 'genero', 'género'],
        organizacion: ['organizacion', 'organización', 'entidad', 'asociacion', 'asociación'],
        club: ['club', 'id_club', 'id club', 'club_id', 'club id', 'club_nombre', 'club nombre', 'codigo_club', 'club_codigo', 'cod_club', 'codigo club']
    };
    let importMasivaHeaders = [];
    let importMasivaRows = [];
    let importMasivaValidacion = [];

    function detectEncodingAndDecode(buffer) {
        var bytes = new Uint8Array(buffer);
        var utf8 = new TextDecoder('utf-8').decode(bytes);
        var mojibakePattern = /Ã[ª©®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏ]/;
        if (mojibakePattern.test(utf8) || (utf8.indexOf('Ã') !== -1 && utf8.indexOf('©') !== -1)) {
            try {
                return new TextDecoder('windows-1252').decode(bytes);
            } catch (e) {
                try {
                    return new TextDecoder('iso-8859-1').decode(bytes);
                } catch (e2) {
                    return utf8;
                }
            }
        }
        return utf8;
    }

    function parseCSV(text) {
        const lines = [];
        let cur = '', inQuotes = false;
        for (let i = 0; i < text.length; i++) {
            const c = text[i];
            if (c === '"') { inQuotes = !inQuotes; continue; }
            if (!inQuotes && (c === '\n' || c === '\r')) {
                if (c === '\r' && text[i+1] === '\n') i++;
                if (cur.trim()) lines.push(cur);
                cur = '';
                continue;
            }
            cur += c;
        }
        if (cur.trim()) lines.push(cur);
        return lines.map(function(line) {
            const out = [];
            let cell = '';
            inQuotes = false;
            for (let j = 0; j < line.length; j++) {
                const c = line[j];
                if (c === '"') { inQuotes = !inQuotes; continue; }
                if (!inQuotes && (c === ',' || c === ';')) { out.push(cell.trim()); cell = ''; continue; }
                cell += c;
            }
            out.push(cell.trim());
            return out;
        });
    }

    function getTorneoId() {
        const m = window.location.href.match(/torneo_id=(\d+)/);
        return m ? m[1] : (document.querySelector('input[name="torneo_id"]') && document.querySelector('input[name="torneo_id"]').value) || '';
    }

    function getCsrfToken() {
        var imp = document.getElementById('csrf_token_import_masiva');
        if (imp && imp.value) {
            return imp.value;
        }
        var first = document.querySelector('input[name="csrf_token"]');
        return first && first.value ? first.value : '';
    }

    /**
     * URL a archivos bajo public/ (api, assets).
     * layout.php define APP_BASE_URL sin el segmento /public; hay que añadirlo o las peticiones van a …/api/… en lugar de …/public/api/… (sesión/cookies en otra ruta).
     */
    function apiPublicUrl(path) {
        var p = (path || '').replace(/^\//, '');
        var base = (typeof window.APP_BASE_URL === 'string' && window.APP_BASE_URL) ? window.APP_BASE_URL.replace(/\/$/, '') : '';
        if (!base) {
            return p;
        }
        var pubBase = base;
        if (!/\/public$/i.test(pubBase)) {
            pubBase = pubBase + '/public';
        }
        return pubBase + '/' + p;
    }

    function fetchJsonResponse(r) {
        return r.text().then(function(text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                var plain = (text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                throw new Error('HTTP ' + r.status + (plain ? ': ' + plain.slice(0, 220) : '') + ' (respuesta no JSON; revise sesión o tamaño del archivo).');
            }
        });
    }

    function fetchJsonImportMasivaOrThrow(r) {
        return fetchJsonResponse(r).then(function(data) {
            if (!r.ok || (data && data.success === false)) {
                throw new Error((data && data.error) ? String(data.error) : ('Error del servidor (HTTP ' + r.status + ')'));
            }
            return data;
        });
    }

    function hideImportMasivaLoading() {
        var el = document.getElementById('importMasivaLoading');
        if (el) { el.classList.add('d-none'); }
    }

    document.getElementById('importMasivaFile').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        document.getElementById('importMasivaLoading').classList.remove('d-none');
        if (ext === 'xls' || ext === 'xlsx' || ext === 'xlsm' || ext === 'csv') {
            var fd = new FormData();
            fd.append('archivo', file);
            fd.append('csrf_token', getCsrfToken());
            fetch(apiPublicUrl('api/tournament_import_parse.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(fetchJsonImportMasivaOrThrow)
                .then(function(data) {
                    importMasivaHeaders = data.headers || [];
                    importMasivaRows = data.rows || [];
                    if (importMasivaHeaders.length === 0 || importMasivaRows.length === 0) {
                        alert('El archivo debe tener cabecera y al menos una fila de datos.');
                        return;
                    }
                    applyParsedData();
                })
                .catch(function(err) {
                    alert(err && err.message ? err.message : 'Error de conexión al procesar el archivo.');
                })
                .finally(hideImportMasivaLoading);
        } else {
            var reader = new FileReader();
            reader.onerror = function() {
                hideImportMasivaLoading();
                alert('No se pudo leer el archivo.');
            };
            reader.onload = function(ev) {
                try {
                    var buffer = ev.target.result;
                    var text = detectEncodingAndDecode(buffer);
                    text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    var parsed = parseCSV(text);
                    if (parsed.length < 2) { alert('El archivo debe tener al menos cabecera y una fila.'); return; }
                    importMasivaHeaders = parsed[0];
                    importMasivaRows = parsed.slice(1);
                    applyParsedData();
                } finally {
                    hideImportMasivaLoading();
                }
            };
            reader.readAsArrayBuffer(file);
        }
    });

    function applyParsedData() {
        const row = document.getElementById('importMasivaMappingRow');
        row.innerHTML = '';
        CAMPOS.forEach(function(campo) {
            const div = document.createElement('div');
            div.className = 'col-6 col-md-4 col-lg-3';
            var label = (CAMPOS_LABEL[campo] || campo);
            var opts = importMasivaHeaders.map(function(h, i) {
                var head = (String(h || 'Col ' + (i+1))).trim().toLowerCase();
                var aliases = CAMPO_ALIASES[campo];
                var selected = aliases && aliases.indexOf(head) !== -1 ? ' selected' : '';
                if (!selected && campo === 'organizacion' && (head === 'entidad' || head === 'organizacion' || head === 'organización' || head === 'asociacion' || head === 'asociación')) selected = ' selected';
                return '<option value="' + i + '"' + selected + '>' + (h || 'Col ' + (i+1)) + '</option>';
            }).join('');
            div.innerHTML = '<label class="form-label small mb-0">' + label + '</label><select class="form-select form-select-sm map-select" data-campo="' + campo + '"><option value="">-- No usar --</option>' + opts + '</select>';
            row.appendChild(div);
        });
        document.getElementById('importMasivaMapping').classList.remove('d-none');
        document.getElementById('importMasivaPreviewWrap').classList.remove('d-none');
        document.getElementById('importMasivaPreviewCount').textContent = importMasivaRows.length;
        buildPreviewTable();
    }

    function buildPreviewTable() {
        const map = {};
        document.querySelectorAll('.map-select').forEach(function(s) {
            const v = s.value;
            if (v !== '') map[s.dataset.campo] = parseInt(v, 10);
        });
        const thead = ['#'].concat(CAMPOS.map(function(c) { return CAMPOS_LABEL[c] || c; }));
        const tbody = importMasivaRows.map(function(r, i) {
            const row = [(i+1)];
            CAMPOS.forEach(function(c) { row.push(map[c] !== undefined ? (r[map[c]] || '') : ''); });
            return row;
        });
        const table = document.getElementById('importMasivaPreviewTable');
        table.innerHTML = '<thead class="table-light"><tr>' + thead.map(function(h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody id="importMasivaTbody"></tbody>';
        const tbodyEl = document.getElementById('importMasivaTbody');
        tbody.forEach(function(row, i) {
            const tr = document.createElement('tr');
            tr.dataset.index = i;
            tr.innerHTML = row.map(function(cell) { return '<td>' + (cell !== undefined && cell !== null ? String(cell) : '') + '</td>'; }).join('');
            tbodyEl.appendChild(tr);
        });
        importMasivaValidacion = [];
    }

    document.querySelector('#importMasivaMappingRow') && document.querySelector('#importMasivaMappingRow').addEventListener('change', function() {
        if (importMasivaRows.length) buildPreviewTable();
    });

    function getFilasMapeadas() {
        const map = {};
        document.querySelectorAll('.map-select').forEach(function(s) {
            const v = s.value;
            if (v !== '') map[s.dataset.campo] = parseInt(v, 10);
        });
        return importMasivaRows.map(function(r) {
            const obj = {};
            CAMPOS.forEach(function(c) {
                if (map[c] !== undefined) {
                    var val = r[map[c]];
                    val = val != null ? String(val).trim() : '';
                    obj[c] = val;
                }
            });
            if (obj.nacionalidad === undefined || obj.nacionalidad === '') {
                obj.nacionalidad = 'V';
            }
            return obj;
        });
    }

    document.getElementById('btnImportMasivaValidar').addEventListener('click', function() {
        const filas = getFilasMapeadas();
        if (!filas.length) { alert('No hay filas para validar.'); return; }
        const fd = new FormData();
        fd.append('action', 'validar');
        fd.append('torneo_id', getTorneoId());
        fd.append('filas', JSON.stringify(filas));
        fd.append('csrf_token', getCsrfToken());
        document.getElementById('importMasivaLoading').classList.remove('d-none');
        fetch(apiPublicUrl('api/tournament_import_masivo.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(fetchJsonImportMasivaOrThrow)
            .then(function(data) {
                importMasivaValidacion = data.validacion || [];
                const tbody = document.getElementById('importMasivaTbody');
                if (tbody) {
                    [].forEach.call(tbody.querySelectorAll('tr'), function(tr, i) {
                        const v = importMasivaValidacion[i];
                        tr.style.backgroundColor = v && COLORS[v.estado] ? COLORS[v.estado] : '';
                        tr.style.color = v && v.estado === 'error' ? '#fff' : (v && COLORS[v.estado] ? '#fff' : '');
                        tr.title = v ? v.mensaje : '';
                    });
                }
            })
            .catch(function(err) {
                alert(err && err.message ? err.message : 'Error de conexión');
            })
            .finally(hideImportMasivaLoading);
    });

    document.getElementById('btnImportMasivaProcesar').addEventListener('click', function() {
        const filas = getFilasMapeadas();
        if (!filas.length) { alert('No hay filas para procesar.'); return; }
        const fd = new FormData();
        fd.append('action', 'importar');
        fd.append('torneo_id', getTorneoId());
        fd.append('filas', JSON.stringify(filas));
        fd.append('csrf_token', getCsrfToken());
        document.getElementById('importMasivaLoading').classList.remove('d-none');
        fetch(apiPublicUrl('api/tournament_import_masivo.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(fetchJsonImportMasivaOrThrow)
            .then(function(data) {
                const tieneErrores = data.errores && data.errores.length > 0;
                const html = '<p>Procesados: <strong>' + (data.procesados || 0) + '</strong></p><p>Nuevos (creados e inscritos): <strong>' + (data.nuevos || 0) + '</strong></p><p>Omitidos (ya inscritos): <strong>' + (data.omitidos || 0) + '</strong></p><p>Usuarios actualizados (nombre/sexo): <strong>' + (data.usuarios_actualizados || 0) + '</strong></p>' +
                    (tieneErrores ? '<p class="text-danger">Errores: ' + data.errores.length + '</p>' : '');
                const opts = {
                    title: 'Importación finalizada',
                    html: html,
                    icon: tieneErrores ? 'warning' : 'success',
                    confirmButtonText: 'Aceptar'
                };
                if (tieneErrores && data.archivo_errores_base64) {
                    opts.showDenyButton = true;
                    opts.denyButtonText = 'Descargar Log de Errores';
                    opts.denyButtonColor = '#6b7280';
                }
                Swal.fire(opts).then(function(res) {
                    if (res.isDenied && data.archivo_errores_base64) {
                        var bin = atob(data.archivo_errores_base64);
                        var blob = new Blob([bin], { type: 'text/plain;charset=utf-8' });
                        const a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = 'log_errores_importacion_' + (new Date().toISOString().slice(0,10)) + '.txt';
                        a.click();
                        URL.revokeObjectURL(a.href);
                    }
                    if (data.success && (data.procesados > 0 || data.omitidos > 0)) window.location.reload();
                });
            })
            .catch(function(err) {
                alert(err && err.message ? err.message : 'Error de conexión');
            })
            .finally(hideImportMasivaLoading);
    });

    if (window.location.hash === '#importacion-masiva') {
        var btnImp = document.getElementById('btnAbrirImportacionMasiva');
        if (btnImp) {
            setTimeout(function() { btnImp.click(); }, 300);
        }
    }
})();
</script>
