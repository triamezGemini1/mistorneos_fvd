<?php
/**
 * Gesti?n de Invitaciones a Torneos
 * Sistema replanteado siguiendo la l?gica de negocio de invitorfvd
 */


require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

Auth::requireRole(['admin_general','admin_torneo']);

$title = "Invitaciones a Torneos";

try {
    $pdo = DB::pdo();
    
    // Paginaci?n (usar 'pag' en lugar de 'page' para evitar conflictos)
    $pagination_page = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
    $pagination_page = max(1, $pagination_page); // Asegurar que sea al menos 1
    $per_page = 15;
    $offset = ($pagination_page - 1) * $per_page;
    
    // Filtros
    $torneo_filter = $_GET['torneo'] ?? '';
    $estado_filter = $_GET['estado'] ?? '';
    
    // Construir query
    $where = [];
    $params = [];
    
    if ($torneo_filter) {
        $where[] = "i.torneo_id = ?";
        $params[] = $torneo_filter;
    }
    
    if ($estado_filter) {
        $where[] = "i.estado = ?";
        $params[] = $estado_filter;
    }
    
    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Contar total
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM invitations i {$where_clause}");
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total / $per_page);
    
    // Obtener invitaciones
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        {$where_clause}
        ORDER BY i.fecha_creacion DESC
        LIMIT {$per_page} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $invitaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener torneos para filtro
    $stmt = $pdo->query("SELECT id, nombre FROM tournaments WHERE estatus=1 ORDER BY fechator DESC");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estad?sticas
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'activa' THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN estado = 'expirada' THEN 1 ELSE 0 END) as expiradas,
            SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) as canceladas
        FROM invitations
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("? Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .stats-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }
        .stats-card h2 {
            font-size: 2.5rem;
            margin: 10px 0;
        }
        .stats-card h5 {
            font-size: 1rem;
            opacity: 0.9;
        }
    </style>
</head>
<body>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3>?? Invitaciones a Torneos</h3>
            <p class="text-muted mb-0">Gesti?n de invitaciones enviadas a clubes</p>
        </div>
        <div>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('home')) ?>" class="btn btn-secondary">?? Volver</a>
            <a href="../../report_invitations.php" class="btn btn-danger me-2">
                <i class="fas fa-file-pdf me-2"></i>Descargar PDF
            </a>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/enviar_masivo')) ?>" class="btn btn-success btn-lg me-2">
                <i class="fab fa-whatsapp me-2"></i>?? Env?o Masivo
            </a>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/create_batch')) ?>" class="btn btn-primary">?? Invitaciones por Lotes</a>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/create')) ?>" class="btn btn-success">? Nueva Invitaci?n</a>
        </div>
    </div>

    <!-- Estad?sticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card bg-primary">
                <h5>Total Invitaciones</h5>
                <h2><?= $stats['total'] ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card bg-success">
                <h5>Activas</h5>
                <h2><?= $stats['activas'] ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card bg-warning">
                <h5>Expiradas</h5>
                <h2><?= $stats['expiradas'] ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card bg-danger">
                <h5>Canceladas</h5>
                <h2><?= $stats['canceladas'] ?></h2>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-trophy me-1"></i>Torneo</label>
                    <select name="torneo" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Todos los Torneos --</option>
                        <?php foreach ($torneos as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $torneo_filter == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-check-circle me-1"></i>Estado</label>
                    <select name="estado" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Todos los Estados --</option>
                        <option value="activa" <?= $estado_filter === 'activa' ? 'selected' : '' ?>>Activa</option>
                        <option value="expirada" <?= $estado_filter === 'expirada' ? 'selected' : '' ?>>Expirada</option>
                        <option value="cancelada" <?= $estado_filter === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations')) ?>" class="btn btn-secondary w-100">
                        <i class="fas fa-times me-2"></i>Limpiar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabla de invitaciones -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Torneo</th>
                            <th>Club</th>
                            <th>Delegado</th>
                            <th>Vigencia</th>
                            <th>Token</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invitaciones)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <p class="text-muted mb-0">No hay invitaciones registradas</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invitaciones as $inv): ?>
                                <tr>
                                    <td><?= $inv['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($inv['torneo_nombre']) ?></strong><br>
                                        <small class="text-muted"><?= date('d/m/Y', strtotime($inv['torneo_fecha'])) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($inv['club_nombre']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($inv['club_delegado'] ?: 'N/A') ?><br>
                                        <small class="text-muted"><?= htmlspecialchars($inv['club_telefono'] ?: '') ?></small>
                                    </td>
                                    <td>
                                        <small>
                                            <?= date('d/m/Y', strtotime($inv['acceso1'])) ?><br>
                                            al <?= date('d/m/Y', strtotime($inv['acceso2'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small><code><?= substr($inv['token'], 0, 10) ?>...</code></small><br>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="verToken('<?= htmlspecialchars($inv['token']) ?>', <?= $inv['id'] ?>)">
                                            ?? Ver Token
                                        </button>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'activa' => 'bg-success',
                                            'expirada' => 'bg-warning',
                                            'cancelada' => 'bg-danger'
                                        ][$inv['estado']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $badge_class ?>">
                                            <?= ucfirst($inv['estado']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/edit', ['id' => $inv['id']])) ?>" 
                                               class="btn btn-outline-primary" 
                                               title="Editar">
                                                ??
                                            </a>
                                            
                                            <?php if ($inv['estado'] === 'activa'): ?>
                                                <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/toggle_estado', ['id' => $inv['id'], 'action' => 'expirar'])) ?>" 
                                                   class="btn btn-outline-warning" 
                                                   title="Marcar Expirada"
                                                   onclick="return confirm('?Marcar como expirada?')">
                                                    ?
                                                </a>
                                                <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/toggle_estado', ['id' => $inv['id'], 'action' => 'cancelar'])) ?>" 
                                                   class="btn btn-outline-danger" 
                                                   title="Cancelar"
                                                   onclick="return confirm('?Cancelar invitaci?n?')">
                                                    ?
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/toggle_estado', ['id' => $inv['id'], 'action' => 'activar'])) ?>" 
                                                   class="btn btn-outline-success" 
                                                   title="Activar"
                                                   onclick="return confirm('?Activar invitaci?n?')">
                                                    ?
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/whatsapp')) ?>&id=<?= $inv['id'] ?>" 
                                               class="btn btn-success btn-sm" 
                                               title="Enviar por WhatsApp (con PDF)" 
>
                                                ??
                                            </a>
                                            
                                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/imprimir_invitacion', ['id' => $inv['id']])) ?>" 
                                               class="btn btn-info btn-sm" 
                                               title="Ver/Imprimir Invitaci?n" 
>
                                                ??
                                            </a>
                                            
                                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('invitations/delete', ['id' => $inv['id']])) ?>" 
                                               class="btn btn-outline-danger" 
                                               onclick="return confirm('?Eliminar esta invitaci?n?')" 
                                               title="Eliminar">
                                                ???
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginaci?n -->
            <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i === $pagination_page ? 'active' : '' ?>">
                                <a class="page-link" href="index.php?page=invitations&pag=<?= $i ?><?= $torneo_filter ? '&torneo=' . urlencode($torneo_filter) : '' ?><?= $estado_filter ? '&estado=' . urlencode($estado_filter) : '' ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>

<!-- Modal para mostrar token completo -->
<div class="modal fade" id="modalToken" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">?? Token de Invitaci?n</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong>ID:</strong> <span id="tokenId"></span>
                </div>
                <p><strong>Token Completo:</strong></p>
                <div class="p-3 bg-light border rounded">
                    <code id="tokenCompleto" style="word-break: break-all; font-size: 0.9rem;"></code>
                </div>
                <button class="btn btn-sm btn-primary mt-2" onclick="copiarToken()">
                    ?? Copiar Token
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function verToken(token, id) {
    document.getElementById('tokenId').textContent = id;
    document.getElementById('tokenCompleto').textContent = token;
    new bootstrap.Modal(document.getElementById('modalToken')).show();
}

function copiarToken() {
    const token = document.getElementById('tokenCompleto').textContent;
    navigator.clipboard.writeText(token).then(() => {
        alert('✓ Token copiado al portapapeles');
    }).catch(() => {
        // Fallback para navegadores antiguos
        const textarea = document.createElement('textarea');
        textarea.value = token;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('✓ Token copiado al portapapeles');
    });
}
</script>

</body>
</html>

