<?php
declare(strict_types=1);
/**
 * Panel reutilizable: ubicación de atleta(s) en partiresul.
 * @var array<string, mixed>|null $preview_swap
 * @var array<string, mixed>|null $preview_reemplazo
 * @var bool $mostrar_confirmar_swap
 * @var bool $mostrar_confirmar_reemplazo
 */
?>
<?php
$renderAtleta = static function (array $atleta, string $titulo): void {
    $nf = (int) ($atleta['numfvd'] ?? 0);
    $uid = (int) ($atleta['id_usuario'] ?? 0);
    $nombre = (string) ($atleta['nombre'] ?? '');
    $ok = ! empty($atleta['ok']);
    $inscrito = ! empty($atleta['inscrito']);
    $ubs = $atleta['ubicaciones'] ?? [];
    ?>
    <div class="col-md-6">
      <div class="border rounded p-3 h-100 <?= $ok ? 'border-success bg-light' : 'border-danger bg-white' ?>">
        <div class="fw-bold mb-2"><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (! $inscrito): ?>
          <p class="text-danger mb-0 small"><?= htmlspecialchars((string) ($atleta['mensaje'] ?? 'No inscrito.'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
          <p class="mb-1 small">
            <strong>NUMFVD:</strong> <?= $nf > 0 ? (int) $nf : '—' ?>
            · <strong>id usuario:</strong> <?= $uid > 0 ? (int) $uid : '—' ?>
            <?php if ($nombre !== ''): ?>
              <br><strong>Nombre:</strong> <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </p>
          <?php if ($ubs === []): ?>
            <p class="text-danger small mb-0"><?= htmlspecialchars((string) ($atleta['mensaje'] ?? 'Sin mesa asignada.'), ENT_QUOTES, 'UTF-8') ?></p>
          <?php else: ?>
            <table class="table table-sm table-bordered mb-0 bg-white">
              <thead class="table-light">
                <tr><th>Ronda</th><th>Mesa</th><th>Sec.</th><th>id fila</th></tr>
              </thead>
              <tbody>
                <?php foreach ($ubs as $u): ?>
                <tr>
                  <td><?= (int) ($u['partida'] ?? 0) ?></td>
                  <td><?= (int) ($u['mesa'] ?? 0) ?></td>
                  <td><?= (int) ($u['secuencia'] ?? 0) ?></td>
                  <td><?= (int) ($u['id_partiresul'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>
<?php if (is_array($preview_swap ?? null)): ?>
  <div class="alert <?= ! empty($preview_swap['puede_proceder']) ? 'alert-info' : 'alert-danger' ?> mb-3">
    <div class="fw-bold mb-2">
      <i class="fas fa-map-marker-alt me-1"></i>
      Ubicación previa al intercambio — ronda <?= (int) ($preview_swap['ronda'] ?? 0) ?>
    </div>
    <?php if (! empty($preview_swap['errores'])): ?>
      <ul class="mb-2 small">
        <?php foreach ($preview_swap['errores'] as $err): ?>
          <li><?= htmlspecialchars((string) $err, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="mb-0 small fw-semibold text-danger">Operación suspendida: corrija los datos antes de intercambiar.</p>
    <?php endif; ?>
    <div class="row g-3">
      <?php $renderAtleta($preview_swap['atleta_a'] ?? [], 'Atleta 1'); ?>
      <?php $renderAtleta($preview_swap['atleta_b'] ?? [], 'Atleta 2'); ?>
    </div>
  </div>
<?php endif; ?>

<?php if (is_array($preview_reemplazo ?? null)): ?>
  <div class="alert <?= ! empty($preview_reemplazo['puede_proceder']) ? 'alert-info' : 'alert-danger' ?> mb-3">
    <div class="fw-bold mb-2">
      <i class="fas fa-map-marker-alt me-1"></i>
      Ubicación previa al reemplazo (alcance: <?= htmlspecialchars((string) ($preview_reemplazo['alcance'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)
    </div>
    <?php if (! empty($preview_reemplazo['errores'])): ?>
      <ul class="mb-2 small">
        <?php foreach ($preview_reemplazo['errores'] as $err): ?>
          <li><?= htmlspecialchars((string) $err, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="mb-0 small fw-semibold text-danger">Operación suspendida hasta ubicar al sustituido en partiresul.</p>
    <?php endif; ?>
    <div class="row g-3">
      <?php $renderAtleta($preview_reemplazo['sustituido'] ?? [], 'Sustituido (sale)'); ?>
      <?php $renderAtleta($preview_reemplazo['sustituto'] ?? [], 'Sustituto (entra)'); ?>
    </div>
  </div>
<?php endif; ?>
