<?php

declare(strict_types=1);

/**
 * Finanzas consolidadas por asociación (código entidad).
 * Moneda de referencia: EUR. Enlaces a reportes operativos y detalle por concepto.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/FvdConfig.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/FinanzasAsociacionData.php';
require_once __DIR__ . '/../../lib/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/../../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('finanzas/resumen_asociacion');

$pdo = DB::pdo();
$user = Auth::user();
if (!is_array($user)) {
    echo '<div class="alert alert-danger">Sesión no válida.</div>';
    return;
}

$isAdminGeneral = Auth::isAdminGeneral();
$uid = (int) ($user['id'] ?? 0);
$role = (string) ($user['role'] ?? '');

$clubOperativo = AsociacionAdminHelper::clubOperativo($pdo, $uid, $role);
$entidadForzada = 0;

if ($isAdminGeneral) {
    $entidadForzada = (int) ($_GET['entidad_id'] ?? 0);
} elseif ($clubOperativo !== null) {
    $entidadForzada = (int) ($clubOperativo['entidad'] ?? 0);
} elseif ($role === 'admin_club' && (int) ($user['entidad'] ?? 0) > 0) {
    $entidadForzada = (int) $user['entidad'];
} else {
    http_response_code(403);
    echo '<div class="alert alert-danger mb-0"><i class="fas fa-lock me-2"></i>'
        . 'No tiene permisos para ver finanzas por asociación. Se requiere administrador general (FVD), delegado de asociación o usuario admin de club con entidad asignada.'
        . '</div>';
    return;
}

$entidadesLista = [];
if ($isAdminGeneral) {
    try {
        $entidadesLista = FinanzasAsociacionData::listarEntidadesConClubes($pdo);
    } catch (Throwable $e) {
        error_log('resumen_asociacion: listar entidades: ' . $e->getMessage());
        $entidadesLista = [];
    }
}

$entidadId = $entidadForzada;
$entidadNombre = '';
$alerta = '';

if ($entidadId > 0) {
    if (!FinanzasAsociacionData::entidadExiste($pdo, $entidadId)) {
        $alerta = 'La entidad indicada no existe.';
        $entidadId = 0;
    } else {
        $st = $pdo->prepare('SELECT nombre FROM entidad WHERE id = ? LIMIT 1');
        $st->execute([$entidadId]);
        $entidadNombre = trim((string) $st->fetchColumn());
    }
}

$orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : 1;
$monedaEtiqueta = FinanzasAsociacionData::MONEDA_REFERENCIA;
$tablaMov = FinanzasAsociacionData::tablaExiste($pdo, 'movimiento_torneo');
$clubIdFin = $clubOperativo !== null ? (int) ($clubOperativo['id'] ?? 0) : 0;

$torneoIdFin = (int) ($_GET['torneo_id'] ?? 0);
$modoEventoMasivo = !empty($_GET['evento_masivo']);
$torneoNombreFin = '';

if ($torneoIdFin <= 0 && !$isAdminGeneral) {
    $torneoIdFin = (int) (FvdMovimientoTorneoHelper::torneoActivoId($pdo) ?? 0);
}

if ($torneoIdFin > 0) {
    $stTor = $pdo->prepare('SELECT nombre, COALESCE(es_evento_masivo, 0) AS es_evento_masivo FROM tournaments WHERE id = ? LIMIT 1');
    $stTor->execute([$torneoIdFin]);
    $torRow = $stTor->fetch(PDO::FETCH_ASSOC);
    if ($torRow) {
        if ((int) ($torRow['es_evento_masivo'] ?? 0) > 0) {
            $modoEventoMasivo = true;
        }
        $torneoNombreFin = trim((string) ($torRow['nombre'] ?? ''));
    } else {
        $torneoIdFin = 0;
        $modoEventoMasivo = false;
    }
}

$conceptoVer = trim((string) ($_GET['concepto'] ?? ''));
$conceptosFin = [
    'afiliacion' => [
        'titulo' => 'Afiliaciones y anualidades',
        'reporte' => 'asociacion/reportes/afiliaciones',
        'reporte_label' => 'Reporte de afiliaciones',
        'pago' => 'asociacion/afiliar_atleta',
        'pago_label' => 'Afiliar atleta',
        'border' => 'primary',
    ],
    'traspaso' => [
        'titulo' => 'Traspasos',
        'reporte' => 'asociacion/reportes/traspasos',
        'reporte_label' => 'Reporte de traspasos',
        'pago' => 'asociacion/solicitud',
        'pago_label' => 'Solicitar traspaso',
        'pago_extra' => ['tipo' => 'traspaso'],
        'border' => 'info',
    ],
    'carnet' => [
        'titulo' => 'Carnets / credenciales',
        'reporte' => 'asociacion/reportes/carnets',
        'reporte_label' => 'Reporte de carnets',
        'pago' => 'asociacion/solicitud',
        'pago_label' => 'Solicitar carnet',
        'pago_extra' => ['tipo' => 'carnet'],
        'border' => 'warning',
    ],
    'inscripcion' => [
        'titulo' => 'Inscripciones al torneo',
        'reporte' => 'torneo_gestion',
        'reporte_label' => 'Administrador de inscripciones',
        'reporte_extra' => ['action' => 'inscripciones'],
        'pago' => 'torneo_gestion',
        'pago_label' => 'Inscribir en sitio',
        'pago_extra' => ['action' => 'inscribir_sitio'],
        'border' => 'success',
    ],
    'pagos_torneo' => [
        'titulo' => 'Pagos reportados (torneo activo)',
        'reporte' => 'reportes_pago_usuarios',
        'reporte_label' => 'Gestionar reportes de pago',
        'pago' => 'torneo_gestion',
        'pago_label' => 'Validar pagos en inscripciones',
        'pago_extra' => ['action' => 'inscripciones'],
        'border' => 'secondary',
    ],
];
if (!isset($conceptosFin[$conceptoVer])) {
    $conceptoVer = '';
}

$urlFin = static function (array $extra = []) use ($isAdminGeneral, $entidadId, $torneoIdFin, $modoEventoMasivo): string {
    $q = $extra;
    if ($isAdminGeneral && $entidadId > 0 && !isset($q['entidad_id'])) {
        $q['entidad_id'] = $entidadId;
    }
    if ($torneoIdFin > 0 && !isset($q['torneo_id'])) {
        $q['torneo_id'] = $torneoIdFin;
    }
    if ($modoEventoMasivo && !isset($q['evento_masivo'])) {
        $q['evento_masivo'] = 1;
    }

    return AppHelpers::dashboard('finanzas/resumen_asociacion', $q);
};

$urlModulo = static function (string $page, array $extra = []) use ($torneoIdFin): string {
    $q = $extra;
    if ($torneoIdFin > 0 && !isset($q['torneo_id'])) {
        $q['torneo_id'] = $torneoIdFin;
    }

    return AppHelpers::dashboard($page, $q);
};

$fmtMoney = static function (float $n): string {
    return FinanzasAsociacionData::formatearImporte($n);
};

$cardAfili = ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
$cardTras = ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
$cardCarnet = ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
$cardInscr = ['recaudado' => 0.0, 'pendiente' => 0.0, 'inscripciones' => 0];
$historial = [];
$historialPagosTorneo = [];
$filtroTorneoMov = $torneoIdFin > 0 ? $torneoIdFin : 0;

if ($entidadId > 0) {
    if ($modoEventoMasivo && $torneoIdFin > 0) {
        $resTor = FinanzasAsociacionData::resumenInscripcionesTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin);
        $cardInscr = [
            'recaudado' => (float) ($resTor['recaudado'] ?? 0),
            'pendiente' => (float) ($resTor['pendiente_monto'] ?? 0),
            'inscripciones' => (int) ($resTor['total'] ?? 0),
            'confirmados' => (int) ($resTor['confirmados'] ?? 0),
            'pendientes_cnt' => (int) ($resTor['pendientes'] ?? 0),
        ];
        $historial = FinanzasAsociacionData::historialInscripcionesTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin, 400);
        $historialPagosTorneo = FinanzasAsociacionData::historialReportesPagoTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin, 400);
    } else {
        $cardAfili = FinanzasAsociacionData::totalesMovimientoConcepto($pdo, $entidadId, '(m.afiliacion + m.anualidad)', $filtroTorneoMov);
        $cardTras = FinanzasAsociacionData::totalesMovimientoConcepto($pdo, $entidadId, 'm.traspaso', $filtroTorneoMov);
        $cardCarnet = FinanzasAsociacionData::totalesMovimientoConcepto($pdo, $entidadId, 'm.carnet', $filtroTorneoMov);
        if ($torneoIdFin > 0) {
            $resTor = FinanzasAsociacionData::resumenInscripcionesTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin);
            $cardInscr = [
                'recaudado' => (float) ($resTor['recaudado'] ?? 0),
                'pendiente' => (float) ($resTor['pendiente_monto'] ?? 0),
                'inscripciones' => (int) ($resTor['total'] ?? 0),
                'confirmados' => (int) ($resTor['confirmados'] ?? 0),
                'pendientes_cnt' => (int) ($resTor['pendientes'] ?? 0),
            ];
            $historialPagosTorneo = FinanzasAsociacionData::historialReportesPagoTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin, 400);
        } else {
            $cardInscr = FinanzasAsociacionData::totalesInscripcionesTorneoFvd($pdo, $entidadId, $orgFvd);
        }

        if ($conceptoVer !== '' && $conceptoVer !== 'inscripcion' && $conceptoVer !== 'pagos_torneo') {
            $historial = FinanzasAsociacionData::historialMovimientoPorConcepto($pdo, $entidadId, $conceptoVer, $filtroTorneoMov, 400);
        } elseif ($conceptoVer === 'inscripcion' && $torneoIdFin > 0) {
            $historial = FinanzasAsociacionData::historialInscripcionesTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin, 400);
        } elseif ($conceptoVer === 'pagos_torneo' && $torneoIdFin > 0) {
            $historial = $historialPagosTorneo;
        } elseif ($torneoIdFin > 0) {
            $historial = array_merge(
                FinanzasAsociacionData::historialInscripcionesTorneoAsociacion($pdo, $torneoIdFin, $entidadId, $clubIdFin, 200),
                $historialPagosTorneo
            );
            usort($historial, static fn (array $a, array $b): int => strcmp($b['fecha'] ?? '', $a['fecha'] ?? ''));
            $historial = array_slice($historial, 0, 400);
        } else {
            $historial = FinanzasAsociacionData::historialTransacciones($pdo, $entidadId, $orgFvd, 400);
        }
    }
}

$urlForm = AppHelpers::url('index.php');
$urlPanel = AppHelpers::dashboard('asociacion_panel', array_filter(['torneo_id' => $torneoIdFin ?: null]));
$urlInformes = $urlModulo('asociacion/informes');
?>
<div class="container-fluid py-3 fvd-finanzas-asoc-page">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item">
                <a href="<?= htmlspecialchars($modoEventoMasivo && $torneoIdFin > 0 ? $urlPanel : AppHelpers::dashboard($isAdminGeneral ? 'home' : 'asociacion_panel')) ?>">Inicio</a>
            </li>
            <?php if ($conceptoVer !== ''): ?>
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlFin()) ?>">Finanzas</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($conceptosFin[$conceptoVer]['titulo']) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item active">Finanzas por asociación</li>
            <?php endif; ?>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1 fw-bold text-primary">
                <i class="fas fa-coins me-2"></i>
                <?php if ($conceptoVer !== ''): ?>
                    <?= htmlspecialchars($conceptosFin[$conceptoVer]['titulo']) ?>
                <?php elseif ($modoEventoMasivo): ?>
                    Estado de cuenta · evento masivo
                <?php else: ?>
                    Finanzas por asociación
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0 small">
                Moneda de referencia: <strong><?= htmlspecialchars($monedaEtiqueta) ?></strong>.
                <?php if ($torneoIdFin > 0 && $torneoNombreFin !== ''): ?>
                    Torneo activo: <strong><?= htmlspecialchars($torneoNombreFin) ?></strong> (ID <?= (int) $torneoIdFin ?>).
                <?php endif; ?>
                <?php if ($modoEventoMasivo): ?>
                    Inscripciones de su asociación en <code>inscritos</code>.
                <?php elseif ($filtroTorneoMov > 0): ?>
                    Movimientos filtrados al torneo activo.
                <?php else: ?>
                    Consolidado administrativo (sin pasarelas). Importes en <code>movimiento_torneo</code> e inscripciones FVD.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($torneoIdFin > 0): ?>
                <a href="<?= htmlspecialchars($urlModulo('reportes_pago_usuarios', ['torneo_id' => $torneoIdFin])) ?>" class="btn btn-sm btn-success">
                    <i class="fas fa-hand-holding-usd me-1"></i>Gestionar pagos
                </a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($urlInformes) ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-chart-bar me-1"></i>Informes FVD
            </a>
            <?php if (!$isAdminGeneral): ?>
                <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-th-large me-1"></i>Panel
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alerta !== ''): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($alerta) ?></div>
    <?php endif; ?>

    <?php if ($isAdminGeneral): ?>
        <div class="card border-primary shadow-sm mb-3">
            <div class="card-header bg-primary text-white py-2">
                <i class="fas fa-map-marked-alt me-2"></i>Asociación (entidad)
            </div>
            <div class="card-body py-2">
                <form method="get" action="<?= htmlspecialchars($urlForm) ?>" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="finanzas/resumen_asociacion">
                    <?php if ($torneoIdFin > 0): ?>
                        <input type="hidden" name="torneo_id" value="<?= (int) $torneoIdFin ?>">
                    <?php endif; ?>
                    <?php if ($conceptoVer !== ''): ?>
                        <input type="hidden" name="concepto" value="<?= htmlspecialchars($conceptoVer) ?>">
                    <?php endif; ?>
                    <div class="col-md-8 col-lg-6">
                        <label for="entidad_id" class="form-label fw-semibold small mb-1">Seleccionar asociación</label>
                        <select name="entidad_id" id="entidad_id" class="form-select form-select-sm" required onchange="this.form.submit()">
                            <option value="">— Elija una entidad —</option>
                            <?php foreach ($entidadesLista as $en): ?>
                                <option value="<?= (int) $en['id'] ?>" <?= $entidadId === (int) $en['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($en['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?> (ID <?= (int) $en['id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                <?php if (!$tablaMov): ?>
                    <p class="text-warning small mb-0 mt-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Falta <code>movimiento_torneo</code> (<code>sql/create_movimiento_torneo.sql</code>).
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($entidadNombre !== ''): ?>
        <div class="alert alert-info py-2 small mb-3">
            <i class="fas fa-shield-alt me-1"></i>
            <strong><?= htmlspecialchars($entidadNombre) ?></strong> (entidad <?= (int) $entidadId ?>)
        </div>
    <?php endif; ?>

    <?php if ($entidadId <= 0 && $isAdminGeneral): ?>
        <div class="alert alert-secondary">Seleccione una asociación para cargar indicadores e historial.</div>
    <?php elseif ($entidadId <= 0): ?>
        <div class="alert alert-danger">Su usuario no tiene una entidad (asociación) asignada.</div>
    <?php else: ?>

        <?php if ($conceptoVer === ''): ?>
        <div class="row g-2 mb-3">
            <?php if (!$modoEventoMasivo): ?>
            <?php
            $cardsResumen = [
                ['key' => 'afiliacion', 'data' => $cardAfili, 'border' => 'primary'],
                ['key' => 'traspaso', 'data' => $cardTras, 'border' => 'info'],
                ['key' => 'carnet', 'data' => $cardCarnet, 'border' => 'warning'],
            ];
            foreach ($cardsResumen as $cr):
                $cfg = $conceptosFin[$cr['key']];
                $dat = $cr['data'];
                $extraPago = $cfg['pago_extra'] ?? [];
            ?>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm border-start border-<?= $cr['border'] ?> border-4">
                    <div class="card-body py-2">
                        <div class="text-muted small text-uppercase fw-semibold mb-1"><?= htmlspecialchars($cfg['titulo']) ?></div>
                        <div class="fs-6 fw-bold text-success"><?= $fmtMoney((float) $dat['recaudado']) ?> <span class="small fw-normal text-muted">recaudado</span></div>
                        <div class="small text-warning fw-bold"><?= $fmtMoney((float) $dat['pendiente']) ?> <span class="fw-normal text-muted">pendiente</span></div>
                        <div class="small text-muted"><?= (int) $dat['registros'] ?> movimientos</div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <a href="<?= htmlspecialchars($urlFin(['concepto' => $cr['key']])) ?>" class="btn btn-xs btn-outline-<?= $cr['border'] ?> btn-sm py-0">Ver detalle</a>
                            <a href="<?= htmlspecialchars($urlModulo($cfg['reporte'], $cfg['reporte_extra'] ?? [])) ?>" class="btn btn-xs btn-outline-secondary btn-sm py-0"><?= htmlspecialchars($cfg['reporte_label']) ?></a>
                            <a href="<?= htmlspecialchars($urlModulo($cfg['pago'], $extraPago)) ?>" class="btn btn-xs btn-<?= $cr['border'] ?> btn-sm py-0"><?= htmlspecialchars($cfg['pago_label']) ?></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php $cfgInscr = $conceptosFin['inscripcion']; ?>
            <div class="<?= $modoEventoMasivo ? 'col-md-12' : 'col-md-6 col-xl-3' ?>">
                <div class="card h-100 border-0 shadow-sm border-start border-success border-4">
                    <div class="card-body py-2">
                        <div class="text-muted small text-uppercase fw-semibold mb-1"><?= $modoEventoMasivo ? 'Inscripciones del evento' : $cfgInscr['titulo'] ?></div>
                        <div class="fs-6 fw-bold text-success"><?= $fmtMoney((float) $cardInscr['recaudado']) ?> <span class="small fw-normal text-muted">recaudado</span></div>
                        <div class="small text-warning fw-bold"><?= $fmtMoney((float) $cardInscr['pendiente']) ?> <span class="fw-normal text-muted">pendiente</span></div>
                        <div class="small text-muted">
                            <?= (int) $cardInscr['inscripciones'] ?> inscripciones
                            <?php if (isset($cardInscr['confirmados'])): ?>
                                · <?= (int) $cardInscr['confirmados'] ?> confirmados · <?= (int) ($cardInscr['pendientes_cnt'] ?? 0) ?> pendientes
                            <?php endif; ?>
                        </div>
                        <?php if ($torneoIdFin > 0): ?>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <a href="<?= htmlspecialchars($urlFin(['concepto' => 'inscripcion'])) ?>" class="btn btn-outline-success btn-sm py-0">Ver detalle</a>
                            <a href="<?= htmlspecialchars($urlModulo($cfgInscr['reporte'], ['action' => 'inscripciones', 'torneo_id' => $torneoIdFin])) ?>" class="btn btn-outline-secondary btn-sm py-0">Inscripciones</a>
                            <a href="<?= htmlspecialchars($urlModulo('reportes_pago_usuarios', ['torneo_id' => $torneoIdFin])) ?>" class="btn btn-success btn-sm py-0">Reportar / validar pago</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($torneoIdFin > 0 && $historialPagosTorneo !== []): ?>
            <div class="col-md-12">
                <div class="card border-0 shadow-sm border-start border-secondary border-4">
                    <div class="card-body py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <strong><i class="fas fa-receipt me-1"></i>Pagos reportados en el torneo activo</strong>
                            <span class="text-muted small ms-1">(<?= count($historialPagosTorneo) ?> registros)</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <a href="<?= htmlspecialchars($urlFin(['concepto' => 'pagos_torneo'])) ?>" class="btn btn-outline-secondary btn-sm">Ver listado</a>
                            <a href="<?= htmlspecialchars($urlModulo('reportes_pago_usuarios', ['torneo_id' => $torneoIdFin])) ?>" class="btn btn-success btn-sm">Gestionar pagos</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <?php $cfgDet = $conceptosFin[$conceptoVer]; ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">Detalle solicitado desde el resumen financiero</span>
                <div class="d-flex flex-wrap gap-1">
                    <a href="<?= htmlspecialchars($urlFin()) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al resumen</a>
                    <a href="<?= htmlspecialchars($urlModulo($cfgDet['reporte'], $cfgDet['reporte_extra'] ?? ['torneo_id' => $torneoIdFin])) ?>" class="btn btn-outline-primary btn-sm"><?= htmlspecialchars($cfgDet['reporte_label']) ?></a>
                    <a href="<?= htmlspecialchars($urlModulo($cfgDet['pago'], array_merge($cfgDet['pago_extra'] ?? [], $torneoIdFin > 0 ? ['torneo_id' => $torneoIdFin] : []))) ?>" class="btn btn-primary btn-sm"><?= htmlspecialchars($cfgDet['pago_label']) ?></a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-bold text-primary mb-0 small">
                    <i class="fas fa-list-ul me-1"></i>
                    <?php
                    if ($conceptoVer === 'pagos_torneo') {
                        echo 'Pagos realizados / reportados';
                    } elseif ($conceptoVer === 'inscripcion') {
                        echo 'Detalle de inscripciones';
                    } elseif ($conceptoVer !== '') {
                        echo 'Detalle de movimientos';
                    } elseif ($modoEventoMasivo || $torneoIdFin > 0) {
                        echo 'Movimientos del torneo activo';
                    } else {
                        echo 'Historial de transacciones';
                    }
                    ?>
                </span>
                <span class="small text-muted"><?= count($historial) ?> registros</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Fecha</th>
                                <th scope="col">Atleta / Club</th>
                                <th scope="col">Concepto</th>
                                <th scope="col" class="text-end"><?= htmlspecialchars($monedaEtiqueta) ?></th>
                                <th scope="col">Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($historial === []): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4 small">Sin registros para este criterio.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historial as $h): ?>
                                    <tr>
                                        <td class="text-nowrap small"><?= htmlspecialchars($h['fecha'] ?? '') ?></td>
                                        <td class="small"><?= htmlspecialchars($h['atleta_club'] ?? '') ?></td>
                                        <td class="small"><?= htmlspecialchars($h['concepto'] ?? '') ?></td>
                                        <td class="text-end fw-semibold small"><?= $fmtMoney((float) ($h['monto'] ?? 0)) ?></td>
                                        <td>
                                            <?php
                                            $est = (string) ($h['estatus'] ?? '');
                                            if ($est === 'Pagado') {
                                                $badge = 'bg-success';
                                            } elseif ($est === 'Pendiente') {
                                                $badge = 'bg-warning text-dark';
                                            } elseif ($est === 'Rechazado') {
                                                $badge = 'bg-danger';
                                            } else {
                                                $badge = 'bg-secondary';
                                            }
                                            ?>
                                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($est) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>
