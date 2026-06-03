<?php
/**
 * Fila del listado HTML en Gestionar Inscripciones (registrants).
 * Requiere: $item, $es_torneo_ranking, $filter_torneo, $torneo_cerrado_reg
 */
$est = $item['estatus'] ?? 0;
$es_confirmado = InscritosHelper::esConfirmado($est);
$es_retirado = InscritosHelper::esRetirado($est);
$uid_est = (int) ($item['id'] ?? 0);
?>
<tr data-inscripcion-row="<?= $uid_est ?>">
    <td><code><?= (int) ($item['id_usuario'] ?? 0) ?></code></td>
    <td><code><?= (int) ($item['usuario_numfvd'] ?? 0) ?></code></td>
    <td class="text-truncate" style="max-width:14rem;" title="<?= htmlspecialchars((string) ($item['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <strong class="fw-semibold"><?= htmlspecialchars((string) ($item['nombre'] ?? '')) ?></strong>
    </td>
    <td class="text-center">
        <?php
        $sexo_text = $item['sexo'] ?? '';
        if ($sexo_text === 'M' || $sexo_text == 1) {
            echo '<span class="badge bg-info">M</span>';
        } elseif ($sexo_text === 'F' || $sexo_text == 2) {
            echo '<span class="badge bg-success">F</span>';
        } else {
            echo '<span class="badge bg-secondary">O</span>';
        }
        ?>
    </td>
    <td class="text-nowrap"><?= htmlspecialchars((string) ($item['celular'] ?? 'N/A')) ?></td>
    <td class="registrants-club-cell">
        <?php
        $cid_insc = (int) ($item['club_id'] ?? $item['id_club'] ?? 0);
        if (!empty($permite_cambiar_asoc_reg) && empty($torneo_cerrado_reg) && !empty($clubes_inscripcion_opts)): ?>
        <select class="form-select form-select-sm js-cambiar-asoc-inscrito"
                data-inscripcion-id="<?= $uid_est ?>"
                data-usuario-id="<?= (int) ($item['id_usuario'] ?? 0) ?>"
                data-torneo-id="<?= (int) $filter_torneo ?>"
                title="Cambiar asociación">
            <?php foreach ($clubes_inscripcion_opts as $cOpt):
                $cidOpt = (int) ($cOpt['id'] ?? 0);
                if ($cidOpt <= 0) {
                    continue;
                }
            ?>
            <option value="<?= $cidOpt ?>"<?= $cid_insc === $cidOpt ? ' selected' : '' ?>>
                <?= htmlspecialchars(ClubHelper::etiquetaAsociacion($cidOpt, (string) ($cOpt['nombre'] ?? ''))) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
            <?= $cid_insc > 0
                ? htmlspecialchars(ClubHelper::etiquetaAsociacion($cid_insc, (string) ($item['club_nombre'] ?? '')))
                : '—' ?>
        <?php endif; ?>
    </td>
    <?php if ($es_torneo_ranking): ?>
    <td class="text-center">
        <span class="badge bg-primary"><?= (int) ($item['jugador_posi_rnk'] ?? 0) ?></span>
    </td>
    <?php else: ?>
    <td class="text-center registrants-estatus-cell">
        <?php if ($es_retirado): ?>
            <span class="badge bg-dark">Retirado</span>
        <?php elseif (empty($torneo_cerrado_reg)): ?>
            <button type="button"
                class="btn btn-sm px-2 py-0 js-pago-celda-inscrito <?= $es_confirmado ? 'btn-success' : 'btn-warning text-dark' ?>"
                data-inscripcion-id="<?= $uid_est ?>"
                data-torneo-id="<?= (int) $filter_torneo ?>"
                data-estado="<?= $es_confirmado ? 'confirmado' : 'pendiente' ?>"
                data-nombre="<?= htmlspecialchars((string) ($item['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                title="<?= $es_confirmado ? 'Ver recibo de pago emitido' : 'Pagar e emitir recibo' ?>">
                <?= $es_confirmado ? 'Confirmado' : 'Pagar' ?>
            </button>
        <?php elseif ($es_confirmado): ?>
            <span class="badge bg-success">Confirmado</span>
        <?php else: ?>
            <span class="badge bg-warning text-dark">Pendiente</span>
        <?php endif; ?>
    </td>
    <?php endif; ?>
    <td class="text-center">
        <?php if (!$es_retirado && empty($torneo_cerrado_reg)): ?>
            <button type="button" class="btn btn-sm btn-outline-dark js-retirar-inscrito"
                    data-inscripcion-id="<?= $uid_est ?>"
                    data-torneo-id="<?= (int) $filter_torneo ?>"
                    data-pago-confirmado="<?= $es_confirmado ? '1' : '0' ?>"
                    data-usuario-id="<?= (int) ($item['id_usuario'] ?? 0) ?>"
                    data-nombre="<?= htmlspecialchars((string) ($item['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    data-cedula="<?= htmlspecialchars((string) ($item['cedula'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    data-club-id="<?= (int) ($item['club_id'] ?? $item['id_club'] ?? 0) ?>"
                    title="Retirar del torneo y liberar en disponibles">
                <i class="fas fa-user-slash"></i>
            </button>
        <?php else: ?>
            <span class="text-muted small">—</span>
        <?php endif; ?>
    </td>
    <td class="text-center">
        <div class="btn-group btn-group-sm registrants-report-actions flex-wrap justify-content-center">
        <?php if ($es_retirado && empty($torneo_cerrado_reg)): ?>
            <button type="button" class="btn btn-outline-danger js-eliminar-inscripcion-retirado"
                    title="Eliminar inscripción y liberar jugador"
                    data-inscripcion-id="<?= (int) ($item['id'] ?? 0) ?>"
                    data-torneo-id="<?= (int) $filter_torneo ?>"
                    data-nombre="<?= htmlspecialchars((string) ($item['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <i class="fas fa-trash-alt"></i>
            </button>
        <?php elseif (!$es_retirado && empty($torneo_cerrado_reg)): ?>
            <button type="button" class="btn btn-outline-primary js-enviar-mensaje-inscrito" title="Enviar recordatorio de pago"
                    data-inscripcion-id="<?= (int) ($item['id'] ?? 0) ?>" data-torneo-id="<?= (int) $filter_torneo ?>">
                <i class="fas fa-paper-plane"></i>
            </button>
            <?php if ($es_confirmado): ?>
            <button type="button" class="btn btn-outline-success js-recibo-inscrito" title="Ver recibo"
                    data-inscripcion-id="<?= (int) ($item['id'] ?? 0) ?>" data-torneo-id="<?= (int) $filter_torneo ?>">
                <i class="fas fa-receipt"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary js-revertir-pago-inscrito" title="Revertir a Pagar (confirmación doble)"
                    data-inscripcion-id="<?= (int) ($item['id'] ?? 0) ?>"
                    data-torneo-id="<?= (int) $filter_torneo ?>"
                    data-nombre="<?= htmlspecialchars((string) ($item['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <i class="fas fa-undo"></i>
            </button>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </td>
</tr>
