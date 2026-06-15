<?php

declare(strict_types=1);

/**
 * Asigna acceso inicial a usuarios con rol "usuario" y NUMFVD válido:
 *   usuario  = user{numfvd}  (ej. user2701)
 *   clave    = {numfvd}      (ej. 2701)
 * Marca must_change_password = 1 si la columna existe (cambio obligatorio al entrar).
 *
 * Uso:
 *   php scripts/asignar_acceso_inicial_usuarios_numfvd_cli.php           # simulación
 *   php scripts/asignar_acceso_inicial_usuarios_numfvd_cli.php --apply   # aplicar cambios
 */

require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../config/db_config.php';
require __DIR__ . '/../lib/security.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo = DB::pdo();

$tieneMustChange = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'must_change_password'")->fetch(PDO::FETCH_ASSOC);
$tieneNumfvd = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetch(PDO::FETCH_ASSOC);

if (! $tieneNumfvd) {
    fwrite(STDERR, "La tabla usuarios no tiene columna numfvd.\n");
    exit(1);
}

$st = $pdo->query(
    "SELECT id, numfvd, username, nombre, cedula, email
     FROM usuarios
     WHERE role = 'usuario'
       AND numfvd IS NOT NULL
       AND CAST(numfvd AS UNSIGNED) > 0
     ORDER BY CAST(numfvd AS UNSIGNED) ASC, id ASC"
);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    echo "No hay usuarios con rol 'usuario' y NUMFVD > 0.\n";
    exit(0);
}

$chkUsername = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE username = ? AND id != ? LIMIT 1');
$updWithMust = $pdo->prepare(
    'UPDATE usuarios
     SET username = ?, password_hash = ?, must_change_password = 1, updated_at = NOW()
     WHERE id = ?'
);
$updBasic = $pdo->prepare(
    'UPDATE usuarios
     SET username = ?, password_hash = ?, updated_at = NOW()
     WHERE id = ?'
);

$ok = 0;
$skip = 0;
$errores = [];

echo ($apply ? "APLICANDO" : "SIMULACIÓN (use --apply para guardar)") . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;
printf("%-8s %-12s %-18s %-18s %s\n", 'NUMFVD', 'ID', 'Usuario nuevo', 'Clave', 'Nombre');
echo str_repeat('-', 72) . PHP_EOL;

if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $numfvd = (int) $row['numfvd'];
        $newUsername = 'user' . $numfvd;
        $plainPassword = (string) $numfvd;
        $nombre = trim((string) ($row['nombre'] ?? ''));

        $chkUsername->execute([$newUsername, $id]);
        $conflict = $chkUsername->fetch(PDO::FETCH_ASSOC);
        if ($conflict !== false) {
            ++$skip;
            $errores[] = "NUMFVD {$numfvd} (id {$id}): username {$newUsername} ya usado por id " . (int) $conflict['id'];
            printf(
                "%-8d %-12d %-18s %-18s %s  [OMITIDO: conflicto]\n",
                $numfvd,
                $id,
                $newUsername,
                $plainPassword,
                mb_substr($nombre, 0, 40)
            );
            continue;
        }

        printf(
            "%-8d %-12d %-18s %-18s %s\n",
            $numfvd,
            $id,
            $newUsername,
            $plainPassword,
            mb_substr($nombre, 0, 40)
        );

        if ($apply) {
            $hash = Security::hashPassword($plainPassword);
            if ($tieneMustChange) {
                $updWithMust->execute([$newUsername, $hash, $id]);
            } else {
                $updBasic->execute([$newUsername, $hash, $id]);
            }
        }
        ++$ok;
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo str_repeat('-', 72) . PHP_EOL;
echo 'Procesados: ' . $ok . PHP_EOL;
echo 'Omitidos: ' . $skip . PHP_EOL;
if (! $apply) {
    echo PHP_EOL . 'Ejecute con --apply para guardar en la base de datos.' . PHP_EOL;
}
if ($errores !== []) {
    echo PHP_EOL . 'Conflictos:' . PHP_EOL;
    foreach ($errores as $msg) {
        echo '  - ' . $msg . PHP_EOL;
    }
}

exit($skip > 0 && $ok === 0 ? 2 : 0);
