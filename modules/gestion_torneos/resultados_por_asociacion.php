<?php
/**
 * Listado de atletas del torneo con resultados individuales, agrupados por asociación.
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$tid = (int)($torneo['id'] ?? $torneo_id ?? 0);
$asociaciones = isset($asociaciones) && is_array($asociaciones) ? $asociaciones : [];
$page_title = 'Resultados por asociación — ' . (string)($torneo['nombre'] ?? 'Torneo');
$total_atletas = 0;
foreach ($asociaciones as $bloque) {
    $total_atletas += count($bloque['atletas'] ?? []);
}
$mostrar_equipo = false;
foreach ($asociaciones as $bloque) {
    foreach ($bloque['atletas'] ?? [] as $a) {
        if (trim((string)($a['codigo_equipo'] ?? '')) !== '') {
            $mostrar_equipo = true;
            break 2;
        }
    }
}
?>
<link rel="stylesheet" href="assets/css/fvd-tokens.css">
<link rel="stylesheet" href="assets/css/design-system.css">

<div class="container-fluid py-3 ds-root">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($base_url . ($use_standalone ? '?' : '&') . 'action=index'); ?>">Gestión de Torneos</a></li>
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $tid); ?>"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></a></li>
            <li class="breadcrumb-item active">Resultados por asociación</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-building text-primary me-2"></i>Resultados por asociación</h1>
            <p class="text-muted small mb-0"><?php echo htmlspecialchars($torneo['nombre'] ?? ''); ?> · <?php echo $total_atletas; ?> atleta(s) en <?php echo count($asociaciones); ?> asociación(es)</p>
        </div>
        <a href="<?php echo htmlspecialchars($base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $tid); ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Volver al panel
        </a>
    </div>

    <?php if ($asociaciones === []): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No hay atletas con resultados registrados para este torneo.
        </div>
    <?php else: ?>
        <?php foreach ($asociaciones as $bloque): ?>
            <?php $atletas = $bloque['atletas'] ?? []; if ($atletas === []) { continue; } ?>
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-primary text-white py-2">
                    <h2 class="h6 mb-0 fw-bold">
                        <i class="fas fa-flag me-2"></i><?php echo htmlspecialchars((string)($bloque['nombre'] ?? 'Sin asociación')); ?>
                        <span class="badge bg-light text-primary ms-2"><?php echo count($atletas); ?> atleta(s)</span>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width:3.5rem;">Pos.</th>
                                    <th>Atleta</th>
                                    <th class="text-center">NUMFVD</th>
                                    <?php if ($mostrar_equipo): ?>
                                    <th>Equipo</th>
                                    <?php endif; ?>
                                    <th class="text-center">G</th>
                                    <th class="text-center">P</th>
                                    <th class="text-center">Efect.</th>
                                    <th class="text-center">Pts</th>
                                    <th class="text-center">Rnk</th>
                                    <th class="text-center">GFF</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($atletas as $a): ?>
                                <tr>
                                    <td class="text-center fw-semibold"><?php
                                        $pos = (int)($a['posicion'] ?? 0);
                                        echo $pos > 0 ? $pos : '—';
                                    ?></td>
                                    <td><?php echo htmlspecialchars((string)($a['nombre'] ?? '')); ?></td>
                                    <td class="text-center"><code><?php
                                        $nf = (int)($a['numfvd'] ?? 0);
                                        echo $nf > 0 ? $nf : (int)($a['id_usuario'] ?? 0);
                                    ?></code></td>
                                    <?php if ($mostrar_equipo): ?>
                                    <td class="small"><?php
                                        $eq = trim((string)($a['nombre_equipo'] ?? ''));
                                        if ($eq === '' && !empty($a['codigo_equipo'])) {
                                            $eq = (string)$a['codigo_equipo'];
                                        }
                                        echo htmlspecialchars($eq !== '' ? $eq : '—');
                                    ?></td>
                                    <?php endif; ?>
                                    <td class="text-center"><?php echo (int)($a['ganados'] ?? 0); ?></td>
                                    <td class="text-center"><?php echo (int)($a['perdidos'] ?? 0); ?></td>
                                    <td class="text-center"><?php echo (int)($a['efectividad'] ?? 0); ?></td>
                                    <td class="text-center"><?php echo (int)($a['puntos'] ?? 0); ?></td>
                                    <td class="text-center fw-semibold text-primary"><?php echo (int)($a['ptosrnk'] ?? 0); ?></td>
                                    <td class="text-center"><?php echo (int)($a['gff'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
