<?php
/**
 * Mostrar Resultados del Torneo (clasificación paginada, GFF y tarjetas correctas).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../../lib/ResultadosReportePaginacion.php';
require_once __DIR__ . '/../../lib/InscritosReporteStatsHelper.php';
require_once __DIR__ . '/../../lib/Tournament/Services/PaginationService.php';

if (!$tabla_partiresul_existe) {
    echo '<div class="alert alert-danger">';
    echo '<h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Tabla partiresul no encontrada</h6>';
    echo '<p class="mb-2">La tabla <code>partiresul</code> no existe. Para ver resultados, debe crear esta tabla primero.</p>';
    echo '<p class="mb-0">Ejecute: <code>php scripts/migrate_partiresul_table.php</code></p>';
    echo '</div>';
    return;
}

$items_por_pagina = ResultadosReportePaginacion::PER_PAGE;
$pagina_raw = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$participantes = [];
$total_participantes = 0;
$pagina_actual = 1;
$total_paginas = 1;

$estadisticas_partidas = [
    'total_rondas' => 0,
    'total_partidas' => 0,
    'partidas_registradas' => 0,
];

try {
    $stmt = $pdo->prepare('
        SELECT
            COUNT(DISTINCT partida) AS total_rondas,
            COUNT(*) AS total_partidas,
            COUNT(CASE WHEN registrado = 1 THEN 1 END) AS partidas_registradas
        FROM partiresul
        WHERE id_torneo = ?
    ');
    $stmt->execute([$torneo_id]);
    $estadisticas_partidas = $stmt->fetch(PDO::FETCH_ASSOC) ?: $estadisticas_partidas;

    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) FROM inscritos i
        WHERE i.torneo_id = ? AND i.estatus != 'retirado'
    ");
    $stmt_count->execute([$torneo_id]);
    $total_participantes = (int) $stmt_count->fetchColumn();

    $pagina_actual = 1;
    $total_paginas = 1;
    $offset = 0;
    $p = \Tournament\Services\PaginationService::getParams($total_participantes, $pagina_raw, $items_por_pagina);
    $pagina_actual = $p['page'];
    $total_paginas = $p['total_pages'];
    $offset = (int) $p['offset'];
    $limitSql = ' LIMIT ' . (int) $p['limit'] . ' OFFSET ' . $offset;

    InscritosReporteStatsHelper::ensureColumnas($pdo);
    $cols = InscritosReporteStatsHelper::expresionesSelectClasificacion('i');

    $sql = '
        SELECT
            i.id_usuario,
            i.posicion,
            i.ganados,
            i.perdidos,
            i.efectividad,
            i.puntos,
            i.ptosrnk,
            i.sancion,
            ' . $cols['gff'] . ',
            ' . $cols['tarjeta'] . ',
            u.nombre AS usuario_nombre,
            c.nombre AS club_nombre
        FROM inscritos i
        INNER JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ?
          AND i.estatus != \'retirado\'
        ORDER BY
            CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
            i.ganados DESC,
            i.efectividad DESC,
            i.puntos DESC
        ' . $limitSql;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('mostrar_resultados: ' . $e->getMessage());
    $participantes = [];
}

$base_url_return = 'index.php?page=tournament_admin&torneo_id=' . (int) $torneo_id;
?>

<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="fas fa-chart-bar me-2"></i>Resultados del Torneo
        </h5>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?= (int) ($estadisticas_partidas['total_rondas'] ?? 0) ?></h3>
                        <p class="mb-0">Rondas Jugadas</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?= (int) ($estadisticas_partidas['total_partidas'] ?? 0) ?></h3>
                        <p class="mb-0">Total Partidas</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?= (int) ($estadisticas_partidas['partidas_registradas'] ?? 0) ?></h3>
                        <p class="mb-0">Partidas Registradas</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Clasificación General</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($participantes === []): ?>
                    <div class="alert alert-info m-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>No hay resultados disponibles aún.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Jugador</th>
                                    <th class="text-center">Club</th>
                                    <th class="text-center">G</th>
                                    <th class="text-center">P</th>
                                    <th class="text-center">GFF</th>
                                    <th class="text-center">Tarj.</th>
                                    <th class="text-center">Efect.</th>
                                    <th class="text-center">Puntos</th>
                                    <th class="text-center">Pts. Rnk.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $posicion_lista = $offset + 1;
                                foreach ($participantes as $jugador):
                                    $pos = (int) ($jugador['posicion'] ?? 0);
                                    if ($pos <= 0) {
                                        $pos = $posicion_lista;
                                    }
                                    $rowClass = $pos <= 3 ? 'table-warning' : '';
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="text-center fw-bold"><?= $pos ?></td>
                                        <td><strong><?= htmlspecialchars((string) ($jugador['usuario_nombre'] ?? 'N/A')) ?></strong></td>
                                        <td class="text-center"><?= htmlspecialchars((string) ($jugador['club_nombre'] ?? 'Sin club')) ?></td>
                                        <td class="text-center"><span class="badge bg-success"><?= (int) ($jugador['ganados'] ?? 0) ?></span></td>
                                        <td class="text-center"><span class="badge bg-danger"><?= (int) ($jugador['perdidos'] ?? 0) ?></span></td>
                                        <td class="text-center"><span class="badge bg-warning text-dark"><?= (int) ($jugador['gff'] ?? 0) ?></span></td>
                                        <td class="text-center"><?= htmlspecialchars(ResultadosReporteData::tarjetaTexto($jugador['tarjeta'] ?? 0)) ?></td>
                                        <td class="text-center">
                                            <?php $ef = (int) ($jugador['efectividad'] ?? 0); ?>
                                            <span class="fw-bold <?= $ef >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= $ef >= 0 ? '+' : '' ?><?= $ef ?>
                                            </span>
                                        </td>
                                        <td class="text-center"><strong><?= (int) ($jugador['puntos'] ?? 0) ?></strong></td>
                                        <td class="text-center"><span class="badge bg-primary"><?= (int) ($jugador['ptosrnk'] ?? 0) ?></span></td>
                                    </tr>
                                <?php
                                    $posicion_lista++;
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_participantes > 0): ?>
                    <div class="p-3 border-top">
                        <?= ResultadosReportePaginacion::renderForTorneoReport(
                            $pagina_actual,
                            $total_paginas,
                            $total_participantes,
                            $items_por_pagina,
                            $base_url_return,
                            false,
                            ['action' => 'mostrar_resultados', 'torneo_id' => (int) $torneo_id],
                            'jugadores'
                        ) ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <p class="small text-muted mb-0">
            <strong>GFF:</strong> victorias por forfait (FF) o tarjeta roja/negra del rival o compañero (TR); misma contabilidad.
            <strong>Tarj.:</strong> tarjeta disciplinaria vigente (máx. en partiresul).
        </p>
    </div>
</div>
