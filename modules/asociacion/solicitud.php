<?php

declare(strict_types=1);

/**
 * Solicitudes operativas de asociación → movimiento_torneo (pendiente FVD).
 * Tipos: afiliacion, traspaso, carnet.
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('asociacion/solicitud');

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

if (!AsociacionAdminHelper::usuarioTieneAccesoModulosAsociacion()) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4">Acceso restringido al administrador de asociación.</div>';
    return;
}

$pdo = DB::pdo();
$club = Auth::clubOperativoAsociacion();
if ($club === null) {
    echo '<div class="alert alert-warning m-4">No se encontró la asociación asignada a su usuario.</div>';
    return;
}

$tiposPermitidos = [
    'afiliacion' => 'Nueva afiliación',
    'traspaso' => 'Traspaso de atleta',
    'carnet' => 'Carnet / credencial',
];
$tipo = trim((string) ($_GET['tipo'] ?? 'afiliacion'));
if (!isset($tiposPermitidos[$tipo])) {
    $tipo = 'afiliacion';
}
$torneoId = (int) ($_GET['torneo_id'] ?? 0);
$urlPanel = AppHelpers::dashboard('asociacion_panel', array_filter(['torneo_id' => $torneoId ?: null]));
$mensaje = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::validate();
    $tipoPost = trim((string) ($_POST['tipo'] ?? ''));
    if (!isset($tiposPermitidos[$tipoPost])) {
        $error = 'Tipo de solicitud no válido.';
    } else {
        try {
            $idMov = AsociacionAdminHelper::registrarSolicitudMovimiento($pdo, $club, [
                'tipo' => $tipoPost,
                'id_usuario' => (int) ($_POST['id_usuario'] ?? 0),
                'cedula' => trim((string) ($_POST['cedula'] ?? '')),
                'monto' => (float) ($_POST['monto'] ?? 0),
                'torneo_id' => (int) ($_POST['torneo_id'] ?? 0),
                'nota' => trim((string) ($_POST['nota'] ?? '')),
            ]);
            $mensaje = 'Solicitud registrada (ref. #' . $idMov . '). La FVD la validará administrativamente.';
            $tipo = $tipoPost;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf = CSRF::token();
?>
<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlPanel) ?>">Panel asociación</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($tiposPermitidos[$tipo]) ?></li>
        </ol>
    </nav>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <h1 class="h4 fw-bold mb-3"><?= htmlspecialchars($tiposPermitidos[$tipo]) ?></h1>
    <p class="text-muted">Asociación: <strong><?= htmlspecialchars((string) ($club['nombre'] ?? '')) ?></strong>. La solicitud queda pendiente hasta validación de la FVD.</p>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="post" class="row g-3" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
                <?php if ($torneoId > 0): ?>
                    <input type="hidden" name="torneo_id" value="<?= (int) $torneoId ?>">
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" disabled>
                        <?php foreach ($tiposPermitidos as $k => $label): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $k === $tipo ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Cambie el tipo desde el panel si necesita otro trámite.</div>
                </div>

                <div class="col-md-4">
                    <label for="cedula" class="form-label">Cédula del atleta</label>
                    <input type="text" class="form-control" id="cedula" name="cedula" maxlength="20" required>
                </div>
                <div class="col-md-4">
                    <label for="id_usuario" class="form-label">ID usuario (opcional)</label>
                    <input type="number" class="form-control" id="id_usuario" name="id_usuario" min="0" placeholder="Si ya está en el sistema">
                </div>
                <div class="col-md-4">
                    <label for="monto" class="form-label">Monto referencial (opcional)</label>
                    <input type="number" class="form-control" id="monto" name="monto" min="0" step="0.01" value="0">
                </div>
                <div class="col-12">
                    <label for="nota" class="form-label">Observaciones</label>
                    <textarea class="form-control" id="nota" name="nota" rows="2" placeholder="Detalle del traspaso, club de origen, etc."></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Enviar solicitud</button>
                    <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="btn-group flex-wrap">
        <?php foreach ($tiposPermitidos as $k => $label): ?>
            <?php if ($k === $tipo) {
                continue;
            } ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(AppHelpers::dashboard('asociacion/solicitud', ['tipo' => $k] + ($torneoId ? ['torneo_id' => $torneoId] : []))) ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
