<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/SolicitudesAsociacionService.php';
require_once __DIR__ . '/../../lib/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/../../lib/app_helpers.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$tablaOk = SolicitudesAsociacionService::tablaDisponible($pdo);
$tipo = trim((string) ($_GET['tipo'] ?? 'todas'));
if (!in_array($tipo, SolicitudesAsociacionService::TIPOS_FILTRO, true)) {
    $tipo = 'todas';
}
$estatusGet = $_GET['estatus'] ?? 'pendiente';
if ($estatusGet === 'todas') {
    $estatusFiltro = null;
} elseif ($estatusGet === 'aprobada') {
    $estatusFiltro = SolicitudesAsociacionService::ESTATUS_APROBADO;
} elseif ($estatusGet === 'rechazada') {
    $estatusFiltro = SolicitudesAsociacionService::ESTATUS_RECHAZADO;
} else {
    $estatusFiltro = SolicitudesAsociacionService::ESTATUS_PENDIENTE;
}

$mensaje = '';
$error = '';

if ($tablaOk && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::validate();
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);
    $adminId = (int) (Auth::id() ?? 0);
    if ($id > 0 && $adminId > 0) {
        if ($accion === 'aprobar') {
            if (SolicitudesAsociacionService::aprobar($pdo, $id, $adminId)) {
                $mensaje = 'Solicitud #' . $id . ' aprobada. Se notificó a la asociación y al afiliado.';
            } else {
                $error = 'No se pudo aprobar la solicitud (ya procesada o no encontrada).';
            }
        } elseif ($accion === 'rechazar') {
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if (SolicitudesAsociacionService::rechazar($pdo, $id, $adminId, $motivo)) {
                $mensaje = 'Solicitud #' . $id . ' rechazada. Se envió notificación.';
            } else {
                $error = 'No se pudo rechazar la solicitud.';
            }
        }
    }
}

$contadores = SolicitudesAsociacionService::contadores($pdo);
$solicitudes = $tablaOk
    ? SolicitudesAsociacionService::listar($pdo, $tipo, $estatusFiltro)
    : [];

$u = static function (string $page, array $q = []): string {
    return htmlspecialchars(AppHelpers::dashboard($page, $q));
};
$csrf = CSRF::token();

