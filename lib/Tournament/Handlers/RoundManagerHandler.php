<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use TorneoMesaAsignacionResolver;

require_once __DIR__ . '/../../PartiresulEstatusSql.php';

/**
 * Gestión de rondas: comprobación de mesas pendientes y generación de la siguiente ronda.
 */
final class RoundManagerHandler
{
    private function __construct()
    {
    }

    /**
     * Sincroniza inscritos desde partiresul antes de generar ronda (requiere torneo_gestion en flujos normales).
     */
    public static function syncInscritosStatsBeforeGeneracion(int $torneoId): void
    {
        if (\function_exists('actualizarEstadisticasInscritos')) {
            actualizarEstadisticasInscritos($torneoId, true);

            return;
        }
        require_once __DIR__ . '/../../InscritosPartiresulHelper.php';
        $pdo = \DB::pdo();
        $stmt = $pdo->prepare('SELECT DISTINCT id_usuario FROM inscritos WHERE torneo_id = ? AND estatus != 4');
        $stmt->execute([$torneoId]);
        while ($uid = $stmt->fetchColumn()) {
            \InscritosPartiresulHelper::actualizarEstadisticas((int) $uid, $torneoId);
        }
    }

    /**
     * Redirección tras validaciones / éxito de generación (panel torneo_gestion o tournament_admin).
     *
     * @param array<string, mixed> $options
     */
    private static function redirectUrlPanel(int $torneoId, array $options): string
    {
        if (($options['redirect_base'] ?? '') === 'tournament_admin') {
            return 'index.php?page=tournament_admin&torneo_id=' . $torneoId . '&action=generar_rondas';
        }
        if (($options['redirect_base'] ?? '') === 'op_especiales') {
            return 'index.php?page=op_especiales&torneo_id=' . $torneoId . '&view=carga';
        }
        if (function_exists('buildRedirectUrl')) {
            return buildRedirectUrl('panel', ['torneo_id' => $torneoId]);
        }

        return 'index.php?page=tournament_admin&torneo_id=' . $torneoId . '&action=generar_rondas';
    }

    /**
     * Cuenta mesas de una ronda sin resultados registrados (misma consulta que en torneo_gestion).
     */
    public static function contarMesasIncompletas(int $torneoId, int $ronda): int
    {
        $pdo = \DB::pdo();

        $sql = 'SELECT COUNT(DISTINCT pr.mesa) as mesas_incompletas
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            AND ' . \PartiresulEstatusSql::whereRegistradoNoCompleto('pr');

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (int) ($result['mesas_incompletas'] ?? 0);
    }

    /**
     * Última ronda con partidas y si se puede generar la siguiente (sin mesas abiertas en esa ronda).
     *
     * @return array{ultima_ronda: int, mesas_incompletas: int, puede_generar_ronda: bool}
     */
    public static function verificarMesasPendientes(int $torneoId): array
    {
        $pdo = \DB::pdo();
        // Misma semántica que inscripción en sitio / panel: última ronda con mesas reales; CAST evita MAX lexicográfico en partida VARCHAR (p. ej. ronda 10 < ronda 9).
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(CAST(partida AS UNSIGNED)), 0) FROM partiresul WHERE id_torneo = ? AND mesa > 0');
        $stmt->execute([$torneoId]);
        $ultima_ronda = (int) $stmt->fetchColumn();

        $mesas_incompletas = 0;
        $puede_generar = true;
        if ($ultima_ronda > 0) {
            $mesas_incompletas = self::contarMesasIncompletas($torneoId, $ultima_ronda);
            $puede_generar = $mesas_incompletas === 0;
        }

