<?php
/**
 * Recalcula estadísticas + ptosrnk de todos los torneos con ranking = 1 (incluye en curso).
 * Uso: php scripts/recalcular_torneos_ranking_cli.php
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../config/db_config.php';
require __DIR__ . '/../lib/RankingNumfvdAdminService.php';

$svc = new RankingNumfvdAdminService(DB::pdo());
$lista = $svc->listarTorneosConRanking(false);
echo 'Torneos con ranking = 1: ' . count($lista) . PHP_EOL;

$res = $svc->recalcularEstadisticasTodosTorneosRanking();
echo 'Procesados: ' . (int) ($res['procesados'] ?? 0) . PHP_EOL;
echo 'Fallidos: ' . (int) ($res['fallidos'] ?? 0) . PHP_EOL;

foreach ($res['torneos'] ?? [] as $t) {
    $ok = ! empty($t['ok']) ? 'OK' : 'ERR';
    echo "  [{$ok}] #" . (int) ($t['id'] ?? 0) . ' ' . ($t['nombre'] ?? '');
    if (! empty($t['error'])) {
        echo ' — ' . $t['error'];
    }
    echo PHP_EOL;
}

exit(empty($res['ok']) && (int) ($res['procesados'] ?? 0) === 0 ? 1 : 0);
