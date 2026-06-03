<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';

$pdo = DB::pdo();
$tid = (int) ($argv[1] ?? 2);
$ronda = (int) ($argv[2] ?? 2);

$order = InscritosHelper::sqlOrderClasificacionEstricta('i');
$st = $pdo->prepare("
    SELECT i.id_usuario, i.ganados, i.efectividad, i.puntos, u.nombre,
           ROW_NUMBER() OVER (ORDER BY {$order}, i.id_usuario ASC) AS orden_clasif
    FROM inscritos i
    INNER JOIN usuarios u ON u.id = i.id_usuario
    WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . "
");
$st->execute([$tid]);
$rank = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rank[(int) $r['id_usuario']] = (int) $r['orden_clasif'];
}

$stM = $pdo->prepare('
    SELECT pr.mesa, pr.secuencia, pr.id_usuario, i.ganados, i.efectividad, i.puntos, u.nombre
    FROM partiresul pr
    INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
    INNER JOIN usuarios u ON u.id = i.id_usuario
    WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
    ORDER BY pr.mesa ASC, pr.secuencia ASC
');
$stM->execute([$tid, $ronda]);

$numMesas = 0;
$byMesa = [];
foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $m = (int) $r['mesa'];
    $numMesas = max($numMesas, $m);
    $byMesa[$m][] = $r;
}

echo "Torneo {$tid} ronda {$ronda} — mesas: {$numMesas}\n";
echo "Greedy esperado mesa 1: #1 (A), siguiente válido en ranking sin repetir pareja (C,B,D); #2 suele ir a mesa 2 (A) si fue pareja de #1.\n\n";

$letras = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];
foreach ([1, 2, 3] as $mesaVer) {
    if (!isset($byMesa[$mesaVer])) {
        continue;
    }
    echo "=== MESA {$mesaVer} ===\n";
    foreach ($byMesa[$mesaVer] as $r) {
        $uid = (int) $r['id_usuario'];
        $ord = $rank[$uid] ?? 0;
        $sec = (int) $r['secuencia'];
        echo sprintf(
            "  %s sec=%d orden_clasif=#%d G=%d E=%d P=%d %s\n",
            $letras[$sec] ?? '?',
            $sec,
            $ord,
            (int) $r['ganados'],
            (int) $r['efectividad'],
            (int) $r['puntos'],
            $r['nombre']
        );
    }
}
