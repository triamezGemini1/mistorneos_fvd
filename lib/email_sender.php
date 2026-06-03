<?php


class EmailSender {
    private static $smtp_host = 'localhost';
    private static $smtp_port = 587;
    private static $smtp_username = '';
    private static $smtp_password = '';
    private static $from_email = 'noreply@mistorneos.com';
    private static $from_name = 'Sistema de Inscripciones - Mistorneos';

    /**
     * Env�a un correo de invitación a un club
     */
    public static function sendInvitationEmail(array $invitation_data): bool {
        try {
            $to_email = $invitation_data['club_email'];
            $to_name = $invitation_data['club_delegado'];
            $subject = "Invitación al Torneo: " . $invitation_data['tournament_name'];
            
            $message = self::generateInvitationMessage($invitation_data);
            
            return self::sendEmail($to_email, $to_name, $subject, $message);
        } catch (Exception $e) {
            error_log("Error enviando correo de invitación: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Genera el mensaje de invitación
     */
    private static function generateInvitationMessage(array $data): string {
        require_once __DIR__ . '/invitation_helpers.php';
        $login_url = InvitationHelpers::buildSimpleInvitationUrl((int)$data['torneo_id'], (int)$data['club_id']);
        
        $message = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Invitación al Torneo</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f8f9fa; }
                .credentials { background: #e8f5e8; padding: 15px; border-left: 4px solid #27ae60; margin: 20px 0; }
                .footer { background: #34495e; color: white; padding: 15px; text-align: center; font-size: 12px; }
                .btn { display: inline-block; background: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
                .btn:hover { background: #2980b9; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>?? Invitación al Torneo</h1>
                    <p>Sistema de Inscripciones - Mistorneos</p>
                </div>
                
                <div class='content'>
                    <p><strong>Apreciado: {$data['club_delegado']}</strong></p>
                    
                    <p>El <strong>{$data['organizer_club_name']}</strong> le invita a participar de nuestro magno evento:</p>
                    
                    <h2>?? {$data['tournament_name']}</h2>
                    
                    <p><strong>?? Fecha del torneo:</strong> {$data['tournament_date']}</p>
                    <p><strong>?? Club organizador:</strong> {$data['organizer_club_name']}</p>
                    <p><strong>?? Delegado organizador:</strong> {$data['organizer_delegado']}</p>
                    <p><strong>?? Teléfono del club invitado:</strong> {$data['club_telefono']}</p>
                    
                    <p>Se anexan datos para su acceso al sistema de inscripciones:</p>
                    
                    <div class='credentials'>
                        <h3>?? Datos de Acceso</h3>
                        <p><strong>URL de acceso:</strong> <a href='{$login_url}'>{$login_url}</a></p>
                        <p><strong>Usuario:</strong> {$data['usuario']}</p>
                        <p><strong>Contraseña:</strong> usuario</p>
                    </div>
                    
                    <p>Esperando su pronta y positiva respuesta se suscriben de usted:</p>
                    
                    <p><strong>Por la comisi�n de Domin• del {$data['organizer_club_name']}</strong></p>
                    <p><strong>{$data['organizer_delegado']}</strong></p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$login_url}' class='btn'>Acceder al Sistema de Inscripciones</a>
                    </div>
                    
                    <p><small><strong>Nota:</strong> Este enlace es personalizado para su club. Por favor, no comparta estas credenciales con otros clubes.</small></p>
                </div>
                
                <div class='footer'>
                    <p>• 2025 Sistema de Inscripciones - Mistorneos</p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return $message;
    }

    /**
     * Env�a un correo usando la función mail() de PHP
     */
    private static function sendEmail(string $to_email, string $to_name, string $subject, string $message): bool {
        try {
            // Headers para correo HTML
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: ' . self::$from_name . ' <' . self::$from_email . '>',
                'Reply-To: ' . self::$from_email,
                'X-Mailer: PHP/' . phpversion()
            ];
            
            $headers_string = implode("\r\n", $headers);
            
            // Enviar correo
            $result = mail($to_email, $subject, $message, $headers_string);
            
            if ($result) {
                error_log("Correo enviado exitosamente a: $to_email");
                return true;
            } else {
                error_log("Error al enviar correo a: $to_email");
                return false;
            }
        } catch (Exception $e) {
            error_log("Excepción al enviar correo: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene los datos completos de una invitación para el correo
     */
    public static function getInvitationDataForEmail(int $invitation_id): ?array {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT 
                    i.*,
                    t.nombre as tournament_name,
                    t.fechator as tournament_date,
                    t.club_responsable,
                    c.nombre as club_name,
                    c.delegado as club_delegado,
                    c.email as club_email,
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
     * Verifica si el correo se puede enviar
     */
    public static function canSendEmail(): bool {
        // Verificar si la función mail() est• disponible
        if (!function_exists('mail')) {
            return false;
        }
        
        // Verificar configuración básica
        $sendmail_path = ini_get('sendmail_path');
        if (empty($sendmail_path)) {
            return false;
        }
        
        return true;
    }
}
?>


