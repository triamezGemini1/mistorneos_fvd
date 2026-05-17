<?php
/**
 * Diagnóstico de conexión a Base de Datos
 * Acceder: https://laestaciondeldominohoy.com/mistorneos_fvd/public/diagnostico_db.php
 * 
 * IMPORTANTE: Eliminar este archivo después de resolver el problema
 */

header('Content-Type: text/html; charset=utf-8');

$projectRoot = realpath(__DIR__ . '/..');
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';

$diagnostico = [];
$diagnostico['archivo_env'] = [
    'existe' => file_exists($envPath),
    'ruta' => $envPath,
    'legible' => file_exists($envPath) ? is_readable($envPath) : false,
];

// Cargar bootstrap para obtener config
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/Env.php';

$cfg = $GLOBALS['APP_CONFIG']['db'] ?? [];
$diagnostico['config_usada'] = [
    'host' => Env::get('DB_HOST') ?: ($cfg['host'] ?? 'N/A'),
    'port' => Env::get('DB_PORT') ?: ($cfg['port'] ?? 'N/A'),
    'database' => Env::get('DB_DATABASE') ?: ($cfg['name'] ?? 'N/A'),
    'username' => Env::get('DB_USERNAME') ?: ($cfg['user'] ?? 'N/A'),
    'password_set' => !empty(Env::get('DB_PASSWORD') ?: ($cfg['pass'] ?? '')),
    'app_env' => Env::get('APP_ENV', 'N/A'),
];

// Probar conexiones con diferentes hosts
$hosts = ['localhost', '127.0.0.1'];
$user = $diagnostico['config_usada']['username'];
$pass = Env::get('DB_PASSWORD') ?: ($cfg['pass'] ?? '');
$db = $diagnostico['config_usada']['database'];
$port = $diagnostico['config_usada']['port'];

$resultados = [];
foreach ($hosts as $host) {
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $resultados[$host] = ['ok' => true, 'msg' => 'Conectado correctamente'];
    } catch (PDOException $e) {
        $resultados[$host] = ['ok' => false, 'msg' => $e->getMessage()];
    }
}
$diagnostico['pruebas_conexion'] = $resultados;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Diagnóstico BD</title>
    <style>
        body { font-family: system-ui; max-width: 700px; margin: 40px auto; padding: 20px; }
        h1 { color: #1a365d; }
        h2 { color: #2d3748; margin-top: 24px; }
        .box { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 12px 0; }
        .ok { color: #276749; }
        .fail { color: #c53030; }
        .tip { background: #ebf8ff; border-left: 4px solid #3182ce; padding: 12px; margin: 16px 0; }
        code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; }
        ul { margin: 8px 0; padding-left: 20px; }
    </style>
</head>
<body>
    <h1>Diagnóstico de Base de Datos</h1>
    
    <h2>1. Archivo .env</h2>
    <div class="box">
        <p><strong>Existe:</strong> <?= $diagnostico['archivo_env']['existe'] ? '<span class="ok">Sí</span>' : '<span class="fail">No</span>' ?></p>
        <p><strong>Legible:</strong> <?= $diagnostico['archivo_env']['legible'] ? '<span class="ok">Sí</span>' : '<span class="fail">No</span>' ?></p>
        <?php if (!$diagnostico['archivo_env']['existe']): ?>
        <p class="tip">Crea el archivo <code>.env</code> en <code>public_html/mistorneos_fvd/</code> copiando <code>.env.production.example</code> y completa <code>DB_USERNAME</code>, <code>DB_PASSWORD</code> y <code>DB_DATABASE</code> (cPanel → MySQL).</p>
        <?php endif; ?>
    </div>

    <h2>2. Configuración usada</h2>
    <div class="box">
        <p><strong>Host:</strong> <?= htmlspecialchars($diagnostico['config_usada']['host']) ?></p>
        <p><strong>Puerto:</strong> <?= htmlspecialchars($diagnostico['config_usada']['port']) ?></p>
        <p><strong>Base de datos:</strong> <?= htmlspecialchars($diagnostico['config_usada']['database']) ?></p>
        <p><strong>Usuario:</strong> <?= htmlspecialchars($diagnostico['config_usada']['username']) ?></p>
        <p><strong>Contraseña configurada:</strong> <?= $diagnostico['config_usada']['password_set'] ? '<span class="ok">Sí</span>' : '<span class="fail">No</span>' ?></p>
        <p><strong>Entorno:</strong> <?= htmlspecialchars($diagnostico['config_usada']['app_env']) ?></p>
    </div>

    <h2>3. Pruebas de conexión</h2>
    <?php foreach ($diagnostico['pruebas_conexion'] as $host => $r): ?>
    <div class="box">
        <p><strong><?= htmlspecialchars($host) ?>:</strong>
        <?php if ($r['ok']): ?>
            <span class="ok"><?= htmlspecialchars($r['msg']) ?></span>
        <?php else: ?>
            <span class="fail"><?= htmlspecialchars($r['msg']) ?></span>
        <?php endif; ?>
        </p>
    </div>
    <?php endforeach; ?>

    <?php if (!array_filter($diagnostico['pruebas_conexion'], fn($r) => $r['ok'])): ?>
    <div class="tip">
        <h3>Posibles soluciones para "Access denied":</h3>
        <ul>
            <li><strong>Contraseña incorrecta:</strong> Verifica en cPanel → MySQL® Databases que la contraseña del usuario <code><?= htmlspecialchars($user) ?></code> sea correcta. Puedes cambiarla y actualizar <code>DB_PASSWORD</code> en .env.</li>
            <li><strong>Usuario no asignado a la BD:</strong> En cPanel, asegúrate de que el usuario esté asignado a la base de datos <code><?= htmlspecialchars($db) ?></code> con privilegios (ALL PRIVILEGES o al menos SELECT, INSERT, UPDATE, DELETE).</li>
            <li><strong>Host diferente:</strong> Algunos proveedores usan otro host. Prueba en .env: <code>DB_HOST=127.0.0.1</code> en lugar de <code>localhost</code>, o consulta la documentación de tu hosting.</li>
            <li><strong>Caracteres especiales:</strong> Si la contraseña tiene caracteres como <code>#</code>, <code>$</code> o comillas, envuélvela entre comillas en .env: <code>DB_PASSWORD="tu_password"</code></li>
        </ul>
    </div>
    <?php endif; ?>

    <p><small>⚠️ Eliminar este archivo después de resolver el problema.</small></p>
</body>
</html>
