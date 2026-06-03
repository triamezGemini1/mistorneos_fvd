<?php
/**
 * Generador de PDFs de Invitación
 * Genera PDFs para invitaciones a clubes y torneos
 */

// Solo cargar db.php si no está definida la clase DB
if (!class_exists('DB')) {
    require_once __DIR__ . '/../config/db.php';
}

class InvitationPDFGenerator {
    
    private static $pdf_dir = __DIR__ . '/../upload/pdfs/';
    
    /**
     * Genera PDF de invitación a afiliarse a un club
     */
    public static function generateClubInvitationPDF(int $club_id): array {
        try {
            // Obtener datos del club
            $club_data = self::getClubData($club_id);
            if (!$club_data) {
                return ['success' => false, 'error' => 'Club no encontrado'];
            }
            
            // Obtener información del administrador
            $admin_data = self::getClubAdminData($club_id);
            
            // Generar contenido HTML
            $html_content = self::generateClubInvitationHTML($club_data, $admin_data);
            
            // Generar PDF
            $pdf_path = self::createPDF($html_content, "club_invitation_{$club_id}_" . time());
            
            // Guardar ruta del PDF en la base de datos
            self::saveClubPDFPath($club_id, $pdf_path);
            
            return [
                'success' => true,
                'pdf_path' => $pdf_path,
                'message' => 'PDF de invitación al club generado correctamente'
            ];
            
        } catch (Exception $e) {
            error_log("Error generando PDF de invitación al club: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error generando PDF: ' . $e->getMessage()];
        }
    }
    
    /**
     * Genera PDF de invitación a participar en un torneo
     */
    public static function generateTournamentInvitationPDF(int $tournament_id): array {
        try {
            // Obtener datos del torneo
            $tournament_data = self::getTournamentData($tournament_id);
            if (!$tournament_data) {
                return ['success' => false, 'error' => 'Torneo no encontrado'];
            }
            
            // club_responsable ahora contiene el ID de la ORGANIZACIÓN
            $club_data = null;
            $admin_data = null;
            
            if (!empty($tournament_data['club_responsable'])) {
                // Intentar obtener como organización primero
                $club_data = self::getOrganizacionData((int)$tournament_data['club_responsable']);
                $admin_data = self::getOrganizacionAdminData((int)$tournament_data['club_responsable']);
                
                // Si no se encontró como organización, intentar como club (compatibilidad con datos legacy)
                if (!$club_data) {
                    $club_data = self::getClubData((int)$tournament_data['club_responsable']);
                    $admin_data = self::getTournamentAdminData((int)$tournament_data['club_responsable']);
                }
            }
            
            // Fallback: si hay cod_org en el torneo, usarlo
            if (!$club_data && !empty($tournament_data['cod_org'])) {
                $club_data = self::getOrganizacionData((int)$tournament_data['cod_org']);
                $admin_data = self::getOrganizacionAdminData((int)$tournament_data['cod_org']);
            }
            
            // Generar link de invitación
            $app_url = $_ENV['APP_URL'] ?? (function_exists('app_base_url') ? app_base_url() : FvdConfig::resolveAppUrl());
            $invitation_link = AppHelpers::url("tournament_register.php?torneo_id=") . $tournament_id;
            
            // Generar contenido HTML
            $html_content = self::generateTournamentInvitationHTML($tournament_data, $club_data, $admin_data, $invitation_link);
            
            // Generar PDF
            $pdf_path = self::createPDF($html_content, "tournament_invitation_{$tournament_id}_" . time());
            
            // Guardar ruta del PDF en la base de datos
            self::saveTournamentPDFPath($tournament_id, $pdf_path);
            
            return [
                'success' => true,
                'pdf_path' => $pdf_path,
                'message' => 'PDF de invitación al torneo generado correctamente'
            ];
            
        } catch (Exception $e) {
            error_log("Error generando PDF de invitación al torneo: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error generando PDF: ' . $e->getMessage()];
        }
    }
    
    /**
     * Obtiene datos del club
     */
    private static function getClubData(int $club_id): ?array {
        $stmt = DB::pdo()->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM usuarios WHERE club_id = c.id AND role = 'usuario' AND status = 0) as total_afiliados,
                   (SELECT COUNT(*) FROM tournaments WHERE club_responsable = c.id AND estatus = 1) as total_torneos
            FROM clubes c
            WHERE c.id = ? AND c.estatus = 1
        ");
        $stmt->execute([$club_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Obtiene datos del administrador del club
     */
    private static function getClubAdminData(int $club_id): ?array {
        $stmt = DB::pdo()->prepare("
            SELECT u.nombre, u.username, u.email, u.celular, u.cedula
            FROM usuarios u
            WHERE u.club_id = ? AND u.role = 'admin_club' AND u.status = 0
            LIMIT 1
        ");
        $stmt->execute([$club_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Obtiene datos de la organización (cuando no hay club asignado)
     */
    private static function getOrganizacionData(int $organizacion_id): ?array {
        $stmt = DB::pdo()->prepare("
            SELECT o.id, o.nombre, o.direccion, o.responsable as delegado, o.telefono, o.email,
                   (SELECT COUNT(*) FROM clubes WHERE cod_org = COALESCE(NULLIF(o.cod_org, 0), NULLIF(o.entidad, 0)) AND estatus = 1) as total_clubes
            FROM organizaciones o
            WHERE o.id = ? AND o.estatus = 1
        ");
        $stmt->execute([$organizacion_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Obtiene datos del administrador de la organización
     */
    private static function getOrganizacionAdminData(int $organizacion_id): ?array {
        $stmt = DB::pdo()->prepare("
            SELECT u.nombre, u.username, u.email, u.celular, u.cedula
            FROM organizaciones o
            INNER JOIN usuarios u ON o.admin_user_id = u.id
            WHERE o.id = ? AND o.estatus = 1
        ");
        $stmt->execute([$organizacion_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Obtiene datos del torneo
     */
    private static function getTournamentData(int $tournament_id): ?array {
        $pdo = DB::pdo();
        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $hasCodOrg = false;
        }
        $orgJoin = $hasCodOrg
            ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
            : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";
        $stmt = DB::pdo()->prepare("
            SELECT t.*, 
                   o.nombre as organizacion_nombre, o.responsable as organizacion_responsable, o.telefono as organizacion_telefono, o.direccion as organizacion_direccion
            FROM tournaments t
            {$orgJoin}
            WHERE t.id = ? AND t.estatus = 1
        ");
        $stmt->execute([$tournament_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Obtiene datos del administrador del torneo
     */
    private static function getTournamentAdminData(int $club_id): ?array {
        return self::getClubAdminData($club_id);
    }
    
    /**
     * Genera HTML para invitación al club
     */
    private static function generateClubInvitationHTML(array $club_data, ?array $admin_data): string {
        $app_url = $_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd';
        $invitation_link = AppHelpers::url("register_by_club.php?club_id=") . $club_data['id'];
        $logo_path = self::getLogoPath($club_data['logo'] ?? null);
        
        $modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invitación a Afiliarse al Club</title>
    <style>
        @page { 
            size: A4; 
            margin: 15mm; 
        }
        
        body { 
            font-family: "Helvetica Neue", "Helvetica", "Arial", sans-serif;
            margin: 0; 
            padding: 0;
            color: #1a1a1a;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-size: 11px;
        }
        
        .document-wrapper {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 3px solid #667eea;
            margin-bottom: 20px;
        }
        
        .header .icon {
            font-size: 48px;
            color: #25D366;
            margin-bottom: 10px;
        }
        
        .header h1 {
            color: #667eea;
            font-size: 24px;
            font-weight: 700;
            margin: 10px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header p {
            color: #4a5568;
            font-size: 14px;
            margin: 5px 0;
        }
        
        .logo-container {
            text-align: center;
            margin: 20px 0;
        }
        
        .logo-container img {
            max-width: 150px;
            max-height: 150px;
            border: 3px solid #667eea;
            border-radius: 10px;
            padding: 10px;
            background: white;
        }
        
        .club-name {
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            color: #667eea;
            margin: 20px 0;
            padding: 15px;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border: 2px solid #667eea;
            border-radius: 8px;
        }
        
        .section {
            margin: 20px 0;
            padding: 15px;
            background: #f7fafc;
            border-left: 4px solid #667eea;
            border-radius: 5px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-grid {
            display: table;
            width: 100%;
        }
        
        .info-row {
            display: table-row;
        }
        
        .info-label {
            display: table-cell;
            font-weight: 700;
            color: #4a5568;
            padding: 8px 10px 8px 0;
            width: 30%;
        }
        
        .info-value {
            display: table-cell;
            color: #2d3748;
            padding: 8px 0;
        }
        
        .benefits-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .benefits-list li {
            padding: 10px 0 10px 30px;
            position: relative;
            font-size: 13px;
            color: #2d3748;
        }
        
        .benefits-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #25D366;
            font-weight: 700;
            font-size: 18px;
        }
        
        .link-section {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
            color: white;
        }
        
        .link-section h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            font-weight: 700;
        }
        
        .link-box {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            word-break: break-all;
        }
        
        .link-box code {
            font-family: "Monaco", "Courier New", monospace;
            font-size: 10px;
            color: #667eea;
            font-weight: 600;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            color: #718096;
            font-size: 10px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="document-wrapper">
        <div class="header">
            <div class="icon">🎲</div>
            <h1>Invitación a Afiliarse</h1>
            <p>La Estación del Dominó</p>
        </div>
        
        ' . ($logo_path ? '
        <div class="logo-container">
            <img src="' . $logo_path . '" alt="Logo del Club" />
        </div>' : '') . '
        
        <div class="club-name">' . htmlspecialchars($club_data['nombre']) . '</div>
        
        <div class="section">
            <div class="section-title">🏢 Información del Club</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['nombre']) . '</div>
                </div>';
        
        if ($club_data['delegado']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Delegado:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['delegado']) . '</div>
                </div>';
        }
        
        if ($club_data['telefono']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['telefono']) . '</div>
                </div>';
        }
        
        if ($club_data['direccion']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Dirección:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['direccion']) . '</div>
                </div>';
        }
        
        if ($club_data['total_afiliados']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Afiliados:</div>
                    <div class="info-value">' . number_format($club_data['total_afiliados']) . ' miembros</div>
                </div>';
        }
        
        if ($club_data['total_torneos']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Torneos Organizados:</div>
                    <div class="info-value">' . number_format($club_data['total_torneos']) . '</div>
                </div>';
        }
        
        $html .= '
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">✨ Beneficios de Afiliarte</div>
            <ul class="benefits-list">
                <li>Participar en torneos organizados</li>
                <li>Acceso a estadísticas y resultados</li>
                <li>Formar parte de nuestra comunidad</li>
                <li>Invitar a más amigos</li>
                <li>Gestionar tus propios torneos</li>
            </ul>
        </div>
        
        <div class="link-section">
            <h3>🔗 Link de Registro</h3>
            <p>Haz clic en el siguiente enlace para completar tu registro:</p>
            <div class="link-box">
                <code>' . htmlspecialchars($invitation_link) . '</code>
            </div>
        </div>';
        
        if ($admin_data) {
            $html .= '
        <div class="section">
            <div class="section-title">👤 Información de Contacto</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Administrador:</div>
                    <div class="info-value">' . htmlspecialchars($admin_data['nombre'] ?? $admin_data['username'] ?? 'N/A') . '</div>
                </div>';
            
            if ($admin_data['celular']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value">' . htmlspecialchars($admin_data['celular']) . '</div>
                </div>';
            }
            
            if ($admin_data['email']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value">' . htmlspecialchars($admin_data['email']) . '</div>
                </div>';
            }
            
            $html .= '
            </div>
        </div>';
        }
        
        $html .= '
        
        <div class="footer">
            <p>Este documento es una invitación oficial - La Estación del Dominó</p>
            <p>Fecha de generación: ' . date('d/m/Y H:i') . '</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Genera HTML para invitación al torneo
     */
    private static function generateTournamentInvitationHTML(array $tournament_data, ?array $club_data, ?array $admin_data, string $invitation_link): string {
        $logo_path = $club_data ? self::getLogoPath($club_data['logo'] ?? null) : null;
        $modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
        $clases = [1 => 'Torneo', 2 => 'Campeonato'];
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invitación al Torneo</title>
    <style>
        @page { 
            size: A4; 
            margin: 15mm; 
        }
        
        body { 
            font-family: "Helvetica Neue", "Helvetica", "Arial", sans-serif;
            margin: 0; 
            padding: 0;
            color: #1a1a1a;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            font-size: 11px;
        }
        
        .document-wrapper {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 3px solid #f59e0b;
            margin-bottom: 20px;
        }
        
        .header .icon {
            font-size: 48px;
            color: #f59e0b;
            margin-bottom: 10px;
        }
        
        .header h1 {
            color: #f59e0b;
            font-size: 24px;
            font-weight: 700;
            margin: 10px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .tournament-title {
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            color: #f59e0b;
            margin: 20px 0;
            padding: 15px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 8px;
        }
        
        .section {
            margin: 20px 0;
            padding: 15px;
            background: #f7fafc;
            border-left: 4px solid #f59e0b;
            border-radius: 5px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #f59e0b;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-grid {
            display: table;
            width: 100%;
        }
        
        .info-row {
            display: table-row;
        }
        
        .info-label {
            display: table-cell;
            font-weight: 700;
            color: #4a5568;
            padding: 8px 10px 8px 0;
            width: 35%;
        }
        
        .info-value {
            display: table-cell;
            color: #2d3748;
            padding: 8px 0;
        }
        
        .link-section {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
            color: white;
        }
        
        .link-section h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            font-weight: 700;
        }
        
        .link-box {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            word-break: break-all;
        }
        
        .link-box code {
            font-family: "Monaco", "Courier New", monospace;
            font-size: 10px;
            color: #25D366;
            font-weight: 600;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            color: #718096;
            font-size: 10px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="document-wrapper">
        <div class="header">
            <div class="icon">🏆</div>
            <h1>Invitación al Torneo</h1>
            <p>La Estación del Dominó</p>
        </div>
        
        ' . ($logo_path ? '
        <div style="text-align: center; margin: 20px 0;">
            <img src="' . $logo_path . '" alt="Logo del Club" style="max-width: 150px; max-height: 150px; border: 3px solid #f59e0b; border-radius: 10px; padding: 10px; background: white;" />
        </div>' : '') . '
        
        <div class="tournament-title">' . htmlspecialchars($tournament_data['nombre']) . '</div>
        
        <div class="section">
            <div class="section-title">🏆 Información del Torneo</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value">' . htmlspecialchars($tournament_data['nombre']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Fecha:</div>
                    <div class="info-value">' . date('d/m/Y', strtotime($tournament_data['fechator'])) . '</div>
                </div>';
        
        if ($tournament_data['lugar']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Lugar:</div>
                    <div class="info-value">' . htmlspecialchars($tournament_data['lugar']) . '</div>
                </div>';
        }
        
        $html .= '
                <div class="info-row">
                    <div class="info-label">Modalidad:</div>
                    <div class="info-value">' . ($modalidades[$tournament_data['modalidad']] ?? 'N/A') . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Tipo:</div>
                    <div class="info-value">' . ($clases[$tournament_data['clase']] ?? 'Torneo') . '</div>
                </div>';
        
        if ($tournament_data['costo'] > 0) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Costo:</div>
                    <div class="info-value">$' . number_format($tournament_data['costo'], 2) . '</div>
                </div>';
        }
        
        if ($tournament_data['rondas']) {
            $html .= '
                <div class="info-row">
                    <div class="info-label">Rondas:</div>
                    <div class="info-value">' . $tournament_data['rondas'] . '</div>
                </div>';
        }
        
        $html .= '
            </div>
        </div>';
        
        if ($club_data) {
            $html .= '
        <div class="section">
            <div class="section-title">🏢 Club Organizador</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['nombre']) . '</div>
                </div>';
            
            if ($club_data['delegado']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Delegado:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['delegado']) . '</div>
                </div>';
            }
            
            if ($club_data['telefono']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['telefono']) . '</div>
                </div>';
            }
            
            if ($club_data['direccion']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Dirección:</div>
                    <div class="info-value">' . htmlspecialchars($club_data['direccion']) . '</div>
                </div>';
            }
            
            $html .= '
            </div>
        </div>';
        }
        
        $html .= '
        
        <div class="link-section">
            <h3>🔗 Link de Inscripción</h3>
            <p>Haz clic en el siguiente enlace para inscribirte directamente:</p>
            <div class="link-box">
                <code>' . htmlspecialchars($invitation_link) . '</code>
            </div>
        </div>';
        
        if ($admin_data) {
            $html .= '
        <div class="section">
            <div class="section-title">👤 Información de Contacto</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Administrador:</div>
                    <div class="info-value">' . htmlspecialchars($admin_data['nombre'] ?? $admin_data['username'] ?? 'N/A') . '</div>
                </div>';
            
            if ($admin_data['celular']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value">' . htmlspecialchars($admin_data['celular']) . '</div>
                </div>';
            }
            
            if ($admin_data['email']) {
                $html .= '
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value">' . htmlspecialchars($admin_data['email']) . '</div>
                </div>';
            }
            
            $html .= '
            </div>
        </div>';
        }
        
        $html .= '
        
        <div class="footer">
            <p>Este documento es una invitación oficial al torneo - La Estación del Dominó</p>
            <p>Fecha de generación: ' . date('d/m/Y H:i') . '</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Obtiene la ruta del logo
     */
    private static function getLogoPath(?string $logo_relative_path): ?string {
        if (empty($logo_relative_path)) {
            return null;
        }
        
        $absolute_path = __DIR__ . '/../' . $logo_relative_path;
        
        if (!file_exists($absolute_path)) {
            return null;
        }
        
        return $absolute_path;
    }
    
    /**
     * Obtiene la ruta del logo de La Estación del Dominó
     */
    private static function getEstacionLogoPath(): ?string {
        // Buscar el logo en varias ubicaciones posibles
        $possible_paths = [
            __DIR__ . '/../lib/Assets/mislogos/logo4.png',
            __DIR__ . '/../lib/Assets/mislogos/logo2.png',
            __DIR__ . '/../upload/logos/estacion_domino.png',
            __DIR__ . '/../upload/logos/estacion_domino.jpg',
            __DIR__ . '/../upload/logos/la_estacion.png',
            __DIR__ . '/../upload/logos/la_estacion.jpg',
            __DIR__ . '/../public/assets/img/estacion_domino.png',
            __DIR__ . '/../public/assets/img/estacion_domino.jpg',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * Crea el PDF usando TCPDF o Dompdf
     */
    private static function createPDF(string $html_content, string $filename): string {
        // Crear directorio si no existe
        if (!is_dir(self::$pdf_dir)) {
            mkdir(self::$pdf_dir, 0755, true);
        }
        
        $pdf_path = self::$pdf_dir . $filename . '.pdf';
        
        // Intentar cargar Dompdf desde vendor
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }
        
        // Intentar usar Dompdf primero (más común)
        if (class_exists('Dompdf\Dompdf')) {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('chroot', __DIR__ . '/..');
            
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html_content);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            file_put_contents($pdf_path, $dompdf->output());
        }
        // Intentar usar TCPDF
        elseif (class_exists('TCPDF')) {
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator('La Estación del Dominó');
            $pdf->SetAuthor('Sistema Mistorneos');
            $pdf->SetTitle('Invitación');
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);
            $pdf->AddPage();
            $pdf->writeHTML($html_content, true, false, true, false, '');
            $pdf->Output($pdf_path, 'F');
        }
        // Fallback: error
        else {
            throw new Exception('No se encontró ninguna librería de PDF instalada. Por favor instale Dompdf o TCPDF.');
        }
        
        return 'upload/pdfs/' . $filename . '.pdf';
    }
    
    /**
     * Guarda la ruta del PDF en la base de datos para el club
     */
    private static function saveClubPDFPath(int $club_id, string $pdf_path): void {
        // Verificar si existe la columna invitation_pdf en la tabla clubes
        try {
            $stmt = DB::pdo()->prepare("SHOW COLUMNS FROM clubes LIKE 'invitation_pdf'");
            $stmt->execute();
            $column_exists = $stmt->fetch();
            
            if (!$column_exists) {
                // Crear la columna si no existe
                DB::pdo()->exec("ALTER TABLE clubes ADD COLUMN invitation_pdf VARCHAR(255) NULL AFTER logo");
            }
            
            // Actualizar la ruta del PDF
            $stmt = DB::pdo()->prepare("UPDATE clubes SET invitation_pdf = ? WHERE id = ?");
            $stmt->execute([$pdf_path, $club_id]);
        } catch (Exception $e) {
            error_log("Error guardando ruta del PDF del club: " . $e->getMessage());
        }
    }
    
    /**
     * Guarda la ruta del PDF en la base de datos para el torneo
     */
    private static function saveTournamentPDFPath(int $tournament_id, string $pdf_path): void {
        // Verificar si existe la columna invitation_pdf en la tabla tournaments
        try {
            $stmt = DB::pdo()->prepare("SHOW COLUMNS FROM tournaments LIKE 'invitation_pdf'");
            $stmt->execute();
            $column_exists = $stmt->fetch();
            
            if (!$column_exists) {
                // Crear la columna si no existe
                DB::pdo()->exec("ALTER TABLE tournaments ADD COLUMN invitation_pdf VARCHAR(255) NULL AFTER afiche");
            }
            
            // Actualizar la ruta del PDF
            $stmt = DB::pdo()->prepare("UPDATE tournaments SET invitation_pdf = ? WHERE id = ?");
            $stmt->execute([$pdf_path, $tournament_id]);
        } catch (Exception $e) {
            error_log("Error guardando ruta del PDF del torneo: " . $e->getMessage());
        }
    }
    
    /**
     * Obtiene la ruta del PDF de invitación del club
     */
    public static function getClubPDFPath(int $club_id): ?string {
        try {
            $stmt = DB::pdo()->prepare("SELECT invitation_pdf FROM clubes WHERE id = ?");
            $stmt->execute([$club_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['invitation_pdf'] ?? null;
        } catch (Exception $e) {
            error_log("Error obteniendo PDF del club: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtiene la ruta del PDF de invitación del torneo
     */
    /**
     * Genera PDF de invitación para ser Administrador de organización
     */
    public static function generateAdminClubInvitationPDF(
        string $nombre_invitado,
        string $email_invitado,
        string $telefono_invitado = '',
        string $notas = ''
    ): array {
        try {
            // Generar contenido HTML
            $html_content = self::generateAdminClubInvitationHTML(
                $nombre_invitado,
                $email_invitado,
                $telefono_invitado,
                $notas
            );
            
            // Generar PDF
            $filename = "admin_club_invitation_" . time() . "_" . uniqid();
            $pdf_path = self::createPDF($html_content, $filename);
            
            // Guardar registro de invitación (opcional)
            self::saveAdminClubInvitation($nombre_invitado, $email_invitado, $telefono_invitado, $pdf_path);
            
            return [
                'success' => true,
                'pdf_path' => $pdf_path,
                'message' => 'PDF de invitación a administrador de club generado correctamente'
            ];
            
        } catch (Exception $e) {
            error_log("Error generando PDF de invitación a admin_club: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error generando PDF: ' . $e->getMessage()];
        }
    }
    
    /**
     * Genera el HTML para la invitación a administrador de club
     */
    private static function generateAdminClubInvitationHTML(
        string $nombre_invitado,
        string $email_invitado,
        string $telefono_invitado,
        string $notas
    ): string {
        // Obtener logo de La Estación del Dominó
        $logo_path = self::getEstacionLogoPath();
        $app_url = function_exists('app_base_url') ? app_base_url() : FvdConfig::resolveAppUrl();
        $register_url = AppHelpers::url('affiliate_request.php');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Arial", "Helvetica", sans-serif;
            color: #2d3748;
            line-height: 1.6;
            background: #f7fafc;
        }
        
        .document-wrapper {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .top-logo-section {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px 0;
            background: #ffffff;
            border-bottom: 3px solid #667eea;
        }
        
        .top-logo-section img {
            max-width: 300px;
            max-height: 150px;
            height: auto;
            width: auto;
            margin-bottom: 20px;
        }
        
        .invitation-text {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            text-align: center;
            padding: 15px 20px;
            background: #f0f4ff;
            border-left: 5px solid #667eea;
            border-right: 5px solid #667eea;
            margin: 0 auto 30px auto;
            max-width: 90%;
            line-height: 1.8;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 32px;
            font-weight: 800;
            margin: 15px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .logo-container {
            text-align: center;
            margin: 30px 0;
        }
        
        .logo-container img {
            max-width: 200px;
            max-height: 200px;
        }
        
        .greeting {
            font-size: 20px;
            color: #667eea;
            font-weight: 700;
            margin: 30px 0 20px 0;
            text-align: center;
        }
        
        .intro-text {
            font-size: 16px;
            line-height: 1.8;
            color: #4a5568;
            text-align: justify;
            margin: 20px 0;
            padding: 20px;
            background: #f7fafc;
            border-left: 4px solid #667eea;
            border-radius: 5px;
        }
        
        .section {
            margin: 30px 0;
            padding: 25px;
            background: #f7fafc;
            border-left: 4px solid #667eea;
            border-radius: 8px;
        }
        
        .section-title {
            font-size: 22px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .benefits-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .benefits-list li {
            padding: 15px 0 15px 40px;
            position: relative;
            font-size: 15px;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .benefits-list li:last-child {
            border-bottom: none;
        }
        
        .benefits-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #25D366;
            font-weight: 900;
            font-size: 24px;
            width: 30px;
            height: 30px;
            background: #f0fdf4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #25D366;
        }
        
        .benefit-title {
            font-weight: 700;
            color: #1a202c;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .benefit-description {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .cta-section {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            margin: 40px 0;
            color: white;
        }
        
        .cta-section h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .cta-section p {
            font-size: 16px;
            margin-bottom: 20px;
            opacity: 0.95;
        }
        
        .register-link {
            display: inline-block;
            background: white;
            color: #128C7E;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            margin: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        }
        
        .register-link:hover {
            background: #f7fafc;
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0,0,0,0.3);
        }
        
        .info-section {
            background: #edf2f7;
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
        }
        
        .info-row {
            margin: 10px 0;
            font-size: 14px;
        }
        
        .info-label {
            font-weight: 700;
            color: #4a5568;
            display: inline-block;
            width: 120px;
        }
        
        .info-value {
            color: #2d3748;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            color: #718096;
            font-size: 12px;
        }
        
        .notas-section {
            background: #fffbf0;
            border-left: 4px solid #f59e0b;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .notas-section strong {
            color: #d97706;
        }
    </style>
</head>
<body>
    <div class="document-wrapper">
        <!-- Logo y texto de invitación en la parte superior -->
        <div class="top-logo-section">';
        
        if ($logo_path) {
            $html .= '<img src="' . htmlspecialchars($logo_path) . '" alt="La Estación del Dominó" />';
        }
        
        $html .= '<div class="invitation-text">
            La Estación del Dominó te invita a participar como aliado en la masificación deportiva del Dominó
        </div>
        </div>
        
        <div class="header">
            <h1>Invitación Especial</h1>
            <p>La Estación del Dominó</p>
        </div>
        
        <div class="greeting">
            Estimado/a ' . htmlspecialchars($nombre_invitado) . '
        </div>
        
        <div class="intro-text">
            <p>Nos complace invitarte a formar parte de <strong>La Estación del Dominó</strong> como <strong>Administrador de organización</strong>.</p>
            <p>Como administrador de club, tendrás acceso a herramientas profesionales que te permitirán gestionar tu club de manera eficiente, organizar torneos, administrar afiliados y mucho más.</p>
        </div>';
        
        if (!empty($notas)) {
            $html .= '
        <div class="notas-section">
            <strong>Mensaje Personal:</strong>
            <p>' . nl2br(htmlspecialchars($notas)) . '</p>
        </div>';
        }
        
        $html .= '
        <div class="section">
            <div class="section-title">🌟 Ventajas de Ser Administrador de organización</div>
            <ul class="benefits-list">
                <li>
                    <div class="benefit-title">Gestión Completa de Tu Club</div>
                    <div class="benefit-description">Administra todos los aspectos de tu club desde una plataforma centralizada y fácil de usar. Control total sobre la información y configuración de tu club.</div>
                </li>
                <li>
                    <div class="benefit-title">Organización de Torneos Profesionales</div>
                    <div class="benefit-description">Crea y gestiona torneos propios con herramientas profesionales de administración. Controla inscripciones, resultados, mesas y estadísticas en tiempo real.</div>
                </li>
                <li>
                    <div class="benefit-title">Gestión de Afiliados</div>
                    <div class="benefit-description">Administra la lista completa de afiliados, sus datos personales, estadísticas de participación y rendimiento. Genera reportes detallados de tu club.</div>
                </li>
                <li>
                    <div class="benefit-title">Reportes y Estadísticas Avanzadas</div>
                    <div class="benefit-description">Accede a reportes detallados y estadísticas completas de tu club y torneos. Visualiza el rendimiento de tus afiliados y el crecimiento de tu club.</div>
                </li>
                <li>
                    <div class="benefit-title">Invitaciones Personalizadas</div>
                    <div class="benefit-description">Invita a jugadores a tus torneos mediante WhatsApp con enlaces personalizados. Sistema automatizado de invitaciones y recordatorios.</div>
                </li>
                <li>
                    <div class="benefit-title">Galería de Fotos</div>
                    <div class="benefit-description">Comparte las mejores fotos de tus eventos y torneos. Crea galerías organizadas por torneo y comparte los mejores momentos con tu comunidad.</div>
                </li>
                <li>
                    <div class="benefit-title">Soporte Técnico Dedicado</div>
                    <div class="benefit-description">Recibe asistencia técnica especializada y capacitación para aprovechar al máximo todas las funcionalidades de la plataforma.</div>
                </li>
                <li>
                    <div class="benefit-title">Comunidad Activa</div>
                    <div class="benefit-description">Forma parte de una red de clubes y administradores activos en el mundo del dominó. Intercambia experiencias y mejores prácticas.</div>
                </li>
                <li>
                    <div class="benefit-title">Acceso Prioritario</div>
                    <div class="benefit-description">Obtén acceso prioritario a nuevas funcionalidades, actualizaciones y eventos exclusivos para administradores de club.</div>
                </li>
                <li>
                    <div class="benefit-title">Herramientas de Marketing</div>
                    <div class="benefit-description">Utiliza herramientas integradas para promocionar tus torneos y eventos. Genera códigos QR, enlaces de compartir y materiales promocionales.</div>
                </li>
            </ul>
        </div>
        
        <div class="cta-section">
            <h3>¡Únete a Nosotros!</h3>
            <p>Regístrate ahora y comienza a gestionar tu club de manera profesional</p>
            <a href="' . htmlspecialchars($register_url) . '" class="register-link">
                📝 Registrarse como Administrador
            </a>
        </div>
        
        <div class="info-section">
            <div class="section-title" style="font-size: 18px; margin-bottom: 15px;">📋 Información de Contacto</div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value">' . htmlspecialchars($email_invitado) . '</span>
            </div>';
        
        if (!empty($telefono_invitado)) {
            $html .= '
            <div class="info-row">
                <span class="info-label">Teléfono:</span>
                <span class="info-value">' . htmlspecialchars($telefono_invitado) . '</span>
            </div>';
        }
        
        $html .= '
            <div class="info-row">
                <span class="info-label">Plataforma:</span>
                <span class="info-value">' . htmlspecialchars($app_url) . '</span>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>La Estación del Dominó</strong></p>
            <p>Sistema de Gestión de Torneos y Clubes</p>
            <p>Fecha de emisión: ' . date('d/m/Y') . '</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Guarda el registro de invitación a administrador de club
     */
    private static function saveAdminClubInvitation(
        string $nombre_invitado,
        string $email_invitado,
        string $telefono_invitado,
        string $pdf_path
    ): void {
        try {
            // Crear tabla si no existe
            $pdo = DB::pdo();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_club_invitations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nombre_invitado VARCHAR(255) NOT NULL,
                    email_invitado VARCHAR(255) NOT NULL,
                    telefono_invitado VARCHAR(50),
                    pdf_path VARCHAR(500),
                    notas TEXT,
                    fecha_invitacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email (email_invitado),
                    INDEX idx_fecha (fecha_invitacion)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Insertar registro
            $stmt = $pdo->prepare("
                INSERT INTO admin_club_invitations 
                (nombre_invitado, email_invitado, telefono_invitado, pdf_path, fecha_invitacion)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $nombre_invitado,
                $email_invitado,
                $telefono_invitado ?: null,
                $pdf_path
            ]);
        } catch (Exception $e) {
            // No es crítico si falla el guardado del registro
            error_log("Error guardando registro de invitación admin_club: " . $e->getMessage());
        }
    }
    
    public static function getTournamentPDFPath(int $tournament_id): ?string {
        try {
            $stmt = DB::pdo()->prepare("SELECT invitation_pdf FROM tournaments WHERE id = ?");
            $stmt->execute([$tournament_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['invitation_pdf'] ?? null;
        } catch (Exception $e) {
            error_log("Error obteniendo PDF del torneo: " . $e->getMessage());
            return null;
        }
    }
}
?>

