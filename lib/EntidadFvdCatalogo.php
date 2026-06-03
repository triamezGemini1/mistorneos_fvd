<?php

declare(strict_types=1);

/**
 * Códigos oficiales FVD de asociaciones estadales (clubes.id = entidad.id en ámbito 1–39).
 * Fuente de verdad para etiquetas cuando la BD tiene nombres desalineados (p. ej. Barinas en código 6).
 */
final class EntidadFvdCatalogo
{
    /** @var array<int, string> */
    private const NOMBRES = [
        1 => 'FALCON',
        2 => 'BOLIVAR',
        3 => 'PORTUGUESA',
        4 => 'MIRANDA',
        5 => 'ARAGUA',
        6 => 'ANZOATEGUI',
        7 => 'TRUJILLO',
        8 => 'CARABOBO',
        9 => 'TACHIRA',
        10 => 'SUCRE',
        11 => 'COJEDES',
        12 => 'DELTA AMACURO',
        13 => 'DISTRITO CAPITAL',
        14 => 'AMAZONAS',
        15 => 'MERIDA',
        16 => 'GUARICO',
        17 => 'LARA',
        18 => 'APURE',
        19 => 'LA GUAIRA',
        20 => 'BARINAS',
        21 => 'ZULIA',
        23 => 'MONAGAS',
        24 => 'YARACUY',
        25 => 'ARBITROS NACIONALES',
        26 => 'NUEVA ESPARTA',
    ];

    public static function nombreCanonico(int $codigo): ?string
    {
        return self::NOMBRES[$codigo] ?? null;
    }

    /**
     * Nombre para mostrar/guardar: catálogo FVD si existe; si no, limpia el de BD.
     */
    public static function normalizarNombre(int $codigo, ?string $nombreDb = null): string
    {
        $canon = self::nombreCanonico($codigo);
        if ($canon !== null) {
            return $canon;
        }
        $db = self::limpiarTexto($nombreDb ?? '');
        if ($db !== '') {
            return $db;
        }

        return 'ASOCIACION ' . $codigo;
    }

    /**
     * Etiqueta estándar: "6 — ANZOATEGUI".
     */
    public static function etiqueta(int $codigo, ?string $nombreDb = null): string
    {
        if ($codigo <= 0) {
            return '—';
        }

        return $codigo . ' — ' . self::normalizarNombre($codigo, $nombreDb);
    }

    /**
     * @return array<int, string>
     */
    public static function todosLosNombres(): array
    {
        return self::NOMBRES;
    }

    private static function limpiarTexto(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s);
        $s = preg_replace('/^ASOCIACION\s+/iu', '', $s) ?? $s;

        return trim($s);
    }
}
