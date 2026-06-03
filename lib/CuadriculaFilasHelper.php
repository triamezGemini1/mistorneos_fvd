<?php
/**
 * Filas de datos por segmento en la cuadrícula (configurable 15–22).
 */
class CuadriculaFilasHelper
{
    public const MIN = 15;
    public const MAX = 22;
    public const DEFAULT = 20;

    public static function sessionKey(int $torneoId): string
    {
        return 'cuadricula_filas_' . $torneoId;
    }

    public static function clamp(int $filas): int
    {
        return max(self::MIN, min(self::MAX, $filas));
    }

    /**
     * Lee ?filas=, persiste en sesión por torneo, o devuelve default.
     */
    public static function resolve(int $torneoId): int
    {
        $key = self::sessionKey($torneoId);
        if (isset($_GET['filas']) && $_GET['filas'] !== '') {
            $n = self::clamp((int) $_GET['filas']);
            $_SESSION[$key] = $n;

            return $n;
        }
        if (isset($_SESSION[$key])) {
            return self::clamp((int) $_SESSION[$key]);
        }

        return self::DEFAULT;
    }

    /** vh por fila de datos para que la rejilla quepa en pantalla (referencia 17 × 4.72vh). */
    public static function rowVh(int $filas): float
    {
        $refTotal = 4.72 * 17;

        return round($refTotal / max(1, self::clamp($filas)), 3);
    }

    public static function queryParam(int $filas): array
    {
        return ['filas' => self::clamp($filas)];
    }
}
