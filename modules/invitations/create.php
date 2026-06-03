<?php
/**
 * Crear Nueva Invitación (misma lógica que invitacion_clubes: usuario creador, datos del club, columnas dinámicas).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';

Auth::requireRole(['admin_general', 'admin_torneo']);

$title = "Nueva Invitación";
$errors = [];

$tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';

try {
    $pdo = DB::pdo();

    $stmt = $pdo->query("SELECT id, nombre, fechator FROM tournaments WHERE estatus=1 ORDER BY fechator DESC");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT id, nombre, delegado, telefono, email FROM clubes WHERE estatus=1 ORDER BY nombre ASC");
    $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::validate();

        $torneo_id = (int)($_POST['torneo_id'] ?? 0);
        $club_id = (int)($_POST['club_id'] ?? 0);
        $acceso1 = trim((string)($_POST['acceso1'] ?? ''));
        $acceso2 = trim((string)($_POST['acceso2'] ?? ''));

        if (!$torneo_id) {
            $errors[] = "Debe seleccionar un torneo";
        }
        if (!$club_id) {
            $errors[] = "Debe seleccionar un club";
        }
        if ($acceso1 === '' || $acceso2 === '') {
            $errors[] = "Las fechas de acceso son requeridas";
        } elseif ($acceso1 > $acceso2) {
            $errors[] = "La fecha de inicio no puede ser mayor que la fecha fin";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM {$tb_inv} WHERE torneo_id = ? AND club_id = ?");
            $stmt->execute([$torneo_id, $club_id]);
            if ($stmt->fetch()) {
                $errors[] = "Ya existe una invitación para este torneo y club";
            }
        }

        if (empty($errors)) {
            if (!Auth::canAccessTournament($torneo_id)) {
                $errors[] = "No tiene permiso para crear invitaciones en este torneo";
            } else {
                $stmt = $pdo->prepare("SELECT id, nombre, delegado, telefono, email FROM clubes WHERE id = ? AND estatus = 1");
                $stmt->execute([$club_id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);
                $inv_delegado = $club['delegado'] ?? null;
                $inv_email = $club['email'] ?? null;
                $club_tel = $club['telefono'] ?? null;

                $token = bin2hex(random_bytes(32));
                if (strlen($token) !== 64) {
                    $errors[] = "Error al generar token de seguridad";
                } else {
                    $usuario_creador = (Auth::user() && isset(Auth::user()['id'])) ? (string)Auth::user()['id'] : '';
                    $admin_club_id = Auth::id();

                    $cols_inv = $pdo->query("SHOW COLUMNS FROM {$tb_inv}")->fetchAll(PDO::FETCH_COLUMN);
                    $campos = [
                        'torneo_id' => $torneo_id,
                        'club_id' => $club_id,
                        'invitado_delegado' => $inv_delegado,
                        'invitado_email' => $inv_email,
                        'acceso1' => $acceso1,
                        'acceso2' => $acceso2,
                        'usuario' => $usuario_creador,
                        'club_email' => $inv_email,
                        'club_telefono' => $club_tel,
                        'club_delegado' => $inv_delegado,
                        'token' => $token,
                        'estado' => 'activa',
                    ];
                    foreach ($cols_inv as $col_name) {
                        if (strtolower((string)$col_name) === 'admin_club_id') {
                            $campos[$col_name] = $admin_club_id;
                            break;
                        }
                    }
                    $cols = array_values(array_intersect($cols_inv, array_keys($campos)));
                    $vals = array_map(function ($c) use ($campos) {
                        return $campos[$c];
                    }, $cols);
                    if (!empty($cols)) {
                        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                        $stmt = $pdo->prepare("INSERT INTO {$tb_inv} (" . implode(', ', $cols) . ") VALUES ({$placeholders})");
                        $stmt->execute($vals);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO {$tb_inv} (torneo_id, club_id, acceso1, acceso2, usuario, token, estado) VALUES (?, ?, ?, ?, ?, ?, 'activa')");
                        $stmt->execute([$torneo_id, $club_id, $acceso1, $acceso2, $usuario_creador, $token]);
                    }
                    $redirect = "../../public/index.php?page=invitations&filter_torneo=" . $torneo_id . "&success=1&msg=" . urlencode("Invitación creada.");
                    header("Location: " . $redirect);
                    exit;
                }
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = "Error: " . $e->getMessage();
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
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0">? Nueva Invitación a Torneo</h4>
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

                    <?php if (empty($torneos)): ?>
                        <div class="alert alert-warning">
                            ?? No hay torneos disponibles. <a href="../tournaments/new.php">Crear torneo</a>
                        </div>
                    <?php elseif (empty($clubes)): ?>
                        <div class="alert alert-warning">
                            ?? No hay clubes registrados. <a href="<?= htmlspecialchars(function_exists('AppHelpers') ? AppHelpers::dashboard('clubs', ['action' => 'new']) : 'index.php?page=clubs&action=new') ?>">Crear club</a>
                        </div>
                    <?php else: ?>
                    
                    <form method="POST">
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
                            <label class="form-label">Club <span class="text-danger">*</span></label>
                            <select name="club_id" id="club_id" class="form-control" required>
                                <option value="">-- Seleccionar Club --</option>
                                <?php foreach ($clubes as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                            data-nombre="<?= htmlspecialchars($c['nombre']) ?>"
                                            <?= (isset($_POST['club_id']) && $_POST['club_id'] == $c['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                                <strong>?? Nota:</strong> El token de seguridad se generará automáticamente.
                                Este token ser• necesario para que el club pueda inscribir jugadores.
                            </small>
                        </div>

                        <div class="border-top pt-3 mt-3">
                            <button type="submit" class="btn btn-success">
                                ? Crear Invitación
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
</body>
</html>

