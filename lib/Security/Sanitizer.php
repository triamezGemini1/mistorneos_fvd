<?php



namespace Lib\Security;

/**
 * Input Sanitizer - Limpieza y sanitización de inputs del usuario
 * 
 * Previene:
 * - XSS (Cross-Site Scripting)
 * - SQL Injection (complemento a prepared statements)
 * - Command Injection
 * - Path Traversal
 * - HTML/JavaScript injection
 * 
 * @package Lib\Security
 * @version 1.0.0
 */
final class Sanitizer
{
    /**
     * Sanitiza string b�sico (remueve HTML/PHP tags)
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function string(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remover null bytes
        $value = str_replace("\0", '', $value);
        
        // Remover tags HTML/PHP
        $value = strip_tags($value);
        
        // Trim whitespace
        return trim($value);
    }

    /**
     * Sanitiza email
     * 
     * @param string|null $email
     * @return string|null
     */
    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return $email !== false ? strtolower(trim($email)) : null;
    }

    /**
     * Sanitiza URL
     * 
     * @param string|null $url
     * @return string|null
     */
    public static function url(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);
        
        // Validar que sea URL v�lida
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    /**
     * Sanitiza entero
     * 
     * @param mixed $value
     * @return int|null
     */
    public static function int($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    /**
     * Sanitiza float
     * 
     * @param mixed $value
     * @return float|null
     */
    public static function float($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }

    /**
     * Sanitiza boolean
     * 
     * @param mixed $value
     * @return bool
     */
    public static function bool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sanitiza HTML permitiendo tags específicos
     * 
     * @param string|null $html
     * @param array $allowedTags Tags permitidos (ej: ['p', 'a', 'strong'])
     * @return string|null
     */
    public static function html(?string $html, array $allowedTags = []): ?string
    {
        if ($html === null) {
            return null;
        }

        if (empty($allowedTags)) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Convertir array a string para strip_tags
        $allowedTagsStr = '<' . implode('><', $allowedTags) . '>';
        
        return strip_tags($html, $allowedTagsStr);
    }

    /**
     * Escapa HTML para output seguro
     * 
     * @param string|null $value
     * @return string
     */
    public static function escape(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escapa para atributos HTML
     * 
     * @param string|null $value
     * @return string
     */
    public static function escapeAttr(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Remover comillas y caracteres peligrosos
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escapa para JavaScript
     * 
     * @param string|null $value
     * @return string
     */
    public static function escapeJs(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Usar json_encode para escape seguro
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    /**
     * Sanitiza nombre de archivo
     * 
     * @param string|null $filename
     * @return string|null
     */
    public static function filename(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        // Remover path traversal attempts
        $filename = basename($filename);
        
        // Remover caracteres peligrosos
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        
        // Prevenir nombres de archivo vacíos
        if (empty($filename) || $filename === '.') {
            return null;
        }

        return $filename;
    }

    /**
     * Sanitiza path (previene path traversal)
     * 
     * @param string|null $path
     * @param string|null $basePath Path base permitido
     * @return string|null
     */
    public static function path(?string $path, ?string $basePath = null): ?string
    {
        if ($path === null) {
            return null;
        }

        // Remover null bytes
        $path = str_replace("\0", '', $path);
        
        // Resolver path real
        $realPath = realpath($path);
        
        if ($realPath === false) {
            return null;
        }

        // Si se especifica basePath, verificar que est• dentro
        if ($basePath !== null) {
            $realBasePath = realpath($basePath);
            if ($realBasePath === false || strpos($realPath, $realBasePath) !== 0) {
                return null;
            }
        }

        return $realPath;
    }

    /**
     * Sanitiza array recursivamente
     * 
     * @param array $array
     * @param string $type Tipo de sanitización ('string', 'int', 'email', etc.)
     * @return array
     */
    public static function array(array $array, string $type = 'string'): array
    {
        $sanitized = [];

        foreach ($array as $key => $value) {
            // Sanitizar key
            $safeKey = self::string($key);

            if (is_array($value)) {
                $sanitized[$safeKey] = self::array($value, $type);
            } else {
                switch ($type) {
                    case 'email': $sanitized[$safeKey] = self::email($value); break;
                    case 'url': $sanitized[$safeKey] = self::url($value); break;
                    case 'int': $sanitized[$safeKey] = self::int($value); break;
                    case 'float': $sanitized[$safeKey] = self::float($value); break;
                    case 'bool': $sanitized[$safeKey] = self::bool($value); break;
                    case 'html': $sanitized[$safeKey] = self::html($value); break;
                    default: $sanitized[$safeKey] = self::string($value);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Limpia input SQL (SIEMPRE usar prepared statements, esto es capa adicional)
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function sql(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remover null bytes
        $value = str_replace("\0", '', $value);
        
        // Remover caracteres de control
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return trim($value);
    }

    /**
     * Sanitiza input para comandos shell (PELIGROSO, evitar usar shell_exec)
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function shell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return escapeshellarg($value);
    }

    /**
     * Limpia y valida cédula/DNI/RUT
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function document(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remover todo excepto números y guiones
        $value = preg_replace('/[^0-9\-]/', '', $value);
        
        return trim($value) ?: null;
    }

    /**
     * Limpia número de teléfono
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remover todo excepto números, +, espacios y guiones
        $value = preg_replace('/[^0-9+\-\s()]/', '', $value);
        
        return trim($value) ?: null;
    }

    /**
     * Sanitiza código postal
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function zipCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Solo alfanum�ricos y guiones
        $value = preg_replace('/[^a-zA-Z0-9\-]/', '', $value);
        
        return strtoupper(trim($value)) ?: null;
    }

    /**
     * Sanitiza fecha (formato YYYY-MM-DD)
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = date_create_from_format('Y-m-d', $value);
        
        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * Sanitiza JSON
     * 
     * @param string|null $json
     * @return array|null
     */
    public static function json(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Sanitiza slug (para URLs amigables)
     * 
     * @param string|null $value
     * @return string|null
     */
    public static function slug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Convertir a min�sculas
        $value = strtolower($value);
        
        // Remover acentos
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        
        // Solo letras, números y guiones
        $value = preg_replace('/[^a-z0-9\-]/', '-', $value);
        
        // Remover múltiples guiones consecutivos
        $value = preg_replace('/-+/', '-', $value);
        
        // Trim guiones
        $value = trim($value, '-');
        
        return $value ?: null;
    }

    /**
     * Sanitiza entrada completa de request
     * 
     * @param array $input
     * @param array $rules ['field' => 'type', ...]
     * @return array
     */
    public static function sanitizeRequest(array $input, array $rules): array
    {
        $sanitized = [];

        foreach ($rules as $field => $type) {
            if (!isset($input[$field])) {
                continue;
            }

            $value = $input[$field];

            switch ($type) {
                case 'email': $sanitized[$field] = self::email($value); break;
                case 'url': $sanitized[$field] = self::url($value); break;
                case 'int': $sanitized[$field] = self::int($value); break;
                case 'float': $sanitized[$field] = self::float($value); break;
                case 'bool': $sanitized[$field] = self::bool($value); break;
                case 'html': $sanitized[$field] = self::html($value); break;
                case 'filename': $sanitized[$field] = self::filename($value); break;
                case 'document': $sanitized[$field] = self::document($value); break;
                case 'phone': $sanitized[$field] = self::phone($value); break;
                case 'date': $sanitized[$field] = self::date($value); break;
                case 'slug': $sanitized[$field] = self::slug($value); break;
                case 'array': $sanitized[$field] = is_array($value) ? self::array($value) : []; break;
                default: $sanitized[$field] = self::string($value); break;
            }
        }

        return $sanitized;
    }
}









