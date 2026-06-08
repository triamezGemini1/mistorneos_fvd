<?php
/**
 * Helper para el sistema de disciplina en torneos.
 * Procesa Tarjetas Amarillas y Rojas según las reglas establecidas.
 *
 * Reglas:
 * - Sanción 40 pts: Resta 40 del resultado1. Sin tarjeta amarilla.
 * - Sanción 80 pts: Resta 80 del resultado1. Si inscritos.tarjeta > 0 → tarjeta siguiente (Roja/Negra); si no → Amarilla.
 * - La tarjeta se guarda solo en partiresul; inscritos se actualiza vía actualizarEstadisticasInscritos.
 * - Tarjeta previa = desde partiresul de partidas ANTERIORES (excluir la partida actual para evitar doble escalación al re-editar).
 * - Tarjeta negra (4): Al guardar resultados, el jugador se marca como retirado del torneo (inscritos.estatus=4).
 *   No participará en rondas futuras; se asume como BYE para el resto del torneo.
 */
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/TorneoCampoNumerico.php';

/**
 * Disciplina / tarjetas: misma base numérica que Individual ({@see TorneoCampoNumerico}).
 * Nunca operar con valores crudos de BD sin normalizar (evita 'pendiente' en columnas DOUBLE).
 */
class SancionesHelper {

    /** Códigos de tarjeta (inscritos.tarjeta, partiresul.tarjeta) */
    const TARJETA_NINGUNA = 0;
    const TARJETA_AMARILLA = 1;
    const TARJETA_ROJA = 3;
    const TARJETA_NEGRA = 4;

    /** Sanción 40 pts: resta de resultado1, sin asignar tarjeta (alias para compatibilidad: SANCION_AMARILLA) */
    const SANCION_40 = 40;
    const SANCION_AMARILLA = 40;

    /** Sanción máxima que dispara Amarilla o Roja (según tarjeta previa) y SÍ resta puntos */
    const SANCION_ROJA = 80;

    /**
     * Procesa sanción y tarjeta para un jugador.
     *
     * @param mixed $sancion Puntos de sanción (0-80); texto/NULL/'pendiente' → 0 vía {@see TorneoCampoNumerico}
     * @param mixed $tarjetaForm Tarjeta en formulario (0, 1, 3, 4)
     * @param mixed $tarjetaInscritos Tarjeta previa (inscritos o partidas anteriores)
     * @return array ['tarjeta' => int, 'sancion_para_calculo' => int, 'sancion_guardar' => int]
     *   - tarjeta: valor final a guardar en partiresul (0, 1, 3, 4)
     *   - sancion_para_calculo: puntos a restar en resultado1 para efectividad (40 → 0)
     *   - sancion_guardar: valor a guardar en partiresul.sancion (para registro)
     */
    /** @param mixed $sancion @param mixed $tarjetaForm @param mixed $tarjetaInscritos */
    public static function procesar($sancion, $tarjetaForm, $tarjetaInscritos): array {
        $sancion = TorneoCampoNumerico::intEstadistica($sancion);
        $sancion = (int) max(0, min($sancion, self::SANCION_ROJA));
        $tarjetaInscritos = TorneoCampoNumerico::codigoTarjeta($tarjetaInscritos);
        $tarjetaForm = TorneoCampoNumerico::codigoTarjeta($tarjetaForm);

        $tarjeta = $tarjetaForm;
        $sancionParaCalculo = $sancion;
        $sancionGuardar = $sancion;

        // Sanción 40: resta 40 de resultado1, sin tarjeta
        if ($sancion === self::SANCION_40 || $sancion === self::SANCION_AMARILLA) {
            $tarjeta = self::TARJETA_NINGUNA;
            $sancionParaCalculo = self::SANCION_40;
            $sancionGuardar = self::SANCION_40;
            return ['tarjeta' => $tarjeta, 'sancion_para_calculo' => $sancionParaCalculo, 'sancion_guardar' => $sancionGuardar];
        }

        // Sanción 80: si NO hay tarjeta previa → Amarilla; si HAY tarjeta previa → tarjeta siguiente (Roja/Negra)
        if ($sancion >= self::SANCION_ROJA) {
            $sancionGuardar = self::SANCION_ROJA;
            $sancionParaCalculo = self::SANCION_ROJA;
            $tarjeta = self::tieneTarjetaPrevia($tarjetaInscritos)
                ? self::tarjetaSiguiente($tarjetaInscritos)
                : self::TARJETA_AMARILLA;
            return ['tarjeta' => $tarjeta, 'sancion_para_calculo' => $sancionParaCalculo, 'sancion_guardar' => $sancionGuardar];
        }

        // Tarjeta directa (amarilla en boleta sin sanción 80): si ya tiene → siguiente; si no → Amarilla
        if ($tarjetaForm === self::TARJETA_AMARILLA) {
            $tarjeta = self::tieneTarjetaPrevia($tarjetaInscritos)
                ? self::tarjetaSiguiente($tarjetaInscritos)
                : self::TARJETA_AMARILLA;
            return ['tarjeta' => $tarjeta, 'sancion_para_calculo' => $sancion, 'sancion_guardar' => $sancion];
        }

        // Tarjeta roja o negra directa: se mantiene
        if ($tarjetaForm === self::TARJETA_ROJA || $tarjetaForm === self::TARJETA_NEGRA) {
            return ['tarjeta' => $tarjetaForm, 'sancion_para_calculo' => $sancion, 'sancion_guardar' => $sancion];
        }

        return ['tarjeta' => $tarjeta, 'sancion_para_calculo' => $sancionParaCalculo, 'sancion_guardar' => $sancionGuardar];
    }

