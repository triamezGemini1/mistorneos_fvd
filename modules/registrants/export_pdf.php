<?php
/**
 * Exportar Inscritos a PDF
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
    $titulo_clubs = 'Todos los Clubs';
    
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
    
    if (!empty($club_ids) && is_array($club_ids)) {
        if (count($club_ids) == 1) {
            $stmt = DB::pdo()->prepare("SELECT nombre FROM clubes WHERE id = ?");
            $stmt->execute([(int)$club_ids[0]]);
            $club = $stmt->fetchColumn();
            if ($club) {
                $titulo_clubs = $club;
            }
        } else {
            $titulo_clubs = count($club_ids) . ' clubs seleccionados';
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
    
    // Agrupar por club
    $por_club = [];
    foreach ($inscripciones AS $r) {
        $club_nombre = $r['club'] ?? 'Sin Club';
        $club_id = $r['club_id'];
        $es_responsable = ($club_id == $r['club_responsable']) ? 1 : 0;
        
        if (!isset($por_club[$club_nombre])) {
            $por_club[$club_nombre] = [
                'es_responsable' => $es_responsable,
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
    if ($sexo === 'M' || $sexo == 1) return '1';
    if ($sexo === 'F' || $sexo == 2) return '2';
    return '0';
}

function getCategoriaNum($categ) {
    return (int)$categ;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inscritos</title>
    <style>
        @page {
            margin: 1.5cm;
            size: letter landscape;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.3;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .header h1 {
            font-size: 18pt;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 10pt;
            margin: 3px 0;
        }
        .leyenda {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 8px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-size: 8pt;
            text-align: center;
        }
        .club-section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .club-header {
            background: #667eea;
            color: white;
            padding: 8px;
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 5px;
            border-radius: 5px;
        }
        .club-header.responsable {
            background: #28a745;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 8pt;
        }
        th {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 6px 4px;
            text-align: center;
            font-weight: bold;
        }
        td {
            border: 1px solid #dee2e6;
            padding: 4px 4px;
        }
        tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        .total-row {
            background: #e7f3ff !important;
            font-weight: bold;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8pt;
            color: #666;
            padding-top: 10px;
            border-top: 1px solid #ccc;
        }
        @media print {
            body { margin: 0; }
            .footer { position: fixed; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>?? REPORTE DE INSCRITOS</h1>
        <p><strong>Torneo:</strong> <?= htmlspecialchars($titulo_torneo) ?></p>
        <p><strong>Clubs:</strong> <?= htmlspecialchars($titulo_clubs) ?></p>
        <p><strong>Generado:</strong> <?= date('d/m/Y H:i') ?></p>
    </div>
    
    <div class="leyenda">
        <strong>LEYENDA:</strong> 
        SEXO (1 = Masculino, 2 = Femenino) | 
        CATEGOR�A (1 = Junior, 2 = Libre, 3 = Master) | 
        ESTADO (? = Activo, ? = Inactivo)
    </div>
    
    <?php 
    $total_general = 0;
    
    foreach ($por_club as $club_nombre => $club_data): 
        $inscritos = $club_data['registrants'];
        $es_responsable = $club_data['es_responsable'];
        $total_club = count($inscritos);
        $total_general += $total_club;
    ?>
        <div class="club-section">
            <div class="club-header <?= $es_responsable ? 'responsable' : '' ?>">
                <?= strtoupper(htmlspecialchars($club_nombre)) ?> 
                <?= $es_responsable ? '(CLUB RESPONSABLE)' : '' ?> 
                - <?= $total_club ?> INSCRITO(S)
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 8%;">IDENTIFICADOR</th>
                        <th style="width: 12%;">C�DULA</th>
                        <th style="width: 30%;">NOMBRE COMPLETO</th>
                        <th style="width: 8%;">SEXO</th>
                        <th style="width: 10%;">CATEGOR�A</th>
                        <th style="width: 15%;">CELULAR</th>
                        <th style="width: 10%;">ESTADO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inscritos as $r): ?>
                        <tr>
                            <td style="text-align: center; font-weight: bold;"><?= $r['identificador'] ?? 0 ?></td>
                            <td><?= htmlspecialchars($r['cedula']) ?></td>
                            <td><?= htmlspecialchars($r['nombre']) ?></td>
                            <td style="text-align: center;"><?= getSexoNum($r['sexo']) ?></td>
                            <td style="text-align: center;"><?= getCategoriaNum($r['categ']) ?></td>
                            <td><?= htmlspecialchars($r['celular'] ?? '') ?></td>
                            <td style="text-align: center;"><?= $r['estatus'] ? '?' : '?' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="7" style="text-align: right; padding-right: 10px;">
                            <strong>TOTAL DEL CLUB: <?= $total_club ?> INSCRITO(S)</strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    
    <?php if (count($por_club) > 1): ?>
        <div class="club-section">
            <div class="club-header" style="background: #28a745; font-size: 14pt; padding: 12px;">
                TOTAL GENERAL: <?= $total_general ?> INSCRITO(S)
            </div>
        </div>
    <?php endif; ?>
    
    <div class="footer">
        Serviclubes LED | Documento generado el <?= date('d/m/Y H:i:s') ?>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
