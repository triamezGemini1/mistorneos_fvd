<?php

declare(strict_types=1);

namespace Tournament;

require_once __DIR__ . '/../PartiresulEstatusSql.php';
require_once __DIR__ . '/../InscritosHelper.php';
require_once __DIR__ . '/../InscritosPartiresulHelper.php';
require_once __DIR__ . '/../TorneoCampoNumerico.php';
require_once __DIR__ . '/../UserActivationHelper.php';
require_once __DIR__ . '/../Tournament/Handlers/TournamentActionHandler.php';

/**
 * Operaciones sobre partiresul / inscritos.
 * Carga masiva, FF, tarjetas y auditoría analítica usados desde op_especiales solo con estatus 9
 * ({@see esCargaEspecial}). Swap y reemplazo de id_usuario están disponibles para cualquier torneo con permisos.
 */
final class OpEspecialesHelper
{
    public const ESTATUS_CARGA_ESPECIAL = 9;

    /**
     * @return array<string, mixed> Fila tournaments
     */
    public static function obtenerTorneoObligatorio(int $torneoId): array
    {
        if ($torneoId <= 0) {
            throw new \InvalidArgumentException('Torneo no válido.');
        }
        $pdo = \DB::pdo();
        $st = $pdo->prepare('SELECT id, nombre, estatus, modalidad, puntos, rondas FROM tournaments WHERE id = ?');
        $st->execute([$torneoId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Torneo no encontrado.');
        }

        return $row;
    }

    public static function esCargaEspecial(array $torneo): bool
    {
        return (int) ($torneo['estatus'] ?? 0) === self::ESTATUS_CARGA_ESPECIAL;
    }

    /**
     * Actualiza inscritos desde partiresul (misma idea que InscritosPartiresulHelper por jugador).
     */
    public static function sincronizarEstadisticasInscritos(int $torneoId): void
    {
        if (! \function_exists('actualizarEstadisticasInscritos')) {
            if (! \defined('TORNEO_GESTION_SKIP_AUTH')) {
                \define('TORNEO_GESTION_SKIP_AUTH', true);
            }
            if (! \defined('TORNEO_GESTION_SKIP_ROUTER')) {
                \define('TORNEO_GESTION_SKIP_ROUTER', true);
            }
            $mod = __DIR__ . '/../../modules/torneo_gestion.php';
            if (\is_readable($mod)) {
                require_once $mod;
            }
        }
        if (\function_exists('actualizarEstadisticasInscritos')) {
            actualizarEstadisticasInscritos($torneoId);

            if (! \class_exists(\RankingTorneoRecalc::class, false)) {
                require_once __DIR__ . '/../RankingTorneoRecalc.php';
            }
            \RankingTorneoRecalc::reclasificarSiUltimaRondaTorneoCompleta($torneoId);

            return;
        }
        $pdo = \DB::pdo();
        $st = $pdo->prepare('SELECT DISTINCT id_usuario FROM inscritos WHERE torneo_id = ? AND estatus != 4');
        $st->execute([$torneoId]);
        while ($uid = $st->fetchColumn()) {
            \InscritosPartiresulHelper::actualizarEstadisticas((int) $uid, $torneoId);
        }
    }

    public static function idEquipoDeUsuario(int $torneoId, int $idUsuario): ?int
    {
        if ($idUsuario <= 0) {
            return null;
        }
        $pdo = \DB::pdo();
        $st = $pdo->prepare(
            'SELECT e.id FROM equipos e
             INNER JOIN inscritos i ON i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo
             WHERE e.id_torneo = ? AND i.id_usuario = ? AND e.estatus = 0
             LIMIT 1'
        );
        $st->execute([$torneoId, $idUsuario]);
        $id = $st->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarFilasMesa(\PDO $pdo, int $torneoId, int $ronda, int $mesa): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? ORDER BY secuencia ASC'
        );
        $st->execute([$torneoId, $ronda, $mesa]);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Si el usuario no está inscrito (activo) en el torneo, inserta inscripción confirmada como en inscripción en sitio.
     * En modalidad equipos (3) copia codigo_equipo / id_club del jugador de referencia (sustituido) cuando exista.
     *
     * @throws \RuntimeException
     */
    public static function asegurarInscripcionParaReemplazoPartiresul(
        int $torneoId,
        int $idUsuarioNuevo,
        int $idUsuarioReferencia,
        int $modalidad,
        int $operadorId
    ): void {
        if ($idUsuarioNuevo <= 0) {
            throw new \RuntimeException('ID de usuario nuevo no válido.');
        }
        $pdo = \DB::pdo();
        $st = $pdo->prepare(
            'SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND ' . \InscritosHelper::SQL_WHERE_NO_RETIRADO
        );
        $st->execute([$idUsuarioNuevo, $torneoId]);
        if ($st->fetch()) {
            return;
        }
        $stU = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
        $stU->execute([$idUsuarioNuevo]);
        if (!$stU->fetch()) {
            throw new \RuntimeException('El usuario de reemplazo no existe en la plataforma (usuarios).');
        }

        $id_club = null;
        $codigo_equipo = '';
        if ($modalidad === 3 && $idUsuarioReferencia > 0) {
            $stRef = $pdo->prepare(
                'SELECT codigo_equipo, id_club FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND '
                . \InscritosHelper::SQL_WHERE_NO_RETIRADO . ' LIMIT 1'
            );
            $stRef->execute([$idUsuarioReferencia, $torneoId]);
            $ref = $stRef->fetch(\PDO::FETCH_ASSOC);
            if ($ref) {
                $codigo_equipo = trim((string) ($ref['codigo_equipo'] ?? ''));
                if (! empty($ref['id_club'])) {
                    $id_club = (int) $ref['id_club'];
                }
            }
        }
        if ($id_club === null || $id_club <= 0) {
            $stT = $pdo->prepare('SELECT club_responsable FROM tournaments WHERE id = ?');
            $stT->execute([$torneoId]);
            $cr = $stT->fetchColumn();
            if (! empty($cr) && (int) $cr > 0) {
                $id_club = (int) $cr;
            } else {
                $stUc = $pdo->prepare('SELECT club_id FROM usuarios WHERE id = ?');
                $stUc->execute([$idUsuarioNuevo]);
                $id_club = (int) ($stUc->fetchColumn() ?: 0) ?: null;
            }
        }

        if ($modalidad === 3) {
            $ce = $codigo_equipo;
            if ($ce === '' || $ce === '000-000') {
                throw new \RuntimeException(
                    'Modalidad equipos: no se pudo determinar código de equipo desde el jugador sustituido. Inscriba al nuevo jugador en el equipo o indique un usuario ya inscrito en este torneo.'
                );
            }
        }

        $datos = [
            'id_usuario' => $idUsuarioNuevo,
            'torneo_id' => $torneoId,
            'id_club' => $id_club,
            'estatus' => 1,
            'inscrito_por' => $operadorId,
            'numero' => 0,
        ];
        if ($modalidad === 3 && $codigo_equipo !== '') {
            $datos['codigo_equipo'] = $codigo_equipo;
        }

        \InscritosHelper::insertarInscrito($pdo, $datos);
        \UserActivationHelper::activateUser($pdo, $idUsuarioNuevo);
    }

    /**
     * Reemplaza id_usuario (todas las filas en el alcance). Opcional alta en inscritos si falta.
     *
     * @param 'una_ronda'|'rango'|'todas' $alcance
     *
     * @return int Filas actualizadas en partiresul
     *
     * @throws \RuntimeException
     */
    public static function reemplazarIdUsuarioPartiresul(
        int $torneoId,
        int $idUsuarioViejo,
        int $idUsuarioNuevo,
        string $alcance,
        ?int $rondaUnica,
        ?int $rondaDesde,
        ?int $rondaHasta,
        int $modalidad,
        int $operadorId
    ): int {
        if ($idUsuarioViejo <= 0 || $idUsuarioNuevo <= 0) {
            throw new \RuntimeException('IDs de usuario no válidos.');
        }
        if ($idUsuarioViejo === $idUsuarioNuevo) {
            throw new \RuntimeException('El usuario sustituto debe ser distinto al sustituido.');
        }

        $alcance = strtolower(trim($alcance));
        if (! in_array($alcance, ['una_ronda', 'rango', 'todas'], true)) {
            throw new \RuntimeException('Alcance no válido.');
        }

        $pdo = \DB::pdo();
        $stOld = $pdo->prepare('SELECT id FROM usuarios WHERE id IN (?, ?)');
        $stOld->execute([$idUsuarioViejo, $idUsuarioNuevo]);
        if (count($stOld->fetchAll()) < 2) {
            throw new \RuntimeException('Ambos usuarios deben existir en la tabla usuarios.');
        }

        $partSql = '';
        $paramsPref = [$torneoId, $idUsuarioViejo];
        if ($alcance === 'una_ronda') {
            $r = (int) ($rondaUnica ?? 0);
            if ($r <= 0) {
                throw new \RuntimeException('Indique una ronda válida.');
            }
            $partSql = ' AND partida = ? ';
            $paramsPref[] = $r;
        } elseif ($alcance === 'rango') {
            $d = (int) ($rondaDesde ?? 0);
            $h = (int) ($rondaHasta ?? 0);
            if ($d <= 0 || $h <= 0 || $d > $h) {
                throw new \RuntimeException('Rango de rondas no válido (desde/hasta).');
            }
            $partSql = ' AND partida >= ? AND partida <= ? ';
            $paramsPref[] = $d;
            $paramsPref[] = $h;
        }

        $stMesas = $pdo->prepare(
            'SELECT DISTINCT partida, mesa FROM partiresul WHERE id_torneo = ? AND id_usuario = ? AND mesa > 0' . $partSql
            . ' ORDER BY partida, mesa'
        );
        $stMesas->execute($paramsPref);
        $pares = $stMesas->fetchAll(\PDO::FETCH_ASSOC);
        if ($pares === []) {
            throw new \RuntimeException(
                'No hay filas del usuario sustituido en partiresul para el alcance indicado (solo mesas de juego).'
            );
        }

        self::asegurarInscripcionParaReemplazoPartiresul(
            $torneoId,
            $idUsuarioNuevo,
            $idUsuarioViejo,
            $modalidad,
            $operadorId
        );

        foreach ($pares as $pm) {
            $partida = (int) $pm['partida'];
            $mesa = (int) $pm['mesa'];
            self::validarMesaTrasReemplazoUnUsuario(
                $pdo,
                $torneoId,
                $partida,
                $mesa,
                $idUsuarioViejo,
                $idUsuarioNuevo,
                $modalidad
            );
        }

        $updParams = [$idUsuarioNuevo, $torneoId, $idUsuarioViejo];
        $updSql = 'UPDATE partiresul SET id_usuario = ? WHERE id_torneo = ? AND id_usuario = ? AND mesa > 0' . $partSql;
        $stUp = $pdo->prepare($updSql);
        $stUp->execute(array_merge($updParams, array_slice($paramsPref, 2)));

        return $stUp->rowCount();
    }

    /**
     * @throws \RuntimeException
     */
    private static function validarMesaTrasReemplazoUnUsuario(
        \PDO $pdo,
        int $torneoId,
        int $partida,
        int $mesa,
        int $uidOld,
        int $uidNew,
        int $modalidad
    ): void {
        $stU = $pdo->prepare(
            'SELECT id_usuario FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? ORDER BY secuencia'
        );
        $stU->execute([$torneoId, $partida, $mesa]);
        $uids = array_map('intval', $stU->fetchAll(\PDO::FETCH_COLUMN));
        if ($uids === []) {
            return;
        }
        $uids2 = array_map(static fn (int $u): int => $u === $uidOld ? $uidNew : $u, $uids);
        if (count(array_unique($uids2)) < count($uids2)) {
            throw new \RuntimeException(
                "El reemplazo dejaría al jugador nuevo repetido en la misma mesa (ronda {$partida}, mesa {$mesa})."
            );
        }
        if ($modalidad === 3) {
            $seen = [];
            foreach ($uids2 as $u) {
                if ($u <= 0) {
                    continue;
                }
                $e = self::idEquipoDeUsuario($torneoId, $u);
                if ($e === null) {
                    continue;
                }
                if (isset($seen[$e])) {
                    throw new \RuntimeException(
                        "Modalidad equipos: en ronda {$partida}, mesa {$mesa}, habría dos jugadores del mismo equipo."
                    );
                }
                $seen[$e] = true;
            }
        }
    }

    /**
     * Intercambia dos jugadores entre dos mesas de la misma ronda a partir de id_usuario en el torneo indicado.
     * Comprueba primero que ambos existan en partiresul (solo mesas de juego) para esa ronda; si falta alguno o hay ambigüedad, falla sin modificar datos.
     *
     * @return array{ronda: int, cambios: list<array{id_usuario: int, id_partiresul: int, mesa_desde: int, mesa_hasta: int}>}
     *
     * @throws \RuntimeException
     */
    public static function swapAtletasPorUsuariosYRonda(
        int $torneoId,
        int $ronda,
        int $idUsuarioA,
        int $idUsuarioB,
        int $modalidad
    ): array {
        if ($ronda <= 0) {
            throw new \RuntimeException('Ronda no válida.');
        }
        if ($idUsuarioA <= 0 || $idUsuarioB <= 0) {
            throw new \RuntimeException('Indique dos IDs de usuario válidos (mayores que 0).');
        }
        if ($idUsuarioA === $idUsuarioB) {
            throw new \RuntimeException('Debe indicar dos jugadores distintos.');
        }

        $pdo = \DB::pdo();
        $stCnt = $pdo->prepare(
            'SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND partida = ? AND id_usuario = ? AND mesa > 0'
        );
        $stCnt->execute([$torneoId, $ronda, $idUsuarioA]);
        $cA = (int) $stCnt->fetchColumn();
        $stCnt->execute([$torneoId, $ronda, $idUsuarioB]);
        $cB = (int) $stCnt->fetchColumn();

        $partes = [];
        if ($cA === 0) {
            $partes[] = 'No se encontró el usuario ' . $idUsuarioA . ' en la ronda ' . $ronda . ' de este torneo (sin fila en partiresul con mesa de juego).';
        } elseif ($cA > 1) {
            $partes[] = 'El usuario ' . $idUsuarioA . ' tiene más de una fila en la ronda ' . $ronda . '; corrija datos o use intercambio por ID de fila.';
        }
        if ($cB === 0) {
            $partes[] = 'No se encontró el usuario ' . $idUsuarioB . ' en la ronda ' . $ronda . ' de este torneo (sin fila en partiresul con mesa de juego).';
        } elseif ($cB > 1) {
            $partes[] = 'El usuario ' . $idUsuarioB . ' tiene más de una fila en la ronda ' . $ronda . '; corrija datos o use intercambio por ID de fila.';
        }
        if ($partes !== []) {
            throw new \RuntimeException(implode(' ', $partes));
        }

        $st = $pdo->prepare(
            'SELECT id, mesa FROM partiresul WHERE id_torneo = ? AND partida = ? AND id_usuario = ? AND mesa > 0 ORDER BY id ASC LIMIT 1'
        );
        $st->execute([$torneoId, $ronda, $idUsuarioA]);
        $rowA = $st->fetch(\PDO::FETCH_ASSOC);
        $st->execute([$torneoId, $ronda, $idUsuarioB]);
        $rowB = $st->fetch(\PDO::FETCH_ASSOC);

        if (! $rowA || ! $rowB) {
            throw new \RuntimeException('No se pudieron resolver las filas en partiresul; no se aplicó ningún cambio.');
        }

        $idRowA = (int) $rowA['id'];
        $idRowB = (int) $rowB['id'];
        $mesaA = (int) $rowA['mesa'];
        $mesaB = (int) $rowB['mesa'];

        self::swapAtletasPorIdsPartiresul($torneoId, $ronda, $idRowA, $idRowB, $modalidad);

        return [
            'ronda' => $ronda,
            'cambios' => [
                [
                    'id_usuario' => $idUsuarioA,
                    'id_partiresul' => $idRowA,
                    'mesa_desde' => $mesaA,
                    'mesa_hasta' => $mesaB,
                ],
                [
                    'id_usuario' => $idUsuarioB,
                    'id_partiresul' => $idRowB,
                    'mesa_desde' => $mesaB,
                    'mesa_hasta' => $mesaA,
                ],
            ],
        ];
    }

    /**
     * Intercambia dos jugadores entre dos mesas de la misma ronda (por id de fila partiresul).
     *
     * @throws \RuntimeException
     */
    public static function swapAtletasPorIdsPartiresul(
        int $torneoId,
        int $ronda,
        int $idRowA,
        int $idRowB,
        int $modalidad
    ): void {
        $pdo = \DB::pdo();
        $st = $pdo->prepare('SELECT * FROM partiresul WHERE id = ? AND id_torneo = ? AND partida = ?');
        $st->execute([$idRowA, $torneoId, $ronda]);
        $a = $st->fetch(\PDO::FETCH_ASSOC);
        $st->execute([$idRowB, $torneoId, $ronda]);
        $b = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$a || !$b) {
            throw new \RuntimeException('No se encontraron ambas filas en esa ronda.');
        }
        $mesaA = (int) $a['mesa'];
        $mesaB = (int) $b['mesa'];
        if ($mesaA <= 0 || $mesaB <= 0) {
            throw new \RuntimeException('El swap solo aplica a mesas de juego (mesa &gt; 0).');
        }
        if ($mesaA === $mesaB) {
            throw new \RuntimeException('Seleccione dos mesas distintas.');
        }

        $ua = (int) $a['id_usuario'];
        $ub = (int) $b['id_usuario'];

        if ($modalidad === 3) {
            $stU = $pdo->prepare(
                'SELECT id_usuario FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? ORDER BY secuencia'
            );
            $stU->execute([$torneoId, $ronda, $mesaA]);
            $uidsA = array_map('intval', $stU->fetchAll(\PDO::FETCH_COLUMN));
            $stU->execute([$torneoId, $ronda, $mesaB]);
            $uidsB = array_map('intval', $stU->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($uidsA as $i => $v) {
                if ($v === $ua) {
                    $uidsA[$i] = $ub;
                }
            }
            foreach ($uidsB as $i => $v) {
                if ($v === $ub) {
                    $uidsB[$i] = $ua;
                }
            }
            $chk = static function (array $uids) use ($torneoId): void {
                $seen = [];
                foreach ($uids as $uid) {
                    $e = self::idEquipoDeUsuario($torneoId, $uid);
                    if ($e === null) {
                        continue;
                    }
                    if (isset($seen[$e])) {
                        throw new \RuntimeException(
                            'La Regla de Oro impide este intercambio: habría dos jugadores del mismo equipo en una mesa.'
                        );
                    }
                    $seen[$e] = true;
                }
            };
            $chk($uidsA);
            $chk($uidsB);
        }

        $pdo->beginTransaction();
        try {
            $u1 = $pdo->prepare('UPDATE partiresul SET id_usuario = ? WHERE id = ? AND id_torneo = ?');
            $u1->execute([$ub, $idRowA, $torneoId]);
            $u1->execute([$ua, $idRowB, $torneoId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('No se pudo completar el intercambio: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Aplica FF + puntos de penalización (sanción) y marca mesa como registrada vía núcleo de resultados.
     *
     * @param list<int> $idsPartiresul
     */
    public static function aplicarForfaitFilas(
        int $torneoId,
        int $ronda,
        array $idsPartiresul,
        int $puntosPenal,
        int $registradoPorUserId
    ): int {
        $idsPartiresul = array_values(array_filter(array_map('intval', $idsPartiresul), static fn (int $x) => $x > 0));
        if ($idsPartiresul === []) {
            return 0;
        }
        $pdo = \DB::pdo();
        $porMesa = [];
        $st = $pdo->prepare('SELECT * FROM partiresul WHERE id = ? AND id_torneo = ? AND partida = ?');
        foreach ($idsPartiresul as $pid) {
            $st->execute([$pid, $torneoId, $ronda]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int) $row['mesa'] <= 0) {
                continue;
            }
            $mesa = (int) $row['mesa'];
            $porMesa[$mesa] = $porMesa[$mesa] ?? [];
            $porMesa[$mesa][(int) $row['id']] = true;
        }
        $n = 0;
        foreach ($porMesa as $mesa => $_ids) {
            $filas = self::listarFilasMesa($pdo, $torneoId, $ronda, $mesa);
            if (count($filas) !== 4) {
                continue;
            }
            $jugadores = [];
            foreach ($filas as $f) {
                $id = (int) $f['id'];
                $marcarFf = isset($_ids[$id]);
                $jugadores[] = self::filaPartiresulAJugadorPost($f, $marcarFf, $marcarFf ? $puntosPenal : 0);
            }
            \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                $pdo,
                $torneoId,
                $ronda,
                $mesa,
                $jugadores,
                $registradoPorUserId,
                ''
            );
            $n++;
        }

        return $n;
    }

    /**
     * Tarjeta administrativa (amarilla/roja) + puntos de sanción en filas seleccionadas.
     *
     * @param list<int> $idsPartiresul
     */
    public static function aplicarTarjetasFilas(
        int $torneoId,
        int $ronda,
        array $idsPartiresul,
        int $codigoTarjeta,
        int $puntosSancion,
        int $registradoPorUserId
    ): int {
        $codigoTarjeta = \TorneoCampoNumerico::codigoTarjeta($codigoTarjeta);
        $idsPartiresul = array_values(array_filter(array_map('intval', $idsPartiresul), static fn (int $x) => $x > 0));
        if ($idsPartiresul === []) {
            return 0;
        }
        $pdo = \DB::pdo();
        $porMesa = [];
        $st = $pdo->prepare('SELECT * FROM partiresul WHERE id = ? AND id_torneo = ? AND partida = ?');
        foreach ($idsPartiresul as $pid) {
            $st->execute([$pid, $torneoId, $ronda]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int) $row['mesa'] <= 0) {
                continue;
            }
            $mesa = (int) $row['mesa'];
            $porMesa[$mesa] = $porMesa[$mesa] ?? [];
            $porMesa[$mesa][(int) $row['id']] = ['tarjeta' => $codigoTarjeta, 'sancion' => min(80, max(0, $puntosSancion))];
        }
        $n = 0;
        foreach ($porMesa as $mesa => $mapTar) {
            $filas = self::listarFilasMesa($pdo, $torneoId, $ronda, $mesa);
            if (count($filas) !== 4) {
                continue;
            }
            $jugadores = [];
            foreach ($filas as $f) {
                $id = (int) $f['id'];
                $extra = $mapTar[$id] ?? null;
                $jugadores[] = self::filaPartiresulAJugadorPost(
                    $f,
                    false,
                    0,
                    $extra['tarjeta'] ?? null,
                    $extra['sancion'] ?? null
                );
            }
            \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                $pdo,
                $torneoId,
                $ronda,
                $mesa,
                $jugadores,
                $registradoPorUserId,
                ''
            );
            $n++;
        }

        return $n;
    }

    /**
     * Carga masiva: rellena cada mesa completa con un resultado base coherente (pareja 1–2 vs 3–4).
     */
    public static function cargaMasivaResultadosBase(int $torneoId, int $ronda, int $registradoPorUserId): int
    {
        $pdo = \DB::pdo();
        $st = $pdo->prepare(
            'SELECT DISTINCT mesa FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0 ORDER BY mesa ASC'
        );
        $st->execute([$torneoId, $ronda]);
        $mesas = $st->fetchAll(\PDO::FETCH_COLUMN);
        $stT = $pdo->prepare('SELECT puntos FROM tournaments WHERE id = ?');
        $stT->execute([$torneoId]);
        $puntosTorneo = (int) $stT->fetchColumn();
        if ($puntosTorneo <= 0) {
            $puntosTorneo = 200;
        }
        $win = min((int) round($puntosTorneo * 0.55), (int) round($puntosTorneo * 1.6));
        $lose = min($win - 5, (int) round($puntosTorneo * 0.50));
        if ($lose < 0) {
            $lose = 0;
        }

        $n = 0;
        foreach ($mesas as $mesaVal) {
            $mesa = (int) $mesaVal;
            $filas = self::listarFilasMesa($pdo, $torneoId, $ronda, $mesa);
            if (count($filas) !== 4) {
                continue;
            }
            $jugadores = [];
            foreach ($filas as $f) {
                $sec = (int) $f['secuencia'];
                $esA = ($sec === 1 || $sec === 2);
                $jugadores[] = [
                    'id' => (int) $f['id'],
                    'id_usuario' => (int) $f['id_usuario'],
                    'secuencia' => $sec,
                    'resultado1' => (string) ($esA ? $win : $lose),
                    'resultado2' => (string) ($esA ? $lose : $win),
                    'tarjeta' => '0',
                    'sancion' => '0',
                    'chancleta' => '0',
                    'zapato' => '0',
                ];
            }
            \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                $pdo,
                $torneoId,
                $ronda,
                $mesa,
                $jugadores,
                $registradoPorUserId,
                ''
            );
            $n++;
        }

        return $n;
    }

    /**
     * @return array{integridad: list<array<string, mixed>>, gdu: list<array<string, mixed>>, ff_incoherente: list<array<string, mixed>>}
     */
    public static function reporteAuditoria(int $torneoId): array
    {
        $pdo = \DB::pdo();
        $reg = \PartiresulEstatusSql::whereRegistradoUno('pr');
        $ff1 = \PartiresulEstatusSql::whereFfUno('pr');
        $r1 = \InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $r2 = \InscritosHelper::sqlExprColumnaNumerica('pr.resultado2');
        $sn = \InscritosHelper::sqlExprColumnaNumerica('pr.sancion');

        $integridad = [];

        $stMesas = $pdo->prepare(
            "SELECT partida, mesa, COUNT(*) AS c
             FROM partiresul pr
             WHERE pr.id_torneo = ? AND pr.mesa > 0
             GROUP BY partida, mesa
             HAVING c <> 4"
        );
        $stMesas->execute([$torneoId]);
        foreach ($stMesas->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $integridad[] = [
                'tipo' => 'mesa_incompleta',
                'partida' => (int) $m['partida'],
                'mesa' => (int) $m['mesa'],
                'filas' => (int) $m['c'],
            ];
        }

        $st = $pdo->prepare(
            "SELECT pr.partida, pr.mesa, e.id AS id_equipo, COUNT(*) AS c
             FROM partiresul pr
             INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
             INNER JOIN equipos e ON e.id_torneo = pr.id_torneo AND e.codigo_equipo = i.codigo_equipo AND e.estatus = 0
             WHERE pr.id_torneo = ? AND pr.mesa > 0
             GROUP BY pr.partida, pr.mesa, e.id
             HAVING c > 1"
        );
        $st->execute([$torneoId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $integridad[] = [
                'tipo' => 'equipo_duplicado_mesa',
                'partida' => (int) $m['partida'],
                'mesa' => (int) $m['mesa'],
                'id_equipo' => (int) $m['id_equipo'],
                'repetidos' => (int) $m['c'],
            ];
        }

        $gdu = self::obtenerReporteAnomalias($torneoId);

        $ff_incoherente = [];
        $stFf = $pdo->prepare(
            "SELECT pr.id, pr.partida, pr.mesa, pr.id_usuario, pr.resultado1, pr.resultado2, pr.sancion, pr.ff
             FROM partiresul pr
             WHERE pr.id_torneo = ? AND pr.mesa > 0 AND {$reg} AND {$ff1}"
        );
        $stFf->execute([$torneoId]);
        while ($row = $stFf->fetch(\PDO::FETCH_ASSOC)) {
            $r1v = \TorneoCampoNumerico::floatCalculo($row['resultado1'] ?? 0);
            $r2v = \TorneoCampoNumerico::floatCalculo($row['resultado2'] ?? 0);
            $snv = \TorneoCampoNumerico::floatCalculo($row['sancion'] ?? 0);
            $adj = max(0.0, $r1v - $snv);
            $pareceGanador = ($snv <= 0 && $r1v > $r2v) || ($snv > 0 && $adj > $r2v);
            if ($pareceGanador) {
                $ff_incoherente[] = [
                    'tipo' => 'ff_con_marcador_ganador',
                    'id' => (int) $row['id'],
                    'partida' => (int) $row['partida'],
                    'mesa' => (int) $row['mesa'],
                    'id_usuario' => (int) $row['id_usuario'],
                ];
            }
        }

        return [
            'integridad' => $integridad,
            'gdu' => $gdu,
            'ff_incoherente' => $ff_incoherente,
        ];
    }

    /**
     * Detecta Anomalía GDU: ganador con PF menor o igual al mejor PF perdedor de su mesa.
     * Cruza partiresul (ganadores vs perdedores) por torneo/ronda/mesa y excluye FF.
     *
     * @return list<array{partida:int, mesa:int, id_usuario:int, pf:float, max_pf_perdedor_mesa:float}>
     */
    public static function obtenerReporteAnomalias(int $torneoId): array
    {
        $pdo = \DB::pdo();
        $wReg = \PartiresulEstatusSql::whereRegistradoUno('w');
        $lReg = \PartiresulEstatusSql::whereRegistradoUno('l');
        $wFf1 = \PartiresulEstatusSql::whereFfUno('w');
        $lFf1 = \PartiresulEstatusSql::whereFfUno('l');

        $wR1 = \InscritosHelper::sqlExprColumnaNumerica('w.resultado1');
        $wR2 = \InscritosHelper::sqlExprColumnaNumerica('w.resultado2');
        $wSn = \InscritosHelper::sqlExprColumnaNumerica('w.sancion');
        $lR1 = \InscritosHelper::sqlExprColumnaNumerica('l.resultado1');
        $lR2 = \InscritosHelper::sqlExprColumnaNumerica('l.resultado2');
        $lSn = \InscritosHelper::sqlExprColumnaNumerica('l.sancion');
        /** Neto GDU: puntos a favor (resultado1) menos sanción en puntos (sancion), acotado como en el panel. */
        $wNet = "CASE WHEN ({$wSn}) <= 0 THEN ({$wR1}) ELSE GREATEST(0, ({$wR1}) - ({$wSn})) END";
        $lNet = "CASE WHEN ({$lSn}) <= 0 THEN ({$lR1}) ELSE GREATEST(0, ({$lR1}) - ({$lSn})) END";

        /* Subconsulta + filtro en WHERE: evita error MySQL «Unknown column 'w.sancion' in 'having clause'»
           al repetir expresiones con alias de tabla dentro de HAVING (resolución de columnas en HAVING). */
        $sql = "SELECT sub.partida, sub.mesa, sub.id_usuario, sub.pf, sub.max_pf_perdedor_mesa
                FROM (
                    SELECT
                        w.partida AS partida,
                        w.mesa AS mesa,
                        w.id_usuario AS id_usuario,
                        {$wNet} AS pf,
                        MAX({$lNet}) AS max_pf_perdedor_mesa
                    FROM partiresul w
                    INNER JOIN partiresul l
                        ON l.id_torneo = w.id_torneo
                        AND l.partida = w.partida
                        AND l.mesa = w.mesa
                        AND l.id <> w.id
                    WHERE w.id_torneo = ?
                        AND w.mesa > 0
                        AND {$wReg}
                        AND {$lReg}
                        AND NOT ({$wFf1})
                        AND NOT ({$lFf1})
                        AND (
                            (({$wSn}) <= 0 AND ({$wR1}) > ({$wR2}))
                            OR (({$wSn}) > 0 AND GREATEST(0, ({$wR1}) - ({$wSn})) > ({$wR2}))
                        )
                        AND (
                            (({$lSn}) <= 0 AND ({$lR1}) <= ({$lR2}))
                            OR (({$lSn}) > 0 AND GREATEST(0, ({$lR1}) - ({$lSn})) <= ({$lR2}))
                        )
                    GROUP BY w.partida, w.mesa, w.id_usuario, {$wNet}
                ) AS sub
                WHERE sub.pf <= sub.max_pf_perdedor_mesa + 0.00001
                ORDER BY sub.partida ASC, sub.mesa ASC, sub.id_usuario ASC";

        $st = $pdo->prepare($sql);
        $st->execute([$torneoId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'partida' => (int) ($row['partida'] ?? 0),
                'mesa' => (int) ($row['mesa'] ?? 0),
                'id_usuario' => (int) ($row['id_usuario'] ?? 0),
                'pf' => (float) ($row['pf'] ?? 0),
                'max_pf_perdedor_mesa' => (float) ($row['max_pf_perdedor_mesa'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $f
     */
    private static function filaPartiresulAJugadorPost(
        array $f,
        bool $ff,
        int $sancionFf,
        ?int $tarjeta = null,
        ?int $sancion = null
    ): array {
        $sBase = $sancion !== null
            ? min(80, max(0, $sancion))
            : ($ff ? min(80, max(0, $sancionFf)) : \TorneoCampoNumerico::intEstadistica($f['sancion'] ?? 0));
        $out = [
            'id' => (int) $f['id'],
            'id_usuario' => (int) $f['id_usuario'],
            'secuencia' => (int) $f['secuencia'],
            'resultado1' => (string) \TorneoCampoNumerico::intEstadistica($f['resultado1'] ?? 0),
            'resultado2' => (string) \TorneoCampoNumerico::intEstadistica($f['resultado2'] ?? 0),
            'tarjeta' => (string) ($tarjeta ?? \TorneoCampoNumerico::intEstadistica($f['tarjeta'] ?? 0)),
            'sancion' => (string) $sBase,
            'chancleta' => (string) \TorneoCampoNumerico::intEstadistica($f['chancleta'] ?? 0),
            'zapato' => (string) \TorneoCampoNumerico::intEstadistica($f['zapato'] ?? 0),
        ];
        if ($ff) {
            $out['ff'] = '1';
        }

        return $out;
    }

    private static function filaFfActiva(array $row): bool
    {
        $v = $row['ff'] ?? 0;
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return trim((string) $v) === '1';
    }
}
