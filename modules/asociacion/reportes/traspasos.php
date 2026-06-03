<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$pageReporte = 'asociacion/reportes/traspasos';
$rows = FvdDelegadoReporteService::reporteAfiliadosConMovimiento($pdo, $clubId, $torneoId);
$otrosClubes = FvdDelegadoReporteService::listarOtrosClubes($pdo, $clubId);
$filtradas = array_values(array_filter(
    $rows,
    static fn (array $r): bool => FvdDelegadoReporteService::filaPasaBusqueda($r, $q)
        && FvdDelegadoReporteService::filaTraspasoSolicitud($r, $filtro)
));
$pag = fvd_reporte_paginar($filtradas, $pagina, $porPagina);
$apiTraspaso = AppHelpers::url('api/fvd_solicitar_traspaso.php');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssFvd) ?>">
<div class="fvd-afiliacion-wrap fvd-reporte-wrap">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb breadcrumb--fvd mb-0">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlPanel) ?>">Panel</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(fvd_reporte_url('asociacion/informes')) ?>">Informes</a></li>
            <li class="breadcrumb-item active">Traspasos</li>
        </ol>
    </nav>

    <div class="afiliacion-card fvd-reporte-card">
        <h1 class="afiliacion-title">Reporte de traspasos</h1>
        <p class="afiliacion-lead">
            Torneo <strong>#<?= (int) $torneoId ?></strong>. Elija asociación destino en cada fila para enviar la solicitud automáticamente.
        </p>

        <form method="get" class="ag-inf-carnet-toolbar fvd-form fvd-form--inline mb-3">
            <input type="hidden" name="page" value="asociacion/reportes/traspasos">
            <?php if ($torneoId > 0): ?><input type="hidden" name="torneo_id" value="<?= $torneoId ?>"><?php endif; ?>
            <label class="ag-inf-carnet-fld">
                <span class="ag-inf-carnet-lbl">Buscar</span>
                <input type="search" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($q) ?>" placeholder="Cédula, Nº FVD o nombre">
            </label>
            <label class="ag-inf-carnet-fld">
                <span class="ag-inf-carnet-lbl">Listado</span>
                <select name="filtro" class="form-select form-select-sm">
                    <option value="todos"<?= $filtro !== 'solicitados' ? ' selected' : '' ?>>Todos los afiliados</option>
                    <option value="solicitados"<?= $filtro === 'solicitados' ? ' selected' : '' ?>>Solo solicitudes de traspaso</option>
                </select>
            </label>
            <button type="submit" class="btn btn-sm fvd-btn-secondary">Filtrar</button>
            <span class="ag-inf-carnet-meta text-muted small"><?= (int) $pag['total'] ?> coincidencia(s)</span>
        </form>

        <div id="fvd-traspaso-msg" class="alert alert-info py-2 small d-none"></div>

        <?= fvd_reporte_pager_html($pageReporte, $pag['pagina'], $pag['paginas'], $q, $filtro) ?>

        <div class="reporte-vista table-responsive">
            <table class="fvd-table table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ag-num">Nº FVD</th>
                        <th>Cédula</th>
                        <th>Nombre</th>
                        <th>Sexo</th>
                        <th>Movimiento</th>
                        <th>Traspaso a</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pag['items'] === []): ?>
                        <tr><td colspan="7" class="text-muted">Sin registros con el filtro actual.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pag['items'] as $a): ?>
                            <?php
                            $uid = (int) ($a['user_id'] ?? 0);
                            $destActual = FvdMovimientoTorneoHelper::parsearDestinoClubDesdeGrupo((string) ($a['grupo_nombre'] ?? ''));
                            $ver = fvd_reporte_url('asociacion/afiliar_atleta', ['modo' => 'ver', 'cedula' => (string) ($a['cedula'] ?? '')]);
                            $ed = fvd_reporte_url('asociacion/afiliar_atleta', ['modo' => 'editar', 'cedula' => (string) ($a['cedula'] ?? '')]);
                            ?>
                            <tr>
                                <td class="ag-num"><?= (int) ($a['numfvd'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string) ($a['cedula'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($a['nombre'] ?? '')) ?></td>
                                <td><?= htmlspecialchars(FvdDelegadoReporteService::sexoLabel($a['sexo'] ?? 0)) ?></td>
                                <td><?= FvdDelegadoReporteService::badgesMovimientoHtml($a) ?></td>
                                <td>
                                    <select class="form-select form-select-sm del-tr-dest" data-user-id="<?= $uid ?>">
                                        <option value="">— Elija destino —</option>
                                        <?php foreach ($otrosClubes as $oc): ?>
                                            <option value="<?= (int) $oc['id'] ?>"<?= $destActual === (int) $oc['id'] ? ' selected' : '' ?>>
                                                <?= htmlspecialchars((string) $oc['nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
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
<script>
(function () {
    const tid = <?= (int) $torneoId ?>;
    const api = <?= json_encode($apiTraspaso, JSON_UNESCAPED_UNICODE) ?>;
    const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
    const msg = document.getElementById('fvd-traspaso-msg');
    document.querySelectorAll('.del-tr-dest').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const uid = parseInt(sel.getAttribute('data-user-id') || '0', 10);
            const dest = parseInt(sel.value || '0', 10);
            if (uid < 1 || dest < 1 || tid < 1) return;
            if (!confirm('¿Registrar solicitud de traspaso a la asociación seleccionada?')) {
                sel.value = '';
                return;
            }
            sel.disabled = true;
            fetch(api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
                body: JSON.stringify({ user_id: uid, torneo_id: tid, club_destino_id: dest })
            }).then(function (r) { return r.json(); }).then(function (d) {
                msg.classList.remove('d-none', 'alert-danger', 'alert-success');
                msg.classList.add(d.ok ? 'alert-success' : 'alert-danger');
                msg.textContent = d.message || (d.ok ? 'Traspaso solicitado.' : 'Error');
                if (d.ok) setTimeout(function () { location.reload(); }, 1200);
                else sel.disabled = false;
            }).catch(function () {
                msg.classList.remove('d-none'); msg.classList.add('alert-danger');
                msg.textContent = 'Error de conexión.';
                sel.disabled = false;
            });
        });
    });
})();
</script>
