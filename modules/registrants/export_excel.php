<?php
/**
 * Exportar Inscritos a Excel (Formato HTML para Excel)
 * Versión corregida: sin fecha nacimiento, sin torneo, sexo 1/2, identificador de BD
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
    
    // Obtener información del filtro para el t�tulo
    $titulo_torneo = 'Todos los Torneos';
    
    $club_responsable_id = null;
    if ($torneo_id) {
        $stmt = DB::pdo()->prepare("SELECT nombre, club_responsable FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($torneo) {
            $titulo_torneo = $torneo['nombre'];
            $club_responsable_id = $torneo['club_responsable'];
        }
    }
    
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
    
    // Consultar datos - Ordenar: club responsable al final
    $stmt = DB::pdo()->prepare("
        SELECT 
            r.identificador,
            r.cedula,
            r.nombre,
            r.sexo,
            r.categ,
            r.celular,
            r.estatus,
            t.club_responsable,
            c.id as club_id,
            c.nombre as club
        FROM inscripciones r
        LEFT JOIN tournaments t ON r.torneo_id = t.id
        LEFT JOIN clubes c ON r.club_id = c.id
        $where_clause
        ORDER BY 
            CASE 
                WHEN c.id = t.club_responsable THEN 1
                ELSE 0
            END ASC,
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
    $filename = 'inscritos_' . date('Y-m-d_His') . '.xls';
    
    // Headers para descarga como Excel
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Agrupar por club
    $por_club = [];
    foreach ($inscripciones AS $r) {
        $club_nombre = $r['club'] ?? 'Sin Club';
        if (!isset($por_club[$club_nombre])) {
            $por_club[$club_nombre] = [
                'es_responsable' => ($r['club_id'] == $r['club_responsable']),
                'registrants' => []
            ];
        }
        $por_club[$club_nombre]['registrants'][] = $r;
    }
    
} catch (Exception $e) {
    die('Error: ' . htmlspecialchars($e->getMessage()));
}

// Función helper
function getSexoNum($sexo) {
    if ($sexo === 'M' || $sexo == 1) return 1;
    if ($sexo === 'F' || $sexo == 2) return 2;
    return 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inscritos</title>
</head>
<body>
    <table border="1" cellpadding="5" cellspacing="0">
        <thead>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="8" style="text-align: center; font-size: 14pt; padding: 10px;">
                    REPORTE DE INSCRITOS - <?= htmlspecialchars($titulo_torneo) ?>
                </td>
            </tr>
            <tr style="background-color: #667eea; color: white; font-weight: bold;">
                <td colspan="8" style="text-align: center; padding: 5px;">
                    Generado: <?= date('d/m/Y H:i') ?>
                </td>
            </tr>
            <tr style="height: 10px;"><td colspan="8"></td></tr>
            <tr style="background-color: #f8f9fa; font-weight: bold;">
                <td style="text-align: center;">IDENTIFICADOR</td>
                <td style="text-align: center;">C�DULA</td>
                <td style="text-align: center;">NOMBRE COMPLETO</td>
                <td style="text-align: center;">SEXO</td>
                <td style="text-align: center;">CATEGOR�A</td>
                <td style="text-align: center;">CELULAR</td>
                <td style="text-align: center;">ESTADO</td>
                <td style="text-align: center;">CLUB</td>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_general = 0;
            foreach ($por_club as $club_nombre => $club_data): 
                $inscritos = $club_data['registrants'];
                $es_responsable = $club_data['es_responsable'];
                $total_club = count($inscritos);
                $total_general += $total_club;
            ?>
                <!-- Encabezado del Club -->
                <tr style="background-color: <?= $es_responsable ? '#28a745' : '#667eea' ?>; color: white; font-weight: bold;">
                    <td colspan="8" style="padding: 8px;">
                        <?= strtoupper(htmlspecialchars($club_nombre)) ?> 
                        <?= $es_responsable ? '(CLUB RESPONSABLE)' : '' ?> 
                        - <?= $total_club ?> INSCRITO(S)
                    </td>
                </tr>
                
                <!-- Registros del Club -->
                <?php foreach ($inscritos as $r): ?>
                    <tr>
                        <td style="text-align: center;"><?= $r['identificador'] ?? 0 ?></td>
                        <td><?= htmlspecialchars($r['cedula']) ?></td>
                        <td><?= htmlspecialchars($r['nombre']) ?></td>
                        <td style="text-align: center;"><?= getSexoNum($r['sexo']) ?></td>
                        <td style="text-align: center;"><?= $r['categ'] ?? 0 ?></td>
                        <td><?= htmlspecialchars($r['celular'] ?? '') ?></td>
                        <td style="text-align: center;"><?= $r['estatus'] ? 'ACTIVO' : 'INACTIVO' ?></td>
                        <td><?= htmlspecialchars($club_nombre) ?></td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Subtotal del Club -->
                <tr style="background-color: #e7f3ff; font-weight: bold;">
                    <td colspan="8" style="text-align: right; padding: 5px;">
                        TOTAL DEL CLUB: <?= $total_club ?> INSCRITO(S)
                    </td>
                </tr>
                <tr style="height: 5px;"><td colspan="8"></td></tr>
            <?php endforeach; ?>
            
            <!-- Total General -->
            <?php if (count($por_club) > 1): ?>
                <tr style="background-color: #28a745; color: white; font-weight: bold; font-size: 12pt;">
                    <td colspan="8" style="text-align: center; padding: 10px;">
                        TOTAL GENERAL: <?= $total_general ?> INSCRITO(S)
                    </td>
                </tr>
            <?php endif; ?>
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
                Leyenda: SEXO (1=Masculino, 2=Femenino) | CATEGOR�A (1=Junior, 2=Libre, 3=Master)
            </td>
        </tr>
    </table>
</body>
</html>
