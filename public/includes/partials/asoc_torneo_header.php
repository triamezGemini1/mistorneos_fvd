<?php
/**
 * Franja de torneo vigente — panel operativo de asociación.
 *
 * @var array{
 *   torneo_id: int,
 *   torneo: ?array<string, mixed>,
 *   torneos_lista: list<array<string, mixed>>,
 *   estado: ?string,
 *   asociados: list<array{id: int, nombre: string}>,
 *   mostrar_todos_asociados: bool,
 *   sin_vigente: bool
 * } $asocTorneoHdr
 * @var callable(int): string|null $asocTorneoUrlPanel
 */
if (!isset($asocTorneoHdr) || !is_array($asocTorneoHdr)) {
    return;
}
$sinVigente = !empty($asocTorneoHdr['sin_vigente']);
$torneo = $asocTorneoHdr['torneo'] ?? null;
$tid = (int) ($asocTorneoHdr['torneo_id'] ?? 0);
$estado = (string) ($asocTorneoHdr['estado'] ?? '');
$asociados = $asocTorneoHdr['asociados'] ?? [];
$mostrarTodos = !empty($asocTorneoHdr['mostrar_todos_asociados']);
$vigentes = $asocTorneoHdr['torneos_lista'] ?? [];
$urlPanelFn = $asocTorneoUrlPanel ?? static function (int $id): string {
    return class_exists('AppHelpers')
        ? AppHelpers::dashboard('asociacion_panel', ['torneo_id' => $id])
        : '#';
};
?>
<div class="asoc-torneo-header" role="region" aria-label="Torneo vigente del circuito">
    <?php if ($sinVigente || $torneo === null || $tid <= 0): ?>
        <p class="asoc-torneo-header__empty mb-0">
            <i class="fas fa-calendar-times me-2" aria-hidden="true"></i>
            No hay torneo en ejecución ni pendiente en los próximos <?= (int) AsociacionAdminHelper::DIAS_VENTANA_TORNEO_PANEL ?> días.
        </p>
    <?php else: ?>
        <?php
        $nombreTor = trim((string) ($torneo['nombre'] ?? ''));
        $fechator = trim((string) ($torneo['fechator'] ?? ''));
        $fechaFmt = $fechator !== '' ? date('d/m/Y', strtotime($fechator)) : '—';
        $estadoLabel = $estado === 'en_ejecucion' ? 'En ejecución' : 'Pendiente';
        $estadoClass = $estado === 'en_ejecucion' ? 'asoc-torneo-header__badge--run' : 'asoc-torneo-header__badge--pend';
        ?>
        <div class="asoc-torneo-header__main">
            <div class="asoc-torneo-header__torneo">
                <span class="asoc-torneo-header__label">Torneo vigente</span>
                <?php if (count($vigentes) > 1): ?>
                    <select class="asoc-torneo-header__select form-select form-select-sm" id="asocTorneoHeaderSelect" aria-label="Cambiar torneo vigente">
                        <?php foreach ($vigentes as $tx): ?>
                            <?php
                            $optId = (int) ($tx['id'] ?? 0);
                            $optNombre = trim((string) ($tx['nombre'] ?? ''));
                            $optFecha = !empty($tx['fechator']) ? ' · ' . date('d/m/Y', strtotime((string) $tx['fechator'])) : '';
                            ?>
                            <option value="<?= htmlspecialchars($urlPanelFn($optId), ENT_QUOTES, 'UTF-8') ?>"<?= $optId === $tid ? ' selected' : '' ?>>
                                <?= htmlspecialchars($optNombre . $optFecha, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <strong class="asoc-torneo-header__nombre"><?= htmlspecialchars($nombreTor) ?></strong>
                <?php endif; ?>
                <span class="asoc-torneo-header__fecha"><i class="far fa-calendar-alt me-1"></i><?= htmlspecialchars($fechaFmt) ?></span>
                <span class="asoc-torneo-header__badge <?= htmlspecialchars($estadoClass) ?>"><?= htmlspecialchars($estadoLabel) ?></span>
            </div>
            <?php if ($mostrarTodos && $asociados !== []): ?>
                <div class="asoc-torneo-header__asociados">
                    <span class="asoc-torneo-header__asoc-label">Asociaciones:</span>
                    <div class="asoc-torneo-header__chips" role="list">
                        <?php foreach ($asociados as $asoc): ?>
                            <span class="asoc-torneo-header__chip" role="listitem" title="<?= htmlspecialchars((string) ($asoc['nombre'] ?? '')) ?>">
                                <?= htmlspecialchars((string) ($asoc['nombre'] ?? '')) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif (!$mostrarTodos && $asociados !== []): ?>
                <div class="asoc-torneo-header__asociados asoc-torneo-header__asociados--single">
                    <span class="asoc-torneo-header__asoc-label">Asociación:</span>
                    <span class="asoc-torneo-header__chip asoc-torneo-header__chip--active"><?= htmlspecialchars((string) ($asociados[0]['nombre'] ?? '')) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php if (!$sinVigente && count($vigentes) > 1): ?>
<script>
(function () {
    var sel = document.getElementById('asocTorneoHeaderSelect');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var u = sel.value;
        if (u) window.location.href = u;
    });
})();
</script>
<?php endif; ?>
