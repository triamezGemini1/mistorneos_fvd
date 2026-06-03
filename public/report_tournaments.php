<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Reporte de Torneos en PDF
 * Patrón en bloque: db_config → auth_service → requireAuth (antes de cualquier procesamiento).
 * No genera HTML; solo PDF. Sin header/footer.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
AuthService::requireAuth();
Auth::requireRole(['admin_general', 'admin_torneo']);
require_once __DIR__ . '/../lib/report_generator.php';

try {
    $pdo = DB::pdo();
    
    // Obtener filtros
    $status_filter = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : null;
    $club_filter = isset($_GET['club_id']) && $_GET['club_id'] !== '' ? (int)$_GET['club_id'] : null;
    $search = $_GET['q'] ?? '';
    
    // Construir query
    $where = ["1=1"];
    $params = [];
    
    if ($status_filter !== null) {
        $where[] = "t.estatus = ?";
        $params[] = $status_filter;
    }
    
    if ($club_filter) {
        $where[] = "t.club_responsable = ?";
        $params[] = $club_filter;
    }
    
    if ($search) {
        $where[] = "(t.nombre LIKE ?)";
        $params[] = "%{$search}%";
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Obtener torneos
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            c.nombre as club_nombre,
            c.delegado as club_delegado
        FROM tournaments t
        LEFT JOIN clubes c ON t.club_responsable = c.id
        WHERE {$where_clause}
        ORDER BY t.fechator DESC
    ");
    $stmt->execute($params);
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estatus = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN estatus = 0 THEN 1 ELSE 0 END) as inactivos,
            SUM(CASE WHEN fechator >= CURDATE() THEN 1 ELSE 0 END) as proximos,
            SUM(CASE WHEN fechator < CURDATE() THEN 1 ELSE 0 END) as pasados
        FROM tournaments
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Crear generador de reporte
    $report = new ReportGenerator('Reporte de Torneos', 'landscape');
    
    // Construir contenido HTML
    $content = '';
    
    // Encabezado del reporte
    $subtitle = 'Lista completa de torneos registrados en el sistema';
    if ($search) {
        $subtitle .= ' - Búsqueda: "' . htmlspecialchars($search) . '"';
    }
    if ($status_filter !== null) {
        $subtitle .= ' - Estado: ' . ($status_filter ? 'Activos' : 'Inactivos');
    }
    
    $content .= $report->addReportHeader($subtitle);
    
    // Estadísticas
    $content .= $report->generateStatsBoxes([
        ['number' => $stats['total'], 'label' => 'Total Torneos'],
        ['number' => $stats['activos'], 'label' => 'Activos'],
        ['number' => $stats['proximos'], 'label' => 'Próximos'],
        ['number' => $stats['pasados'], 'label' => 'Realizados']
    ]);
    
    // Tabla de torneos
    $content .= '<h2>Listado de Torneos</h2>';
    
    if (empty($tournaments)) {
        $content .= '<p style="text-align: center; color: #999; padding: 20px;">No se encontraron torneos con los filtros aplicados</p>';
    } else {
        $headers = ['#', 'Torneo', 'Fecha', 'Lugar', 'Club Organizador', 'Clase', 'Modalidad', 'Estado'];
        $rows = [];
        
        // Mapeos para valores
        $clase_map = [0 => 'N/A', 1 => 'Torneo', 2 => 'Campeonato'];
        $modalidad_map = [0 => 'N/A', 1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
        
        foreach ($tournaments as $index => $tournament) {
            $status_badge = $tournament['estatus'] 
                ? ReportGenerator::badge('Activo', 'success')
                : ReportGenerator::badge('Inactivo', 'danger');
            
            $clase = $clase_map[$tournament['clase']] ?? 'N/A';
            $modalidad = $modalidad_map[$tournament['modalidad']] ?? 'N/A';
            
            $rows[] = [
                $index + 1,
                htmlspecialchars($tournament['nombre']),
                ReportGenerator::formatDate($tournament['fechator']),
                htmlspecialchars(!empty($tournament['lugar']) ? $tournament['lugar'] : 'N/A'),
                htmlspecialchars($tournament['club_nombre'] ?? 'N/A'),
                $clase,
                $modalidad,
                $status_badge
            ];
        }
        
        $content .= $report->generateTable($headers, $rows);
    }
    
    // Detalle individual de cada torneo
    if (!empty($tournaments)) {
        $content .= '<div class="page-break"></div>';
        $content .= '<h2>Detalle de Torneos</h2>';
        
        foreach ($tournaments as $tournament) {
            // Información básica
            $info_data = [
                'Nombre' => htmlspecialchars($tournament['nombre']),
                'Fecha' => ReportGenerator::formatDate($tournament['fechator']),
                'Lugar' => htmlspecialchars(!empty($tournament['lugar']) ? $tournament['lugar'] : 'No especificado'),
                'Club Responsable' => htmlspecialchars($tournament['club_nombre'] ?? 'No especificado'),
                'Delegado' => htmlspecialchars($tournament['club_delegado'] ?? 'No especificado'),
                'Clase' => $clase_map[$tournament['clase']] ?? 'N/A',
                'Modalidad' => $modalidad_map[$tournament['modalidad']] ?? 'N/A',
                'Tiempo' => $tournament['tiempo'] ? $tournament['tiempo'] . ' minutos' : 'N/A',
                'Puntos' => $tournament['puntos'] ?? 'N/A',
                'Rondas' => $tournament['rondas'] ?? 'N/A',
                'Costo' => $tournament['costo'] ? ReportGenerator::formatCurrency((float)$tournament['costo']) : 'Gratuito',
                'Ranking' => $tournament['ranking'] ?? 'N/A',
                'Pareclub' => $tournament['pareclub'] ?? 'N/A',
                'Estado' => $tournament['estatus'] 
                    ? ReportGenerator::badge('Activo', 'success')
                    : ReportGenerator::badge('Inactivo', 'danger')
            ];
            
            // Estadísticas del torneo
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT i.id) as invitaciones_enviadas,
                    COUNT(DISTINCT r.id) as jugadores_inscritos,
                    COUNT(DISTINCT r.club_id) as clubes_participantes
                FROM tournaments t
                LEFT JOIN invitations i ON t.id = i.torneo_id
                LEFT JOIN inscripciones r ON t.id = r.torneo_id
                WHERE t.id = ?
            ");
            $stmt->execute([$tournament['id']]);
            $tournament_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $info_data['Invitaciones Enviadas'] = $tournament_stats['invitaciones_enviadas'];
            $info_data['Jugadores Inscritos'] = $tournament_stats['jugadores_inscritos'];
            $info_data['Clubes Participantes'] = $tournament_stats['clubes_participantes'];
            
            $content .= $report->generateInfoSection($tournament['nombre'], $info_data);
            
            // Lista de clubes participantes
            if ($tournament_stats['clubes_participantes'] > 0) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT
                        c.nombre,
                        COUNT(r.id) as num_jugadores
                    FROM inscripciones r
                    INNER JOIN clubes c ON r.club_id = c.id
                    WHERE r.torneo_id = ?
                    GROUP BY c.id, c.nombre
                    ORDER BY c.nombre
                ");
                $stmt->execute([$tournament['id']]);
                $participating_clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($participating_clubs)) {
                    $content .= '<h3 style="margin-left: 20px;">Clubes Participantes</h3>';
                    $content .= '<ul style="margin-left: 40px;">';
                    foreach ($participating_clubs as $club) {
                        $content .= '<li>' . htmlspecialchars($club['nombre']) . ' (' . $club['num_jugadores'] . ' jugadores)</li>';
                    }
                    $content .= '</ul>';
                }
            }
        }
    }
    
    // Establecer contenido y generar PDF
    $report->setContent($content);
    
    // Nombre del archivo
    $filename = 'reporte_torneos_' . date('Y-m-d_His') . '.pdf';
    
    // Generar y descargar
    $report->generate($filename, true);
    
} catch (PDOException $e) {
    die("? Error de base de datos: " . $e->getMessage());
} catch (Exception $e) {
    die("? Error: " . $e->getMessage());
}

