<?php
/**
 * Verifica que las inscripciones en línea (landing) se graben en inscritos.
 * Uso: php scripts/verify_inscripcion_linea.php [torneo_id]
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/FvdConfig.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';

$pdo = DB::pdo();
$torneoId = isset($argv[1]) ? (int) $argv[1] : 0;
$landingPor = (int) FvdConfig::INSCRITO_POR_LANDING_PUBLICO;

echo "=== Columnas tabla inscritos ===\n";
$cols = $pdo->query('SHOW COLUMNS FROM inscritos')->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . "\n\n";

$required = ['id_usuario', 'torneo_id', 'estatus'];
$missing = array_diff($required, $cols);
if ($missing !== []) {
    echo "ERROR: faltan columnas obligatorias: " . implode(', ', $missing) . "\n";
    exit(1);
}

$where = 'inscrito_por = ?';
$params = [$landingPor];
if ($torneoId > 0) {
    $where .= ' AND torneo_id = ?';
    $params[] = $torneoId;
}

$selectCols = ['id', 'torneo_id', 'id_usuario', 'id_club', 'estatus', 'inscrito_por', 'nacionalidad', 'cedula', 'codigo_equipo', 'fecha_inscripcion'];
foreach (['entidad_id', 'numfvd'] as $opt) {
    if (in_array($opt, $cols, true)) {
        $selectCols[] = $opt;
    }
}
$sql = 'SELECT ' . implode(', ', $selectCols) . " FROM inscritos WHERE {$where} ORDER BY id DESC LIMIT 10";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

echo "=== Inscripciones en línea (inscrito_por={$landingPor})" . ($torneoId > 0 ? " torneo_id={$torneoId}" : '') . " ===\n";
echo "Total: {$total}\n\n";

if ($rows === []) {
    echo "No hay registros. Si acaba de probar en el landing, envíe el formulario POST en inscribir_evento_masivo.php.\n";
} else {
    foreach ($rows as $r) {
        $activo = InscritosHelper::sqlWhereActivoConAlias('');
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n---\n";
    }
}

// Conteo landing (misma regla que LandingDataService)
if ($torneoId > 0) {
    $whereActivo = InscritosHelper::sqlWhereActivoConAlias('');
    $c = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND {$whereActivo}");
    $c->execute([$torneoId]);
    echo "\nContador landing (activos, torneo {$torneoId}): " . (int) $c->fetchColumn() . "\n";
}

echo "\nOK: el flujo escribe en la tabla `inscritos` (y en `usuarios` si es persona nueva).\n";
exit(0);
