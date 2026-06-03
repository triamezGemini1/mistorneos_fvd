<?php

declare(strict_types=1);

/**
 * Auditoría CLI: inscritos, partiresul (NUMFVD) y parejas repetidas en historial_parejas.
 *
 * Uso: php scripts/auditar_torneo_integridad.php [torneo_id]
 */
$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/lib/TorneoIntegridadService.php';
require_once $root . '/lib/ReporteParejasRepetidasService.php';
require_once $root . '/lib/Core/TorneoMesaAsignacionResolver.php';

$torneoId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($torneoId <= 0) {
    fwrite(STDERR, "Uso: php scripts/auditar_torneo_integridad.php TORNEO_ID\n");
    exit(1);
}

$pdo = DB::pdo();
$st = $pdo->prepare('SELECT id, nombre, modalidad FROM tournaments WHERE id = ? LIMIT 1');
$st->execute([$torneoId]);
$torneo = $st->fetch(PDO::FETCH_ASSOC);
if (!$torneo) {
    fwrite(STDERR, "Torneo {$torneoId} no encontrado.\n");
    exit(1);
}

$modalidad = (int) ($torneo['modalidad'] ?? 0);
echo "=== Auditoría torneo #{$torneoId} — " . ($torneo['nombre'] ?? '') . " (modalidad {$modalidad}) ===\n\n";

$val = TorneoIntegridadService::validarAntesGenerarRonda($pdo, $torneoId, $modalidad);
echo 'Inscritos confirmados: ' . (int) $val['confirmados'] . "\n";
echo 'NUMFVD corregidos en esta pasada: ' . (int) $val['numfvd_corregidos'] . "\n";
if ($val['advertencias'] !== []) {
    echo "Advertencias:\n";
    foreach ($val['advertencias'] as $a) {
        echo "  - {$a}\n";
    }
}
if (!$val['ok']) {
    echo "ERRORES (no apto para generar ronda):\n";
    foreach ($val['errores'] as $e) {
        echo "  - {$e}\n";
    }
} else {
    echo "Validación inscritos: OK\n";
}

if (TorneoIntegridadService::esModalidadIndividual($modalidad)) {
    $audit = TorneoIntegridadService::auditarParejasRepetidasPostRonda($pdo, $torneoId);
    echo "\nParejas repetidas (historial_parejas, min 2 veces): "
        . ((int) $audit['total_grupos']) . " grupo(s)\n";
    if (!$audit['sin_repeticiones']) {
        echo 'Reporte: ' . $audit['url_reporte'] . "\n";
    }
}

echo "\nListo.\n";
