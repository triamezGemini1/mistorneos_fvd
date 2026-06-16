<?php
/**
 * Vista: Asignar mesas de una ronda a operadores (ámbito nacional: cualquier operador activo).
 */
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$action_param = $use_standalone ? '?' : '&';
$operadores = $operadores ?? [];
$mesas_numeros = $mesas_numeros ?? [];
$asignaciones = $asignaciones ?? [];
$tabla_existe = $tabla_existe ?? false;
$url_gestionar_operadores = class_exists('AppHelpers')
    ? AppHelpers::dashboard('admin_torneo_operadores', [
        'tab' => 'operadores',
        'return_torneo_id' => (int) ($torneo_id ?? 0),
    ])
    : '';
?>
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-user-cog text-primary"></i> Asignar mesas al operador
                <small class="text-muted">Ronda <?= (int)$ronda; ?> - <?= htmlspecialchars($torneo['nombre'] ?? ''); ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($base_url) ?>">Gestión de Torneos</a></li>
                    <li class="breadcrumb-item"><a href="<?= $base_url . $action_param; ?>action=panel&torneo_id=<?= (int)$torneo_id; ?>"><?= htmlspecialchars($torneo['nombre'] ?? ''); ?></a></li>
                    <li class="breadcrumb-item"><a href="<?= $base_url . $action_param; ?>action=mesas&torneo_id=<?= (int)$torneo_id; ?>&ronda=<?= (int)$ronda; ?>">Mesas Ronda <?= (int)$ronda; ?></a></li>
                    <li class="breadcrumb-item active">Asignar mesas al operador</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <a href="<?= $base_url . $action_param; ?>action=panel&torneo_id=<?= (int)$torneo_id; ?>" class="btn btn-secondary btn-lg me-2">
                <i class="fas fa-arrow-left me-2"></i> Volver al Panel
            </a>
            <a href="<?= $base_url . $action_param; ?>action=mesas&torneo_id=<?= (int)$torneo_id; ?>&ronda=<?= (int)$ronda; ?>" class="btn btn-info btn-lg">
                <i class="fas fa-chess me-2"></i> Ver Mesas
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($operadores)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No hay operadores registrados en la plataforma.
            <?php if ($url_gestionar_operadores !== ''): ?>
                <a href="<?= htmlspecialchars($url_gestionar_operadores) ?>" class="alert-link">Registrar o asignar operadores</a>
                (de cualquier asociación) y luego vuelva aquí para asignar las mesas.
            <?php else: ?>
                Registre operadores desde <strong>Admin Torneo y Operadores</strong> y luego vuelva aquí.
            <?php endif; ?>
        </div>
    <?php elseif (empty($mesas_numeros)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No hay mesas en esta ronda. Genere la ronda desde el panel de control.
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Asignar operador por mesa (Ronda <?= (int)$ronda; ?>)</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= $use_standalone ? $base_url : 'index.php?page=torneo_gestion'; ?>">
                    <input type="hidden" name="action" value="guardar_asignacion_mesas_operador">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()); ?>">
                    <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id; ?>">
                    <input type="hidden" name="ronda" value="<?= (int)$ronda; ?>">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Mesa</th>
                                    <th>Operador que atiende</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mesas_numeros as $num_mesa): ?>
                                    <tr>
                                        <td><strong>Mesa <?= (int)$num_mesa; ?></strong></td>
                                        <td>
                                            <select name="asignacion[<?= (int)$num_mesa; ?>]" class="form-select form-select-sm w-auto">
                                                <option value="0">— Sin asignar —</option>
                                                <?php foreach ($operadores as $op): ?>
                                                    <?php
                                                    $opLabel = htmlspecialchars($op['nombre']);
                                                    if (!empty($op['username'])) {
                                                        $opLabel .= ' (' . htmlspecialchars($op['username']) . ')';
                                                    }
                                                    if (!empty($op['club_nombre'])) {
                                                        $opLabel .= ' — ' . htmlspecialchars($op['club_nombre']);
                                                    }
                                                    ?>
                                                    <option value="<?= (int)$op['id']; ?>" <?= (($asignaciones[$num_mesa] ?? 0) == (int)$op['id']) ? 'selected' : ''; ?>>
                                                        <?= $opLabel; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i> Guardar asignación
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