$badge = static function (int $n): string {
    if ($n <= 0) {
        return '';
    }

    return ' <span class="badge bg-danger rounded-pill">' . number_format($n) . '</span>';
};
?>
<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $u('home') ?>">Inicio</a></li>
            <li class="breadcrumb-item active">Solicitudes de asociaciones</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-eye me-2 text-primary"></i>Supervisión de solicitudes</h1>
            <p class="text-muted mb-0 small">
                Trámites enviados por administradores de asociación desde su panel: <strong>afiliaciones nuevas</strong>,
                <strong>traspasos</strong> y <strong>carnets</strong>. La FVD aprueba o rechaza; el delegado y el afiliado reciben aviso web y Telegram.
            </p>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$tablaOk): ?>
        <div class="alert alert-warning">
            La tabla <code>movimiento_torneo</code> no existe. Ejecute <code>sql/create_movimiento_torneo.sql</code> en la base de datos.
        </div>
    <?php else: ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="btn-group flex-wrap w-100" role="group" aria-label="Filtrar por tipo">
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => 'todas', 'estatus' => $estatusGet]) ?>"
                   class="btn btn-sm <?= $tipo === 'todas' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Todas las solicitudes<?= $badge((int) $contadores['pendiente']) ?>
                </a>
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => 'afiliacion', 'estatus' => $estatusGet]) ?>"
                   class="btn btn-sm <?= $tipo === 'afiliacion' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Afiliaciones<?= $badge((int) $contadores['afiliacion']) ?>
                </a>
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => 'traspaso', 'estatus' => $estatusGet]) ?>"
                   class="btn btn-sm <?= $tipo === 'traspaso' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Traspasos<?= $badge((int) $contadores['traspaso']) ?>
                </a>
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => 'carnet', 'estatus' => $estatusGet]) ?>"
                   class="btn btn-sm <?= $tipo === 'carnet' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Carnets<?= $badge((int) $contadores['carnet']) ?>
                </a>
            </div>
            <div class="btn-group flex-wrap mt-2" role="group" aria-label="Filtrar por estatus">
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => $tipo, 'estatus' => 'pendiente']) ?>"
                   class="btn btn-sm <?= $estatusGet === 'pendiente' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Pendientes</a>
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => $tipo, 'estatus' => 'aprobada']) ?>"
                   class="btn btn-sm <?= $estatusGet === 'aprobada' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Aprobadas</a>
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => $tipo, 'estatus' => 'rechazada']) ?>"
                   class="btn btn-sm <?= $estatusGet === 'rechazada' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Rechazadas</a>
                <a href="<?= $u('solicitudes_asociacion', ['tipo' => $tipo, 'estatus' => 'todas']) ?>"
                   class="btn btn-sm <?= $estatusGet === 'todas' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Todas</a>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-light">
            <strong><?= count($solicitudes) ?></strong> registro(s)
        </div>
        <div class="card-body p-0">
            <?php if ($solicitudes === []): ?>
                <p class="text-muted text-center py-5 mb-0">No hay solicitudes con los filtros seleccionados.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Asociación</th>
                                <th>Atleta</th>
                                <th>Observaciones</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitudes as $sol): ?>
                                <?php
                                $sid = (int) ($sol['id'] ?? 0);
                                $est = (int) ($sol['estatus'] ?? 0);
                                $atleta = trim((string) ($sol['usuario_nombre'] ?? ''));
                                if ($atleta === '') {
                                    $atleta = 'Cédula ' . htmlspecialchars((string) ($sol['cedula'] ?? '—'));
                                } else {
                                    $atleta = htmlspecialchars($atleta) . ' <small class="text-muted">(' . htmlspecialchars((string) ($sol['cedula'] ?? '')) . ')</small>';
                                }
                                ?>
                                <tr>
                                    <td><code><?= $sid ?></code></td>
                                    <td class="small text-nowrap"><?= htmlspecialchars((string) ($sol['created_at'] ?? '')) ?></td>
                                    <td><span class="badge bg-info"><?= htmlspecialchars((string) ($sol['tipo_label'] ?? '')) ?></span></td>
                                    <td><?= htmlspecialchars((string) ($sol['club_nombre'] ?? '—')) ?></td>
                                    <td><?= $atleta ?></td>
                                    <td class="small"><?php
                                        $obs = FvdMovimientoTorneoHelper::notaHumanaGrupo((string) ($sol['grupo_nombre'] ?? ''));
                                        if (($sol['tipo_solicitud'] ?? '') === 'traspaso') {
                                            $dest = FvdMovimientoTorneoHelper::parsearDestinoClubDesdeGrupo((string) ($sol['grupo_nombre'] ?? ''));
                                            if ($dest > 0) {
                                                $obs = 'Destino club #' . $dest . ($obs !== '' ? ' — ' . $obs : '');
                                            }
                                        }
                                        echo htmlspecialchars($obs !== '' ? $obs : (string) ($sol['grupo_nombre'] ?? ''));
                                    ?></td>
                                    <td>
                                        <?php if ($est === SolicitudesAsociacionService::ESTATUS_APROBADO): ?>
                                            <span class="badge bg-success">Aprobada</span>
                                        <?php elseif ($est === SolicitudesAsociacionService::ESTATUS_RECHAZADO): ?>
                                            <span class="badge bg-danger">Rechazada</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($est === SolicitudesAsociacionService::ESTATUS_PENDIENTE): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('¿Aprobar solicitud #<?= $sid ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="id" value="<?= $sid ?>">
                                                <input type="hidden" name="accion" value="aprobar">
                                                <button type="submit" class="btn btn-sm btn-success" title="Aprobar"><i class="fas fa-check"></i></button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="Rechazar"
                                                    data-bs-toggle="modal" data-bs-target="#modalRechazar<?= $sid ?>">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <div class="modal fade" id="modalRechazar<?= $sid ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                            <input type="hidden" name="id" value="<?= $sid ?>">
                                                            <input type="hidden" name="accion" value="rechazar">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Rechazar solicitud #<?= $sid ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <label class="form-label">Motivo (opcional)</label>
                                                                <textarea name="motivo" class="form-control" rows="2"></textarea>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                <button type="submit" class="btn btn-danger">Rechazar</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <p class="small text-muted mt-3 mb-0">
        <i class="fas fa-info-circle me-1"></i>
        Las asociaciones registran trámites en <strong>Panel de asociación → Solicitudes</strong> (Afiliación, Traspaso, Carnets).
    </p>
    <?php endif; ?>
</div>
