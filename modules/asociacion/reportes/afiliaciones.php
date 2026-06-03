<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$pageReporte = 'asociacion/reportes/afiliaciones';
$okGuardado = isset($_GET['ok']) && (int) $_GET['ok'] === 1;
$cedulaDestacada = $okGuardado
    ? FvdMovimientoTorneoHelper::normalizarCedula((string) ($_GET['cedula'] ?? ''))
    : '';
$rows = FvdDelegadoReporteService::reporteAfiliacionesDesdeMovimiento($pdo, $clubId, $torneoId);
$filtradas = array_values(array_filter($rows, static fn (array $r): bool => FvdDelegadoReporteService::filaPasaBusqueda($r, $q)));
$pag = fvd_reporte_paginar($filtradas, $pagina, $porPagina);
$urlAfiliar = fvd_reporte_url('asociacion/afiliar_atleta');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssFvd) ?>">
<div class="fvd-afiliacion-wrap fvd-reporte-wrap">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb breadcrumb--fvd mb-0">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlPanel) ?>">Panel</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(fvd_reporte_url('asociacion/informes')) ?>">Informes</a></li>
            <li class="breadcrumb-item active">Afiliaciones</li>
        </ol>
    </nav>

    <div class="afiliacion-card fvd-reporte-card">
        <h1 class="afiliacion-title">Reporte de afiliaciones</h1>
        <p class="afiliacion-lead">
            Torneo <strong>#<?= (int) $torneoId ?></strong> — <?= htmlspecialchars((string) ($club['nombre'] ?? '')) ?>.
            Listado desde <code>movimiento_torneo</code> con <strong>afiliación = 1</strong>:
            <strong>Nº FVD 0</strong> = pendiente FVD; <strong>Nº FVD &gt; 0</strong> = aceptado.
        </p>

        <div class="ag-del-afiliaciones-toolbar mb-3">
            <a href="<?= htmlspecialchars($urlAfiliar) ?>" class="btn fvd-btn-primary btn-sm">
                <i class="fas fa-user-plus me-1"></i>Nuevo afiliado
            </a>
        </div>

        <form method="get" class="ag-inf-carnet-toolbar fvd-form fvd-form--inline mb-3">
            <input type="hidden" name="page" value="asociacion/reportes/afiliaciones">
            <?php if ($torneoId > 0): ?><input type="hidden" name="torneo_id" value="<?= $torneoId ?>"><?php endif; ?>
            <label class="ag-inf-carnet-fld">
                <span class="ag-inf-carnet-lbl">Buscar</span>
                <input type="search" name="q" class="form-control form-control-sm admin-search" value="<?= htmlspecialchars($q) ?>" placeholder="Cédula, Nº FVD o nombre">
            </label>
            <button type="submit" class="btn btn-sm fvd-btn-secondary">Buscar</button>
            <span class="ag-inf-carnet-meta text-muted small"><?= (int) $pag['total'] ?> registro(s)</span>
        </form>

        <?= fvd_reporte_pager_html($pageReporte, $pag['pagina'], $pag['paginas'], $q, $filtro) ?>

        <div class="reporte-vista table-responsive">
            <table class="fvd-table table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ag-num">Nº FVD</th>
                        <th>Cédula</th>
                        <th>Nombre</th>
                        <th>Sexo</th>
                        <th>Estado alta</th>
                        <th>Movimiento</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pag['items'] === []): ?>
                        <tr><td colspan="7" class="text-muted">Sin afiliaciones para este torneo. Use «Nuevo afiliado».</td></tr>
                    <?php else: ?>
                        <?php foreach ($pag['items'] as $a): ?>
                            <?php
                            $c = urlencode((string) ($a['cedula'] ?? ''));
                            $ver = fvd_reporte_url('asociacion/afiliar_atleta', ['modo' => 'ver', 'cedula' => (string) ($a['cedula'] ?? '')]);
                            $ed = fvd_reporte_url('asociacion/afiliar_atleta', ['modo' => 'editar', 'cedula' => (string) ($a['cedula'] ?? '')]);
                            ?>
                            <?php
                            $cedFila = FvdMovimientoTorneoHelper::normalizarCedula((string) ($a['cedula'] ?? ''));
                            $filaNueva = $cedulaDestacada !== '' && $cedFila === $cedulaDestacada;
                            ?>
                            <tr<?= $filaNueva ? ' class="fvd-row-nueva-afiliacion"' : '' ?>>
                                <td class="ag-num"><?= (int) ($a['numfvd'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string) ($a['cedula'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($a['nombre'] ?? '')) ?></td>
                                <td><?= htmlspecialchars(FvdDelegadoReporteService::sexoLabel($a['sexo'] ?? 0)) ?></td>
                                <td><?= FvdDelegadoReporteService::badgeAltaFederacion($a) ?></td>
                                <td><?= FvdDelegadoReporteService::badgesMovimientoHtml($a) ?></td>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm fvd-btn-secondary" href="<?= htmlspecialchars($ver) ?>">Ver</a>
                                    <a class="btn btn-sm fvd-btn-secondary" href="<?= htmlspecialchars($ed) ?>">Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-2"><?= fvd_reporte_pager_html($pageReporte, $pag['pagina'], $pag['paginas'], $q, $filtro) ?></div>

        <div class="afiliacion-actions mt-3">
            <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn fvd-btn-secondary btn-sm">Volver al panel</a>
            <button type="button" class="btn fvd-btn-secondary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir</button>
        </div>
    </div>
</div>
<?php if ($okGuardado): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function showOk() {
        if (typeof Swal === 'undefined') {
            return;
        }
        Swal.fire({
            icon: 'success',
            title: 'Afiliación registrada',
            text: 'El atleta aparece en el listado del torneo. La FVD validará el Nº FVD si está pendiente.',
            confirmButtonColor: '#2e2e8e'
        });
        var row = document.querySelector('.fvd-row-nueva-afiliacion');
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    if (typeof Swal !== 'undefined') {
        showOk();
    } else {
        var n = 0;
        var iv = setInterval(function () {
            if (typeof Swal !== 'undefined' || ++n > 80) {
                clearInterval(iv);
                if (typeof Swal !== 'undefined') {
                    showOk();
                }
            }
        }, 40);
    }
});
</script>
<?php endif; ?>
