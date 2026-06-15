<?php

declare(strict_types=1);

/**
 * Configuración y consultas a Umami Cloud (panel admin + script público).
 */
final class UmamiAnalyticsHelper
{
    private const SCRIPT_URL = 'https://cloud.umami.is/script.js';
    private const WEBSITE_ID = '1e64a2c9-7c79-49f0-b6a2-6d8c761893d3';
    private const API_BASE = 'https://api.umami.is/v1';
    private const CLOUD_APP = 'https://cloud.umami.is';
    private const TIMEZONE = 'America/Caracas';

    public static function scriptUrl(): string
    {
        $fromEnv = self::readEnvCandidate('UMAMI_SCRIPT_URL');
        if ($fromEnv !== '' && self::isTrackingScriptUrl($fromEnv)) {
            return self::normalizeEnvUrl($fromEnv);
        }

        return self::SCRIPT_URL;
    }

    public static function websiteId(): string
    {
        return self::envString('UMAMI_WEBSITE_ID', self::WEBSITE_ID);
    }

    public static function apiKey(): string
    {
        return self::envString('UMAMI_API_KEY', '');
    }

    /**
     * URL para iframe en el panel (share, dashboard o analytics/…/websites/…).
     * Acepta UMAMI_SHARE_URL o, si UMAMI_SCRIPT_URL no es script.js, esa misma variable.
     */
    public static function shareUrl(): string
    {
        foreach (['UMAMI_SHARE_URL', 'UMAMI_SHARED_URL', 'UMAMI_SHARE_LINK', 'UMAMI_DASHBOARD_URL'] as $key) {
            $value = self::readEnvCandidate($key);
            if ($value !== '') {
                return self::normalizeDashboardUrl($value);
            }
        }

        $scriptEnv = self::readEnvCandidate('UMAMI_SCRIPT_URL');
        if ($scriptEnv !== '' && self::isDashboardOrShareUrl($scriptEnv)) {
            return self::normalizeDashboardUrl($scriptEnv);
        }

        return '';
    }

    /** @return array<string,mixed> */
    public static function configDiagnostics(): array
    {
        $share = self::shareUrl();
        $scriptEnv = self::readEnvCandidate('UMAMI_SCRIPT_URL');
        $envPath = defined('APP_ROOT') ? APP_ROOT . '/.env' : dirname(__DIR__) . '/.env';
        $shareSource = 'none';
        foreach (['UMAMI_SHARE_URL', 'UMAMI_SHARED_URL', 'UMAMI_SHARE_LINK', 'UMAMI_DASHBOARD_URL'] as $key) {
            if (self::readEnvCandidate($key) !== '') {
                $shareSource = $key;
                break;
            }
        }
        if ($shareSource === 'none' && $scriptEnv !== '' && self::isDashboardOrShareUrl($scriptEnv)) {
            $shareSource = 'UMAMI_SCRIPT_URL (dashboard)';
        }

        return [
            'share_url' => $share !== '',
            'share_source' => $shareSource,
            'api_key' => self::apiKey() !== '',
            'website_id' => self::websiteId() !== '',
            'script_url' => self::scriptUrl(),
            'script_env_is_dashboard' => $scriptEnv !== '' && self::isDashboardOrShareUrl($scriptEnv),
            'env_file' => is_file($envPath),
            'env_path' => $envPath,
            'share_preview' => $share !== '' ? (substr($share, 0, 56) . '…') : '',
        ];
    }

    public static function dashboardUrl(): string
    {
        return rtrim(self::CLOUD_APP, '/') . '/websites/' . rawurlencode(self::websiteId());
    }

