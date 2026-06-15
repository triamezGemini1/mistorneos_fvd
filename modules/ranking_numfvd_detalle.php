<?php
/**
 * Detalle de ranking por atleta (página independiente) + enlace a PDF personalizado.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/RankingNumfvdAdminService.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$svc = new RankingNumfvdAdminService($pdo);

$genero = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : 'F';
if ($genero !== 'M' && $genero !== 'F') {
    $genero = 'F';
}
$numfvd = (int) ($_GET['numfvd'] ?? 0);
$baseList = 'index.php?page=ranking_numfvd_admin&genero=' . urlencode($genero);

if ($numfvd <= 0) {
    header('Location: ' . $baseList);
    exit;
}

$atleta = $svc->obtenerAtletaPorNumfvd($genero, $numfvd);
$org = $svc->datosEncabezadoOrganizacion();
$subtituloRanking = $svc->subtituloRankingNacional($genero);
$tituloGenero = $genero === 'F' ? 'Femenino' : 'Masculino';
$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];

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

$pdfUrl = class_exists('AppHelpers')
    ? AppHelpers::url('ranking_numfvd_detalle_pdf.php', ['genero' => $genero, 'numfvd' => $numfvd])
    : 'ranking_numfvd_detalle_pdf.php?genero=' . urlencode($genero) . '&numfvd=' . $numfvd;
?>
<style>
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
</style>

<div class="container-fluid py-3" style="max-width: 1100px;">
    <?php require __DIR__ . '/ranking_numfvd/_encabezado_reporte.php'; ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="<?= htmlspecialchars($baseList) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Volver al ranking
        </a>
        <?php if ($atleta !== null): ?>
            <a href="<?= htmlspecialchars($pdfUrl) ?>" class="btn btn-sm btn-danger" target="_blank" rel="noopener">
                <i class="fas fa-file-pdf me-1"></i>Descargar PDF personal
            </a>
        <?php endif; ?>
    </div>

    <?php if ($atleta === null): ?>
        <div class="alert alert-warning">
            No se encontró el atleta NUMFVD <strong><?= $numfvd ?></strong> en el ranking <?= htmlspecialchars(strtolower($tituloGenero)) ?>.
        </div>
    <?php else: ?>
        <div class="rnk-det-atleta mb-3 shadow-sm">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="flex-grow-1 min-w-0">
                    <div class="rnk-det-carnet">Carnet <strong><?= (int) ($atleta['numfvd'] ?? 0) ?></strong></div>
                    <div><?php $nombreClass = 'nombre-atleta'; require __DIR__ . '/ranking_numfvd/_linea_nombre_atleta.php'; ?></div>
                </div>
                <div class="rnk-det-pos-rnk">#<?= (int) ($atleta['rank'] ?? 0) ?></div>
            </div>
            <?php require __DIR__ . '/ranking_numfvd/_badges_resumen_atleta.php'; ?>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-light fw-semibold">
                <i class="fas fa-list me-1"></i>Detalle de participación por torneo
            </div>
            <div class="card-body p-2 p-md-3">
                <?php require __DIR__ . '/ranking_numfvd/_tabla_detalle_torneos.php'; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
