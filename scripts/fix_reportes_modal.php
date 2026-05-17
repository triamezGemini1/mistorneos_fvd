<?php
$path = dirname(__DIR__) . '/modules/reportes_pago_usuarios.php';
$lines = file($path);
$keep = array_slice($lines, 0, 427);
$tailLines = [
    '',
    '<!-- Modal recibo / ver registro -->',
    '<motion class="modal fade" id="modalRecibo" tabindex="-1">',
];
$tailLines[2] = '<div class="modal fade" id="modalRecibo" tabindex="-1">';
$tailLines = array_merge($tailLines, [
    '    <div class="modal-dialog modal-lg modal-dialog-centered">',
    '        <div class="modal-content">',
    '            <div class="modal-header bg-success text-white">',
    '                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Recibo / detalle del reporte</h5>',
    '                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>',
    '            </div>',
    '            <div class="modal-body" id="modalReciboBody"></div>',
    '            <div class="modal-footer">',
    '                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cerrar</button>',
    '                <button type="button" class="btn btn-success" id="btnReciboImprimir"><i class="fas fa-print me-1"></i>Imprimir</button>',
    '            </div>',
    '        </div>',
    '    </div>',
    '</div>',
    '',
    '<script>',
    'window.REPORTES_PAGO_CFG = {',
    '    apiUrl: <?= json_encode($rpu_api_url, JSON_UNESCAPED_UNICODE) ?>,',
    '    csrf: <?= json_encode($csrf_token, JSON_UNESCAPED_UNICODE) ?>',
    '};',
    '</script>',
    '<script src="<?= htmlspecialchars($rpu_asset_base) ?>/assets/reportes-pago-usuarios.js"></script>',
    '',
]);
file_put_contents($path, implode('', $keep) . implode("\n", $tailLines) . "\n");
echo "ok\n";
