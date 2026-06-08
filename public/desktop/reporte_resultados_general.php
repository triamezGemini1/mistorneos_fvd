<?php
/**
 * Reporte general de resultados (Desktop).
 * Participantes con club y enlace a resumen; detalle de partidas con GFF, bye, tarjeta, sanción, observaciones.
 */
declare(strict_types=1);
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';
require_once __DIR__ . '/../../desktop/core/db_bridge.php';
require_once __DIR__ . '/../../desktop/core/logica_torneo.php';

$pdo = DB_Local::pdo();
$entidad_id = DB::getEntidadId();
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$torneo = null;
$posiciones = [];
$detalle_partidas = [];
$torneos = [];

$ent_i = logica_torneo_where_entidad('i');
$ent_p = $entidad_id > 0 ? [' AND pr.entidad_id = ?', [$entidad_id]] : ['', []];

try {
    if ($entidad_id > 0) {
        $st = $pdo->prepare("SELECT id, nombre, rondas FROM tournaments WHERE entidad = ? ORDER BY id DESC");
        $st->execute([$entidad_id]);
        $torneos = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $torneos = $pdo->query("SELECT id, nombre, rondas FROM tournaments ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($torneo_id > 0) {
        $st = $pdo->prepare("SELECT id, nombre, rondas FROM tournaments WHERE id = ?");
        $st->execute([$torneo_id]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
        if ($torneo) {
            $st = $pdo->prepare("
                SELECT i.id, i.id_usuario, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.sancion, i.tarjeta,
                       u.nombre AS jugador_nombre, u.username,
                       c.nombre AS club_nombre
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(i.id_club, 0), NULLIF(u.club_id, 0))
                WHERE i.torneo_id = ? AND CAST(i.estatus AS TEXT) != '4'" . $ent_i['sql'] . "
                ORDER BY CAST(i.ganados AS INTEGER) DESC, CAST(i.efectividad AS INTEGER) DESC, CAST(i.puntos AS INTEGER) DESC, i.id_usuario ASC
            ");
            $st->execute(array_merge([$torneo_id], $ent_i['bind']));
            $posiciones = $st->fetchAll(PDO::FETCH_ASSOC);

            $st = $pdo->prepare("
                SELECT pr.partida, pr.mesa, pr.secuencia, pr.resultado1, pr.resultado2, pr.efectividad,
                       pr.ff, pr.tarjeta, pr.sancion, pr.chancleta, pr.zapato, pr.observaciones,
                       u.nombre AS jugador_nombre,
                       c.nombre AS club_nombre
                FROM partiresul pr
                INNER JOIN usuarios u ON pr.id_usuario = u.id
                LEFT JOIN inscritos i ON i.id_usuario = pr.id_usuario AND i.torneo_id = pr.id_torneo
                LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(i.id_club, 0), NULLIF(u.club_id, 0))
                WHERE pr.id_torneo = ?" . $ent_p[0] . "
                ORDER BY pr.partida ASC, CAST(pr.mesa AS INTEGER) ASC, pr.secuencia ASC
            ");
            $st->execute(array_merge([$torneo_id], $ent_p[1]));
            $detalle_partidas = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {}

$pageTitle = 'Reporte general de resultados';
$desktopActive = 'panel';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3">
    <h2 class="h4 mb-3"><i class="fas fa-file-alt text-primary me-2"></i>Reporte general de resultados</h2>
    <p class="text-muted mb-3">Participantes con club y enlace a resumen; detalle de partidas con GFF, bye, tarjeta, sanción y observaciones.</p>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="get" action="reporte_resultados_general.php" class="row g-3 mb-0">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Torneo</label>
                    <select name="torneo_id" class="form-select">
                        <option value="0">-- Seleccione torneo --</option>
                        <?php foreach ($torneos as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $torneo_id === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['nombre'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search me-1"></i>Ver reporte</button>
                    <?php if ($torneo_id > 0): ?><a href="panel_torneo.php?torneo_id=<?= $torneo_id ?>" class="btn btn-outline-secondary">Panel</a><?php else: ?><a href="torneos.php" class="btn btn-outline-secondary">Torneos</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($torneo && $torneo_id > 0): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><?= htmlspecialchars($torneo['nombre'] ?? '') ?> — Participantes (con club y resumen)</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($posiciones)): ?>
            <div class="alert alert-info m-3">No hay participantes o posiciones calculadas.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Pos</th>
                            <th>Jugador</th>
                            <th>Club</th>
                            <th>G</th>
                            <th>P</th>
                            <th>Efect.</th>
                            <th>Puntos</th>
                            <th>Sanc.</th>
                            <th>Tarj.</th>
                            <th>Resumen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $pos_show = 0; foreach ($posiciones as $pos): $pos_show++; $posicion_db = (int)($pos['posicion'] ?? 0); $posicion_mostrar = $posicion_db > 0 ? $posicion_db : $pos_show; ?>
                        <tr>
                            <td><strong><?= $posicion_mostrar ?></strong></td>
                            <td><?= htmlspecialchars($pos['jugador_nombre'] ?? $pos['username'] ?? '') ?></td>
                            <td><?= htmlspecialchars($pos['club_nombre'] ?? '—') ?></td>
                            <td><?= (int)($pos['ganados'] ?? 0) ?></td>
                            <td><?= (int)($pos['perdidos'] ?? 0) ?></td>
                            <td><?= (int)($pos['efectividad'] ?? 0) ?></td>
                            <td><?= (int)($pos['puntos'] ?? 0) ?></td>
                            <td><?= (int)($pos['sancion'] ?? 0) ?></td>
                            <td><?= (int)($pos['tarjeta'] ?? 0) ?></td>
                            <td><a href="resumen_individual.php?torneo_id=<?= $torneo_id ?>&inscrito_id=<?= (int)($pos['id_usuario'] ?? 0) ?>&from=reporte" class="btn btn-sm btn-outline-primary">Ver</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Detalle del evento (partidas: GFF, Bye, tarjeta, sanción, observaciones)</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($detalle_partidas)): ?>
            <div class="alert alert-info m-3">No hay partidas registradas.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ronda</th>
                            <th>Mesa</th>
                            <th>Jugador</th>
                            <th>Club</th>
                            <th>Res.1</th>
                            <th>Res.2</th>
                            <th>Efect.</th>
                            <th>FF</th>
                            <th>Bye</th>
                            <th>Tarj.</th>
                            <th>Sanc.</th>
                            <th>Chanc.</th>
                            <th>Zap.</th>
                            <th>Observ.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalle_partidas as $p):
                            $mesa_raw = $p['mesa'] ?? 0;
                            $mesa = (int)$mesa_raw;
                            $es_bye = ($mesa === 0 || $mesa_raw === '0' || (string)$mesa_raw === '0');
                        ?>
                        <tr>
                            <td><?= (int)($p['partida'] ?? 0) ?></td>
                            <td><?= $es_bye ? 'BYE' : $mesa ?></td>
                            <td><?= htmlspecialchars($p['jugador_nombre'] ?? '') ?></td>
                            <td><?= htmlspecialchars($p['club_nombre'] ?? '—') ?></td>
                            <td><?= (int)($p['resultado1'] ?? 0) ?></td>
                            <td><?= (int)($p['resultado2'] ?? 0) ?></td>
                            <td><?= (int)($p['efectividad'] ?? 0) ?></td>
                            <td><?= !empty($p['ff']) ? 'Sí' : '—' ?></td>
                            <td><?= $es_bye ? 'Sí' : '—' ?></td>
                            <td><?= (int)($p['tarjeta'] ?? 0) ?></td>
                            <td><?= (int)($p['sancion'] ?? 0) ?></td>
                            <td><?= !empty($p['chancleta']) ? 'Sí' : '—' ?></td>
                            <td><?= !empty($p['zapato']) ? 'Sí' : '—' ?></td>
                            <td class="small"><?= htmlspecialchars(trim($p['observaciones'] ?? '') ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($torneo_id > 0): ?>
    <div class="alert alert-warning">Torneo no encontrado.</div>
    <?php else: ?>
    <p class="text-muted">Seleccione un torneo para ver el reporte.</p>
    <?php endif; ?>
</div>
</main></body></html>
