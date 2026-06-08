<?php

declare(strict_types=1);

/**
 * Paginador compacto reutilizable (« ‹ n/m › »), 10 registros por página por defecto.
 */
final class FvdPaginacionCompacta
{
    public const PER_PAGE_DEFAULT = 10;

    public static function render(
        int $page,
        int $totalPages,
        int $totalRows,
        int $perPage,
        string $baseUrl,
        string $pageParam = 'p',
        string $itemLabel = 'registros'
    ): string {
        if ($totalRows <= 0) {
            return '';
        }
        $page = max(1, min($page, max(1, $totalPages)));
        $perPage = max(1, $perPage);
        $from = ($page - 1) * $perPage + 1;
        $to = min($page * $perPage, $totalRows);
        $sep = str_contains($baseUrl, '?') ? '&' : '?';
        $url = static fn (int $p): string => htmlspecialchars($baseUrl . $sep . $pageParam . '=' . $p);

        $html = '<div class="fvd-listado-paginacion border-top">';
        $html .= '<small class="fvd-listado-paginacion-info">';
        $html .= 'Mostrando ' . (int) $from . '–' . (int) $to . ' de ' . (int) $totalRows . ' ' . htmlspecialchars($itemLabel);
        $html .= '</small>';
        if ($totalPages > 1) {
            $html .= '<nav aria-label="Paginación"><ul class="pagination pagination-sm mb-0">';
            $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $url(1) . '">«</a></li>';
            $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $url(max(1, $page - 1)) . '">‹</a></li>';
            $html .= '<li class="page-item disabled"><span class="page-link">' . (int) $page . ' / ' . (int) $totalPages . '</span></li>';
            $html .= '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . $url(min($totalPages, $page + 1)) . '">›</a></li>';
            $html .= '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . $url($totalPages) . '">»</a></li>';
            $html .= '</ul></nav>';
        }
        $html .= '</div>';

        return $html;
    }
}
