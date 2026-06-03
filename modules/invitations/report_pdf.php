<?php
/**
 * Reporte de Invitaciones en PDF
 * Genera un reporte completo de las invitaciones enviadas
 */



require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/report_generator.php';

Auth::requireRole(['admin_general', 'admin_torneo']);

try {
    $pdo = DB::pdo();
    
    // Obtener filtros
    $tournament_filter = isset($_GET['torneo']) && $_GET['torneo'] !== '' ? (int)$_GET['torneo'] : null;
    $status_filter = $_GET['estado'] ?? '';
    
    // Construir query
    $where = ["1=1"];
    $params = [];
    
    if ($tournament_filter) {
        $where[] = "i.torneo_id = ?";
        $params[] = $tournament_filter;
    }
    
    if ($status_filter && in_array($status_filter, ['activa', 'expirada', 'cancelada'])) {
        $where[] = "i.estado = ?";
        $params[] = $status_filter;
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Obtener invitaciones
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            c.nombre as club_nombre,
            c.delegado as club_delegado,
            c.telefono as club_telefono
        FROM invitations i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        INNER JOIN clubes c ON i.club_id = c.id
        WHERE {$where_clause}
        ORDER BY i.fecha_creacion DESC
    ");
    $stmt->execute($params);
    $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stats_where = $tournament_filter ? "WHERE torneo_id = {$tournament_filter}" : "";
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'activa' THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN estado = 'expirada' THEN 1 ELSE 0 END) as expiradas,
            SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) as canceladas
        FROM invitations
        {$stats_where}
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Crear generador de reporte
    $report = new ReportGenerator('Reporte de Invitaciones a Torneos', 'landscape');
    
    // Construir contenido HTML
    $content = '';
    
    // Encabezado del reporte
    $subtitle = 'Lista completa de invitaciones enviadas';
    
    // Obtener nombre del torneo si hay filtro
    if ($tournament_filter) {
        $stmt = $pdo->prepare("SELECT nombre, fechator FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_filter]);
        $tournament_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tournament_info) {
            $subtitle = 'Invitaciones para: ' . htmlspecialchars($tournament_info['nombre']);
            $subtitle .= ' - ' . ReportGenerator::formatDate($tournament_info['fechator']);
        }
    }
    
    if ($status_filter) {
        $subtitle .= ' - Estado: ' . ucfirst($status_filter);
    }
    
    $content .= $report->addReportHeader($subtitle);
    
    // Estadísticas
    $content .= $report->generateStatsBoxes([
        ['number' => $stats['total'], 'label' => 'Total Invitaciones'],
        ['number' => $stats['activas'], 'label' => 'Activas'],
        ['number' => $stats['expiradas'], 'label' => 'Expiradas'],
        ['number' => $stats['canceladas'], 'label' => 'Canceladas']
    ]);
    
    // Tabla de invitaciones
    $content .= '<h2>Listado de Invitaciones</h2>';
    
    if (empty($invitations)) {
        $content .= '<p style="text-align: center; color: #999; padding: 20px;">No se encontraron invitaciones con los filtros aplicados</p>';
    } else {
        $headers = ['#', 'Torneo', 'Club Invitado', 'Delegado', 'Periodo Acceso', 'Token', 'Estado'];
        $rows = [];
        
        foreach ($invitations as $index => $invitation) {
            $status_badge = '';
            switch ($invitation['estado']) {
                case 'activa':
                    $status_badge = ReportGenerator::badge('Activa', 'success');
                    break;
                case 'expirada':
                    $status_badge = ReportGenerator::badge('Expirada', 'warning');
                    break;
                case 'cancelada':
                    $status_badge = ReportGenerator::badge('Cancelada', 'danger');
                    break;
                default:
                    $status_badge = ReportGenerator::badge($invitation['estado'], 'info');
            }
            
            $periodo_acceso = ReportGenerator::formatDate($invitation['acceso1']) . ' al ' . 
                            ReportGenerator::formatDate($invitation['acceso2']);
            
            $rows[] = [
                $index + 1,
                htmlspecialchars($invitation['torneo_nombre']),
                htmlspecialchars($invitation['club_nombre']),
                htmlspecialchars($invitation['club_delegado'] ?? 'N/A'),
                $periodo_acceso,
                '<small>' . htmlspecialchars(substr($invitation['token'], 0, 16)) . '...</small>',
                $status_badge
            ];
        }
        
        $content .= $report->generateTable($headers, $rows);
    }
    
    // Detalle individual de invitaciones
    if (!empty($invitations)) {
        $content .= '<div class="page-break"></div>';
        $content .= '<h2>Detalle de Invitaciones</h2>';
        
        foreach ($invitations as $invitation) {
            // Información básica
            $info_data = [
                'Torneo' => htmlspecialchars($invitation['torneo_nombre']),
                'Fecha del Torneo' => ReportGenerator::formatDate($invitation['torneo_fecha']),
                'Club Invitado' => htmlspecialchars($invitation['club_nombre']),
                'Delegado' => htmlspecialchars($invitation['club_delegado'] ?? 'No especificado'),
                'Teléfono' => htmlspecialchars($invitation['club_telefono'] ?? 'No especificado'),
                'Periodo de Acceso' => ReportGenerator::formatDate($invitation['acceso1']) . ' al ' . 
                                      ReportGenerator::formatDate($invitation['acceso2']),
                'Token de Acceso' => '<small>' . htmlspecialchars($invitation['token']) . '</small>',
                'Usuario' => htmlspecialchars($invitation['usuario'] ?? 'N/A'),
                'Estado' => $invitation['estado'] === 'activa' 
                    ? ReportGenerator::badge('Activa', 'success')
                    : ($invitation['estado'] === 'expirada' 
                        ? ReportGenerator::badge('Expirada', 'warning')
                        : ReportGenerator::badge('Cancelada', 'danger')),
                'Fecha de Creación' => ReportGenerator::formatDateTime($invitation['fecha_creacion'])
            ];
            
            // Verificar si tiene jugadores inscritos
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM inscripciones
                WHERE torneo_id = ? AND club_id = ?
            ");
            $stmt->execute([$invitation['torneo_id'], $invitation['club_id']]);
            $inscritos = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $info_data['Jugadores Inscritos'] = $inscritos['total'];
            
            $content .= $report->generateInfoSection(
                'Invitación #' . $invitation['id'] . ': ' . $invitation['club_nombre'],
                $info_data
            );
            
            // Lista de jugadores inscritos si los hay
            if ($inscritos['total'] > 0) {
                $stmt = $pdo->prepare("
                    SELECT 
                        cedula,
                        nombre,
                        sexo,
                        fechnac
                    FROM inscripciones
                    WHERE torneo_id = ? AND club_id = ?
                    ORDER BY nombre
                ");
                $stmt->execute([$invitation['torneo_id'], $invitation['club_id']]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $content .= '<h3 style="margin-left: 20px;">Jugadores Inscritos</h3>';
                $content .= '<table style="width: 95%; margin: 10px auto;">';
                $content .= '<thead><tr>';
                $content .= '<th>Cédula</th><th>Nombre</th><th>Sexo</th><th>Fecha Nac.</th>';
                $content .= '</tr></thead><tbody>';
                
                foreach ($players as $player) {
                    $content .= '<tr>';
                    $content .= '<td>' . htmlspecialchars($player['nombre']) . '</td>';
                    $content .= '<td>' . htmlspecialchars($player['sexo']) . '</td>';
                    $content .= '</tr>';
                }
                
                $content .= '</tbody></table>';
            }
        }
    }
    
    // Resumen por torneo si no hay filtro
    if (!$tournament_filter && !empty($invitations)) {
        $content .= '<div class="page-break"></div>';
        $content .= '<h2>Resumen por Torneo</h2>';
        
        $stmt = $pdo->query("
            SELECT 
                t.nombre,
                t.fechator,
                COUNT(i.id) as total_invitaciones,
                SUM(CASE WHEN i.estado = 'activa' THEN 1 ELSE 0 END) as activas,
                SUM(CASE WHEN i.estado = 'expirada' THEN 1 ELSE 0 END) as expiradas
            FROM tournaments t
            LEFT JOIN invitations i ON t.id = i.torneo_id
            GROUP BY t.id, t.nombre, t.fechator
            HAVING total_invitaciones > 0
            ORDER BY t.fechator DESC
        ");
        $tournament_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tournament_summary as $t_summary) {
            $info_data = [
                'Torneo' => htmlspecialchars($t_summary['nombre']),
                'Fecha' => ReportGenerator::formatDate($t_summary['fechator']),
                'Total Invitaciones' => $t_summary['total_invitaciones'],
                'Activas' => $t_summary['activas'],
                'Expiradas' => $t_summary['expiradas']
            ];
            
            $content .= $report->generateInfoSection(
                $t_summary['nombre'],
                $info_data
            );
        }
    }
    
    // Establecer contenido y generar PDF
    $report->setContent($content);
    
    // Nombre del archivo
    $filename = 'reporte_invitaciones_' . date('Y-m-d_His') . '.pdf';
    
    // Generar y descargar
    $report->generate($filename, true);
    
} catch (PDOException $e) {
    die("? Error de base de datos: " . $e->getMessage());
} catch (Exception $e) {
    die("? Error: " . $e->getMessage());
}







