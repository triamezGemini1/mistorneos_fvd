<?php
/**
 * Comprueba en el servidor si los archivos del último despliegue están presentes.
 * URL: .../public/verificar_despliegue_version.php
 * Eliminar tras validar.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/deploy_build.php';

header('Content-Type: text/html; charset=utf-8');

$checks = [
    'modules/reportes_pago_usuarios.php' => ['marker' => 'formFiltrosReportesPago', 'label' => 'Reportes pago (buscador + filtros)'],
    'lib/ReportePagoUsuarioService.php' => ['marker' => 'class ReportePagoUsuarioService', 'label' => 'Servicio reportes pago'],
    'public/api/reporte_pago_admin.php' => ['marker' => 'toggle_confirmado', 'label' => 'API admin reportes pago'],
    'public/assets/reportes-pago-usuarios.js' => ['marker' => 'REPORTES_PAGO_CFG', 'label' => 'JS reportes pago'],
    'public/api/finances_actualizar_deudas.php' => ['marker' => 'actualizar_deudas', 'label' => 'API finanzas actualizar deudas'],
    'modules/finances.php' => ['marker' => 'FINANCES_ACTUALIZAR_DEUDAS_URL', 'label' => 'Finanzas (URL API deudas)'],
    'lib/LandingDataService.php' => ['marker' => 'sqlWhereActivoConAlias', 'label' => 'Landing contador inscritos'],
    'modules/users.php' => ['marker' => 'sqlOrderUsuariosPorRol', 'label' => 'Usuarios orden por rol'],
];

$rows = [];
$allOk = true;
foreach ($checks as $rel => $meta) {
    $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ok = false;
    $detail = '';
    if (!is_file($path)) {
        $detail = 'Archivo no encontrado';
        $allOk = false;
    } else {
        $content = @file_get_contents($path);
        $hasMarker = is_string($content) && strpos($content, $meta['marker']) !== false;
        $size = filesize($path);
        $mtime = date('Y-m-d H:i:s', (int) filemtime($path));
        $ok = $hasMarker;
        $detail = ($hasMarker ? 'OK' : 'SIN MARCA') . " · {$size} bytes · {$mtime}";
        if (!$hasMarker) {
            $allOk = false;
        }
    }
    $rows[] = ['file' => $rel, 'label' => $meta['label'], 'ok' => $ok, 'detail' => $detail];
}

$indexPath = $root . '/index.php';
$publicIndex = $root . '/public/index.php';
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verificación despliegue FVD</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; }
        h1 { color: #1e3a5f; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        .ok { color: #065f46; font-weight: 600; }
        .fail { color: #991b1b; font-weight: 600; }
        .box { padding: 12px; border-radius: 8px; margin: 12px 0; }
        .box-ok { background: #d1fae5; }
        .box-fail { background: #fee2e2; }
        code { background: #eee; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
    </style>
</head>
<body>
    <h1>Verificación de despliegue</h1>
    <p><strong>Build esperado:</strong> <code><?= htmlspecialchars(FVD_DEPLOY_BUILD) ?></code></p>
    <p><strong>Raíz del proyecto (donde debe estar index.php):</strong><br><code><?= htmlspecialchars($root) ?></code></p>
    <p><strong>index.php raíz:</strong> <?= is_file($indexPath) ? '<span class="ok">presente</span>' : '<span class="fail">FALTA</span>' ?>
        · <strong>public/index.php:</strong> <?= is_file($publicIndex) ? '<span class="ok">presente</span>' : '<span class="fail">FALTA</span>' ?></p>

    <div class="box <?= $allOk ? 'box-ok' : 'box-fail' ?>">
        <?= $allOk
            ? 'Todos los archivos del parche reciente están en esta carpeta con el contenido esperado.'
            : 'Faltan archivos o el ZIP se extrajo en otra ruta (ej. carpeta anidada). Suba el parche o extraiga de nuevo en la raíz de mistorneos_fvd.' ?>
    </div>

    <?php if (is_array($opcache) && !empty($opcache['opcache_enabled'])): ?>
    <p class="fail"><strong>OPcache activo:</strong> si acaba de subir archivos y la tabla sigue en rojo, reinicie PHP-FPM o use «Limpiar caché» en cPanel.</p>
    <?php endif; ?>

    <table>
        <thead>
            <tr><th>Componente</th><th>Archivo</th><th>Estado</th><th>Detalle</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td><code><?= htmlspecialchars($r['file']) ?></code></td>
                <td class="<?= $r['ok'] ? 'ok' : 'fail' ?>"><?= $r['ok'] ? 'OK' : 'FALTA' ?></td>
                <td><?= htmlspecialchars($r['detail']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>URLs de prueba</h2>
    <ul>
        <li>Reportes de pago (admin): <code>index.php?page=reportes_pago_usuarios&amp;torneo_id=ID_TORNEO</code></li>
        <li>Finanzas: <code>index.php?page=finances&amp;torneo_id=ID_TORNEO</code></li>
    </ul>
    <p><small>Elimine este archivo cuando termine la verificación.</small></p>
</body>
</html>
