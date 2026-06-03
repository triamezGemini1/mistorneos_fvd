<?php
/**
 * Script de limpieza de base de datos
 * Limpia tokens vacíos y registros inválidos
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

Auth::requireRole(['admin_general']);

$errors = [];
$success = [];
$info = [];

try {
    $pdo = DB::pdo();
    
    // 1. Verificar tokens vacíos o NULL
    $stmt = $pdo->query("
        SELECT id, torneo_id, club_id, token, LENGTH(token) as token_length 
        FROM invitations 
        WHERE token = '' OR token IS NULL OR LENGTH(token) != 64
    ");
    $invalid_tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $info[] = "Tokens inválidos encontrados: " . count($invalid_tokens);
    
    // 2. Eliminar registros con tokens inválidos
    if (count($invalid_tokens) > 0) {
        $stmt = $pdo->prepare("DELETE FROM invitations WHERE id = ?");
        $deleted = 0;
        
        foreach ($invalid_tokens as $inv) {
            if ($stmt->execute([$inv['id']])) {
                $deleted++;
            }
        }
        
        $success[] = "? {$deleted} registro(s) con tokens inválidos eliminados";
    } else {
        $success[] = "? No se encontraron tokens inválidos";
    }
    
    // 3. Verificar duplicados de torneo + club
    $stmt = $pdo->query("
        SELECT torneo_id, club_id, COUNT(*) as count 
        FROM invitations 
        GROUP BY torneo_id, club_id 
        HAVING count > 1
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($duplicates) > 0) {
        $info[] = "Duplicados encontrados: " . count($duplicates);
        
        // Eliminar duplicados, conservando solo el más reciente
        foreach ($duplicates as $dup) {
            $stmt = $pdo->prepare("
                DELETE FROM invitations 
                WHERE torneo_id = ? AND club_id = ? 
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT MAX(id) as id 
                        FROM invitations 
                        WHERE torneo_id = ? AND club_id = ?
                    ) as temp
                )
            ");
            $stmt->execute([$dup['torneo_id'], $dup['club_id'], $dup['torneo_id'], $dup['club_id']]);
        }
        
        $success[] = "? Duplicados eliminados, conservando los más recientes";
    } else {
        $success[] = "? No se encontraron duplicados";
    }
    
    // 4. Actualizar tokens vacíos que a�n existan
    $stmt = $pdo->query("
        SELECT id FROM invitations 
        WHERE token = '' OR token IS NULL
    ");
    $to_update = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($to_update) > 0) {
        $stmt = $pdo->prepare("UPDATE invitations SET token = ? WHERE id = ?");
        $updated = 0;
        
        foreach ($to_update as $inv) {
            $new_token = bin2hex(random_bytes(32));
            if ($stmt->execute([$new_token, $inv['id']])) {
                $updated++;
            }
        }
        
        $success[] = "? {$updated} token(s) regenerado(s)";
    }
    
    // 5. Estadísticas finales
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM invitations");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as valid FROM invitations WHERE LENGTH(token) = 64");
    $valid = $stmt->fetch(PDO::FETCH_ASSOC)['valid'];
    
    $info[] = "Total de invitaciones: {$total}";
    $info[] = "Invitaciones v�lidas: {$valid}";
    
} catch (PDOException $e) {
    $errors[] = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limpieza de Base de Datos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">?? Limpieza de Base de Datos</h4>
                </div>
                <div class="card-body">
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success">
                            <h5>? Operaciones Exitosas</h5>
                            <ul class="mb-0">
                                <?php foreach ($success as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($info)): ?>
                        <div class="alert alert-info">
                            <h5>?? Información</h5>
                            <ul class="mb-0">
                                <?php foreach ($info as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <h5>?? Errores</h5>
                            <ul class="mb-0">
                                <?php foreach ($errors as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-4">
                        <a href="index.php" class="btn btn-primary">?? Volver al Listado</a>
                        <button onclick="location.reload()" class="btn btn-secondary">?? Ejecutar de Nuevo</button>
                    </div>
                    
                </div>
            </div>
            
            <div class="alert alert-warning mt-4">
                <h5>?? Importante</h5>
                <ul>
                    <li>Este script limpia tokens vacíos o inválidos</li>
                    <li>Elimina duplicados (mantiene el más reciente)</li>
                    <li>Regenera tokens cuando es necesario</li>
                    <li>Solo usuarios con rol admin_general pueden acceder</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>










