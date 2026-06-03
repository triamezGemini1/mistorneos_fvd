<?php


// Cargar PHPMailer
require_once __DIR__ . '/../vendor/autoload.php';

class PHPMailerSender {
    private static $smtp_host = 'localhost';
    private static $smtp_port = 25;
    private static $smtp_username = ''; // No necesario para SMTP local
    private static $smtp_password = ''; // No necesario para SMTP local
    private static $from_email = 'noreply@mistorneos.com';
    private static $from_name = 'Sistema de Inscripciones - Mistorneos';

    /**
     * Env�a un correo de invitación usando PHPMailer
     */
    public static function sendInvitationEmail(array $invitation_data): array {
        try {
            // Verificar si PHPMailer est• disponible
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                return ['success' => false, 'error' => 'PHPMailer no est• instalado'];
            }

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            // Configuración del servidor SMTP
            $mail->isSMTP();
            $mail->Host = self::$smtp_host;
            $mail->SMTPAuth = !empty(self::$smtp_username); // Solo autenticar si hay credenciales
            if (!empty(self::$smtp_username)) {
                $mail->Username = self::$smtp_username;
                $mail->Password = self::$smtp_password;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Port = self::$smtp_port;
            $mail->CharSet = 'UTF-8';

            // Configuración del remitente
            $mail->setFrom(self::$from_email, self::$from_name);
            $mail->addReplyTo(self::$from_email, self::$from_name);

            // Configuración del destinatario
            $to_email = $invitation_data['club_email'];
            $to_name = $invitation_data['club_delegado'] ?? 'Delegado del Club';
            $mail->addAddress($to_email, $to_name);

            // Contenido del correo
            $mail->isHTML(true);
            $mail->Subject = "Invitación al Torneo: " . $invitation_data['tournament_name'];
            
            $message = self::generateInvitationMessage($invitation_data);
            $mail->Body = $message;
            $mail->AltBody = self::generatePlainTextMessage($invitation_data);

            // Enviar correo
            $mail->send();
            
            return ['success' => true, 'message' => 'Correo enviado exitosamente'];
            
        } catch (Exception $e) {
            error_log("Error PHPMailer: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Genera el mensaje HTML de invitación
     */
    private static function generateInvitationMessage(array $data): string {
        require_once __DIR__ . '/invitation_helpers.php';
        $login_url = InvitationHelpers::buildSimpleInvitationUrl((int)$data['torneo_id'], (int)$data['club_id']);
        
        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Invitación al Torneo</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; background: #fff; }
                .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f8f9fa; }
                .credentials { background: #e8f5e8; padding: 15px; border-left: 4px solid #27ae60; margin: 20px 0; }
                .footer { background: #34495e; color: white; padding: 15px; text-align: center; font-size: 12px; }
                .btn { display: inline-block; background: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
                .btn:hover { background: #2980b9; }
                .highlight { background: #fff3cd; padding: 10px; border-radius: 5px; margin: 15px 0; }
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
                    
                    <div class='highlight'>
                        <p><strong>?? Fecha del torneo:</strong> {$data['tournament_date']}</p>
                        <p><strong>?? Club organizador:</strong> {$data['organizer_club_name']}</p>
                        <p><strong>?? Delegado organizador:</strong> {$data['organizer_delegado']}</p>
                        <p><strong>?? Teléfono del club invitado:</strong> {$data['club_telefono']}</p>
                    </div>
                    
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
    }

    /**
     * Genera el mensaje en texto plano
     */
    private static function generatePlainTextMessage(array $data): string {
        require_once __DIR__ . '/invitation_helpers.php';
        $login_url = InvitationHelpers::buildSimpleInvitationUrl((int)$data['torneo_id'], (int)$data['club_id']);
        
        return "
INVITACIÓN AL TORNEO
Sistema de Inscripciones - Mistorneos

Apreciado: {$data['club_delegado']}

El {$data['organizer_club_name']} le invita a participar de nuestro magno evento:

{$data['tournament_name']}

INFORMACIÓN DEL EVENTO:
- Fecha del torneo: {$data['tournament_date']}
- Club organizador: {$data['organizer_club_name']}
- Delegado organizador: {$data['organizer_delegado']}
- Teléfono del club invitado: {$data['club_telefono']}

DATOS DE ACCESO:
- URL de acceso: {$login_url}
- Usuario: {$data['usuario']}
- Contraseña: usuario

Esperando su pronta y positiva respuesta se suscriben de usted:

Por la comisi�n de Domin• del {$data['organizer_club_name']}
{$data['organizer_delegado']}

---
• 2025 Sistema de Inscripciones - Mistorneos
Este es un correo automático, por favor no responda a este mensaje.
        ";
    }

    /**
     * Verifica si PHPMailer est• disponible
     */
    public static function isAvailable(): bool {
        return class_exists('PHPMailer\PHPMailer\PHPMailer');
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
                    c.telefono as club_telefono,
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
}
?>
