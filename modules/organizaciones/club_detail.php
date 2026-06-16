<?php
require_once __DIR__ . '/../../lib/FvdPaginacionCompacta.php';

$afiliados_page = isset($afiliados_page) ? (int) $afiliados_page : 1;
$afiliados_per_page = isset($afiliados_per_page) ? (int) $afiliados_per_page : FvdPaginacionCompacta::PER_PAGE_DEFAULT;
$afiliados_total_rows = isset($afiliados_total_rows) ? (int) $afiliados_total_rows : count($afiliados ?? []);
$afiliados_total_pages = isset($afiliados_total_pages) ? (int) $afiliados_total_pages : 1;
$sexo = isset($sexo) ? (string) $sexo : 'todos';
$afiliados_resumen = $afiliados_resumen ?? ['total' => 0, 'hombres' => 0, 'mujeres' => 0];
$entidad_cod = (int) ($club['entidad'] ?? 0);
$qsOrg = 'index.php?page=organizaciones&id=' . (int) $organizacion['id'];
$club_pk = (int) ($club['id'] ?? 0);
if ($club_pk > 0) {
    $qsBase = $qsOrg . '&club_id=' . $club_pk;
} else {
    $qsBase = $qsOrg . '&entidad_id=' . $entidad_cod;
}
$pag_base_url = $qsBase . '&sexo=' . urlencode($sexo);
$ocultar_volver_mi_org = class_exists('Auth', false) && Auth::isOperativoSoloAsociacion();
?>
<div class="container-fluid fvd-listado-page fvd-listado-page--mi-org py-1" id="top-page">
    <?php if (!$ocultar_volver_mi_org): ?>
    <nav aria-label="breadcrumb" class="mb-1">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($qsOrg) ?>"><?= htmlspecialchars($organizacion['nombre']) ?></a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($club['nombre']) ?></li>
        </ol>
    </nav>
    <?php endif; ?>

    <div class="fvd-listado-toolbar fvd-listado-toolbar--compact mb-1">
        <?php if (!$ocultar_volver_mi_org): ?>
        <a href="<?= htmlspecialchars($qsOrg) ?>" class="btn btn-outline-secondary btn-sm btn-volver">
            <i class="fas fa-arrow-left me-1"></i>Volver
        </a>
        <?php endif; ?>
        <div class="fvd-listado-toolbar-main">
            <div class="min-w-0">
                <h1 class="fvd-listado-title mb-0">
                    <i class="fas fa-sitemap me-1 opacity-75"></i><?= htmlspecialchars($club['nombre']) ?>
                </h1>
                <?php if ($entidad_cod > 0 && !$ocultar_volver_mi_org): ?>
                    <p class="fvd-listado-subtitle mb-0">ID entidad: <?= $entidad_cod ?></p>
                <?php endif; ?>
            </div>
            <div class="btn-group fvd-listado-filtros" role="group" aria-label="Filtro por género">
                <a href="<?= htmlspecialchars($qsBase . '&sexo=todos') ?>" class="btn btn-sm <?= $sexo === 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>">Todos</a>
                <a href="<?= htmlspecialchars($qsBase . '&sexo=m') ?>" class="btn btn-sm <?= $sexo === 'm' ? 'btn-primary' : 'btn-outline-primary' ?>">Hombres</a>
                <a href="<?= htmlspecialchars($qsBase . '&sexo=f') ?>" class="btn btn-sm <?= $sexo === 'f' ? 'btn-primary' : 'btn-outline-primary' ?>">Mujeres</a>
            </div>
        </div>
    </div>

    <div class="row g-1 fvd-listado-kpis mb-1">
        <div class="col-4">
            <div class="fvd-listado-kpi fvd-listado-kpi--sky">
                <strong><?= (int) ($afiliados_resumen['total'] ?? 0) ?></strong>
                <span>Total</span>
            </div>
        </div>
        <div class="col-4">
            <div class="fvd-listado-kpi fvd-listado-kpi--blue">
                <strong><?= (int) ($afiliados_resumen['hombres'] ?? 0) ?></strong>
                <span>Hombres</span>
            </div>
        </div>
        <div class="col-4">
            <div class="fvd-listado-kpi fvd-listado-kpi--rose">
                <strong><?= (int) ($afiliados_resumen['mujeres'] ?? 0) ?></strong>
                <span>Mujeres</span>
            </div>
        </div>
    </div>

    <div class="card fvd-listado-card">
        <div class="card-header"><i class="fas fa-users me-1"></i>Afiliados</div>
        <div class="card-body">
            <?php if (empty($afiliados)): ?>
                <div class="fvd-listado-empty">
                    <?php if ($sexo !== 'todos'): ?>
                        No hay afiliados con el filtro seleccionado.
                    <?php else: ?>
                        No se encontraron afiliados para esta asociación.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Cédula</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($afiliados as $a): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($a['nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['cedula'] ?? '-') ?></td>
                                    <td class="small">
                                        <?php if (!empty($a['email'])): ?><?= htmlspecialchars($a['email']) ?><br><?php endif; ?>
                                        <?php if (!empty($a['celular'])): ?><?= htmlspecialchars($a['celular']) ?><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= (int) ($a['status'] ?? 1) === 0 ? 'success' : 'secondary' ?>">
                                            <?= (int) ($a['status'] ?? 1) === 0 ? 'Activo' : 'Inactivo' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= FvdPaginacionCompacta::render(
                    $afiliados_page,
                    $afiliados_total_pages,
                    $afiliados_total_rows,
                    $afiliados_per_page,
                    $pag_base_url,
                    'afiliados_page',
                    'afiliados'
                ) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
