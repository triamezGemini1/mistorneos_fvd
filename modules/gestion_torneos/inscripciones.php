<?php
/**
 * Vista: Inscripciones del Torneo
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$tid_panel = (int) ($torneo['id'] ?? 0);
$url_panel = class_exists('AppHelpers')
    ? AppHelpers::urlPanelTorneoReturn($tid_panel)
    : ($base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $tid_panel);
$total_inscritos = isset($total_inscritos) ? (int)$total_inscritos : 0;
$confirmados = isset($confirmados) ? (int)$confirmados : 0;
$contadores_inscripcion = isset($contadores_inscripcion) && is_array($contadores_inscripcion) ? $contadores_inscripcion : ['inscritos_total' => $total_inscritos, 'jugadores_confirmados' => $confirmados, 'equipos_activos' => 0];
$torneo_costo = (float) ($torneo['costo'] ?? 0);
$hombres = isset($hombres) ? (int)$hombres : 0;
$mujeres = isset($mujeres) ? (int)$mujeres : 0;
$resumen_clubes = $resumen_clubes ?? [];
$puede_confirmar_retirar = isset($puede_confirmar_retirar) ? $puede_confirmar_retirar : true;
$modalidad_inscripcion = (int) ($torneo['modalidad'] ?? 1);
$accion_inscribir_nuevo = $modalidad_inscripcion === 3 ? 'inscribir_equipo_sitio' : 'inscribir_sitio';
$url_inscribir_nuevo = $tid_panel > 0
    ? (class_exists('AppHelpers')
        ? AppHelpers::dashboard('torneo_gestion', ['action' => $accion_inscribir_nuevo, 'torneo_id' => $tid_panel])
        : ('index.php?page=torneo_gestion&action=' . rawurlencode($accion_inscribir_nuevo) . '&torneo_id=' . $tid_panel))
    : '#';
$label_inscribir_nuevo = $modalidad_inscripcion === 3 ? 'Inscribir equipo en sitio' : 'Inscribir jugador en sitio';
?>

<!-- Vista compacta: sin KPIs, sin resumen clubes, sin estado/pago en tabla -->
<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="breadcrumb-modern mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
        <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($url_panel); ?>"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></a></li>
        <li class="breadcrumb-item active">Inscripciones</li>
    </ol>
</nav>

<!-- Header del Torneo -->
<div class="card-modern fvd-hero mb-4 fvd-gradient-header">
    <div class="d-flex justify-content-between align-items-center p-4">
        <div>
            <h2 class="mb-2" style="color: white; font-weight: 700;">
                <i class="fas fa-users me-2"></i>
                Inscripciones - <?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?>
            </h2>
            <div class="d-flex gap-4 flex-wrap" style="opacity: 0.9; font-size: 0.9rem;">
                <span><i class="fas fa-calendar-alt me-1"></i> <?php echo date('d/m/Y', strtotime($torneo['fechator'] ?? 'now')); ?></span>
                <span><i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($torneo['club_nombre'] ?? 'N/A'); ?></span>
            </div>
        </div>
        <div class="text-end">
            <a href="<?php echo htmlspecialchars($url_panel); ?>" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-2"></i> Retornar al Panel
            </a>
        </div>
    </div>
</div>

<!-- Botón retorno al panel (visible debajo del header) -->
<div class="mb-3">
    <a href="<?php echo htmlspecialchars($url_panel); ?>" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Volver al panel del torneo
    </a>
</div>

<!-- Botón Agregar Jugador (solo si el torneo no ha iniciado) -->
<?php if (!$torneo_iniciado): ?>
<div class="row mb-4">
    <div class="col-12">
        <a href="<?php echo htmlspecialchars($url_inscribir_nuevo); ?>"
           class="btn btn-success btn-lg<?= $url_inscribir_nuevo === '#' ? ' disabled' : '' ?>">
            <i class="fas fa-user-plus me-2"></i> <?php echo htmlspecialchars($label_inscribir_nuevo); ?>
        </a>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info mb-4">
    <i class="fas fa-info-circle me-2"></i>
    <strong>El torneo ya ha iniciado.</strong> No se pueden agregar nuevos jugadores.
    <?php
    $retirados = $retirados ?? [];
    $es_modalidad_equipos = isset($torneo['modalidad']) && (int)$torneo['modalidad'] === 3;
    if (!empty($retirados) && !$es_modalidad_equipos):
        $url_sustituir = $base_url . ($use_standalone ? '?' : '&') . 'action=sustituir_jugador&torneo_id=' . (int)$torneo['id'];
    ?>
    <span class="ms-2">
        <a href="<?= htmlspecialchars($url_sustituir) ?>" class="btn btn-warning btn-sm ms-2">
            <i class="fas fa-user-exchange me-1"></i> Sustituir jugador retirado
        </a>
    </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Listado de Inscritos -->
<div class="card-modern inscripciones-compacta" style="box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 10px;">
    <div class="card-header-modern p-3 fvd-card-head">
        <h5 class="mb-0 fw-bold" style="color: #1f2937;">
            <i class="fas fa-list me-2" style="color: #10b981;"></i>
            Listado de Inscritos (<?php echo $total_inscritos; ?>)
        </h5>
    </div>
    <div class="card-body-modern p-4">
        <?php if (empty($inscritos)): ?>
            <div class="alert alert-info text-center py-5">
                <i class="fas fa-info-circle fa-3x mb-3" style="opacity: 0.5;"></i>
                <h5>No hay inscritos registrados</h5>
                <p class="text-muted mb-0">
                    <?php if (!$torneo_iniciado): ?>
                        Puedes comenzar inscribiendo jugadores usando el botón superior.
                    <?php else: ?>
                        El torneo ya ha iniciado y no se pueden agregar nuevos inscritos.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="border-radius: 8px; overflow: hidden;">
                    <thead style="background: #f9fafb;">
                        <tr>
                            <th style="border: none; padding: 8px; font-weight: 600;">#</th>
                            <th style="border: none; padding: 8px; font-weight: 600;">Jugador</th>
                            <th style="border: none; padding: 8px; font-weight: 600;">Username</th>
                            <th style="border: none; padding: 8px; font-weight: 600;">Club</th>
                            <th style="border: none; padding: 8px; font-weight: 600; text-align: center;">Género</th>
                            <th style="border: none; padding: 8px; font-weight: 600; text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $contador = 1;
                        $club_actual = '';
                        $csrf = class_exists('CSRF') ? CSRF::token() : '';
                        foreach ($inscritos as $inscrito): 
                            $nuevo_club = $inscrito['nombre_club'] ?? 'Sin Club';
                            if ($club_actual !== $nuevo_club):
                                $club_actual = $nuevo_club;
                        ?>
                        <tr style="background: rgba(99, 102, 241, 0.05);">
                            <td colspan="6" style="border: none; padding: 8px 12px; font-weight: 600; color: #6366f1;">
                                <i class="fas fa-building me-2"></i><?php echo htmlspecialchars($club_actual); ?>
                            </td>
                        </tr>
                        <?php endif;
                            $estatus = $inscrito['estatus'] ?? 0;
                            $estatus_num = is_numeric($estatus) ? (int)$estatus : (InscritosHelper::ESTATUS_REVERSE_MAP[$estatus] ?? 0);
                            $es_retirado = InscritosHelper::esRetirado($estatus);
                            $es_pagado = InscritosHelper::esConfirmado($estatus);
                            $es_pendiente = !$es_retirado && !$es_pagado;
                        ?>
                        <tr style="transition: background 0.2s;">
                            <td style="border: none; padding: 8px;"><?php echo $contador++; ?></td>
                            <td style="border: none; padding: 8px;">
                                <strong><?php echo htmlspecialchars($inscrito['nombre_completo'] ?? 'N/A'); ?></strong>
                            </td>
                            <td style="border: none; padding: 8px;">
                                <?php echo htmlspecialchars($inscrito['username'] ?? '-'); ?>
                            </td>
                            <td style="border: none; padding: 8px;">
                                <?php echo htmlspecialchars($inscrito['nombre_club'] ?? 'Sin Club'); ?>
                            </td>
                            <td style="border: none; padding: 8px; text-align: center;">
                                <?php 
                                $sexo = $inscrito['sexo'] ?? '';
                                if ($sexo == 1 || strtoupper($sexo) === 'M') {
                                    echo '<span class="badge bg-info"><i class="fas fa-mars me-1"></i>M</span>';
                                } elseif ($sexo == 2 || strtoupper($sexo) === 'F') {
                                    echo '<span class="badge bg-warning"><i class="fas fa-venus me-1"></i>F</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">-</span>';
                                }
                                ?>
                            </td>
                            <td style="border: none; padding: 8px; text-align: center;">
                                <?php if (!empty($puede_confirmar_retirar)): ?>
                                <div class="btn-group btn-group-sm flex-wrap justify-content-center">
                                    <?php if ($torneo_costo > 0 && $es_pendiente && !$es_retirado): ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="action" value="enviar_recordatorio_pago_inscrito">
                                        <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int)($inscrito['id'] ?? 0); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? ''); ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm" title="WhatsApp + notificación al atleta">
                                            <i class="fab fa-whatsapp"></i> Recordatorio
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$es_retirado): ?>
                                    <form method="post" action="" class="d-inline" onsubmit="return confirm('¿Retirar a este jugador del torneo?');">
                                        <input type="hidden" name="action" value="cambiar_estatus_inscrito">
                                        <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int)($inscrito['id'] ?? 0); ?>">
                                        <input type="hidden" name="estatus" value="<?php echo (int) InscritosHelper::ESTATUS_RETIRADO_NUM; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? ''); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Retirar del evento">
                                            <i class="fas fa-user-minus"></i> Retirar
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small" title="Opciones bloqueadas (torneo cerrado)">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="mt-4 pt-3 border-top">
            <a href="<?php echo htmlspecialchars($url_panel); ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Retornar al panel del torneo
            </a>
        </div>
    </div>
</div>

<style>
.inscripciones-compacta .card-body-modern { padding: 0.75rem 1rem; }
.inscripciones-compacta .table { font-size: 0.875rem; margin-bottom: 0; }
.inscripciones-compacta .card-header-modern { padding: 0.5rem 0.75rem !important; }
.inscripciones-compacta .card-header-modern h5 { font-size: 1rem; }

.card-modern {
    background: white;
    border: 1px solid #e5e7eb;
    transition: transform 0.2s, box-shadow 0.2s;
}

.card-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
}

.card-header-modern {
    border-bottom: 2px solid #e5e7eb;
}

.card-body-modern {
    padding: 1.5rem;
}

.breadcrumb-modern {
    background: transparent;
    padding: 0;
    margin-bottom: 1.5rem;
}

.breadcrumb-modern .breadcrumb-item a {
    color: #6366f1;
    text-decoration: none;
}

.breadcrumb-modern .breadcrumb-item a:hover {
    text-decoration: underline;
}
</style>

<?php if (!empty($_SESSION['whatsapp_redirect_inscripcion'])): ?>
<script>
(function () {
    var url = <?php echo json_encode((string) $_SESSION['whatsapp_redirect_inscripcion'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    if (url) { window.open(url, '_blank', 'noopener'); }
})();
</script>
<?php unset($_SESSION['whatsapp_redirect_inscripcion']); endif; ?>

