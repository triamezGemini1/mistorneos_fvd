<?php
/**
 * Exportar Inscritos a Excel - Formato Completo
 * Columnas: ASOCIACION, TORNEO, CLUB, Cedula, NOMBRE, IDENTIFICADOR, sexo, TELEFONO, CATEG
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

// Verificar autenticación
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

try {
    // Obtener filtros
    $torneo_id = !empty($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : null;
    $club_ids = !empty($_GET['club_ids']) ? $_GET['club_ids'] : [];
    
    // Construir query con filtros
    $where = [];
    $params = [];
    
    if ($torneo_id) {
        $where[] = "r.torneo_id = ?";
        $params[] = $torneo_id;
    }
    
    if (!empty($club_ids) && is_array($club_ids)) {
        $placeholders = str_repeat('?,', count($club_ids) - 1) . '?';
        $where[] = "r.club_id IN ($placeholders)";
        foreach ($club_ids as $club_id) {
            $params[] = (int)$club_id;
        }
    }
    
    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Consultar datos con todas las relaciones
    $stmt = DB::pdo()->prepare("
        SELECT 
            'SERVICLUBES LED' as asociacion,
            t.nombre as torneo,
            c.nombre as club,
            r.cedula,
            r.nombre,
            r.identificador,
            r.sexo,
            r.celular as telefono,
            r.categ
        FROM inscripciones r
        LEFT JOIN tournaments t ON r.torneo_id = t.id
        LEFT JOIN clubes c ON r.club_id = c.id
        $where_clause
        ORDER BY 
            t.nombre ASC,
            c.nombre ASC,
            r.identificador ASC,
            r.nombre ASC
    ");
    $stmt->execute($params);
    $registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($registrants)) {
        die('No hay datos para exportar');
    }
    
    // Generar nombre del archivo
    $filename = 'inscritos_completo_' . date('Y-m-d_His') . '.xls';
    
    // Headers para descarga como Excel
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
} catch (Exception $e) {
    die('Error: ' . htmlspecialchars($e->getMessage()));
}

// Función helper para convertir sexo
function formatSexo($sexo) {
    if ($sexo === 'M' || $sexo == 1) return 'M';
    if ($sexo === 'F' || $sexo == 2) return 'F';
    return '';
}

// Función helper para convertir categoría
function formatCategoria($categ) {
    switch ($categ) {
        case 1: return 'JUNIOR';
        case 2: return 'LIBRE';
        case 3: return 'MASTER';
        default: return '';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte Completo de Inscritos</title>
</head>
<body>
    <table border="1" cellpadding="5" cellspacing="0">
        <thead>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="9" style="text-align: center; font-size: 16pt; padding: 15px;">
                    REPORTE COMPLETO DE INSCRITOS
                </td>
            </tr>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="9" style="text-align: center; padding: 8px;">
                    Generado: <?= date('d/m/Y H:i:s') ?>
                </td>
            </tr>
            <tr style="height: 10px;"><td colspan="9"></td></tr>
            <tr style="background-color: #4a5568; color: white; font-weight: bold; font-size: 11pt;">
                <td style="text-align: center; padding: 10px;">ASOCIACION</td>
                <td style="text-align: center; padding: 10px;">TORNEO</td>
                <td style="text-align: center; padding: 10px;">CLUB</td>
                <td style="text-align: center; padding: 10px;">CEDULA</td>
                <td style="text-align: center; padding: 10px;">NOMBRE</td>
                <td style="text-align: center; padding: 10px;">IDENTIFICADOR</td>
                <td style="text-align: center; padding: 10px;">SEXO</td>
                <td style="text-align: center; padding: 10px;">TELEFONO</td>
                <td style="text-align: center; padding: 10px;">CATEGORIA</td>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_registros = 0;
            foreach ($inscripciones AS $idx => $r): 
                $total_registros++;
                $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8f9fa';
            ?>
                <tr style="background-color: <?= $bg_color ?>;">
                    <td style="padding: 5px;"><?= htmlspecialchars($r['asociacion']) ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($r['torneo'] ?? '') ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($r['club'] ?? '') ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($r['cedula']) ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($r['nombre']) ?></td>
                    <td style="text-align: center; padding: 5px;"><?= $r['identificador'] ?? 0 ?></td>
                    <td style="text-align: center; padding: 5px;"><?= formatSexo($r['sexo']) ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($r['telefono'] ?? '') ?></td>
                    <td style="text-align: center; padding: 5px;"><?= formatCategoria($r['categ']) ?></td>
                </tr>
            <?php endforeach; ?>
            
            <!-- Total -->
            <tr style="background-color: #28a745; color: white; font-weight: bold; font-size: 12pt;">
                <td colspan="9" style="text-align: center; padding: 12px;">
                    TOTAL DE REGISTROS: <?= $total_registros ?>
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
        <tr>
            <td style="text-align: center; color: #666; font-size: 8pt;">
                Exportación generada el <?= date('d/m/Y H:i:s') ?>
            </td>
        </tr>
    </table>
</body>
</html>


