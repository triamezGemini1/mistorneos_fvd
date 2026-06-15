<?php
/**
 * Importación externa desde Access (parejas inscritas, parti2017, clasiequi).
 * Verificación previa obligatoria; no crea usuarios.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/ImportacionAccessExternoService.php';
require_once __DIR__ . '/../lib/CampeonatoTorneoHelper.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$torneos = $pdo->query(
    'SELECT id, nombre, fechator, modalidad FROM tournaments ORDER BY fechator DESC, id DESC LIMIT 300'
)->fetchAll(PDO::FETCH_ASSOC);

$torneoIdSel = (int) ($_GET['torneo_id'] ?? 0);
$torneoActual = null;
foreach ($torneos as $t) {
    if ((int) $t['id'] === $torneoIdSel) {
        $torneoActual = $t;
        break;
    }
}
if ($torneoIdSel > 0 && $torneoActual === null) {
    $st = $pdo->prepare('SELECT id, nombre, fechator, modalidad FROM tournaments WHERE id = ? LIMIT 1');
    $st->execute([$torneoIdSel]);
    $torneoActual = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$modalidad = $torneoActual ? (int) ($torneoActual['modalidad'] ?? 1) : 0;
$esIndividual = $modalidad === 1;
$requiereClasiequi = ImportacionAccessExternoService::requiereClasiequi($modalidad);
$jugadoresUnidad = ImportacionAccessExternoService::jugadoresPorUnidad($modalidad);
$mapaCampeonatoGenero = ($torneoIdSel > 0)
    ? CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoIdSel)
    : null;
$modalidadLabels = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
$etiqModalidad = $modalidadLabels[$modalidad] ?? 'Modalidad ' . $modalidad;
$apiUrl = AppHelpers::url('api/importacion_access_externo.php');
$csrfToken = $_SESSION['csrf_token'] ?? '';
$basePage = 'index.php?page=importacion_torneo_externo';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(AppHelpers::assetHref('assets/css/fvd-tokens.css')) ?>">

<div class="imp-access-page">
<div class="imp-access-hero">
    <div class="imp-access-wrap">
        <h1 class="imp-access-hero__title"><i class="fas fa-database me-2"></i>Importar torneo externo (Access)</h1>
        <p class="imp-access-hero__lead">
            Carga desde exportaciones Access: <strong>parejas inscritas</strong>, <strong>parti2017</strong>
            <?php if ($requiereClasiequi): ?> y <strong>clasiequi</strong><?php endif; ?>.
            Paso 0: alinear padrón <code>atletas</code> → <code>usuarios</code> (incluye <code>numfvd</code>).
        </p>
    </div>
</div>

<div class="container-fluid py-0 imp-access-wrap">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="importacion_torneo_externo">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Torneo activo (destino)</label>
                    <select name="torneo_id" class="form-select" required>
                        <option value="">— Seleccione —</option>
                        <?php foreach ($torneos as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $torneoIdSel === (int) $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['nombre'] . ' · ' . ($t['fechator'] ?? '') . ' · ' . ($modalidadLabels[(int) $t['modalidad']] ?? 'mod.' . $t['modalidad'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-check me-1"></i>Aplicar torneo</button>
                </div>
            </form>
            <?php if ($torneoActual): ?>
                <div class="alert alert-success small mt-3 mb-0 py-2">
                    <strong><?= htmlspecialchars((string) $torneoActual['nombre']) ?></strong>
                    <span class="badge bg-secondary ms-1"><?= htmlspecialchars($etiqModalidad) ?></span>
                    <?php if ($requiereClasiequi): ?>
                        <span class="text-muted ms-2">Requiere 3 archivos · <?= (int) $jugadoresUnidad ?> jugadores por equipo</span>
                    <?php else: ?>
                        <span class="text-muted ms-2">Requiere 2 archivos (parejas + parti2017)</span>
                    <?php endif; ?>
                </div>
                <?php if ($mapaCampeonatoGenero !== null): ?>
                <div class="alert alert-info small mt-2 mb-0 py-2">
                    <strong>Campeonato simultáneo por género.</strong>
                    Use la columna <code>torneo</code> en los archivos Access:
                    <strong>1 = hombres</strong> (<?= htmlspecialchars((string) ($mapaCampeonatoGenero['slots'][1]['nombre'] ?? '')) ?>)
                    · <strong>2 = mujeres</strong> (<?= htmlspecialchars((string) ($mapaCampeonatoGenero['slots'][2]['nombre'] ?? '')) ?>).
                    Cada fila se valida e importa al sub-torneo correspondiente según sexo del usuario.
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$torneoActual): ?>
        <div class="alert alert-warning">Seleccione el torneo destino para desbloquear el panel de importación.</div>
    <?php else: ?>

    <div id="imp-access-panel" data-torneo-id="<?= (int) $torneoIdSel ?>" data-requiere-clasiequi="<?= $requiereClasiequi ? '1' : '0' ?>">

        <!-- Paso 0: Atletas → usuarios -->
        <div class="card imp-access-card imp-access-card--padron shadow-sm mb-3">
            <div class="card-header bg-white">
                <span class="badge bg-warning text-dark me-2">0</span>
                <strong>Padrón atletas → usuarios</strong>
                <div class="small text-muted">Obligatorio antes de importar: alinea <code>usuarios</code> con la tabla <code>atletas</code> (crear faltantes, actualizar <code>numfvd</code>, sexo y entidad).</div>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="imp-access-section-title">A) Padrón completo (todos los atletas)</div>
                    <p class="small text-muted mb-2">Crea usuarios que falten y actualiza los existentes con los datos oficiales del padrón FVD. No requiere archivo.</p>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-verificar-padron-completo">
                            <i class="fas fa-list-check me-1"></i>Verificar padrón completo
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-sincronizar-padron-completo" disabled>
                            <i class="fas fa-users-cog me-1"></i>Sincronizar todo el padrón
                        </button>
                    </div>
                    <div class="imp-access-stats mb-0" id="stats-padron-completo">Revise cuántos usuarios faltan o están desactualizados respecto a <code>atletas</code>.</div>
                </div>

                <div class="imp-access-divider"></div>

                <div>
                    <div class="imp-access-section-title">B) Cédulas del archivo de importación</div>
                    <p class="small text-muted mb-2">Use el mismo archivo de <strong>parejas inscritas</strong> del paso 1.</p>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-outline-warning btn-sm" id="btn-verificar-atletas">
                            <i class="fas fa-user-check me-1"></i>Verificar cédulas del archivo
                        </button>
                        <button type="button" class="btn btn-warning btn-sm" id="btn-sincronizar-atletas" disabled>
                            <i class="fas fa-sync me-1"></i>Sync cédulas del archivo
                        </button>
                    </div>
                    <div class="imp-access-stats" id="stats-atletas">Tras el padrón completo, verifique las cédulas del torneo a importar.</div>
                </div>
            </div>
        </div>

        <!-- Procedimiento 1: Parejas inscritas -->
        <div class="card imp-access-card imp-access-card--parejas shadow-sm">
            <div class="card-header bg-white">
                <span class="badge bg-info me-2">1</span>
                <strong>Inscripciones — parejas inscritas</strong>
                <div class="small text-muted">Cédula → usuario · numfvd y club desde usuarios<?php if ($mapaCampeonatoGenero): ?> · columna torneo 1/2<?php endif; ?><?php if ($requiereClasiequi): ?> · columna activo/titular/banca opcional<?php endif; ?></div>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-md-8">
                        <input type="file" class="form-control form-control-sm" id="file-parejas" accept=".xlsx,.xls,.csv,.txt">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="btn-analizar-parejas">
                            <i class="fas fa-search me-1"></i>Verificar inscripciones
                        </button>
                    </div>
                </div>
                <div class="imp-access-stats" id="stats-parejas">Seleccione el archivo exportado de <em>parejas inscritas</em> y pulse verificar.</div>
            </div>
        </div>

        <!-- Procedimiento 2: parti2017 -->
        <div class="card imp-access-card imp-access-card--parti shadow-sm">
            <div class="card-header bg-white">
                <span class="badge bg-primary me-2">2</span>
                <strong>Resultados — parti2017</strong>
                <div class="small text-muted">Partida, Mesa, Secuencia, Pareja (numfvd), Result1/2, Efectiv, FF, sanciones…</div>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-md-8">
                        <input type="file" class="form-control form-control-sm" id="file-parti" accept=".xlsx,.xls,.csv,.txt">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="btn-analizar-parti">
                            <i class="fas fa-search me-1"></i>Verificar resultados
                        </button>
                    </div>
                </div>
                <div class="imp-access-stats" id="stats-parti">Verifica que todos los numfvd de parti2017 existan en inscritos (incluye filas del paso 1 pendientes de cargar).</div>
            </div>
        </div>

        <!-- Procedimiento 3: clasiequi -->
        <div class="card imp-access-card imp-access-card--equipos shadow-sm<?= $requiereClasiequi ? '' : ' d-none' ?>" id="card-clasiequi">
            <div class="card-header bg-white">
                <span class="badge bg-secondary me-2">3</span>
                <strong>Equipos — clasiequi</strong>
                <div class="small text-muted">CLUB, NOMBRE, equipo, clave, estatus</div>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-md-8">
                        <input type="file" class="form-control form-control-sm" id="file-clasiequi" accept=".xlsx,.xls,.csv,.txt">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="btn-analizar-clasiequi">
                            <i class="fas fa-search me-1"></i>Verificar equipos
                        </button>
                    </div>
                </div>
                <div class="imp-access-stats" id="stats-clasiequi">Comprueba clasiequi vs parejas inscritas (mapa por equipo, <?= (int) $jugadoresUnidad ?> jug./equipo). Suba también parejas al analizar.</div>
            </div>
        </div>

        <div class="card border-success shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fas fa-play-circle text-success me-1"></i>Ejecutar importación</h2>
                <p class="small text-muted mb-2">Complete el paso 0 (atletas) y las verificaciones 1–3. Luego confirme la importación.</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="chk-reemplazar-inscripcion" checked>
                    <label class="form-check-label small" for="chk-reemplazar-inscripcion">Reemplazar inscritos y equipos del torneo (recomendado en importación completa)</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="chk-reemplazar" checked>
                    <label class="form-check-label small" for="chk-reemplazar">Reemplazar partiresul existentes del torneo</label>
                </div>
                <button type="button" class="btn btn-success" id="btn-ejecutar" disabled>
                    <i class="fas fa-file-import me-1"></i>Copiar datos al torneo
                </button>
                <div class="imp-access-stats mt-3 d-none" id="stats-ejecutar"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<?php if ($torneoActual): ?>
<script>
(function () {
    const panel = document.getElementById('imp-access-panel');
    if (!panel) return;

    const torneoId = panel.dataset.torneoId;
    const requiereClasiequi = panel.dataset.requiereClasiequi === '1';
    const apiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
    const csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;

    const state = { atletas: false, parejas: false, parti: false, clasiequi: !requiereClasiequi };

    function renderMuestra(rows) {
        if (!rows || !rows.length) return '';
        let html = '<strong>Vista previa:</strong><ul class="mb-1 small">';
        rows.forEach(function (r) {
            html += '<li>' + escapeHtml(String(r.cedula))
                + ' · ' + escapeHtml(String(r.nombre || '—'))
                + ' · usr #' + (r.id_usuario || '?')
                + ' · numfvd ' + (r.numfvd || '?')
                + ' · ' + escapeHtml(String(r.asociacion || ('ent. ' + (r.entidad || '?'))))
                + ' · ' + escapeHtml(String(r.torneo_etiqueta || ('torneo ' + (r.torneo_id || '?'))))
                + ' · ' + escapeHtml(String(r.estatus_usuario || ''))
                + '</li>';
        });
        html += '</ul>';
        return html;
    }

    function renderDetalleDivergencias(resumen, detalle) {
        if ((!resumen || !resumen.tipos || !resumen.tipos.length) && (!detalle || !detalle.length)) return '';
        let html = '<div class="border rounded p-2 mb-2 bg-white"><strong>Divergencias detalladas</strong>';
        if (resumen && resumen.nota) {
            html += '<div class="small text-muted mb-1">' + escapeHtml(resumen.nota) + '</div>';
        }
        if (resumen && resumen.tipos) {
            resumen.tipos.forEach(function (bloque) {
                html += '<div class="mt-2"><strong>' + escapeHtml(bloque.label) + ' (' + bloque.cantidad + ')</strong>';
                html += '<ul class="mb-1 small">';
                (bloque.items || []).slice(0, 25).forEach(function (d) {
                    html += '<li>'
                        + '<strong>' + escapeHtml(String(d.cedula || '')) + '</strong>'
                        + ' · ' + escapeHtml(String(d.nombre || '—'))
                        + ' · ' + escapeHtml(String(d.asociacion || '—'))
                        + (d.torneo_etiqueta ? ' · ' + escapeHtml(String(d.torneo_etiqueta)) : '')
                        + ' · <span class="text-muted">' + escapeHtml(String(d.estatus || '')) + '</span>';
                    if (d.numfvd_archivo || d.numfvd_usuario) {
                        html += ' · numfvd arch. ' + (d.numfvd_archivo || '—') + ' / usr ' + (d.numfvd_usuario || '—');
                    }
                    if (d.sexo) html += ' · sexo ' + escapeHtml(String(d.sexo));
                    html += '<br><span class="text-danger">' + escapeHtml(String(d.explicacion || '')) + '</span>';
                    html += renderSituacionMeta(d);
                    html += '</li>';
                });
                if ((bloque.items || []).length > 25) {
                    html += '<li class="text-muted">… y ' + ((bloque.items || []).length - 25) + ' más</li>';
                }
                html += '</ul></div>';
            });
        }
        html += '</div>';
        return html;
    }

    function renderResumenParejas(integ) {
        if (!integ || !integ.resumen_parejas_por_equipo || !integ.resumen_parejas_por_equipo.length) {
            if (integ && integ.por_torneo) {
                var all = [];
                var reqMerged = integ.jugadores_requeridos;
                Object.keys(integ.por_torneo).forEach(function (slot) {
                    var pt = integ.por_torneo[slot] || {};
                    if (!reqMerged && pt.jugadores_requeridos) reqMerged = pt.jugadores_requeridos;
                    (pt.resumen_parejas_por_equipo || []).forEach(function (r) {
                        all.push(Object.assign({ slot: slot }, r));
                    });
                });
                if (!all.length) return '';
                integ = { resumen_parejas_por_equipo: all, jugadores_requeridos: reqMerged };
            } else {
                return '';
            }
        }
        var req = integ.jugadores_requeridos || '?';
        var html = '<div class="mt-2 mb-2"><strong>Parejas inscritas por equipo</strong> '
            + '<span class="text-muted">(requeridos: ' + req + ')</span><ul class="mb-0 small">';
        integ.resumen_parejas_por_equipo.forEach(function (r) {
            var cls = r.ok ? 'text-success' : (r.aviso_banca ? 'text-warning' : 'text-danger');
            var pref = r.slot ? ('T' + r.slot + ' ') : '';
            var extra = '';
            if (r.banca > 0) extra += ' · banca ' + r.banca;
            if (r.en_clasiequi === false) extra += ' · sin clasiequi';
            html += '<li class="' + cls + '">' + escapeHtml(pref + (r.codigo_equipo || ''))
                + ': ' + (r.total || 0) + '/' + req
                + ' (tit. ' + (r.titulares || 0) + '/' + req + ')' + extra + '</li>';
        });
        html += '</ul></div>';
        return html;
    }

    function renderReporteBancaPorAsociacion(rb) {
        if (!rb || !(rb.total > 0)) return '';
        var html = '<div class="border rounded p-2 mb-2 mt-2 bg-white"><strong>Jugadores en banca al importar (' + rb.total + ')</strong>';
        var porAsoc = rb.por_asociacion || {};
        html += '<ul class="small mb-2">';
        Object.keys(porAsoc).forEach(function (k) {
            var b = porAsoc[k];
            html += '<li><strong>' + escapeHtml(String(b.asociacion || k)) + '</strong>';
            if (b.sin_clasiequi) html += ' · sin clasiequi: ' + b.sin_clasiequi;
            if (b.exceso_plantilla) html += ' · exceso plantilla: ' + b.exceso_plantilla;
            html += '</li>';
        });
        html += '</ul>';
        html += renderSituacionesLista('Detalle banca', rb.situaciones_detalle || rb.detalle, 20);
        html += '</div>';
        return html;
    }

    function renderEquiposIncompletosDetalle(integ) {
        if (!integ) return '';
        let html = renderResumenParejas(integ);
        html += renderReporteBancaPorAsociacion(integ.reporte_banca);
        if (!integ.reporte_banca && integ.por_torneo) {
            Object.keys(integ.por_torneo).forEach(function (slot) {
                var pt = integ.por_torneo[slot] || {};
                html += renderReporteBancaPorAsociacion(pt.reporte_banca);
            });
        }
        if (integ.leyenda_integridad) {
            html += '<div class="small text-muted mb-2 p-2 border rounded bg-white">'
                + escapeHtml(integ.leyenda_integridad) + '</div>';
        }
        var lista = integ.equipos_incompletos_detalle || [];
        if (!lista.length && integ.por_torneo) {
            Object.keys(integ.por_torneo).forEach(function (slot) {
                var pt = integ.por_torneo[slot] || {};
                (pt.equipos_incompletos_detalle || []).forEach(function (d) { lista.push(d); });
            });
        }
        if (!lista.length) {
            if (integ.equipos_incompletos && integ.equipos_incompletos.length) {
                return html + renderList('Integridad (' + (integ.jugadores_requeridos || '?') + ' jug./equipo)', integ.equipos_incompletos);
            }
            return html;
        }
        html += '<strong>Equipos con integridad incorrecta (' + lista.length + '):</strong>';
        lista.forEach(function (d) {
            var torneoTxt = d.slot ? ('Torneo ' + d.slot + ' · ') : '';
            html += '<div class="border rounded p-2 mb-2 mt-1 bg-white small">';
            html += '<div><strong>' + escapeHtml(torneoTxt + (d.codigo_equipo || '')) + '</strong>';
            if (d.nombre_equipo) html += ' · «' + escapeHtml(String(d.nombre_equipo)) + '»';
            html += '</div>';
            html += '<div>' + escapeHtml(String(d.asociacion || '—'))
                + ' · Estatus equipo: ' + escapeHtml(String(d.estatus_equipo_etiqueta || '—'))
                + ' · <strong class="text-danger">parejas ' + escapeHtml(String(d.formato || ''))
                + '</strong> · tit. ' + escapeHtml(String(d.formato_titulares || '')) + '</div>';
            if (d.explicacion) html += '<div class="text-danger">' + escapeHtml(String(d.explicacion)) + '</div>';
            html += renderSituacionMeta(d);
            if (d.jugadores && d.jugadores.length) {
                html += '<div class="mt-1">Jugadores en parejas (' + d.jugadores.length + '):</div><ul class="mb-0">';
                d.jugadores.forEach(function (j) {
                    html += '<li>' + escapeHtml(String(j.cedula || ''))
                        + ' · ' + escapeHtml(String(j.nombre || '—'))
                        + ' · numfvd ' + (j.numfvd || '—')
                        + ' · ' + escapeHtml(String(j.asociacion || '—'))
                        + (j.rol ? ' · <strong>' + escapeHtml(String(j.rol)) + '</strong>' : '')
                        + '</li>';
                });
                html += '</ul>';
            } else {
                html += '<div class="text-muted mt-1">Sin filas en parejas inscritas para este código.</div>';
            }
            html += '</div>';
        });
        return html;
    }

    function renderList(title, items, max) {
        if (!items || !items.length) return '';
        const slice = items.slice(0, max || 15);
        let html = '<strong>' + title + ' (' + items.length + '):</strong><ul class="mb-1">';
        slice.forEach(function (x) { html += '<li>' + escapeHtml(String(x)) + '</li>'; });
        if (items.length > slice.length) {
            html += '<li class="text-muted">… y ' + (items.length - slice.length) + ' más</li>';
        }
        html += '</ul>';
        return html;
    }

    function renderPorAsoc(map) {
        if (!map || !Object.keys(map).length) return '';
        let html = '<strong>Por asociación:</strong><ul class="mb-1">';
        Object.keys(map).forEach(function (k) {
            html += '<li>' + escapeHtml(k) + ': <strong>' + map[k] + '</strong></li>';
        });
        html += '</ul>';
        return html;
    }

    function escapeHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renderSituacionMeta(d) {
        if (!d || (!d.origen_tabla_access && !d.origen_archivo && !d.tabla_destino)) return '';
        var html = '<div class="imp-situacion-meta small border-start border-3 border-secondary ps-2 mt-1 mb-1">';
        if (d.origen_tabla_access || d.origen_archivo) {
            html += '<div><strong>Origen Access:</strong> '
                + escapeHtml(String(d.origen_tabla_access || d.origen_archivo || '')) + '</div>';
        }
        if (d.tabla_destino) {
            html += '<div><strong>Tabla destino:</strong> <code>' + escapeHtml(String(d.tabla_destino)) + '</code>';
            if (d.campo_destino) {
                html += ' · campo <code>' + escapeHtml(String(d.campo_destino)) + '</code>';
            }
            html += '</div>';
        }
        if (d.elemento) html += '<div><strong>Elemento:</strong> ' + escapeHtml(String(d.elemento)) + '</div>';
        if (d.fila_archivo) html += '<div><strong>Fila archivo:</strong> ' + escapeHtml(String(d.fila_archivo)) + '</div>';
        if (d.valor_archivo) html += '<div><strong>Valor archivo:</strong> ' + escapeHtml(String(d.valor_archivo)) + '</div>';
        if (d.valor_sistema) html += '<div><strong>Valor sistema:</strong> ' + escapeHtml(String(d.valor_sistema)) + '</div>';
        if (d.como_resolver) {
            html += '<div class="text-primary mt-1"><strong>Cómo resolver:</strong> '
                + escapeHtml(String(d.como_resolver)) + '</div>';
        }
        html += '</div>';
        return html;
    }

    function renderSituacionesLista(title, items, max) {
        if (!items || !items.length) return '';
        var slice = items.slice(0, max || 20);
        var html = '<div class="border rounded p-2 mb-2 bg-white"><strong>' + escapeHtml(title)
            + ' (' + items.length + ')</strong>';
        slice.forEach(function (d) {
            html += '<div class="mt-2 pb-2 border-bottom">';
            var titulo = d.cedula || d.codigo_equipo || d.elemento || d.numfvd || '';
            if (titulo) {
                html += '<div><strong>' + escapeHtml(String(titulo)) + '</strong>';
                if (d.nombre) html += ' · ' + escapeHtml(String(d.nombre));
                html += '</div>';
            }
            if (d.explicacion) {
                html += '<div class="text-danger">' + escapeHtml(String(d.explicacion)) + '</div>';
            }
            html += renderSituacionMeta(d);
            html += '</div>';
        });
        if (items.length > slice.length) {
            html += '<div class="small text-muted">… y ' + (items.length - slice.length) + ' más</div>';
        }
        html += '</div>';
        return html;
    }

    function setStats(el, ok, html) {
        el.classList.remove('is-error', 'is-ok');
        el.classList.add(ok ? 'is-ok' : 'is-error');
        el.innerHTML = html;
    }

    function renderSyncAtletas(s) {
        if (!s) return '';
        let html = '<div><strong>Cédulas en archivo:</strong> ' + (s.total_cedulas || 0) + '</div>';
        html += '<div>En tabla atletas: <strong>' + (s.en_atletas || 0) + '</strong> · Ya en usuarios: ' + (s.en_usuarios_inicial || 0) + '</div>';
        if (s.pendiente_crear) html += '<div>Pendiente crear usuarios: <strong class="text-warning">' + s.pendiente_crear + '</strong></div>';
        if (s.pendiente_actualizar) html += '<div>Pendiente actualizar (numfvd/datos): <strong class="text-info">' + s.pendiente_actualizar + '</strong></div>';
        if (s.usuarios_creados) html += '<div class="text-success">Usuarios creados: ' + s.usuarios_creados + '</div>';
        if (s.usuarios_actualizados) html += '<div class="text-success">Usuarios actualizados: ' + s.usuarios_actualizados + ' (numfvd: ' + (s.numfvd_actualizados || 0) + ')</div>';
        html += renderList('Errores', s.errores, 10);
        if (s.situaciones_detalle && s.situaciones_detalle.length) {
            html += renderSituacionesLista('Incidencias — origen y corrección', s.situaciones_detalle, 25);
        } else if (s.detalle_pendientes && s.detalle_pendientes.length) {
            html += '<strong>Cambios pendientes (' + s.detalle_pendientes.length + '):</strong><ul class="mb-1 small">';
            s.detalle_pendientes.slice(0, 20).forEach(function (d) {
                html += '<li>' + escapeHtml(String(d.cedula || '')) + ' · ' + escapeHtml(String(d.accion || ''));
                if (d.numfvd) html += ' · numfvd ' + escapeHtml(JSON.stringify(d.numfvd));
                else if (d.numfvd_atleta) html += ' · numfvd ' + d.numfvd_atleta;
                html += '</li>';
            });
            if (s.detalle_pendientes.length > 20) html += '<li class="text-muted">… más</li>';
            html += '</ul>';
        }
        if (s.detalle_cambios && s.detalle_cambios.length) {
            html += renderList('Cambios aplicados', s.detalle_cambios.map(function (d) {
                return (d.cedula || '') + ' ' + (d.accion || '') + (d.numfvd ? ' nf→' + (d.numfvd.despues || d.numfvd) : '');
            }), 15);
        }
        return html;
    }

    function refreshEjecutarBtn() {
        const ok = state.atletas && state.parejas && state.parti && state.clasiequi;
        document.getElementById('btn-ejecutar').disabled = !ok;
        document.getElementById('btn-analizar-parejas').disabled = !state.atletas;
        document.getElementById('btn-analizar-parti').disabled = !state.atletas;
        var btnClasi = document.getElementById('btn-analizar-clasiequi');
        if (btnClasi) btnClasi.disabled = !state.atletas;
    }

    function postForm(action, extraFiles) {
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', action);
        fd.append('torneo_id', torneoId);
        if (extraFiles) {
            Object.keys(extraFiles).forEach(function (k) {
                if (extraFiles[k]) fd.append(k, extraFiles[k]);
            });
        }
        return fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function renderSyncPadronCompleto(s) {
        if (!s) return '';
        let html = '<div><strong>Atletas en padrón:</strong> ' + (s.total_atletas || 0) + '</div>';
        html += '<div>Con usuario existente: ' + (s.en_usuarios_inicial || 0)
            + ' · Ya alineados: <strong>' + (s.alineados || 0) + '</strong></div>';
        if (s.pendiente_crear) html += '<div>Pendiente crear usuarios: <strong class="text-warning">' + s.pendiente_crear + '</strong></div>';
        if (s.pendiente_actualizar) html += '<div>Pendiente actualizar (numfvd/datos): <strong class="text-info">' + s.pendiente_actualizar + '</strong></div>';
        if (s.sin_cedula_valida) html += '<div class="text-muted">Atletas sin cédula válida: ' + s.sin_cedula_valida + '</div>';
        if (s.usuarios_creados) html += '<div class="text-success">Usuarios creados: ' + s.usuarios_creados + '</div>';
        if (s.usuarios_actualizados) html += '<div class="text-success">Usuarios actualizados: ' + s.usuarios_actualizados + ' (numfvd: ' + (s.numfvd_actualizados || 0) + ')</div>';
        html += renderList('Errores', s.errores, 10);
        if (s.situaciones_detalle && s.situaciones_detalle.length) {
            html += renderSituacionesLista('Incidencias — origen y corrección', s.situaciones_detalle, 25);
        } else if (s.detalle_pendientes && s.detalle_pendientes.length) {
            html += '<strong>Muestra de cambios pendientes:</strong><ul class="mb-1 small">';
            s.detalle_pendientes.slice(0, 15).forEach(function (d) {
                html += '<li>' + escapeHtml(String(d.cedula || '')) + ' · ' + escapeHtml(String(d.accion || ''));
                if (d.numfvd) html += ' · numfvd ' + escapeHtml(JSON.stringify(d.numfvd));
                else if (d.numfvd_atleta) html += ' · numfvd ' + d.numfvd_atleta;
                html += '</li>';
            });
            if (s.detalle_pendientes.length > 15) html += '<li class="text-muted">… más</li>';
            html += '</ul>';
        }
        if (s.detalle_cambios && s.detalle_cambios.length) {
            html += renderList('Cambios aplicados (muestra)', s.detalle_cambios.map(function (d) {
                return (d.cedula || '') + ' ' + (d.accion || '') + (d.numfvd ? ' nf→' + (d.numfvd.despues || d.numfvd) : '');
            }), 15);
        }
        return html;
    }

    document.getElementById('btn-verificar-padron-completo').addEventListener('click', function () {
        const el = document.getElementById('stats-padron-completo');
        el.textContent = 'Analizando padrón completo…';
        postForm('verificar_padron_completo').then(function (res) {
            if (!res.success) {
                setStats(el, false, escapeHtml(res.error || 'Error'));
                document.getElementById('btn-sincronizar-padron-completo').disabled = true;
                return;
            }
            const s = res.stats || {};
            const pendiente = (s.pendiente_crear || 0) + (s.pendiente_actualizar || 0);
            const yaListo = pendiente === 0;
            document.getElementById('btn-sincronizar-padron-completo').disabled = yaListo;
            let html = renderSyncPadronCompleto(s);
            html += yaListo
                ? '<div class="text-success fw-bold mt-1">✓ Padrón completo alineado con usuarios</div>'
                : '<div class="text-warning fw-bold mt-1">Pulse «Sincronizar todo el padrón» para aplicar ' + pendiente + ' cambio(s)</div>';
            setStats(el, yaListo, html);
        });
    });

    document.getElementById('btn-sincronizar-padron-completo').addEventListener('click', function () {
        const el = document.getElementById('stats-padron-completo');
        if (!confirm('¿Sincronizar TODOS los atletas con usuarios? Se crearán faltantes y se actualizarán numfvd, sexo y entidad.')) {
            return;
        }
        el.textContent = 'Sincronizando padrón completo…';
        postForm('sincronizar_padron_completo').then(function (res) {
            const s = res.stats || {};
            let html = renderSyncPadronCompleto(s);
            html += res.success
                ? '<div class="text-success fw-bold mt-1">✓ Padrón sincronizado</div>'
                : '<div class="text-danger fw-bold mt-1">✗ ' + escapeHtml(res.error || (s.errores && s.errores[0]) || 'Sync incompleta') + '</div>';
            setStats(el, !!res.success, html);
            document.getElementById('btn-sincronizar-padron-completo').disabled = true;
        });
    });

    document.getElementById('btn-verificar-atletas').addEventListener('click', function () {
        const f = document.getElementById('file-parejas').files[0];
        const el = document.getElementById('stats-atletas');
        if (!f) { el.textContent = 'Seleccione el archivo de parejas inscritas.'; return; }
        el.textContent = 'Verificando atletas…';
        postForm('verificar_atletas', { archivo_parejas: f }).then(function (res) {
            if (!res.success) {
                setStats(el, false, escapeHtml(res.error || 'Error'));
                state.atletas = false;
                document.getElementById('btn-sincronizar-atletas').disabled = true;
                refreshEjecutarBtn();
                return;
            }
            const s = res.stats || {};
            const puedeSync = s.sin_atleta && s.sin_atleta.length === 0
                && ((s.pendiente_crear || 0) > 0 || (s.pendiente_actualizar || 0) > 0);
            const yaListo = s.sin_atleta && s.sin_atleta.length === 0
                && (s.pendiente_crear || 0) === 0 && (s.pendiente_actualizar || 0) === 0
                && (s.en_usuarios_inicial || 0) >= (s.en_atletas || 0);
            state.atletas = !!yaListo;
            document.getElementById('btn-sincronizar-atletas').disabled = !puedeSync;
            let html = renderSyncAtletas(s);
            html += yaListo
                ? '<div class="text-success fw-bold mt-1">✓ Usuarios alineados con atletas</div>'
                : (puedeSync
                    ? '<div class="text-warning fw-bold mt-1">Pulse «Aplicar sync» para crear/actualizar usuarios</div>'
                    : '<div class="text-danger fw-bold mt-1">✗ Corrija atletas faltantes en el padrón</div>');
            setStats(el, !!yaListo, html);
            refreshEjecutarBtn();
        });
    });

    document.getElementById('btn-sincronizar-atletas').addEventListener('click', function () {
        const f = document.getElementById('file-parejas').files[0];
        const el = document.getElementById('stats-atletas');
        if (!f) return;
        el.textContent = 'Sincronizando…';
        postForm('sincronizar_atletas', { archivo_parejas: f }).then(function (res) {
            const s = res.stats || {};
            state.atletas = !!s.ok && res.success;
            let html = renderSyncAtletas(s);
            html += state.atletas
                ? '<div class="text-success fw-bold mt-1">✓ Sync completada — puede verificar inscripciones</div>'
                : '<div class="text-danger fw-bold mt-1">✗ ' + escapeHtml(res.error || s.errores && s.errores[0] || 'Sync incompleta') + '</div>';
            setStats(el, state.atletas, html);
            document.getElementById('btn-sincronizar-atletas').disabled = true;
            refreshEjecutarBtn();
        });
    });

    document.getElementById('btn-analizar-parejas').addEventListener('click', function () {
        const f = document.getElementById('file-parejas').files[0];
        const el = document.getElementById('stats-parejas');
        if (!f) { el.textContent = 'Seleccione un archivo.'; return; }
        el.textContent = 'Analizando…';
        postForm('analizar_parejas', { archivo_parejas: f }).then(function (res) {
            if (!res.success) {
                setStats(el, false, escapeHtml(res.error || 'Error'));
                state.parejas = false;
                refreshEjecutarBtn();
                return;
            }
            const s = res.stats || {};
            state.parejas = !!s.ok;
            let html = '<div><strong>Torneo destino:</strong> #' + (s.torneo_destino || torneoId) + '</div>';
            if (s.campeonato_genero) {
                html += '<div class="small text-info mb-1"><strong>Campeonato por género:</strong> torneo 1 = hombres · torneo 2 = mujeres</div>';
                if (s.por_torneo) {
                    html += '<ul class="small mb-1">';
                    [1, 2].forEach(function (slot) {
                        var pt = s.por_torneo[slot] || {};
                        html += '<li>Torneo ' + slot + (pt.nombre ? ' (' + escapeHtml(String(pt.nombre)) + ')' : '')
                            + ': ' + (pt.filas || 0) + ' filas · listos ' + (pt.listos || 0)
                            + ' · ya inscritos ' + (pt.ya_inscritos || 0) + '</li>';
                    });
                    html += '</ul>';
                }
            }
            html += '<div><strong>Registros leídos:</strong> ' + (s.filas_leidas || 0) + ' · Total general: ' + (s.total_general || 0) + '</div>';
            html += renderPorAsoc(s.por_asociacion);
            html += '<div class="small">Listos para cargar: <strong>' + (s.listos || 0) + '</strong> · Ya inscritos: ' + (s.ya_inscritos || 0);
            if (s.resumen_divergencias && s.resumen_divergencias.bloqueados) {
                html += ' · <span class="text-danger">Divergencias: ' + s.resumen_divergencias.bloqueados + '</span>';
            }
            html += '</div>';
            html += renderMuestra(s.muestra);
            html += renderDetalleDivergencias(s.resumen_divergencias, s.divergencias_detalle);
            if (s.situaciones_detalle && s.situaciones_detalle.length && (!s.divergencias_detalle || !s.divergencias_detalle.length)) {
                html += renderSituacionesLista('Situaciones detectadas', s.situaciones_detalle, 25);
            }
            html += renderList('Cédulas repetidas en archivo', s.cedulas_duplicadas_archivo);
            html += renderList('numfvd repetido en archivo', s.numfvd_duplicados_resueltos);
            if (!s.divergencias_detalle || !s.divergencias_detalle.length) {
                html += renderList('Cédulas sin usuario', s.cedulas_sin_usuario);
                html += renderList('Usuario sin numfvd', s.usuarios_sin_numfvd);
                html += renderList('Sin club para entidad del usuario', s.sin_club_entidad);
                html += renderList('numfvd archivo ≠ usuario (si aplica)', s.numfvd_discrepancia);
                html += renderList('Valor torneo inválido (use 1 o 2)', s.torneo_archivo_invalido);
                html += renderList('Sexo no coincide con torneo del archivo', s.sexo_no_coincide_torneo);
                html += renderList('Usuario sin sexo registrado', s.torneo_sin_sexo);
            }
            if (!s.campeonato_genero) {
                html += renderList('Torneo distinto en archivo (aviso)', s.torneo_archivo_distinto);
            }
            if (s.errores_columnas && s.errores_columnas.length) {
                html += renderSituacionesLista('Columnas faltantes o inválidas', s.situaciones_detalle, 15);
                if (!s.situaciones_detalle || !s.situaciones_detalle.length) {
                    html += renderList('Columnas', s.errores_columnas);
                }
            }
            html += s.ok ? '<div class="text-success fw-bold mt-1">✓ Verificación OK</div>' : '<div class="text-danger fw-bold mt-1">✗ Hay divergencias</div>';
            setStats(el, !!s.ok, html);
            refreshEjecutarBtn();
        }).catch(function () {
            setStats(el, false, 'Error de red.');
            state.parejas = false;
            refreshEjecutarBtn();
        });
    });

    document.getElementById('btn-analizar-parti').addEventListener('click', function () {
        const f = document.getElementById('file-parti').files[0];
        const fPar = document.getElementById('file-parejas').files[0];
        const el = document.getElementById('stats-parti');
        if (!f) { el.textContent = 'Seleccione parti2017.'; return; }
        el.textContent = 'Analizando…';
        const extra = { archivo_parti: f };
        if (fPar) extra.archivo_parejas_ref = fPar;
        postForm('analizar_parti', extra).then(function (res) {
            if (!res.success) {
                setStats(el, false, escapeHtml(res.error || 'Error'));
                state.parti = false;
                refreshEjecutarBtn();
                return;
            }
            const s = res.stats || {};
            state.parti = !!s.ok;
            let html = '<div><strong>Filas leídas:</strong> ' + (s.filas_leidas || 0) + ' · numfvd únicos: ' + (s.numfvd_unicos || 0) + '</div>';
            if (s.campeonato_genero && s.por_torneo) {
                html += '<ul class="small mb-1">';
                [1, 2].forEach(function (slot) {
                    var pt = s.por_torneo[slot] || {};
                    html += '<li>Torneo ' + slot + ': ' + (pt.filas || 0) + ' filas · ' + (pt.numfvd_unicos || 0) + ' numfvd</li>';
                });
                html += '</ul>';
            }
            html += renderList('numfvd sin inscrito', s.numfvd_sin_inscrito, 20);
            if (s.situaciones_detalle && s.situaciones_detalle.length) {
                html += renderSituacionesLista('Situaciones detectadas (parti2017)', s.situaciones_detalle, 25);
            } else if (s.errores_columnas && s.errores_columnas.length) {
                html += renderList('Columnas', s.errores_columnas);
            }
            html += s.ok ? '<div class="text-success fw-bold mt-1">✓ Todos los numfvd están en inscritos</div>' : '<div class="text-danger fw-bold mt-1">✗ Faltan inscritos</div>';
            setStats(el, !!s.ok, html);
            refreshEjecutarBtn();
        });
    });

    const btnClas = document.getElementById('btn-analizar-clasiequi');
    if (btnClas) {
        btnClas.addEventListener('click', function () {
            const f = document.getElementById('file-clasiequi').files[0];
            const fPar = document.getElementById('file-parejas').files[0];
            const el = document.getElementById('stats-clasiequi');
            if (!f) { el.textContent = 'Seleccione clasiequi.'; return; }
            el.textContent = 'Analizando…';
            const extra = { archivo_clasiequi: f };
            if (fPar) extra.archivo_parejas_ref = fPar;
            postForm('analizar_clasiequi', extra).then(function (res) {
                if (!res.success) {
                    setStats(el, false, escapeHtml(res.error || 'Error'));
                    state.clasiequi = false;
                    refreshEjecutarBtn();
                    return;
                }
                const s = res.stats || {};
                const integ = s.integridad_equipos || {};
                state.clasiequi = !!s.ok;
                let html = '<div><strong>Equipos leídos:</strong> ' + (s.equipos_leidos || 0) + '</div>';
                html += renderPorAsoc(s.por_asociacion);
                html += renderList('Equipos incompletos (clasiequi)', s.equipos_incompletos);
                html += renderEquiposIncompletosDetalle(integ);
                var sitClas = (s.situaciones_detalle || []).filter(function (d) {
                    var c = String(d.codigo || d.tipo || '');
                    return c.indexOf('equipo_') !== 0;
                });
                if (sitClas.length) {
                    html += renderSituacionesLista('Otras situaciones (clasiequi)', sitClas, 25);
                }
                if (integ.campeonato_genero) {
                    html += '<div class="small text-muted">Verificación por sub-torneo (1=hombres, 2=mujeres)</div>';
                }
                html += s.ok ? '<div class="text-success fw-bold mt-1">✓ Equipos OK</div>' : '<div class="text-danger fw-bold mt-1">✗ Revisar equipos</div>';
                setStats(el, !!s.ok, html);
                refreshEjecutarBtn();
            });
        });
    }

    document.getElementById('btn-ejecutar').addEventListener('click', function () {
        const el = document.getElementById('stats-ejecutar');
        el.classList.remove('d-none');
        el.textContent = 'Importando…';
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'ejecutar');
        fd.append('torneo_id', torneoId);
        if (document.getElementById('chk-reemplazar-inscripcion').checked) fd.append('reemplazar_inscripcion', '1');
        if (document.getElementById('chk-reemplazar').checked) fd.append('reemplazar_partiresul', '1');
        fd.append('archivo_parejas', document.getElementById('file-parejas').files[0]);
        fd.append('archivo_parti', document.getElementById('file-parti').files[0]);
        if (requiereClasiequi) {
            fd.append('archivo_clasiequi', document.getElementById('file-clasiequi').files[0]);
        }
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    const msg = res.error || (res.resultado && res.resultado.error) || 'Error';
                    let html = escapeHtml(msg);
                    const sit = (res.resultado && res.resultado.situaciones_detalle) || res.situaciones_detalle;
                    if (sit && sit.length) {
                        html += renderSituacionesLista('Incidencias — origen y corrección', sit, 15);
                    }
                    setStats(el, false, html);
                    return;
                }
                const r = res.resultado || {};
                let html = '<strong>Importación completada</strong><ul class="mb-0">';
                html += '<li>Inscritos nuevos: ' + (r.inscritos_insertados || 0);
                if (r.inscritos_actualizados) html += ' · actualizados: ' + r.inscritos_actualizados;
                html += ' (omitidos: ' + (r.inscritos_omitidos || 0) + ')</li>';
                if (r.inscritos_banca) {
                    html += '<li>Inscritos en banca: <strong>' + r.inscritos_banca + '</strong></li>';
                }
                html += '<li>Equipos nuevos: ' + (r.equipos_insertados || 0);
                if (r.equipos_actualizados) html += ' · actualizados: ' + r.equipos_actualizados;
                if (r.equipos_asegurados_parejas) html += ' · desde parejas: ' + r.equipos_asegurados_parejas;
                if (r.equipos_omitidos) html += ' · omitidos: ' + r.equipos_omitidos;
                html += '</li>';
                if (r.numeros_sincronizados) {
                    html += '<li>Números inscripción sincronizados (numfvd): ' + r.numeros_sincronizados + '</li>';
                }
                html += '<li>partiresul insertados: ' + (r.partiresul_insertados || 0);
                if (r.partiresul_omitidos) html += ' · omitidos: ' + r.partiresul_omitidos;
                if (r.partiresul_reemplazados) html += ' (reemplazados: ' + r.partiresul_reemplazados + ')';
                html += '</li></ul>';
                if (r.incidencias_resumen) {
                    var ir = r.incidencias_resumen;
                    var partes = [];
                    if (ir.jugadores_no_importados) partes.push('jugadores no importados: ' + ir.jugadores_no_importados);
                    if (ir.equipos_no_importados) partes.push('equipos no importados: ' + ir.equipos_no_importados);
                    if (ir.partiresul_omitidos) partes.push('filas partiresul omitidas: ' + ir.partiresul_omitidos);
                    if (partes.length) {
                        html += '<div class="small text-warning mt-1">' + escapeHtml(partes.join(' · ')) + '</div>';
                    }
                }
                html += renderReporteBancaPorAsociacion(r.reporte_banca);
                if (r.situaciones_detalle && r.situaciones_detalle.length) {
                    html += renderSituacionesLista('Incidencias al ejecutar — origen y corrección', r.situaciones_detalle, 30);
                }
                if (r.incidencias_truncadas) {
                    html += '<div class="small text-muted">Se muestran las primeras 200 incidencias; corrija y vuelva a importar.</div>';
                }
                if (r.advertencias && r.advertencias.length) {
                    html += '<div class="text-warning mt-2">' + r.advertencias.map(escapeHtml).join('<br>') + '</div>';
                }
                const okEjec = !(r.incidencias_resumen && (
                    (r.incidencias_resumen.jugadores_no_importados && !r.inscritos_insertados && !r.inscritos_actualizados)
                    || (r.incidencias_resumen.partiresul_omitidos && !r.partiresul_insertados)
                ));
                setStats(el, okEjec, html);
            })
            .catch(function () { setStats(el, false, 'Error de red.'); });
    });

    refreshEjecutarBtn();
})();
</script>
<?php endif; ?>
