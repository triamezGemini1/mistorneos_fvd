<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdPaginacionCompacta.php';

/**
 * Paginador común FVD (FvdPaginacionCompacta) para reportes de resultados de torneo.
 */
final class ResultadosReportePaginacion
{
    /** Registros por página en reportes de torneo (fijo). */
    public const PER_PAGE = 20;

    /** @deprecated Usar PER_PAGE; ya no hay listado completo por defecto. */
    public const PER_PAGE_ALL = 0;

    /**
     * Pagina un arreglo en memoria (20 filas por defecto).
     *
     * @param list<mixed> $filas
     * @return array{
     *   filas: list<mixed>,
     *   pagina: int,
     *   total_paginas: int,
     *   total: int,
     *   por_pagina: int,
     *   offset: int
     * }
     */
    public static function paginarFilas(array $filas, int $paginaRaw, ?int $perPage = null): array
    {
        require_once __DIR__ . '/Tournament/Services/PaginationService.php';
        $perPage = $perPage ?? self::PER_PAGE;
        $total = count($filas);
        $p = \Tournament\Services\PaginationService::getParams($total, $paginaRaw, $perPage);

        return [
            'filas' => array_slice($filas, $p['offset'], $p['per_page']),
            'pagina' => (int) $p['page'],
            'total_paginas' => (int) $p['total_pages'],
            'total' => $total,
            'por_pagina' => $perPage,
            'offset' => (int) $p['offset'],
        ];
    }

    /**
     * @param array<string, scalar|null> $baseParams Parámetros fijos (action, torneo_id, vista, …)
     * @param list<string>               $excludeGet Claves GET que no se preservan además de «pagina»
     */
    public static function renderForTorneoReport(
        int $page,
        int $totalPages,
        int $totalRows,
        int $perPage,
        string $baseUrlReturn,
        bool $useStandalone,
        array $baseParams,
        string $itemLabel = 'registros',
        array $excludeGet = []
    ): string {
        if ($totalRows <= 0) {
            return '';
        }

        $params = $baseParams;
        $skip = array_merge(['pagina'], $excludeGet);
        foreach ($_GET as $key => $value) {
            if (in_array($key, $skip, true) || array_key_exists($key, $baseParams)) {
                continue;
            }
            if (is_scalar($value)) {
                $params[$key] = $value;
            }
        }
        unset($params['pagina']);

        $sep = $useStandalone ? '?' : (str_contains($baseUrlReturn, '?') ? '&' : '?');
        $baseUrl = $baseUrlReturn . $sep . http_build_query($params);

        return FvdPaginacionCompacta::render(
            $page,
            $totalPages,
            $totalRows,
            $perPage,
            $baseUrl,
            'pagina',
            $itemLabel
        );
    }
}
