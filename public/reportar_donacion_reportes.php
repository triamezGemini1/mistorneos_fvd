<?php
/**
 * Reportar pago por donación para habilitar reportes personales (PDF ranking).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/FvdAdminGate.php';
FvdAdminGate::rejectPublicScriptIfDisabled('reportar_donacion_reportes.php');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../lib/ReportePagoDonacionService.php';
require_once __DIR__ . '/../lib/RankingAtletasPdfAccesoHelper.php';
require_once __DIR__ . '/../lib/app_helpers.php';

$pdo = DB::pdo();
$baseUrl = function_exists('app_base_url') ? app_base_url() : '';

$sessionUser = null;
$userId = 0;
if (AuthService::isAuthenticated()) {
    $sessionUser = AuthService::user();
    $userId = (int) ($sessionUser['id'] ?? 0);
}

$error = '';
$success = '';
$misReportes = [];

if (! ReportePagoDonacionService::tablaDisponible($pdo)) {
    $error = 'El sistema de reportes de donación no está disponible temporalmente.';
}

$yaHabilitado = $userId > 0 && RankingAtletasPdfAccesoHelper::usuarioTienePermisoReportes($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    CSRF::validate();

    $numfvdPost = (int) ($_POST['numfvd'] ?? 0);
    $idPost = (int) ($_POST['id_usuario'] ?? 0);
    $idReporte = $userId > 0 ? $userId : ($idPost > 0 ? $idPost : 0);

    $res = ReportePagoDonacionService::crearReporte($pdo, [
        'id_usuario' => $idReporte,
        'numfvd_reportado' => $numfvdPost,
        'fecha' => trim((string) ($_POST['fecha'] ?? date('Y-m-d'))),
        'hora' => trim((string) ($_POST['hora'] ?? date('H:i'))),
        'tipo_pago' => trim((string) ($_POST['tipo_pago'] ?? '')),
        'banco' => trim((string) ($_POST['banco'] ?? '')),
        'monto' => (float) ($_POST['monto'] ?? 0),
        'referencia' => trim((string) ($_POST['referencia'] ?? '')),
        'comentarios' => trim((string) ($_POST['comentarios'] ?? '')),
    ]);

    if ($res['ok']) {
        $success = $res['message'];
    } else {
        $error = $res['message'];
    }
}

if ($userId > 0 && ReportePagoDonacionService::tablaDisponible($pdo)) {
    $st = $pdo->prepare(
        'SELECT * FROM reportes_pago_donacion
         WHERE id_usuario = ? OR id_usuario_resuelto = ?
         ORDER BY created_at DESC LIMIT 20'
    );
    $st->execute([$userId, $userId]);
    $misReportes = $st->fetchAll(PDO::FETCH_ASSOC);
}

$numfvdSesion = (int) ($sessionUser['numfvd'] ?? 0);
$csrf_token = CSRF::token();
$portalUrl = class_exists('AppHelpers') ? AppHelpers::url('user_portal.php') : 'user_portal.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportar donación — Reportes personales FVD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1a5f4a 0%, #0d3d32 100%); min-height: 100vh; padding: 1.5rem 0; }
        .card-main { border-radius: 1rem; box-shadow: 0 12px 40px rgba(0,0,0,.25); }
    </style>
</head>
<body>
<div class="container" style="max-width: 720px;">
    <div class="card card-main">
        <div class="card-header bg-success text-white py-3">
            <h1 class="h4 mb-0"><i class="fas fa-hand-holding-heart me-2"></i>Reportar pago por donación</h1>
            <p class="small mb-0 mt-1 opacity-75">Para habilitar sus reportes personales en PDF del ranking FVD</p>
        </div>
        <div class="card-body p-4">
            <?php if ($yaHabilitado): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Ya tiene habilitados los reportes personales.
                    <a href="<?= htmlspecialchars($portalUrl . '?section=reportes_personales') ?>" class="alert-link">Ir al portal</a>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($error === '' || $success !== ''): ?>
            <form method="post" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <?php if ($userId > 0): ?>
                    <div class="alert alert-light border mb-3">
                        <strong>Sesión:</strong> <?= htmlspecialchars((string) ($sessionUser['nombre'] ?? '')) ?>
                        · ID <?= $userId ?>
                        <?php if ($numfvdSesion > 0): ?> · NUMFVD <?= $numfvdSesion ?><?php endif; ?>
                    </div>
                    <input type="hidden" name="id_usuario" value="<?= $userId ?>">
                    <?php if ($numfvdSesion > 0): ?>
                    <input type="hidden" name="numfvd" value="<?= $numfvdSesion ?>">
                    <?php endif; ?>
                <?php else: ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ID usuario <span class="text-muted">(opcional si indica NUMFVD)</span></label>
                            <input type="number" name="id_usuario" class="form-control" min="1" placeholder="Ej. 1523">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">NUMFVD <span class="text-danger">*</span></label>
                            <input type="number" name="numfvd" class="form-control" min="1" required placeholder="Ej. 2701">
                        </div>
                    </div>
                    <p class="small text-muted">Indique al menos uno: ID de usuario o NUMFVD registrado en la FVD.</p>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Fecha del pago <span class="text-danger">*</span></label>
                        <input type="date" name="fecha" class="form-control" required value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hora <span class="text-danger">*</span></label>
                        <input type="time" name="hora" class="form-control" required value="<?= htmlspecialchars(date('H:i')) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Tipo de pago <span class="text-danger">*</span></label>
                    <select name="tipo_pago" id="tipo_pago" class="form-select" required>
                        <option value="">Seleccionar…</option>
                        <option value="pagomovil">Pago móvil</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="zelle">Zelle</option>
                        <option value="efectivo">Efectivo</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>

                <div class="mb-3" id="grupo_banco" style="display:none;">
                    <label class="form-label">Banco</label>
                    <input type="text" name="banco" class="form-control" placeholder="Nombre del banco">
                </div>

                <div class="mb-3">
                    <label class="form-label">Monto (USD) <span class="text-danger">*</span></label>
                    <input type="number" name="monto" class="form-control" step="0.01" min="0.01" required>
                </div>

                <div class="mb-3" id="grupo_referencia" style="display:none;">
                    <label class="form-label">Número de referencia <span class="text-danger">*</span></label>
                    <input type="text" name="referencia" id="referencia" class="form-control" placeholder="Referencia de la operación">
                </div>

                <div class="mb-3">
                    <label class="form-label">Comentarios</label>
                    <textarea name="comentarios" class="form-control" rows="2" placeholder="Opcional"></textarea>
                </div>

                <button type="submit" class="btn btn-success btn-lg w-100" <?= $yaHabilitado ? 'disabled' : '' ?>>
                    <i class="fas fa-paper-plane me-2"></i>Enviar reporte de pago
                </button>
            </form>
            <?php endif; ?>

            <?php if ($misReportes !== []): ?>
            <hr class="my-4">
            <h2 class="h6 text-muted">Mis reportes enviados</h2>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>#</th><th>Fecha</th><th>Monto</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($misReportes as $mr): ?>
                        <tr>
                            <td><?= (int) $mr['id'] ?></td>
                            <td><?= date('d/m/Y', strtotime((string) $mr['fecha'])) ?></td>
                            <td>$<?= number_format((float) $mr['monto'], 2) ?></td>
                            <td>
                                <?php
                                $est = (string) ($mr['estatus'] ?? '');
                                $badge = $est === 'confirmado' ? 'success' : ($est === 'rechazado' ? 'danger' : 'warning');
                                ?>
                                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($est) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="<?= htmlspecialchars($portalUrl) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver al portal
                </a>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
  var tipo = document.getElementById('tipo_pago');
  var grpRef = document.getElementById('grupo_referencia');
  var grpBanco = document.getElementById('grupo_banco');
  var ref = document.getElementById('referencia');
  if (!tipo) return;
  function sync() {
    var v = tipo.value;
    var needRef = v === 'transferencia' || v === 'pagomovil' || v === 'zelle';
    var needBank = v === 'transferencia' || v === 'pagomovil';
    grpRef.style.display = needRef ? 'block' : 'none';
    grpBanco.style.display = needBank ? 'block' : 'none';
    if (ref) ref.required = needRef;
  }
  tipo.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
