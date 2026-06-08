<?php
declare(strict_types=1);

/**
 * Punto de entrada para sincronizar estadísticas desde partiresul hacia inscritos.
 *
 * POLÍTICA (implementación en modules/torneo_gestion.php):
 * - Sincronizar ganados/puntos/efectividad: al guardar resultados de mesa (sin reclasificar).
 * - Recalcular posición y ptosrnk: al cerrar ronda (generar la siguiente), al completar la última mesa
 *   de la última ronda, finalizar torneo, carga masiva o acción manual «Actualizar estadísticas».
 * - Consultas y reportes: leen posición persistida; no recalculan.
 *
 * @see actualizarEstadisticasInscritos()
 * @see recalcularPosiciones()
 */
final class RankingTorneoRecalc
{
    /** @var array<int, true> Evita repetir reclasificación final en la misma petición HTTP. */
    private static array $reclasificadoUltimaRondaEnPeticion = [];

    /** @var array<int, true> Evita repetir la sincronización del mismo torneo en una sola petición HTTP. */
    private static array $sincronizadoEnEstaPeticion = [];

    /**
     * True si la ronda programada final del torneo existe y todas sus mesas tienen resultados registrados.
     */
    public static function esUltimaRondaTorneoCompleta(int $torneo_id): bool
    {
        $torneo_id = (int) $torneo_id;
        if ($torneo_id <= 0) {
            return false;
        }
        try {
            $pdo = \DB::pdo();
            $stmt = $pdo->prepare('SELECT rondas FROM tournaments WHERE id = ? LIMIT 1');
            $stmt->execute([$torneo_id]);
            $totalRondas = (int) $stmt->fetchColumn();
            if ($totalRondas <= 0) {
                return false;
            }
            $stmt = $pdo->prepare('SELECT MAX(partida) FROM partiresul WHERE id_torneo = ?');
            $stmt->execute([$torneo_id]);
            $ultimaRonda = (int) $stmt->fetchColumn();
            if ($ultimaRonda < $totalRondas) {
                return false;
            }
            self::cargarTorneoGestion();
            if (! \function_exists('contarMesasIncompletas')) {
                return false;
            }

            return \contarMesasIncompletas($torneo_id, $ultimaRonda) === 0;
        } catch (\Throwable $e) {
            error_log('RankingTorneoRecalc::esUltimaRondaTorneoCompleta: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Cierra la clasificación final cuando se ingresa la última mesa de la última ronda (stats ya sincronizados).
     */
    public static function reclasificarSiUltimaRondaTorneoCompleta(int $torneo_id): bool
    {
        $torneo_id = (int) $torneo_id;
        if ($torneo_id <= 0 || isset(self::$reclasificadoUltimaRondaEnPeticion[$torneo_id])) {
            return false;
        }
        if (! self::esUltimaRondaTorneoCompleta($torneo_id)) {
            return false;
        }
        self::$reclasificadoUltimaRondaEnPeticion[$torneo_id] = true;
        self::cargarTorneoGestion();
        if (! \function_exists('recalcularRankingSegunModalidad')) {
            return false;
        }
        try {
            \recalcularRankingSegunModalidad($torneo_id);

            return true;
        } catch (\Throwable $e) {
            error_log('RankingTorneoRecalc::reclasificarSiUltimaRondaTorneoCompleta: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Solo agrega estadísticas de partiresul → inscritos (sin reclasificar posiciones).
     */
    public static function sincronizarEstadisticasPartidas(int $torneo_id): void
    {
        self::ejecutar($torneo_id, false);
    }

    /**
     * Sincroniza partidas y recalcula clasificación (cierre de ronda / finalización).
     */
    public static function sincronizarClasificacionCompleta(int $torneo_id): void
    {
        self::ejecutar($torneo_id, true);
    }

    /**
     * @deprecated Usar sincronizarEstadisticasPartidas() en lecturas o sincronizarClasificacionCompleta() al cerrar ronda.
     */
    public static function actualizarEstadisticasYRanking(int $torneo_id): void
    {
        self::sincronizarEstadisticasPartidas($torneo_id);
    }

    private static function ejecutar(int $torneo_id, bool $recalcularClasificacion): void
    {
        $torneo_id = (int) $torneo_id;
        if ($torneo_id <= 0) {
            return;
        }
        $cacheKey = $recalcularClasificacion ? -$torneo_id : $torneo_id;
        if (isset(self::$sincronizadoEnEstaPeticion[$cacheKey])) {
            return;
        }
        self::$sincronizadoEnEstaPeticion[$cacheKey] = true;
        self::cargarTorneoGestion();
        if (! function_exists('actualizarEstadisticasInscritos')) {
            return;
        }
        try {
            actualizarEstadisticasInscritos($torneo_id, $recalcularClasificacion);
        } catch (Throwable $e) {
            error_log('RankingTorneoRecalc: ' . $e->getMessage());
        }
    }

    private static function cargarTorneoGestion(): void
    {
        if (\function_exists('actualizarEstadisticasInscritos')) {
            return;
        }
        $path = dirname(__DIR__) . '/modules/torneo_gestion.php';
        if (! is_readable($path)) {
            return;
        }
        if (! defined('TORNEO_GESTION_SKIP_AUTH')) {
            define('TORNEO_GESTION_SKIP_AUTH', true);
        }
        if (! defined('TORNEO_GESTION_SKIP_ROUTER')) {
            define('TORNEO_GESTION_SKIP_ROUTER', true);
        }
        require_once $path;
    }
}
