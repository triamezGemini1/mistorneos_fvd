<?php

declare(strict_types=1);

/**
 * Caché de datos para páginas públicas de resultados (evento_resultados.php).
 * APCu + JSON en storage/cache; TTL corto para equilibrio frescura/rendimiento.
 */
final class ResultadosPublicCache
{
    public const TTL_SECONDS = 120;

    public static function buildKey(int $torneoId, string $vista, ?string $genero, int $pagina): string
    {
        $gen = $genero ?? 'T';
        $vista = preg_replace('/[^a-z0-9_]/', '', strtolower($vista)) ?: 'general';

        return 'resultados_public_v1_' . hash('sha256', json_encode([$torneoId, $vista, $gen, $pagina]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        if (function_exists('apcu_fetch')) {
            $v = apcu_fetch($key);
            if (is_array($v) && isset($v['exp'], $v['payload']) && $v['exp'] >= time()) {
                return $v['payload'];
            }
        }

        $file = self::cacheFile($key);
        if (!is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta) || !isset($meta['exp'], $meta['payload']) || $meta['exp'] < time()) {
            return null;
        }

        return $meta['payload'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function set(string $key, array $payload): void
    {
        $wrapped = ['exp' => time() + self::TTL_SECONDS, 'payload' => $payload];
        if (function_exists('apcu_store')) {
            @apcu_store($key, $wrapped, self::TTL_SECONDS + 5);
        }
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::cacheFile($key), json_encode($wrapped), LOCK_EX);
    }

    private static function cacheDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    }

    private static function cacheFile(string $key): string
    {
        return self::cacheDir() . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
