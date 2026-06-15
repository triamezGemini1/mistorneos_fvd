<?php
/**
 * Cron de consolidación analítica web.
 *
 * Ejecutar diariamente (cPanel):
 *   php /ruta/public/modules/cron_analytics.php
 *
 * HTTP (proteger con clave):
 *   curl "https://tudominio.com/mistorneos_fvd/public/modules/cron_analytics.php?key=TU_CRON_SECRET"
 *
 * En .env: ANALYTICS_CRON_KEY=una_clave_secreta
 * Si no está definida, solo se permite ejecución por CLI.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/UmamiAnalyticsHelper.php';
require_once __DIR__ . '/../../lib/WebStatsService.php';

$isCli = (php_sapi_name() === 'cli');
$cronKey = trim((string) (getenv('ANALYTICS_CRON_KEY') ?: (class_exists('Env') ? Env::get('ANALYTICS_CRON_KEY', '') : '')));
$keyOk = ($cronKey !== '' && trim((string) ($_GET['key'] ?? '')) === $cronKey);

if (!$isCli && !$keyOk) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden';
    exit;
}

set_time_limit(600);

$log = static function (string $message) use ($isCli): void {
    $line = '[cron_analytics] ' . $message;
    if ($isCli) {
        echo $line . PHP_EOL;
    }
    error_log($line);
};

try {
    $pdo = WebStatsService::pdo();

    if (!WebStatsService::tablesReady($pdo)) {
        $log('Tablas stats_* no encontradas. Ejecute sql/create_stats_web_analytics_tables.sql en mistorneos_fvd.');
        exit(1);
    }

    $hoy_dia = (int) date('j');

    // ==========================================
    // PASO 1: FECHA A SINCRONIZAR (día anterior)
    // ==========================================
    $fecha_sync = date('Y-m-d', strtotime('-1 day'));
    $log('Paso 1 — Fecha a sincronizar: ' . $fecha_sync);

    // ==========================================
    // PASO 2: DETALLE DIARIO DESDE UMAMI
    // ==========================================
    $filasSync = WebStatsService::syncDayFromUmami($pdo, $fecha_sync);
    $log('Paso 2 — Filas sincronizadas en stats_detalle_diario: ' . $filasSync);

    // ==========================================
    // PASO 3: CIERRE Y CONCENTRACIÓN MENSUAL (Se ejecuta el día 1 de cada mes)
    // ==========================================
    if ($hoy_dia === 1) {
        $mes_anterior = date('Y-m', strtotime('-1 month'));
        $primer_dia_mes_ant = date('Y-m-01', strtotime('-1 month'));
        $ultimo_dia_mes_ant = date('Y-m-t', strtotime('-1 month'));

        $stmtMensualUrl = $pdo->prepare('
            INSERT INTO stats_historico_mensual_url (ano_mes, ruta, torneo_id, total_vistas, total_visitantes, tiempo_medio_seg)
            SELECT
                :ano_mes,
                ruta,
                torneo_id,
                SUM(vistas) AS total_vistas,
                SUM(visitantes_unicos) AS total_visitantes,
                AVG(tiempo_promedio_seg) AS tiempo_medio_seg
            FROM stats_detalle_diario
            WHERE fecha BETWEEN :inicio AND :fin
            GROUP BY ruta, torneo_id
        ');

        $stmtMensualUrl->execute([
            ':ano_mes' => $mes_anterior,
            ':inicio' => $primer_dia_mes_ant,
            ':fin' => $ultimo_dia_mes_ant,
        ]);

        $stmtLimpieza = $pdo->prepare('DELETE FROM stats_detalle_diario WHERE fecha BETWEEN :inicio AND :fin');
        $stmtLimpieza->execute([':inicio' => $primer_dia_mes_ant, ':fin' => $ultimo_dia_mes_ant]);

        $log(sprintf(
            'Paso 3 — Mes %s consolidado (%s → %s). URLs insertadas: %d. Filas diarias eliminadas: %d.',
            $mes_anterior,
            $primer_dia_mes_ant,
            $ultimo_dia_mes_ant,
            $stmtMensualUrl->rowCount(),
            $stmtLimpieza->rowCount()
        ));
    } else {
        $log('Paso 3 — Omitido (solo corre el día 1 del mes).');
    }

    $log('Finalizado OK.');
    exit(0);
} catch (Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
