<?php
/**
 * Exportar Jugadores a Excel - Formato Espec�fico
 * Columnas: id_club, id_torneo, indicador=1, cedula, nombre, identificador, sexo, telefono, categ
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
    
    // Consultar datos con el formato específico
    $stmt = DB::pdo()->prepare("
        SELECT 
            r.club_id as id_club,
            r.torneo_id as id_torneo,
            1 as indicador,
            r.cedula,
            r.nombre,
            r.identificador,
            r.sexo,
            r.celular as telefono,
            r.categ
        FROM inscripciones r
        $where_clause
        ORDER BY 
            r.torneo_id ASC,
            r.club_id ASC,
            r.identificador ASC,
            r.nombre ASC
    ");
    $stmt->execute($params);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($jugadores)) {
        die('No hay datos para exportar');
    }
    
    // Generar nombre del archivo
    $filename = 'jugadores_' . date('Y-m-d_His') . '.xls';
    
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
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Exportación de Jugadores</title>
</head>
<body>
    <table border="1" cellpadding="5" cellspacing="0">
        <thead>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="9" style="text-align: center; font-size: 16pt; padding: 15px;">
                    EXPORTACI�N DE JUGADORES
                </td>
            </tr>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="9" style="text-align: center; padding: 8px;">
                    Generado: <?= date('d/m/Y H:i:s') ?>
                </td>
            </tr>
            <tr style="height: 10px;"><td colspan="9"></td></tr>
            <tr style="background-color: #4a5568; color: white; font-weight: bold; font-size: 11pt;">
                <td style="text-align: center; padding: 10px;">ID_CLUB</td>
                <td style="text-align: center; padding: 10px;">ID_TORNEO</td>
                <td style="text-align: center; padding: 10px;">INDICADOR</td>
                <td style="text-align: center; padding: 10px;">CEDULA</td>
                <td style="text-align: center; padding: 10px;">NOMBRE</td>
                <td style="text-align: center; padding: 10px;">IDENTIFICADOR</td>
                <td style="text-align: center; padding: 10px;">SEXO</td>
                <td style="text-align: center; padding: 10px;">TELEFONO</td>
                <td style="text-align: center; padding: 10px;">CATEG</td>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_registros = 0;
            foreach ($jugadores as $idx => $j): 
                $total_registros++;
                $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8f9fa';
            ?>
                <tr style="background-color: <?= $bg_color ?>;">
                    <td style="text-align: center; padding: 5px;"><?= (int)$j['id_club'] ?></td>
                    <td style="text-align: center; padding: 5px;"><?= (int)$j['id_torneo'] ?></td>
                    <td style="text-align: center; padding: 5px;"><?= (int)$j['indicador'] ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($j['cedula']) ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($j['nombre']) ?></td>
                    <td style="text-align: center; padding: 5px;"><?= (int)($j['identificador'] ?? 0) ?></td>
                    <td style="text-align: center; padding: 5px;"><?= formatSexo($j['sexo']) ?></td>
                    <td style="padding: 5px;"><?= htmlspecialchars($j['telefono'] ?? '') ?></td>
                    <td style="text-align: center; padding: 5px;"><?= (int)($j['categ'] ?? 0) ?></td>
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
                Formato: ID_CLUB | ID_TORNEO | INDICADOR=1 | CEDULA | NOMBRE | IDENTIFICADOR | SEXO | TELEFONO | CATEG
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


