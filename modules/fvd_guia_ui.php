<?php
/**
 * Guía de identidad visual FVD — página modelo para homologar UI del dashboard.
 * Acceso: admin_general · page=fvd_guia_ui
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/FvdAppPage.php';
require_once __DIR__ . '/../lib/FvdPaginacionCompacta.php';

Auth::requireRole(['admin_general']);

$demoRows = [
    ['ent' => 1, 'nombre' => 'Distrito Capital', 'delegado' => 'Juan Pérez', 'af' => 128, 'm' => 72, 'f' => 56],
    ['ent' => 5, 'nombre' => 'Carabobo', 'delegado' => 'María Gómez', 'af' => 95, 'm' => 51, 'f' => 44],
    ['ent' => 12, 'nombre' => 'Zulia', 'delegado' => 'Carlos Ruiz', 'af' => 87, 'm' => 48, 'f' => 39],
];

echo FvdAppPage::openShell('fvd-guia-ui');
echo FvdAppPage::renderBreadcrumb([
    ['label' => 'Inicio', 'href' => 'index.php?page=home'],
    ['label' => 'Guía UI FVD', 'active' => true],
]);
?>
<div class="fvd-app-toolbar mb-2">
    <a href="index.php?page=home" class="btn btn-sm btn-outline-secondary btn-volver">
        <i class="fas fa-arrow-left me-1"></i>Volver
    </a>
    <div class="fvd-app-toolbar-main">
        <div>
            <h1 class="fvd-app-title">Guía de identidad visual</h1>
            <p class="fvd-app-subtitle">Modelo canónico · clases <strong>fvd-app-*</strong> · fondo #00D6F8 → #0E85A3</p>
        </div>
        <div class="fvd-app-filtros">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filtro demo">
                <button type="button" class="btn btn-primary">Todos</button>
                <button type="button" class="btn btn-outline-primary">Hombres</button>
                <button type="button" class="btn btn-outline-primary">Mujeres</button>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2">
    <div class="col-md-4">
        <div class="fvd-app-swatch fvd-app-swatch--soft">Soft #7AE8FB</div>
    </div>
    <div class="col-md-4">
        <div class="fvd-app-swatch fvd-app-swatch--bg">Principal #00D6F8</div>
    </div>
    <div class="col-md-4">
        <div class="fvd-app-swatch fvd-app-swatch--deep">Profundo #0E85A3</div>
    </div>
</div>

<div class="row g-1 fvd-app-kpi-row mb-2">
    <div class="col-4 col-md"><div class="fvd-app-kpi fvd-app-kpi--blue"><strong class="fvd-app-kpi-num">26</strong><span>Asociaciones</span></div></div>
    <div class="col-4 col-md"><div class="fvd-app-kpi fvd-app-kpi--green"><strong class="fvd-app-kpi-num">12</strong><span>Torneos</span></div></div>
    <div class="col-4 col-md"><div class="fvd-app-kpi fvd-app-kpi--yellow"><strong class="fvd-app-kpi-num">1.842</strong><span>Afiliados</span><small>M 980 · F 820 · O 42</small></div></div>
</div>

<?= FvdAppPage::cardOpen('Listado de ejemplo', 'fas fa-table') ?>
<div class="fvd-app-table">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>Ent.</th>
                    <th>Asociación</th>
                    <th>Delegado</th>
                    <th class="text-center">Af.</th>
                    <th class="text-center"><i class="fas fa-mars text-primary"></i></th>
                    <th class="text-center"><i class="fas fa-venus text-danger"></i></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($demoRows as $r): ?>
                <tr>
                    <td class="text-muted font-monospace"><?= (int) $r['ent'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['nombre']) ?></td>
                    <td><?= htmlspecialchars($r['delegado']) ?></td>
                    <td class="text-center"><span class="badge bg-info"><?= (int) $r['af'] ?></span></td>
                    <td class="text-center"><span class="badge bg-primary"><?= (int) $r['m'] ?></span></td>
                    <td class="text-center"><span class="badge bg-danger"><?= (int) $r['f'] ?></span></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" title="Ver"><i class="fas fa-eye"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Editar"><i class="fas fa-edit"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
echo FvdPaginacionCompacta::render(1, 3, 26, FvdPaginacionCompacta::PER_PAGE_DEFAULT, 'index.php?page=fvd_guia_ui', 'p', 'registros demo');
echo FvdAppPage::cardClose();
?>

<div class="card fvd-app-card mt-2">
    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-code me-1"></i>Uso en módulos PHP</h6></div>
    <div class="card-body py-2 small">
        <p class="mb-1">1. Envolver contenido: <code>FvdAppPage::openShell()</code> … <code>FvdAppPage::closeShell()</code></p>
        <p class="mb-1">2. Tarjetas: <code>fvd-app-card</code> · tablas: <code>fvd-app-table</code> · KPIs: <code>fvd-app-kpi fvd-app-kpi--blue</code></p>
        <p class="mb-0">3. Opt-out global: clase <code>no-fvd-app-page</code> en container o <code>no-fvd-app</code> en card/tabla.</p>
    </div>
</div>
<?php
echo FvdAppPage::closeShell();
