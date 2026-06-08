<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/lib/ReporteSancionesPorRondaService.php';
require_once dirname(__DIR__) . '/lib/NumfvdHelper.php';

$pdo = DB::pdo();
$reporte = (new ReporteSancionesPorRondaService())->construirReporte(1, $pdo);
echo 'Total jugadores en reporte: ' . $reporte['total'] . PHP_EOL;
$found211 = false;
foreach ($reporte['filas'] as $f) {
    if (trim((string)($f['numfvd'] ?? '')) === '211') {
        $found211 = true;
        echo '211 EN REPORTE: ' . json_encode($f, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
if (!$found211) {
    echo "NUMFVD 211 NO aparece en reporte (correcto).\n";
}
