<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/FvdInformeAsociacionService.php';
require_once __DIR__ . '/../../lib/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/../../lib/app_helpers.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();
$club = Auth::clubOperativoAsociacion();
$torneoId = (int) ($_GET['torneo_id'] ?? 0);
if ($torneoId <= 0) {
    $torneoId = (int) (FvdMovimientoTorneoHelper::torneoActivoId($pdo) ?? 0);
}
$urlPanel = AppHelpers::dashboard('asociacion_panel', array_filter(['torneo_id' => $torneoId ?: null]));
$cssFvd = AppHelpers::assetVersion('assets/css/fvd-afiliacion-forms.css');
$qTorneo = $torneoId > 0 ? ['torneo_id' => $torneoId] : [];
$u = static fn (string $page, array $extra = []) => AppHelpers::dashboard($page, $qTorneo + $extra);

$consolidado = [];
if (Auth::isAdminGeneral() && $club === null) {
    $consolidado = FvdInformeAsociacionService::consolidadoPorClub($pdo, $torneoId > 0 ? $torneoId : null);
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssFvd) ?>">
<div class="fvd-afiliacion-wrap">
    <nav aria-label="breadcrumb" class="mb-2 px-1">
        <ol class="breadcrumb breadcrumb--fvd mb-0">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlPanel) ?>">Panel</a></li>
            <li class="breadcrumb-item active">Informes FVD</li>
        </ol>
    </nav>

    <div class="afiliacion-card">
        <h1 class="afiliacion-title"><i class="fas fa-chart-bar me-2"></i>Informes FVD</h1>
        <p class="afiliacion-lead">
            Reportes operativos de afiliación, carnets y traspasos (torneo <?= $torneoId > 0 ? '#' . $torneoId : 'activo' ?>).
            Misma lógica que el portal <strong>admin_fvd</strong>.
        </p>

        <div class="fvd-informes-hub">
            <a href="<?= htmlspecialchars($u('asociacion/reportes/afiliaciones')) ?>">
                <i class="fas fa-user-plus"></i>
                <strong>Afiliaciones</strong>
                <small class="d-block opacity-75">Altas pendientes / aceptadas</small>
            </a>
            <a href="<?= htmlspecialchars($u('asociacion/reportes/carnets')) ?>">
                <i class="fas fa-id-card"></i>
                <strong>Carnets</strong>
                <small class="d-block opacity-75">Solicitar carnet por atleta</small>
            </a>
            <a href="<?= htmlspecialchars($u('asociacion/reportes/traspasos')) ?>">
                <i class="fas fa-exchange-alt"></i>
                <strong>Traspasos</strong>
                <small class="d-block opacity-75">Solicitud a otra asociación</small>
            </a>
            <a href="<?= htmlspecialchars($u('asociacion/afiliar_atleta')) ?>">
                <i class="fas fa-edit"></i>
                <strong>Afiliar atleta</strong>
                <small class="d-block opacity-75">Formulario completo</small>
            </a>
        </div>

        <?php if ($consolidado !== []): ?>
            <h2 class="afiliacion-block-title">Consolidado (admin general)</h2>
            <div class="reporte-vista table-responsive">
                <table class="fvd-table table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Asociación</th>
                            <th class="text-center">Afiliación</th>
                            <th class="text-center">Carnet</th>
                            <th class="text-center">Traspaso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consolidado as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['nombre'] ?? '')) ?></td>
                                <td class="text-center"><?= (int) ($row['n_afiliacion'] ?? 0) ?></td>
                                <td class="text-center"><?= (int) ($row['n_carnet'] ?? 0) ?></td>
                                <td class="text-center"><?= (int) ($row['n_traspaso'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="afiliacion-actions mt-3">
            <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn fvd-btn-secondary btn-sm">Volver al panel</a>
            <button type="button" class="btn fvd-btn-secondary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir</button>
        </div>
    </div>
</div>
