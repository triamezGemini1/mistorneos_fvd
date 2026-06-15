<?php
/**
 * Ranking nacional por NUMFVD: acumulado de torneos con ranking, tablas M/F y sync posi_rnk.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/RankingNumfvdAdminService.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$svc = new RankingNumfvdAdminService($pdo);
$basePage = 'index.php?page=ranking_numfvd_admin';

$genero = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : 'F';
if ($genero !== 'M' && $genero !== 'F') {
    $genero = 'F';
}

$flash = $_SESSION['ranking_numfvd_flash'] ?? null;
unset($_SESSION['ranking_numfvd_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf === '' || ! hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $_SESSION['ranking_numfvd_flash'] = [
            'ok' => false,
            'message' => 'Token de seguridad inválido. Recargue la página.',
        ];
        header('Location: ' . $basePage . '&genero=' . urlencode($genero));
        exit;
    }

    $accion = (string) ($_POST['accion'] ?? '');
    $generoPost = strtoupper((string) ($_POST['genero'] ?? $genero));
    if ($generoPost !== 'M' && $generoPost !== 'F') {
        $generoPost = 'F';
    }

    if ($accion === 'recalcular_torneos_ranking') {
        $res = $svc->recalcularEstadisticasTodosTorneosRanking();
        if (! empty($res['ok'])) {
            $_SESSION['ranking_numfvd_flash'] = [
                'ok' => true,
                'message' => sprintf(
                    'Recálculo completado: %d torneo(s) con ranking actualizados (estadísticas + ptosrnk por torneo).',
                    (int) ($res['procesados'] ?? 0)
                ),
                'detalle' => $res['torneos'] ?? [],
            ];
        } elseif ((int) ($res['procesados'] ?? 0) > 0) {
            $_SESSION['ranking_numfvd_flash'] = [
                'ok' => false,
                'message' => sprintf(
                    'Recálculo parcial: %d ok, %d con error. %s',
                    (int) ($res['procesados'] ?? 0),
                    (int) ($res['fallidos'] ?? 0),
                    implode(' ', $res['errores'] ?? [])
                ),
                'detalle' => $res['torneos'] ?? [],
            ];
        } else {
            $_SESSION['ranking_numfvd_flash'] = [
                'ok' => false,
                'message' => implode(' ', $res['errores'] ?? ['No se pudo recalcular ningún torneo.']),
                'detalle' => $res['torneos'] ?? [],
            ];
        }
        header('Location: ' . $basePage . '&genero=' . urlencode($generoPost));
        exit;
    }

    if ($accion === 'aplicar_posi_rnk') {
        $res = $svc->aplicarPosiRnkDesdeRanking($generoPost);
        if (! empty($res['ok'])) {
            $_SESSION['ranking_numfvd_flash'] = [
                'ok' => true,
                'message' => sprintf(
                    'posi_rnk actualizado (%s): %d usuario(s) modificados, %d sin cambio, %d NUMFVD sin usuario.',
                    $generoPost === 'F' ? 'Femenino' : 'Masculino',
                    (int) ($res['actualizados'] ?? 0),
                    (int) ($res['sin_cambio'] ?? 0),
                    (int) ($res['sin_usuario'] ?? 0)
                ),
            ];
        } else {
            $_SESSION['ranking_numfvd_flash'] = [
                'ok' => false,
                'message' => implode(' ', $res['errores'] ?? ['No se pudo actualizar posi_rnk.']),
            ];
        }
        header('Location: ' . $basePage . '&genero=' . urlencode($generoPost));
        exit;
    }

    header('Location: ' . $basePage);
    exit;
}

$data = $svc->construirRanking($genero);
$atletas = $data['atletas'];
$criterio = $data['criterio_orden'];
$torneosCatalogo = $data['torneos_catalogo'] ?? [];
$torneosProcesados = (int) ($data['torneos_procesados'] ?? count($torneosCatalogo));
$torneosConRanking = $svc->listarTorneosConRanking(false);
$torneosConRankingFinalizados = $svc->listarTorneosConRanking(true);
$torneosConRankingEnCurso = array_values(array_filter(
    $torneosConRanking,
    static fn (array $t): bool => (int) ($t['estatus'] ?? 0) !== 1
));
$tienePosiRnk = $svc->tieneColumnaPosiRnk();
$flashDetalle = is_array($flash) ? ($flash['detalle'] ?? []) : [];
$tituloGenero = $genero === 'F' ? 'Femenino' : 'Masculino';
$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];

$fmtFecha = static function (?string $f): string {
    if ($f === null || $f === '') {
        return '—';
    }
    $t = strtotime($f);

    return $t ? date('d/m/Y', $t) : '—';
};

$urlDetalleAtleta = static function (int $nf) use ($genero): string {
    return 'index.php?page=ranking_numfvd_detalle&genero=' . urlencode($genero) . '&numfvd=' . $nf;
};
?>
<div class="container-fluid py-3" style="max-width: 1400px;">
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h1 class="h4 mb-2"><i class="fas fa-medal me-2 text-warning"></i>Ranking nacional por NUMFVD</h1>
            <p class="text-muted mb-0 small">
                Incluye <strong>torneos con Ranking = Sí</strong> y solo atletas <strong>afiliados a la FVD</strong> (<code>usuarios.entidad</code> &gt; 0).
                Quienes participaron sin entidad son invitados externos y no entran en este ranking.
                Acumula por NUMFVD: <strong>TJ</strong>, <strong>PJ</strong>, <strong>PG</strong>, <strong>PP</strong>, <strong>Efect. Σ</strong>, <strong>Pts Σ</strong> y <strong>Ptos. Rnk</strong>.
            </p>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert <?= ! empty($flash['ok']) ? 'alert-success' : 'alert-danger' ?> py-2">
            <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
        </div>
        <?php if (is_array($flashDetalle) && $flashDetalle !== []): ?>
            <details class="mb-3 small">
                <summary class="text-muted" style="cursor:pointer;">Ver detalle por torneo recalculado</summary>
                <ul class="mt-2 mb-0">
                    <?php foreach ($flashDetalle as $ft): ?>
                        <li class="<?= ! empty($ft['ok']) ? 'text-success' : 'text-danger' ?>">
                            #<?= (int) ($ft['id'] ?? 0) ?> — <?= htmlspecialchars((string) ($ft['nombre'] ?? '')) ?>
                            <?php if (! empty($ft['error'])): ?>
                                <span class="text-danger">(<?= htmlspecialchars((string) $ft['error']) ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card shadow-sm mb-3 border-primary">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h2 class="h6 mb-1"><i class="fas fa-sync-alt me-1 text-primary"></i>Paso 1 — Recalcular torneos con ranking</h2>
                    <p class="small text-muted mb-0">
                        Ejecuta en bloque lo mismo que <strong>Actualizar estadísticas</strong> del panel de cada torneo,
                        en <strong>todos</strong> los que tengan <strong>Ranking = Sí</strong> — <em>finalizados y en curso</em>.
                        Total: <?= count($torneosConRanking) ?>
                        (<?= count($torneosConRankingEnCurso) ?> en curso, <?= count($torneosConRankingFinalizados) ?> finalizados).
                        Solo entran torneos con campo <strong>Ranking = Sí</strong> guardado en la ficha del torneo.
                    </p>
                </div>
                <form method="post" class="mb-0" onsubmit="return confirm('¿Recalcular estadísticas y ptosrnk de TODOS los torneos con ranking = Sí (incluye en curso y finalizados)? Puede tardar varios minutos.');">
                    <?= CSRF::input() ?>
                    <input type="hidden" name="accion" value="recalcular_torneos_ranking">
                    <input type="hidden" name="genero" value="<?= htmlspecialchars($genero) ?>">
                    <button type="submit" class="btn btn-primary" <?= $torneosConRanking === [] ? 'disabled' : '' ?>>
                        <i class="fas fa-database me-1"></i>Recalcular todos (ranking = Sí)
                    </button>
                </form>
            </div>
            <?php if ($torneosConRanking !== []): ?>
                <details class="mt-3 small">
                    <summary class="text-muted" style="cursor:pointer;">Ver lista de torneos que se recalcularán (<?= count($torneosConRanking) ?>)</summary>
                    <ul class="mt-2 mb-0">
                        <?php foreach ($torneosConRanking as $tr): ?>
                            <?php $enCurso = (int) ($tr['estatus'] ?? 0) !== 1; ?>
                            <li>
                                #<?= (int) ($tr['id'] ?? 0) ?> — <?= htmlspecialchars((string) ($tr['nombre'] ?? '')) ?>
                                · <?= htmlspecialchars($modalidades[(int) ($tr['modalidad'] ?? 0)] ?? '—') ?>
                                · <?= $fmtFecha((string) ($tr['fechator'] ?? '')) ?>
                                · <span class="badge bg-<?= $enCurso ? 'warning text-dark' : 'success' ?>"><?= $enCurso ? 'En curso' : 'Finalizado' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <ul class="nav nav-pills mb-0">
            <li class="nav-item">
                <a class="nav-link <?= $genero === 'F' ? 'active' : '' ?>" href="<?= htmlspecialchars($basePage . '&genero=F') ?>">
                    <i class="fas fa-venus me-1"></i>Femenino
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $genero === 'M' ? 'active' : '' ?>" href="<?= htmlspecialchars($basePage . '&genero=M') ?>">
                    <i class="fas fa-mars me-1"></i>Masculino
                </a>
            </li>
        </ul>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-secondary"><?= (int) $torneosProcesados ?> torneo(s) con ranking</span>
            <span class="badge bg-primary"><?= count($atletas) ?> atleta(s)</span>
            <?php if ($tienePosiRnk && $atletas !== []): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('¿Actualizar usuarios.posi_rnk para todos los NUMFVD del ranking <?= htmlspecialchars($tituloGenero) ?>? (Paso 2 tras recalcular torneos)');">
                    <?= CSRF::input() ?>
                    <input type="hidden" name="accion" value="aplicar_posi_rnk">
                    <input type="hidden" name="genero" value="<?= htmlspecialchars($genero) ?>">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-user-check me-1"></i>Paso 2 — Actualizar posi_rnk (<?= htmlspecialchars($tituloGenero) ?>)
                    </button>
                </form>
            <?php elseif (! $tienePosiRnk): ?>
                <span class="small text-danger">Columna usuarios.posi_rnk no disponible.</span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : '', '/') . '/ranking_atletas.php?genero=' . $genero) ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                <i class="fas fa-external-link-alt me-1"></i>Vista pública
            </a>
        </div>
    </div>

    <p class="text-muted small mb-2"><i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($criterio) ?></p>

    <?php if ($torneosCatalogo !== []): ?>
        <div class="small mb-3 p-2 bg-white border rounded">
            <strong>Catálogo ranking <?= htmlspecialchars($tituloGenero) ?> (<?= count($torneosCatalogo) ?>):</strong>
            <?php foreach ($torneosCatalogo as $tc): ?>
                <span class="badge bg-light text-dark border me-1 mb-1">
                    #<?= (int) ($tc['torneo_id'] ?? 0) ?>
                    <?= htmlspecialchars((string) ($tc['nombre'] ?? '')) ?>
                    (<?= htmlspecialchars($modalidades[(int) ($tc['modalidad'] ?? 0)] ?? '—') ?>)
                    <?php if (! empty($tc['genero_requerido'])): ?>
                        · <?= htmlspecialchars((string) $tc['genero_requerido']) ?>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning py-2 mb-3">
            No hay torneos con <strong>Ranking = Sí</strong> en base de datos. Edite cada torneo en
            <em>Torneos → Editar</em> y active el campo Ranking antes de recalcular.
        </div>
    <?php endif; ?>

    <?php if ($atletas === []): ?>
        <div class="alert alert-info mb-0">
            No hay atletas <?= htmlspecialchars(strtolower($tituloGenero)) ?> con NUMFVD en torneos con ranking.
            Verifique inscripciones, NUMFVD y ejecute el Paso 1 (recalcular).
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle bg-white mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width:3rem;">RNK</th>
                        <th class="text-center" style="width:5.5rem;">NUMFVD</th>
                        <th>Atleta</th>
                        <th class="text-center" title="Torneos jugados">TJ</th>
                        <th class="text-center" title="Partidas jugadas">PJ</th>
                        <th class="text-center" title="Partidas ganadas">PG</th>
                        <th class="text-center" title="Partidas perdidas">PP</th>
                        <th class="text-end" title="Suma efectividad">Efect. Σ</th>
                        <th class="text-end" title="Suma puntos torneo">Pts Σ</th>
                        <th class="text-end" title="Suma puntos ranking">Ptos. Rnk</th>
                        <th style="width:7rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($atletas as $a): ?>
                        <?php
                        $rk = (int) ($a['rank'] ?? 0);
                        $nf = (int) ($a['numfvd'] ?? 0);
                        $rowClass = $rk === 1 ? 'table-warning' : ($rk === 2 ? 'table-light' : ($rk === 3 ? 'table-secondary' : ''));
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="text-center fw-bold"><?= $rk ?></td>
                            <td class="text-center"><span class="badge bg-dark"><?= $nf ?></span></td>
                            <td><?php $atleta = $a; require __DIR__ . '/ranking_numfvd/_linea_nombre_atleta.php'; ?></td>
                            <td class="text-center"><?= (int) ($a['tj'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($a['pj'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($a['pg'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($a['pp'] ?? 0) ?></td>
                            <td class="text-end"><?= (int) ($a['total_efectividad'] ?? 0) ?></td>
                            <td class="text-end"><?= (int) ($a['total_puntos'] ?? 0) ?></td>
                            <td class="text-end fw-semibold"><?= (int) ($a['total_ptosrnk'] ?? 0) ?></td>
                            <td class="p-1">
                                <a href="<?= htmlspecialchars($urlDetalleAtleta($nf)) ?>" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="fas fa-external-link-alt me-1"></i>Ver detalle
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