    /**
     * Indica si el jugador ya tiene tarjeta previa (para acumulación).
     */
    public static function tieneTarjetaPrevia(int $tarjetaInscritos): bool {
        return $tarjetaInscritos >= self::TARJETA_AMARILLA;
    }

    /**
     * Retorna la tarjeta siguiente: Amarilla→Roja, Roja→Negra, Negra→Negra.
     */
    public static function tarjetaSiguiente(int $tarjetaActual): int {
        if ($tarjetaActual >= self::TARJETA_NEGRA) {
            return self::TARJETA_NEGRA;
        }
        if ($tarjetaActual >= self::TARJETA_ROJA) {
            return self::TARJETA_NEGRA;
        }
        return self::TARJETA_ROJA;
    }

    /**
     * Obtiene tarjetas actuales de inscritos para los jugadores dados.
     */
    public static function getTarjetasInscritos(PDO $pdo, int $torneo_id, array $id_usuarios): array {
        $result = [];
        $id_usuarios = array_filter(array_map('intval', $id_usuarios));
        if (empty($id_usuarios)) {
            return $result;
        }
        $placeholders = implode(',', array_fill(0, count($id_usuarios), '?'));
        $tx = InscritosHelper::sqlExprColumnaNumerica('tarjeta');
        $stmt = $pdo->prepare("
            SELECT id_usuario, {$tx} AS tarjeta
            FROM inscritos
            WHERE torneo_id = ? AND id_usuario IN ($placeholders)
        ");
        $stmt->execute(array_merge([$torneo_id], array_values($id_usuarios)));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[(int)$row['id_usuario']] = (int)$row['tarjeta'];
        }
        return $result;
    }

    /**
     * Obtiene la tarjeta previa desde partiresul de partidas ANTERIORES (partida < partida_actual).
     * Usar esto para determinar acumulación al guardar: evita doble escalación al re-editar la misma mesa.
     *
     * @return array [id_usuario => tarjeta máxima en partidas anteriores (0,1,3,4)]
     */
    public static function getTarjetaPreviaDesdePartidasAnteriores(PDO $pdo, int $torneo_id, int $partida_actual, array $id_usuarios): array {
        $result = [];
        $id_usuarios = array_filter(array_map('intval', $id_usuarios));
        if (empty($id_usuarios) || $partida_actual <= 1) {
            return $result;
        }
        $placeholders = implode(',', array_fill(0, count($id_usuarios), '?'));
        $tx = InscritosHelper::sqlExprColumnaNumerica('tarjeta');
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $pdo->prepare("
            SELECT id_usuario, MAX({$tx}) AS tarjeta
            FROM partiresul
            WHERE id_torneo = ? AND partida < ? AND id_usuario IN ($placeholders) AND {$regOk}
            GROUP BY id_usuario
        ");
        $stmt->execute(array_merge([$torneo_id, $partida_actual], array_values($id_usuarios)));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[(int)$row['id_usuario']] = (int)$row['tarjeta'];
        }
        return $result;
    }

    /**
     * Retorna etiqueta legible para la tarjeta.
     */
    public static function getEtiquetaTarjeta(int $tarjeta): string {
        switch ($tarjeta) {
            case self::TARJETA_AMARILLA: return 'Amarilla';
            case self::TARJETA_ROJA: return 'Roja';
            case self::TARJETA_NEGRA: return 'Negra';
            default: return 'Sin tarjeta';
        }
    }
}
