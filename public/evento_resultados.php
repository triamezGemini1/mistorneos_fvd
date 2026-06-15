<?php
/**
 * Página pública de Resultados de Evento.
 * Patrón en bloque: db_config (conexión única). Sin requireAuth (acceso público a resultados).
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/UrlHelper.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';
require_once __DIR__ . '/../lib/ResultadosPublicHelper.php';
require_once __DIR__ . '/../lib/ResultadosPublicCache.php';
require_once __DIR__ . '/../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../lib/LandingDataService.php';

$pdo = DB::pdo();
$base_url = app_base_url();
$has_cod_org = false;
try {
    $has_cod_org = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$vista = $_GET['vista'] ?? 'general';
$pagina = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$genero_get = isset($_GET['genero']) ? (string) $_GET['genero'] : null;
$per_page = 50;

if ($torneo_id <= 0) {
    header('Location: resultados.php');
    exit;
}

$torneo_data = null;
try {
    $stmt = $pdo->prepare("
        SELECT t.*, o.nombre as organizacion_nombre,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus IS NULL OR (estatus != 4 AND estatus != 'retirado'))) as total_inscritos,
            (SELECT COUNT(*) FROM club_photos WHERE torneo_id = t.id) as total_fotos
        FROM tournaments t
        {$org_join}
        WHERE t.id = ?
    ");
    $stmt->execute([$torneo_id]);
    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("evento_resultados: " . $e->getMessage());
}

if (!$torneo_data || !TournamentScopeHelper::canAccessResultsPublicly($torneo_data)) {
    header('Location: resultados.php');
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
}

$modalidad = (int)($torneo_data['modalidad'] ?? 1);
$es_equipos = ($modalidad === 3);
$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
$genero_evt = ResultadosReporteData::generoFiltroDesdeParametro($genero_get);
$gen_q = 'genero=' . urlencode($genero_evt);

$cacheKey = ResultadosPublicCache::buildKey($torneo_id, $vista, $genero_get, $pagina);
$cachedView = ResultadosPublicCache::get($cacheKey);

if (is_array($cachedView)) {
    $rounds_info = $cachedView['rounds_info'] ?? [];
    $podio_acta = $cachedView['podio_acta'] ?? [];
    $posiciones = $cachedView['posiciones'] ?? [];
    $total_posiciones = (int) ($cachedView['total_posiciones'] ?? 0);
    $clubes_data = $cachedView['clubes_data'] ?? [];
    $equipos_resumido = $cachedView['equipos_resumido'] ?? [];
    $equipos_detallado = $cachedView['equipos_detallado'] ?? [];
    $total_pages = (int) ($cachedView['total_pages'] ?? 1);
} else {
    $rounds_info = ResultadosPublicHelper::getRoundsInfo($pdo, $torneo_id, $torneo_data);
    $podio_acta = [];
    $posiciones = [];
    $total_posiciones = 0;
    $clubes_data = [];
    $equipos_resumido = [];
    $equipos_detallado = [];

    if ($vista === 'general') {
        $total_posiciones = ResultadosPublicHelper::getPosicionesCount($pdo, $torneo_id, $genero_get, $torneo_data);
        $offset = ($pagina - 1) * $per_page;
        $posiciones = ResultadosPublicHelper::getPosiciones($pdo, $torneo_id, $per_page, $offset, $genero_get, $torneo_data);
        $landingService = new LandingDataService($pdo);
        $podio_acta = $landingService->getPodioPorTorneo($torneo_id);
    }

    $pareclub = (int) ($torneo_data['pareclub'] ?? 0);
    $limite_club = ($pareclub > 0) ? $pareclub : 8;
    if ($vista === 'club' || $vista === 'club_resumido' || $vista === 'club_detallado') {
        $clubes_data = ResultadosPublicHelper::getResultadosPorClub($pdo, $torneo_id, $limite_club);
    }

    if ($es_equipos) {
        if ($vista === 'equipos_resumido') {
            $equipos_resumido = ResultadosPublicHelper::getResultadosEquiposResumido($pdo, $torneo_id, 100, 0);
        }
        if ($vista === 'equipos_detallado') {
            $equipos_detallado = ResultadosPublicHelper::getResultadosEquiposDetallado($pdo, $torneo_id, 50, 0);
        }
    }

    $total_pages = $vista === 'general' ? max(1, (int) ceil($total_posiciones / $per_page)) : 1;

    ResultadosPublicCache::set($cacheKey, [
        'rounds_info' => $rounds_info,
        'podio_acta' => $podio_acta,
        'posiciones' => $posiciones,
        'total_posiciones' => $total_posiciones,
        'clubes_data' => $clubes_data,
        'equipos_resumido' => $equipos_resumido,
        'equipos_detallado' => $equipos_detallado,
        'total_pages' => $total_pages,
    ]);
}

$pareclub = (int) ($torneo_data['pareclub'] ?? 0);
$limite_club = ($pareclub > 0) ? $pareclub : 8;
$url_base = 'evento_resultados.php?torneo_id=' . $torneo_id;

$vendorBootstrap = dirname(__DIR__) . '/public/assets/vendor/bootstrap/css/bootstrap.min.css';
$vendorFa = dirname(__DIR__) . '/public/assets/vendor/fontawesome/css/all.min.css';
$bootstrapCss = is_file($vendorBootstrap)
    ? AppHelpers::publicAssetUrl('vendor/bootstrap/css/bootstrap.min.css')
    : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
$faCss = is_file($vendorFa)
    ? AppHelpers::publicAssetUrl('vendor/fontawesome/css/all.min.css')
    : 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title>Resultados <?= htmlspecialchars($torneo_data['nombre']) ?> - La Estación del Dominó</title>
    <meta name="description" content="Consulta resultados del torneo <?= htmlspecialchars($torneo_data['nombre']) ?>. Clasificación, resultados por club y equipos. <?= $rounds_info['ejecutadas'] ?> de <?= $rounds_info['total'] ?> rondas.">
    <link href="<?= htmlspecialchars($bootstrapCss) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars($faCss) ?>" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .er-page {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem clamp(0.75rem, 2.5vw, 1.5rem) 2rem;
        }
        .er-card {
            width: 100%;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            overflow: hidden;
        }
        .er-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e40af 100%);
            color: #fff;
            padding: 1.5rem 1.75rem;
            text-align: center;
        }
        .er-header-bar {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .er-header-bar .er-back { justify-self: start; }
        .er-header-bar .er-logo { justify-self: center; grid-column: 2; }
        @media (max-width: 640px) {
            .er-header-bar {
                grid-template-columns: 1fr;
                justify-items: center;
            }
            .er-header-bar .er-back { justify-self: center; grid-column: 1; }
            .er-header-bar .er-logo { grid-column: 1; }
        }
        .er-header h4, .er-header h5 { margin: 0.35rem 0 0; }
        .er-header h4 { font-size: 1.15rem; }
        .er-header h5 { font-size: 1rem; opacity: 0.92; font-weight: 600; }
        .er-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.35rem 1.25rem;
            font-size: 0.875rem;
            opacity: 0.95;
        }
        .er-rondas {
            margin-top: 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        .er-rondas-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.15);
        }
        .er-progress {
            width: min(400px, 100%);
            height: 10px;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        .er-progress > span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, #f59e0b, #10b981);
        }
        .er-btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.85rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            text-decoration: none;
            font-size: 0.875rem;
            white-space: nowrap;
        }
        .er-btn-back:hover { background: rgba(255, 255, 255, 0.25); color: #fff; }
        .er-tabs-wrap { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; }
        .er-tabs {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.5rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .er-tabs a {
            display: inline-block;
            padding: 0.55rem 1rem;
            border-radius: 10px;
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .er-tabs a:hover { background: #f1f5f9; color: #0f172a; }
        .er-tabs a.is-active {
            background: linear-gradient(135deg, #0f172a, #1e40af);
            color: #fff;
        }
        .er-content { padding: 1.25rem 1.5rem 1.5rem; width: 100%; }
        .er-title { text-align: center; margin: 0 0 1rem; font-size: 1.1rem; font-weight: 700; color: #0f172a; }
        .er-sub { text-align: center; color: #64748b; font-size: 0.875rem; margin: -0.5rem 0 1rem; }
        .er-gender {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
        }
        .er-gender a {
            padding: 0.45rem 1rem;
            border-radius: 8px;
            border: 1px solid #1e40af;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .er-gender a.is-on { background: #1e40af; color: #fff; }
        .er-gender a.is-off { background: #fff; color: #1e40af; }
        .er-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }
        .er-table {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .er-table th,
        .er-table td {
            padding: 0.65rem 0.55rem;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: middle;
        }
        .er-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 0.78rem;
            white-space: nowrap;
        }
        .er-table tbody tr:hover { background: #f8fafc; }
        .er-table .tc { text-align: center; }
        .er-table .pos-1 { background: linear-gradient(90deg, #fef3c7, #fde68a); }
        .er-table .pos-2 { background: linear-gradient(90deg, #f1f5f9, #e2e8f0); }
        .er-table .pos-3 { background: linear-gradient(90deg, #fed7aa, #fdba74); }
        .er-link { color: #2563eb; text-decoration: none; font-weight: 500; }
        .er-link:hover { color: #1d4ed8; text-decoration: underline; }
        .er-badge-ok {
            display: inline-block;
            min-width: 1.5rem;
            padding: 0.15rem 0.4rem;
            border-radius: 6px;
            background: #10b981;
            color: #fff;
            font-weight: 700;
            font-size: 0.78rem;
        }
        .er-badge-bad {
            display: inline-block;
            min-width: 1.5rem;
            padding: 0.15rem 0.4rem;
            border-radius: 6px;
            background: #ef4444;
            color: #fff;
            font-weight: 700;
            font-size: 0.78rem;
        }
        .er-pagination-wrap {
            margin-top: 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        .er-pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 0.35rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .er-pagination a,
        .er-pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.35rem;
            height: 2.35rem;
            padding: 0 0.55rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            color: #1e40af;
            background: #fff;
        }
        .er-pagination a:hover { background: #eff6ff; }
        .er-pagination .is-active span {
            background: #1e40af;
            color: #fff;
            border-color: #1e40af;
        }
        .er-pagination .is-disabled span {
            color: #94a3b8;
            background: #f8fafc;
            cursor: default;
        }
        .er-pagination-info { color: #64748b; font-size: 0.85rem; }
        .er-club-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        .er-club-card-head {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            font-weight: 600;
        }
        .er-footer {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.5rem;
        }
        .er-footer a {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #1e293b;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .er-footer a:hover { background: #eff6ff; border-color: #93c5fd; }
        .er-podio {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            text-align: center;
        }
        .er-empty { text-align: center; color: #64748b; padding: 1.5rem 0; }
    </style>
</head>
<body>
<div class="er-page">
    <div class="er-card">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="er-content" style="padding-bottom:0;">
            <div class="er-empty" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;">
                <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($_GET['msg']) ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="er-header">
            <div class="er-header-bar">
                <a href="resultados.php" class="er-btn-back er-back">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <div class="er-logo"><?= AppHelpers::appLogo('', 'La Estación del Dominó', 36) ?></div>
            </div>
            <h4><i class="fas fa-trophy me-2"></i>Resultados del evento</h4>
            <h5><?= htmlspecialchars($torneo_data['nombre']) ?></h5>
            <div class="er-meta">
                <span><i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></span>
                <?php if (!empty($torneo_data['lugar'])): ?>
                <span><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($torneo_data['lugar']) ?></span>
                <?php endif; ?>
                <span><i class="fas fa-building me-1"></i><?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'N/A') ?></span>
                <span><i class="fas fa-users me-1"></i><?= $modalidades[$modalidad] ?? 'N/A' ?></span>
            </div>
            <div class="er-rondas">
                <span class="er-rondas-badge">
                    <i class="fas fa-sync-alt"></i>
                    Rondas: <strong><?= $rounds_info['ejecutadas'] ?></strong> ejecutadas
                    <?php if ($rounds_info['total'] > 0): ?>
                        de <strong><?= $rounds_info['total'] ?></strong>
                        <?php if ($rounds_info['faltantes'] > 0): ?>
                            — Faltan <strong><?= $rounds_info['faltantes'] ?></strong>
                        <?php else: ?>
                            — <strong style="color:#86efac;">Completado</strong>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
                <?php if ($rounds_info['total'] > 0): ?>
                <div class="er-progress" role="progressbar" aria-valuenow="<?= (int) $rounds_info['ejecutadas'] ?>" aria-valuemin="0" aria-valuemax="<?= (int) $rounds_info['total'] ?>">
                    <span style="width: <?= $rounds_info['total'] > 0 ? min(100, ($rounds_info['ejecutadas'] / $rounds_info['total']) * 100) : 0 ?>%;"></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="er-tabs-wrap">
            <ul class="er-tabs">
                <li><a class="<?= $vista === 'general' ? 'is-active' : '' ?>" href="<?= $url_base ?>&vista=general&<?= $gen_q ?>"><i class="fas fa-list-ol me-1"></i>Clasificación general</a></li>
                <li><a class="<?= $vista === 'club_resumido' ? 'is-active' : '' ?>" href="<?= $url_base ?>&vista=club_resumido"><i class="fas fa-chart-bar me-1"></i>Por club (resumido)</a></li>
                <li><a class="<?= ($vista === 'club' || $vista === 'club_detallado') ? 'is-active' : '' ?>" href="<?= $url_base ?>&vista=club_detallado"><i class="fas fa-list-ul me-1"></i>Por club (detallado)</a></li>
                <?php if ($es_equipos): ?>
                <li><a class="<?= $vista === 'equipos_resumido' ? 'is-active' : '' ?>" href="<?= $url_base ?>&vista=equipos_resumido"><i class="fas fa-users me-1"></i>Equipos (resumido)</a></li>
                <li><a class="<?= $vista === 'equipos_detallado' ? 'is-active' : '' ?>" href="<?= $url_base ?>&vista=equipos_detallado"><i class="fas fa-list-ul me-1"></i>Equipos (detallado)</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="er-content">
            <?php if ($vista === 'general'): ?>
                <!-- Clasificación general -->
                <h2 class="er-title"><i class="fas fa-trophy" style="color:#f59e0b;"></i> Clasificación individual</h2>
                <div class="er-gender">
                    <a href="<?= $url_base ?>&vista=general&genero=M" class="<?= $genero_evt === 'M' ? 'is-on' : 'is-off' ?>">Masculino</a>
                    <a href="<?= $url_base ?>&vista=general&genero=F" class="<?= $genero_evt === 'F' ? 'is-on' : 'is-off' ?>">Femenino</a>
                </div>
                <?php if (empty($posiciones)): ?>
                    <p class="er-empty">Aún no hay resultados disponibles.</p>
                <?php else: ?>
                    <div class="er-table-wrap">
                        <table class="er-table">
                            <thead>
                                <tr>
                                    <th class="tc">Pos</th>
                                    <th class="tc">ID</th>
                                    <th>Jugador</th>
                                    <th>Club</th>
                                    <th class="tc">G</th>
                                    <th class="tc">P</th>
                                    <th class="tc">GFF</th>
                                    <th class="tc">Efect.</th>
                                    <th class="tc">Pts</th>
                                    <th class="tc">Pts.Rnk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $pos_display = ($pagina - 1) * $per_page + 1;
                                foreach ($posiciones as $p):
                                    $row_class = '';
                                    if ($pos_display == 1) {
                                        $row_class = 'pos-1';
                                    } elseif ($pos_display == 2) {
                                        $row_class = 'pos-2';
                                    } elseif ($pos_display == 3) {
                                        $row_class = 'pos-3';
                                    }
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td class="tc"><strong><?= $pos_display ?></strong>
                                        <?php if ($pos_display <= 3): ?>
                                            <span><?= ['🥇','🥈','🥉'][$pos_display - 1] ?? '' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tc"><code><?= htmlspecialchars((string)($p['id_usuario'] ?? '')) ?></code></td>
                                    <td><?= htmlspecialchars($p['nombre_completo'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($p['club_nombre'] ?? '—') ?></td>
                                    <td class="tc"><span class="er-badge-ok"><?= (int)($p['ganados'] ?? 0) ?></span></td>
                                    <td class="tc"><span class="er-badge-bad"><?= (int)($p['perdidos'] ?? 0) ?></span></td>
                                    <td class="tc"><?= (int)($p['ganadas_por_forfait'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($p['efectividad'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($p['puntos'] ?? 0) ?></td>
                                    <td class="tc"><strong><?= (int)($p['ptosrnk'] ?? 0) ?></strong></td>
                                </tr>
                                <?php $pos_display++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1):
                        $pageStart = max(1, $pagina - 2);
                        $pageEnd = min($total_pages, $pagina + 2);
                        if ($pageEnd - $pageStart < 4) {
                            $pageStart = max(1, $pageEnd - 4);
                            $pageEnd = min($total_pages, $pageStart + 4);
                        }
                    ?>
                    <nav class="er-pagination-wrap" aria-label="Paginación de clasificación">
                        <ul class="er-pagination">
                            <li class="<?= $pagina <= 1 ? 'is-disabled' : '' ?>">
                                <?php if ($pagina > 1): ?>
                                <a href="<?= $url_base ?>&vista=general&p=<?= $pagina - 1 ?>&<?= $gen_q ?>" aria-label="Anterior">&laquo;</a>
                                <?php else: ?>
                                <span>&laquo;</span>
                                <?php endif; ?>
                            </li>
                            <?php if ($pageStart > 1): ?>
                            <li><a href="<?= $url_base ?>&vista=general&p=1&<?= $gen_q ?>">1</a></li>
                            <?php if ($pageStart > 2): ?><li><span>…</span></li><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $pageStart; $i <= $pageEnd; $i++): ?>
                            <li class="<?= $i === $pagina ? 'is-active' : '' ?>">
                                <?php if ($i === $pagina): ?>
                                <span><?= $i ?></span>
                                <?php else: ?>
                                <a href="<?= $url_base ?>&vista=general&p=<?= $i ?>&<?= $gen_q ?>"><?= $i ?></a>
                                <?php endif; ?>
                            </li>
                            <?php endfor; ?>
                            <?php if ($pageEnd < $total_pages): ?>
                            <?php if ($pageEnd < $total_pages - 1): ?><li><span>…</span></li><?php endif; ?>
                            <li><a href="<?= $url_base ?>&vista=general&p=<?= $total_pages ?>&<?= $gen_q ?>"><?= $total_pages ?></a></li>
                            <?php endif; ?>
                            <li class="<?= $pagina >= $total_pages ? 'is-disabled' : '' ?>">
                                <?php if ($pagina < $total_pages): ?>
                                <a href="<?= $url_base ?>&vista=general&p=<?= $pagina + 1 ?>&<?= $gen_q ?>" aria-label="Siguiente">&raquo;</a>
                                <?php else: ?>
                                <span>&raquo;</span>
                                <?php endif; ?>
                            </li>
                        </ul>
                        <div class="er-pagination-info">Página <?= $pagina ?> de <?= $total_pages ?> · <?= (int) $total_posiciones ?> jugadores</div>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ($vista === 'club_resumido'): ?>
                <h2 class="er-title"><i class="fas fa-chart-bar" style="color:#1e40af;"></i> Resultados por club (resumido)</h2>
                <p class="er-sub">Se consideran los mejores <?= $limite_club ?> jugadores de cada club para las estadísticas.</p>
                <?php if (empty($clubes_data)): ?>
                    <p class="er-empty">No hay datos por club disponibles.</p>
                <?php else: ?>
                    <div class="er-table-wrap">
                        <table class="er-table">
                            <thead>
                                <tr>
                                    <th class="tc">Pos</th>
                                    <th>Club</th>
                                    <th class="tc">Jugadores</th>
                                    <th class="tc">Ganados</th>
                                    <th class="tc">Perdidos</th>
                                    <th class="tc">GFF</th>
                                    <th class="tc">Efect. prom.</th>
                                    <th class="tc">Puntos prom.</th>
                                    <th class="tc">Pts. Rnk total</th>
                                    <th class="tc">Mejor pos.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $pos_club = 1; foreach ($clubes_data as $club): ?>
                                <tr class="<?= $pos_club <= 3 ? ($pos_club == 1 ? 'pos-1' : ($pos_club == 2 ? 'pos-2' : 'pos-3')) : '' ?>">
                                    <td class="tc"><strong><?= $pos_club ?></strong><?php if ($pos_club <= 3): ?> <i class="fas fa-medal" style="color:#f59e0b;"></i><?php endif; ?></td>
                                    <td><strong><?= htmlspecialchars($club['club_nombre']) ?></strong></td>
                                    <td class="tc"><?= count($club['jugadores'] ?? []) ?></td>
                                    <td class="tc"><strong style="color:#10b981;"><?= (int)($club['total_ganados'] ?? 0) ?></strong></td>
                                    <td class="tc" style="color:#ef4444;"><?= (int)($club['total_perdidos'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($club['total_gff'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($club['promedio_efectividad'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($club['promedio_puntos'] ?? 0) ?></td>
                                    <td class="tc"><strong><?= (int)($club['total_ptosrnk'] ?? 0) ?></strong></td>
                                    <td class="tc"><?= (int)($club['mejor_posicion'] ?? 0) ?: '—' ?></td>
                                </tr>
                                <?php $pos_club++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($vista === 'club' || $vista === 'club_detallado'): ?>
                <h2 class="er-title"><i class="fas fa-list-ul" style="color:#1e40af;"></i> Resultados por club (detallado)</h2>
                <p class="er-sub">Se consideran los mejores <?= $limite_club ?> jugadores de cada club.</p>
                <?php if (empty($clubes_data)): ?>
                    <p class="er-empty">No hay datos por club disponibles.</p>
                <?php else: ?>
                    <?php $pos_club = 1; foreach ($clubes_data as $club): ?>
                    <div class="er-club-card">
                        <div class="er-club-card-head" style="display:flex;justify-content:space-between;align-items:center;">
                            <span>
                                <?= $pos_club ?>°
                                <?php if ($pos_club <= 3): ?><i class="fas fa-medal" style="color:#f59e0b;"></i><?php endif; ?>
                                <strong><?= htmlspecialchars($club['club_nombre']) ?></strong>
                            </span>
                            <span class="er-badge-ok"><?= (int)($club['total_ganados'] ?? 0) ?> G</span>
                        </div>
                        <div class="er-table-wrap" style="border:0;border-radius:0;">
                            <table class="er-table" style="min-width:520px;">
                                <thead>
                                    <tr>
                                        <th class="tc">Pos</th>
                                        <th>Jugador</th>
                                        <th class="tc">G</th>
                                        <th class="tc">P</th>
                                        <th class="tc">Efect.</th>
                                        <th class="tc">Pts.Rnk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($club['jugadores'] as $j): ?>
                                    <tr>
                                        <td class="tc"><?= (int)($j['posicion'] ?? 0) ?: '—' ?></td>
                                        <td><?= htmlspecialchars($j['nombre_completo'] ?? $j['username'] ?? 'N/A') ?></td>
                                        <td class="tc"><?= (int)($j['ganados'] ?? 0) ?></td>
                                        <td class="tc"><?= (int)($j['perdidos'] ?? 0) ?></td>
                                        <td class="tc"><?= (int)($j['efectividad'] ?? 0) ?></td>
                                        <td class="tc"><?= (int)($j['ptosrnk'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php $pos_club++; endforeach; ?>
                <?php endif; ?>

            <?php elseif ($vista === 'equipos_resumido' && $es_equipos): ?>
                <h2 class="er-title"><i class="fas fa-users" style="color:#4338ca;"></i> Resultados por equipos (resumido)</h2>
                <?php if (empty($equipos_resumido)): ?>
                    <p class="er-empty">No hay equipos registrados.</p>
                <?php else: ?>
                    <div class="er-table-wrap">
                        <table class="er-table">
                            <thead>
                                <tr>
                                    <th class="tc">Pos</th>
                                    <th>Equipo</th>
                                    <th>Club</th>
                                    <th class="tc">G</th>
                                    <th class="tc">P</th>
                                    <th class="tc">Efect.</th>
                                    <th class="tc">Puntos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $pos_eq = 1; foreach ($equipos_resumido as $eq): ?>
                                <tr>
                                    <td class="tc"><?= $pos_eq ?><?php if ($pos_eq <= 3): ?> <i class="fas fa-medal" style="color:#f59e0b;"></i><?php endif; ?></td>
                                    <td><strong><?= htmlspecialchars($eq['nombre_equipo'] ?? 'Equipo ' . ($eq['codigo_equipo'] ?? '')) ?></strong></td>
                                    <td><?= htmlspecialchars($eq['club_nombre'] ?? '—') ?></td>
                                    <td class="tc"><?= (int)($eq['ganados'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($eq['perdidos'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($eq['efectividad'] ?? 0) ?></td>
                                    <td class="tc"><?= (int)($eq['puntos'] ?? 0) ?></td>
                                </tr>
                                <?php $pos_eq++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($vista === 'equipos_detallado' && $es_equipos): ?>
                <h2 class="er-title"><i class="fas fa-list-ul" style="color:#4338ca;"></i> Resultados por equipos (detallado)</h2>
                <?php if (empty($equipos_detallado)): ?>
                    <p class="er-empty">No hay equipos registrados.</p>
                <?php else: ?>
                    <?php $pos_eq = 1; foreach ($equipos_detallado as $eq): ?>
                    <div class="er-club-card">
                        <div class="er-club-card-head">
                            <?= $pos_eq ?>° <?= htmlspecialchars($eq['nombre_equipo'] ?? 'Equipo ' . ($eq['codigo_equipo'] ?? '')) ?>
                            — <?= htmlspecialchars($eq['club_nombre'] ?? '') ?>
                            <span class="er-badge-ok" style="margin-left:0.5rem;"><?= (int)($eq['ganados'] ?? 0) ?> G</span>
                        </div>
                        <div class="er-table-wrap" style="border:0;border-radius:0;">
                            <table class="er-table" style="min-width:520px;">
                                <thead>
                                    <tr>
                                        <th class="tc">Pos</th>
                                        <th>Jugador</th>
                                        <th class="tc">G</th>
                                        <th class="tc">P</th>
                                        <th class="tc">Efect.</th>
                                        <th class="tc">Pts.Rnk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($eq['jugadores'] ?? [] as $j): ?>
                                    <tr>
                                        <td class="tc"><?= (int)($j['posicion'] ?? 0) ?: '—' ?></td>
                                        <td><?= htmlspecialchars($j['nombre_completo'] ?? 'N/A') ?></td>
                                        <td class="tc"><?= (int)($j['ganados'] ?? 0) ?></td>
                                        <td class="tc"><?= (int)($j['perdidos'] ?? 0) ?></td>
                                        <td class="tc"><?= (int)($j['efectividad'] ?? 0) ?></td>
                                        <td class="tc"><?= (int)($j['ptosrnk'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php $pos_eq++; endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($podio_acta)): ?>
        <div class="er-podio">
            <h6 style="margin:0 0 0.75rem;font-weight:700;"><i class="fas fa-file-signature me-2"></i>Podio oficial</h6>
            <?php foreach ($podio_acta as $p):
                $medal = [1 => '🥇', 2 => '🥈', 3 => '🥉'][(int)($p['posicion_display'] ?? 0)] ?? '•';
            ?>
            <div style="margin-bottom:0.35rem;"><?= $medal ?> <strong><?= (int)($p['posicion_display'] ?? 0) ?>°</strong> <?= htmlspecialchars($p['nombre'] ?? '') ?>
                <?php if (!empty($p['club_nombre'])): ?>(<?= htmlspecialchars($p['club_nombre']) ?>)<?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="er-footer">
            <a href="resultados.php"><i class="fas fa-list"></i> Ver todos los eventos</a>
            <a href="clasificacion.php?torneo_id=<?= $torneo_id ?>"><i class="fas fa-chart-bar"></i> Clasificación móvil</a>
            <?php if (($torneo_data['total_fotos'] ?? 0) > 0): ?>
            <a href="galeria_fotos.php?torneo_id=<?= $torneo_id ?>"><i class="fas fa-images"></i> Galería</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(AppHelpers::url('landing-spa.php')) ?>"><i class="fas fa-home"></i> Inicio</a>
        </div>
    </div>
</div>
</body>
</html>
