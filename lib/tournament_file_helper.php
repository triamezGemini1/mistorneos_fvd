<?php


require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/environment.php';

class TournamentFileHelper {
    
    /**
     * Obtiene las URLs de los archivos de un torneo
     */
    public static function getTournamentFileUrls(int $tournament_id): array {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT invitacion, normas, afiche 
                FROM tournaments 
                WHERE id = ? AND estatus = 1
            ");
            $stmt->execute([$tournament_id]);
            $tournament = $stmt->fetch();
            
            if (!$tournament) {
                return [
                    'invitation_file_url' => '',
                    'norms_file_url' => '',
                    'poster_file_url' => ''
                ];
            }
            
            return [
                'invitation_file_url' => self::buildFileUrl($tournament['invitacion']),
                'norms_file_url' => self::buildFileUrl($tournament['normas']),
                'poster_file_url' => self::buildFileUrl($tournament['afiche'])
            ];
            
        } catch (Exception $e) {
            error_log("Error obteniendo archivos del torneo: " . $e->getMessage());
            return [
                'invitation_file_url' => '',
                'norms_file_url' => '',
                'poster_file_url' => ''
            ];
        }
    }
    
    /**
     * Construye la URL completa de un archivo
     */
    private static function buildFileUrl(?string $file_path): string {
        if (empty($file_path)) {
            return '';
        }
        
        // Si ya es una URL completa, devolverla
        if (strpos($file_path, 'http') === 0) {
            return $file_path;
        }
        
        // Limpiar la ruta
        $clean_path = ltrim($file_path, '/');
        
        // Si ya incluye 'upload/tournaments/', usar solo la parte relativa
        if (strpos($clean_path, 'upload/tournaments/') === 0) {
            $clean_path = substr($clean_path, 18); // Remover 'upload/tournaments/'
        }
        
        // Limpiar barras adicionales
        $clean_path = ltrim($clean_path, '/');
        
        // Obtener la URL base de la aplicación
        $base_url = self::getAppBaseUrl();
        
        return AppHelpers::tournamentFile($clean_path);
    }
    
    /**
     * Obtiene la URL base de la aplicación
     */
    private static function getAppBaseUrl(): string {
        // Usar la función global del sistema
        if (function_exists('app_base_url')) {
            return app_base_url();
        }
        
        // Fallback para compatibilidad
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Limpiar el host (remover punto extra si existe)
        $host = rtrim($host, '.');
        
        // Construir la URL base
        $folder = class_exists('FvdConfig') ? FvdConfig::APP_FOLDER : 'mistorneos_fvd';
        return $protocol . '://' . $host . '/' . $folder;
    }
    
    /**
     * Verifica si un archivo existe f�sicamente
     */
    public static function fileExists(string $file_path): bool {
        if (empty($file_path)) {
            return false;
        }
        
        $physical_path = __DIR__ . '/../' . ltrim($file_path, '/');
        return file_exists($physical_path);
    }
    
    /**
     * Obtiene información detallada de un archivo
     */
    public static function getFileInfo(string $file_path): array {
        if (empty($file_path)) {
            return [
                'exists' => false,
                'size' => 0,
                'extension' => '',
                'url' => ''
            ];
        }
        
        $physical_path = __DIR__ . '/../' . ltrim($file_path, '/');
        
        return [
            'exists' => file_exists($physical_path),
            'size' => file_exists($physical_path) ? filesize($physical_path) : 0,
            'extension' => strtolower(pathinfo($file_path, PATHINFO_EXTENSION)),
            'url' => self::buildFileUrl($file_path)
        ];
    }
}
?>
