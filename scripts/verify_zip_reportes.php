<?php
$zip = $argv[1] ?? dirname(__DIR__) . '/dist/mistorneos_fvd_produccion_2026-05-17_030055.zip';
$z = new ZipArchive();
if ($z->open($zip) !== true) {
    fwrite(STDERR, "No abre: $zip\n");
    exit(1);
}
$files = [
    'modules/reportes_pago_usuarios.php',
    'lib/ReportePagoUsuarioService.php',
    'public/api/reporte_pago_admin.php',
    'public/assets/reportes-pago-usuarios.js',
    'modules/finances.php',
    'lib/LandingDataService.php',
];
foreach ($files as $f) {
    $c = $z->getFromName($f);
    echo $f . ': ' . ($c === false ? 'MISSING' : strlen($c) . ' bytes') . "\n";
    if ($f === 'modules/reportes_pago_usuarios.php' && $c !== false) {
        echo '  formFiltrosReportesPago: ' . (strpos($c, 'formFiltrosReportesPago') !== false ? 'YES' : 'NO') . "\n";
        echo '  modalRecibo: ' . (strpos($c, 'modalRecibo') !== false ? 'YES' : 'NO') . "\n";
    }
}
$z->close();
