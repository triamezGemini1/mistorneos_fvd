<?php

$logo_url = !empty($organizacion['logo'])

    ? AppHelpers::imageUrl($organizacion['logo'])

    : AppHelpers::getAppLogo();

$clubes_paginados = $clubes_paginados ?? $clubes ?? [];

$clubes_page = isset($clubes_page) ? (int)$clubes_page : 1;

$clubes_total_pages = isset($clubes_total_pages) ? (int)$clubes_total_pages : 1;

$clubes_total_rows = isset($clubes_total_rows) ? (int)$clubes_total_rows : count($clubes ?? []);

$clubes_per_page = isset($clubes_per_page) ? (int)$clubes_per_page : 10;

$qsBase = 'index.php?page=organizaciones&id=' . (int)$organizacion['id'];



$stats_asociaciones = (int) ($stats_asociaciones ?? count($clubes ?? []));

$stats_torneos = (int) ($org_dashboard_snap['stats']['torneos'] ?? 0);

$stats_afiliados_total = (int) ($stats_afiliados_total ?? 0);

$stats_hombres_total = (int) ($stats_hombres_total ?? 0);

$stats_mujeres_total = (int) ($stats_mujeres_total ?? 0);

$stats_otros_total = (int) ($stats_otros_total ?? 0);

$stats_operadores = isset($stats_operadores) ? (int)$stats_operadores : 0;

$stats_admin_torneo = isset($stats_admin_torneo) ? (int)$stats_admin_torneo : 0;

$stats_afiliados_sin_club = isset($stats_afiliados_sin_club) ? (int)$stats_afiliados_sin_club : 0;



$user_role_org = (string) ($current_user['role'] ?? '');

$can_edit_org = !empty($is_admin_general)

    || ($user_role_org === 'admin_club' && (int) Auth::getUserOrganizacionId() === (int) ($organizacion['id'] ?? 0));

$url_editar_org = AppHelpers::dashboard('mi_organizacion', ['id' => (int) $organizacion['id']]);

?>

