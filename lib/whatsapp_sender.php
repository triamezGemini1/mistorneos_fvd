<?php


require_once __DIR__ . '/../config/db.php';

class WhatsAppSender {
    private static $log_file = __DIR__ . '/../logs/whatsapp_log.txt';

    /**
     * Genera el mensaje de WhatsApp para invitación usando plantillas configurables
     */
    public static function generateInvitationMessage(array $data): string {
        // MENSAJE CON TOKEN (Sistema nuevo)
        $base_url = rtrim(class_exists('FvdConfig') ? FvdConfig::resolvePublicUrl() : (class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : 'http://localhost/mistorneos_fvd/public'), '/') . '/';
        
        $url_sistema = $base_url;
        $url_login = $url_sistema . "modules/invitations/inscripciones/login.php";
        
        // Obtener TOKEN de la invitación
        $token = $data['token'] ?? '';
        
        // Obtener datos
        $organizacion = $data['organizer_club_name'] ?? 'Organización';
        $torneo_nombre = $data['tournament_name'] ?? '';
        $fecha_torneo = isset($data['tournament_date']) ? date('d/m/Y', strtotime($data['tournament_date'])) : '';
        $lugar_torneo = $data['torneo_lugar'] ?? '';
        $club_nombre = $data['club_name'] ?? '';
        $delegado = $data['club_delegado'] ?? '';
        $club_direccion = $data['club_direccion'] ?? '';
        $telefono_club = $data['club_telefono'] ?? '';
        
        // Vigencia
        $acceso1 = isset($data['acceso1']) ? date('d/m/Y', strtotime($data['acceso1'])) : '';
        $acceso2 = isset($data['acceso2']) ? date('d/m/Y', strtotime($data['acceso2'])) : '';
        $vigencia = $acceso1 . ' al ' . $acceso2;
        $estado = strtoupper($data['estado'] ?? 'ACTIVA');
        
        // Construir mensaje con TOKEN
        $separador = "??????????????????????";
        
        $mensaje = "• *INVITACIÓN A TORNEO - " . strtoupper($organizacion) . "*\n\n";
        $mensaje .= $separador . "\n\n";
        
        // INFORMACIÓN DEL TORNEO
        $mensaje .= "• *INFORMACIÓN DEL TORNEO*\n\n";
        $mensaje .= "• *Organización Responsable:* " . $organizacion . "\n";
        $mensaje .= "• *Nombre del Torneo:* " . $torneo_nombre . "\n";
        $mensaje .= "• *Fecha del Torneo:* " . $fecha_torneo . "\n";
        if (!empty($lugar_torneo)) {
            $mensaje .= "• *Lugar:* " . $lugar_torneo . "\n";
        }
        $mensaje .= "\n" . $separador . "\n\n";
        
        // CLUB INVITADO
        $mensaje .= "• *CLUB INVITADO*\n\n";
        $mensaje .= "• *Nombre Club:* " . $club_nombre . "\n";
        $mensaje .= "• *Delegado:* " . $delegado . "\n";
        if (!empty($telefono_club)) {
            $mensaje .= "• *Teléfono:* " . $telefono_club . "\n";
        }
        if (!empty($club_direccion)) {
            $mensaje .= "• *Dirección:* " . $club_direccion . "\n";
        }
        $mensaje .= "\n" . $separador . "\n\n";
        
        // VIGENCIA
        $mensaje .= "• *VIGENCIA DE LA INVITACIÓN*\n\n";
        $mensaje .= "• *Periodo de Acceso:* " . $vigencia . "\n";
        $mensaje .= "• *Estado:* " . $estado . "\n";
        $mensaje .= "\n" . $separador . "\n\n";
        
        // ***** CREDENCIALES DE ACCESO CON TOKEN *****
        $mensaje .= "• *CREDENCIALES PARA INSCRIPCIÓN DE JUGADORES*\n\n";
        $mensaje .= "• ?? ?? *¡INFORMACIÓN IMPORTANTE!* ⚠ ⚠ ⚠\n\n";
        $mensaje .= "Para inscribir a sus jugadores, utilice:\n\n";
        
        $mensaje .= "• *URL DE ACCESO:*\n";
        $mensaje .= $url_login . "\n\n";
        
        $mensaje .= "• *TOKEN DE ACCESO (Su Clave Personal):*\n";
        $mensaje .= "*" . $token . "*\n\n";
        
        $mensaje .= "• *INSTRUCCIONES:*\n";
        $mensaje .= "1?? Copie el TOKEN completo (arriba)\n";
        $mensaje .= "2?? Entre a la URL de acceso\n";
        $mensaje .= "3?? Pegue su TOKEN en el formulario\n";
        $mensaje .= "4?? Inscriba a sus jugadores por cédula\n\n";
        
        $mensaje .= "• *GUARDE ESTE TOKEN - Lo necesitará cada vez que acceda*\n";
        $mensaje .= "\n" . $separador . "\n\n";
        
        // CONTACTO
        $mensaje .= "• *CONTACTO " . strtoupper($organizacion) . "*\n\n";
        $mensaje .= "¡Esperamos contar con su participación!\n\n";
        $mensaje .= "_" . $organizacion . "_";
        
        return $mensaje;
    }

