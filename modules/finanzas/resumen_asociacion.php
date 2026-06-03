<?php

declare(strict_types=1);

/**
 * Finanzas consolidadas por asociación (código entidad).
 * - admin_general (FVD): selector de entidad vía GET entidad_id.
 * - Delegado de asociación / admin_club con entidad en sesión: solo su entidad (ignora entidad_id en URL).
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
$monedaEtiqueta = 'USD';
$tablaMov = FinanzasAsociacionData::tablaExiste($pdo, 'movimiento_torneo');

$cardAfili = ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
$cardTras = ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
$cardCarnet = ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
$cardInscr = ['recaudado' => 0.0, 'pendiente' => 0.0, 'inscripciones' => 0];
$historial = [];
$torneoIdFin = (int) ($_GET['torneo_id'] ?? 0);
$modoEventoMasivo = !empty($_GET['evento_masivo']);
$torneoNombreFin = '';
$clubIdFin = $clubOperativo !== null ? (int) ($clubOperativo['id'] ?? 0) : 0;

if ($entidadId > 0) {
    if ($torneoIdFin > 0) {
        $stTor = $pdo->prepare('SELECT nombre, COALESCE(es_evento_masivo, 0) AS es_evento_masivo FROM tournaments WHERE id = ? LIMIT 1');
        $stTor->execute([$torneoIdFin]);
        $torRow = $stTor->fetch(PDO::FETCH_ASSOC);
        if ($torRow && (int) ($torRow['es_evento_masivo'] ?? 0) > 0) {
            $modoEventoMasivo = true;
        }
        $torneoNombreFin = trim((string) ($torRow['nombre'] ?? ''));
    }
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
    } else {
        $cardAfili = FinanzasAsociacionData::totalesMovimientoConcepto($pdo, $entidadId, '(m.afiliacion + m.anualidad)');
        $cardTras = FinanzasAsociacionData::totalesMovimientoConcepto($pdo, $entidadId, 'm.traspaso');
        $cardCarnet = FinanzasAsociacionData::totalesMovimientoConcepto($pdo, $entidadId, 'm.carnet');
        $cardInscr = FinanzasAsociacionData::totalesInscripcionesTorneoFvd($pdo, $entidadId, $orgFvd);
        $historial = FinanzasAsociacionData::historialTransacciones($pdo, $entidadId, $orgFvd, 400);
    }
}

$fmtMoney = static function (float $n): string {
    return '$' . number_format($n, 2, '.', ',');
};

$urlForm = AppHelpers::url('index.php');
?>
<div class="container-fluid py-3">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($modoEventoMasivo && $torneoIdFin > 0 ? AppHelpers::dashboard('asociacion_panel', ['tab' => 2, 'torneo_id' => $torneoIdFin]) : AppHelpers::dashboard($isAdminGeneral ? 'home' : 'asociacion_panel')) ?>">Inicio</a></li>
            <li class="breadcrumb-item active" aria-current="page">Finanzas por asociación</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1 fw-bold text-primary">
                <i class="fas fa-coins me-2"></i><?= $modoEventoMasivo ? 'Estado de cuenta · evento masivo' : 'Finanzas por asociación' ?>
            </h1>
            <p class="text-muted mb-0 small">
                <?php if ($modoEventoMasivo && $torneoNombreFin !== ''): ?>
                    Inscripciones de su asociación en <strong><?= htmlspecialchars($torneoNombreFin) ?></strong> (tabla <code>inscritos</code>). Torneo organizado por la FVD.
                <?php else: ?>
                    Consolidado administrativo (sin pasarelas de pago). Moneda de referencia: <strong><?= htmlspecialchars($monedaEtiqueta) ?></strong>.
                    Los importes de afiliaciones, traspasos y carnets provienen de la tabla <code>movimiento_torneo</code> cuando existe en la base de datos.
                <?php endif; ?>
            </p>
            <?php if ($modoEventoMasivo): ?>
                <a href="<?= htmlspecialchars(AppHelpers::dashboard('asociacion_panel', ['tab' => 2, 'torneo_id' => $torneoIdFin])) ?>" class="btn btn-sm btn-outline-primary mt-2">
                    <i class="fas fa-arrow-left me-1"></i>Volver al panel
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alerta !== ''): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($alerta) ?></div>
    <?php endif; ?>

    <?php if ($isAdminGeneral): ?>
        <div class="card border-primary shadow-sm mb-4">
            <div class="card-header bg-primary text-white py-2">
                <i class="fas fa-map-marked-alt me-2"></i>Asociación (entidad)
            </div>
            <div class="card-body">
                <form method="get" action="<?= htmlspecialchars($urlForm) ?>" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="finanzas/resumen_asociacion">
                    <div class="col-md-8 col-lg-6">
                        <label for="entidad_id" class="form-label fw-semibold">Seleccionar asociación</label>
                        <select name="entidad_id" id="entidad_id" class="form-select" required onchange="this.form.submit()">
                            <option value="">— Elija una entidad —</option>
                            <?php foreach ($entidadesLista as $en): ?>
                                <option value="<?= (int) $en['id'] ?>" <?= $entidadId === (int) $en['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($en['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?> (ID <?= (int) $en['id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-sync-alt me-1"></i>Consultar
                        </button>
                    </div>
                </form>
                <?php if (!$tablaMov): ?>
                    <p class="text-warning small mb-0 mt-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        La tabla <code>movimiento_torneo</code> no está creada: las tarjetas de afiliaciones, traspasos y carnets mostrarán cero hasta ejecutar el SQL del proyecto (<code>sql/create_movimiento_torneo.sql</code>).
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info shadow-sm mb-4">
            <i class="fas fa-shield-alt me-2"></i>
            <strong>Vista restringida:</strong> solo se muestran datos de su asociación
            <?php if ($entidadNombre !== ''): ?>
                (<strong><?= htmlspecialchars($entidadNombre) ?></strong>, entidad ID <?= (int) $entidadId ?>).
            <?php elseif ($entidadId > 0): ?>
                (entidad ID <?= (int) $entidadId ?>).
            <?php endif; ?>
            <?php if (!$tablaMov): ?>
                <span class="d-block mt-2 small">La tabla <code>movimiento_torneo</code> no existe aún en esta base de datos.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($entidadId <= 0 && $isAdminGeneral): ?>
        <div class="alert alert-secondary">Seleccione una asociación para cargar indicadores e historial.</div>
    <?php elseif ($entidadId <= 0): ?>
        <div class="alert alert-danger">Su usuario no tiene una entidad (asociación) asignada. Contacte al administrador FVD.</div>
    <?php else: ?>

        <div class="row g-3 mb-4">
            <?php if (!$modoEventoMasivo): ?>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-1">Afiliaciones y anualidades</div>
                        <div class="fs-5 fw-bold text-success"><?= $fmtMoney($cardAfili['recaudado']) ?> <span class="small fw-normal text-muted">recaudado</span></div>
                        <div class="fs-6 text-warning fw-bold"><?= $fmtMoney($cardAfili['pendiente']) ?> <span class="small fw-normal text-muted">pendiente</span></div>
                        <div class="small text-muted mt-2"><?= (int) $cardAfili['registros'] ?> movimientos</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-1">Traspasos</div>
                        <div class="fs-5 fw-bold text-success"><?= $fmtMoney($cardTras['recaudado']) ?> <span class="small fw-normal text-muted">recaudado</span></div>
                        <div class="fs-6 text-warning fw-bold"><?= $fmtMoney($cardTras['pendiente']) ?> <span class="small fw-normal text-muted">pendiente</span></div>
                        <div class="small text-muted mt-2"><?= (int) $cardTras['registros'] ?> movimientos</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-1">Carnets / credenciales</div>
                        <div class="fs-5 fw-bold text-success"><?= $fmtMoney($cardCarnet['recaudado']) ?> <span class="small fw-normal text-muted">recaudado</span></div>
                        <div class="fs-6 text-warning fw-bold"><?= $fmtMoney($cardCarnet['pendiente']) ?> <span class="small fw-normal text-muted">pendiente</span></div>
                        <div class="small text-muted mt-2"><?= (int) $cardCarnet['registros'] ?> movimientos</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="<?= $modoEventoMasivo ? 'col-md-12' : 'col-md-6 col-xl-3' ?>">
                <div class="card h-100 border-0 shadow-sm border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-1"><?= $modoEventoMasivo ? 'Inscripciones del evento (inscritos)' : 'Inscripciones (torneos FVD)' ?></div>
                        <div class="fs-5 fw-bold text-success"><?= $fmtMoney($cardInscr['recaudado']) ?> <span class="small fw-normal text-muted">recaudado</span></div>
                        <div class="fs-6 text-warning fw-bold"><?= $fmtMoney($cardInscr['pendiente']) ?> <span class="small fw-normal text-muted">pendiente</span></div>
                        <div class="small text-muted mt-2">
                            <?= (int) $cardInscr['inscripciones'] ?> inscripciones
                            <?php if ($modoEventoMasivo): ?>
                                · <?= (int) ($cardInscr['confirmados'] ?? 0) ?> confirmados · <?= (int) ($cardInscr['pendientes_cnt'] ?? 0) ?> pendientes
                            <?php else: ?>
                                · org. <?= (int) $orgFvd ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-bold text-primary mb-0"><i class="fas fa-list-ul me-2"></i><?= $modoEventoMasivo ? 'Detalle de inscripciones' : 'Historial de transacciones' ?></span>
                <span class="small text-muted"><?= count($historial) ?> registros mostrados</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Fecha</th>
                                <th scope="col">Atleta / Club</th>
                                <th scope="col">Concepto</th>
                                <th scope="col" class="text-end">Monto (<?= htmlspecialchars($monedaEtiqueta) ?>)</th>
                                <th scope="col">Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($historial === []): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-5"><?= $modoEventoMasivo ? 'No hay inscripciones de su asociación en este evento.' : 'No hay movimientos ni reportes de pago registrados para esta asociación.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historial as $h): ?>
                                    <tr>
                                        <td class="text-nowrap small"><?= htmlspecialchars($h['fecha'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($h['atleta_club'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($h['concepto'] ?? '') ?></td>
                                        <td class="text-end fw-semibold"><?= $fmtMoney((float) ($h['monto'] ?? 0)) ?></td>
                                        <td>
                                            <?php
                                            $est = (string) ($h['estatus'] ?? '');
                                            $badge = 'bg-secondary';
                                            if ($est === 'Pagado') {
                                                $badge = 'bg-success';
                                            } elseif ($est === 'Pendiente') {
                                                $badge = 'bg-warning text-dark';
                                            } elseif ($est === 'Rechazado') {
                                                $badge = 'bg-danger';
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
