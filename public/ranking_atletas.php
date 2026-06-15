<?php
/**
 * Ranking público de atletas (femenino / masculino) según acumulado en torneos finalizados.
 * Datos desde inscritos + usuarios + tournaments (misma lógica que resultados por evento).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/RankingAtletasPublicoService.php';
require_once __DIR__ . '/../lib/RankingCategoriaFvdHelper.php';

$pdo = DB::pdo();
$base_public = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';

require_once __DIR__ . '/includes/ranking_atletas_context.php';

$ranking_atletas_qs = static function (array $overrides = []) use ($genero, $vista, $categoria): string {
    $p = ['genero' => $genero];
    if ($categoria !== RankingCategoriaFvdHelper::ABSOLUTO) {
        $p['categoria'] = $categoria;
    }
    if ($vista !== 'resumen') {
        $p['vista'] = $vista;
    }
    $p = array_merge($p, $overrides);
    if (($p['vista'] ?? 'resumen') === 'resumen') {
        unset($p['vista']);
    }
    if (($p['categoria'] ?? RankingCategoriaFvdHelper::ABSOLUTO) === RankingCategoriaFvdHelper::ABSOLUTO) {
        unset($p['categoria']);
    }

    return http_build_query($p);
};

$ranking_pdf_qs = static function () use ($genero, $categoria): string {
    $p = ['genero' => $genero];
    if ($categoria !== RankingCategoriaFvdHelper::ABSOLUTO) {
        $p['categoria'] = $categoria;
    }

    return http_build_query($p);
};

$titulo_categoria = RankingCategoriaFvdHelper::etiqueta($categoria, $genero);
$page_title = RankingCategoriaFvdHelper::tituloRanking($categoria, $genero) . ' — La Estación del Dominó';

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];

function fmtfecha(?string $f): string
{
    if ($f === null || $f === '') {
        return '—';
    }
    $t = strtotime($f);

    return $t ? date('d/m/Y', $t) : '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="Ranking oficial FVD <?= htmlspecialchars($titulo_categoria) ?>: atletas afiliados (con entidad) en torneos con ranking activado.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars($base_public . 'ranking_atletas.php?' . $ranking_atletas_qs()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #f8fafc;
            padding: 1.5rem 0;
        }
        .card-rank {
            background: rgba(255,255,255,0.98);
            color: #1e293b;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);
            overflow: hidden;
            max-width: 1200px;
        }
        .header-rank {
            background: linear-gradient(135deg, #0f172a 0%, #1e40af 100%);
            color: white;
            padding: 1.75rem;
        }
        .header-rank h1.h3 {
            font-size: clamp(1.35rem, 2.5vw, 1.85rem);
            font-weight: 700;
        }
        .header-rank .opacity-90,
        .header-rank .small {
            font-size: 1rem !important;
        }
        .nav-genero .nav-link { color: #64748b; font-weight: 600; border-radius: 10px; }
        .nav-genero .nav-link.active {
            background: linear-gradient(135deg, #0f172a, #1e40af);
            color: #fff;
        }
        .tabla-rank th {
            font-size: 1.05rem;
            font-weight: 700;
            color: #334155;
            background: #f8fafc;
            padding: 0.65rem 0.5rem;
        }
        .tabla-rank td {
            font-size: 1.05rem;
            padding: 0.55rem 0.45rem;
        }
        .pos-1 { background: linear-gradient(90deg, #fef3c7, #fde68a); }
        .pos-2 { background: linear-gradient(90deg, #f1f5f9, #e2e8f0); }
        .pos-3 { background: linear-gradient(90deg, #fed7aa, #fdba74); }
        .btn-volver {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .btn-volver:hover { background: rgba(255,255,255,0.25); color: white; }
        .detalle-torneos { font-size: 1rem; background: #f8fafc; }
        .detalle-torneos .table th {
            font-size: 0.95rem;
            font-weight: 700;
        }
        .detalle-torneos .table td {
            font-size: 0.95rem;
        }
        .card-rank-wide { max-width: min(1680px, 100%); }
        .card-detalle-atleta { border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; background: #fff; }
        .tabla-matriz {
            table-layout: fixed;
            width: 100%;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .tabla-matriz th, .tabla-matriz td {
            vertical-align: middle;
            padding: 0.12rem 0.05rem;
        }
        .tabla-matriz tbody td {
            text-rendering: optimizeLegibility;
            font-feature-settings: "tnum" 1, "lnum" 1;
        }
        .tabla-matriz .col-rank-num {
            width: 1.85rem;
            font-size: 0.82rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
        }
        /* Atleta: +20 % sobre 7.8rem ≈ 9.36rem */
        .tabla-matriz .col-atleta {
            max-width: 9.36rem;
            width: 9.36rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 1.05rem;
            font-weight: 600;
            line-height: 1.42;
            letter-spacing: 0.015em;
            color: #0f172a;
        }
        /* Pos / P Gan / Pts: mitad del ancho actual (50 % de 2.65rem × 0.5) */
        .tabla-matriz .col-torneo-sub {
            width: calc(2.65rem * 0.32);
            min-width: calc(2.65rem * 0.32);
            max-width: calc(2.65rem * 0.32);
            box-sizing: border-box;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            line-height: 1.22;
            text-align: center;
            font-size: 0.68rem;
            letter-spacing: -0.02em;
            color: #1e293b;
            padding: 0.14rem 0.04rem !important;
        }
        /* Pts Σ, Efect. Σ, G Σ: sin cambios respecto a la versión anterior */
        .tabla-matriz .col-stat-total {
            width: calc(2.65rem * 1.5);
            min-width: calc(2.65rem * 1.5);
            max-width: calc(2.65rem * 1.5);
            box-sizing: border-box;
            font-variant-numeric: tabular-nums;
            font-weight: 800;
            line-height: 1.25;
            text-align: right;
            font-size: 0.85rem;
            color: #0f172a;
            padding: 0.26rem 0.2rem !important;
        }
        .tabla-matriz thead .sub-h.col-torneo-sub {
            white-space: normal;
            font-size: 0.52rem;
            font-weight: 800;
            line-height: 1.08;
            padding: 0.1rem 0.04rem !important;
            letter-spacing: 0.02em;
        }
        .tabla-matriz thead th.col-stat-total {
            white-space: normal;
            font-size: 0.68rem;
            line-height: 1.15;
            vertical-align: middle;
        }
        .tabla-matriz thead .torneo-nombre {
            font-size: 0.5rem;
            font-weight: 800;
            line-height: 1.12;
            padding: 0.1rem 0.04rem !important;
            word-break: break-word;
            hyphens: auto;
            white-space: normal;
            letter-spacing: 0.005em;
            color: #fff;
            background: #334155 !important;
            border-color: #1e293b !important;
        }
        .tabla-matriz thead .sub-h {
            font-size: 0.55rem;
            font-weight: 700;
            padding: 0.1rem 0.04rem !important;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: #1e293b;
            background: #e2e8f0 !important;
        }
        @media print {
            body { background: #fff !important; color: #111 !important; padding: 0 !important; }
            .no-print, form { display: none !important; }
            .card-rank { box-shadow: none !important; max-width: 100% !important; border-radius: 0; }
            .header-rank { background: #1e293b !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .tabla-matriz { font-size: 8pt; }
            .tabla-matriz .col-atleta { font-size: 9.5pt; max-width: 9.36rem; width: 9.36rem; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card-rank mx-auto <?= $vista === 'matriz' ? 'card-rank-wide' : '' ?>">
        <div class="header-rank">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <?= AppHelpers::appLogo('mb-2', 'La Estación del Dominó', 40) ?>
                    <h1 class="h3 mb-1"><i class="fas fa-medal text-warning me-2"></i>Ranking oficial</h1>
                    <p class="mb-0 opacity-90 small"><?= htmlspecialchars($titulo_categoria) ?> · Solo torneos con ranking activado</p>
                    <?php if ($org_nombre_encabezado !== ''): ?>
                        <p class="mb-0 mt-2 fw-semibold fs-5 text-white"><i class="fas fa-building me-2 opacity-75"></i><?= htmlspecialchars($org_nombre_encabezado) ?></p>
                    <?php endif; ?>
                </div>
                <div class="no-print">
                    <a href="landing-spa.php" class="btn btn-sm btn-volver me-1"><i class="fas fa-home me-1"></i>Inicio</a>
                    <a href="resultados.php" class="btn btn-sm btn-volver"><i class="fas fa-trophy me-1"></i>Resultados por evento</a>
                </div>
            </div>
            <ul class="nav nav-pills nav-genero gap-2 mt-3 no-print flex-wrap">
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?= $genero === 'F' ? 'active' : '' ?>" href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['genero' => 'F'])) ?>"><i class="fas fa-venus me-1"></i>Femenino</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?= $genero === 'M' ? 'active' : '' ?>" href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['genero' => 'M'])) ?>"><i class="fas fa-mars me-1"></i>Masculino</a>
                </li>
            </ul>
            <ul class="nav nav-pills gap-2 mt-2 no-print flex-wrap">
                <?php
                $catsNav = [
                    RankingCategoriaFvdHelper::ABSOLUTO => 'Categoría libre',
                    RankingCategoriaFvdHelper::SUB => 'Sub',
                    RankingCategoriaFvdHelper::SUB12 => 'Sub 12',
                    RankingCategoriaFvdHelper::SUB15 => 'Sub 15',
                    RankingCategoriaFvdHelper::SUB18 => 'Sub 18',
                ];
                foreach ($catsNav as $slug => $label):
                ?>
                <li class="nav-item">
                    <a class="nav-link py-1 px-3 small <?= $categoria === $slug ? 'active' : '' ?>" href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['categoria' => $slug])) ?>"><?= htmlspecialchars($label) ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="p-3 p-md-4">
            <?php if ($atletas !== []): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3 no-print">
                <span class="small text-muted me-1">Vista del reporte:</span>
                <div class="btn-group btn-group-sm mb-0 flex-wrap" role="group" aria-label="Vista del reporte">
                    <a href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['vista' => 'resumen'])) ?>" class="btn btn-<?= $vista === 'resumen' ? 'primary' : 'outline-primary' ?>">Resumen</a>
                    <a href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['vista' => 'detalle'])) ?>" class="btn btn-<?= $vista === 'detalle' ? 'primary' : 'outline-primary' ?>">Detalle vertical</a>
                    <a href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['vista' => 'matriz'])) ?>" class="btn btn-<?= $vista === 'matriz' ? 'primary' : 'outline-primary' ?>">Matriz por torneo</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($atletas === []): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>No hay datos de ranking para este grupo. Participa en torneos finalizados o verifica que el sexo esté registrado en tu perfil.
                </div>
            <?php elseif ($vista === 'detalle'): ?>
                <?php foreach ($atletas as $a): ?>
                    <?php
                    $rk = (int) $a['rank'];
                    $borderClass = $rk === 1 ? 'border-warning' : ($rk === 2 ? 'border-secondary' : ($rk === 3 ? 'border-danger' : ''));
                    ?>
                    <div class="card-detalle-atleta <?= $borderClass !== '' ? $borderClass : '' ?>" style="<?= $rk <= 3 ? 'border-width:2px;' : '' ?>">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <span class="badge bg-dark me-2">#<?= $rk ?></span>
                                <strong class="fs-6"><?= htmlspecialchars($a['nombre']) ?></strong>
                                <?php if (! empty($a['asociacion'])): ?>
                                    <span class="text-muted small d-block"><?= htmlspecialchars((string) $a['asociacion']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-end">
                                <span class="text-muted">Torneos:</span> <?= (int) $a['torneos_count'] ?>
                                · <span class="text-muted">Efect. Σ:</span> <strong><?= (int) $a['total_efectividad'] ?></strong>
                                · <span class="text-muted">Pts Σ:</span> <strong><?= (int) $a['total_puntos'] ?></strong>
                                · <span class="text-muted">Ptos. Rnk:</span> <strong><?= (int) $a['total_ptosrnk'] ?></strong>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 bg-white tabla-rank">
                                <thead class="table-light">
                                    <tr>
                                        <th>Torneo</th>
                                        <th>Fecha</th>
                                        <th>Modalidad</th>
                                        <th class="text-center">Pos.</th>
                                        <th class="text-end">G/P</th>
                                        <th class="text-end">Efect.</th>
                                        <th class="text-end">Pts rnk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($a['detalle_torneos'] as $t): ?>
                                        <tr>
                                            <td>
                                                <a href="evento_resultados.php?torneo_id=<?= (int) $t['torneo_id'] ?>"><?= htmlspecialchars($t['nombre']) ?></a>
                                            </td>
                                            <td><?= fmtfecha($t['fechator'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($modalidades[(int) ($t['modalidad'] ?? 0)] ?? '—') ?></td>
                                            <td class="text-center"><?= (int) ($t['posicion'] ?? 0) ?: '—' ?></td>
                                            <td class="text-end"><?= (int) ($t['ganados'] ?? 0) ?> / <?= (int) ($t['perdidos'] ?? 0) ?></td>
                                            <td class="text-end"><?= (int) ($t['efectividad'] ?? 0) ?></td>
                                            <td class="text-end"><?= (int) ($t['ptosrnk'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php elseif ($vista === 'matriz'): ?>
                <?php if ($torneos_matriz === []): ?>
                    <div class="alert alert-warning mb-0">No hay columnas de torneos para mostrar la matriz.</div>
                <?php else: ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2 no-print">
                    <a href="ranking_atletas_pdf.php?<?= htmlspecialchars($ranking_pdf_qs()) ?>" class="btn btn-sm btn-danger" target="_blank" rel="noopener">
                        <i class="fas fa-file-pdf me-1"></i>Descargar PDF
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Imprimir
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover tabla-matriz align-middle mb-0 bg-white">
                        <thead class="table-light">
                            <tr>
                                <th class="sticky-top bg-light col-rank-num" rowspan="2">#</th>
                                <th class="sticky-top bg-light col-atleta" rowspan="2" title="Atleta">Atleta</th>
                                <?php foreach ($torneos_matriz as $col): ?>
                                    <?php
                                    $nomCol = (string) ($col['nombre'] ?? '');
                                    $nomCorto = function_exists('mb_strlen') && function_exists('mb_substr')
                                        ? ((mb_strlen($nomCol) > 9) ? (mb_substr($nomCol, 0, 7) . '…') : $nomCol)
                                        : ((strlen($nomCol) > 9) ? (substr($nomCol, 0, 7) . '…') : $nomCol);
                                    ?>
                                    <th class="text-center torneo-nombre" colspan="3" title="<?= htmlspecialchars($nomCol . ' — ' . fmtfecha($col['fechator'] ?? '')) ?>">
                                        <?= htmlspecialchars($nomCorto) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-end sticky-top bg-light col-stat-total" rowspan="2">Efect. Σ</th>
                                <th class="text-end sticky-top bg-light col-stat-total" rowspan="2">Pts Σ</th>
                                <th class="text-end sticky-top bg-light col-stat-total" rowspan="2">Ptos. Rnk</th>
                            </tr>
                            <tr>
                                <?php foreach ($torneos_matriz as $_col): ?>
                                    <th class="text-center sub-h col-torneo-sub">Pos</th>
                                    <th class="text-center sub-h col-torneo-sub">P Gan</th>
                                    <th class="text-center sub-h col-torneo-sub">Pts</th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($atletas as $a): ?>
                                <?php
                                $rk = (int) $a['rank'];
                                $trClass = $rk === 1 ? 'pos-1' : ($rk === 2 ? 'pos-2' : ($rk === 3 ? 'pos-3' : ''));
                                $porTorneo = [];
                                foreach ($a['detalle_torneos'] as $t) {
                                    $porTorneo[(int) ($t['torneo_id'] ?? 0)] = $t;
                                }
                                ?>
                                <tr class="<?= $trClass ?>">
                                    <td class="col-rank-num"><strong><?= $rk ?></strong></td>
                                    <td class="col-atleta" title="<?= htmlspecialchars($a['nombre']) ?>">
                                        <strong><?= htmlspecialchars($a['nombre']) ?></strong>
                                        <?php if (! empty($a['asociacion'])): ?>
                                            <span class="text-muted small d-block"><?= htmlspecialchars((string) $a['asociacion']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($torneos_matriz as $col): ?>
                                        <?php
                                        $tid = (int) $col['torneo_id'];
                                        $celda = $porTorneo[$tid] ?? null;
                                        ?>
                                        <?php if ($celda === null): ?>
                                            <td class="text-center col-torneo-sub"><span class="text-muted">—</span></td>
                                            <td class="text-center col-torneo-sub"><span class="text-muted">—</span></td>
                                            <td class="text-center col-torneo-sub"><span class="text-muted">—</span></td>
                                        <?php else: ?>
                                            <?php $posN = (int) ($celda['posicion'] ?? 0); ?>
                                            <td class="text-center col-torneo-sub"><?= $posN > 0 ? $posN : '<span class="text-muted">—</span>' ?></td>
                                            <td class="text-center col-torneo-sub"><?= (int) ($celda['ganados'] ?? 0) ?></td>
                                            <td class="text-center col-torneo-sub"><?= (int) ($celda['ptosrnk'] ?? 0) ?></td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <td class="text-end col-stat-total"><?= (int) $a['total_efectividad'] ?></td>
                                    <td class="text-end col-stat-total"><?= (int) $a['total_puntos'] ?></td>
                                    <td class="text-end fw-semibold col-stat-total"><?= (int) $a['total_ptosrnk'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">Por cada torneo: <strong>Pos</strong> = posición en el evento, <strong>P Gan</strong> = partidas ganadas, <strong>Pts</strong> = puntos de ranking. Desplace horizontalmente si hay muchos torneos.</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover tabla-rank align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Atleta</th>
                                <th class="text-center">Torneos</th>
                                <th class="text-end">Efect. Σ</th>
                                <th class="text-end">Pts Σ</th>
                                <th class="text-end">Ptos. Rnk</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($atletas as $a): ?>
                                <?php
                                $rk = (int) $a['rank'];
                                $trClass = $rk === 1 ? 'pos-1' : ($rk === 2 ? 'pos-2' : ($rk === 3 ? 'pos-3' : ''));
                                ?>
                                <tr class="<?= $trClass ?>">
                                    <td><strong><?= $rk ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($a['nombre']) ?></strong>
                                        <?php if (! empty($a['asociacion'])): ?>
                                            <span class="text-muted small d-block"><?= htmlspecialchars((string) $a['asociacion']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= (int) $a['torneos_count'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_efectividad'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_puntos'] ?></td>
                                    <td class="text-end fw-semibold"><?= (int) $a['total_ptosrnk'] ?></td>
                                    <td class="text-end">
                                        <?php
                                        $detQs = ['genero' => $genero, 'id_usuario' => (int) $a['id_usuario']];
                                        if ($categoria !== RankingCategoriaFvdHelper::ABSOLUTO) {
                                            $detQs['categoria'] = $categoria;
                                        }
                                        $detalleUrl = $base_public . 'ranking_atletas_detalle.php?' . http_build_query($detQs);
                                        ?>
                                        <a href="<?= htmlspecialchars($detalleUrl) ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-list-ul me-1"></i>Ver detalle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <p class="text-center text-white-50 small mt-3 mb-0">
        Estructura tipo reporte histórico: una fila por torneo en el detalle (similar a un Excel de ranking por categoría).
    </p>
</div>
</body>
</html>
