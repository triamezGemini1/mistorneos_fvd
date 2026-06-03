<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Reporte de Clubes en PDF - Versión P�blica
 * Accesible directamente desde: public/report_clubs.php
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
    $search = $_GET['q'] ?? '';
    
    // Construir query
    $where = ["1=1"];
    $params = [];
    
    if ($status_filter !== null) {
        $where[] = "estatus = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where[] = "(nombre LIKE ? OR delegado LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Obtener clubes
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nombre,
            direccion,
            delegado,
            telefono,
            logo,
            estatus,
            indica,
            created_at
        FROM clubes
        WHERE {$where_clause}
        ORDER BY nombre ASC
    ");
    $stmt->execute($params);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estatus = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN estatus = 0 THEN 1 ELSE 0 END) as inactivos
        FROM clubes
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Crear generador de reporte
    $report = new ReportGenerator('Reporte de Clubes Registrados', 'portrait');
    
    // Construir contenido HTML
    $content = '';
    
    // Encabezado del reporte
    $subtitle = 'Lista completa de clubes registrados en el sistema';
    if ($search) {
        $subtitle .= ' - Búsqueda: "' . htmlspecialchars($search) . '"';
    }
    if ($status_filter !== null) {
        $subtitle .= ' - Estado: ' . ($status_filter ? 'Activos' : 'Inactivos');
    }
    
    $content .= $report->addReportHeader($subtitle);
    
    // Estadísticas
    $content .= $report->generateStatsBoxes([
        ['number' => $stats['total'], 'label' => 'Total Clubes'],
        ['number' => $stats['activos'], 'label' => 'Activos'],
        ['number' => $stats['inactivos'], 'label' => 'Inactivos'],
        ['number' => count($clubs), 'label' => 'En este reporte']
    ]);
    
    // Tabla de clubes
    $content .= '<h2>Listado de Clubes</h2>';
    
    if (empty($clubs)) {
        $content .= '<p style="text-align: center; color: #999; padding: 20px;">No se encontraron clubes con los filtros aplicados</p>';
    } else {
        $headers = ['#', 'Club', 'Delegado', 'Teléfono', 'Dirección', 'Estado'];
        $rows = [];
        
        foreach ($clubs as $index => $club) {
            $status_badge = $club['estatus'] 
                ? ReportGenerator::badge('Activo', 'success')
                : ReportGenerator::badge('Inactivo', 'danger');
            
            $rows[] = [
                $index + 1,
                htmlspecialchars($club['nombre']),
                htmlspecialchars($club['delegado'] ?? 'N/A'),
                htmlspecialchars($club['telefono'] ?? 'N/A'),
                htmlspecialchars($club['direccion'] ?? 'N/A'),
                $status_badge
            ];
        }
        
        $content .= $report->generateTable($headers, $rows);
    }
    
    // Detalle individual de cada club
    if (!empty($clubs)) {
        $content .= '<div class="page-break"></div>';
        $content .= '<h2>Detalle de Clubes</h2>';
        
        foreach ($clubs as $club) {
            $info_data = [
                'Nombre' => htmlspecialchars($club['nombre']),
                'Delegado' => htmlspecialchars($club['delegado'] ?? 'No especificado'),
                'Teléfono' => htmlspecialchars($club['telefono'] ?? 'No especificado'),
                'Dirección' => htmlspecialchars($club['direccion'] ?? 'No especificado'),
                'Estado' => $club['estatus'] 
                    ? ReportGenerator::badge('Activo', 'success')
                    : ReportGenerator::badge('Inactivo', 'danger'),
                'Indicador' => $club['indica'] ?? '0',
                'Fecha de Registro' => ReportGenerator::formatDateTime($club['created_at'])
            ];
            
            // Estadísticas del club
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT t.id) as torneos_organizados,
                    COUNT(DISTINCT r.id) as jugadores_inscritos,
                    COUNT(DISTINCT i.id) as invitaciones_recibidas
                FROM clubes c
                LEFT JOIN tournaments t ON c.id = t.club_responsable
                LEFT JOIN inscripciones r ON c.id = r.club_id
                LEFT JOIN invitations i ON c.id = i.club_id
                WHERE c.id = ?
            ");
            $stmt->execute([$club['id']]);
            $club_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($club_stats['torneos_organizados'] > 0 || $club_stats['jugadores_inscritos'] > 0 || $club_stats['invitaciones_recibidas'] > 0) {
                $info_data['Torneos Organizados'] = $club_stats['torneos_organizados'];
                $info_data['Jugadores Inscritos'] = $club_stats['jugadores_inscritos'];
                $info_data['Invitaciones Recibidas'] = $club_stats['invitaciones_recibidas'];
            }
            
            $content .= $report->generateInfoSection($club['nombre'], $info_data);
        }
    }
    
    // Establecer contenido y generar PDF
    $report->setContent($content);
    
    // Nombre del archivo
    $filename = 'reporte_clubes_' . date('Y-m-d_His') . '.pdf';
    
    // Generar y descargar
    $report->generate($filename, true);
    
} catch (PDOException $e) {
    die("? Error de base de datos: " . $e->getMessage());
} catch (Exception $e) {
    die("? Error: " . $e->getMessage());
}

