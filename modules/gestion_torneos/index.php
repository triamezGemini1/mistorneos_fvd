<?php
/**
 * Vista: Lista de Torneos para Gestión (por realizar, en proceso, realizados)
 */
$filtro_torneos = $filtro_torneos ?? null;
$base_list = 'index.php?page=torneo_gestion&action=index';
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone_list = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url_panel = $use_standalone_list ? $script_actual : 'index.php?page=torneo_gestion';
$panel_sep = $use_standalone_list ? '?' : '&';
?>

<div class="container-fluid ds-torneo-gestion-13">
    <div class="row mb-1 gx-0">
        <div class="col-12">
            <div class="btn-group btn-group-sm mb-1">
                <a href="<?php echo $base_list; ?>&filtro=por_realizar" class="btn btn-outline-info <?= $filtro_torneos === 'por_realizar' ? 'active' : '' ?>"><i class="fas fa-clock mr-1"></i> Por realizar</a>
                <a href="<?php echo $base_list; ?>&filtro=en_proceso" class="btn btn-outline-primary <?= $filtro_torneos === 'en_proceso' ? 'active' : '' ?>"><i class="fas fa-play-circle mr-1"></i> En proceso</a>
                <a href="<?php echo $base_list; ?>&filtro=realizados" class="btn btn-outline-success <?= $filtro_torneos === 'realizados' ? 'active' : '' ?>"><i class="fas fa-check-circle mr-1"></i> Realizados</a>
                <a href="<?php echo $base_list; ?>" class="btn btn-outline-secondary <?= !$filtro_torneos ? 'active' : '' ?>"><i class="fas fa-list mr-1"></i> Todos</a>
            </div>
        </div>
    </div>
    <div class="row mb-2 gx-0">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div class="flex-grow-1" style="min-width: 12rem;">
                    <h1 class="h5 mb-1">
                        <i class="fas fa-trophy text-primary"></i>
                        <?php
                        echo $filtro_torneos === 'por_realizar' ? 'Por realizar' : ($filtro_torneos === 'en_proceso' ? 'En proceso' : ($filtro_torneos === 'realizados' ? 'Realizados' : 'Gestión de Torneos'));
                        ?>
                    </h1>
                    <p class="text-muted small mb-0">Rondas, mesas, resultados y posiciones.</p>
                </div>
                <div class="d-flex flex-wrap gap-1 align-items-center justify-content-end">
                    <a href="index.php?page=torneo_gestion&action=index" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye me-1"></i>Ver / editar
                    </a>
                    <a href="index.php?page=tournaments&action=new" class="btn btn-sm btn-success">
                        <i class="fas fa-plus-circle me-1"></i>Nuevo torneo
                    </a>
                    <a href="index.php?page=estadisticas_torneos" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-chart-line me-1"></i>Estadísticas
                    </a>
                    <?php if (!empty($is_admin_general)): ?>
                    <a href="index.php?page=importacion_torneo_externo" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-file-import me-1"></i>Importar torneo
                    </a>
                    <a href="index.php?page=notificaciones_masivas" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-bell me-1"></i>Notif.
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i>
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <?php if (empty($torneos)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-trophy fa-4x text-muted mb-3"></i>
                <h5 class="card-title">No hay torneos para gestionar</h5>
                <p class="text-muted">Crea un torneo primero para poder gestionarlo.</p>
                <a href="index.php?page=tournaments&action=new" class="btn btn-primary mt-3">
                    <i class="fas fa-plus mr-2"></i> Crear Nuevo Torneo
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php $is_admin_general = $is_admin_general ?? false; ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Estatus</th>
                                <th>Nombre</th>
                                <th class="text-center">Inscritos</th>
                                <th class="text-center">Rondas</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($torneos as $t): ?>
                            <tr>
                                <td><?= !empty($t['fechator']) ? date('d/m/Y', strtotime($t['fechator'])) : '—' ?></td>
                                <td>
                                    <?php $cat = $t['categoria'] ?? ''; ?>
                                    <?php if ($cat === 'por_realizar'): ?><span class="badge bg-info">Por realizar</span>
                                    <?php elseif ($cat === 'en_proceso'): ?><span class="badge bg-primary">En proceso</span>
                                    <?php else: ?><span class="badge bg-success">Realizados</span><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($t['nombre']) ?></td>
                                <td class="text-center"><?= (int)($t['total_inscritos'] ?? 0) ?></td>
                                <td class="text-center"><?= (int)($t['ultima_ronda'] ?? 0) ?> / <?= (int)($t['rondas'] ?? 0) ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="index.php?page=torneo_gestion&action=view&id=<?= (int)$t['id'] ?>" class="btn btn-outline-info" title="Ver">Ver</a>
                                        <a href="index.php?page=torneo_gestion&action=edit&id=<?= (int)$t['id'] ?>" class="btn btn-outline-primary" title="Editar">Editar</a>
                                        <a href="<?= htmlspecialchars($base_url_panel . $panel_sep . 'action=panel&torneo_id=' . (int)$t['id']) ?>" class="btn btn-outline-success">Panel</a>
                                        <?php if ($is_admin_general): ?>
                                        <a href="index.php?page=importacion_torneo_externo&amp;torneo_id=<?= (int)$t['id'] ?>" class="btn btn-outline-secondary" title="Importar desde Access">
                                            <i class="fas fa-file-import"></i> Importar
                                        </a>
                                        <?php endif; ?>
                                        <?php
                                        $notif_url = $is_admin_general
                                            ? 'index.php?page=notificaciones_masivas&tipo_ag=inscritos_torneo&torneo_id_ag=' . (int)$t['id']
                                            : 'index.php?page=notificaciones_masivas&tipo=torneo&torneo_id=' . (int)$t['id'] . '&from=torneo_gestion';
                                        ?>
                                        <a href="<?= htmlspecialchars($notif_url) ?>" class="btn btn-outline-warning" title="Enviar notificaciones a inscritos o usuarios de la organización">
                                            <i class="fas fa-bell"></i> Notificación
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

