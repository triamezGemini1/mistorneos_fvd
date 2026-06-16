<?php
/**
 * Panel operativo admin general — estilo sobrio FVD (CSS en fvd-identidad.css).
 */
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../../lib/app_helpers.php';
}
if (!class_exists('FvdInstitutionalScope', false)) {
    require_once __DIR__ . '/../../../lib/FvdInstitutionalScope.php';
}
$pb = $panel_badges ?? [
    'solicitudes_afiliacion_total' => 0,
    'solicitudes_afiliacion_pendiente' => 0,
    'comentarios_pendientes' => 0,
];
$orgPk = (int) ($fvd_org_id ?? 1);
$institutional = FvdInstitutionalScope::isEnabled();

$u = static function (string $page, array $q = []): string {
    return htmlspecialchars(AppHelpers::dashboard($page, $q));
};

$b = static function (int $n): string {
    $cls = $n > 0 ? 'admin-general-panel-operativo__badge admin-general-panel-operativo__badge--active' : 'admin-general-panel-operativo__badge';
    return '<span class="' . $cls . '">' . number_format($n) . '</span>';
};

$link = 'admin-general-panel-operativo__link';
$linkDestacado = 'admin-general-panel-operativo__link admin-general-panel-operativo__link--highlight';
$modulosExtendidos = class_exists('FvdConfig', false) && FvdConfig::adminModuleEnabled();
?>
<section class="admin-general-panel-operativo">
    <header>
        <h2>Panel operativo FVD</h2>
        <p>Federación Venezolana de Dominó — organización, afiliados y campeonatos</p>
    </header>

    <div class="admin-general-panel-operativo__grid">
        <div class="admin-general-panel-operativo__card">
            <div class="admin-general-panel-operativo__card-head">
                <i class="fas fa-concierge-bell me-1"></i>Servicios
            </div>
            <div class="admin-general-panel-operativo__card-body">
                <a href="<?= $u('calendario') ?>" class="<?= $linkDestacado ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Ver calendario</span>
                    <i class="fas fa-chevron-right ms-auto small text-muted"></i>
                </a>
                <a href="<?= $u('organizaciones', ['id' => $orgPk]) ?>" class="<?= $link ?>">
                    <i class="fas fa-building"></i>
                    <span>Mi organización</span>
                </a>
                <a href="<?= $u('clubs') ?>" class="<?= $link ?>">
                    <i class="fas fa-sitemap"></i>
                    <span>Asociaciones</span>
                </a>
                <a href="<?= $u('users') ?>" class="<?= $link ?>">
                    <i class="fas fa-running"></i>
                    <span>Atletas y usuarios</span>
                </a>
            </div>
        </div>

        <?php if ($modulosExtendidos): ?>
        <div class="admin-general-panel-operativo__card">
            <div class="admin-general-panel-operativo__card-head">
                <i class="fas fa-eye me-1"></i>Supervisión
            </div>
            <div class="admin-general-panel-operativo__card-body">
                <a href="<?= $u('affiliate_requests', ['filter' => 'todas']) ?>" class="<?= $link ?>">
                    <i class="fas fa-list"></i>
                    <span>Todas las solicitudes</span>
                    <?= $b((int) $pb['solicitudes_afiliacion_total']) ?>
                </a>
                <a href="<?= $u('affiliate_requests', ['filter' => 'pendiente']) ?>" class="<?= $link ?>">
                    <i class="fas fa-user-plus"></i>
                    <span>Afiliaciones pendientes</span>
                    <?= $b((int) $pb['solicitudes_afiliacion_pendiente']) ?>
                </a>
                <a href="<?= $u('solicitudes_asociacion') ?>" class="<?= $link ?>">
                    <i class="fas fa-file-signature"></i>
                    <span>Solicitudes de asociaciones</span>
                </a>
                <a href="<?= $u('admin_atletas_sync') ?>" class="<?= $link ?>">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Traspasos / sync atletas</span>
                </a>
                <a href="<?= $u('comments', ['estatus' => 'pendiente']) ?>" class="<?= $link ?>">
                    <i class="fas fa-globe"></i>
                    <span>Solicitudes portal</span>
                    <?= $b((int) $pb['comentarios_pendientes']) ?>
                </a>
                <a href="<?= $u('estadisticas_web') ?>" class="<?= $linkDestacado ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Estadísticas web (Umami)</span>
                    <i class="fas fa-chevron-right ms-auto small text-muted"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="admin-general-panel-operativo__card">
            <div class="admin-general-panel-operativo__card-head">
                <i class="fas fa-cogs me-1"></i>Operaciones
            </div>
            <div class="admin-general-panel-operativo__card-body">
                <?php if ($institutional): ?>
                <a href="<?= $u('tournaments', ['action' => 'list']) ?>" class="<?= $linkDestacado ?>">
                    <i class="fas fa-trophy"></i>
                    <span>Campeonatos y torneos</span>
                    <i class="fas fa-chevron-right ms-auto small text-muted"></i>
                </a>
                <a href="<?= $u('tournaments', ['action' => 'new']) ?>" class="<?= $link ?>">
                    <i class="fas fa-plus-circle"></i>
                    <span>Crear campeonato</span>
                </a>
                <?php else: ?>
                <a href="<?= $u('torneo_gestion', ['action' => 'index']) ?>" class="<?= $link ?>">
                    <i class="fas fa-trophy"></i>
                    <span>Torneos</span>
                </a>
                <?php endif; ?>
                <a href="<?= $u('auditoria') ?>" class="<?= $link ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Informes y auditoría</span>
                </a>
                <a href="<?= $u('control_admin') ?>" class="<?= $link ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Control administrativo</span>
                </a>
                <a href="<?= $u('notificaciones_masivas') ?>" class="<?= $link ?>">
                    <i class="fas fa-bell"></i>
                    <span>Notificaciones masivas</span>
                </a>
            </div>
        </div>

        <?php if ($modulosExtendidos): ?>
        <div class="admin-general-panel-operativo__card">
            <div class="admin-general-panel-operativo__card-head">
                <i class="fas fa-coins me-1"></i>Finanzas
            </div>
            <div class="admin-general-panel-operativo__card-body">
                <a href="<?= $u('finances') ?>" class="<?= $link ?>">
                    <i class="fas fa-balance-scale"></i>
                    <span>Estado de cuentas</span>
                </a>
                <a href="<?= $u('ranking_numfvd_admin') ?>" class="<?= $link ?>">
                    <i class="fas fa-medal"></i>
                    <span>Ranking NUMFVD</span>
                </a>
                <a href="<?= $u('reportes_pago_donacion') ?>" class="<?= $link ?>">
                    <i class="fas fa-hand-holding-heart"></i>
                    <span>Donaciones / activar PDF</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
