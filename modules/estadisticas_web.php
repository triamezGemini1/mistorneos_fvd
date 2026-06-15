<?php
/**
 * Estadísticas web (Umami) — Solo Admin General.
 * Desglose de visitas públicas y del panel administrativo.
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/UmamiAnalyticsHelper.php';
require_once __DIR__ . '/../lib/WebStatsService.php';
require_once __DIR__ . '/../lib/FvdAppPage.php';

Auth::requireRole(['admin_general']);

$periodKey = trim((string) ($_GET['period'] ?? '7d'));
$mesKey = trim((string) ($_GET['mes'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $mesKey)) {
    $mesKey = date('Y-m');
}
$period = UmamiAnalyticsHelper::resolvePeriod($periodKey);
$startMs = $period['startMs'];
$endMs = $period['endMs'];

$hasApi = UmamiAnalyticsHelper::apiKey() !== '';
$shareUrl = UmamiAnalyticsHelper::shareUrl();
$umamiDiag = UmamiAnalyticsHelper::configDiagnostics();
$iframeSrc = '';
if ($shareUrl !== '') {
    $iframeSrc = $shareUrl;
    if (stripos($iframeSrc, 'embed=true') === false) {
        $iframeSrc .= (str_contains($iframeSrc, '?') ? '&' : '?') . 'embed=true';
    }
}
$dashboardUrl = UmamiAnalyticsHelper::dashboardUrl();

$stats = $hasApi ? UmamiAnalyticsHelper::fetchStats($startMs, $endMs) : null;
$pageviewsSeries = $hasApi ? UmamiAnalyticsHelper::fetchPageviews($startMs, $endMs) : null;

$metricSections = [
    'url' => ['title' => 'Páginas más visitadas', 'icon' => 'fas fa-link'],
    'referrer' => ['title' => 'Origen del tráfico (referrers)', 'icon' => 'fas fa-external-link-alt'],
    'country' => ['title' => 'Países', 'icon' => 'fas fa-globe-americas'],
    'device' => ['title' => 'Dispositivos', 'icon' => 'fas fa-mobile-alt'],
    'browser' => ['title' => 'Navegadores', 'icon' => 'fas fa-window-maximize'],
    'os' => ['title' => 'Sistemas operativos', 'icon' => 'fas fa-desktop'],
];

$metricsData = [];
if ($hasApi) {
    foreach (array_keys($metricSections) as $type) {
        $metricsData[$type] = UmamiAnalyticsHelper::fetchMetrics($type, $startMs, $endMs, 15) ?? [];
    }
}

$localStatsReady = false;
$urlBreakdown = [];
$availableMonths = [date('Y-m')];
try {
    $statsPdo = WebStatsService::pdo();
    $localStatsReady = WebStatsService::tablesReady($statsPdo);
    if ($localStatsReady) {
        $availableMonths = WebStatsService::listAvailableMonths($statsPdo);
        $urlBreakdown = WebStatsService::fetchUrlBreakdown($statsPdo, $mesKey);
    }
} catch (Throwable $e) {
    $localStatsReady = false;
}

$periodLinks = static function (string $key) use ($periodKey, $mesKey): string {
    return htmlspecialchars(AppHelpers::dashboard('estadisticas_web', ['period' => $key, 'mes' => $mesKey]), ENT_QUOTES, 'UTF-8');
};

$mesLinks = static function (string $mes) use ($periodKey): string {
    return htmlspecialchars(AppHelpers::dashboard('estadisticas_web', ['period' => $periodKey, 'mes' => $mes]), ENT_QUOTES, 'UTF-8');
};

$renderMetricTable = static function (array $rows): string {
    if ($rows === []) {
        return '<p class="text-muted small mb-0">Sin datos en el periodo seleccionado.</p>';
    }
    $html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
    $html .= '<thead><tr><th>Elemento</th><th class="text-end" style="width:7rem">Visitas</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $label = trim((string) ($row['x'] ?? ''));
        if ($label === '') {
            $label = '(directo / sin dato)';
        }
        $count = (int) ($row['y'] ?? 0);
        $html .= '<tr><td class="text-break">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td class="text-end fw-semibold">' . htmlspecialchars(UmamiAnalyticsHelper::formatNumber($count), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
};

$renderUrlBreakdownTable = static function (array $rows): string {
    if ($rows === []) {
        return '<p class="text-muted small mb-0">Sin datos locales para el mes seleccionado. El cron diario aún no ha sincronizado información.</p>';
    }
    $html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
    $html .= '<thead><tr>';
    $html .= '<th>URL / Ruta</th><th class="text-center" style="width:6rem">Torneo ID</th>';
    $html .= '<th class="text-end" style="width:7rem">Vistas</th><th class="text-end" style="width:7rem">Visitantes</th>';
    $html .= '<th class="text-end" style="width:7rem">Tiempo prom.</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $ruta = trim((string) ($row['ruta'] ?? ''));
        $torneoId = $row['torneo_id'] ?? null;
        $vistas = (int) ($row['total_vistas'] ?? 0);
        $visitantes = (int) ($row['total_visitantes'] ?? 0);
        $tiempo = (int) ($row['tiempo_medio_seg'] ?? 0);
        $html .= '<tr>';
        $html .= '<td class="text-break"><code class="small">' . htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8') . '</code></td>';
        $html .= '<td class="text-center">' . ($torneoId !== null && (int) $torneoId > 0
            ? htmlspecialchars((string) (int) $torneoId, ENT_QUOTES, 'UTF-8')
            : '<span class="text-muted">NULL</span>') . '</td>';
        $html .= '<td class="text-end fw-semibold">' . htmlspecialchars(UmamiAnalyticsHelper::formatNumber($vistas), ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td class="text-end">' . htmlspecialchars(UmamiAnalyticsHelper::formatNumber($visitantes), ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td class="text-end">' . htmlspecialchars(WebStatsService::formatDuration($tiempo), ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
};

$page_title = 'Estadísticas web';
?>
<?= FvdAppPage::openShell('page-estadisticas-web') ?>
<?= FvdAppPage::renderBreadcrumb([
    ['label' => 'Inicio', 'href' => AppHelpers::dashboard('home')],
    ['label' => 'Estadísticas web', 'active' => true],
]) ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><i class="fas fa-chart-line text-primary me-2"></i>Estadísticas web</h1>
        <p class="text-muted small mb-0">
            Impacto del portal público y del panel administrativo (Umami). Periodo: <?= htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8') ?>.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener noreferrer">
            <i class="fas fa-external-link-alt me-1"></i>Dashboard Umami
        </a>
    </div>
</div>

<div class="btn-group btn-group-sm mb-3" role="group" aria-label="Periodo">
    <?php foreach (['24h' => '24 h', '7d' => '7 días', '30d' => '30 días', '90d' => '90 días'] as $key => $label): ?>
    <a href="<?= $periodLinks($key) ?>" class="btn btn-<?= $periodKey === $key ? 'primary' : 'outline-secondary' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<?php if ($localStatsReady): ?>
<?= FvdAppPage::cardOpen('Desglose por URL (base local)', 'fas fa-sitemap') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-muted small mb-0">
        <?php if ($mesKey === date('Y-m')): ?>
            Mes en curso: suma en tiempo real desde <code>stats_detalle_diario</code>.
        <?php else: ?>
            Histórico de <?= htmlspecialchars(WebStatsService::formatMonthLabel($mesKey), ENT_QUOTES, 'UTF-8') ?> desde <code>stats_historico_mensual_url</code>.
        <?php endif; ?>
    </p>
    <div class="btn-group btn-group-sm" role="group" aria-label="Mes">
        <?php foreach ($availableMonths as $mesOption): ?>
        <a href="<?= $mesLinks($mesOption) ?>" class="btn btn-<?= $mesKey === $mesOption ? 'primary' : 'outline-secondary' ?>">
            <?= htmlspecialchars(WebStatsService::formatMonthLabel($mesOption), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?= $renderUrlBreakdownTable($urlBreakdown) ?>
<?= FvdAppPage::cardClose() ?>
<?php else: ?>
<div class="alert alert-secondary small mb-3">
    <i class="fas fa-database me-2"></i>
    Desglose local por URL disponible tras crear las tablas <code>stats_detalle_diario</code> y
    <code>stats_historico_mensual_url</code> en <code>mistorneos_fvd</code> (<code>sql/create_stats_web_analytics_tables.sql</code>)
    y programar el cron <code>public/modules/cron_analytics.php</code>.
</div>
<?php endif; ?>

<?php if (!$hasApi && $shareUrl === ''): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    Para ver el desglose completo dentro del panel, agrega en el <code>.env</code> de la raíz del proyecto
    (<code><?= htmlspecialchars(basename(dirname(__DIR__)) . '/.env', ENT_QUOTES, 'UTF-8') ?></code>, no <code>config/env.production.php</code>) la clave
    <code>UMAMI_API_KEY</code> (Umami Cloud → Perfil → Settings → API keys).
    Opcionalmente define <code>UMAMI_SHARE_URL</code> o usa <code>UMAMI_SCRIPT_URL</code> con la ruta
    <code>/analytics/…/websites/…</code> del dashboard Umami (el tracker seguirá usando <code>script.js</code> por defecto).
    Mientras tanto, usa el botón <strong>Dashboard Umami</strong>.
    <?php if (!$umamiDiag['env_file']): ?>
    <br><strong>Archivo .env no encontrado</strong> en: <code><?= htmlspecialchars($umamiDiag['env_path'], ENT_QUOTES, 'UTF-8') ?></code>
    <?php elseif ($umamiDiag['env_file'] && !$umamiDiag['share_url']): ?>
    <br>Si ya agregaste la URL, verifica <code>UMAMI_SHARE_URL</code> o <code>UMAMI_SCRIPT_URL</code> con ruta
    <code>/analytics/…/websites/…</code> (sin coma al final de la línea).
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($hasApi && !is_array($stats)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    No se pudieron obtener datos de Umami. Verifica que <code>UMAMI_API_KEY</code> sea válida y que el website ID coincida.
</div>
<?php endif; ?>

<?php if (is_array($stats)): ?>
<div class="row g-2 mb-3">
    <?php
    $kpis = [
        ['key' => 'visitors', 'label' => 'Visitantes únicos', 'tone' => 'blue'],
        ['key' => 'visits', 'label' => 'Visitas', 'tone' => 'indigo'],
        ['key' => 'pageviews', 'label' => 'Páginas vistas', 'tone' => 'teal'],
        ['key' => 'bounces', 'label' => 'Rebotes', 'tone' => 'amber'],
    ];
    foreach ($kpis as $kpi):
        $block = $stats[$kpi['key']] ?? [];
        $value = UmamiAnalyticsHelper::metricValue(is_array($block) ? $block : []);
        $change = UmamiAnalyticsHelper::metricChange(is_array($block) ? $block : []);
        $sub = $change === null ? '' : (($change >= 0 ? '+' : '') . UmamiAnalyticsHelper::formatNumber($change) . ' vs periodo anterior');
    ?>
    <div class="col-6 col-lg-3">
        <?= FvdAppPage::kpi(UmamiAnalyticsHelper::formatNumber($value), $kpi['label'], $kpi['tone'], $sub) ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (is_array($pageviewsSeries) && !empty($pageviewsSeries['pageviews']) && is_array($pageviewsSeries['pageviews'])): ?>
<?= FvdAppPage::cardOpen('Tendencia de páginas vistas', 'fas fa-chart-area') ?>
<div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr><th>Fecha</th><th class="text-end">Páginas vistas</th><th class="text-end">Sesiones</th></tr></thead>
        <tbody>
        <?php
        $sessions = [];
        if (!empty($pageviewsSeries['sessions']) && is_array($pageviewsSeries['sessions'])) {
            foreach ($pageviewsSeries['sessions'] as $row) {
                $sessions[(string) ($row['x'] ?? '')] = (int) ($row['y'] ?? 0);
            }
        }
        foreach ($pageviewsSeries['pageviews'] as $row):
            $day = (string) ($row['x'] ?? '');
        ?>
            <tr>
                <td><?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-end fw-semibold"><?= htmlspecialchars(UmamiAnalyticsHelper::formatNumber((int) ($row['y'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-end"><?= htmlspecialchars(UmamiAnalyticsHelper::formatNumber($sessions[$day] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= FvdAppPage::cardClose() ?>
<?php endif; ?>

<?php if ($hasApi): ?>
<div class="row g-2 mb-3">
    <?php foreach ($metricSections as $type => $section): ?>
    <div class="col-lg-6">
        <?= FvdAppPage::cardOpen($section['title'], $section['icon']) ?>
        <?= $renderMetricTable($metricsData[$type] ?? []) ?>
        <?= FvdAppPage::cardClose() ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($iframeSrc !== ''): ?>
<?= FvdAppPage::cardOpen('Dashboard Umami', 'fas fa-chart-pie') ?>
<div class="border rounded overflow-hidden bg-white" style="min-height: 70vh; height: calc(100vh - 220px);">
    <iframe
        src="<?= htmlspecialchars($iframeSrc, ENT_QUOTES, 'UTF-8') ?>"
        title="Umami Analytics Dashboard"
        width="100%"
        height="100%"
        style="border: 0; display: block; min-height: 70vh;"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        allowfullscreen
    ></iframe>
</div>
<?= FvdAppPage::cardClose() ?>
<?php elseif ($iframeSrc === ''): ?>
<div class="alert alert-light border text-center py-4 mb-0">
    <div class="fs-2 mb-2">📊</div>
    <h4 class="h6 text-secondary mb-2">Visualización interna pendiente</h4>
    <p class="text-muted small mb-0 mx-auto" style="max-width: 32rem;">
        Agrega <code>UMAMI_SHARE_URL</code> en el <code>.env</code> de la raíz del proyecto para incrustar el dashboard de Umami,
        o <code>UMAMI_API_KEY</code> para ver KPIs y tablas vía API.
    </p>
</div>
<?php endif; ?>

<?= FvdAppPage::closeShell() ?>
