<?php
/**
 * Guardar pago de club
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';

// Verificar autenticación
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

// Configurar respuesta JSON
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'M�todo no permitido']);
    exit;
}

try {
    // Verificar CSRF
    CSRF::validate();
    
    // Validar datos
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $club_id = (int)($_POST['club_id'] ?? 0);
    
    // Validar acceso al torneo antes de procesar
    if (!Auth::canAccessTournament($torneo_id)) {
        throw new Exception('No tiene permisos para acceder a este torneo');
    }
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $tipo_pago = $_POST['tipo_pago'] ?? 'efectivo';
    $moneda = $_POST['moneda'] ?? 'USD';
    $monto = (float)($_POST['monto'] ?? 0); // Este es el monto en dólares
    $tasa_cambio = (float)($_POST['tasa_cambio'] ?? 0);
    $monto_dolares = (float)($_POST['monto_dolares'] ?? $monto);
    $monto_total = (float)($_POST['monto_total'] ?? $monto);
    $referencia = trim($_POST['referencia'] ?? '');
    $banco = trim($_POST['banco'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    if ($torneo_id <= 0 || $club_id <= 0) {
        throw new Exception('Torneo y club son requeridos');
    }
    
    if ($monto <= 0) {
        throw new Exception('El monto debe ser mayor que cero');
    }
    
    // Validar tasa de cambio si es en bolívares
    if ($moneda === 'BS' && $tasa_cambio <= 0) {
        throw new Exception('La tasa de cambio es requerida para pagos en bolívares');
    }
    
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    
    // Verificar deuda existente
    $stmt = $pdo->prepare("SELECT monto_total, abono FROM deuda_clubes WHERE torneo_id = ? AND club_id = ?");
    $stmt->execute([$torneo_id, $club_id]);
    $deuda = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deuda) {
        throw new Exception('No existe deuda registrada para este club y torneo');
    }
    
    $pendiente = $deuda['monto_total'] - $deuda['abono'];
    
    // El monto en dólares no puede exceder la deuda pendiente
    if ($monto_dolares > $pendiente) {
        throw new Exception('El monto excede la deuda pendiente ($' . number_format((float)$pendiente, 2) . ')');
    }
    
    // Obtener siguiente secuencia
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(secuencia), 0) + 1 as siguiente FROM relacion_pagos WHERE torneo_id = ? AND club_id = ?");
    $stmt->execute([$torneo_id, $club_id]);
    $secuencia = $stmt->fetchColumn();
    
    // Insertar pago
    $stmt = $pdo->prepare("
        INSERT INTO relacion_pagos (
            torneo_id, club_id, secuencia, fecha, tipo_pago, moneda, 
            tasa_cambio, monto_dolares, monto_total, referencia, banco, observaciones
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $torneo_id, $club_id, $secuencia, $fecha, $tipo_pago, $moneda,
        $tasa_cambio, $monto_dolares, $monto_total, $referencia, $banco, $observaciones
    ]);
    
    // Actualizar abono en deuda_clubes (siempre en dólares)
    $stmt = $pdo->prepare("UPDATE deuda_clubes SET abono = COALESCE(abono, 0) + ? WHERE torneo_id = ? AND club_id = ?");
    $stmt->execute([$monto_dolares, $torneo_id, $club_id]);
    
    $pdo->commit();
    
    $mensaje = 'Pago registrado exitosamente';
    if ($moneda === 'BS') {
        $mensaje .= ' - $' . number_format($monto_dolares, 2) . ' USD (Bs. ' . number_format($monto_total, 2) . ' a tasa de ' . number_format($tasa_cambio, 2) . ')';
    } else {
        $mensaje .= ' - $' . number_format($monto_dolares, 2) . ' USD';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'torneo_id' => $torneo_id,
        'monto' => $monto_dolares,
        'moneda' => $moneda,
        'tasa_cambio' => $tasa_cambio,
        'monto_total' => $monto_total
    ]);
    exit;
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al registrar pago: ' . $e->getMessage()
    ]);
    exit;
}

