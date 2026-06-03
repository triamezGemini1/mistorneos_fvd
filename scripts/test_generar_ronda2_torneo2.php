<?php

declare(strict_types=1);

/**
 * Prueba CLI: generar ronda 2 torneo 2 y verificar partiresul.
 * Uso: php scripts/test_generar_ronda2_torneo2.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Core/TorneoMesaAsignacionResolver.php';
require_once __DIR__ . '/../lib/Core/MesaAsignacionService.php';

$torneoId = 2;
$pdo = DB::pdo();

$st = $pdo->prepare('SELECT rondas, modalidad FROM tournaments WHERE id = ?');
$st->execute([$torneoId]);
$t = $st->fetch(PDO::FETCH_ASSOC);
$totalRondas = (int) ($t['rondas'] ?? 9);
$modalidad = (int) ($t['modalidad'] ?? 0);

$svc = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);
$ultimaAntes = $svc->obtenerUltimaRonda($torneoId);
echo "Ultima ronda antes: {$ultimaAntes}\n";

$cntAntes = (int) $pdo->prepare('SELECT COUNT(*) FROM partiresul WHERE id_torneo=? AND partida=2 AND mesa>0')
    ->execute([$torneoId]) ?: 0;
$stC = $pdo->prepare('SELECT COUNT(*) FROM partiresul WHERE id_torneo=? AND partida=2 AND mesa>0');
$stC->execute([$torneoId]);
echo 'Filas partiresul r2 antes: ' . (int) $stC->fetchColumn() . "\n";

$resultado = $svc->generarAsignacionRonda($torneoId, 2, $totalRondas, 'separar');
$resumen = [
    'success' => $resultado['success'] ?? false,
    'message' => $resultado['message'] ?? '',
    'total_mesas' => $resultado['total_mesas'] ?? 0,
    'jugadores_bye' => $resultado['jugadores_bye'] ?? 0,
];
echo 'Resultado: ' . json_encode($resumen, JSON_UNESCAPED_UNICODE) . "\n";

$ultimaDespues = $svc->obtenerUltimaRonda($torneoId);
echo "Ultima ronda despues: {$ultimaDespues}\n";

$stC->execute([$torneoId]);
echo 'Filas partiresul r2 despues: ' . (int) $stC->fetchColumn() . "\n";
