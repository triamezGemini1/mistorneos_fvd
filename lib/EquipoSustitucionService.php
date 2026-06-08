<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/ImportacionAccessExternoService.php';

/**
 * Titulares (activo_mesa=1) vs banca (activo_mesa=0) en torneos por equipos.
 * Sustitución: el suplente pasa a mesas; el titular sale de mesas pero conserva codigo_equipo y estadísticas.
 */
final class EquipoSustitucionService
{
    public static function jugadoresPorEquipo(PDO $pdo, int $modalidad): int
    {
        return ImportacionAccessExternoService::jugadoresPorUnidad($modalidad);
    }

    /** @return array{ok: bool, message?: string} */
    public static function asegurarEsquema(PDO $pdo): array
    {
        if (!InscritosHelper::tieneColumnaActivoMesa($pdo)) {
            return [
                'ok' => false,
                'message' => 'Falta columna inscritos.activo_mesa. Ejecute sql/run_add_activo_mesa_inscritos.php',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarPlantillaEquipo(PDO $pdo, int $torneoId, string $codigoEquipo): array
    {
        if ($codigoEquipo === '' || $codigoEquipo === '000-000') {
            return [];
        }
        $exprAm = InscritosHelper::sqlExprActivoMesa('i', $pdo);
        $st = $pdo->prepare(
            "SELECT i.id, i.id_usuario, i.codigo_equipo, i.estatus, {$exprAm} AS activo_mesa,
                    u.cedula, u.nombre, u.numfvd
             FROM inscritos i
             INNER JOIN usuarios u ON u.id = i.id_usuario
             WHERE i.torneo_id = ? AND i.codigo_equipo = ?
               AND " . InscritosHelper::sqlWhereNoRetiradoConAlias('i') . "
             ORDER BY activo_mesa DESC, i.id ASC"
        );
        $st->execute([$torneoId, $codigoEquipo]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{ok: bool, message: string, detalle?: array<string, mixed>}
     */
    public static function sustituir(
        PDO $pdo,
        int $torneoId,
        string $codigoEquipo,
        int $idUsuarioSale,
        int $idUsuarioEntra,
        int $operadorId,
        ?string $observacion = null
    ): array {
        $esq = self::asegurarEsquema($pdo);
        if (!$esq['ok']) {
            return ['ok' => false, 'message' => (string) ($esq['message'] ?? 'Esquema incompleto.')];
        }

        $codigoEquipo = trim($codigoEquipo);
        if ($codigoEquipo === '' || $idUsuarioSale <= 0 || $idUsuarioEntra <= 0) {
            return ['ok' => false, 'message' => 'Datos de sustitución incompletos.'];
        }
        if ($idUsuarioSale === $idUsuarioEntra) {
            return ['ok' => false, 'message' => 'El titular y el suplente deben ser personas distintas.'];
        }

        $stT = $pdo->prepare('SELECT modalidad, pareclub FROM tournaments WHERE id = ? LIMIT 1');
        $stT->execute([$torneoId]);
        $torneo = $stT->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            return ['ok' => false, 'message' => 'Torneo no encontrado.'];
        }
        $req = max(2, (int) ($torneo['pareclub'] ?? self::jugadoresPorEquipo($pdo, (int) ($torneo['modalidad'] ?? 3))));

        $exprAm = InscritosHelper::sqlExprActivoMesa('i', $pdo);
        $st = $pdo->prepare(
            "SELECT i.id, i.id_usuario, i.codigo_equipo, {$exprAm} AS activo_mesa, u.nombre
             FROM inscritos i
             INNER JOIN usuarios u ON u.id = i.id_usuario
             WHERE i.torneo_id = ? AND i.id_usuario IN (?, ?)
               AND " . InscritosHelper::sqlWhereNoRetiradoConAlias('i')
        );
        $st->execute([$torneoId, $idUsuarioSale, $idUsuarioEntra]);
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[(int) $r['id_usuario']] = $r;
        }
        if (!isset($rows[$idUsuarioSale]) || !isset($rows[$idUsuarioEntra])) {
            return ['ok' => false, 'message' => 'Ambos jugadores deben estar inscritos y activos en el torneo.'];
        }

        $sale = $rows[$idUsuarioSale];
        $entra = $rows[$idUsuarioEntra];
        if ((string) ($sale['codigo_equipo'] ?? '') !== $codigoEquipo
            || (string) ($entra['codigo_equipo'] ?? '') !== $codigoEquipo) {
            return ['ok' => false, 'message' => 'Los dos jugadores deben pertenecer al equipo ' . $codigoEquipo . '.'];
        }
        if ((int) ($sale['activo_mesa'] ?? 1) !== 1) {
            return ['ok' => false, 'message' => 'Quien sale debe ser titular (activo en mesas).'];
        }
        if ((int) ($entra['activo_mesa'] ?? 1) !== 0) {
            return ['ok' => false, 'message' => 'Quien entra debe estar en banca (inactivo para mesas).'];
        }

        $pdo->beginTransaction();
        try {
            $stOff = $pdo->prepare(
                'UPDATE inscritos SET activo_mesa = 0 WHERE torneo_id = ? AND id_usuario = ? AND codigo_equipo = ?'
            );
            $stOff->execute([$torneoId, $idUsuarioSale, $codigoEquipo]);

            $stOn = $pdo->prepare(
                'UPDATE inscritos SET activo_mesa = 1 WHERE torneo_id = ? AND id_usuario = ? AND codigo_equipo = ?'
            );
            $stOn->execute([$torneoId, $idUsuarioEntra, $codigoEquipo]);

            if ($pdo->query("SHOW TABLES LIKE 'equipo_sustituciones'")->fetchColumn()) {
                $stLog = $pdo->prepare(
                    'INSERT INTO equipo_sustituciones
                     (torneo_id, codigo_equipo, id_usuario_sale, id_usuario_entra, registrado_por, observacion)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stLog->execute([
                    $torneoId,
                    $codigoEquipo,
                    $idUsuarioSale,
                    $idUsuarioEntra,
                    $operadorId > 0 ? $operadorId : null,
                    $observacion !== null ? mb_substr(trim($observacion), 0, 500) : null,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $activos = self::contarTitularesEquipo($pdo, $torneoId, $codigoEquipo);
        if ($activos !== $req) {
            return [
                'ok' => true,
                'message' => 'Sustitución registrada. Advertencia: el equipo tiene ' . $activos . ' titulares (esperados ' . $req . ').',
                'detalle' => [
                    'sale' => $sale['nombre'] ?? '',
                    'entra' => $entra['nombre'] ?? '',
                    'titulares' => $activos,
                ],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Sustitución aplicada: ' . ($entra['nombre'] ?? '') . ' entra a mesas; '
                . ($sale['nombre'] ?? '') . ' pasa a banca (conserva equipo y estadísticas).',
            'detalle' => [
                'sale' => $sale['nombre'] ?? '',
                'entra' => $entra['nombre'] ?? '',
                'titulares' => $activos,
            ],
        ];
    }

    public static function contarTitularesEquipo(PDO $pdo, int $torneoId, string $codigoEquipo): int
    {
        if (!InscritosHelper::tieneColumnaActivoMesa($pdo)) {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM inscritos i
                 WHERE i.torneo_id = ? AND i.codigo_equipo = ?
                   AND " . InscritosHelper::sqlWhereNoRetiradoConAlias('i')
            );
            $st->execute([$torneoId, $codigoEquipo]);

            return (int) $st->fetchColumn();
        }

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM inscritos i
             WHERE i.torneo_id = ? AND i.codigo_equipo = ?
               AND i.activo_mesa = 1
               AND " . InscritosHelper::sqlWhereNoRetiradoConAlias('i')
        );
        $st->execute([$torneoId, $codigoEquipo]);

        return (int) $st->fetchColumn();
    }
}
