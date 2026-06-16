<?php

/**
 * Op Especiales — carga automática y auditoría analítica solo con tournaments.estatus = 9.
 * Swap entre mesas y reemplazo de id_usuario en partiresul: disponibles para cualquier torneo (con permisos).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Tournament/OpEspecialesHelper.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/RoundManagerHandler.php';
require_once __DIR__ . '/../lib/NumfvdHelper.php';
require_once __DIR__ . '/../lib/PartiresulJugadorHelper.php';

use Tournament\OpEspecialesHelper;
use Tournament\Handlers\RoundManagerHandler;

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneo_id = (int) ($_POST['torneo_id'] ?? $_GET['torneo_id'] ?? 0);
$view = trim((string) ($_GET['view'] ?? 'swap'));
if (! in_array($view, ['carga', 'swap', 'auditoria'], true)) {
    $view = 'swap';
}

if ($torneo_id <= 0) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('Indique un torneo para Operaciones Especiales'));
    exit;
}

if (! Auth::canAccessTournament($torneo_id)) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('Sin permisos para este torneo'));
    exit;
}

try {
    $torneo = OpEspecialesHelper::obtenerTorneoObligatorio($torneo_id);
} catch (Throwable $e) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode($e->getMessage()));
    exit;
}

$es_carga_especial = OpEspecialesHelper::esCargaEspecial($torneo);

$pdo = DB::pdo();

if (isset($_GET['ajax']) && $_GET['ajax'] === 'atleta_numfvd') {
    header('Content-Type: application/json; charset=utf-8');
    $numfvdAjax = (int) ($_GET['numfvd'] ?? 0);
    if ($numfvdAjax <= 0) {
        echo json_encode(['ok' => false, 'error' => 'NUMFVD inválido', 'nombre' => '', 'rondas' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uidAjax = NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneo_id, $numfvdAjax);
    $nombreAjax = '';
    $numfvdMostrar = $numfvdAjax;
    if ($uidAjax !== null) {
        $stNom = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ? LIMIT 1');
        $stNom->execute([$uidAjax]);
        $nombreAjax = trim((string) $stNom->fetchColumn());
        $nfIns = NumfvdHelper::numfvdInscrito($pdo, $torneo_id, $uidAjax);
        if ($nfIns > 0) {
            $numfvdMostrar = $nfIns;
        }
    }
    $rondasAjax = [];
    $claves = PartiresulJugadorHelper::clavesBusquedaDesdeIdentificador($pdo, $torneo_id, $numfvdAjax);
    if ($claves !== []) {
        $whereClaves = PartiresulJugadorHelper::sqlWhereClaveIn('pr', $claves);
        $stRondas = $pdo->prepare(
            "SELECT DISTINCT partida FROM partiresul pr
             WHERE pr.id_torneo = ? AND pr.mesa > 0 AND {$whereClaves}
             ORDER BY partida ASC"
        );
        $stRondas->execute(array_merge([$torneo_id], $claves));
        $rondasAjax = array_map('intval', $stRondas->fetchAll(PDO::FETCH_COLUMN));
    }
    echo json_encode([
        'ok' => $uidAjax !== null,
        'inscrito' => $uidAjax !== null,
        'nombre' => $nombreAjax,
        'numfvd' => $numfvdMostrar,
        'rondas' => $rondasAjax,
        'error' => $uidAjax === null ? 'NUMFVD no inscrito en este torneo' : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (int) Auth::id();
$modalidad = (int) ($torneo['modalidad'] ?? 0);

if (! class_exists('AppHelpers', false) && is_readable(__DIR__ . '/../lib/app_helpers.php')) {
    require_once __DIR__ . '/../lib/app_helpers.php';
}
/** @param array<string, scalar> $extra query adicional (p. ej. view, ronda) */
$opEspHref = function (array $extra = []) use ($torneo_id): string {
    $q = array_merge(['page' => 'op_especiales', 'torneo_id' => $torneo_id], $extra);
    if (class_exists('AppHelpers', false)) {
        return AppHelpers::url('index.php', $q);
    }

    return 'index.php?' . http_build_query($q);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && ! $es_carga_especial && in_array($view, ['carga', 'auditoria'], true)) {
    $_SESSION['info'] = 'La carga automática por ronda y la auditoría analítica solo están disponibles si el torneo tiene estatus «Carga especial / simulación» (9).';
    header('Location: ' . $opEspHref(['view' => 'swap']));
    exit;
}

/**
 * @return list<int>
 */
