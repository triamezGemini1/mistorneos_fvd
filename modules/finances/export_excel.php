<?php
/**
 * Exportar Deudas a Excel (Formato HTML)
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

try {
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    
    // Validar acceso al torneo si está especificado
    if ($torneo_id > 0 && !Auth::canAccessTournament($torneo_id)) {
        throw new Exception('No tiene permisos para acceder a este torneo');
    }
    
    $titulo = 'TODAS LAS DEUDAS';
    $where = "";
    $params = [];
    
    if ($torneo_id > 0) {
        $where = "WHERE d.torneo_id = ?";
        $params = [$torneo_id];
        
        // Obtener nombre del torneo
        $stmt = DB::pdo()->prepare("SELECT nombre FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo_nombre = $stmt->fetchColumn();
        if ($torneo_nombre) {
            $titulo = strtoupper($torneo_nombre);
        }
    }
    
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha
        FROM deuda_clubes d
        INNER JOIN clubes c ON d.club_id = c.id
        INNER JOIN tournaments t ON d.torneo_id = t.id
        $where
        ORDER BY (d.monto_total - d.abono) DESC, c.nombre ASC
    ");
    $stmt->execute($params);
    $deudas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($deudas)) {
        die('No hay datos para exportar');
    }
    
    // Calcular totales
    $totales = [
        'inscritos' => array_sum(array_column($deudas, 'total_inscritos')),
        'deuda' => array_sum(array_column($deudas, 'monto_total')),
        'pagado' => array_sum(array_column($deudas, 'abono')),
        'pendiente' => 0
    ];
    $totales['pendiente'] = $totales['deuda'] - $totales['pagado'];
    
    $filename = 'deudas_' . date('Y-m-d_His') . '.xls';
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
} catch (Exception $e) {
    die('Error: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Deudas</title>
</head>
<body>
    <table border="1" cellpadding="5" cellspacing="0">
        <thead>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="10" style="text-align: center; font-size: 14pt; padding: 10px;">
                    REPORTE DE DEUDAS - <?= htmlspecialchars($titulo) ?>
                </td>
            </tr>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="10" style="text-align: center; padding: 5px;">
                    Generado: <?= date('d/m/Y H:i') ?>
                </td>
            </tr>
            <tr style="height: 10px;"><td colspan="10"></td></tr>
            
            <tr style="background-color: #f8f9fa; font-weight: bold;">
                <td style="text-align: center;">CLUB</td>
                <td style="text-align: center;">DELEGADO</td>
                <td style="text-align: center;">TEL�FONO</td>
                <td style="text-align: center;">TORNEO</td>
                <td style="text-align: center;">FECHA TORNEO</td>
                <td style="text-align: center;">INSCRITOS</td>
                <td style="text-align: center;">DEUDA TOTAL</td>
                <td style="text-align: center;">PAGADO</td>
                <td style="text-align: center;">PENDIENTE</td>
                <td style="text-align: center;">%</td>
            </tr>
        </thead>
        <tbody>
            <?php 
            $num = 1;
            foreach ($deudas as $d): 
                $pendiente = (float)$d['monto_total'] - (float)$d['abono'];
                $porcentaje = (float)$d['monto_total'] > 0 ? (((float)$d['abono'] / (float)$d['monto_total']) * 100) : 0;
                $bg_color = ($num % 2 == 0) ? '#f8f9fa' : '#ffffff';
            ?>
                <tr style="background-color: <?= $bg_color ?>;">
                    <td style="font-weight: bold;"><?= htmlspecialchars($d['club_nombre']) ?></td>
                    <td><?= htmlspecialchars($d['club_delegado'] ?? '') ?></td>
                    <td><?= htmlspecialchars($d['club_telefono'] ?? '') ?></td>
                    <td><?= htmlspecialchars($d['torneo_nombre']) ?></td>
                    <td style="text-align: center;"><?= date('d/m/Y', strtotime($d['torneo_fecha'])) ?></td>
                    <td style="text-align: center;"><?= $d['total_inscritos'] ?></td>
                    <td style="text-align: right; font-weight: bold;">$<?= number_format((float)$d['monto_total'], 2) ?></td>
                    <td style="text-align: right; color: green;">$<?= number_format((float)$d['abono'], 2) ?></td>
                    <td style="text-align: right; font-weight: bold; color: <?= $pendiente > 0 ? 'red' : 'green' ?>;">
                        $<?= number_format((float)$pendiente, 2) ?>
                    </td>
                    <td style="text-align: center;"><?= number_format((float)$porcentaje, 1) ?>%</td>
                </tr>
            <?php 
            $num++;
            endforeach; 
            ?>
            
            <tr style="background-color: #28a745; color: white; font-weight: bold; font-size: 11pt;">
                <td colspan="5" style="text-align: right; padding: 8px;">TOTALES:</td>
                <td style="text-align: center;"><?= $totales['inscritos'] ?></td>
                <td style="text-align: right;">$<?= number_format((float)$totales['deuda'], 2) ?></td>
                <td style="text-align: right;">$<?= number_format((float)$totales['pagado'], 2) ?></td>
                <td style="text-align: right;">$<?= number_format((float)$totales['pendiente'], 2) ?></td>
                <td style="text-align: center;">
                    <?= $totales['deuda'] > 0 ? number_format((float)(($totales['pagado'] / $totales['deuda']) * 100), 1) : 0 ?>%
                </td>
            </tr>
        </tbody>
    </table>
    
    <table border="0" style="margin-top: 20px; width: 100%;">
        <tr>
            <td style="text-align: center; color: #666; font-size: 9pt;">
                Serviclubes LED - Sistema de Gestión de Torneos
            </td>
        </tr>
    </table>
</body>
</html>

