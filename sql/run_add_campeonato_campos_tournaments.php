<?php
/**
 * Añade columnas de campeonato a tournaments si no existen.
 * Ejecutar una vez: php sql/run_add_campeonato_campos_tournaments.php
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

$pdo = DB::pdo();
$cols = $pdo->query('SHOW COLUMNS FROM tournaments')->fetchAll(PDO::FETCH_COLUMN);

$alters = [];
if (!in_array('genero_requerido', $cols, true)) {
    $alters[] = "ADD COLUMN genero_requerido CHAR(1) NULL DEFAULT NULL COMMENT 'M o F' AFTER modalidad";
}
if (!in_array('edad_maxima', $cols, true)) {
    $after = in_array('genero_requerido', $cols, true) ? 'genero_requerido' : 'modalidad';
    $alters[] = "ADD COLUMN edad_maxima SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Edad max SUB' AFTER {$after}";
}
if (!in_array('campeonato_grupo', $cols, true)) {
    $alters[] = "ADD COLUMN campeonato_grupo VARCHAR(30) NULL DEFAULT NULL COMMENT 'MASCULINO, SUB 12, etc.' AFTER edad_maxima";
}

if ($alters === []) {
    echo "Columnas de campeonato ya presentes.\n";
    exit(0);
}

foreach ($alters as $fragment) {
    $pdo->exec('ALTER TABLE tournaments ' . $fragment);
    echo "OK: {$fragment}\n";
}

echo "Migración completada.\n";
