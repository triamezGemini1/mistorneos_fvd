<?php
/**
 * Migración: activo_mesa en inscritos + tabla equipo_sustituciones.
 * Ejecutar una vez: php sql/run_add_activo_mesa_inscritos.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';

$pdo = DB::pdo();
$isWeb = PHP_SAPI !== 'cli';

function out(string $msg, string $type = 'info'): void
{
    global $isWeb;
    if ($isWeb) {
        $colors = ['info' => '#333', 'ok' => 'green', 'warn' => '#856404', 'err' => 'red'];
        echo '<p style="color:' . ($colors[$type] ?? '#333') . '">' . htmlspecialchars($msg) . '</p>';
    } else {
        echo $msg . PHP_EOL;
    }
}

try {
    $cols = $pdo->query('SHOW COLUMNS FROM inscritos')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('activo_mesa', $cols, true)) {
        $pdo->exec(
            "ALTER TABLE inscritos ADD COLUMN activo_mesa TINYINT(1) NOT NULL DEFAULT 1
             COMMENT '1=titular mesas, 0=banca' AFTER codigo_equipo"
        );
        out('Columna inscritos.activo_mesa creada.', 'ok');
    } else {
        out('Columna inscritos.activo_mesa ya existe.', 'warn');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS equipo_sustituciones (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            torneo_id INT UNSIGNED NOT NULL,
            codigo_equipo VARCHAR(32) NOT NULL,
            id_usuario_sale INT UNSIGNED NOT NULL,
            id_usuario_entra INT UNSIGNED NOT NULL,
            registrado_por INT UNSIGNED NULL,
            observacion VARCHAR(500) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_es_torneo_equipo (torneo_id, codigo_equipo),
            KEY idx_es_torneo_fecha (torneo_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    out('Tabla equipo_sustituciones OK.', 'ok');
    out('Migración completada.', 'ok');
} catch (Throwable $e) {
    out('Error: ' . $e->getMessage(), 'err');
    exit(1);
}
