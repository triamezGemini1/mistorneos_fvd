<?php
/**
 * Tarjeta de inscripción pública (post-registro).
 * Variables: $inscripcion_tarjeta (array), $torneo_id (int), $whatsapp_admin_links (list), $volver_url (string)
 */
if (!is_array($inscripcion_tarjeta ?? null)) {
    return;
}
$t = $inscripcion_tarjeta;
$torneo_id = (int)($torneo_id ?? ($t['torneo_id'] ?? 0));
$volver_url = $volver_url ?? 'landing-spa.php#eventos';
$whatsapp_admin_links = $whatsapp_admin_links ?? [];
?>
<div class="card border-success mb-4 shadow">
    <div class="card-header bg-success text-white text-center py-3">
        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Inscripción registrada</h5>
        <p class="mb-0 small opacity-90 mt-1"><?= htmlspecialchars($t['torneo_nombre'] ?? '') ?></p>
    </div>
    <div class="card-body">
        <p class="text-center text-muted small mb-3">
            <?= htmlspecialchars($t['fecha'] ?? '') ?>
            <?php if (!empty($t['lugar'])): ?> · <?= htmlspecialchars($t['lugar']) ?><?php endif; ?>
            <?php if (!empty($t['hora']) && ($t['hora'] ?? '—') !== '—'): ?> · <?= htmlspecialchars($t['hora']) ?><?php endif; ?>
        </p>
        <p class="text-center small mb-3">
            <strong><?= htmlspecialchars($t['modalidad'] ?? '') ?></strong>
            <?php if ((int)($t['rondas'] ?? 0) > 0): ?> · <?= (int)$t['rondas'] ?> rondas<?php endif; ?>
            <?php if ((float)($t['costo'] ?? 0) > 0): ?> · <span class="text-success fw-bold">$<?= number_format((float)$t['costo'], 2) ?></span><?php endif; ?>
        </p>
        <hr>
        <p class="text-center mb-1"><strong>Atleta</strong></p>
        <p class="text-center mb-2">
            <?= htmlspecialchars($t['atleta_nombre'] ?? '') ?><br>
            <span class="text-muted">Cédula <?= htmlspecialchars($t['cedula_mostrar'] ?? '') ?></span>
            <?php if (!empty($t['entidad_nombre'])): ?><br><span class="badge bg-secondary"><?= htmlspecialchars($t['entidad_nombre']) ?></span><?php endif; ?>
        </p>
        <?php if (!empty($t['numfvd']) || !empty($t['username']) || (!empty($t['password_temporal']) && !empty($t['es_usuario_nuevo']))): ?>
        <div class="alert alert-light border py-3 small mb-3">
            <p class="text-center mb-2 fw-bold"><i class="fas fa-id-badge me-1"></i>Datos de ingreso al torneo</p>
            <?php if (!empty($t['numfvd'])): ?>
            <p class="text-center mb-1"><span class="text-muted">NUMFVD:</span> <code class="fs-6"><?= (int)$t['numfvd'] ?></code></p>
            <?php endif; ?>
            <?php if (!empty($t['username'])): ?>
            <p class="text-center mb-1"><span class="text-muted">Usuario:</span> <code><?= htmlspecialchars($t['username']) ?></code></p>
            <?php endif; ?>
            <?php if (!empty($t['password_temporal']) && !empty($t['es_usuario_nuevo'])): ?>
            <p class="text-center mb-1">
                <span class="text-muted">Contraseña:</span>
                <code class="user-select-all"><?= htmlspecialchars((string)$t['password_temporal']) ?></code>
                <?php if (!empty($t['password_igual_usuario'])): ?>
                <br><span class="text-muted">(igual al usuario de acceso)</span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <?php if (!empty($t['credenciales_automaticas'])): ?>
            <p class="text-center mb-0 text-muted"><i class="fas fa-info-circle me-1"></i>Credenciales generadas automáticamente. Guárdelas para iniciar sesión.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="row align-items-center mt-3">
            <div class="col-sm-5 text-center mb-3 mb-sm-0">
                <img src="<?= htmlspecialchars($t['qr_url'] ?? '') ?>" alt="QR acceso" class="img-fluid border rounded" style="max-width:140px" loading="lazy">
            </div>
            <div class="col-sm-7 text-center text-sm-start small">
                <p class="mb-2">Escanee el QR o use su enlace de acceso al portal para notificaciones y perfil.</p>
                <a href="<?= htmlspecialchars($t['perfil_url'] ?? '#') ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">Abrir mi acceso</a>
            </div>
        </div>
        <?php if (!empty($whatsapp_admin_links)): ?>
        <hr>
        <p class="small mb-2"><i class="fab fa-whatsapp text-success me-1"></i>Notificar al administrador general (opcional):</p>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <?php foreach ($whatsapp_admin_links as $wa): ?>
            <a href="<?= htmlspecialchars($wa['url'] ?? '#') ?>" target="_blank" rel="noopener" class="btn btn-success btn-sm">
                <i class="fab fa-whatsapp me-1"></i><?= htmlspecialchars($wa['admin_nombre'] ?? 'Admin') ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
            <?php if ($torneo_id > 0 && !empty($t['cedula_mostrar'])): ?>
            <a href="reportar_pago_evento_masivo.php?torneo_id=<?= $torneo_id ?>&cedula=<?= urlencode((string)$t['cedula_mostrar']) ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-money-bill-wave me-1"></i>Reportar pago
            </a>
            <?php endif; ?>
            <a href="tournament_register.php?torneo_id=<?= $torneo_id ?>&ver_tarjeta=1" class="btn btn-outline-success btn-sm">
                <i class="fas fa-id-card me-1"></i>Ver tarjeta de nuevo
            </a>
            <a href="<?= htmlspecialchars($volver_url) ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Volver al portal
            </a>
        </div>
    </div>
</div>