function op_especiales_parse_ids_from_post(string $key): array
{
    $raw = (string) ($_POST[$key] ?? '');
    $raw = str_replace([',', ';'], "\n", $raw);
    $out = [];
    foreach (preg_split('/\s+/', trim($raw)) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) {
            $out[] = (int) $p;
        }
    }

    return $out;
}

/**
 * Resuelve NUMFVD del torneo → id_usuario interno inscrito.
 *
 * @throws \RuntimeException
 */
function op_especiales_uid_desde_numfvd(PDO $pdo, int $torneoId, int $numfvd, string $etiqueta): int
{
    if ($numfvd <= 0) {
        throw new \RuntimeException('Indique un NUMFVD válido para ' . $etiqueta . '.');
    }
    $uid = NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneoId, $numfvd);
    if ($uid === null) {
        throw new \RuntimeException('NUMFVD ' . $numfvd . ' (' . $etiqueta . ') no está inscrito en este torneo.');
    }

    return $uid;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    CSRF::validate();
    $view_redirect = trim((string) ($_POST['return_view'] ?? $_GET['view'] ?? 'swap'));
    if (! in_array($view_redirect, ['carga', 'swap', 'auditoria'], true)) {
        $view_redirect = 'swap';
    }
    $action = trim((string) ($_POST['op_action'] ?? ''));

    try {
        if (in_array($action, ['aplicar_ff', 'aplicar_tarjetas', 'carga_masiva', 'generar_siguiente'], true) && ! OpEspecialesHelper::esCargaEspecial($torneo)) {
            throw new \RuntimeException('La carga automática y la generación asistida desde esta pantalla solo están disponibles con estatus de torneo «Carga especial / simulación» (9).');
        }
        if ($action === 'aplicar_ff') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $ids = op_especiales_parse_ids_from_post('ids_partiresul_text');
            $pen = (int) ($_POST['penalizacion'] ?? 0);
            $n = OpEspecialesHelper::aplicarForfaitFilas($torneo_id, $ronda, $ids, min(80, max(0, $pen)), $uid);
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = $n > 0 ? "Forfait aplicado en {$n} mesa(s)." : 'No se aplicó ningún cambio (revise selección y mesas completas de 4).';
        } elseif ($action === 'aplicar_tarjetas') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $ids = op_especiales_parse_ids_from_post('ids_tarjeta_text');
            $tarjeta = (int) ($_POST['tipo_tarjeta'] ?? 1);
            $sanc = (int) ($_POST['sancion_pts'] ?? 0);
            $n = OpEspecialesHelper::aplicarTarjetasFilas($torneo_id, $ronda, $ids, $tarjeta, min(80, max(0, $sanc)), $uid);
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = $n > 0 ? "Sanciones/tarjetas aplicadas en {$n} mesa(s)." : 'No se aplicó ningún cambio.';
        } elseif ($action === 'carga_masiva') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $n = OpEspecialesHelper::cargaMasivaResultadosBase($torneo_id, $ronda, $uid);
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = "Carga masiva completada en {$n} mesa(s).";
        } elseif ($action === 'generar_siguiente') {
            RoundManagerHandler::ejecutarGeneracionRonda($torneo_id, [
                'redirect_base' => 'op_especiales',
                'estrategia_ronda2' => trim((string) ($_POST['estrategia_ronda2'] ?? 'separar')),
                'estrategia_asignacion' => trim((string) ($_POST['estrategia_asignacion'] ?? 'secuencial')),
            ]);
            exit;
        } elseif ($action === 'swap') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $nfA = (int) ($_POST['numfvd_a'] ?? 0);
            $nfB = (int) ($_POST['numfvd_b'] ?? 0);
            $idUa = op_especiales_uid_desde_numfvd($pdo, $torneo_id, $nfA, 'jugador 1');
            $idUb = op_especiales_uid_desde_numfvd($pdo, $torneo_id, $nfB, 'jugador 2');
            $resumenSwap = OpEspecialesHelper::swapAtletasPorUsuariosYRonda($torneo_id, $ronda, $idUa, $idUb, $modalidad);
            foreach ($resumenSwap['cambios'] as &$cambioSwap) {
                $cambioSwap['numfvd'] = NumfvdHelper::numfvdInscrito($pdo, $torneo_id, (int) ($cambioSwap['id_usuario'] ?? 0));
            }
            unset($cambioSwap);
            $_SESSION['op_especiales_swap_resumen'] = $resumenSwap;
            $_SESSION['success'] = 'Intercambio aplicado. Revise el detalle de mesas en el formulario.';
        } elseif ($action === 'reemplazo_usuario') {
            $nfV = (int) ($_POST['numfvd_viejo'] ?? 0);
            $nfN = (int) ($_POST['numfvd_nuevo'] ?? 0);
            $idV = op_especiales_uid_desde_numfvd($pdo, $torneo_id, $nfV, 'jugador sustituido');
            $idN = op_especiales_uid_desde_numfvd($pdo, $torneo_id, $nfN, 'jugador sustituto');
            $alc = trim((string) ($_POST['alcance_rondas'] ?? 'todas'));
            $rU = (int) ($_POST['ronda_unica'] ?? 0);
            $rD = (int) ($_POST['ronda_desde'] ?? 0);
            $rH = (int) ($_POST['ronda_hasta'] ?? 0);
            $n = OpEspecialesHelper::reemplazarIdUsuarioPartiresul(
                $torneo_id,
                $idV,
                $idN,
                $alc,
                $rU > 0 ? $rU : null,
                $rD > 0 ? $rD : null,
                $rH > 0 ? $rH : null,
                $modalidad,
                $uid
            );
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = $n > 0
                ? "Reemplazo aplicado en {$n} fila(s) de partiresul (NUMFVD {$nfV} → {$nfN})."
                : 'No se actualizó ninguna fila.';
        } else {
            $_SESSION['error'] = 'Acción no reconocida.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: ' . $opEspHref(['view' => $view_redirect]));
    exit;
}

