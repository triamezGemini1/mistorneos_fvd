<?php
/**
 * Reporte PDF de Jugadores Inscritos
 * Generado desde el portal de invitaciones
 */

session_start();

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/db.php';

try {
    $pdo = DB::pdo();
    
    $torneo_id = $_SESSION['torneo_id'];
    $club_id = $_SESSION['club_id'];
    
    // Obtener información del torneo
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener información del club
    $stmt = $pdo->prepare("SELECT * FROM clubes WHERE id = ?");
    $stmt->execute([$club_id]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener jugadores inscritos
    $stmt = $pdo->prepare("
        SELECT * FROM inscripciones 
        WHERE torneo_id = ? AND club_id = ? 
        ORDER BY nombre ASC
    ");
    $stmt->execute([$torneo_id, $club_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $total = count($inscritos);
    $hombres = count(array_filter($inscritos, function($r) { 
        return $r['sexo'] == 1 || strtoupper($r['sexo']) === 'M'; 
    }));
    $mujeres = count(array_filter($inscritos, function($r) { 
        return $r['sexo'] == 2 || strtoupper($r['sexo']) === 'F'; 
    }));
    
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Generar PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="jugadores_' . $club_id . '_' . $torneo_id . '.pdf"');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Jugadores</title>
    <style>
        @page { margin: 2cm; }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #333;
            font-size: 18pt;
        }
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info-box table {
            width: 100%;
        }
        .info-box td {
            padding: 3px 0;
        }
        .stats {
            display: flex;
            justify-content: space-around;
            margin: 20px 0;
            text-align: center;
        }
        .stat-box {
            background: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            min-width: 120px;
        }
        .stat-box h2 {
            margin: 0;
            color: #0066cc;
            font-size: 24pt;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background: #333;
            color: white;
            padding: 10px 5px;
            text-align: left;
            font-size: 9pt;
        }
        td {
            padding: 8px 5px;
            border-bottom: 1px solid #ddd;
            font-size: 9pt;
        }
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #666;
            padding: 10px 0;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>?? REPORTE DE JUGADORES INSCRITOS</h1>
    </div>
    
    <div class="info-box">
        <table>
            <tr>
                <td style="width: 20%;"><strong>Torneo:</strong></td>
                <td><?= htmlspecialchars($torneo['nombre']) ?></td>
                <td style="width: 20%;"><strong>Fecha:</strong></td>
                <td><?= date('d/m/Y', strtotime($torneo['fechator'])) ?></td>
            </tr>
            <tr>
                <td><strong>Club:</strong></td>
                <td colspan="3"><?= htmlspecialchars($club['nombre']) ?></td>
            </tr>
            <tr>
                <td><strong>Delegado:</strong></td>
                <td><?= htmlspecialchars($club['delegado'] ?? 'N/A') ?></td>
                <td><strong>Teléfono:</strong></td>
                <td><?= htmlspecialchars($club['telefono'] ?? 'N/A') ?></td>
            </tr>
        </table>
    </div>
    
    <div class="stats">
        <div class="stat-box">
            <h2><?= $total ?></h2>
            <p style="margin: 0;">Total Inscritos</p>
        </div>
        <div class="stat-box">
            <h2><?= $hombres ?></h2>
            <p style="margin: 0;">Hombres</p>
        </div>
        <div class="stat-box">
            <h2><?= $mujeres ?></h2>
            <p style="margin: 0;">Mujeres</p>
        </div>
    </div>
    
    <?php if (empty($inscritos)): ?>
        <p style="text-align: center; padding: 40px; color: #666;">
            No hay jugadores inscritos
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 15%;">Cédula</th>
                    <th style="width: 35%;">Nombre Completo</th>
                    <th style="width: 8%;">Sexo</th>
                    <th style="width: 15%;">Fecha Nac.</th>
                    <th style="width: 15%;">Celular</th>
                    <th style="width: 7%;">Cat.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inscritos as $index => $jugador): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($jugador['nombre']) ?></td>
                        <td style="text-align: center;">
                            <?= ($jugador['sexo'] == 1 || strtoupper($jugador['sexo']) === 'M') ? 'M' : 'F' ?>
                        </td>
                        <td><?= htmlspecialchars($jugador['celular'] ?? 'N/A') ?></td>
                        <td style="text-align: center;"><?= (int)$jugador['categ'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <div class="footer">
        Generado el <?= date('d/m/Y H:i:s') ?> | 
        Total de jugadores: <?= $total ?> (<?= $hombres ?> hombres, <?= $mujeres ?> mujeres) |
        Serviclubes LED
    </div>
    
    <script>
        // Auto-imprimir al cargar (genera PDF)
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>














