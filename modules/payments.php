<?php
require_once __DIR__ . '/../config/auth.php';

// Verificar que solo admin_general y admin_torneo pueden acceder
Auth::requireRole(['admin_general', 'admin_torneo']);

// Obtener acción solicitada
$action = $_GET['action'] ?? 'list';

// Obtener lista de pagos
$payments = [];
try {
    $stmt = DB::pdo()->query("
        SELECT p.*, r.nombre, c.nombre as club_name, t.nombre as tournament_name
        FROM payments p 
        LEFT JOIN tournaments t ON p.torneo_id = t.id 
        LEFT JOIN clubes c ON p.club_id = c.id
        LEFT JOIN inscripciones r ON r.torneo_id = t.id
        ORDER BY p.created_at DESC
    ");
    $payments = $stmt->fetchAll();
} catch (Exception $e) {
    $error_message = "Error al obtener pagos: " . $e->getMessage();
}
?>

<div class="fade-in">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-credit-card me-2"></i>
                Gestión de Pagos
            </h1>
            <p class="text-muted mb-0">Administra los pagos del sistema</p>
        </div>
        <div>
            <a href="index.php?page=payments&action=new" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Nuevo Pago
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-success mb-2">
                        <i class="fas fa-check-circle fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= count(array_filter($payments, fn($p) => $p['status'] === 'completed')) ?></h4>
                    <p class="text-muted mb-0">Completados</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-warning mb-2">
                        <i class="fas fa-clock fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= count(array_filter($payments, fn($p) => $p['status'] === 'pending')) ?></h4>
                    <p class="text-muted mb-0">Pendientes</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-danger mb-2">
                        <i class="fas fa-times-circle fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= count(array_filter($payments, fn($p) => $p['status'] === 'failed')) ?></h4>
                    <p class="text-muted mb-0">Fallidos</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-info mb-2">
                        <i class="fas fa-dollar-sign fs-1"></i>
                    </div>
                    <h4 class="mb-1">$<?= number_format(array_sum(array_column(array_filter($payments, fn($p) => $p['status'] === 'completed'), 'amount')), 2) ?></h4>
                    <p class="text-muted mb-0">Total</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista de Pagos -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Lista de Pagos
            </h5>
            <span class="badge bg-primary"><?= count($payments) ?> pagos</span>
        </div>
        <div class="card-body">
            <?php if (!empty($payments)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Inscrito</th>
                                <th>Torneo</th>
                                <th>Club</th>
                                <th>Monto</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= htmlspecialchars($payment['id']) ?></td>
                                    <td>
                                        <i class="fas fa-user text-muted me-2"></i>
                                        <?= htmlspecialchars($payment['nombre'] ?? 'N/A') ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-trophy text-warning me-2"></i>
                                        <?= htmlspecialchars($payment['tournament_name'] ?? 'N/A') ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-building text-muted me-2"></i>
                                        <?= htmlspecialchars($payment['club_name'] ?? 'N/A') ?>
                                    </td>
                                    <td>
                                        <strong>$<?= number_format((float)$payment['amount'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        $status_classes = [
                                            'completed' => 'bg-success',
                                            'pending' => 'bg-warning',
                                            'failed' => 'bg-danger'
                                        ];
                                        $status_texts = [
                                            'completed' => 'Completado',
                                            'pending' => 'Pendiente',
                                            'failed' => 'Fallido'
                                        ];
                                        $class = $status_classes[$payment['status']] ?? 'bg-secondary';
                                        $text = $status_texts[$payment['status']] ?? 'Desconocido';
                                        ?>
                                        <span class="badge <?= $class ?>"><?= $text ?></span>
                                    </td>
                                    <td>
                                        <i class="fas fa-calendar text-muted me-2"></i>
                                        <?= date('d/m/Y', strtotime($payment['created_at'])) ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-info" title="Ver detalles">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-credit-card text-muted fs-1 mb-3"></i>
                    <h5 class="text-muted">No hay pagos registrados</h5>
                    <p class="text-muted">Los pagos aparecer�n aqu• cuando se registren</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