$stR = $pdo->prepare('SELECT COALESCE(MAX(partida), 0) FROM partiresul WHERE id_torneo = ?');
$stR->execute([$torneo_id]);
$ultima_ronda = (int) $stR->fetchColumn();
$rondas_opts = range(1, max(1, (int) ($torneo['rondas'] ?? 9)));

$audit = ['integridad' => [], 'gdu' => [], 'ff_incoherente' => []];
if ($es_carga_especial && $view === 'auditoria') {
    $audit = OpEspecialesHelper::reporteAuditoria($torneo_id);
}

$ronda_lista = (int) ($_GET['ronda'] ?? ($ultima_ronda > 0 ? $ultima_ronda : 1));
$filas_ronda = [];
if ($es_carga_especial && $view === 'carga') {
    $stFilas = $pdo->prepare(
        'SELECT id, partida, mesa, secuencia, id_usuario, resultado1, resultado2, ff, tarjeta, sancion,
                registrado
         FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0
         ORDER BY mesa ASC, secuencia ASC'
    );
    $stFilas->execute([$torneo_id, $ronda_lista]);
    $filas_ronda = $stFilas->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Op Especiales — ' . htmlspecialchars((string) ($torneo['nombre'] ?? 'Torneo'));
?>
<div class="container-fluid">
  <h1 class="h3 mb-3"><i class="fas fa-flask text-warning me-2"></i>Operaciones Especiales</h1>
  <p class="text-muted">Torneo <strong><?= htmlspecialchars((string) ($torneo['nombre'] ?? '')) ?></strong>
    <?php if ($es_carga_especial): ?>
      <span class="badge bg-secondary">Carga automática / análisis: estatus 9</span>
    <?php else: ?>
      <span class="badge bg-info text-dark">Estatus torneo: <?= (int) ($torneo['estatus'] ?? 0) ?> · Swap/reemplazo disponibles</span>
    <?php endif; ?>
  </p>

  <?php if (! empty($_SESSION['info'])): ?>
    <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars((string) $_SESSION['info']) ?><?php unset($_SESSION['info']); ?></div>
  <?php endif; ?>
  <?php if (! empty($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars((string) $_SESSION['success']) ?><?php unset($_SESSION['success']); ?></div>
  <?php endif; ?>
  <?php if (! empty($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars((string) $_SESSION['error']) ?><?php unset($_SESSION['error']); ?></div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2 mb-3" role="navigation" aria-label="Secciones Op Especiales">
    <?php if ($es_carga_especial): ?>
    <a class="btn btn-lg <?= $view === 'carga' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($opEspHref(['view' => 'carga']), ENT_QUOTES, 'UTF-8') ?>">
      <i class="fas fa-layer-group me-1"></i> Carga por ronda (estatus 9)
    </a>
    <?php endif; ?>
    <a class="btn btn-lg <?= $view === 'swap' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($opEspHref(['view' => 'swap']), ENT_QUOTES, 'UTF-8') ?>">
      <i class="fas fa-exchange-alt me-1"></i> Cambiar atleta (NUMFVD)
    </a>
    <?php if ($es_carga_especial): ?>
    <a class="btn btn-lg <?= $view === 'auditoria' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($opEspHref(['view' => 'auditoria']), ENT_QUOTES, 'UTF-8') ?>">
      <i class="fas fa-clipboard-check me-1"></i> Auditoría (estatus 9)
    </a>
    <?php endif; ?>
  </div>

  <?php if ($es_carga_especial): ?>
  <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    <li class="nav-item">
      <a class="nav-link <?= $view === 'carga' ? 'active' : '' ?>" href="<?= htmlspecialchars($opEspHref(['view' => 'carga']), ENT_QUOTES, 'UTF-8') ?>">Carga por ronda</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'auditoria' ? 'active' : '' ?>" href="<?= htmlspecialchars($opEspHref(['view' => 'auditoria']), ENT_QUOTES, 'UTF-8') ?>">Auditoría</a>
    </li>
  </ul>
  <?php endif; ?>

  <?php if ($view === 'carga'): ?>
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">Forfait (FF) y penalización</div>
          <div class="card-body">
            <form method="post" class="needs-validation" action="<?= htmlspecialchars($opEspHref(['view' => $view]), ENT_QUOTES, 'UTF-8') ?>">
              <?= CSRF::input() ?>
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="op_action" value="aplicar_ff">
              <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
              <div class="mb-2">
                <label class="form-label">Ronda</label>
                <select name="ronda" class="form-select" required>
                  <?php foreach ($rondas_opts as $r): ?>
                    <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Puntos de penalización (sanción, máx. 80)</label>
                <input type="number" name="penalizacion" class="form-control" min="0" max="80" value="0">
              </div>
              <div class="mb-2">
                <label class="form-label">Filas partiresul (IDs) con FF — una por línea o separadas por coma</label>
                <textarea name="ids_partiresul_text" class="form-control" rows="3" placeholder="ej: 101,102"></textarea>
              </div>
              <p class="small text-muted">Se agrupan por mesa y se aplica el núcleo de registro de resultados (incluye efectividad por FF).</p>
              <button type="submit" class="btn btn-warning">Aplicar FF</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">Tarjetas administrativas</div>
          <div class="card-body">
            <form method="post" action="<?= htmlspecialchars($opEspHref(['view' => $view]), ENT_QUOTES, 'UTF-8') ?>">
              <?= CSRF::input() ?>
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="op_action" value="aplicar_tarjetas">
              <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
              <div class="mb-2">
                <label class="form-label">Ronda</label>
                <select name="ronda" class="form-select" required>
                  <?php foreach ($rondas_opts as $r): ?>
                    <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Tipo</label>
                <select name="tipo_tarjeta" class="form-select">
                  <option value="1">Amarilla (1)</option>
                  <option value="3">Roja (3)</option>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Puntos de sanción administrativa (máx. 80)</label>
                <input type="number" name="sancion_pts" class="form-control" min="0" max="80" value="0">
              </div>
              <div class="mb-2">
                <label class="form-label">IDs partiresul (una por línea o comas)</label>
                <textarea name="ids_tarjeta_text" class="form-control" rows="3"></textarea>
              </div>
              <button type="submit" class="btn btn-outline-danger">Aplicar tarjeta/sanción</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header">Carga masiva de resultados base</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end" action="<?= htmlspecialchars($opEspHref(['view' => $view]), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Se rellenarán todas las mesas completas (4 jugadores) de la ronda con un marcador simulado AC vs BD. ¿Continuar?');">
          <?= CSRF::input() ?>
          <input type="hidden" name="return_view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="op_action" value="carga_masiva">
          <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
          <div class="col-auto">
            <label class="form-label">Ronda</label>
            <select name="ronda" class="form-select">
              <?php foreach ($rondas_opts as $r): ?>
                <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Llenar resultados base</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header">Cerrar ronda y generar emparejamientos (Power Pairing / asignación por modalidad)</div>
      <div class="card-body">
        <form method="post" action="<?= htmlspecialchars($opEspHref(['view' => $view]), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Se generará la siguiente ronda si todas las mesas de la última ronda están registradas. ¿Continuar?');">
          <?= CSRF::input() ?>
          <input type="hidden" name="return_view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="op_action" value="generar_siguiente">
          <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
          <?php if ($modalidad === 3): ?>
            <div class="mb-2">
              <label class="form-label">Estrategia equipos</label>
              <select name="estrategia_asignacion" class="form-select">
                <option value="secuencial">Secuencial</option>
                <option value="intercalada_13_24">Intercalada 13–24</option>
                <option value="intercalada_14_23">Intercalada 14–23</option>
                <option value="por_rendimiento">Por rendimiento</option>
              </select>
            </div>
          <?php else: ?>
            <div class="mb-2">
              <label class="form-label">Estrategia emparejamiento (individual / mesas)</label>
              <select name="estrategia_ronda2" class="form-select">
                <option value="separar">Clásico — ronda 2: separar líderes; siguientes: Suizo</option>
                <option value="club_interclub_rr">Interclub — RR por club; R1 sin BYE (sobrantes por club → retirados)</option>
              </select>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-success">Generar siguiente ronda</button>
        </form>
        <p class="small text-muted mt-2">Última ronda con datos: <strong><?= (int) $ultima_ronda ?></strong>. Usa la misma lógica que el panel del torneo.</p>
      </div>
    </div>
  <?php elseif ($view === 'swap'): ?>
    <?php
    $swap_resumen = $_SESSION['op_especiales_swap_resumen'] ?? null;
    if (is_array($swap_resumen)) {
        unset($_SESSION['op_especiales_swap_resumen']);
    } else {
        $swap_resumen = null;
    }
    $apiAtletaNumfvd = $opEspHref(['view' => 'swap', 'ajax' => 'atleta_numfvd']);
    ?>
    <style>
    .op-especiales-dual-cols {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: stretch;
    }
    .op-especiales-panel {
      flex: 0 0 calc(50% - 0.5rem);
      max-width: calc(50% - 0.5rem);
    }
    @media (max-width: 991.98px) {
      .op-especiales-panel {
        flex: 0 0 100%;
        max-width: 100%;
      }
    }
    .op-especiales-panel .card-body {
      font-size: 0.875rem;
    }
    .op-btn-primario {
      background: #0d6efd !important;
      color: #fff !important;
      font-weight: 700 !important;
      border: none !important;
    }
    .op-btn-primario:hover,
    .op-btn-primario:focus {
      background: #0b5ed7 !important;
      color: #fff !important;
    }
    .op-campo-ronda {
      width: 20%;
      min-width: 72px;
    }
    .op-campo-id {
      width: 30%;
      min-width: 88px;
    }
    .op-campo-nombre {
      width: 60%;
      min-height: 2.15rem;
      padding: 0.35rem 0.5rem;
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 0.375rem;
      font-size: 0.85rem;
      color: #212529;
      display: flex;
      align-items: center;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .op-campo-nombre.text-muted {
      color: #6c757d !important;
      font-style: italic;
    }
    .op-jugador-fila {
      display: flex;
      gap: 0.4rem;
      margin-bottom: 0.45rem;
      align-items: stretch;
    }
    .op-jugadores-bloque {
      margin-top: 0.65rem;
    }
    .op-rondas-afectadas {
      margin-top: 0.75rem;
      padding: 0.5rem 0.75rem;
      background: #e7f1ff;
      border: 1px solid #b6d4fe;
      border-radius: 0.375rem;
      font-size: 0.85rem;
      min-height: 2.4rem;
    }
    .op-rondas-afectadas strong {
      color: #084298;
    }
    </style>

    <?php if ($swap_resumen !== null && ! empty($swap_resumen['cambios'])): ?>
    <div class="alert alert-success border border-success mb-3">
      <div class="fw-bold mb-2">
        Cambio realizado — ronda <?= (int) ($swap_resumen['ronda'] ?? 0) ?>
        (torneo #<?= (int) $torneo_id ?>)
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 bg-white">
          <thead class="table-light">
            <tr>
                  <th scope="col">NUMFVD</th>
              <th scope="col">id fila partiresul</th>
              <th scope="col">Mesa desde</th>
              <th scope="col">Mesa hasta</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($swap_resumen['cambios'] as $sc): ?>
            <tr>
                  <td><?= (int) ($sc['numfvd'] ?? NumfvdHelper::numfvdInscrito($pdo, $torneo_id, (int) ($sc['id_usuario'] ?? 0))) ?></td>
              <td><?= (int) ($sc['id_partiresul'] ?? 0) ?></td>
              <td><?= (int) ($sc['mesa_desde'] ?? 0) ?></td>
              <td><?= (int) ($sc['mesa_hasta'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="op-especiales-dual-cols mb-4">
      <div class="op-especiales-panel">
        <div class="card h-100">
          <div class="card-header py-2"><strong><i class="fas fa-exchange-alt me-1"></i> Intercambiar jugadores</strong></div>
          <div class="card-body">
            <p class="small text-muted mb-2">
              Misma ronda: intercambia las mesas de dos atletas por su <strong>NUMFVD</strong> en <code>partiresul</code>.
            </p>
            <form method="post" id="form-swap-atletas" action="<?= htmlspecialchars($opEspHref(['view' => $view]), ENT_QUOTES, 'UTF-8') ?>">
              <?= CSRF::input() ?>
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="op_action" value="swap">
              <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
              <div class="op-campo-ronda">
                <label class="form-label mb-1">Ronda</label>
                <select name="ronda" class="form-select form-select-sm" required>
                  <?php foreach ($rondas_opts as $r): ?>
                    <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="op-jugadores-bloque">
                <div class="op-jugador-fila">
                  <div class="op-campo-id">
                    <label class="form-label mb-1">NUMFVD jugador 1</label>
                    <input type="number" name="numfvd_a" id="swap-numfvd-a" class="form-control form-control-sm" required min="1" placeholder="ej. 10025" autocomplete="off">
                  </div>
                  <div class="op-campo-nombre text-muted" id="swap-nombre-a" title="Nombre jugador 1">— nombre —</div>
                </div>
                <div class="op-jugador-fila">
                  <div class="op-campo-id">
                    <label class="form-label mb-1">NUMFVD jugador 2</label>
                    <input type="number" name="numfvd_b" id="swap-numfvd-b" class="form-control form-control-sm" required min="1" placeholder="ej. 10048" autocomplete="off">
                  </div>
                  <div class="op-campo-nombre text-muted" id="swap-nombre-b" title="Nombre jugador 2">— nombre —</div>
                </div>
              </div>
              <div class="mt-2">
                <button type="submit" class="btn btn-sm op-btn-primario">Intercambiar posiciones</button>
                <?php if ($modalidad === 3): ?>
                  <span class="text-muted small ms-1 d-block mt-1">Equipos: no duplicar jugadores del mismo equipo en una mesa.</span>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="op-especiales-panel">
        <div class="card h-100">
          <div class="card-header py-2"><strong><i class="fas fa-user-edit me-1"></i> Reemplazar jugador</strong></div>
          <div class="card-body">
            <p class="small text-muted mb-2">
              Sustituye al atleta en <code>partiresul</code> por otro (por <strong>NUMFVD</strong>, alcance por ronda(s)).
            </p>
            <form method="post" id="form-reemplazo-usuario" action="<?= htmlspecialchars($opEspHref(['view' => $view]), ENT_QUOTES, 'UTF-8') ?>">
              <?= CSRF::input() ?>
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="op_action" value="reemplazo_usuario">
              <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
              <div class="op-jugador-fila">
                <div class="op-campo-id">
                  <label class="form-label mb-1">NUMFVD a sustituir</label>
                  <input type="number" name="numfvd_viejo" id="reemplazo-numfvd-viejo" class="form-control form-control-sm" required min="1" value="">
                </div>
                <div class="op-campo-nombre text-muted" id="reemplazo-nombre-viejo" title="Nombre sustituido">— nombre —</div>
              </div>
              <div class="op-jugador-fila">
                <div class="op-campo-id">
                  <label class="form-label mb-1">NUMFVD sustituto</label>
                  <input type="number" name="numfvd_nuevo" id="reemplazo-numfvd-nuevo" class="form-control form-control-sm" required min="1" value="">
                </div>
                <div class="op-campo-nombre text-muted" id="reemplazo-nombre-nuevo" title="Nombre sustituto">— nombre —</div>
              </div>
              <div class="mt-2 mb-2">
                <label class="form-label mb-1 d-block">Alcance de rondas</label>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="alcance_rondas" id="alc_todas" value="todas" checked>
                  <label class="form-check-label small" for="alc_todas">Todas</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="alcance_rondas" id="alc_una" value="una_ronda">
                  <label class="form-check-label small" for="alc_una">Una ronda</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="alcance_rondas" id="alc_rango" value="rango">
                  <label class="form-check-label small" for="alc_rango">Rango</label>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2 mb-2">
                <div class="op-campo-ronda" id="wrap-ronda-unica" style="display:none;">
                  <label class="form-label mb-1">Ronda</label>
                  <select name="ronda_unica" id="reemplazo-ronda-unica" class="form-select form-select-sm">
                    <?php foreach ($rondas_opts as $r): ?>
                      <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="op-campo-ronda" id="wrap-rango-desde" style="display:none;">
                  <label class="form-label mb-1">Desde</label>
                  <select name="ronda_desde" id="reemplazo-ronda-desde" class="form-select form-select-sm">
                    <?php foreach ($rondas_opts as $r): ?>
                      <option value="<?= (int) $r ?>"><?= (int) $r ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="op-campo-ronda" id="wrap-rango-hasta" style="display:none;">
                  <label class="form-label mb-1">Hasta</label>
                  <select name="ronda_hasta" id="reemplazo-ronda-hasta" class="form-select form-select-sm">
                    <?php foreach ($rondas_opts as $r): ?>
                      <option value="<?= (int) $r ?>"><?= (int) $r ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="op-rondas-afectadas" id="reemplazo-rondas-info" aria-live="polite">
                <strong>Rondas a sustituir:</strong> <span id="reemplazo-rondas-texto">Indique el NUMFVD a sustituir para ver las rondas afectadas.</span>
              </div>
              <div class="mt-2">
                <button type="submit" class="btn btn-sm op-btn-primario" onclick="return confirm('¿Confirmar reemplazo en partiresul según el alcance elegido?');">Aplicar reemplazo</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function () {
      var apiAtleta = <?= json_encode($apiAtletaNumfvd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

      function fetchAtletaNumfvd(inputEl, outEl, onRondas) {
        if (!inputEl || !outEl) return;
        var nf = parseInt(inputEl.value, 10);
        if (!nf || nf <= 0) {
          outEl.textContent = '— nombre —';
          outEl.classList.add('text-muted');
          if (typeof onRondas === 'function') onRondas([]);
          return;
        }
        outEl.textContent = 'Buscando…';
        outEl.classList.add('text-muted');
        fetch(apiAtleta + '&numfvd=' + encodeURIComponent(nf), { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.ok && data.nombre) {
              outEl.textContent = data.nombre;
              outEl.classList.remove('text-muted');
              outEl.title = data.nombre;
            } else if (data && data.ok) {
              outEl.textContent = 'Inscrito (sin nombre)';
              outEl.classList.add('text-muted');
            } else {
              outEl.textContent = (data && data.error) ? data.error : 'No encontrado';
              outEl.classList.add('text-muted');
            }
            if (typeof onRondas === 'function') {
              onRondas((data && Array.isArray(data.rondas)) ? data.rondas : []);
            }
          })
          .catch(function () {
            outEl.textContent = 'Error al buscar';
            outEl.classList.add('text-muted');
            if (typeof onRondas === 'function') onRondas([]);
          });
      }

      function bindNumfvd(inputId, outId, onRondas) {
        var inp = document.getElementById(inputId);
        var out = document.getElementById(outId);
        if (!inp || !out) return;
        var run = function () { fetchAtletaNumfvd(inp, out, onRondas); };
        inp.addEventListener('change', run);
        inp.addEventListener('blur', run);
      }

      bindNumfvd('swap-numfvd-a', 'swap-nombre-a');
      bindNumfvd('swap-numfvd-b', 'swap-nombre-b');
      bindNumfvd('reemplazo-numfvd-nuevo', 'reemplazo-nombre-nuevo');

      var f = document.getElementById('form-reemplazo-usuario');
      var txtRondas = document.getElementById('reemplazo-rondas-texto');
      var rondasCache = [];

      function alcanceSeleccionado() {
        if (!f) return 'todas';
        var v = f.querySelector('input[name="alcance_rondas"]:checked');
        return v ? v.value : 'todas';
      }

      function rondasFiltradasPorAlcance(rondas) {
        var alc = alcanceSeleccionado();
        if (alc === 'todas') return rondas.slice();
        if (alc === 'una_ronda') {
          var ru = parseInt((document.getElementById('reemplazo-ronda-unica') || {}).value, 10);
          return rondas.indexOf(ru) >= 0 ? [ru] : (ru > 0 ? [ru] : []);
        }
        var rd = parseInt((document.getElementById('reemplazo-ronda-desde') || {}).value, 10);
        var rh = parseInt((document.getElementById('reemplazo-ronda-hasta') || {}).value, 10);
        if (rd > rh) { var t = rd; rd = rh; rh = t; }
        return rondas.filter(function (r) { return r >= rd && r <= rh; });
      }

      function pintarRondasAfectadas() {
        if (!txtRondas) return;
        var nfV = parseInt((document.getElementById('reemplazo-numfvd-viejo') || {}).value, 10);
        if (!nfV || nfV <= 0) {
          txtRondas.textContent = 'Indique el NUMFVD a sustituir para ver las rondas afectadas.';
          return;
        }
        if (!rondasCache.length) {
          txtRondas.textContent = 'El atleta no tiene mesas en partiresul de este torneo.';
          return;
        }
        var filtradas = rondasFiltradasPorAlcance(rondasCache);
        if (!filtradas.length) {
          txtRondas.textContent = 'Ninguna ronda coincide con el alcance elegido.';
          return;
        }
        txtRondas.textContent = filtradas.join(', ');
      }

      bindNumfvd('reemplazo-numfvd-viejo', 'reemplazo-nombre-viejo', function (rondas) {
        rondasCache = rondas;
        pintarRondasAfectadas();
      });

      if (f) {
        var radios = f.querySelectorAll('input[name="alcance_rondas"]');
        var w1 = document.getElementById('wrap-ronda-unica');
        var wd = document.getElementById('wrap-rango-desde');
        var wh = document.getElementById('wrap-rango-hasta');
        function syncAlcance() {
          var v = alcanceSeleccionado();
          if (w1) w1.style.display = v === 'una_ronda' ? '' : 'none';
          if (wd) wd.style.display = wh.style.display = v === 'rango' ? '' : 'none';
          pintarRondasAfectadas();
        }
        radios.forEach(function (r) { r.addEventListener('change', syncAlcance); });
        ['reemplazo-ronda-unica', 'reemplazo-ronda-desde', 'reemplazo-ronda-hasta'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.addEventListener('change', pintarRondasAfectadas);
        });
        syncAlcance();
      }
    })();
    </script>
  <?php else: ?>
    <div class="card mb-3">
      <div class="card-header">Integridad</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tipo</th><th>Ronda</th><th>Mesa</th><th>Detalle</th></tr></thead>
          <tbody>
          <?php foreach ($audit['integridad'] as $row): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($row['tipo'] ?? '')) ?></td>
              <td><?= (int) ($row['partida'] ?? 0) ?></td>
              <td><?= (int) ($row['mesa'] ?? 0) ?></td>
              <td><code><?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE)) ?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($audit['integridad'] === []): ?>
            <tr><td colspan="4" class="text-muted">Sin incidencias de integridad.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Anomalía GDU (ganador con PF ≤ mejor perdedor)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Ronda</th><th>Mesa</th><th>Usuario</th><th>PF</th><th>Max PF perdedores</th></tr></thead>
          <tbody>
          <?php foreach ($audit['gdu'] as $row): ?>
            <tr>
              <td><?= (int) ($row['partida'] ?? 0) ?></td>
              <td><?= (int) ($row['mesa'] ?? 0) ?></td>
              <td><?= (int) ($row['id_usuario'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string) ($row['pf'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($row['max_pf_perdedor_mesa'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($audit['gdu'] === []): ?>
            <tr><td colspan="5" class="text-muted">Sin casos GDU detectados.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Coherencia FF (forfait con marcador de ganador)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>ID</th><th>Ronda</th><th>Mesa</th><th>Usuario</th></tr></thead>
          <tbody>
          <?php foreach ($audit['ff_incoherente'] as $row): ?>
            <tr>
              <td><?= (int) ($row['id'] ?? 0) ?></td>
              <td><?= (int) ($row['partida'] ?? 0) ?></td>
              <td><?= (int) ($row['mesa'] ?? 0) ?></td>
              <td><?= (int) ($row['id_usuario'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($audit['ff_incoherente'] === []): ?>
            <tr><td colspan="4" class="text-muted">Sin incoherencias FF.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($view === 'carga'): ?>
    <div class="card mt-4">
      <div class="card-header">Filas de la ronda <?= (int) $ronda_lista ?> (referencia de IDs)</div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>ID</th><th>Mesa</th><th>Seq</th><th>id_usuario</th><th>R1</th><th>R2</th><th>FF</th><th>Tj</th><th>San</th><th>Reg</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filas_ronda as $f): ?>
              <tr>
                <td><?= (int) $f['id'] ?></td>
                <td><?= (int) $f['mesa'] ?></td>
                <td><?= (int) $f['secuencia'] ?></td>
                <td><?= (int) $f['id_usuario'] ?></td>
                <td><?= htmlspecialchars((string) $f['resultado1']) ?></td>
                <td><?= htmlspecialchars((string) $f['resultado2']) ?></td>
                <td><?= htmlspecialchars((string) $f['ff']) ?></td>
                <td><?= htmlspecialchars((string) $f['tarjeta']) ?></td>
                <td><?= htmlspecialchars((string) $f['sancion']) ?></td>
                <td><?= htmlspecialchars((string) $f['registrado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small">
        <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($opEspHref(['view' => 'carga', 'ronda' => (int) $ronda_lista]), ENT_QUOTES, 'UTF-8') ?>">Refrescar listado</a>
      </div>
    </div>
  <?php endif; ?>

  <p class="mt-4">
    <a href="index.php?page=torneo_gestion&action=panel&torneo_id=<?= (int) $torneo_id ?>" class="btn btn-outline-secondary">Volver al panel del torneo</a>
  </p>
</div>
