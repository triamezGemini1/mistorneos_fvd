<?php
/**
 * Módulo de Administración de Comentarios
 * Permite a los administradores aprobar, rechazar y gestionar comentarios
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('comments');

// Solo administradores pueden acceder
Auth::requireRole(['admin_general', 'admin_club']);

$pdo = DB::pdo();
$action = $_GET['action'] ?? 'list';
$redirect_url = null;
$redirect_message = null;

// Manejar acciones de aprobar/rechazar
if ($action === 'approve' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE comentariossugerencias SET estatus = 'aprobado', fecha_moderacion = NOW(), moderado_por = ? WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id'], $id]);
    $redirect_url = 'index.php?page=comments&success=' . urlencode('Comentario aprobado');
}

if ($action === 'reject' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE comentariossugerencias SET estatus = 'rechazado', fecha_moderacion = NOW(), moderado_por = ? WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id'], $id]);
    $redirect_url = 'index.php?page=comments&success=' . urlencode('Comentario rechazado');
}

// Obtener comentarios según el filtro
$filtro_estatus = $_GET['estatus'] ?? 'todos';
$filtro_tipo = $_GET['tipo'] ?? 'todos';

$where_clauses = [];
$params = [];

if ($filtro_estatus !== 'todos') {
    $where_clauses[] = "c.estatus = ?";
    $params[] = $filtro_estatus;
}

if ($filtro_tipo !== 'todos') {
    $where_clauses[] = "c.tipo = ?";
    $params[] = $filtro_tipo;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$stmt = $pdo->prepare("
    SELECT 
        c.*,
        u.username as usuario_username,
        u.nombre as usuario_nombre
    FROM comentariossugerencias c
    LEFT JOIN usuarios u ON c.usuario_id = u.id
    $where_sql
    ORDER BY c.fecha_creacion DESC
");
$stmt->execute($params);
$comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = [
    'total' => count($comentarios),
    'pendientes' => count(array_filter($comentarios, fn($c) => $c['estatus'] === 'pendiente')),
    'aprobados' => count(array_filter($comentarios, fn($c) => $c['estatus'] === 'aprobado')),
    'rechazados' => count(array_filter($comentarios, fn($c) => $c['estatus'] === 'rechazado'))
];
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h3 mb-0">
            <i class="fas fa-comments me-2"></i>Gestión de Comentarios
        </h2>
    </div>

    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total</h5>
                    <h3><?= $stats['total'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Pendientes</h5>
                    <h3><?= $stats['pendientes'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Aprobados</h5>
                    <h3><?= $stats['aprobados'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Rechazados</h5>
                    <h3><?= $stats['rechazados'] ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="comments">
                <div class="col-md-4">
                    <label class="form-label">Estado</label>
                    <select name="estatus" class="form-select">
                        <option value="todos" <?= $filtro_estatus === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendiente" <?= $filtro_estatus === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="aprobado" <?= $filtro_estatus === 'aprobado' ? 'selected' : '' ?>>Aprobados</option>
                        <option value="rechazado" <?= $filtro_estatus === 'rechazado' ? 'selected' : '' ?>>Rechazados</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="todos" <?= $filtro_tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="comentario" <?= $filtro_tipo === 'comentario' ? 'selected' : '' ?>>Comentarios</option>
                        <option value="sugerencia" <?= $filtro_tipo === 'sugerencia' ? 'selected' : '' ?>>Sugerencias</option>
                        <option value="testimonio" <?= $filtro_tipo === 'testimonio' ? 'selected' : '' ?>>Testimonios</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Comentarios -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($comentarios)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-comment-slash fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No hay comentarios con los filtros seleccionados</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Autor</th>
                                <th>Tipo</th>
                                <th>Contenido</th>
                                <th>Calificación</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comentarios as $comentario): ?>
                            <tr>
                                <td><?= $comentario['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($comentario['nombre']) ?></strong>
                                    <?php if ($comentario['usuario_username']): ?>
                                        <br><small class="text-muted">@<?= htmlspecialchars($comentario['usuario_username']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= ucfirst($comentario['tipo']) ?></span>
                                </td>
                                <td>
                                    <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars(substr($comentario['contenido'], 0, 100)) ?>...
                                    </div>
                                </td>
                                <td>
                                    <?php if ($comentario['calificacion']): ?>
                                        <?php for ($i = 0; $i < 5; $i++): ?>
                                            <i class="fas fa-star <?= $i < $comentario['calificacion'] ? 'text-warning' : 'text-muted' ?>"></i>
                                        <?php endfor; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $estatus_badges = ['pendiente' => 'bg-warning', 'aprobado' => 'bg-success', 'rechazado' => 'bg-danger'];
                                    $badge_class = $estatus_badges[$comentario['estatus']] ?? 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $badge_class ?>"><?= ucfirst($comentario['estatus']) ?></span>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($comentario['fecha_creacion'])) ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?page=comments&action=view&id=<?= $comentario['id'] ?>" class="btn btn-info" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($comentario['estatus'] === 'pendiente'): ?>
                                            <a href="?page=comments&action=approve&id=<?= $comentario['id'] ?>" class="btn btn-success" title="Aprobar">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="?page=comments&action=reject&id=<?= $comentario['id'] ?>" class="btn btn-danger" title="Rechazar">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($redirect_url): ?>
<script>
    // Redirección después de acción completada
    window.location.href = '<?= $redirect_url ?>';
</script>
<?php endif; ?>

