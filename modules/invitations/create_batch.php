<?php
/**
 * Crear Invitaciones por Lotes
 * Permite crear múltiples invitaciones para un torneo a varios clubes a la vez
 */



error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';

Auth::requireRole(['admin_general','admin_torneo']);

$title = "Invitaciones por Lotes";
$errors = [];
$success = [];

try {
    $pdo = DB::pdo();
    
    // Obtener torneos disponibles
    $stmt = $pdo->query("SELECT id, nombre, fechator FROM tournaments WHERE estatus=1 ORDER BY fechator DESC");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener clubes
    $stmt = $pdo->query("SELECT id, nombre, delegado, telefono FROM clubes WHERE estatus=1 ORDER BY nombre ASC");
    $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::validate();
        
        $torneo_id = (int)$_POST['torneo_id'];
        $clubs_ids = $_POST['clubs_ids'] ?? [];
        $acceso1 = $_POST['acceso1'];
        $acceso2 = $_POST['acceso2'];
        $usuario = trim($_POST['usuario'] ?? '');
        $estado = $_POST['estado'] ?? 'activa';
        
        // Validaciones
        if (!$torneo_id) {
            $errors[] = "Debe seleccionar un torneo";
        }
        
        if (empty($clubs_ids)) {
            $errors[] = "Debe seleccionar al menos un club";
        }
        
        if (empty($acceso1) || empty($acceso2)) {
            $errors[] = "Las fechas de acceso son requeridas";
        } elseif ($acceso1 > $acceso2) {
            $errors[] = "La fecha de inicio no puede ser mayor que la fecha fin";
        }
        
        // Crear invitaciones
        if (empty($errors)) {
            $pdo->beginTransaction();
            
            try {
                $stmt_check = $pdo->prepare("SELECT id FROM invitations WHERE torneo_id = ? AND club_id = ?");
                $stmt_insert = $pdo->prepare("
                    INSERT INTO invitations 
                    (torneo_id, club_id, acceso1, acceso2, usuario, token, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $creadas = 0;
                $duplicadas = 0;
                
                foreach ($clubs_ids as $club_id) {
                    $club_id = (int)$club_id;
                    
                    // Verificar duplicado
                    $stmt_check->execute([$torneo_id, $club_id]);
                    if ($stmt_check->fetch()) {
                        $duplicadas++;
                        continue;
                    }
                    
                    // Generar token único
                    do {
                        $token = bin2hex(random_bytes(32));
                    } while (empty($token) || strlen($token) != 64);
                    
                    // Insertar
                    try {
                        if ($stmt_insert->execute([$torneo_id, $club_id, $acceso1, $acceso2, $usuario, $token, $estado])) {
                            $creadas++;
                        }
                    } catch (PDOException $e) {
                        // Si hay error, continuar con el siguiente
                        continue;
                    }
                }
                
                $pdo->commit();
                
                if ($creadas > 0) {
                    $success[] = "? {$creadas} invitación(es) creada(s) exitosamente";
                }
                
                if ($duplicadas > 0) {
                    $errors[] = "• {$duplicadas} invitación(es) ya exist�a(n) y no se crearon";
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error al crear invitaciones: " . $e->getMessage();
            }
        }
    }
    
} catch (PDOException $e) {
    die("Error de base de datos: " . $e->getMessage());
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
        .club-checkbox {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .club-checkbox:hover {
            background-color: #f8f9fa;
        }
        .club-checkbox input[type="checkbox"]:checked + label {
            font-weight: bold;
            color: #0d6efd;
        }
        .stats-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
    </style>
</head>
<body>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">?? Crear Invitaciones por Lotes</h4>
                    <small>Cree múltiples invitaciones para un torneo a varios clubes</small>
                </div>
                <div class="card-body">
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success">
                            <strong>? éxito:</strong>
                            <ul class="mb-0">
                                <?php foreach ($success as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <a href="index.php" class="btn btn-sm btn-success mt-2">Ver Invitaciones</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <strong>?? Errores:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($torneos)): ?>
                        <div class="alert alert-warning">
                            ?? No hay torneos disponibles. <a href="../tournaments/new.php">Crear torneo</a>
                        </div>
                    <?php elseif (empty($clubes)): ?>
                        <div class="alert alert-warning">
                            ?? No hay clubes registrados. <a href="<?= htmlspecialchars(function_exists('AppHelpers') ? AppHelpers::dashboard('clubs', ['action' => 'new']) : 'index.php?page=clubs&action=new') ?>">Crear club</a>
                        </div>
                    <?php else: ?>
                    
                    <form method="POST" id="formBatch">
                        <?= CSRF::input(); ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Torneo <span class="text-danger">*</span></label>
                            <select name="torneo_id" class="form-control" required>
                                <option value="">-- Seleccionar Torneo --</option>
                                <?php foreach ($torneos as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= (isset($_POST['torneo_id']) && $_POST['torneo_id'] == $t['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['nombre']) ?> - <?= date('d/m/Y', strtotime($t['fechator'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Clubes a Invitar <span class="text-danger">*</span></label>
                            
                            <div class="stats-box mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><strong>Total de Clubes:</strong> <?= count($clubes) ?></span>
                                    <span><strong>Seleccionados:</strong> <span id="countSelected">0</span></span>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="selectAll()">? Seleccionar Todos</button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAll()">? Limpiar</button>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
                                <?php foreach ($clubes as $c): ?>
                                    <div class="club-checkbox">
                                        <input type="checkbox" 
                                               name="clubs_ids[]" 
                                               value="<?= $c['id'] ?>" 
                                               id="club_<?= $c['id'] ?>"
                                               class="form-check-input"
                                               onchange="updateCount()">
                                        <label for="club_<?= $c['id'] ?>" class="form-check-label ms-2" style="cursor: pointer;">
                                            <?= htmlspecialchars($c['nombre']) ?>
                                            <?php if ($c['delegado']): ?>
                                                <small class="text-muted">(Delegado: <?= htmlspecialchars($c['delegado']) ?>)</small>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <small class="text-muted">Seleccione los clubes que desea invitar al torneo</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Acceso Desde <span class="text-danger">*</span></label>
                                    <input type="date" name="acceso1" class="form-control" 
                                           value="<?= $_POST['acceso1'] ?? date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Acceso Hasta <span class="text-danger">*</span></label>
                                    <input type="date" name="acceso2" class="form-control" 
                                           value="<?= $_POST['acceso2'] ?? '' ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Usuario (Opcional)</label>
                            <input type="text" name="usuario" class="form-control" 
                                   value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
                                   placeholder="Nombre del usuario/delegado">
                            <small class="text-muted">Nombre de referencia del responsable de la inscripción</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Estado</label>
                            <select name="estado" class="form-control">
                                <option value="activa" <?= (($_POST['estado'] ?? 'activa') === 'activa') ? 'selected' : '' ?>>Activa</option>
                                <option value="expirada" <?= (($_POST['estado'] ?? '') === 'expirada') ? 'selected' : '' ?>>Expirada</option>
                                <option value="cancelada" <?= (($_POST['estado'] ?? '') === 'cancelada') ? 'selected' : '' ?>>Cancelada</option>
                            </select>
                        </div>

                        <div class="alert alert-info">
                            <small>
                                <strong>?? Nota:</strong> Se generará un token único para cada invitación.
                                Las invitaciones que ya existan (mismo torneo + club) ser�n omitidas.
                            </small>
                        </div>

                        <div class="border-top pt-3 mt-3">
                            <button type="submit" class="btn btn-primary">
                                ? Crear Invitaciones
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                ? Cancelar
                            </a>
                        </div>
                    </form>
                    
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
function updateCount() {
    const checkboxes = document.querySelectorAll('input[name="clubs_ids[]"]:checked');
    document.getElementById('countSelected').textContent = checkboxes.length;
}

function selectAll() {
    document.querySelectorAll('input[name="clubs_ids[]"]').forEach(cb => {
        cb.checked = true;
    });
    updateCount();
}

function deselectAll() {
    document.querySelectorAll('input[name="clubs_ids[]"]').forEach(cb => {
        cb.checked = false;
    });
    updateCount();
}

// Validación antes de enviar
document.getElementById('formBatch').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="clubs_ids[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('• Debe seleccionar al menos un club');
        return false;
    }
    
    if (!confirm(`�Confirma crear ${checkboxes.length} invitación(es)?`)) {
        e.preventDefault();
        return false;
    }
});
</script>

</body>
</html>

