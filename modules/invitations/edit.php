<?php
/**
 * Editar Invitación
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';

Auth::requireRole(['admin_general','admin_torneo']);

$title = "Editar Invitación";
$errors = [];

if (!isset($_GET['id']) && !isset($_POST['id'])) {
    header("Location: index.php");
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: index.php");
    exit;
}

try {
    $pdo = DB::pdo();
    
    // Obtener invitación
    $stmt = $pdo->prepare("
        SELECT i.*, t.nombre as torneo_nombre, c.nombre as club_nombre
        FROM " . TABLE_INVITATIONS . " i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $invitacion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invitacion) {
        die("Invitación no encontrada");
    }
    
    // Procesar formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::validate();
        
        $acceso1 = $_POST['acceso1'];
        $acceso2 = $_POST['acceso2'];
        $usuario = trim($_POST['usuario'] ?? '');
        $estado = $_POST['estado'];
        $invitado_delegado = trim($_POST['invitado_delegado'] ?? '');
        $invitado_email = trim($_POST['invitado_email'] ?? '');
        
        // Validaciones
        if (empty($acceso1) || empty($acceso2)) {
            $errors[] = "Las fechas de acceso son requeridas";
        } elseif ($acceso1 > $acceso2) {
            $errors[] = "La fecha de inicio no puede ser mayor que la fecha fin";
        }
        
        // Actualizar (token no se modifica; invitado_delegado e invitado_email si existen)
        if (empty($errors)) {
            $cols = $pdo->query("SHOW COLUMNS FROM " . TABLE_INVITATIONS)->fetchAll(PDO::FETCH_COLUMN);
            $set = "acceso1 = ?, acceso2 = ?, usuario = ?, estado = ?";
            $params = [$acceso1, $acceso2, $usuario, $estado];
            if (in_array('invitado_delegado', $cols, true)) {
                $set .= ", invitado_delegado = ?";
                $params[] = $invitado_delegado === '' ? null : $invitado_delegado;
            }
            if (in_array('invitado_email', $cols, true)) {
                $set .= ", invitado_email = ?";
                $params[] = $invitado_email === '' ? null : $invitado_email;
            }
            $params[] = $id;
            $stmt = $pdo->prepare("UPDATE " . TABLE_INVITATIONS . " SET " . $set . " WHERE id = ?");
            if ($stmt->execute($params)) {
                $redirect = "index.php?page=invitations&msg=" . urlencode("Invitación actualizada");
                if (!empty($_POST['return_to']) && $_POST['return_to'] === 'invitacion_clubes' && !empty($_POST['torneo_id'])) {
                    $redirect = "index.php?page=invitacion_clubes&torneo_id=" . (int)$_POST['torneo_id'] . "&success=" . urlencode("Invitación actualizada");
                } elseif (!empty($_POST['filter_torneo'])) {
                    $redirect .= "&filter_torneo=" . (int)$_POST['filter_torneo'];
                }
                header("Location: " . $redirect);
                exit;
            } else {
                $errors[] = "Error al actualizar la invitacion";
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
</head>
<body>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">?? Editar Invitación #<?= $id ?></h4>
                </div>
                <div class="card-body">
                    
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

                    <!-- Información no editable -->
                    <div class="alert alert-info">
                        <strong>?? Torneo:</strong> <?= htmlspecialchars($invitacion['torneo_nombre']) ?><br>
                        <strong>?? Club:</strong> <?= htmlspecialchars($invitacion['club_nombre']) ?><br>
                        <strong>?? Token:</strong> <code><?= htmlspecialchars($invitacion['token']) ?></code>
                    </div>
                    
                    <form method="POST">
                        <?= CSRF::input(); ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Acceso Desde <span class="text-danger">*</span></label>
                                    <input type="date" name="acceso1" class="form-control" 
                                           value="<?= $invitacion['acceso1'] ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Acceso Hasta <span class="text-danger">*</span></label>
                                    <input type="date" name="acceso2" class="form-control" 
                                           value="<?= $invitacion['acceso2'] ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Usuario (Opcional)</label>
                            <input type="text" name="usuario" class="form-control" 
                                   value="<?= htmlspecialchars($invitacion['usuario']) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Estado</label>
                            <select name="estado" class="form-control">
                                <option value="activa" <?= $invitacion['estado'] === 'activa' ? 'selected' : '' ?>>Activa</option>
                                <option value="expirada" <?= $invitacion['estado'] === 'expirada' ? 'selected' : '' ?>>Expirada</option>
                                <option value="cancelada" <?= $invitacion['estado'] === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Delegado / Contacto invitado</label>
                            <input type="text" name="invitado_delegado" class="form-control" value="<?= htmlspecialchars($invitacion['invitado_delegado'] ?? '') ?>" placeholder="Nombre del delegado">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email del invitado</label>
                            <input type="email" name="invitado_email" class="form-control" value="<?= htmlspecialchars($invitacion['invitado_email'] ?? '') ?>" placeholder="email@ejemplo.com">
                        </div>
                        <?php if (!empty($_GET['filter_torneo'])): ?>
                        <input type="hidden" name="filter_torneo" value="<?= (int)$_GET['filter_torneo'] ?>">
                        <?php endif; ?>
                        <?php if (!empty($_GET['return_to']) && $_GET['return_to'] === 'invitacion_clubes' && !empty($_GET['torneo_id'])): ?>
                        <input type="hidden" name="return_to" value="invitacion_clubes">
                        <input type="hidden" name="torneo_id" value="<?= (int)$_GET['torneo_id'] ?>">
                        <?php endif; ?>
                        <div class="alert alert-secondary">
                            <small>
                                <strong>Creada:</strong> <?= date('d/m/Y H:i', strtotime($invitacion['fecha_creacion'])) ?><br>
                                <strong>Modificada:</strong> <?= date('d/m/Y H:i', strtotime($invitacion['fecha_modificacion'])) ?>
                            </small>
                        </div>

                        <div class="border-top pt-3 mt-3">
                            <button type="submit" class="btn btn-primary">
                                Guardar Cambios
                            </button>
                            <?php
                            $cancel_url = 'index.php';
                            if (!empty($_GET['return_to']) && $_GET['return_to'] === 'invitacion_clubes' && !empty($_GET['torneo_id'])) {
                                $cancel_url = 'index.php?page=invitacion_clubes&torneo_id=' . (int)$_GET['torneo_id'];
                            } elseif (!empty($_GET['filter_torneo'])) {
                                $cancel_url = 'index.php?page=invitations&filter_torneo=' . (int)$_GET['filter_torneo'];
                            }
                            ?>
                            <a href="<?= htmlspecialchars($cancel_url) ?>" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>










