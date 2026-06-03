<?php

declare(strict_types=1);

/**
 * Alinea entidad.nombre y clubes.nombre con el catálogo FVD (EntidadFvdCatalogo).
 *
 * Uso:
 *   php scripts/reparar_nombres_asociaciones_fvd.php
 *   php scripts/reparar_nombres_asociaciones_fvd.php --dry-run
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/EntidadFvdCatalogo.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

try {
    $pdo = DB::pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $catalogo = EntidadFvdCatalogo::todosLosNombres();
    $cambios = 0;

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    foreach ($catalogo as $id => $nombreCanon) {
        $st = $pdo->prepare('SELECT nombre FROM entidad WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $actualEnt = trim((string) ($st->fetchColumn() ?: ''));
        $actualNorm = strtoupper(preg_replace('/\s+/', ' ', $actualEnt) ?? $actualEnt);

        if ($actualNorm !== $nombreCanon) {
            echo "entidad {$id}: «{$actualEnt}» → «{$nombreCanon}»\n";
            if (!$dryRun) {
                $pdo->prepare('UPDATE entidad SET nombre = ? WHERE id = ?')->execute([$nombreCanon, $id]);
            }
            ++$cambios;
        }

        $stC = $pdo->prepare('SELECT nombre, entidad FROM clubes WHERE id = ? LIMIT 1');
        $stC->execute([$id]);
        $club = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$club) {
            continue;
        }
        $nombreClub = trim((string) ($club['nombre'] ?? ''));
        $nombreClubNorm = strtoupper(preg_replace('/\s+/', ' ', EntidadFvdCatalogo::normalizarNombre($id, $nombreClub)) ?? '');
        $entClub = (int) ($club['entidad'] ?? 0);

        if ($nombreClubNorm !== $nombreCanon || $entClub !== $id) {
            echo "clubes {$id}: «{$nombreClub}» (entidad={$entClub}) → «{$nombreCanon}»\n";
            if (!$dryRun) {
                $pdo->prepare('UPDATE clubes SET nombre = ?, entidad = ? WHERE id = ?')
                    ->execute([$nombreCanon, $id, $id]);
            }
            ++$cambios;
        }
    }

    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->commit();
    }

    echo $dryRun ? "DRY-RUN: {$cambios} fila(s) a corregir.\n" : "Listo. {$cambios} fila(s) actualizada(s).\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
