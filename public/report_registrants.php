<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Reporte de Inscritos en PDF
 * Patrón en bloque: conexión única → seguridad → validación inmediata.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
AuthService::requireAuth();
require_once __DIR__ . '/../lib/report_generator.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

try {
    $pdo = DB::pdo();
    
    // Obtener filtros
    $tournament_filter = isset($_GET['torneo_id']) && $_GET['torneo_id'] !== '' ? (int)$_GET['torneo_id'] : null;
    $club_filter = isset($_GET['club_id']) && $_GET['club_id'] !== '' ? (int)$_GET['club_id'] : null;
    $sexo_filter = $_GET['sexo'] ?? '';
    $search = $_GET['q'] ?? '';
    
    // Construir query
    $where = ["1=1"];
    $params = [];
    
    if ($tournament_filter) {
        $where[] = "r.torneo_id = ?";
        $params[] = $tournament_filter;
    }
    
    if ($club_filter) {
        $where[] = "r.club_id = ?";
        $params[] = $club_filter;
    }
    
    if ($sexo_filter && in_array($sexo_filter, ['M', 'F', 'O'])) {
        $where[] = "r.sexo = ?";
        $params[] = $sexo_filter;
    }
    
    if ($search) {
        $where[] = "(r.nombre LIKE ? OR r.cedula LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Obtener inscritos
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            c.nombre as club_nombre
        FROM inscripciones r
        INNER JOIN tournaments t ON r.torneo_id = t.id
        LEFT JOIN clubes c ON r.club_id = c.id
        WHERE {$where_clause}
        ORDER BY t.fechator DESC, c.nombre ASC, r.nombre ASC
    ");
    $stmt->execute($params);
    $registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stats_where = $tournament_filter ? "WHERE torneo_id = {$tournament_filter}" : "";
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sexo = 'M' THEN 1 ELSE 0 END) as masculino,
            SUM(CASE WHEN sexo = 'F' THEN 1 ELSE 0 END) as femenino,
            COUNT(DISTINCT club_id) as clubes
        FROM inscripciones
        {$stats_where}
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Crear generador de reporte
    $report = new ReportGenerator('Reporte de Jugadores Inscritos', 'landscape');
    
    // Construir contenido HTML
    $content = '';
    
    // Encabezado del reporte
    $subtitle = 'Lista completa de jugadores inscritos';
    
    // Obtener nombre del torneo si hay filtro
    if ($tournament_filter) {
        $stmt = $pdo->prepare("SELECT nombre, fechator FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_filter]);
        $tournament_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tournament_info) {
            $subtitle = 'Jugadores inscritos en: ' . htmlspecialchars($tournament_info['nombre']);
            $subtitle .= ' - ' . ReportGenerator::formatDate($tournament_info['fechator']);
        }
    }
    
    if ($search) {
        $subtitle .= ' - Búsqueda: "' . htmlspecialchars($search) . '"';
    }
    
    $content .= $report->addReportHeader($subtitle);
    
    // Estadísticas
    $content .= $report->generateStatsBoxes([
        ['number' => $stats['total'], 'label' => 'Total Inscritos'],
        ['number' => $stats['masculino'], 'label' => 'Masculino'],
        ['number' => $stats['femenino'], 'label' => 'Femenino'],
        ['number' => $stats['clubes'], 'label' => 'Clubes']
    ]);
    
    // Tabla de inscritos
    $content .= '<h2>Listado de Jugadores Inscritos</h2>';
    
    if (empty($registrants)) {
        $content .= '<p style="text-align: center; color: #999; padding: 20px;">No se encontraron jugadores inscritos con los filtros aplicados</p>';
    } else {
        $headers = ['#', 'Cédula', 'Nombre', 'Sexo', 'Fecha Nac.', 'Edad', 'Club', 'Torneo', 'Celular'];
        $rows = [];
        
        foreach ($inscripciones AS $index => $registrant) {
            // Calcular edad
            $edad = 'N/A';
            if ($registrant['fechnac']) {
                $fecha_nac = new DateTime($registrant['fechnac']);
                $hoy = new DateTime();
                $edad = $hoy->diff($fecha_nac)->y;
            }
            
            $sexo_badge = '';
            if ($registrant['sexo'] === 'M') {
                $sexo_badge = ReportGenerator::badge('M', 'info');
            } elseif ($registrant['sexo'] === 'F') {
                $sexo_badge = ReportGenerator::badge('F', 'warning');
            } else {
                $sexo_badge = ReportGenerator::badge('O', 'secondary');
            }
            
            $rows[] = [
                $index + 1,
                htmlspecialchars($registrant['nombre']),
                $sexo_badge,
                htmlspecialchars($registrant['club_nombre'] ?? 'N/A'),
                htmlspecialchars($registrant['torneo_nombre']),
                htmlspecialchars($registrant['celular'] ?? 'N/A')
            ];
        }
        
        $content .= $report->generateTable($headers, $rows);
    }
    
    // Agrupación por club si hay torneo seleccionado
    if ($tournament_filter && !empty($registrants)) {
        $content .= '<div class="page-break"></div>';
        $content .= '<h2>Inscritos Agrupados por Club</h2>';
        
        // Agrupar por club
        $stmt = $pdo->prepare("
            SELECT 
                c.nombre as club_nombre,
                COUNT(r.id) as total_jugadores,
                SUM(CASE WHEN r.sexo = 'M' THEN 1 ELSE 0 END) as masculinos,
                SUM(CASE WHEN r.sexo = 'F' THEN 1 ELSE 0 END) as femeninos
            FROM inscripciones r
            LEFT JOIN clubes c ON r.club_id = c.id
            WHERE r.torneo_id = ?
            GROUP BY r.club_id, c.nombre
            ORDER BY c.nombre
        ");
        $stmt->execute([$tournament_filter]);
        $clubs_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($clubs_summary as $club_sum) {
            $info_data = [
                'Club' => htmlspecialchars($club_sum['club_nombre'] ?? 'Sin club'),
                'Total Jugadores' => $club_sum['total_jugadores'],
                'Masculinos' => $club_sum['masculinos'],
                'Femeninos' => $club_sum['femeninos']
            ];
            
            $content .= $report->generateInfoSection(
                'Resumen: ' . ($club_sum['club_nombre'] ?? 'Sin club'),
                $info_data
            );
            
            // Lista de jugadores del club
            $stmt = $pdo->prepare("
                SELECT 
                    cedula,
                    nombre,
                    sexo,
                    fechnac,
                    celular
                FROM inscripciones
                WHERE torneo_id = ? AND " . ($club_sum['club_nombre'] ? "club_id = (SELECT id FROM clubes WHERE nombre = ? LIMIT 1)" : "club_id IS NULL") . "
                ORDER BY nombre
            ");
            
            $params_players = [$tournament_filter];
            if ($club_sum['club_nombre']) {
                $params_players[] = $club_sum['club_nombre'];
            }
            
            $stmt->execute($params_players);
            $club_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($club_players)) {
                $content .= '<table style="width: 95%; margin: 10px auto;">';
                $content .= '<thead><tr>';
                $content .= '<th>Cédula</th><th>Nombre</th><th>Sexo</th><th>Fecha Nac.</th><th>Celular</th>';
                $content .= '</tr></thead><tbody>';
                
                foreach ($club_players as $player) {
                    $content .= '<tr>';
                    $content .= '<td>' . htmlspecialchars($player['cedula']) . '</td>';
                    $content .= '<td>' . htmlspecialchars($player['nombre']) . '</td>';
                    $content .= '<td>' . htmlspecialchars($player['sexo']) . '</td>';
                    $content .= '<td>' . ReportGenerator::formatDate($player['fechnac']) . '</td>';
                    $content .= '<td>' . htmlspecialchars($player['celular'] ?? 'N/A') . '</td>';
                    $content .= '</tr>';
                }
                
                $content .= '</tbody></table>';
            }
        }
    }
    
    // Establecer contenido y generar PDF
    $report->setContent($content);
    
    // Nombre del archivo
    $filename = 'reporte_inscritos_' . date('Y-m-d_His') . '.pdf';
    
    // Generar y descargar
    $report->generate($filename, true);
    
} catch (PDOException $e) {
    die("? Error de base de datos: " . $e->getMessage());
} catch (Exception $e) {
    die("? Error: " . $e->getMessage());
}

