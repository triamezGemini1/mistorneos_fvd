<?php

declare(strict_types=1);

require_once __DIR__ . '/UmamiAnalyticsHelper.php';

/**
 * Persistencia local de estadísticas web (detalle diario + histórico mensual por URL).
 * Usa la BD principal mistorneos_fvd.
 */
final class WebStatsService
{
    /** @var PDO|null */
    private static $pdoCache = null;

    public static function pdo(): PDO
    {
        if (self::$pdoCache instanceof PDO) {
            return self::$pdoCache;
        }

        self::$pdoCache = DB::pdo();

        return self::$pdoCache;
    }

    public static function tablesReady(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'stats_detalle_diario'");

            return (bool) $stmt->fetch(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Sincroniza un día desde Umami hacia stats_detalle_diario.
     *
     * @return int Filas insertadas/actualizadas
     */
    public static function syncDayFromUmami(PDO $pdo, string $fecha): int
    {
        if (!self::tablesReady($pdo) || UmamiAnalyticsHelper::apiKey() === '') {
            return 0;
        }

        [$startMs, $endMs] = UmamiAnalyticsHelper::dayBoundsMs($fecha);
        $paths = UmamiAnalyticsHelper::fetchMetrics('path', $startMs, $endMs, 500);
        if (!is_array($paths) || $paths === []) {
            return 0;
        }

        $stmt = $pdo->prepare('
            INSERT INTO stats_detalle_diario (
                fecha, ruta, torneo_id, dispositivo, pais, vistas, visitantes_unicos, tiempo_promedio_seg
            ) VALUES (
                :fecha, :ruta, :torneo_id, :dispositivo, :pais, :vistas, :visitantes, :tiempo
            )
            ON DUPLICATE KEY UPDATE
                vistas = VALUES(vistas),
                visitantes_unicos = VALUES(visitantes_unicos),
                tiempo_promedio_seg = VALUES(tiempo_promedio_seg)
        ');

        $rows = 0;
        foreach ($paths as $pathRow) {
            $ruta = self::normalizeRuta((string) ($pathRow['x'] ?? ''));
            if ($ruta === '') {
                continue;
            }

            $vistas = max(0, (int) ($pathRow['y'] ?? 0));
            if ($vistas === 0) {
                continue;
            }

            $torneoId = self::extractTorneoId($ruta);
            $breakdown = self::fetchPathBreakdown($ruta, $startMs, $endMs, $vistas);
            foreach ($breakdown as $item) {
                $stmt->execute([
                    ':fecha' => $fecha,
                    ':ruta' => $ruta,
                    ':torneo_id' => $torneoId,
                    ':dispositivo' => $item['dispositivo'],
                    ':pais' => $item['pais'],
                    ':vistas' => $item['vistas'],
                    ':visitantes' => $item['visitantes'],
                    ':tiempo' => $item['tiempo'],
                ]);
                ++$rows;
            }
        }

        return $rows;
    }

    /**
     * Cierre mensual: concentra el mes anterior en stats_historico_mensual_url y limpia detalle diario.
     *
     * @return array{mes:string,insertadas:int,eliminadas:int}
     */
    public static function consolidatePreviousMonth(PDO $pdo): array
    {
        $mesAnterior = date('Y-m', strtotime('-1 month'));
        $primerDia = date('Y-m-01', strtotime('-1 month'));
        $ultimoDia = date('Y-m-t', strtotime('-1 month'));

        $stmtMensualUrl = $pdo->prepare('
            INSERT INTO stats_historico_mensual_url (ano_mes, ruta, torneo_id, total_vistas, total_visitantes, tiempo_medio_seg)
            SELECT
                :ano_mes,
                ruta,
                torneo_id,
                SUM(vistas) AS total_vistas,
                SUM(visitantes_unicos) AS total_visitantes,
                ROUND(AVG(tiempo_promedio_seg)) AS tiempo_medio_seg
            FROM stats_detalle_diario
            WHERE fecha BETWEEN :inicio AND :fin
            GROUP BY ruta, torneo_id
            ON DUPLICATE KEY UPDATE
                total_vistas = VALUES(total_vistas),
                total_visitantes = VALUES(total_visitantes),
                tiempo_medio_seg = VALUES(tiempo_medio_seg)
        ');
        $stmtMensualUrl->execute([
            ':ano_mes' => $mesAnterior,
            ':inicio' => $primerDia,
            ':fin' => $ultimoDia,
        ]);
        $insertadas = $stmtMensualUrl->rowCount();

        $stmtLimpieza = $pdo->prepare('DELETE FROM stats_detalle_diario WHERE fecha BETWEEN :inicio AND :fin');
        $stmtLimpieza->execute([':inicio' => $primerDia, ':fin' => $ultimoDia]);
        $eliminadas = $stmtLimpieza->rowCount();

        return [
            'mes' => $mesAnterior,
            'insertadas' => $insertadas,
            'eliminadas' => $eliminadas,
        ];
    }

    /**
     * Desglose por URL para el panel admin.
     *
     * @return list<array{ruta:string,torneo_id:?int,total_vistas:int,total_visitantes:int,tiempo_medio_seg:int}>
     */
    public static function fetchUrlBreakdown(PDO $pdo, string $mes): array
    {
        if (!self::tablesReady($pdo)) {
            return [];
        }

        $mesActual = date('Y-m');
        if ($mes === $mesActual || $mes === 'actual' || $mes === '') {
            $inicio = date('Y-m-01');
            $fin = date('Y-m-d');
            $stmt = $pdo->prepare('
                SELECT
                    ruta,
                    torneo_id,
                    SUM(vistas) AS total_vistas,
                    SUM(visitantes_unicos) AS total_visitantes,
                    ROUND(AVG(tiempo_promedio_seg)) AS tiempo_medio_seg
                FROM stats_detalle_diario
                WHERE fecha BETWEEN :inicio AND :fin
                GROUP BY ruta, torneo_id
                ORDER BY total_vistas DESC
                LIMIT 200
            ');
            $stmt->execute([':inicio' => $inicio, ':fin' => $fin]);
        } else {
            $stmt = $pdo->prepare('
                SELECT
                    ruta,
                    torneo_id,
                    total_vistas,
                    total_visitantes,
                    tiempo_medio_seg
                FROM stats_historico_mensual_url
                WHERE ano_mes = :mes
                ORDER BY total_vistas DESC
                LIMIT 200
            ');
            $stmt->execute([':mes' => $mes]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /** @return list<string> Meses YYYY-MM disponibles (histórico + mes actual) */
    public static function listAvailableMonths(PDO $pdo): array
    {
        if (!self::tablesReady($pdo)) {
            return [date('Y-m')];
        }

        $months = [date('Y-m')];
        try {
            $stmt = $pdo->query('
                SELECT DISTINCT ano_mes
                FROM stats_historico_mensual_url
                ORDER BY ano_mes DESC
                LIMIT 24
            ');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $m = trim((string) ($row['ano_mes'] ?? ''));
                if ($m !== '' && !in_array($m, $months, true)) {
                    $months[] = $m;
                }
            }
        } catch (Throwable $e) {
            // Sin histórico aún
        }

        rsort($months);

        return array_values(array_unique($months));
    }

    public static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;
        if ($minutes === 0) {
            return $secs . 's';
        }

        return $minutes . 'm ' . str_pad((string) $secs, 2, '0', STR_PAD_LEFT) . 's';
    }

    public static function formatMonthLabel(string $mes): string
    {
        $mesActual = date('Y-m');
        if ($mes === $mesActual) {
            return 'Mes en curso (' . self::monthName($mes) . ')';
        }

        return self::monthName($mes);
    }

    public static function extractTorneoId(string $ruta): ?int
    {
        if (preg_match('/(?:torneo_id|torneo|id)=([0-9]+)/i', $ruta, $m)) {
            $id = (int) $m[1];

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @return list<array{dispositivo:string,pais:string,vistas:int,visitantes:int,tiempo:int}>
     */
    private static function fetchPathBreakdown(string $ruta, int $startMs, int $endMs, int $totalVistas): array
    {
        $devices = UmamiAnalyticsHelper::fetchMetricsWithFilter('device', $startMs, $endMs, ['path' => $ruta], 10);
        $countries = UmamiAnalyticsHelper::fetchMetricsWithFilter('country', $startMs, $endMs, ['path' => $ruta], 20);

        if (!is_array($devices) || $devices === []) {
            return [[
                'dispositivo' => 'desktop',
                'pais' => '--',
                'vistas' => $totalVistas,
                'visitantes' => $totalVistas,
                'tiempo' => 0,
            ]];
        }

        $rows = [];
        foreach ($devices as $deviceRow) {
            $deviceRaw = (string) ($deviceRow['x'] ?? '');
            $deviceViews = max(0, (int) ($deviceRow['y'] ?? 0));
            if ($deviceViews === 0) {
                continue;
            }

            $dispositivo = self::normalizeDevice($deviceRaw);
            if (!is_array($countries) || $countries === []) {
                $rows[] = [
                    'dispositivo' => $dispositivo,
                    'pais' => '--',
                    'vistas' => $deviceViews,
                    'visitantes' => $deviceViews,
                    'tiempo' => 0,
                ];
                continue;
            }

            $countryTotal = 0;
            foreach ($countries as $countryRow) {
                $countryTotal += max(0, (int) ($countryRow['y'] ?? 0));
            }
            $countryTotal = max(1, $countryTotal);

            foreach ($countries as $countryRow) {
                $pais = self::normalizePais((string) ($countryRow['x'] ?? ''));
                $countryViews = max(0, (int) ($countryRow['y'] ?? 0));
                if ($countryViews === 0) {
                    continue;
                }
                $share = $countryViews / $countryTotal;
                $rows[] = [
                    'dispositivo' => $dispositivo,
                    'pais' => $pais,
                    'vistas' => max(1, (int) round($deviceViews * $share)),
                    'visitantes' => max(1, (int) round($deviceViews * $share)),
                    'tiempo' => 0,
                ];
            }
        }

        return $rows !== [] ? $rows : [[
            'dispositivo' => 'desktop',
            'pais' => '--',
            'vistas' => $totalVistas,
            'visitantes' => $totalVistas,
            'tiempo' => 0,
        ]];
    }

    private static function normalizeRuta(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return substr($path, 0, 255);
    }

    private static function normalizeDevice(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return str_contains($raw, 'mobile') || str_contains($raw, 'tablet') ? 'mobile' : 'desktop';
    }

    private static function normalizePais(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '' || strlen($raw) > 2) {
            return '--';
        }

        return $raw;
    }

    private static function monthName(string $mes): string
    {
        $dt = DateTime::createFromFormat('Y-m', $mes);
        if (!$dt) {
            return $mes;
        }
        $names = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($names[(int) $dt->format('n')] ?? $mes) . ' ' . $dt->format('Y');
    }
}
