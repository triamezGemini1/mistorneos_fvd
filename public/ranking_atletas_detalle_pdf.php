<?php
/**
 * PDF personalizado: detalle de ranking público por atleta.
 * Controlado por RankingAtletasPdfAccesoHelper::DESCARGA_HABILITADA.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/RankingAtletasPublicoService.php';
require_once __DIR__ . '/../lib/RankingAtletasPdfAccesoHelper.php';
require_once __DIR__ . '/../lib/RankingCategoriaFvdHelper.php';
require_once __DIR__ . '/../lib/report_generator.php';

$pdo = DB::pdo();
$sessionUser = Auth::user();

$genero = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : 'F';
if ($genero !== 'M' && $genero !== 'F') {
    $genero = 'F';
}
$categoria = RankingCategoriaFvdHelper::normalizar((string) ($_GET['categoria'] ?? RankingCategoriaFvdHelper::ABSOLUTO));
$idUsuario = (int) ($_GET['id_usuario'] ?? 0);
if ($idUsuario <= 0) {
    http_response_code(400);
    echo 'Atleta inválido.';
    exit;
}

$acceso = RankingAtletasPdfAccesoHelper::evaluarDescargaPropio(
    $pdo,
    is_array($sessionUser) ? $sessionUser : null,
    $idUsuario
);
if (! $acceso['permitido']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $acceso['mensaje'];
    exit;
}

$svc = new RankingAtletasPublicoService($pdo);
$atleta = $svc->obtenerAtletaPorIdUsuario($genero, $idUsuario, 0, $categoria);
if ($atleta === null) {
    http_response_code(404);
    echo 'Atleta no encontrado en el ranking.';
    exit;
}

$org = $svc->datosEncabezadoOrganizacion();
$subtituloRanking = $svc->subtituloRankingNacional($genero, $categoria);
$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];

$fmtFecha = static function (?string $f): string {
    if ($f === null || $f === '') {
        return '—';
    }
    $t = strtotime($f);

    return $t ? date('d/m/Y', $t) : '—';
};

$leyenda = static function (int $mod): string {
    if ($mod === 1) {
        return 'Indiv.';
    }
    if (in_array($mod, [2, 4], true)) {
        return 'Pareja';
    }
    if ($mod === 3) {
        return 'Equipo';
    }

    return '';
};

$cssExtra = '
<style>
.header { display: none !important; }
.enc-org-pdf {
  background: #2182E9;
  color: #fff;
  margin: -20px -20px 18px -20px;
  padding: 16px 18px 14px 18px;
  font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
}
.enc-org-pdf-table { width: 100%; border-collapse: collapse; }
.enc-org-pdf-table td { vertical-align: middle; border: none; padding: 0; }
.enc-org-logo-cell {
  width: 108pt;
  padding-right: 12pt !important;
}
.enc-org-logo-box {
  background: #fff;
  border-radius: 10pt;
  padding: 8pt 10pt;
  text-align: center;
  border: 2px solid #fff;
}
.enc-org-logo-box img {
  max-height: 72pt;
  max-width: 96pt;
  width: auto;
  height: auto;
}
.enc-org-pdf .org-nombre {
  font-size: 13pt;
  font-weight: bold;
  color: #fff;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin: 0 0 10px 0;
  line-height: 1.25;
}
.enc-org-pdf .org-subtitulo {
  font-size: 11.5pt;
  font-weight: bold;
  color: #fff;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin: 0;
  padding-top: 10px;
  border-top: 2px solid rgba(255,255,255,0.5);
}
.enc-org-fecha-pdf {
  font-size: 8pt;
  color: rgba(255,255,255,0.9);
  text-align: right;
  white-space: nowrap;
  padding-left: 8pt;
}
.resumen-atleta {
  background: #f8fafc;
  border: 2px solid #2182E9;
  border-radius: 8px;
  padding: 14px 16px;
  margin: 16px 0;
  overflow: hidden;
}
.resumen-top { width: 100%; }
.resumen-top td { vertical-align: top; padding: 0; border: none; }
.resumen-carnet { font-size: 9.5pt; color: #475569; font-weight: bold; margin-bottom: 4px; }
.resumen-carnet strong { color: #091A32; }
.resumen-atleta .nombre {
  font-size: 16pt;
  font-weight: bold;
  color: #091A32;
  margin: 0;
}
.resumen-asociacion {
  font-size: 9.5pt;
  color: #475569;
  margin-top: 3pt;
  line-height: 1.25;
}
.resumen-pos-rnk {
  text-align: right;
  font-size: 28pt;
  font-weight: bold;
  color: #091A32;
  white-space: nowrap;
  min-width: 72pt;
  line-height: 1;
  letter-spacing: -0.02em;
}
.resumen-stats-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 6pt 0;
  margin-top: 12pt;
  padding-top: 10pt;
  border-top: 1px solid #cbd5e1;
}
.resumen-stats-table td {
  border: none;
  padding: 0;
  vertical-align: top;
}
.stat-pill-pdf {
  text-align: center;
  border-radius: 6pt;
  padding: 6pt 8pt 7pt;
  color: #fff;
  min-width: 52pt;
}
.stat-pill-pdf .stat-lbl {
  display: block;
  font-size: 7pt;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.95;
  margin-bottom: 3pt;
  line-height: 1.15;
}
.stat-pill-pdf .stat-num {
  display: block;
  font-size: 12pt;
  font-weight: bold;
  line-height: 1.1;
}
.stat-pill-primary { background: #0d6efd; }
.stat-pill-secondary { background: #6c757d; }
.stat-pill-success { background: #198754; }
.stat-pill-danger { background: #dc3545; }
.stat-pill-dark { background: #212529; }
.stat-pill-info { background: #0dcaf0; color: #111; }
.stat-pill-warning { background: #ffc107; color: #111; }
table.det-pdf { font-size: 8.5pt; }
table.det-pdf th { background: #2182E9; color: #fff; padding: 6px 5px; }
table.det-pdf td { padding: 5px; vertical-align: top; }
table.det-pdf tfoot th { background: #e2e8f0; color: #111; }
.fila-np { color: #64748b; }
</style>';

$logoPdfSrc = AppHelpers::getBrandLogoDataUri() ?? '';
$fechaReporte = date('d/m/Y H:i');

$badgesResumenPdf = [
    ['label' => 'TJ', 'value' => (int) ($atleta['tj'] ?? 0), 'class' => 'stat-pill-primary'],
    ['label' => 'PJ', 'value' => (int) ($atleta['pj'] ?? 0), 'class' => 'stat-pill-secondary'],
    ['label' => 'PG', 'value' => (int) ($atleta['pg'] ?? 0), 'class' => 'stat-pill-success'],
    ['label' => 'PP', 'value' => (int) ($atleta['pp'] ?? 0), 'class' => 'stat-pill-danger'],
    ['label' => 'Efect. Σ', 'value' => (int) ($atleta['total_efectividad'] ?? 0), 'class' => 'stat-pill-info'],
    ['label' => 'Pts Σ', 'value' => (int) ($atleta['total_puntos'] ?? 0), 'class' => 'stat-pill-dark'],
    ['label' => 'Ptos. Rnk', 'value' => (int) ($atleta['total_ptosrnk'] ?? 0), 'class' => 'stat-pill-warning'],
];

$html = $cssExtra;
$html .= '<div class="enc-org-pdf">';
$html .= '<table class="enc-org-pdf-table" cellpadding="0" cellspacing="0"><tr>';
if ($logoPdfSrc !== '') {
    $html .= '<td class="enc-org-logo-cell"><div class="enc-org-logo-box">';
    $html .= '<img src="' . htmlspecialchars($logoPdfSrc) . '" alt="FVD">';
    $html .= '</div></td>';
}
$html .= '<td>';
$html .= '<div class="org-nombre">' . htmlspecialchars($org['nombre']) . '</div>';
$html .= '<div class="org-subtitulo">' . htmlspecialchars($subtituloRanking) . '</div>';
$html .= '</td>';
$html .= '<td class="enc-org-fecha-pdf">' . htmlspecialchars($fechaReporte) . '</td>';
$html .= '</tr></table>';
$html .= '</div>';
$html .= '<div class="resumen-atleta">';
$html .= '<table class="resumen-top" cellpadding="0" cellspacing="0"><tr>';
$html .= '<td style="width:70%">';
if ((int) ($atleta['numfvd'] ?? 0) > 0) {
    $html .= '<div class="resumen-carnet">Carnet <strong>' . (int) $atleta['numfvd'] . '</strong></div>';
}
$html .= '<div class="nombre">' . htmlspecialchars((string) ($atleta['nombre'] ?? '')) . '</div>';
$asocPdf = trim((string) ($atleta['asociacion'] ?? ''));
if ($asocPdf !== '') {
    $html .= '<div class="resumen-asociacion">' . htmlspecialchars($asocPdf) . '</div>';
}
$html .= '</td>';
$html .= '<td style="width:32%" class="resumen-pos-rnk">#' . (int) ($atleta['rank'] ?? 0) . '</td>';
$html .= '</tr></table>';
$html .= '<table class="resumen-stats-table" cellpadding="0" cellspacing="0"><tr>';
foreach ($badgesResumenPdf as $badge) {
    $html .= '<td><div class="stat-pill-pdf ' . htmlspecialchars($badge['class']) . '">';
    $html .= '<span class="stat-lbl">' . htmlspecialchars($badge['label']) . '</span>';
    $html .= '<span class="stat-num">' . (int) $badge['value'] . '</span>';
    $html .= '</div></td>';
}
$html .= '</tr></table></div>';

$html .= '<h2>Participación por torneo</h2>';
$html .= '<table class="det-pdf"><thead><tr>';
$html .= '<th>Torneo</th><th>Fecha</th><th>Mod.</th><th>Clasif</th><th>PG</th><th>PP</th><th>PJ</th><th>EFEC</th><th>Tot Pts</th><th>Ptos. Rnk</th>';
$html .= '</tr></thead><tbody>';

foreach ($atleta['detalle_torneos'] ?? [] as $t) {
    $participo = ! isset($t['participo']) || ! empty($t['participo']);
    $modT = (int) ($t['modalidad'] ?? 0);
    $trClass = $participo ? '' : ' class="fila-np"';
    $html .= '<tr' . $trClass . '>';
    $nom = htmlspecialchars((string) ($t['nombre'] ?? ''));
    if (! $participo) {
        $nom .= ' (No participó)';
    } elseif ($modT === 3 && ! empty($t['codigo_equipo'])) {
        $nom .= '<br><small>Eq. ' . htmlspecialchars((string) $t['codigo_equipo']) . '</small>';
    }
    $html .= '<td>' . $nom . '</td>';
    $html .= '<td>' . $fmtFecha((string) ($t['fechator'] ?? '')) . '</td>';
    $html .= '<td>' . htmlspecialchars(($modalidades[$modT] ?? '—') . ($leyenda($modT) !== '' ? ' (' . $leyenda($modT) . ')' : '')) . '</td>';
    $html .= '<td style="text-align:center">' . ($participo && (int) ($t['clasif'] ?? 0) ? (int) $t['clasif'] : '—') . '</td>';
    $html .= '<td style="text-align:center">' . ($participo ? (int) ($t['pg'] ?? 0) : '—') . '</td>';
    $html .= '<td style="text-align:center">' . ($participo ? (int) ($t['pp'] ?? 0) : '—') . '</td>';
    $html .= '<td style="text-align:center">' . ($participo ? (int) ($t['pj'] ?? 0) : '—') . '</td>';
    $html .= '<td style="text-align:right">' . ($participo ? (int) ($t['efec'] ?? 0) : '—') . '</td>';
    $html .= '<td style="text-align:right">' . ($participo ? (int) ($t['tot_pts'] ?? 0) : '—') . '</td>';
    $html .= '<td style="text-align:right;font-weight:bold">' . ($participo ? (int) ($t['ptosrnk'] ?? 0) : '—') . '</td>';
    $html .= '</tr>';
}

$html .= '</tbody><tfoot><tr>';
$html .= '<th colspan="3" style="text-align:right">Totales (jugados)</th>';
$html .= '<th>—</th>';
$html .= '<th style="text-align:center">' . (int) ($atleta['pg'] ?? 0) . '</th>';
$html .= '<th style="text-align:center">' . (int) ($atleta['pp'] ?? 0) . '</th>';
$html .= '<th style="text-align:center">' . (int) ($atleta['pj'] ?? 0) . '</th>';
$html .= '<th style="text-align:right">' . (int) ($atleta['total_efectividad'] ?? 0) . '</th>';
$html .= '<th style="text-align:right">' . (int) ($atleta['total_puntos'] ?? 0) . '</th>';
$html .= '<th style="text-align:right;font-weight:bold">' . (int) ($atleta['total_ptosrnk'] ?? 0) . '</th>';
$html .= '</tr></tfoot></table>';

try {
    $report = new ReportGenerator((string) $org['nombre'], 'portrait');
    $report->setContent($html);
    $slug = (int) ($atleta['numfvd'] ?? 0) > 0 ? (string) (int) $atleta['numfvd'] : 'u' . $idUsuario;
    $fn = 'ranking_atleta_' . $slug . '_' . $genero . '_' . date('Y-m-d') . '.pdf';
    $report->generate($fn, true);
} catch (Throwable $e) {
    error_log('ranking_atletas_detalle_pdf: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar el PDF.';
    exit;
}
