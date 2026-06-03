<?php
declare(strict_types=1);

/**
 * Actualiza usuarios.posi_rnk desde atletas.categ, emparejando por cédula.
 *
 * Uso:
 *   php scripts/sync_posi_rnk_desde_atletas.php
 *   php scripts/sync_posi_rnk_desde_atletas.php --dry-run
 */

$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

function normalizarCedula(string $cedula): string
{
    return preg_replace('/\D/', '', $cedula) ?? '';
}

$pdo = DB::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tienePosiRnk = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'posi_rnk'")->fetch(PDO::FETCH_ASSOC);
if (!$tienePosiRnk) {
    fwrite(STDERR, "La columna usuarios.posi_rnk no existe. Ejecute la migración correspondiente primero.\n");
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  usuarios.posi_rnk <- atletas.categ (por cédula)\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo $dryRun ? "  [MODO DRY-RUN]\n\n" : "\n";

try {
    $userByCedula = [];
    foreach ($pdo->query('SELECT id, cedula, posi_rnk FROM usuarios')->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $ced = normalizarCedula((string) ($u['cedula'] ?? ''));
        if ($ced !== '') {
            $userByCedula[$ced] = $u;
        }
    }

    $atletas = $pdo->query('SELECT cedula, categ FROM atletas')->fetchAll(PDO::FETCH_ASSOC);

    $totalAtletas = count($atletas);
    $conMatch = 0;
    $actualizados = 0;
    $sinCambio = 0;
    $sinMatch = 0;

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    $upd = $pdo->prepare('UPDATE usuarios SET posi_rnk = ? WHERE id = ?');

    foreach ($atletas as $a) {
        $ced = normalizarCedula((string) ($a['cedula'] ?? ''));
        if ($ced === '' || !isset($userByCedula[$ced])) {
            $sinMatch++;
            continue;
        }

        $conMatch++;
        $u = $userByCedula[$ced];
        $uid = (int) ($u['id'] ?? 0);
        $nuevo = (int) ($a['categ'] ?? 0);
        $viejo = (int) ($u['posi_rnk'] ?? 0);

        if ($nuevo === $viejo) {
            $sinCambio++;
            continue;
        }

        if ($dryRun) {
            $actualizados++;
            $userByCedula[$ced]['posi_rnk'] = $nuevo;
            continue;
        }

        $upd->execute([$nuevo, $uid]);
        if ($upd->rowCount() > 0) {
            $actualizados++;
        }
        $userByCedula[$ced]['posi_rnk'] = $nuevo;
    }

    if ($dryRun) {
        echo "Simulación completada.\n\n";
    } else {
        $pdo->commit();
        echo "Sincronización completada.\n\n";
    }

    echo "Resumen:\n";
    echo "  Atletas procesados: {$totalAtletas}\n";
    echo "  Coincidencias por cédula: {$conMatch}\n";
    echo "  Usuarios actualizados: {$actualizados}\n";
    echo "  Sin cambio (mismo valor): {$sinCambio}\n";
    echo "  Atletas sin usuario por cédula: {$sinMatch}\n";
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