<div class="container-fluid py-2 fvd-app-page fvd-app-page--compact fvd-app-page--glass fvd-org-page fvd-org-page--compact" id="top-page">

    <nav aria-label="breadcrumb" class="mb-1">

        <ol class="breadcrumb mb-0">

            <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>

            <li class="breadcrumb-item active" aria-current="page">Mi organización</li>

        </ol>

    </nav>



    <?php $error_org = isset($_GET['error']) ? trim((string) $_GET['error']) : ''; ?>

    <?php if ($error_org !== ''): ?>

        <div class="alert alert-warning alert-dismissible fade show py-2 mb-2" role="alert">

            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_org) ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>

        </div>

    <?php endif; ?>



    <?php

    $org_estatus = (int)($organizacion['estatus'] ?? 1);

    $org_desactivada = $org_estatus === 0;

    if ($org_desactivada && !empty($is_admin_general)): ?>

        <div class="alert alert-warning alert-dismissible fade show py-2 mb-2">

            <i class="fas fa-ban me-2"></i>Esta organización está <strong>desactivada</strong>.

            <a href="index.php?page=mi_organizacion&action=reactivar&id=<?= (int)$organizacion['id'] ?>&return_to=organizaciones&entidad_id=<?= (int)($organizacion['entidad'] ?? 0) ?>" class="btn btn-sm btn-success ms-2" onclick="return confirm('¿Reactivar esta organización?');">

                <i class="fas fa-check-circle me-1"></i>Reactivar

            </a>

            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>

        </div>

    <?php endif; ?>



    <div class="row g-2 mb-2">

        <div class="col-lg-6">

            <div class="card shadow-sm h-100 fvd-app-card fvd-org-card-compact fvd-org-card--info">

                <div class="card-header fvd-org-card-header fvd-org-card-header--info d-flex flex-wrap justify-content-between align-items-center gap-1 py-2">

                    <h6 class="mb-0"><i class="fas fa-building me-1"></i>Organización</h6>

                    <?php if ($can_edit_org): ?>

                        <a href="<?= htmlspecialchars($url_editar_org) ?>" class="btn btn-sm btn-primary py-0 px-2">

                            <i class="fas fa-edit me-1"></i>Editar

                        </a>

                    <?php endif; ?>

                </div>

                <div class="card-body py-2">

                    <div class="d-flex align-items-start gap-2">

                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($organizacion['nombre']) ?>" class="rounded flex-shrink-0 fvd-org-logo-sm">

                        <div class="flex-grow-1 min-w-0">

                            <h5 class="mb-0 fvd-org-title"><?= htmlspecialchars($organizacion['nombre']) ?></h5>

                            <?php $org_cod_display = (int) ($organizacion['cod_org'] ?? 0); ?>

                            <p class="small mb-1 fvd-org-meta">

                                ID <?= (int) ($organizacion['id'] ?? 0) ?>

                                <?php if ($org_cod_display > 0): ?>

                                    · Fed. <?= $org_cod_display ?>

                                <?php endif; ?>

                                <?php if (!empty($organizacion['entidad_nombre'])): ?>

                                    · <?= htmlspecialchars($organizacion['entidad_nombre']) ?>

                                <?php endif; ?>

                            </p>

                            <div class="fvd-org-contact-lines">

                                <?php if (!empty($organizacion['responsable'])): ?>

                                    <span><i class="fas fa-user me-1"></i><?= htmlspecialchars($organizacion['responsable']) ?></span>

                                <?php endif; ?>

                                <?php if (!empty($organizacion['telefono'])): ?>

                                    <span><i class="fas fa-phone me-1"></i><?= htmlspecialchars($organizacion['telefono']) ?></span>

                                <?php endif; ?>

                                <?php if (!empty($organizacion['email'])): ?>

                                    <span><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($organizacion['email']) ?></span>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card shadow-sm h-100 fvd-app-card fvd-org-card-compact fvd-org-card--stats">

                <div class="card-header fvd-org-card-header fvd-org-card-header--stats py-2">

                    <h6 class="mb-0"><i class="fas fa-chart-bar me-1"></i>Estadísticas</h6>

                </div>

                <div class="card-body py-2">

                    <div class="row g-1 fvd-org-kpi-row">

                        <div class="col-4 col-md">

                            <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--blue">

                                <strong class="fvd-org-kpi-num"><?= $stats_asociaciones ?></strong>

                                <span>Asociaciones</span>

                            </div>

                        </div>

                        <div class="col-4 col-md">

                            <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--green">

                                <strong class="fvd-org-kpi-num"><?= $stats_torneos ?></strong>

                                <span>Torneos</span>

                            </div>

                        </div>

                        <div class="col-4 col-md">

                            <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--yellow">

                                <strong class="fvd-org-kpi-num"><?= $stats_afiliados_total ?></strong>

                                <span>Afiliados</span>

                                <small class="d-block mt-0">M <?= $stats_hombres_total ?> · F <?= $stats_mujeres_total ?> · O <?= $stats_otros_total ?></small>

                            </div>

                        </div>

                        <div class="col-6 col-md">

                            <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--lavender">

                                <strong class="fvd-org-kpi-num"><?= $stats_admin_torneo ?></strong>

                                <span>Admin. torneo</span>

                            </div>

                        </div>

                        <div class="col-6 col-md">

                            <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--peach">

                                <strong class="fvd-org-kpi-num"><?= $stats_operadores ?></strong>

                                <span>Operadores</span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <div class="card shadow-sm fvd-app-card fvd-org-card-compact fvd-org-card--list" id="lista-asociaciones-org">

        <div class="card-header fvd-org-card-header fvd-org-card-header--list d-flex flex-wrap justify-content-between align-items-center gap-1 py-2">

            <h6 class="mb-0"><i class="fas fa-sitemap me-1"></i>Asociaciones (entidades)</h6>

            <?php if (empty($is_admin_general)): ?>

                <a href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::dashboard('clubes_asociados') : 'index.php?page=clubes_asociados') ?>" class="btn btn-sm btn-outline-primary py-0 px-2">

                    <i class="fas fa-cog me-1"></i>Gestionar

                </a>

            <?php endif; ?>

        </div>

        <div class="card-body p-0">

            <?php if ($stats_afiliados_sin_club > 0): ?>

                <div class="alert alert-warning rounded-0 mb-0 py-1 px-2 small">

                    <i class="fas fa-info-circle me-1"></i>

                    Afiliados sin entidad asignada: <strong><?= (int)$stats_afiliados_sin_club ?></strong>

                </div>

            <?php endif; ?>

            <?php if (empty($clubes)): ?>

                <div class="text-center py-3 text-muted small">

                    <i class="fas fa-sitemap mb-1"></i>

                    <p class="mb-0">No hay asociaciones registradas</p>

                </div>

            <?php else: ?>

                <div class="table-responsive fvd-app-table">

                    <table class="table table-hover table-sm mb-0 fvd-org-table-compact">

                        <thead>

                            <tr>

                                <th>Ent.</th>

                                <th>Asociación</th>

                                <th>Delegado</th>

                                <th class="text-center">Af.</th>

                                <th class="text-center" title="Hombres"><i class="fas fa-mars text-primary"></i></th>

                                <th class="text-center" title="Mujeres"><i class="fas fa-venus text-danger"></i></th>

                                <th class="text-end">Acciones</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($clubes_paginados as $c): ?>

                                <?php $entidad_asoc_id = (int) ($c['entidad'] ?? 0); ?>

                                <tr>

                                    <td class="text-muted font-monospace"><?= $entidad_asoc_id > 0 ? $entidad_asoc_id : '—' ?></td>

                                    <td class="fw-semibold text-truncate" style="max-width: 12rem;"><?= htmlspecialchars($c['nombre']) ?></td>

                                    <td class="text-truncate" style="max-width: 9rem;"><?= htmlspecialchars($c['delegado'] ?? '-') ?></td>

                                    <td class="text-center"><span class="badge bg-info"><?= (int)($c['total_afiliados'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-primary"><?= (int)($c['hombres'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-danger"><?= (int)($c['mujeres'] ?? 0) ?></span></td>

                                    <td class="text-end text-nowrap">

                                        <?php

                                        $urlAfiliados = (int) ($c['id'] ?? 0) > 0

                                            ? 'index.php?page=organizaciones&id=' . (int) $organizacion['id'] . '&club_id=' . (int) $c['id']

                                            : ($entidad_asoc_id > 0

                                                ? 'index.php?page=organizaciones&id=' . (int) $organizacion['id'] . '&entidad_id=' . $entidad_asoc_id

                                                : '');

                                        ?>

                                        <?php if ($urlAfiliados !== ''): ?>

                                            <a href="<?= htmlspecialchars($urlAfiliados) ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="Ver afiliados">

                                                <i class="fas fa-eye"></i>

                                            </a>

                                        <?php endif; ?>

                                        <?php if ((int)($c['id'] ?? 0) > 0): ?>

                                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('clubes_asociados', ['club_id' => $c['id']])) ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Editar asociación">

                                                <i class="fas fa-edit"></i>

                                            </a>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <?php

                if (class_exists('FvdPaginacionCompacta')) {

                    echo FvdPaginacionCompacta::render(

                        $clubes_page,

                        $clubes_total_pages,

                        $clubes_total_rows,

                        $clubes_per_page,

                        $qsBase,

                        'clubes_page',

                        'asociaciones'

                    );

                }

                ?>

            <?php endif; ?>

        </div>

    </div>



    <div class="mt-2 d-flex flex-wrap gap-1" id="bottom-page">

        <?php if (!empty($is_admin_general)): ?>

            <a href="index.php?page=organizaciones" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Listado</a>

        <?php else: ?>

            <a href="index.php?page=home" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Inicio</a>

        <?php endif; ?>

        <?php if ($can_edit_org): ?>

            <a href="<?= htmlspecialchars($url_editar_org) ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Editar</a>

        <?php endif; ?>

    </div>

</div>

