<?php
/**
 * Script de verificación para producción
 * Acceder: https://laestaciondeldominohoy.com/mistorneos_fvd/public/verificar_produccion.php
 * 
 * IMPORTANTE: Eliminar o proteger este archivo después del deploy
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: text/html; charset=utf-8');

$base = function_exists('app_base_url') ? app_base_url() : 'N/A';
$env = $_ENV['APP_ENV'] ?? 'N/A';
$checks = [];

// 1. Conexión BD principal
try {
    $pdo = DB::pdo();
    $stmt = $pdo->query("SELECT 1");
    $dbName = $GLOBALS['APP_CONFIG']['db']['name'] ?? 'mistorneos_fvd';
    $checks['BD Principal (' . $dbName . ')'] = ['ok' => true, 'msg' => 'Conectado'];
} catch (Exception $e) {
    $dbName = $GLOBALS['APP_CONFIG']['db']['name'] ?? 'mistorneos_fvd';
    $checks['BD Principal (' . $dbName . ')'] = ['ok' => false, 'msg' => $e->getMessage()];
}

// 2. Conexión BD secundaria (fvdadmin)
try {
    $pdo2 = DB::pdoSecondary();
    $stmt = $pdo2->query("SELECT 1");
    $checks['BD Secundaria (fvdadmin)'] = ['ok' => true, 'msg' => 'Conectado'];
} catch (Exception $e) {
    $checks['BD Secundaria (fvdadmin)'] = ['ok' => false, 'msg' => $e->getMessage()];
}

// 3. Tabla persona en fvdadmin
try {
    $pdo2 = DB::pdoSecondary();
    $tables = ['persona', 'dbo_persona'];
    $found = false;
    foreach ($tables as $t) {
        try {
            $pdo2->query("SELECT 1 FROM `$t` LIMIT 1");
            $checks['Tabla persona'] = ['ok' => true, 'msg' => "Tabla '$t' existe"];
            $found = true;
            break;
        } catch (Exception $e) {}
    }
    if (!$found) {
        $checks['Tabla persona'] = ['ok' => false, 'msg' => 'No se encontró tabla persona ni dbo_persona'];
    }
} catch (Exception $e) {
    $checks['Tabla persona'] = ['ok' => false, 'msg' => $e->getMessage()];
}

// 4. Carpetas escribibles
foreach (['upload', 'uploads', 'storage/cache', 'logs'] as $dir) {
    $abs = dirname(__DIR__) . '/' . $dir;
    $writable = is_dir($abs) && is_writable($abs);
    $checks['Carpeta ' . $dir] = [
        'ok' => $writable,
        'msg' => $writable ? 'Existe y es escribible' : (is_dir($abs) ? 'Sin permiso de escritura' : 'No existe'),
    ];
}

// 5. vendor (PDF, Excel, mail)
$vendorOk = is_file(dirname(__DIR__) . '/vendor/autoload.php');
$checks['Composer vendor'] = [
    'ok' => $vendorOk,
    'msg' => $vendorOk ? 'autoload.php presente' : 'Ejecutar composer install --no-dev en el servidor',
];

// 6. URLs críticas
$urls = [
    'Landing SPA' => $base . '/public/landing-spa.php',
    'Login' => $base . '/public/login.php',
    'API Landing' => $base . '/public/api/landing_data.php',
    'API Search Persona' => $base . '/public/api/search_user_persona.php',
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verificación Producción</title>
    <style>
        body { font-family: system-ui; max-width: 800px; margin: 40px auto; padding: 20px; }
        h1 { color: #1a365d; }
        .check { padding: 10px; margin: 8px 0; border-radius: 8px; }
        .ok { background: #d1fae5; color: #065f46; }
        .fail { background: #fee2e2; color: #991b1b; }
        .url-list { background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .url-list a { display: block; margin: 5px 0; color: #2563eb; }
        code { background: #e5e7eb; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Verificación de Producción</h1>
    <p><strong>Entorno:</strong> <?= htmlspecialchars($env) ?> | <strong>Base URL:</strong> <code><?= htmlspecialchars($base) ?></code></p>
    
    <h2>Comprobaciones</h2>
    <?php foreach ($checks as $name => $r): ?>
    <div class="check <?= $r['ok'] ? 'ok' : 'fail' ?>">
        <strong><?= htmlspecialchars($name) ?>:</strong> <?= htmlspecialchars($r['msg']) ?>
    </div>
    <?php endforeach; ?>
    
    <h2>URLs a verificar (sin 404)</h2>
    <div class="url-list">
        <?php foreach ($urls as $label => $url): ?>
        <a href="<?= htmlspecialchars($url) ?>" target="_blank"><?= htmlspecialchars($label) ?> → <?= htmlspecialchars($url) ?></a>
        <?php endforeach; ?>
    </div>
    
    <p><small>⚠️ Eliminar o proteger este archivo después del deploy.</small></p>
</body>
</html>
