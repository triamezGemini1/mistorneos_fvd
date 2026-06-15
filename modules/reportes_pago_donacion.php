<?php
/**
 * Gestión de reportes de pago por donación → activar reportes personales del atleta.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/ReportePagoDonacionService.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../config/deploy_build.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$error = '';
$success = '';

if (! ReportePagoDonacionService::tablaDisponible($pdo)) {
    $error = 'Ejecute sql/create_reportes_pago_donacion.sql en la base de datos.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') !== '1' && $error === '') {
    CSRF::validate();
    $accion = $_POST['accion'] ?? '';
    $reporteId = (int) ($_POST['reporte_id'] ?? 0);
    if ($reporteId > 0 && $accion === 'rechazar') {
        $notas = trim((string) ($_POST['notas_admin'] ?? ''));
        $res = ReportePagoDonacionService::rechazar($pdo, $reporteId, $notas);
        if ($res['ok']) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

$filtroEstatus = $_GET['estatus'] ?? 'pendiente';
if (! in_array($filtroEstatus, ['todos', 'pendiente', 'confirmado', 'rechazado'], true)) {
    $filtroEstatus = 'pendiente';
}
$filtroBusqueda = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($filtroEstatus !== 'todos') {
    $where[] = 'rpd.estatus = ?';
    $params[] = $filtroEstatus;
}
if ($filtroBusqueda !== '') {
    $like = '%' . $filtroBusqueda . '%';
    $searchParts = [
        'u.nombre LIKE ?',
        'ur.nombre LIKE ?',
        'u.cedula LIKE ?',
        'ur.cedula LIKE ?',
        'CAST(COALESCE(rpd.id_usuario, 0) AS CHAR) LIKE ?',
        'CAST(COALESCE(rpd.numfvd_reportado, 0) AS CHAR) LIKE ?',
        'CAST(COALESCE(ur.numfvd, u.numfvd, 0) AS CHAR) LIKE ?',
        'rpd.referencia LIKE ?',
    ];
    $params = array_merge($params, array_fill(0, count($searchParts), $like));
    if (ctype_digit($filtroBusqueda)) {
        $n = (int) $filtroBusqueda;
        $searchParts[] = 'rpd.id = ?';
        $searchParts[] = 'rpd.id_usuario = ?';
        $searchParts[] = 'rpd.numfvd_reportado = ?';
        $searchParts[] = 'ur.id = ?';
        $searchParts[] = 'u.id = ?';
        array_push($params, $n, $n, $n, $n, $n);
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$hasEntidad = false;
try {
    $hasEntidad = (bool) $pdo->query("SHOW TABLES LIKE 'entidad'")->fetchColumn();
} catch (Throwable $e) {
    $hasEntidad = false;
}
$entidadJoin = $hasEntidad ? 'LEFT JOIN entidad e ON e.id = COALESCE(ur.entidad, u.entidad)' : '';
$entidadSelect = $hasEntidad ? ', e.nombre AS entidad_nombre' : ', NULL AS entidad_nombre';

$reportes = [];
if ($error === '') {
    $sql = "
        SELECT
            rpd.*,
            COALESCE(ur.id, u.id) AS usuario_id,
            COALESCE(ur.nombre, u.nombre) AS usuario_nombre,
            COALESCE(ur.cedula, u.cedula) AS usuario_cedula,
            COALESCE(NULLIF(ur.numfvd, 0), NULLIF(u.numfvd, 0), rpd.numfvd_reportado, 0) AS usuario_numfvd,
            COALESCE(ur.permite_reportes_personales, u.permite_reportes_personales, 0) AS ya_habilitado,
            adm.nombre AS activado_por_nombre
            {$entidadSelect}
        FROM reportes_pago_donacion rpd
        LEFT JOIN usuarios u ON rpd.id_usuario = u.id
        LEFT JOIN usuarios ur ON rpd.id_usuario_resuelto = ur.id
        LEFT JOIN usuarios adm ON rpd.activado_por = adm.id
        {$entidadJoin}
        {$whereSql}
        ORDER BY
            CASE rpd.estatus WHEN 'pendiente' THEN 0 WHEN 'confirmado' THEN 1 ELSE 2 END,
            rpd.created_at DESC
        LIMIT 500
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $reportes = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pendientes = 0;
$confirmados = 0;
if ($error === '') {
    try {
        $pendientes = (int) $pdo->query("SELECT COUNT(*) FROM reportes_pago_donacion WHERE estatus = 'pendiente'")->fetchColumn();
        $confirmados = (int) $pdo->query("SELECT COUNT(*) FROM reportes_pago_donacion WHERE estatus = 'confirmado'")->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
}

$csrf_token = CSRF::token();
$rpd_api_url = class_exists('AppHelpers')
    ? AppHelpers::url('api/reporte_donacion_admin.php')
    : 'api/reporte_donacion_admin.php';
$rpd_asset_base = class_exists('AppHelpers') ? rtrim(AppHelpers::basePath(), '/') : '';
?>
<div class="container-fluid py-3" style="max-width: 1500px;">
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h1 class="h4 mb-2">
                <i class="fas fa-hand-holding-heart me-2 text-success"></i>
                Donaciones — activar reportes personales
            </h1>
            <p class="text-muted mb-0 small">
                Revise los reportes de pago (donación) enviados por los atletas. Al confirmar, se habilita automáticamente
                <code>permite_reportes_personales</code> para el usuario identificado por <strong>ID</strong> o <strong>NUMFVD</strong>.
            </p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-warning">
                <div class="card-body py-2">
                    <span class="text-muted small">Pendientes de verificar</span>
                    <div class="fs-4 fw-bold text-warning"><?= $pendientes ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-success">
                <div class="card-body py-2">
                    <span class="text-muted small">Activados (confirmados)</span>
                    <div class="fs-4 fw-bold text-success"><?= $confirmados ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4 d-flex align-items-stretch">
            <a href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::url('reportar_donacion_reportes.php') : 'reportar_donacion_reportes.php') ?>"
               class="btn btn-outline-primary w-100 align-self-center" target="_blank" rel="noopener">
                <i class="fas fa-external-link-alt me-1"></i>Formulario público de reporte
            </a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="reportes_pago_donacion">
                <div class="col-lg-4">
                    <label class="form-label small mb-1">Buscar (nombre, cédula, ID, NUMFVD, referencia)</label>
                    <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($filtroBusqueda) ?>" placeholder="Ej. 2701 o V-12345678">
                </div>
                <div class="col-lg-5">
                    <label class="form-label small mb-1 d-block">Estado</label>
                    <div class="btn-group" role="group">
                        <?php foreach (['pendiente' => 'Pendientes', 'confirmado' => 'Confirmados', 'rechazado' => 'Rechazados', 'todos' => 'Todos'] as $val => $lbl): ?>
                        <input type="radio" class="btn-check" name="estatus" id="est_<?= $val ?>" value="<?= $val ?>" <?= $filtroEstatus === $val ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="est_<?= $val ?>"><?= $lbl ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search me-1"></i>Filtrar</button>
                    <?php if ($filtroBusqueda !== '' || $filtroEstatus !== 'pendiente'): ?>
                    <a href="?page=reportes_pago_donacion" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Notificaciones de pago
                <span class="badge bg-light text-dark ms-2"><?= count($reportes) ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if ($reportes !== []): ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Atleta</th>
                            <th>NUMFVD</th>
                            <th>Entidad</th>
                            <th>Pago</th>
                            <th>Monto</th>
                            <th>Ref.</th>
                            <th>En registro</th>
                            <th>PDF</th>
                            <th>Confirmar / activar</th>
                            <th>Estado</th>
                            <th>Opciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportes as $r): ?>
                        <?php
                        $enRegistro = (int) ($r['usuario_id'] ?? 0) > 0;
                        $yaPdf = (int) ($r['ya_habilitado'] ?? 0) === 1;
                        ?>
                        <tr>
                            <td><strong>#<?= (int) $r['id'] ?></strong></td>
                            <td>
                                <?php if ($enRegistro): ?>
                                    <?= htmlspecialchars((string) $r['usuario_nombre']) ?>
                                    <small class="d-block text-muted">ID <?= (int) $r['usuario_id'] ?></small>
                                <?php else: ?>
                                    <span class="text-danger">Sin usuario</span>
                                    <small class="d-block text-muted">NUMFVD reportado: <?= (int) ($r['numfvd_reportado'] ?? 0) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><code><?= (int) ($r['usuario_numfvd'] ?? 0) ?></code></td>
                            <td><?= htmlspecialchars((string) ($r['entidad_nombre'] ?? '—')) ?></td>
                            <td>
                                <?= ! empty($r['fecha']) ? date('d/m/Y', strtotime((string) $r['fecha'])) : '—' ?>
                                <small class="d-block text-muted"><?= htmlspecialchars(substr((string) ($r['hora'] ?? ''), 0, 5)) ?></small>
                                <span class="badge bg-info"><?= htmlspecialchars((string) $r['tipo_pago']) ?></span>
                            </td>
                            <td><strong class="text-success">$<?= number_format((float) $r['monto'], 2) ?></strong></td>
                            <td><code><?= htmlspecialchars((string) ($r['referencia'] ?? '—')) ?></code></td>
                            <td class="text-center">
                                <?php if ($enRegistro): ?>
                                    <span class="badge bg-success"><i class="fas fa-check"></i> Sí</span>
                                <?php else: ?>
                                    <span class="badge bg-danger" title="No hay usuario con ese ID/NUMFVD">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?= $yaPdf ? '<span class="badge bg-primary">Activo</span>' : '<span class="badge bg-secondary">No</span>' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($r['estatus'] !== 'rechazado'): ?>
                                <div class="form-check form-switch d-inline-flex align-items-center justify-content-center mb-0">
                                    <input type="checkbox" class="form-check-input rpd-switch-confirmado" role="switch"
                                           data-reporte-id="<?= (int) $r['id'] ?>"
                                           <?= $r['estatus'] === 'confirmado' ? 'checked' : '' ?>
                                           <?= ! $enRegistro ? 'disabled title="Usuario no encontrado en registro"' : '' ?>>
                                </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $cls = ['pendiente' => 'bg-warning text-dark', 'confirmado' => 'bg-success', 'rechazado' => 'bg-danger'];
                                $lbl = ['pendiente' => 'Pendiente', 'confirmado' => 'Activado', 'rechazado' => 'Rechazado'];
                                $est = (string) ($r['estatus'] ?? '');
                                ?>
                                <span class="badge <?= $cls[$est] ?? 'bg-secondary' ?> rpd-estatus-badge"><?= $lbl[$est] ?? $est ?></span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-info" data-rpd-accion="ver" data-reporte-id="<?= (int) $r['id'] ?>" title="Ver recibo"><i class="fas fa-eye"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" data-rpd-accion="imprimir" data-reporte-id="<?= (int) $r['id'] ?>" title="Imprimir"><i class="fas fa-print"></i></button>
                                    <?php if ($est === 'pendiente'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Rechazar este reporte?');">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="reporte_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="accion" value="rechazar">
                                        <button type="submit" class="btn btn-outline-danger" title="Rechazar"><i class="fas fa-ban"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-inbox fs-1 mb-3 d-block"></i>
                No hay reportes con los filtros actuales.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="modalReciboDonacion" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Recibo de donación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalReciboDonacionBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-success" id="btnReciboDonacionImprimir"><i class="fas fa-print me-1"></i>Imprimir</button>
            </div>
        </div>
    </div>
</div>

<script>
window.REPORTES_DONACION_CFG = {
    apiUrl: <?= json_encode($rpd_api_url, JSON_UNESCAPED_UNICODE) ?>,
    csrf: <?= json_encode($csrf_token, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars($rpd_asset_base) ?>/assets/reportes-pago-donacion.js"></script>
