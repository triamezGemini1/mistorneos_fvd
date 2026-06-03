<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../../config/bootstrap.php';
}
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/csrf.php';
require_once __DIR__ . '/../../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../../lib/FvdDelegadoReporteService.php';
require_once __DIR__ . '/../../../lib/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/../../../lib/app_helpers.php';
require_once __DIR__ . '/../../../lib/FvdAdminGate.php';

$pageGate = trim((string) ($_GET['page'] ?? 'asociacion/reportes'));
FvdAdminGate::rejectPageIfDisabled($pageGate);

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

if (!Auth::isOperativoSoloAsociacion() && !Auth::isAdminGeneral()) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4">Acceso restringido.</div>';
    exit;
}

$pdo = DB::pdo();
$club = Auth::clubOperativoAsociacion();
if ($club === null) {
    echo '<div class="alert alert-warning m-4">Seleccione una asociación desde el <a href="'
        . htmlspecialchars(AppHelpers::dashboard('clubs')) . '">listado de asociaciones</a>.</div>';
    exit;
}

$torneoId = (int) ($_GET['torneo_id'] ?? 0);
if ($torneoId < 1) {
    $torneoId = (int) (FvdMovimientoTorneoHelper::torneoActivoId($pdo) ?? 0);
}
$clubId = (int) ($club['id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$filtro = trim((string) ($_GET['filtro'] ?? 'todos'));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 25;

$urlPanel = AppHelpers::dashboard('asociacion_panel', array_filter(['torneo_id' => $torneoId ?: null]));
$cssFvd = AppHelpers::assetVersion('assets/css/fvd-afiliacion-forms.css');
$csrf = CSRF::token();

/**
 * @param list<array<string, mixed>> $rows
 * @return array{items: list<array<string, mixed>>, total: int, paginas: int, pagina: int}
 */
function fvd_reporte_paginar(array $rows, int $pagina, int $porPagina): array
{
    $total = count($rows);
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $paginas);
    $offset = ($pagina - 1) * $porPagina;

    return [
        'items' => array_slice($rows, $offset, $porPagina),
        'total' => $total,
        'paginas' => $paginas,
        'pagina' => $pagina,
    ];
}

function fvd_reporte_url(string $page, array $extra = []): string
{
    global $torneoId;
    $q = $extra;
    if ($torneoId > 0) {
        $q['torneo_id'] = $torneoId;
    }

    return AppHelpers::dashboard($page, $q);
}

function fvd_reporte_pager_html(string $page, int $pagina, int $paginas, string $q, string $filtro): string
{
    if ($paginas <= 1) {
        return '';
    }
    $base = ['q' => $q, 'filtro' => $filtro];
    $html = '<nav class="fvd-reporte-pager" aria-label="Paginación"><ul class="pagination pagination-sm mb-0">';
    for ($p = 1; $p <= $paginas; $p++) {
        if ($p > 12 && $p < $paginas - 1 && abs($p - $pagina) > 2) {
            if ($p === 2 || $p === $paginas - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            continue;
        }
        $active = $p === $pagina ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="'
            . htmlspecialchars(fvd_reporte_url($page, $base + ['p' => $p])) . '">' . $p . '</a></li>';
    }
    $html .= '</ul></nav>';

    return $html;
}
