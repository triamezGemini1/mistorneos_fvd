<?php
/**
 * Gestión de Reportes de Pago de Usuarios
 * Permite a administradores revisar, confirmar o rechazar los reportes de pago de usuarios individuales
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/BankValidator.php';
require_once __DIR__ . '/../lib/ReportePagoUsuarioService.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../config/deploy_build.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();
$user = Auth::user();

$error = '';
$success = '';

// POST legacy (rechazar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') !== '1') {
    CSRF::validate();
    $accion = $_POST['accion'] ?? '';
    $reporte_id_post = (int) ($_POST['reporte_id'] ?? 0);
    if ($reporte_id_post > 0 && $accion === 'rechazar') {
        $reporte_data = ReportePagoUsuarioService::cargarReporte($pdo, $reporte_id_post);
        if ($reporte_data && ReportePagoUsuarioService::puedeGestionar($reporte_data)) {
            $stmt = $pdo->prepare("UPDATE reportes_pago_usuarios SET estatus = 'rechazado', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$reporte_id_post]);
            $success = 'Reporte de pago rechazado';
        } else {
            $error = 'No tiene permiso para gestionar este reporte';
        }
    }
}

// Obtener filtros. Acceso desde panel de control: torneo_id obligatorio (sin selector).
$filtro_estatus = $_GET['estatus'] ?? 'todos';
$filtro_busqueda = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
$filtro_torneo = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

if (!in_array($filtro_estatus, ['todos', 'pendiente', 'confirmado', 'rechazado'], true)) {
    $filtro_estatus = 'todos';
}

if ($filtro_torneo <= 0) {
    $dashboard = class_exists('AppHelpers') ? (AppHelpers::dashboard('home') ?? 'index.php') : 'index.php';
    header('Location: ' . $dashboard . '?error=' . urlencode('Acceda a Reportes de Pago desde el Panel de Control de un torneo (evento masivo).'));
    exit;
}
if (!Auth::canAccessTournament($filtro_torneo)) {
    $dashboard = class_exists('AppHelpers') ? (AppHelpers::dashboard('home') ?? 'index.php') : 'index.php';
    header('Location: ' . $dashboard . '?error=' . urlencode('No tiene permiso para este torneo.'));
    exit;
}

// Construir consulta
$where = [];
$params = [];

if ($filtro_estatus !== 'todos') {
    $where[] = "rpu.estatus = ?";
    $params[] = $filtro_estatus;
}

if ($filtro_torneo > 0) {
    $where[] = "rpu.torneo_id = ?";
    $params[] = $filtro_torneo;
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$hasEntidadTbl = false;
try {
    $hasEntidadTbl = (bool) $pdo->query("SHOW TABLES LIKE 'entidad'")->fetchColumn();
} catch (Throwable $e) {
    $hasEntidadTbl = false;
}
$entidadJoin = $hasEntidadTbl ? 'LEFT JOIN entidad e ON e.id = u.entidad' : '';
$entidadSelect = $hasEntidadTbl ? ', e.nombre AS entidad_nombre, u.entidad AS entidad_id' : ', u.entidad AS entidad_id, NULL AS entidad_nombre';

$hasNumfvdUsuario = false;
try {
    $hasNumfvdUsuario = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetchColumn();
} catch (Throwable $e) {
    $hasNumfvdUsuario = false;
}
$inscritoNumfvdSub = '(SELECT COALESCE(i0.numfvd, 0) FROM inscritos i0 WHERE i0.torneo_id = rpu.torneo_id AND i0.id_usuario = rpu.id_usuario ORDER BY i0.id DESC LIMIT 1)';
$numfvdSelect = $hasNumfvdUsuario
    ? ", COALESCE(NULLIF(u.numfvd, 0), {$inscritoNumfvdSub}, 0) AS usuario_numfvd"
    : ", COALESCE({$inscritoNumfvdSub}, 0) AS usuario_numfvd";

if ($filtro_busqueda !== '') {
    $like = '%' . $filtro_busqueda . '%';
    $cedulaDigits = '%' . preg_replace('/\D/', '', $filtro_busqueda) . '%';
    $searchParts = [
        'u.nombre LIKE ?',
        'u.cedula LIKE ?',
        "REPLACE(REPLACE(REPLACE(TRIM(CAST(u.cedula AS CHAR)), '-', ''), '.', ''), ' ', '') LIKE ?",
        'CAST(u.id AS CHAR) LIKE ?',
    ];
    $searchParams = [$like, $like, $cedulaDigits, $like];
    if ($hasNumfvdUsuario) {
        $searchParts[] = 'CAST(u.numfvd AS CHAR) LIKE ?';
        $searchParams[] = $like;
    }
    $searchParts[] = "EXISTS (SELECT 1 FROM inscritos i_s WHERE i_s.torneo_id = rpu.torneo_id AND i_s.id_usuario = rpu.id_usuario AND CAST(COALESCE(i_s.numfvd, 0) AS CHAR) LIKE ?)";
    $searchParams[] = $like;
    if (ctype_digit($filtro_busqueda)) {
        $idNum = (int) $filtro_busqueda;
        $searchParts[] = 'u.id = ?';
        $searchParams[] = $idNum;
        if ($hasNumfvdUsuario) {
            $searchParts[] = 'u.numfvd = ?';
            $searchParams[] = $idNum;
        }
        $searchParts[] = "EXISTS (SELECT 1 FROM inscritos i_s WHERE i_s.torneo_id = rpu.torneo_id AND i_s.id_usuario = rpu.id_usuario AND i_s.numfvd = ?)";
        $searchParams[] = $idNum;
        $searchParts[] = 'rpu.id_usuario = ?';
        $searchParams[] = $idNum;
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
    $params = array_merge($params, $searchParams);
}

// Obtener reportes de pago
$reportes = [];
try {
    $sql = "
        SELECT 
            rpu.*,
            u.id as usuario_id,
            u.nombre as usuario_nombre,
            u.cedula as usuario_cedula,
            u.telegram_chat_id
            {$entidadSelect}
            {$numfvdSelect},
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.costo as torneo_costo,
            t.cuenta_id,
            cb.banco as cuenta_banco,
            cb.numero_cuenta as cuenta_numero,
            cb.tipo_cuenta as cuenta_tipo,
            cb.telefono_afiliado as cuenta_telefono,
            cb.nombre_propietario as cuenta_propietario
        FROM reportes_pago_usuarios rpu
        INNER JOIN usuarios u ON rpu.id_usuario = u.id
        INNER JOIN tournaments t ON rpu.torneo_id = t.id
        {$entidadJoin}
        LEFT JOIN cuentas_bancarias cb ON t.cuenta_id = cb.id
        $where_sql
        ORDER BY rpu.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo reportes de pago: " . $e->getMessage());
    $error = 'Error al cargar los reportes de pago';
}

// Obtener torneos para filtro (admin_club solo ve torneos de su club)
$torneos = [];
try {
    $torneos_sql = "
        SELECT DISTINCT t.id, t.nombre 
        FROM tournaments t
        INNER JOIN reportes_pago_usuarios rpu ON t.id = rpu.torneo_id
        WHERE t.es_evento_masivo = 1
    ";
    $torneos_params = [];
    if ($filtro_torneo > 0) {
        $torneos_sql .= ' AND t.id = ?';
        $torneos_params[] = $filtro_torneo;
    }
    $torneos_sql .= ' ORDER BY t.nombre ASC';
    $stmt = $pdo->prepare($torneos_sql);
    $stmt->execute($torneos_params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo torneos: " . $e->getMessage());
}

// Estadísticas
$stats = [
    'total' => count($reportes),
    'pendientes' => count(array_filter($reportes, fn($r) => $r['estatus'] === 'pendiente')),
    'confirmados' => count(array_filter($reportes, fn($r) => $r['estatus'] === 'confirmado')),
    'rechazados' => count(array_filter($reportes, fn($r) => $r['estatus'] === 'rechazado')),
    'monto_total' => array_sum(array_column(array_filter($reportes, fn($r) => $r['estatus'] === 'confirmado'), 'monto'))
];

$csrf_token = CSRF::token();
$rpu_api_url = class_exists('AppHelpers')
    ? rtrim(AppHelpers::getPublicUrl(), '/') . '/api/reporte_pago_admin.php'
    : '/public/api/reporte_pago_admin.php';
$rpu_asset_base = class_exists('AppHelpers')
    ? rtrim(AppHelpers::getPublicUrl(), '/')
    : '/public';
?>
<style>
@media print {
  body * { visibility: hidden; }
  #modalRecibo .modal-content, #modalRecibo .modal-content * { visibility: visible; }
  #modalRecibo .modal-footer, #modalRecibo .btn-close { display: none !important; }
  #modalRecibo .modal-dialog { max-width: 100%; margin: 0; }
}
</style>
<div class="fade-in">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-money-bill-wave me-2"></i>
                Reportes de Pago de Usuarios
                <span class="badge bg-secondary fs-6 align-middle" title="Versión desplegada"><?= htmlspecialchars(FVD_DEPLOY_BUILD) ?></span>
            </h1>
            <p class="text-muted mb-0">
                <?php if ($user['role'] === 'admin_club'): ?>
                Revisa los reportes de pago de tus torneos (eventos masivos)
                <?php else: ?>
                Revisa y gestiona los reportes de pago de usuarios en eventos masivos
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-info mb-2">
                        <i class="fas fa-list fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['total'] ?></h4>
                    <p class="text-muted mb-0">Total</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-warning mb-2">
                        <i class="fas fa-clock fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['pendientes'] ?></h4>
                    <p class="text-muted mb-0">Pendientes</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-success mb-2">
                        <i class="fas fa-check-circle fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['confirmados'] ?></h4>
                    <p class="text-muted mb-0">Confirmados</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-primary mb-2">
                        <i class="fas fa-dollar-sign fs-1"></i>
                    </div>
                    <h4 class="mb-1">$<?= number_format($stats['monto_total'], 2) ?></h4>
                    <p class="text-muted mb-0">Total Confirmado</p>
                </div>
            </div>
        </div>
    </div>

    <?php
    $torneo_nombre_actual = '';
    foreach ($torneos as $t) {
        if ((int)$t['id'] === $filtro_torneo) {
            $torneo_nombre_actual = $t['nombre'];
            break;
        }
    }
    if ($torneo_nombre_actual === '' && !empty($reportes[0]['torneo_nombre'])) {
        $torneo_nombre_actual = $reportes[0]['torneo_nombre'];
    }
    ?>
    <!-- Encabezado: torneo + búsqueda + filtro pago -->
    <div class="card mb-4 border-primary shadow-sm">
        <div class="card-header bg-dark text-white py-2">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span><i class="fas fa-trophy me-2 text-warning"></i><strong>Torneo:</strong> <?= htmlspecialchars($torneo_nombre_actual ?: 'Torneo #' . $filtro_torneo) ?></span>
                <span class="badge bg-light text-dark"><?= count($reportes) ?> registro(s)</span>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end" id="formFiltrosReportesPago">
                <input type="hidden" name="page" value="reportes_pago_usuarios">
                <input type="hidden" name="torneo_id" value="<?= (int)$filtro_torneo ?>">

                <div class="col-lg-5 col-md-12">
                    <label class="form-label mb-1"><i class="fas fa-search me-1"></i>Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                        <input type="search" name="q" class="form-control"
                               placeholder="Cédula, nombre, ID usuario o NUMFVD…"
                               value="<?= htmlspecialchars($filtro_busqueda) ?>"
                               autocomplete="off">
                    </div>
                    <small class="text-muted">Cédula, nombre, id de usuario o numfvd.</small>
                </div>

                <div class="col-lg-4 col-md-8">
                    <label class="form-label mb-1 d-block"><i class="fas fa-filter me-1"></i>Estado del pago</label>
                    <div class="btn-group w-100" role="group" aria-label="Filtro estatus pago">
                        <input type="radio" class="btn-check" name="estatus" id="estatus_todos" value="todos" <?= $filtro_estatus === 'todos' ? 'checked' : '' ?> autocomplete="off">
                        <label class="btn btn-outline-secondary" for="estatus_todos"><i class="fas fa-list me-1"></i>Todos</label>
                        <input type="radio" class="btn-check" name="estatus" id="estatus_pendiente" value="pendiente" <?= $filtro_estatus === 'pendiente' ? 'checked' : '' ?> autocomplete="off">
                        <label class="btn btn-outline-warning" for="estatus_pendiente"><i class="fas fa-clock me-1"></i>Pendientes</label>
                        <input type="radio" class="btn-check" name="estatus" id="estatus_confirmado" value="confirmado" <?= $filtro_estatus === 'confirmado' ? 'checked' : '' ?> autocomplete="off">
                        <label class="btn btn-outline-success" for="estatus_confirmado"><i class="fas fa-check me-1"></i>Confirmados</label>
                    </div>
                </div>

                <div class="col-lg-3 col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="fas fa-search me-1"></i>Aplicar
                    </button>
                    <?php if ($filtro_busqueda !== '' || $filtro_estatus !== 'todos'): ?>
                    <a href="?page=reportes_pago_usuarios&amp;torneo_id=<?= (int)$filtro_torneo ?>" class="btn btn-outline-secondary" title="Limpiar filtros">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Reportes -->
    <div class="card">
        <div class="card-header bg-dark text-warning fw-bold">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Lista de Reportes de Pago
                <span class="badge bg-light text-dark ms-2"><?= count($reportes) ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($reportes)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>NUMFVD</th>
                                <th>Entidad</th>
                                <th>Torneo</th>
                                <th>Fecha/Hora Pago</th>
                                <th>Tipo</th>
                                <th>Banco</th>
                                <th>Monto</th>
                                <th>Referencia</th>
                                <th>Cantidad</th>
                                <th>Confirmado</th>
                                <th>Estado</th>
                                <th>Fecha Reporte</th>
                                <th class="text-center">Opciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportes as $reporte): ?>
                            <tr>
                                <td><strong>#<?= $reporte['id'] ?></strong></td>
                                <td>
                                    <i class="fas fa-user text-muted me-2"></i>
                                    <?= htmlspecialchars($reporte['usuario_nombre']) ?>
                                    <small class="d-block text-muted ms-4">
                                        ID <?= (int)($reporte['usuario_id'] ?? $reporte['id_usuario'] ?? 0) ?>
                                        <?php if (!empty($reporte['usuario_cedula'])): ?>
                                        · <?= htmlspecialchars((string)$reporte['usuario_cedula']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td><code><?= (int)($reporte['usuario_numfvd'] ?? 0) ?></code></td>
                                <td>
                                    <i class="fas fa-map-marker-alt text-secondary me-1"></i>
                                    <?= htmlspecialchars($reporte['entidad_nombre'] ?? ('Entidad #' . (int)($reporte['entidad_id'] ?? 0))) ?>
                                </td>
                                <td>
                                    <i class="fas fa-trophy text-warning me-2"></i>
                                    <?= htmlspecialchars($reporte['torneo_nombre']) ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y', strtotime($reporte['fecha'])) ?><br>
                                    <small class="text-muted"><?= $reporte['hora'] ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= ucfirst($reporte['tipo_pago']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($reporte['banco'] ?? 'N/A') ?></td>
                                <td>
                                    <strong class="text-success">$<?= number_format($reporte['monto'], 2) ?></strong>
                                </td>
                                <td>
                                    <?php if ($reporte['referencia']): ?>
                                        <code><?= htmlspecialchars($reporte['referencia']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= $reporte['cantidad_inscritos'] ?> persona(s)</span>
                                </td>
                                <td class="text-center">
                                    <?php if ($reporte['estatus'] !== 'rechazado'): ?>
                                    <div class="form-check form-switch d-inline-flex align-items-center justify-content-center mb-0">
                                        <input type="checkbox" class="form-check-input rpu-switch-confirmado" role="switch"
                                               data-reporte-id="<?= (int)$reporte['id'] ?>"
                                               <?= $reporte['estatus'] === 'confirmado' ? 'checked' : '' ?>>
                                        <label class="form-check-label small ms-1"><?= $reporte['estatus'] === 'confirmado' ? 'Sí' : 'No' ?></label>
                                    </div>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $estatus_classes = [
                                        'pendiente' => 'bg-warning',
                                        'confirmado' => 'bg-success',
                                        'rechazado' => 'bg-danger'
                                    ];
                                    $estatus_texts = [
                                        'pendiente' => 'Pendiente',
                                        'confirmado' => 'Confirmado',
                                        'rechazado' => 'Rechazado'
                                    ];
                                    $class = $estatus_classes[$reporte['estatus']] ?? 'bg-secondary';
                                    $text = $estatus_texts[$reporte['estatus']] ?? 'Desconocido';
                                    ?>
                                    <span class="badge <?= $class ?> rpu-estatus-badge"><?= $text ?></span>
                                </td>
                                <td>
                                    <small><?= date('d/m/Y H:i', strtotime($reporte['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm flex-wrap justify-content-center">
                                        <button type="button" class="btn btn-outline-info btn-sm" data-rpu-accion="ver" data-reporte-id="<?= (int)$reporte['id'] ?>" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-rpu-accion="imprimir" data-reporte-id="<?= (int)$reporte['id'] ?>" title="Imprimir"><i class="fas fa-print"></i></button>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown" title="Notificar"><i class="fas fa-bell"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="#" data-rpu-accion="notificar" data-canal="ambos" data-reporte-id="<?= (int)$reporte['id'] ?>"><i class="fas fa-broadcast-tower me-2"></i>Web + Telegram</a></li>
                                                <li><a class="dropdown-item" href="#" data-rpu-accion="notificar" data-canal="web" data-reporte-id="<?= (int)$reporte['id'] ?>"><i class="fas fa-globe me-2"></i>Web push</a></li>
                                                <li><a class="dropdown-item" href="#" data-rpu-accion="notificar" data-canal="telegram" data-reporte-id="<?= (int)$reporte['id'] ?>"><i class="fab fa-telegram me-2"></i>Telegram</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="#" data-rpu-accion="notificar" data-canal="recordatorio" data-reporte-id="<?= (int)$reporte['id'] ?>"><i class="fab fa-whatsapp me-2 text-success"></i>Recordatorio</a></li>
                                            </ul>
                                        </div>
                                        <?php if ($reporte['estatus'] === 'pendiente'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Rechazar?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="reporte_id" value="<?= $reporte['id'] ?>">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Rechazar"><i class="fas fa-ban"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox text-muted fs-1 mb-3"></i>
                    <h5 class="text-muted">No hay reportes de pago</h5>
                    <p class="text-muted">Los reportes de pago aparecerán aquí cuando los usuarios los envíen</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- Modal recibo / ver registro -->
<div class="modal fade" id="modalRecibo" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Recibo / detalle del reporte</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalReciboBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cerrar</button>
                <button type="button" class="btn btn-success" id="btnReciboImprimir"><i class="fas fa-print me-1"></i>Imprimir</button>
            </div>
        </div>
    </div>
</div>

<script>
window.REPORTES_PAGO_CFG = {
    apiUrl: <?= json_encode($rpu_api_url, JSON_UNESCAPED_UNICODE) ?>,
    csrf: <?= json_encode($csrf_token, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= htmlspecialchars($rpu_asset_base) ?>/assets/reportes-pago-usuarios.js"></script>

