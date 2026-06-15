<?php
/**
 * Detalle individual del ranking público (landing) — misma estructura que ranking NUMFVD admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/RankingAtletasPublicoService.php';
require_once __DIR__ . '/../lib/RankingAtletasPdfAccesoHelper.php';
require_once __DIR__ . '/../lib/RankingCategoriaFvdHelper.php';

$pdo = DB::pdo();
$base_public = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';

$genero = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : 'F';
if ($genero !== 'M' && $genero !== 'F') {
    $genero = 'F';
}
$categoria = RankingCategoriaFvdHelper::normalizar((string) ($_GET['categoria'] ?? RankingCategoriaFvdHelper::ABSOLUTO));
$idUsuario = (int) ($_GET['id_usuario'] ?? 0);

$listQs = ['genero' => $genero];
if ($categoria !== RankingCategoriaFvdHelper::ABSOLUTO) {
    $listQs['categoria'] = $categoria;
}
$baseList = $base_public . 'ranking_atletas.php?' . http_build_query($listQs);

if ($idUsuario <= 0) {
    header('Location: ' . $baseList);
    exit;
}

$svc = new RankingAtletasPublicoService($pdo);
$atleta = $svc->obtenerAtletaPorIdUsuario($genero, $idUsuario, 0, $categoria);
$org = $svc->datosEncabezadoOrganizacion();
$subtituloRanking = $svc->subtituloRankingNacional($genero, $categoria);
$tituloGenero = $genero === 'F' ? 'Femenino' : 'Masculino';
$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];

$sessionUser = Auth::user();
$accesoPdf = RankingAtletasPdfAccesoHelper::evaluarDescargaPropio(
    $pdo,
    is_array($sessionUser) ? $sessionUser : null,
    $idUsuario
);
$pdfDisponible = $accesoPdf['permitido'];
$mostrarSeccionPdf = RankingAtletasPdfAccesoHelper::descargaGlobalHabilitada()
    && is_array($sessionUser)
    && (int) ($sessionUser['id'] ?? 0) === $idUsuario;

$pdfQs = array_merge($listQs, ['id_usuario' => $idUsuario]);
$pdfUrl = $base_public . 'ranking_atletas_detalle_pdf.php?' . http_build_query($pdfQs);
$loginUrl = $base_public . 'login.php?redirect=' . rawurlencode($base_public . 'ranking_atletas_detalle.php?' . http_build_query($pdfQs));
$pdfTooltip = htmlspecialchars($accesoPdf['mensaje'] !== '' ? $accesoPdf['mensaje'] : 'PDF no disponible');

$fmtFecha = static function (?string $f): string {
    if ($f === null || $f === '') {
        return '—';
    }
    $t = strtotime($f);

    return $t ? date('d/m/Y', $t) : '—';
};

$leyendaStatsModalidad = static function (int $mod): string {
    if ($mod === 1) {
        return 'Stats individuales';
    }
    if (in_array($mod, [2, 4], true)) {
        return 'Stats unidad (pareja)';
    }
    if ($mod === 3) {
        return 'Stats individuales · clasif. equipo';
    }

    return '';
};

$page_title = 'Detalle ranking — ' . RankingCategoriaFvdHelper::etiqueta($categoria, $genero);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #f8fafc;
            padding: 1.5rem 0;
        }
        .rnk-det-org {
            background: #2182E9;
            color: #fff;
            border-radius: 12px;
            padding: 1.35rem 1.5rem;
            font-family: "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .rnk-det-org-titulo {
            font-size: clamp(1.1rem, 2.2vw, 1.45rem);
            margin: 0 0 0.65rem 0;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #fff;
        }
        .rnk-det-org-subtitulo {
            font-size: clamp(1rem, 1.8vw, 1.2rem);
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #fff;
            padding-top: 0.65rem;
            margin-top: 0.15rem;
            border-top: 2px solid rgba(255, 255, 255, 0.55);
        }
        .rnk-det-org-fecha {
            font-size: 0.8rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.88);
        }
        .rnk-det-org-logo-wrap {
            background: #fff;
            border-radius: 14px;
            padding: 0.55rem 0.75rem;
            box-shadow: 0 6px 20px rgba(9, 26, 50, 0.28);
            border: 2px solid rgba(255, 255, 255, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 7.5rem;
            min-height: 7.5rem;
        }
        .rnk-det-org-logo-img {
            display: block;
            width: auto;
            height: auto;
            max-height: 6.5rem;
            max-width: 8.5rem;
            min-height: 4.5rem;
            object-fit: contain;
            object-position: center center;
        }
        .rnk-det-atleta {
            background: #fff;
            border: 2px solid #2182E9;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            color: #1e293b;
        }
        .rnk-det-atleta .nombre-atleta {
            font-size: 1.4rem;
            font-weight: 800;
            color: #091A32;
            line-height: 1.3;
        }
        .rnk-det-carnet {
            font-size: 0.95rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.35rem;
        }
        .rnk-det-carnet strong { color: #091A32; }
        .rnk-atleta-asociacion {
            font-size: 0.9rem;
            font-weight: 500;
            margin-top: 0.2rem;
            line-height: 1.3;
        }
        .rnk-det-pos-rnk {
            min-width: 5.5rem;
            font-size: clamp(2.25rem, 5vw, 3rem);
            font-weight: 800;
            color: #091A32;
            line-height: 1;
            text-align: right;
            white-space: nowrap;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
        }
        .rnk-stat-pill {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            min-width: 4.25rem;
            padding: 0.45rem 0.7rem 0.5rem;
            line-height: 1.15;
            border-radius: 0.5rem;
        }
        .rnk-stat-label {
            display: block;
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            opacity: 0.92;
            margin-bottom: 0.2rem;
            white-space: nowrap;
        }
        .rnk-stat-val {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        .card-detalle {
            background: rgba(255,255,255,0.98);
            color: #1e293b;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);
        }
    </style>
</head>
<body>
<div class="container py-3" style="max-width: 1100px;">
    <?php require dirname(__DIR__) . '/modules/ranking_numfvd/_encabezado_reporte.php'; ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="<?= htmlspecialchars($baseList) ?>" class="btn btn-sm btn-outline-light">
            <i class="fas fa-arrow-left me-1"></i>Volver al ranking
        </a>
        <?php if ($atleta !== null && $mostrarSeccionPdf): ?>
            <?php if ($pdfDisponible): ?>
                <a href="<?= htmlspecialchars($pdfUrl) ?>" class="btn btn-sm btn-danger" target="_blank" rel="noopener">
                    <i class="fas fa-file-pdf me-1"></i>Descargar PDF personal
                </a>
            <?php else: ?>
                <button type="button"
                        class="btn btn-sm btn-danger disabled opacity-75"
                        disabled
                        title="<?= $pdfTooltip ?>"
                        aria-disabled="true">
                    <i class="fas fa-file-pdf me-1"></i>Descargar PDF personal
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($atleta === null): ?>
        <div class="alert alert-warning">
            No se encontró el atleta en el ranking <?= htmlspecialchars(strtolower($tituloGenero)) ?>
            <?= $categoria !== RankingCategoriaFvdHelper::ABSOLUTO ? ' (' . htmlspecialchars(RankingCategoriaFvdHelper::etiqueta($categoria, $genero)) . ')' : '' ?>.
        </div>
    <?php else: ?>
        <?php if ($mostrarSeccionPdf && ! $pdfDisponible): ?>
            <div class="alert alert-secondary small mb-3 py-2">
                <i class="fas fa-file-pdf me-1"></i>
                <?= htmlspecialchars($accesoPdf['mensaje']) ?>
                <?php if (($accesoPdf['motivo'] ?? '') === 'login' && RankingAtletasPdfAccesoHelper::descargaGlobalHabilitada()): ?>
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="alert-link ms-1">Iniciar sesión</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="rnk-det-atleta mb-3 shadow-sm">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="flex-grow-1 min-w-0">
                    <?php if ((int) ($atleta['numfvd'] ?? 0) > 0): ?>
                        <div class="rnk-det-carnet">Carnet <strong><?= (int) $atleta['numfvd'] ?></strong></div>
                    <?php endif; ?>
                    <div><?php $nombreClass = 'nombre-atleta'; require dirname(__DIR__) . '/modules/ranking_numfvd/_linea_nombre_atleta.php'; ?></div>
                </div>
                <div class="rnk-det-pos-rnk">#<?= (int) ($atleta['rank'] ?? 0) ?></div>
            </div>
            <?php require dirname(__DIR__) . '/modules/ranking_numfvd/_badges_resumen_atleta.php'; ?>
        </div>

        <div class="card card-detalle shadow-sm">
            <div class="card-header bg-light fw-semibold">
                <i class="fas fa-list me-1"></i>Detalle de participación por torneo
            </div>
            <div class="card-body p-2 p-md-3">
                <?php require dirname(__DIR__) . '/modules/ranking_numfvd/_tabla_detalle_torneos.php'; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
