<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use Exception;
use PDO;

/**
 * Acciones de torneo extraídas de torneo_gestion.php (Fase 3).
 * Depende de funciones globales ya cargadas desde modules/torneo_gestion.php
 * (buildRedirectUrl, verificarPermisosTorneo, etc.) y de lib/partiresul_efectividad_funcs.php
 * para calcularEfectividad / forfait / tarjeta grave (también cargado por torneo_gestion).
 */
final class TournamentActionHandler
{
    /**
     * Guarda resultados de una mesa (4 jugadores): validación, efectividad, UPDATE partiresul.
     *
     * @return array{
     *   success: bool,
     *   redirect_url: string,
     *   session_error?: string,
     *   session_info?: string,
     *   limpiar_formulario?: bool,
     *   resultados_guardados?: bool
     * }
     */
    public static function guardarResultados(int $torneoId, array $datosPost, int $userId, bool $isAdminGeneral): array
    {
        $torneo_id = $torneoId > 0 ? $torneoId : (int) ($datosPost['torneo_id'] ?? 0);
        $ronda = (int) ($datosPost['ronda'] ?? 0);
        $mesa = (int) ($datosPost['mesa'] ?? 0);

        $urlRegistrar = \buildRedirectUrl('registrar_resultados', [
            'torneo_id' => $torneo_id,
            'ronda' => $ronda,
            'mesa' => $mesa > 0 ? $mesa : 1,
        ]) . '#formResultados';

        if ($mesa <= 0) {
            return [
                'success' => false,
                'session_error' => 'No hay una mesa válida asignada. Seleccione una mesa de la lista antes de guardar.',
                'redirect_url' => \buildRedirectUrl('registrar_resultados', ['torneo_id' => $torneo_id, 'ronda' => $ronda]),
            ];
        }

        $pdo = null;

        try {
            \verificarPermisosTorneo($torneo_id, $userId, $isAdminGeneral);

            $current = \Auth::user();
            $user_role = $current['role'] ?? '';
            if ($user_role === 'operador') {
                $mesas_operador = \obtenerMesasAsignadasOperador($torneo_id, $ronda, $userId, $user_role);
                if ($mesas_operador !== null && ! in_array($mesa, $mesas_operador, true)) {
                    throw new Exception("No tiene permiso para registrar resultados en la mesa #{$mesa}. Solo puede operar las mesas asignadas a su ámbito.");
                }
            }

            $jugadores = $datosPost['jugadores'] ?? [];
            $observaciones = trim((string) ($datosPost['observaciones'] ?? ''));

            if (empty($jugadores) || ! is_array($jugadores) || count($jugadores) != 4) {
                throw new Exception('Debe haber exactamente 4 jugadores por mesa');
            }

            $pdo = \DB::pdo();

            $stmt = $pdo->prepare('
            SELECT COUNT(DISTINCT pr.mesa) as total_mesas, MAX(CAST(pr.mesa AS UNSIGNED)) as max_mesa
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
        ');
            $stmt->execute([$torneo_id, $ronda]);
            $mesasInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $maxMesa = (int) ($mesasInfo['max_mesa'] ?? 0);

            $stmt = $pdo->prepare('
            SELECT COUNT(*) as existe
            FROM partiresul
            WHERE id_torneo = ? AND partida = ? AND mesa = ?
        ');
            $stmt->execute([$torneo_id, $ronda, $mesa]);
            $mesaExiste = $stmt->fetch(PDO::FETCH_ASSOC);

            if ((int) $mesaExiste['existe'] === 0) {
                throw new Exception("La mesa #{$mesa} no existe en la ronda {$ronda}. " .
                    ($maxMesa > 0 ? "El número máximo de mesa asignada es {$maxMesa}." : 'No hay mesas asignadas para esta ronda.'));
            }

            if ($maxMesa > 0 && $mesa > $maxMesa) {
                throw new Exception("La mesa #{$mesa} no existe. El número máximo de mesa asignada es {$maxMesa}.");
            }

            $pdo->beginTransaction();

            $sessionInfo = self::aplicarResultadosMesaCore($pdo, $torneo_id, $ronda, $mesa, $jugadores, $userId, $observaciones);

            $pdo->commit();

            try {
                \actualizarEstadisticasInscritos($torneo_id);
            } catch (Exception $e) {
                error_log('Error al actualizar estadísticas después de guardar resultados: ' . $e->getMessage());
            }

            if (! \class_exists(\RankingTorneoRecalc::class, false)) {
                require_once dirname(__DIR__, 2) . '/RankingTorneoRecalc.php';
            }
            \RankingTorneoRecalc::reclasificarSiUltimaRondaTorneoCompleta($torneo_id);

            self::postCommitResultadosMesa($pdo, $torneo_id, $ronda, $mesa);

            $out = [
                'success' => true,
                'redirect_url' => $urlRegistrar,
                'limpiar_formulario' => true,
                'resultados_guardados' => true,
            ];
            if ($sessionInfo !== null) {
                $out['session_info'] = $sessionInfo;
            }

            return $out;
        } catch (Exception $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'success' => false,
                'session_error' => 'Error al guardar resultados: ' . $e->getMessage(),
                'redirect_url' => $urlRegistrar,
            ];
        }
    }

    /**
     * Núcleo compartido con el formulario de ingreso de resultados: modalidad, SancionesHelper, efectividad, UPDATE partiresul.
     * No valida permisos ni existencia de mesa (hace el caller). No hace commit.
     *
     * @param array<int, array<string, mixed>> $jugadores Exactamente 4 jugadores (mismo formato que $_POST['jugadores'])
     */
    public static function aplicarResultadosMesaCore(
        PDO $pdo,
        int $torneo_id,
        int $ronda,
        int $mesa,
        array $jugadores,
        int $userId,
        string $observaciones = ''
    ): ?string {
        if (! \function_exists('calcularEfectividad')) {
            require_once dirname(__DIR__, 2) . '/partiresul_efectividad_funcs.php';
        }
        require_once dirname(__DIR__, 2) . '/PartiresulTriunfoGffHelper.php';
        \PartiresulTriunfoGffHelper::ensureColumna($pdo);
        $persistirTriunfoGff = \PartiresulTriunfoGffHelper::tieneColumna($pdo);

        $stmt = $pdo->prepare('SELECT modalidad, puntos FROM tournaments WHERE id = ?');
        $stmt->execute([$torneo_id]);
        $torneoRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $modalidadTorneo = (int) ($torneoRow['modalidad'] ?? 0);
        $puntosTorneo = (int) ($torneoRow['puntos'] ?? 100);

        $sessionInfo = null;

        $jugadores = array_values($jugadores);
        if (in_array($modalidadTorneo, [2, 4], true)) {
            self::unificarSancionForfaitTarjetaPorPareja($jugadores);
        }

        $codigoPorUsuario = [];
        if (in_array($modalidadTorneo, [2, 4], true)) {
            $idsUsuariosPost = array_map(static function ($j) {
                return (int) ($j['id_usuario'] ?? 0);
            }, $jugadores);
            $idsUsuariosPost = array_values(array_filter($idsUsuariosPost));

            if (! empty($idsUsuariosPost)) {
                $placeholdersUsuarios = implode(',', array_fill(0, count($idsUsuariosPost), '?'));
                $stmtCodigos = $pdo->prepare("
                    SELECT id_usuario, codigo_equipo
                    FROM inscritos
                    WHERE torneo_id = ? AND id_usuario IN ($placeholdersUsuarios)
                ");
                $stmtCodigos->execute(array_merge([$torneo_id], $idsUsuariosPost));
                foreach ($stmtCodigos->fetchAll(PDO::FETCH_ASSOC) as $filaCodigo) {
                    $codigoPorUsuario[(int) $filaCodigo['id_usuario']] = trim((string) ($filaCodigo['codigo_equipo'] ?? ''));
                }

                $controlPorCodigo = [];
                foreach ($jugadores as $jugadorPost) {
                    $idUsuarioPost = (int) ($jugadorPost['id_usuario'] ?? 0);
                    $codigoEquipo = trim((string) ($codigoPorUsuario[$idUsuarioPost] ?? ''));
                    if ($codigoEquipo === '') {
                        continue;
                    }
                    if (! isset($controlPorCodigo[$codigoEquipo])) {
                        $controlPorCodigo[$codigoEquipo] = [
                            'sancion' => min(80, max(0, \TorneoCampoNumerico::intEstadistica($jugadorPost['sancion'] ?? 0))),
                            'ff' => (isset($jugadorPost['ff']) && ($jugadorPost['ff'] == '1' || $jugadorPost['ff'] === true || $jugadorPost['ff'] === 'on')) ? 1 : 0,
                            'tarjeta' => \TorneoCampoNumerico::codigoTarjeta($jugadorPost['tarjeta'] ?? 0),
                        ];
                    }
                }

                foreach ($jugadores as &$jugadorPost) {
                    $idUsuarioPost = (int) ($jugadorPost['id_usuario'] ?? 0);
                    $codigoEquipo = trim((string) ($codigoPorUsuario[$idUsuarioPost] ?? ''));
                    if ($codigoEquipo !== '' && isset($controlPorCodigo[$codigoEquipo])) {
                        $jugadorPost['sancion'] = (int) $controlPorCodigo[$codigoEquipo]['sancion'];
                        $jugadorPost['ff'] = (int) $controlPorCodigo[$codigoEquipo]['ff'];
                        $jugadorPost['tarjeta'] = (int) $controlPorCodigo[$codigoEquipo]['tarjeta'];
                    }
                }
                unset($jugadorPost);
            }
        }

        require_once dirname(__DIR__, 2) . '/SancionesHelper.php';

        $ids_usuarios_mesa = array_map(function ($j) {
            return (int) ($j['id_usuario'] ?? 0);
        }, $jugadores);
        $ids_usuarios_mesa = array_filter($ids_usuarios_mesa);
        $tarjetaPreviaPorUsuario = \SancionesHelper::getTarjetaPreviaDesdePartidasAnteriores($pdo, $torneo_id, $ronda, array_values($ids_usuarios_mesa));
        $tarjetaPreviaPorEquipo = [];
        if (in_array($modalidadTorneo, [2, 4], true) && ! empty($codigoPorUsuario)) {
            foreach ($tarjetaPreviaPorUsuario as $idUsuarioPrevio => $tarjetaPrevia) {
                $codigoEquipoPrevio = trim((string) ($codigoPorUsuario[(int) $idUsuarioPrevio] ?? ''));
                if ($codigoEquipoPrevio === '') {
                    continue;
                }
                $valorPrevio = (int) $tarjetaPrevia;
                $tarjetaPreviaPorEquipo[$codigoEquipoPrevio] = max((int) ($tarjetaPreviaPorEquipo[$codigoEquipoPrevio] ?? 0), $valorPrevio);
            }
        }

        $modoMesa = \detectarModoCalculoMesa($jugadores);
        $hayTarjetaGraveEnMesa = ($modoMesa === 'tarjeta_grave');
        $hayForfaitEnMesa = ($modoMesa === 'forfait');

        \normalizarResultadosEntradaMesa($jugadores, $hayForfaitEnMesa, $hayTarjetaGraveEnMesa, $puntosTorneo);

        $esEmpateManoNula = false;
        if (! $hayForfaitEnMesa && ! $hayTarjetaGraveEnMesa) {
            $puntosParejaA = null;
            $puntosParejaB = null;
            foreach ($jugadores as $jugador) {
                $sec = (int) ($jugador['secuencia'] ?? 0);
                $r1 = \TorneoCampoNumerico::intEstadistica($jugador['resultado1'] ?? 0);
                if (($sec === 1 || $sec === 2) && $puntosParejaA === null) {
                    $puntosParejaA = $r1;
                }
                if (($sec === 3 || $sec === 4) && $puntosParejaB === null) {
                    $puntosParejaB = $r1;
                }
            }
            if ($puntosParejaA !== null && $puntosParejaB !== null && $puntosParejaA > 0 && $puntosParejaA === $puntosParejaB) {
                $esEmpateManoNula = true;
            }
        }

        $datosJugadores = [];
        foreach ($jugadores as $index => $jugador) {
            $id = (int) ($jugador['id'] ?? 0);
            $id_usuario = (int) ($jugador['id_usuario'] ?? 0);
            $secuencia = (int) ($jugador['secuencia'] ?? 0);
            $resultado1 = \TorneoCampoNumerico::intEstadistica($jugador['resultado1'] ?? 0);
            $resultado2 = \TorneoCampoNumerico::intEstadistica($jugador['resultado2'] ?? 0);
            $ff = \torneoFfDesdeEntrada($jugador['ff'] ?? 0);
            $tarjeta = \TorneoCampoNumerico::codigoTarjeta($jugador['tarjeta'] ?? 0);
            $sancion = \TorneoCampoNumerico::intEstadistica($jugador['sancion'] ?? 0);
            $sancion = min(80, max(0, $sancion));
            $chancleta = \TorneoCampoNumerico::intEstadistica($jugador['chancleta'] ?? 0);
            $zapato = \TorneoCampoNumerico::intEstadistica($jugador['zapato'] ?? 0);

            if ($resultado1 < 0 || $resultado2 < 0) {
                throw new Exception(sprintf(
                    'Los puntos (resultado1/resultado2) no pueden ser negativos (jugador %d). Contexto: torneo=%d ronda=%d mesa=%d | id_usuario=%d secuencia=%d | resultado1=%d resultado2=%d | ff=%d tarjeta=%d sancion=%d | mesa_ff=%s mesa_tarjeta_grave=%s',
                    $index + 1,
                    $torneo_id,
                    $ronda,
                    $mesa,
                    $id_usuario,
                    $secuencia,
                    $resultado1,
                    $resultado2,
                    $ff,
                    $tarjeta,
                    $sancion,
                    $hayForfaitEnMesa ? 'si' : 'no',
                    $hayTarjetaGraveEnMesa ? 'si' : 'no'
                ));
            }

            if ($id_usuario == 0 || $secuencia == 0) {
                throw new Exception(sprintf(
                    'Datos incompletos para el jugador %d. Contexto: torneo=%d ronda=%d mesa=%d | id_usuario=%d secuencia=%d',
                    $index + 1,
                    $torneo_id,
                    $ronda,
                    $mesa,
                    $id_usuario,
                    $secuencia
                ));
            }

            $maximoPermitido = (int) round($puntosTorneo * 1.6);
            if ($resultado1 > $maximoPermitido) {
                throw new Exception(sprintf(
                    'El resultado1 del jugador %d (%d) excede el máximo permitido (%d). Contexto: torneo=%d ronda=%d mesa=%d | id_usuario=%d secuencia=%d | resultado2=%d ff=%d tarjeta=%d sancion=%d',
                    $index + 1,
                    $resultado1,
                    $maximoPermitido,
                    $torneo_id,
                    $ronda,
                    $mesa,
                    $id_usuario,
                    $secuencia,
                    $resultado2,
                    $ff,
                    $tarjeta,
                    $sancion
                ));
            }
            if ($resultado2 > $maximoPermitido) {
                throw new Exception(sprintf(
                    'El resultado2 del jugador %d (%d) excede el máximo permitido (%d). Contexto: torneo=%d ronda=%d mesa=%d | id_usuario=%d secuencia=%d | resultado1=%d ff=%d tarjeta=%d sancion=%d',
                    $index + 1,
                    $resultado2,
                    $maximoPermitido,
                    $torneo_id,
                    $ronda,
                    $mesa,
                    $id_usuario,
                    $secuencia,
                    $resultado1,
                    $ff,
                    $tarjeta,
                    $sancion
                ));
            }

            $esParejaA = ($secuencia == 1 || $secuencia == 2);

            if (in_array($modalidadTorneo, [2, 4], true)) {
                $codigoEquipoJugador = trim((string) ($codigoPorUsuario[$id_usuario] ?? ''));
                $tarjetaInscritos = (int) ($tarjetaPreviaPorEquipo[$codigoEquipoJugador] ?? ($tarjetaPreviaPorUsuario[$id_usuario] ?? 0));
            } else {
                $tarjetaInscritos = (int) ($tarjetaPreviaPorUsuario[$id_usuario] ?? 0);
            }
            if ($sancion > 0 || $tarjeta > 0) {
                $procesado = \SancionesHelper::procesar($sancion, $tarjeta, $tarjetaInscritos);
                $sancionParaCalculo = $procesado['sancion_para_calculo'];
                $tarjeta = $procesado['tarjeta'];
                $sancion = $procesado['sancion_guardar'];
            } else {
                $sancionParaCalculo = 0;
            }
            $resultado1Ajustado = max(0, $resultado1 - $sancionParaCalculo);

            $datosJugadores[] = [
                'id' => $id,
                'id_usuario' => $id_usuario,
                'secuencia' => $secuencia,
                'resultado1' => $resultado1,
                'resultado2' => $resultado2,
                'resultado1Ajustado' => $resultado1Ajustado,
                'ff' => $ff,
                'tarjeta' => $tarjeta,
                'sancion' => $sancion,
                'sancion_para_calculo' => $sancionParaCalculo,
                'chancleta' => $chancleta,
                'zapato' => $zapato,
                'esParejaA' => $esParejaA,
                'index' => $index,
            ];
        }

        foreach ($datosJugadores as $jugador) {
            $id = $jugador['id'];
            $id_usuario = $jugador['id_usuario'];
            $secuencia = $jugador['secuencia'];
            $resultado1 = $jugador['resultado1'];
            $resultado2 = $jugador['resultado2'];
            $resultado1Ajustado = $jugador['resultado1Ajustado'];
            $ff = $jugador['ff'];
            $tarjeta = $jugador['tarjeta'];
            $sancion = $jugador['sancion'];
            $chancleta = $jugador['chancleta'];
            $zapato = $jugador['zapato'];
            $idx = (int) $jugador['index'];

            if ($esEmpateManoNula) {
                $efectividad = 0;
                $resultado1 = 0;
                $resultado2 = 0;
            } elseif ($hayTarjetaGraveEnMesa) {
                $calculoTarjeta = \calcularEfectividadTarjetaGrave($tarjeta == 3 || $tarjeta == 4, $puntosTorneo);
                $efectividad = $calculoTarjeta['efectividad'];
                $resultado1 = $calculoTarjeta['resultado1'];
                $resultado2 = $calculoTarjeta['resultado2'];
            } elseif ($hayForfaitEnMesa) {
                $calculoForfait = \calcularEfectividadForfait($ff == 1, $puntosTorneo);
                $efectividad = $calculoForfait['efectividad'];
                $resultado1 = $calculoForfait['resultado1'];
                $resultado2 = $calculoForfait['resultado2'];
            } else {
                $sancionParaCalc = $jugador['sancion_para_calculo'] ?? $jugador['sancion'] ?? 0;
                if ($sancionParaCalc > 0) {
                    $evaluacionSancion = \evaluarSancionIndividual($resultado1, $resultado2, $sancionParaCalc, $puntosTorneo);
                    $efectividad = $evaluacionSancion['efectividad'];
                } else {
                    $efectividad = \calcularEfectividad($resultado1Ajustado, $resultado2, $puntosTorneo, $ff, $tarjeta, 0);
                }
            }

            $triunfoGff = 0;
            if (! $esEmpateManoNula) {
                $triunfoGff = \calcularTriunfoGffJugadorMesa(
                    $hayForfaitEnMesa,
                    $hayTarjetaGraveEnMesa,
                    $ff,
                    $tarjeta,
                    $resultado1,
                    $resultado2,
                    $efectividad
                );
            }

            $setTriunfoGff = $persistirTriunfoGff ? ",\n                        triunfo_gff = ?" : '';
            $paramsTriunfoGff = $persistirTriunfoGff ? [$triunfoGff] : [];

            if ($id > 0) {
                $sql = 'UPDATE partiresul SET 
                        resultado1 = ?,
                        resultado2 = ?,
                        efectividad = ?,
                        ff = ?,
                        tarjeta = ?,
                        sancion = ?,
                        chancleta = ?,
                        zapato = ?,
                        fecha_partida = NOW(),
                        registrado_por = ?,
                        registrado = 1' . $setTriunfoGff . '
                        WHERE id = ?';

                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute(array_merge([
                    $resultado1, $resultado2, $efectividad, $ff, $tarjeta,
                    $sancion, $chancleta, $zapato, $userId,
                ], $paramsTriunfoGff, [$id]));

                if (! $result || $stmt->rowCount() == 0) {
                    throw new Exception('No se pudo actualizar el registro del jugador ' . ($idx + 1) . " (ID: $id)");
                }
            } else {
                $sql = 'UPDATE partiresul SET 
                        resultado1 = ?,
                        resultado2 = ?,
                        efectividad = ?,
                        ff = ?,
                        tarjeta = ?,
                        sancion = ?,
                        chancleta = ?,
                        zapato = ?,
                        fecha_partida = NOW(),
                        registrado_por = ?,
                        registrado = 1' . $setTriunfoGff . '
                        WHERE id_torneo = ? AND partida = ? AND mesa = ? 
                        AND id_usuario = ? AND secuencia = ?';

                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute(array_merge([
                    $resultado1, $resultado2, $efectividad, $ff, $tarjeta,
                    $sancion, $chancleta, $zapato, $userId,
                ], $paramsTriunfoGff, [
                    $torneo_id, $ronda, $mesa, $id_usuario, $secuencia,
                ]));

                if (! $result || $stmt->rowCount() == 0) {
                    throw new Exception('No se pudo actualizar el registro del jugador ' . ($idx + 1) . " (usuario: $id_usuario, secuencia: $secuencia)");
                }
            }
        }

        if ($observaciones !== '') {
            $stmt = $pdo->prepare('UPDATE partiresul SET observaciones = ? WHERE id_torneo = ? AND partida = ? AND mesa = ?');
            $stmt->execute([$observaciones, $torneo_id, $ronda, $mesa]);
        }

        $idsTarjetaNegra = [];
        foreach ($datosJugadores as $j) {
            if ((int) ($j['tarjeta'] ?? 0) === \SancionesHelper::TARJETA_NEGRA) {
                $idsTarjetaNegra[] = (int) $j['id_usuario'];
            }
        }
        if (! empty($idsTarjetaNegra)) {
            require_once dirname(__DIR__, 2) . '/InscritosHelper.php';
            $placeholders = implode(',', array_fill(0, count($idsTarjetaNegra), '?'));
            $stmt = $pdo->prepare("UPDATE inscritos SET estatus = ? WHERE torneo_id = ? AND id_usuario IN ($placeholders)");
            $stmt->execute(array_merge([\InscritosHelper::ESTATUS_RETIRADO_NUM, $torneo_id], $idsTarjetaNegra));
            $n = count($idsTarjetaNegra);
            $sessionInfo = $n === 1
                ? 'Jugador marcado como retirado del torneo por tarjeta negra. No participará en rondas futuras (asumido como BYE).'
                : "{$n} jugadores marcados como retirados del torneo por tarjeta negra. No participarán en rondas futuras (asumidos como BYE).";
        }
        if ($esEmpateManoNula) {
            $sessionInfo = 'Empate en tranque registrado como Mano Nula: 0 puntos para ambas parejas.';
        }

        return $sessionInfo;
    }

    /**
     * Modalidad parejas (2, 4): el formulario envía una sola línea de sanción / forfait / tarjeta por pareja.
     * Copia los mismos valores a ambos integrantes (índices 0–1 y 2–3) antes de guardar en partiresul.
     *
     * @param array<int, array<string, mixed>> $jugadores
     */
    private static function unificarSancionForfaitTarjetaPorPareja(array &$jugadores): void
    {
        foreach ([[0, 1], [2, 3]] as [$a, $b]) {
            if (! isset($jugadores[$a], $jugadores[$b]) || ! is_array($jugadores[$a]) || ! is_array($jugadores[$b])) {
                continue;
            }
            $s = (int) ($jugadores[$a]['sancion'] ?? $jugadores[$b]['sancion'] ?? 0);
            if ($s > 80) {
                $s = 80;
            }
            if ($s < 0) {
                $s = 0;
            }
            $jugadores[$a]['sancion'] = (string) $s;
            $jugadores[$b]['sancion'] = (string) $s;
            $t = (int) ($jugadores[$a]['tarjeta'] ?? $jugadores[$b]['tarjeta'] ?? 0);
            $jugadores[$a]['tarjeta'] = (string) $t;
            $jugadores[$b]['tarjeta'] = (string) $t;
            $ffA = isset($jugadores[$a]['ff']) && ($jugadores[$a]['ff'] === '1' || $jugadores[$a]['ff'] === true || $jugadores[$a]['ff'] === 'on');
            $ffB = isset($jugadores[$b]['ff']) && ($jugadores[$b]['ff'] === '1' || $jugadores[$b]['ff'] === true || $jugadores[$b]['ff'] === 'on');
            if ($ffA || $ffB) {
                $jugadores[$a]['ff'] = '1';
                $jugadores[$b]['ff'] = '1';
            } else {
                unset($jugadores[$a]['ff'], $jugadores[$b]['ff']);
            }
        }
    }

    /**
     * Tras commit: ventana de correcciones al cerrar última mesa y notificaciones (torneo_gestion.php).
     */
    private static function postCommitResultadosMesa(PDO $pdo, int $torneo_id, int $ronda, int $mesa): void
    {
        try {
            \ensureTournamentsCorreccionesCierreColumn();
            $rondas_gen = \obtenerRondasGeneradas($torneo_id);
            $ultima_r = ! empty($rondas_gen) ? max(array_column($rondas_gen, 'num_ronda')) : 0;
            $stmt_tr = $pdo->prepare('SELECT rondas FROM tournaments WHERE id = ?');
            $stmt_tr->execute([$torneo_id]);
            $total_r = (int) $stmt_tr->fetchColumn();
            $mesas_inc = $ultima_r > 0 ? \contarMesasIncompletas($torneo_id, $ultima_r) : 1;
            if ($total_r > 0 && $ultima_r >= $total_r && $mesas_inc === 0) {
                $stmt_up = $pdo->prepare("UPDATE tournaments SET correcciones_cierre_at = NOW() + INTERVAL 20 MINUTE WHERE id = ? AND (correcciones_cierre_at IS NULL OR correcciones_cierre_at = '0000-00-00 00:00:00')");
                $stmt_up->execute([$torneo_id]);
            }
        } catch (Exception $e) {
            // Ignorar
        }

        if ($mesa > 0) {
            try {
                \enviarNotificacionesResultadosMesa($pdo, $torneo_id, $ronda, $mesa);
            } catch (Exception $e) {
                error_log('Error al enviar notificaciones de resultados de mesa: ' . $e->getMessage());
            }
        }
    }
}
