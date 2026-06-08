<?php
/**
 * Publicación de Resultados - Tabla de clasificación (Desktop).
 * Lee posición persistida en inscritos (sin recalcular en cada consulta).
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

if ($torneo_id > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nombre, modalidad FROM tournaments WHERE id = ?");
        $st->execute([$torneo_id]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
        if ($torneo) {
            $ent = logica_torneo_where_entidad('i');
            $st = $pdo->prepare("
                SELECT i.id, i.id_usuario, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.ptosrnk, i.sancion, i.tarjeta,
                       u.nombre AS jugador_nombre, u.username, u.cedula,
                       c.nombre AS club_nombre
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(i.id_club, 0), NULLIF(u.club_id, 0))
                WHERE i.torneo_id = ? AND i.estatus != 4" . $ent['sql'] . "
                ORDER BY CAST(i.ganados AS INTEGER) DESC, CAST(i.efectividad AS INTEGER) DESC, CAST(i.puntos AS INTEGER) DESC, i.id_usuario ASC
            ");
            $st->execute(array_merge([$torneo_id], $ent['bind']));
            $posiciones = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
    }
}

$torneos_lista = [];
try {
    $sql = "SELECT id, nombre FROM tournaments ORDER BY id DESC";
    $params = [];
    if ($entidad_id > 0) {
        $sql = "SELECT id, nombre FROM tournaments WHERE entidad = ? ORDER BY id DESC";
        $params = [$entidad_id];
    }
    $st = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $st->execute($params);
    $torneos_lista = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$pageTitle = 'Posiciones';
$desktopActive = 'panel';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3">
    <h2 class="h4 mb-3"><i class="fas fa-trophy text-primary me-2"></i>Tabla de Posiciones</h2>
    <p class="text-muted mb-4">Clasificación con los mismos criterios de desempate que la web (ganados, efectividad, puntos).</p>

    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body">
            <label class="form-label fw-bold">Torneo</label>
            <select class="form-select" id="selectTorneo" onchange="window.location='posiciones.php?torneo_id='+this.value">
                <option value="0">-- Seleccione torneo --</option>
                <?php foreach ($torneos_lista as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $torneo_id === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['nombre'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($torneo && $torneo_id > 0): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0"><?= htmlspecialchars($torneo['nombre'] ?? 'Torneo') ?> — Clasificación General</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($posiciones)): ?>
            <div class="alert alert-info m-3">Aún no hay jugadores inscritos o no hay posiciones calculadas.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Pos</th>
                            <th>ID</th>
                            <th>Jugador</th>
                            <th>Club</th>
                            <th>G</th>
                            <th>P</th>
                            <th>Efect.</th>
                            <th>Puntos</th>
                            <th>Pts. Rnk</th>
                            <th>Sanc.</th>
                            <th>Tarj.</th>
                            <th>Resumen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pos_actual = 0;
                        foreach ($posiciones as $pos):
                            $pos_actual++;
                            $posicion_db = (int)($pos['posicion'] ?? 0);
                            $posicion_mostrar = $posicion_db > 0 ? $posicion_db : $pos_actual;
                            $medalla = '';
                            if ($posicion_mostrar == 1) $medalla = 'table-warning';
                            elseif ($posicion_mostrar == 2) $medalla = 'table-secondary';
                            elseif ($posicion_mostrar == 3) $medalla = 'table-light';
                        ?>
                        <tr class="<?= $medalla ?>">
                            <td><strong><?= $posicion_mostrar ?></strong></td>
                            <td><?= (int)($pos['id_usuario'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($pos['jugador_nombre'] ?? $pos['username'] ?? '') ?></td>
                            <td><?= htmlspecialchars($pos['club_nombre'] ?? '—') ?></td>
                            <td><?= (int)($pos['ganados'] ?? 0) ?></td>
                            <td><?= (int)($pos['perdidos'] ?? 0) ?></td>
                            <td><?= (int)($pos['efectividad'] ?? 0) ?></td>
                            <td><?= (int)($pos['puntos'] ?? 0) ?></td>
                            <td><?= (int)($pos['ptosrnk'] ?? 0) ?></td>
                            <td><?= (int)($pos['sancion'] ?? 0) ?></td>
                            <td><?= (int)($pos['tarjeta'] ?? 0) ?></td>
                            <td><a href="resumen_individual.php?torneo_id=<?= $torneo_id ?>&inscrito_id=<?= (int)($pos['id_usuario'] ?? 0) ?>" class="btn btn-sm btn-outline-primary">Ver</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer bg-light">
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            <a href="torneos.php" class="btn btn-outline-secondary btn-sm">Torneos</a>
            <a href="captura.php?torneo_id=<?= $torneo_id ?>" class="btn btn-outline-primary btn-sm ms-2">Captura de Resultados</a>
        </div>
    </div>
    <?php elseif ($torneo_id > 0): ?>
    <div class="alert alert-warning">Torneo no encontrado.</div>
    <?php endif; ?>
</div>
</main></body></html>
