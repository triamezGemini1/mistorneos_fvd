<?php

declare(strict_types=1);

/**
 * Explica dónde están los jugadores que no aparecen en mesas 1..N de una ronda.
 * Uso: php scripts/diag_jugadores_fuera_mesas.php ID_TORNEO [RONDA]
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';

$torneoId = isset($argv[1]) ? (int) $argv[1] : 0;
$ronda = isset($argv[2]) ? (int) $argv[2] : 0;

if ($torneoId <= 0) {
    fwrite(STDERR, "Uso: php scripts/diag_jugadores_fuera_mesas.php ID_TORNEO [RONDA]\n");
    exit(1);
}

$pdo = DB::pdo();

if ($ronda <= 0) {
    $st = $pdo->prepare('SELECT COALESCE(MAX(CAST(partida AS UNSIGNED)), 0) FROM partiresul WHERE id_torneo = ? AND mesa > 0');
    $st->execute([$torneoId]);
    $ronda = (int) $st->fetchColumn();
}

$stT = $pdo->prepare('SELECT nombre FROM tournaments WHERE id = ?');
$stT->execute([$torneoId]);
$nombre = (string) ($stT->fetchColumn() ?: '');

echo "=== Torneo #{$torneoId} {$nombre} · Ronda {$ronda} ===\n\n";

$st = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?');
$st->execute([$torneoId]);
$totalInscritos = (int) $st->fetchColumn();

$st = $pdo->prepare('SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = ? AND ' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i'));
$st->execute([$torneoId]);
$confirmados = (int) $st->fetchColumn();

$st = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND estatus = 4');
$st->execute([$torneoId]);
$retirados = (int) $st->fetchColumn();

$st = $pdo->prepare(
    'SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0'
);
$st->execute([$torneoId, $ronda]);
$enMesas = (int) $st->fetchColumn();

$st = $pdo->prepare(
    'SELECT COUNT(DISTINCT mesa) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0'
);
$st->execute([$torneoId, $ronda]);
$mesasDistintas = (int) $st->fetchColumn();

$st = $pdo->prepare(
    'SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = 0'
);
$st->execute([$torneoId, $ronda]);
$bye = (int) $st->fetchColumn();

$mesasTeoricas = (int) floor($confirmados / 4);
$sobranteTeorico = $confirmados % 4;

echo "Inscritos (todos):           {$totalInscritos}\n";
echo "Inscritos confirmados:       {$confirmados}\n";
echo "Retirados (estatus=4):       {$retirados}\n";
echo "Mesas teóricas (conf/4):     {$mesasTeoricas}\n";
echo "Sobrante teórico (conf%4):   {$sobranteTeorico} (0 = no debería haber BYE)\n\n";

echo "En partiresul mesa>0:        {$enMesas} jugadores\n";
echo "Mesas distintas (mesa>0):    {$mesasDistintas}\n";
echo "Jugadores BYE (mesa=0):      {$bye}\n\n";

$faltan = $confirmados - $enMesas - $bye;
echo "Confirmados - mesa>0 - BYE:  {$faltan} (debería ser 0)\n";
echo "648 esperado → mesas:        " . (int) floor(648 / 4) . " con 0 BYE\n";
echo "Si ves 161 mesas × 4:        " . (161 * 4) . " jugadores en mesa + 4 fuera = 648\n\n";

if ($faltan > 0) {
    echo "--- Confirmados SIN fila en partiresul (esta ronda) ---\n";
    $sql = 'SELECT i.id_usuario, u.nombre, i.estatus
            FROM inscritos i
            INNER JOIN usuarios u ON u.id = i.id_usuario
            WHERE i.torneo_id = ? AND ' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . '
            AND NOT EXISTS (
                SELECT 1 FROM partiresul pr
                WHERE pr.id_torneo = i.torneo_id AND pr.partida = ? AND pr.id_usuario = i.id_usuario
            )
            LIMIT 20';
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $ronda]);
    } catch (Throwable $e) {
        $sql = 'SELECT i.id_usuario, u.nombre, i.estatus
                FROM inscritos i
                INNER JOIN usuarios u ON u.id = i.id_usuario
                WHERE i.torneo_id = ? AND ' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . '
                AND i.id_usuario NOT IN (
                    SELECT DISTINCT pr.id_usuario FROM partiresul pr
                    WHERE pr.id_torneo = ? AND pr.partida = ?
                )
                LIMIT 20';
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $torneoId, $ronda]);
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  id={$row['id_usuario']} estatus={$row['estatus']} {$row['nombre']}\n";
    }
}

if ($bye > 0) {
    echo "\n--- Jugadores en BYE (mesa=0) ---\n";
    $st = $pdo->prepare(
        'SELECT pr.id_usuario, u.nombre FROM partiresul pr
         INNER JOIN usuarios u ON u.id = pr.id_usuario
         WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = 0 LIMIT 10'
    );
    $st->execute([$torneoId, $ronda]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  id={$row['id_usuario']} {$row['nombre']}\n";
    }
}

$st = $pdo->prepare(
    'SELECT mesa, COUNT(*) AS c FROM partiresul
     WHERE id_torneo = ? AND partida = ? AND mesa > 0
     GROUP BY mesa HAVING c <> 4 ORDER BY mesa LIMIT 10'
);
$st->execute([$torneoId, $ronda]);
$bad = $st->fetchAll(PDO::FETCH_ASSOC);
if ($bad !== []) {
    echo "\n--- Mesas con cantidad distinta de 4 ---\n";
    foreach ($bad as $row) {
        echo "  mesa {$row['mesa']}: {$row['c']} jugadores\n";
    }
}