    public static function isLocalEnvironment(): bool
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return in_array($remote, ['127.0.0.1', '::1'], true)
            || strpos($host, 'localhost') !== false;
    }

    public static function shouldTrack(): bool
    {
        return !self::isLocalEnvironment() && self::websiteId() !== '';
    }

    /** @return array{startMs:int,endMs:int,label:string,key:string} */
    public static function resolvePeriod(string $key): array
    {
        $endMs = (int) round(microtime(true) * 1000);
        $map = [
            '24h' => ['ms' => 86400000, 'label' => 'Últimas 24 horas'],
            '7d' => ['ms' => 7 * 86400000, 'label' => 'Últimos 7 días'],
            '30d' => ['ms' => 30 * 86400000, 'label' => 'Últimos 30 días'],
            '90d' => ['ms' => 90 * 86400000, 'label' => 'Últimos 90 días'],
        ];
        if (!isset($map[$key])) {
            $key = '7d';
        }

        return [
            'key' => $key,
            'label' => $map[$key]['label'],
            'startMs' => $endMs - $map[$key]['ms'],
            'endMs' => $endMs,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function fetchStats(int $startMs, int $endMs): ?array
    {
        return self::apiGet('/websites/' . rawurlencode(self::websiteId()) . '/stats', [
            'startAt' => $startMs,
            'endAt' => $endMs,
        ]);
    }

    /** @return list<array{x:string,y:int|float}>|null */
    public static function fetchMetrics(string $type, int $startMs, int $endMs, int $limit = 15): ?array
    {
        $data = self::apiGet('/websites/' . rawurlencode(self::websiteId()) . '/metrics', [
            'type' => self::normalizeMetricType($type),
            'startAt' => $startMs,
            'endAt' => $endMs,
            'limit' => $limit,
        ]);

        return is_array($data) ? $data : null;
    }

    /** @return list<array<string,mixed>>|null */
    public static function fetchMetricsExpanded(string $type, int $startMs, int $endMs, int $limit = 500): ?array
    {
        $data = self::apiGet('/websites/' . rawurlencode(self::websiteId()) . '/metrics/expanded', [
            'type' => self::normalizeMetricType($type),
            'startAt' => $startMs,
            'endAt' => $endMs,
            'limit' => $limit,
        ]);

        return is_array($data) ? $data : null;
    }

    /** @return list<array{x:string,y:int|float}>|null */
    public static function fetchMetricsWithFilter(
        string $type,
        int $startMs,
        int $endMs,
        array $filters,
        int $limit = 15
    ): ?array {
        $data = self::apiGet('/websites/' . rawurlencode(self::websiteId()) . '/metrics', [
            'type' => self::normalizeMetricType($type),
            'startAt' => $startMs,
            'endAt' => $endMs,
            'limit' => $limit,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
        ]);

        return is_array($data) ? $data : null;
    }

    /** @return array{0:int,1:int} Milisegundos [inicio, fin] para un día en America/Caracas */
    public static function dayBoundsMs(string $dateYmd): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $start = new DateTime($dateYmd . ' 00:00:00', $tz);
        $end = new DateTime($dateYmd . ' 23:59:59.999', $tz);

        return [
            (int) round($start->format('U.u') * 1000),
            (int) round($end->format('U.u') * 1000),
        ];
    }

    private static function normalizeMetricType(string $type): string
    {
        return $type === 'url' ? 'path' : $type;
    }

    /** @return array<string,mixed>|null */
    public static function fetchPageviews(int $startMs, int $endMs, string $unit = 'day'): ?array
    {
        return self::apiGet('/websites/' . rawurlencode(self::websiteId()) . '/pageviews', [
            'startAt' => $startMs,
            'endAt' => $endMs,
            'unit' => $unit,
            'timezone' => self::TIMEZONE,
        ]);
    }

    public static function metricValue(array $metric): int
    {
        return (int) ($metric['value'] ?? 0);
    }

    public static function metricChange(array $metric): ?int
    {
        if (!array_key_exists('change', $metric)) {
            return null;
        }

        return (int) $metric['change'];
    }

    public static function formatNumber($n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    /** @return array<string,mixed>|null */
    private static function apiGet(string $path, array $query): ?array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return null;
        }

        $url = rtrim(self::API_BASE, '/') . $path . '?' . http_build_query($query);
        $headers = [
            'Accept: application/json',
            'x-umami-api-key: ' . $apiKey,
        ];

        $body = self::httpGet($url, $headers);
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param list<string> $headers */
    private static function httpGet(string $url, array $headers): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($response === false || $code >= 400) {
                return null;
            }

            return (string) $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }

    private static function envString(string $key, string $default): string
    {
        foreach (self::envKeyCandidates($key) as $candidate) {
            $value = self::readEnvCandidate($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /** @return list<string> */
    private static function envKeyCandidates(string $key): array
    {
        $aliases = [
            'UMAMI_SHARE_URL' => ['UMAMI_SHARE_URL', 'UMAMI_SHARED_URL', 'UMAMI_SHARE_LINK'],
            'UMAMI_API_KEY' => ['UMAMI_API_KEY', 'UMAMI_APIKEY'],
            'UMAMI_SCRIPT_URL' => ['UMAMI_SCRIPT_URL'],
            'UMAMI_WEBSITE_ID' => ['UMAMI_WEBSITE_ID', 'UMAMI_WEBSITE_UUID'],
        ];

        $list = $aliases[$key] ?? [$key];

        return array_values(array_unique($list));
    }

    private static function readEnvCandidate(string $key): string
    {
        if (class_exists('Env', false)) {
            $value = trim((string) Env::get($key, ''));
            if ($value !== '') {
                return self::normalizeEnvUrl($value);
            }
        }

        foreach ([$_ENV[$key] ?? null, getenv($key), $_SERVER[$key] ?? null] as $raw) {
            if ($raw === false || $raw === null) {
                continue;
            }
            $value = self::normalizeEnvUrl((string) $raw);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function normalizeEnvUrl(string $value): string
    {
        return trim($value, " \t\n\r\0\x0B\"',;");
    }

    private static function normalizeDashboardUrl(string $url): string
    {
        return rtrim(self::normalizeEnvUrl($url), '/');
    }

    private static function isTrackingScriptUrl(string $url): bool
    {
        $url = self::normalizeEnvUrl($url);

        return preg_match('#/script\.js(\?.*)?$#i', $url) === 1;
    }

    private static function isDashboardOrShareUrl(string $url): bool
    {
        $url = self::normalizeEnvUrl($url);
        if ($url === '' || self::isTrackingScriptUrl($url)) {
            return false;
        }

        return preg_match('#/(share|websites|analytics)/#i', $url) === 1;
    }
}