    /**
     * Genera mensaje de WhatsApp con PDF para invitación
     */
    public static function generateInvitationWithPDF(array $data): array {
        require_once __DIR__ . '/pdf_generator.php';
        
        try {
            // Generar PDF de invitación
            $pdf_result = PDFGenerator::generateInvitationPDF((int)$data['id']);
            
            if (!$pdf_result['success']) {
                return [
                    'success' => false,
                    'error' => 'Error generando PDF: ' . ($pdf_result['error'] ?? 'Error desconocido')
                ];
            }
            
            // Generar mensaje de WhatsApp
            $message = self::generateInvitationMessage($data);
            
            // Agregar información del PDF al mensaje
            $pdf_url = self::getBaseUrl() . '/' . $pdf_result['pdf_path'];
            $message .= "\n\n?? *DOCUMENTO ADJUNTO:*\n";
            $message .= "• Descargar invitación completa: {$pdf_url}";
            
            // Obtener teléfonos
            $sender_phone = self::getSenderPhone($data['club_responsable']);
            $receiver_phone = self::getReceiverPhone($data['club_id']);
            
            return [
                'success' => true,
                'message' => $message,
                'sender_phone' => $sender_phone,
                'receiver_phone' => $receiver_phone,
                'pdf_path' => $pdf_result['pdf_path'],
                'pdf_url' => $pdf_url,
                'whatsapp_link' => self::generateWhatsAppLink($receiver_phone, $message)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error interno: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Genera el enlace de WhatsApp Web
     */
    public static function generateWhatsAppLink(string $phone, string $message): string {
        // Limpiar número de teléfono (solo números)
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Agregar código de país si no lo tiene (Venezuela +58)
        if (!str_starts_with($clean_phone, '58')) {
            $clean_phone = '58' . $clean_phone;
        }
        
        // Codificar mensaje para URL
        $encoded_message = urlencode($message);
        
        return "https://wa.me/{$clean_phone}?text={$encoded_message}";
    }

    /**
     * Simula el envío de WhatsApp (registra en log)
     */
    public static function sendInvitationWhatsApp(array $invitation_data, string $phone): array {
        try {
            $message = self::generateInvitationMessage($invitation_data);
            $whatsapp_link = self::generateWhatsAppLink($phone, $message);
            
            // Registrar en log
            $log_entry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'phone' => $phone,
                'club_name' => $invitation_data['club_name'] ?? 'N/A',
                'delegado' => $invitation_data['club_delegado'] ?? 'N/A',
                'tournament_name' => $invitation_data['tournament_name'] ?? 'N/A',
                'whatsapp_link' => $whatsapp_link,
                'message_preview' => substr($message, 0, 200) . '...',
                'status' => 'GENERATED'
            ];
            
            $log_line = json_encode($log_entry, JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents(self::$log_file, $log_line, FILE_APPEND | LOCK_EX);
            
            return [
                'success' => true,
                'message' => "Enlace de WhatsApp generado para: $phone",
                'whatsapp_link' => $whatsapp_link,
                'phone' => $phone
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Error generando enlace de WhatsApp: ' . $e->getMessage()];
        }
    }

    /**
     * Obtiene los datos completos de una invitación
     */
    public static function getInvitationDataForWhatsApp(int $invitation_id): ?array {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT 
                    i.*,
                    t.nombre as tournament_name,
                    t.fechator as tournament_date,
                    t.lugar as torneo_lugar,
                    t.club_responsable,
                    c.nombre as club_name,
                    c.delegado as club_delegado,
                    c.email as club_email,
                    c.telefono as club_telefono,
                    c.direccion as club_direccion,
                    oc.nombre as organizer_club_name,
                    oc.delegado as organizer_delegado
                FROM invitations i
                LEFT JOIN tournaments t ON i.torneo_id = t.id
                LEFT JOIN clubes c ON i.club_id = c.id
                LEFT JOIN clubes oc ON t.club_responsable = oc.id
                WHERE i.id = ?
            ");
            $stmt->execute([$invitation_id]);
            $data = $stmt->fetch();
            
            return $data ?: null;
        } catch (Exception $e) {
            error_log("Error obteniendo datos de invitación: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene el historial de WhatsApp
     */
    public static function getWhatsAppLog(): array {
        if (!file_exists(self::$log_file)) {
            return [];
        }
        
        $lines = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $messages = [];
        
        foreach ($lines as $line) {
            $message = json_decode($line, true);
            if ($message) {
                $messages[] = $message;
            }
        }
        
        return array_reverse($messages); // Más recientes primero
    }

    /**
     * Valida número de teléfono
     */
    public static function validatePhone(string $phone): bool {
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        return strlen($clean_phone) >= 10 && strlen($clean_phone) <= 15;
    }

    /**
     * Formatea número de teléfono para mostrar
     */
    public static function formatPhone(string $phone): string {
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($clean_phone, '58')) {
            $clean_phone = substr($clean_phone, 2);
        }
        
        if (strlen($clean_phone) == 10) {
            return substr($clean_phone, 0, 3) . '-' . substr($clean_phone, 3, 3) . '-' . substr($clean_phone, 6);
        }
        
        return $clean_phone;
    }

    /**
     * Obtiene las credenciales reales del usuario
     */
    private static function getUserCredentials(string $username, int $club_id): array {
        try {
            // Primero intentar buscar el username exacto
            $stmt = DB::pdo()->prepare("SELECT username FROM usuarios WHERE username = ?");
            $stmt->execute([$username]);
            $exact_user = $stmt->fetch();
            
            if ($exact_user) {
                return [
                    'username' => $exact_user['username'],
                    'password' => 'invitado123' // Contraseña real para invitados
                ];
            }
            
            // Si no existe el usuario con "usuario{id}", crear/actualizar con "invitado{id}"
            $invitado_username = "invitado" . $club_id;
            $stmt = DB::pdo()->prepare("SELECT username FROM usuarios WHERE username = ?");
            $stmt->execute([$invitado_username]);
            $invitado_user = $stmt->fetch();
            
            if ($invitado_user) {
                return [
                    'username' => $invitado_username,
                    'password' => 'invitado123'
                ];
            }
            
            // Si tampoco existe "invitado{id}", crearlo automáticamente
            self::createInvitedUser($invitado_username, $club_id);
            
            return [
                'username' => $invitado_username,
                'password' => 'invitado123'
            ];
            
        } catch (Exception $e) {
            error_log("Error obteniendo credenciales de usuario: " . $e->getMessage());
            return [
                'username' => "invitado" . $club_id,
                'password' => 'invitado123'
            ];
        }
    }
    
    /**
     * Crea automáticamente un usuario invitado faltante
     */
    private static function createInvitedUser(string $username, int $club_id): bool {
        try {
            require_once __DIR__ . '/security.php';
            $password_hash = Security::hashPassword('invitado123');
            
            // Obtener email del club
            $stmt_club = DB::pdo()->prepare("SELECT email FROM clubes WHERE id = ?");
            $stmt_club->execute([$club_id]);
            $club_email = $stmt_club->fetchColumn();
            
            // Crear el usuario invitado
            $stmt_user = DB::pdo()->prepare("
                INSERT INTO usuarios (username, password_hash, email, role, status, club_id) 
                VALUES (?, ?, ?, 'admin_club', 0, ?)
            ");
            $result = $stmt_user->execute([$username, $password_hash, $club_email, $club_id]);
            
            if ($result) {
                error_log("Usuario invitado creado: $username");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error creando usuario invitado $username: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene el teléfono del remitente (club organizador)
     */
    private static function getSenderPhone(int $club_responsable_id): ?string {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT telefono 
                FROM clubes 
                WHERE id = ?
            ");
            $stmt->execute([$club_responsable_id]);
            $club = $stmt->fetch();
            
            return $club['telefono'] ?? null;
        } catch (Exception $e) {
            error_log("Error obteniendo teléfono del remitente: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene el teléfono del receptor (club invitado)
     */
    private static function getReceiverPhone(int $club_id): ?string {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT telefono 
                FROM clubes 
                WHERE id = ?
            ");
            $stmt->execute([$club_id]);
            $club = $stmt->fetch();
            
            return $club['telefono'] ?? null;
        } catch (Exception $e) {
            error_log("Error obteniendo teléfono del receptor: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene la URL base del sistema
     */
    private static function getBaseUrl(): string {
        try {
            // Usar AppHelpers si está disponible
            if (function_exists('app_base_url')) {
                return app_base_url();
            }
            if (class_exists('AppHelpers')) {
                return AppHelpers::getBaseUrl();
            }
            
            // Fallback: construir URL manualmente
            require_once __DIR__ . '/../config/db.php';
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = str_replace('/lib', '', $_SERVER['SCRIPT_NAME'] ?? '');
            $path = dirname($path);
            return rtrim($protocol . '://' . $host . $path, '/');
        } catch (Exception $e) {
            // Fallback seguro: usar función app_base_url si está disponible
            if (function_exists('app_base_url')) {
                return app_base_url();
            }
            // Último fallback: detectar producción
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $folder = class_exists('FvdConfig') ? FvdConfig::APP_FOLDER : 'mistorneos_fvd';
            return $protocol . '://' . $host . '/' . $folder;
        }
    }
}
?>
