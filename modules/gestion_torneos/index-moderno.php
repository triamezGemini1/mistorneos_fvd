<?php
/**
 * Vista Moderna: Lista de Torneos para Gestión (discriminados por realizados, en proceso, por realizar)
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$filtro_torneos = $filtro_torneos ?? null;

$titulos = [
    'por_realizar' => ['titulo' => 'Por realizar', 'icono' => 'fa-clock', 'texto' => 'Torneos con fecha futura'],
    'en_proceso'   => ['titulo' => 'En proceso', 'icono' => 'fa-play-circle', 'texto' => 'Torneos en curso'],
    'realizados'   => ['titulo' => 'Realizados', 'icono' => 'fa-check-circle', 'texto' => 'Torneos finalizados'],
];
$actual = $filtro_torneos ? ($titulos[$filtro_torneos] ?? null) : ['titulo' => 'Todos los torneos', 'icono' => 'fa-trophy', 'texto' => 'Gestiona tus torneos y administra rondas, mesas y resultados'];
$is_admin_general = $is_admin_general ?? false;
?>

<div class="ds-torneo-gestion-13">
<!-- Filtros rápidos (pestañas) -->
<div class="mb-2">
    <div class="btn-group btn-group-sm flex-wrap" role="group">
        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index&filtro=por_realizar" class="btn btn-outline-info <?= $filtro_torneos === 'por_realizar' ? 'active' : '' ?>">
            <i class="fas fa-clock me-1"></i> Por realizar
        </a>
        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index&filtro=en_proceso" class="btn btn-outline-primary <?= $filtro_torneos === 'en_proceso' ? 'active' : '' ?>">
            <i class="fas fa-play-circle me-1"></i> En proceso
        </a>
        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index&filtro=realizados" class="btn btn-outline-success <?= $filtro_torneos === 'realizados' ? 'active' : '' ?>">
            <i class="fas fa-check-circle me-1"></i> Realizados
        </a>
        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index" class="btn btn-outline-secondary <?= $filtro_torneos === null || $filtro_torneos === '' ? 'active' : '' ?>">
            <i class="fas fa-list me-1"></i> Todos
        </a>
    </div>
</div>

<!-- Header compacto (~13") -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <div class="flex-grow-1" style="min-width: 12rem;">
        <h2 class="h5 mb-1">
            <i class="fas <?= $actual['icono'] ?? 'fa-trophy' ?> text-primary me-2"></i><?php echo htmlspecialchars($actual['titulo']); ?>
        </h2>
        <p class="text-muted small mb-0"><?php echo htmlspecialchars($actual['texto']); ?></p>
    </div>
    <div class="d-flex flex-wrap gap-1 justify-content-end">
        <a href="index.php?page=torneo_gestion&action=index" class="btn btn-sm btn-outline-primary shadow-sm">
            <i class="fas fa-list me-1"></i>Ver / editar
        </a>
        <a href="index.php?page=tournaments&action=new" class="btn btn-sm btn-success shadow-sm">
            <i class="fas fa-plus-circle me-1"></i>Nuevo torneo
        </a>
        <a href="index.php?page=estadisticas_torneos" class="btn btn-sm btn-outline-info shadow-sm">
            <i class="fas fa-chart-line me-1"></i>Estadísticas
        </a>
        <?php if ($is_admin_general): ?>
        <a href="index.php?page=importacion_torneo_externo" class="btn btn-sm btn-outline-secondary shadow-sm">
            <i class="fas fa-file-import me-1"></i>Importar torneo
        </a>
        <a href="index.php?page=notificaciones_masivas" class="btn btn-sm btn-outline-warning shadow-sm">
            <i class="fas fa-bell me-1"></i>Notif.
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($error_message)): ?>
    <div class="alert-modern alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        <div><?php echo htmlspecialchars($error_message); ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (empty($torneos)): ?>
    <?php
    $mensaje_vacio = [
        'por_realizar' => 'No hay torneos por realizar.',
        'en_proceso'   => 'No hay torneos en proceso.',
        'realizados'   => 'No hay torneos realizados.',
    ];
    $texto_vacio = $filtro_torneos ? ($mensaje_vacio[$filtro_torneos] ?? 'No hay torneos en esta categoría.') : 'No hay torneos para gestionar.';
    ?>
    <div class="card-modern text-center py-5">
        <i class="fas fa-trophy fa-4x text-muted mb-3"></i>
        <h5 class="card-header-modern justify-content-center"><?php echo htmlspecialchars($texto_vacio); ?></h5>
        <p class="text-muted mb-4"><?= $filtro_torneos ? 'Cambia de pestaña o crea un nuevo torneo.' : 'Crea un torneo primero para poder gestionarlo.' ?></p>
        <a href="index.php?page=tournaments&action=new" class="btn-modern btn-primary-modern">
            <i class="fas fa-plus"></i> Crear Nuevo Torneo
        </a>
    </div>
<?php else: ?>
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
                                    <a href="<?= htmlspecialchars($base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . (int)$t['id']) ?>" class="btn btn-outline-success">Panel</a>
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
