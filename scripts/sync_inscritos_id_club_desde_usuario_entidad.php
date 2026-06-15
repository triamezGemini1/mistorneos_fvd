<?php
declare(strict_types=1);

/**
 * Actualiza inscritos.id_club con usuarios.entidad (código FVD de asociación).
 * Solo usuarios con entidad > 0 (afiliados FVD). Sin entidad = invitado externo; no se modifica.
 *
 * Orden:
 *   1) Por id_usuario (inscripción ligada al usuario)
 *   2) Por cédula normalizada (solo dígitos), si existe columna inscritos.cedula
 *
 * Uso:
 *   php scripts/sync_inscritos_id_club_desde_usuario_entidad.php
 *   php scripts/sync_inscritos_id_club_desde_usuario_entidad.php --dry-run
 *   php scripts/sync_inscritos_id_club_desde_usuario_entidad.php --torneo=4
 */

$opts = getopt('', ['dry-run', 'torneo:']);
$dryRun = isset($opts['dry-run']);
$torneoId = isset($opts['torneo']) ? max(0, (int) $opts['torneo']) : 0;

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

function normalizarCedula(string $cedula): string
{
    return preg_replace('/\D/', '', $cedula) ?? '';
}

function tieneColumna(PDO $pdo, string $tabla, string $columna): bool
{
    $tabla = str_replace('`', '', $tabla);
    $columna = str_replace('`', '', $columna);
    $st = $pdo->query("SHOW COLUMNS FROM `{$tabla}` LIKE " . $pdo->quote($columna));

    return $st !== false && (bool) $st->fetch(PDO::FETCH_ASSOC);
}

$pdo = DB::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (! tieneColumna($pdo, 'usuarios', 'entidad')) {
    fwrite(STDERR, "La columna usuarios.entidad no existe.\n");
    exit(1);
}
if (! tieneColumna($pdo, 'inscritos', 'id_club')) {
    fwrite(STDERR, "La columna inscritos.id_club no existe.\n");
    exit(1);
}

$tieneCedulaInsc = tieneColumna($pdo, 'inscritos', 'cedula');

echo "═══════════════════════════════════════════════════════════════\n";
echo "  inscritos.id_club <- usuarios.entidad\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo $dryRun ? "  [MODO DRY-RUN]\n" : '';
if ($torneoId > 0) {
    echo "  Torneo filtro: #{$torneoId}\n";
}
echo "\n";

$whereTorneo = $torneoId > 0 ? ' AND i.torneo_id = ' . (int) $torneoId : '';

// --- Paso 1: por id_usuario ---
$sqlPreview1 = "
    SELECT COUNT(*) FROM inscritos i
    INNER JOIN usuarios u ON u.id = i.id_usuario
    WHERE COALESCE(u.entidad, 0) > 0
      AND COALESCE(i.id_club, 0) <> u.entidad
    {$whereTorneo}
";
$pendientes1 = (int) $pdo->query($sqlPreview1)->fetchColumn();

$sqlUpdate1 = "
    UPDATE inscritos i
    INNER JOIN usuarios u ON u.id = i.id_usuario
    SET i.id_club = u.entidad
    WHERE COALESCE(u.entidad, 0) > 0
      AND COALESCE(i.id_club, 0) <> u.entidad
    {$whereTorneo}
";

if (! $dryRun) {
    $st1 = $pdo->prepare($sqlUpdate1);
    $st1->execute();
    $afectados1 = $st1->rowCount();
} else {
    $afectados1 = $pendientes1;
}

echo "Paso 1 (id_usuario): {$afectados1} inscripción(es) " . ($dryRun ? 'a actualizar' : 'actualizadas') . "\n";

// --- Paso 2: por cédula ---
$afectados2 = 0;
$pendientes2 = 0;

if ($tieneCedulaInsc) {
    $usersByCedula = [];
    foreach ($pdo->query('SELECT id, cedula, entidad FROM usuarios WHERE COALESCE(entidad, 0) > 0')->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $ced = normalizarCedula((string) ($u['cedula'] ?? ''));
        if ($ced !== '') {
            $usersByCedula[$ced] = $u;
        }
    }

    $sqlIns = "SELECT i.id, i.id_usuario, i.torneo_id, i.id_club, i.cedula
        FROM inscritos i
        WHERE 1=1 {$whereTorneo}";
    $candidatos = $pdo->query($sqlIns)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $upd = $pdo->prepare('UPDATE inscritos SET id_club = ? WHERE id = ?');

    foreach ($candidatos as $ins) {
        $cedIns = normalizarCedula((string) ($ins['cedula'] ?? ''));
        if ($cedIns === '' || ! isset($usersByCedula[$cedIns])) {
            continue;
        }
        $ent = (int) ($usersByCedula[$cedIns]['entidad'] ?? 0);
        if ($ent <= 0) {
            continue;
        }
        $actual = (int) ($ins['id_club'] ?? 0);
        if ($actual === $ent) {
            continue;
        }
        ++$pendientes2;
        if (! $dryRun) {
            $upd->execute([$ent, (int) $ins['id']]);
            if ($upd->rowCount() > 0) {
                ++$afectados2;
            }
        } else {
            ++$afectados2;
        }
    }

    echo "Paso 2 (cédula en inscritos): {$afectados2} inscripción(es) " . ($dryRun ? 'a actualizar' : 'actualizadas') . "\n";
} else {
    echo "Paso 2 (cédula): omitido — columna inscritos.cedula no existe.\n";
}

$sinEntidad = (int) $pdo->query("
    SELECT COUNT(*) FROM inscritos i
    INNER JOIN usuarios u ON u.id = i.id_usuario
    WHERE COALESCE(u.entidad, 0) = 0
    {$whereTorneo}
")->fetchColumn();

echo "\nInscripciones con usuario sin entidad (invitados externos, no se tocan): {$sinEntidad}\n";
echo "Listo.\n";

exit(0);
