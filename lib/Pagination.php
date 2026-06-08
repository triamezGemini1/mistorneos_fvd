<?php

/**
 * Paginacion FVD: 10 registros por pagina, estilo compacto.
 */
class Pagination {
    public const DEFAULT_PER_PAGE = 10;

    private int $total_records;
    private int $current_page;
    private int $per_page;
    private int $total_pages;
    private int $offset;
    private array $query_params;

    public function __construct(int $total_records, int $current_page = 1, int $per_page = self::DEFAULT_PER_PAGE) {
        $this->total_records = max(0, $total_records);
        $this->per_page = max(1, $per_page);
        $this->total_pages = $this->calculateTotalPages($this->total_records);
        $this->current_page = max(1, min($current_page, $this->total_pages));
        $this->offset = ($this->current_page - 1) * $this->per_page;

        $this->query_params = $_GET;
        unset($this->query_params['p'], $this->query_params['per_page']);
    }

    private function calculateTotalPages(int $total): int {
        if ($total === 0) {
            return 1;
        }

        return (int) ceil($total / $this->per_page);
    }

    public function getOffset(): int {
        return $this->offset;
    }

    public function getLimit(): int {
        return $this->per_page;
    }

    public function getCurrentPage(): int {
        return $this->current_page;
    }

    public function getTotalPages(): int {
        return $this->total_pages;
    }

    public function getTotalRecords(): int {
        return $this->total_records;
    }

    public function getBaseUrl(): string {
        $qs = http_build_query($this->query_params);

        return $qs !== '' ? '?' . $qs : '?';
    }

    public function render(string $itemLabel = 'registros', string $pageParam = 'p'): string {
        require_once __DIR__ . '/FvdPaginacionCompacta.php';

        if ($this->total_records === 0) {
            return '<div class="fvd-listado-paginacion border-top mt-2">'
                . '<small class="fvd-listado-paginacion-info">No hay registros para mostrar</small></div>';
        }

        return FvdPaginacionCompacta::render(
            $this->current_page,
            $this->total_pages,
            $this->total_records,
            $this->per_page,
            $this->getBaseUrl(),
            $pageParam,
            $itemLabel
        );
    }

    /** @deprecated */
    public function renderInfo(): string {
        if ($this->total_records === 0) {
            return '<div class="text-muted">No hay registros para mostrar</div>';
        }
        $from = $this->offset + 1;
        $to = min($this->offset + $this->per_page, $this->total_records);

        return sprintf(
            '<div class="text-muted">Mostrando <strong>%d</strong> a <strong>%d</strong> de <strong>%d</strong> registros</div>',
            $from,
            $to,
            $this->total_records
        );
    }

    /** @deprecated */
    public function renderPerPageSelector(): string {
        return '';
    }

    /** @deprecated */
    public function renderButtons(): string {
        return '';
    }

    public function applySql(string $base_query): string {
        return $base_query . " LIMIT {$this->per_page} OFFSET {$this->offset}";
    }
}