        return [
            'ultima_ronda' => $ultima_ronda,
            'mesas_incompletas' => $mesas_incompletas,
            'puede_generar_ronda' => $puede_generar,
        ];
    }

    /**
     * Genera la siguiente ronda (emparejamientos, mesas, inserts). Sin verificar permisos: el llamador debe hacerlo.
     *
     * @param array<string, mixed> $options redirect_base: 'tournament_admin' para volver al admin del torneo
     */
    public static function ejecutarGeneracionRonda(int $torneoId, array $options = []): void
    {
        try {
            require_once __DIR__ . '/../../Core/TorneoMesaAsignacionResolver.php';
            $pdo = \DB::pdo();

            // Solo estatus 1 (confirmado) cuentan para participar en el torneo
            require_once __DIR__ . '/../../InscritosHelper.php';
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND ' . \InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO);
            $stmt->execute([$torneoId]);
            $num_inscritos = (int) $stmt->fetchColumn();
            if ($num_inscritos < 4) {
                $_SESSION['error'] = 'No se puede generar ronda: se necesitan al menos 4 participantes inscritos y activos en el torneo. Actualmente hay ' . $num_inscritos . '.';
                header('Location: ' . self::redirectUrlPanel($torneoId, $options));
                exit;
            }

            // Obtener torneo para verificar modalidad y nombre
            $stmt = $pdo->prepare('SELECT nombre, rondas, modalidad FROM tournaments WHERE id = ?');
            $stmt->execute([$torneoId]);
            $torneo = $stmt->fetch(\PDO::FETCH_ASSOC);
            $total_rondas = (int) ($torneo['rondas'] ?? 0);
            $modalidad = (int) ($torneo['modalidad'] ?? 0);

            $es_torneo_equipos = ($modalidad === TorneoMesaAsignacionResolver::MODALIDAD_EQUIPOS);
            $mesaService = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);

            // Verificar que la última ronda esté completa
            $ultima_ronda = $mesaService->obtenerUltimaRonda($torneoId);

            if ($ultima_ronda > 0) {
                $todas_completas = method_exists($mesaService, 'todasLasMesasCompletas')
                    ? $mesaService->todasLasMesasCompletas($torneoId, $ultima_ronda)
                    : (self::contarMesasIncompletas($torneoId, $ultima_ronda) === 0);
                if (!$todas_completas) {
                    $mesas_incompletas = method_exists($mesaService, 'contarMesasIncompletas')
                        ? $mesaService->contarMesasIncompletas($torneoId, $ultima_ronda)
                        : self::contarMesasIncompletas($torneoId, $ultima_ronda);
                    $_SESSION['error'] = "No se puede generar una nueva ronda. Faltan resultados en {$mesas_incompletas} mesa(s) de la ronda {$ultima_ronda}";
                    header('Location: ' . self::redirectUrlPanel($torneoId, $options));
                    exit;
                }
            }

            // Un solo generador a la vez por torneo (torneos asociados por parent_event_id pueden generar en paralelo)
            $lockKey = 'mistorneos_torneo_' . $torneoId;
            if (strlen($lockKey) > 64) {
                $lockKey = substr(hash('sha256', $lockKey), 0, 64);
            }
            $stLock = $pdo->prepare('SELECT GET_LOCK(?, 60)');
            $stLock->execute([$lockKey]);
            if ((int) $stLock->fetchColumn() !== 1) {
                $_SESSION['error'] = 'Ya hay una generación de ronda en curso para este torneo. Espere unos segundos e intente de nuevo.';
                header('Location: ' . self::redirectUrlPanel($torneoId, $options));
                exit;
            }
            register_shutdown_function(static function () use ($lockKey): void {
                try {
                    $p = \DB::pdo();
                    $p->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
                } catch (\Throwable $e) {
                    // ignorar si la conexión ya cerró
                }
            });

            // Actualizar estadísticas antes de generar nueva ronda
            try {
                self::syncInscritosStatsBeforeGeneracion($torneoId);
            } catch (\Throwable $e) {
                $_SESSION['error'] = 'Error al actualizar estadísticas: ' . $e->getMessage();
                header('Location: ' . self::redirectUrlPanel($torneoId, $options));
                exit;
            }

            $proxima_ronda = $ultima_ronda + 1;
            $msg_no_presentes = '';

            // Antes de generar la 3.ª ronda: marcar como retirados a los no presentes (pendientes sin ninguna partida)
            if ($proxima_ronda === 3) {
                $marcados_retirados = marcarNoPresentesRetiradosAntesRonda3($torneoId);
                if ($marcados_retirados > 0) {
                    $msg_no_presentes = $marcados_retirados . ' inscrito(s) no presente(s) marcado(s) como retirado(s).';
                }
                // Revalidar que sigan habiendo al menos 4 confirmados tras retirar no presentes
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND ' . \InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO);
                $stmt->execute([$torneoId]);
                if ((int) $stmt->fetchColumn() < 4) {
                    $_SESSION['error'] = 'No se puede generar la ronda 3: tras marcar no presentes quedan menos de 4 participantes confirmados.';
                    header('Location: ' . self::redirectUrlPanel($torneoId, $options));
                    exit;
                }
            }

            // Obtener estrategia de asignación (para equipos puede ser: secuencial, intercalada_13_24, intercalada_14_23, por_rendimiento)
            if ($es_torneo_equipos) {
                $estrategia = $options['estrategia_asignacion'] ?? ($_POST['estrategia_asignacion'] ?? 'secuencial');
            } else {
                $estrategia = $options['estrategia_ronda2'] ?? ($_POST['estrategia_ronda2'] ?? 'separar');
            }

            $resultado = $es_torneo_equipos
                ? TorneoMesaAsignacionResolver::generarAsignacionRondaEquipos(
                    $torneoId,
                    $proxima_ronda,
                    $total_rondas,
                    $estrategia
                )
                : $mesaService->generarAsignacionRonda(
                    $torneoId,
                    $proxima_ronda,
                    $total_rondas,
                    $estrategia
                );

            if (! empty($resultado['success'])) {
                $mensaje = (string) ($resultado['message'] ?? 'Ronda generada correctamente.');
                if (isset($resultado['total_mesas'])) {
                    $mensaje .= ': ' . $resultado['total_mesas'] . ' mesas';
                }
                if (isset($resultado['total_equipos'])) {
                    $mensaje .= ', ' . $resultado['total_equipos'] . ' equipos';
                }
                if (isset($resultado['jugadores_bye']) && $resultado['jugadores_bye'] > 0) {
                    $mensaje .= ', ' . $resultado['jugadores_bye'] . ' rezagado(s) sin mesa (retirados del torneo, sin partida en partiresul)';
                }
                if (isset($resultado['excedentes_club_interclub_parejas']) && (int) $resultado['excedentes_club_interclub_parejas'] > 0) {
                    $mensaje .= ', ' . (int) $resultado['excedentes_club_interclub_parejas'] . ' pareja(s) excedentes de club (sin mesa; no BYE ni estadística de partida en esa ronda)';
                }
                if (! empty($resultado['advertencias_interclub_r1']) && is_array($resultado['advertencias_interclub_r1'])) {
                    $mensaje .= ' ' . implode(' ', array_map('strval', $resultado['advertencias_interclub_r1']));
                }
                if ($msg_no_presentes !== '') {
                    $mensaje .= '. ' . $msg_no_presentes;
                }
                $_SESSION['success'] = $mensaje;

                // Encolar notificaciones masivas (Telegram + campanita web) usando plantilla 'nueva_ronda'
                try {
                    $stmtJug = $pdo->prepare("
                    SELECT u.id, u.nombre, u.telegram_chat_id,
                           COALESCE(i.posicion, 0) AS posicion, COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
                           COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
                    FROM inscritos i
                    INNER JOIN usuarios u ON (
                        u.id = i.id_usuario
                        OR (
                            u.numfvd = i.id_usuario
                            AND EXISTS (
                                SELECT 1 FROM tournaments tx
                                WHERE tx.id = i.torneo_id AND tx.club_responsable = 7
                            )
                        )
                    )
                    WHERE i.torneo_id = ? AND " . \InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . '
                ');
                    $stmtJug->execute([$torneoId]);
                    $jugadores = $stmtJug->fetchAll(\PDO::FETCH_ASSOC);

                    // Mesa y pareja para esta ronda (partiresul ya tiene la asignación recién generada)
                    $mesaPareja = [];
                    $stmtMesa = $pdo->prepare("
                    SELECT pr.id_usuario, pr.mesa, pr_p.id_usuario AS pareja_id, u_pareja.nombre AS pareja_nombre
                    FROM partiresul pr
                    LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                        AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
                    LEFT JOIN usuarios u_pareja ON (
                        u_pareja.id = pr_p.id_usuario
                        OR (
                            u_pareja.numfvd = pr_p.id_usuario
                            AND EXISTS (
                                SELECT 1 FROM tournaments tx
                                WHERE tx.id = pr_p.id_torneo AND tx.club_responsable = 7
                            )
                        )
                    )
                    WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
                ");
                    $stmtMesa->execute([$torneoId, $proxima_ronda]);
                    while ($row = $stmtMesa->fetch(\PDO::FETCH_ASSOC)) {
                        $mesaPareja[(int) $row['id_usuario']] = [
                            'mesa' => (string) $row['mesa'],
                            'pareja_id' => (int) ($row['pareja_id'] ?? 0),
                            'pareja' => trim((string) ($row['pareja_nombre'] ?? '')) ?: '—',
                        ];
                    }

                    require_once __DIR__ . '/../../app_helpers.php';
                    foreach ($jugadores as &$j) {
                        $uid = (int) $j['id'];
                        $j['mesa'] = $mesaPareja[$uid]['mesa'] ?? '—';
                        $j['pareja_id'] = $mesaPareja[$uid]['pareja_id'] ?? 0;
                        $j['pareja'] = $mesaPareja[$uid]['pareja'] ?? '—';
                        $j['url_resumen'] = \AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneoId, 'inscrito_id' => $uid, 'from' => 'notificaciones']);
                        $j['url_clasificacion'] = \AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'posiciones', 'torneo_id' => $torneoId, 'from' => 'notificaciones']);
                    }
                    unset($j);

                    $titulo = $torneo['nombre'] ?? 'Torneo';
                    if (!empty($jugadores)) {
                        require_once __DIR__ . '/../../NotificationManager.php';
                        $nm = new \NotificationManager($pdo);
                        $nm->programarRondaMasiva($jugadores, $titulo, $proxima_ronda, null, 'nueva_ronda', $torneoId);
                    }
                } catch (\Throwable $e) {
                    error_log('Notificaciones ronda: ' . $e->getMessage());
                }
            } else {
                $_SESSION['error'] = (string) ($resultado['message'] ?? 'No se pudo generar la ronda.');
            }
        } catch (\Throwable $e) {
            error_log('Error al generar ronda: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $_SESSION['error'] = 'Error al generar ronda: ' . $e->getMessage();
        }

        // Permanecer siempre en el panel: éxito o error. El usuario irá al formulario de resultados cuando lo requiera.
        if (isset($torneoId) && $torneoId > 0) {
            header('Location: ' . self::redirectUrlPanel($torneoId, $options));
            exit;
        }

        header('Location: ' . self::redirectUrlPanel($torneoId, $options));
        exit;
    }
}
