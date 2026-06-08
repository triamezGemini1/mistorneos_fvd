<?php

declare(strict_types=1);

/**
 * Motor numérico unificado (Individual, Parejas, Equipos, QR, desktop).
 * Normaliza hacia columnas INT/DOUBLE en torneos (resultados, sanciones, tarjetas).
 * Cualquier texto no numérico o marcador legacy ('pendiente', etc.) → 0 (mismo criterio en toda la app).
 */
final class TorneoCampoNumerico
{
    /** @param mixed $v */
    public static function intEstadistica($v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) round($v);
        }
        $s = trim(strtolower((string) $v));
        if ($s === '' || $s === 'pendiente' || $s === 'null' || $s === 'n/a' || $s === '-') {
            return 0;
        }
        if (is_numeric($s)) {
            return (int) round((float) $s);
        }

        return 0;
    }

    /**
     * Valor para aritmética (restas de sanción, efectividad): mismo saneamiento que intEstadistica.
     */
    /** @param mixed $v */
    public static function floatCalculo($v): float
    {
        return (float) self::intEstadistica($v);
    }

    /**
     * Códigos de tarjeta en partiresul/inscritos: 0 ninguna, 1 amarilla, 3 roja, 4 negra.
     * Legacy Access PARTI2017: 5 amarilla, 6 roja, 8 negra.
     */
    /** @param mixed $v */
    public static function codigoTarjeta($v): int
    {
        $n = self::intEstadistica($v);
        if (in_array($n, [0, 1, 3, 4], true)) {
            return $n;
        }
        $legacyAccess = [5 => 1, 6 => 3, 8 => 4];

        return $legacyAccess[$n] ?? 0;
    }

    /**
     * Marca disciplinaria en origen Access (PARTI2017.Sancion / Tarjeta): solo 5, 6 u 8.
     * 0, 1, 40, 80 u otros valores → sin tarjeta (no confundir Sancion=1 con amarilla).
     */
    public static function codigoTarjetaDesdeAccess($v): int
    {
        $n = self::intEstadistica($v);
        $soloMarcasAccess = [5 => 1, 6 => 3, 8 => 4];

        return $soloMarcasAccess[$n] ?? 0;
    }
}
