<?php
/**
 * Añade partiresul.numfvd y rellena desde inscritos (idempotente).
 */
require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../config/db.php';

$pdo = DB::pdo();
$exists = (bool) $pdo->query("SHOW COLUMNS FROM partiresul LIKE 'numfvd'")->fetch();
if (!$exists) {
    $pdo->exec(
        "ALTER TABLE partiresul ADD COLUMN numfvd int UNSIGNED DEFAULT NULL
         COMMENT 'NUMFVD del inscrito (inscritos.numfvd)' AFTER id_usuario"
    );
    echo "Columna numfvd creada.\n";
} else {
    echo "Columna numfvd ya existía.\n";
}

$pdo->exec(
    'UPDATE partiresul pr
     INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
     SET pr.numfvd = NULLIF(i.numfvd, 0)
     WHERE pr.numfvd IS NULL OR pr.numfvd = 0'
);

$pdo->exec(
    'UPDATE partiresul pr
     INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.numfvd = pr.id_usuario
     SET pr.numfvd = i.numfvd, pr.id_usuario = i.id_usuario
     WHERE (pr.numfvd IS NULL OR pr.numfvd = 0)
       AND i.numfvd > 0 AND pr.id_usuario = i.numfvd AND pr.id_usuario <> i.id_usuario'
);

$pdo->exec('UPDATE partiresul SET numfvd = id_usuario WHERE numfvd IS NULL OR numfvd = 0');

try {
    $pdo->exec(
        'ALTER TABLE partiresul ADD KEY idx_partiresul_torneo_numfvd_partida (id_torneo, numfvd, partida)'
    );
    echo "Índice idx_partiresul_torneo_numfvd_partida creado.\n";
} catch (Throwable $e) {
    echo "Índice (puede existir): " . $e->getMessage() . "\n";
}

$st = $pdo->query('SELECT COUNT(*) FROM partiresul WHERE numfvd IS NULL OR numfvd = 0');
echo 'Filas sin numfvd: ' . (int) $st->fetchColumn() . "\n";
echo "Migración completada.\n";
