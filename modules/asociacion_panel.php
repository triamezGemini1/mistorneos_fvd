<?php
/**
 * Panel operativo — Administración de asociación (delegado / admin club).
 * Vista ancho completo. Solo solicitudes, inscripciones y consulta (sin crear entidades ni gestionar torneo).
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/FvdConfig.php';
require_once __DIR__ . '/../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('asociacion_panel');

if (!Auth::user()) {
    header('Location: ' . AppHelpers::url('login.php'));
    exit;
}

$pdo = DB::pdo();
$uid = Auth::id();
$user = Auth::user();
$role = (string) ($user['role'] ?? '');

$club = AsociacionAdminHelper::clubOperativo($pdo, $uid, $role);
$esOperativoAcotado = Auth::isOperativoSoloAsociacion();

if ($club === null) {
    echo '<div class="alert alert-warning m-4"><i class="fas fa-info-circle me-2"></i>'
        . 'Esta pantalla es para el administrador de asociación: debe ser <strong>delegado</strong> del club en la ficha del club, '
        . 'o usuario <strong>admin club</strong> con club asignado.'
        . '</div>';
    return;
}

$entidadNombre = trim((string) ($club['entidad_nombre'] ?? ''));
$clubNombre = trim((string) ($club['nombre'] ?? 'Asociación'));
$cid = (int) ($club['id'] ?? 0);
$entidadId = (int) ($club['entidad'] ?? 0);
$orgClub = (int) ($club['organizacion_id'] ?? 0);
$orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : 1;
$delegadoNombre = trim((string) ($user['nombre'] ?? $user['username'] ?? 'Usuario'));

$truncLabel = static function (string $s, int $max = 42): string {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $max, '…', 'UTF-8');
    }
    return strlen($s) <= $max ? $s : (substr($s, 0, $max - 1) . '…');
};
$upperLabel = static function (string $s): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
};
$normalizeLabel = static function (string $s): string {
    $t = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    return $t;
};
$clubNombreDisplay = $upperLabel($normalizeLabel($clubNombre));
$entidadDisplay = $normalizeLabel($entidadNombre);

$torneoHighlight = (int) ($_GET['torneo_nuevo'] ?? 0);
$tabActiva = (int) ($_GET['tab'] ?? 2);
if ($tabActiva !== 2 && $tabActiva !== 3) {
    $tabActiva = 2;
}

$urlFinanzasBase = AppHelpers::dashboard('finanzas/resumen_asociacion');

$clubLogo = !empty($club['logo']) ? AppHelpers::imageUrl((string) $club['logo']) : '';

$torneosSede = AsociacionAdminHelper::listarTorneosFvdParaClub($pdo, $club, $orgFvd, 30);
$torneosMasivos = AsociacionAdminHelper::listarTorneosFvdMasivos($pdo, $orgFvd, 30);
$torneosLista = array_merge($torneosMasivos, $torneosSede);

$torneoPanelId = (int) ($_GET['torneo_id'] ?? 0);
if ($torneoHighlight > 0) {
    $torneoPanelId = $torneoHighlight;
}
if ($torneoPanelId <= 0) {
    if ($tabActiva === 2 && $torneosMasivos !== []) {
        $torneoPanelId = (int) $torneosMasivos[0]['id'];
    } elseif ($torneosLista !== []) {
        $torneoPanelId = (int) $torneosLista[0]['id'];
    }
}

$torneoCtx = null;
$modalidadTorneo = 1;
foreach ($torneosLista as $tx) {
    if ((int) $tx['id'] === $torneoPanelId) {
        $torneoCtx = $tx;
        $modalidadTorneo = (int) ($tx['modalidad'] ?? 1);
        break;
    }
}

$urlTorneo = static function (string $action, array $extra = []) use ($torneoPanelId): string {
    if ($torneoPanelId <= 0) {
        return '#';
    }
    return AppHelpers::dashboard('torneo_gestion', ['action' => $action, 'torneo_id' => $torneoPanelId] + $extra);
};

$urlVerTorneo = $torneoPanelId > 0
    ? AppHelpers::dashboard('asociacion/torneo_ver', ['torneo_id' => $torneoPanelId])
    : '#';
$urlInscripciones = $urlTorneo('inscripciones');
$urlInscribirSitio = $urlTorneo('inscribir_sitio');
$urlInscribirEquipo = $urlTorneo('inscribir_equipo_sitio');
$urlCarnetQr = $torneoPanelId > 0
    ? AppHelpers::dashboard('tournament_admin', ['torneo_id' => $torneoPanelId, 'action' => 'generar_qr'])
    : '#';

$sinTorneo = $torneoPanelId <= 0
    || !AsociacionAdminHelper::usuarioPuedeVerTorneo($pdo, $torneoPanelId, $club);
$esEventoMasivo = AsociacionAdminHelper::esEventoMasivo($torneoCtx);
$urlFinanzas = $esEventoMasivo && $torneoPanelId > 0
    ? AppHelpers::dashboard('finanzas/resumen_asociacion', ['torneo_id' => $torneoPanelId, 'evento_masivo' => 1])
    : ($torneoPanelId > 0
        ? AppHelpers::dashboard('finanzas/resumen_asociacion', ['torneo_id' => $torneoPanelId])
        : $urlFinanzasBase);
$panelError = trim((string) ($_GET['error'] ?? ''));

$urlAsoc = static function (string $page, array $extra = []) use ($torneoPanelId): string {
    $q = $extra;
    if ($torneoPanelId > 0 && !isset($q['torneo_id'])) {
        $q['torneo_id'] = $torneoPanelId;
    }

    return AppHelpers::dashboard($page, $q);
};

$panelQs = static function (array $extra = []) use ($torneoPanelId, $torneoHighlight): array {
    $q = $extra;
    if ($torneoPanelId > 0) {
        $q['torneo_id'] = $torneoPanelId;
    }
    if ($torneoHighlight > 0) {
        $q['torneo_nuevo'] = $torneoHighlight;
    }
    return $q;
};
$tab2Href = AppHelpers::dashboard('asociacion_panel', $panelQs(['tab' => 2]));
$tab3Href = AppHelpers::dashboard('asociacion_panel', $panelQs(['tab' => 3]));

$listaContexto = $tabActiva === 2 ? $torneosMasivos : $torneosLista;
$urlInscripcionPrincipal = $modalidadTorneo === 3
    ? $urlInscribirEquipo
    : $urlInscribirSitio;
$btnTorneoOff = $sinTorneo ? ' asoc-po-btn--disabled' : '';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(AppHelpers::assetVersion('assets/css/asociacion-panel-operativo.css')) ?>">
<div class="asoc-fvd-wrap asoc-panel-operativo text-dark">
    <div class="container-fluid py-2 px-0">
        <?php if ($torneoHighlight > 0): ?>
        <div class="alert alert-primary border-0 shadow-sm d-flex align-items-center flex-wrap gap-2 mb-4">
            <i class="fas fa-bullhorn fa-lg"></i>
            <div class="flex-grow-1">
                <strong>Nuevo torneo publicado.</strong> Seleccione el evento en el listado y use inscripciones o solicitudes según corresponda.
            </div>
        </div>
        <?php endif; ?>

        <div class="mb-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2 small">
                    <li class="breadcrumb-item active">Panel de asociación</li>
                </ol>
            </nav>
            <h1 class="asoc-fvd-h1 mb-1">Administración de asociación</h1>
            <?php if ($panelError !== ''): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2"><?= htmlspecialchars($panelError) ?></div>
            <?php endif; ?>
        </div>

        <div class="card asoc-fvd-identity shadow-sm border-0 mb-3">
            <div class="card-body asoc-fvd-identity__body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="asoc-entity-name text-uppercase fw-bold text-primary text-truncate"><?= htmlspecialchars($clubNombreDisplay) ?></div>
                    <div class="small text-muted text-truncate">
                        Delegado: <strong><?= htmlspecialchars($delegadoNombre) ?></strong>
                        <?php if ($entidadDisplay !== '' && strcasecmp($entidadDisplay, $normalizeLabel($clubNombre)) !== 0): ?>
                            · <?= htmlspecialchars($entidadDisplay) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="asoc-fvd-identity__logo-wrap flex-shrink-0">
                    <?php if ($clubLogo !== ''): ?>
                        <img src="<?= htmlspecialchars($clubLogo) ?>" alt="<?= htmlspecialchars($clubNombreDisplay) ?>" class="rounded border bg-white asoc-club-logo asoc-club-logo--active">
                    <?php else: ?>
                        <div class="asoc-club-logo-placeholder rounded d-flex align-items-center justify-content-center bg-primary text-white fw-bold asoc-club-logo--active" title="<?= htmlspecialchars($clubNombreDisplay) ?>">
                            <?= htmlspecialchars($upperLabel(function_exists('mb_substr') ? mb_substr($normalizeLabel($clubNombre), 0, 1, 'UTF-8') : substr($normalizeLabel($clubNombre), 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="asoc-po-toolbar mb-3">
            <label for="asocQuickSearch" class="form-label asoc-po-toolbar__label mb-1">Acción rápida</label>
            <div class="input-group asoc-quick-search shadow-sm">
                <span class="input-group-text bg-dark text-white border-0"><i class="fas fa-bolt"></i></span>
                <input type="search" id="asocQuickSearch" class="form-control border-0" placeholder="Filtrar botones (Ctrl+Q)" autocomplete="off" aria-describedby="asocQuickHint">
            </div>
            <div id="asocQuickHint" class="form-text small text-muted mt-1 mb-0">Filtra los botones del panel · Atajo Ctrl+Q</div>
        </div>

        <?php if ($listaContexto !== []): ?>
        <div class="asoc-po-torneo-bar">
            <label for="asocTorneoSelect">Torneo activo</label>
            <select id="asocTorneoSelect" aria-label="Seleccionar torneo">
                <?php foreach ($listaContexto as $tx): ?>
                    <?php $tid = (int) $tx['id']; ?>
                    <option value="<?= htmlspecialchars(AppHelpers::dashboard('asociacion_panel', $panelQs(['torneo_id' => $tid, 'tab' => $tabActiva])), ENT_QUOTES, 'UTF-8') ?>"<?= $tid === $torneoPanelId ? ' selected' : '' ?>>
                        <?= htmlspecialchars($truncLabel((string) $tx['nombre'], 64)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif ($sinTorneo): ?>
        <div class="alert alert-secondary py-2 small mb-3">No hay torneos FVD visibles para su entidad en este momento.</div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-3 asoc-columns">
            <div class="asoc-po-card asoc-po-card--solicitudes">
                <h3 class="asoc-po-card__title">Solicitudes</h3>
                <div class="asoc-po-actions">
                    <a href="<?= htmlspecialchars($urlAsoc('asociacion/afiliar_atleta')) ?>" class="asoc-po-btn asoc-po-btn--violet asoc-proc-link">
                        <span>Afiliación</span><i class="fas fa-user-plus"></i>
                    </a>
                    <a href="<?= htmlspecialchars($urlAsoc('asociacion/reportes/traspasos')) ?>" class="asoc-po-btn asoc-po-btn--orange asoc-proc-link">
                        <span>Traspaso</span><i class="fas fa-exchange-alt"></i>
                    </a>
                    <a href="<?= htmlspecialchars($urlAsoc('asociacion/reportes/carnets')) ?>" class="asoc-po-btn asoc-po-btn--slate asoc-proc-link">
                        <span>Carnets</span><i class="fas fa-id-card"></i>
                    </a>
                </div>
            </div>

            <div class="asoc-po-card asoc-po-card--operaciones">
                <h3 class="asoc-po-card__title">Operaciones</h3>
                <div class="asoc-po-actions">
                    <a href="<?= htmlspecialchars($urlInscripcionPrincipal) ?>" class="asoc-po-btn asoc-po-btn--emerald asoc-proc-link<?= $btnTorneoOff ?>">
                        <span>Inscripciones</span><i class="fas fa-user-check"></i>
                    </a>
                    <a href="<?= htmlspecialchars($urlInscripciones) ?>" class="asoc-po-btn asoc-po-btn--blue asoc-proc-link<?= $btnTorneoOff ?>">
                        <span>Administrador de inscripciones</span><i class="fas fa-clipboard-list"></i>
                    </a>
                    <a href="<?= htmlspecialchars($urlVerTorneo) ?>" class="asoc-po-btn asoc-po-btn--slate asoc-proc-link<?= $btnTorneoOff ?>">
                        <span>Ver torneo</span><i class="fas fa-eye"></i>
                    </a>
                    <a href="<?= htmlspecialchars($urlCarnetQr) ?>" class="asoc-po-btn asoc-po-btn--pink asoc-proc-link<?= $btnTorneoOff ?>">
                        <span>Carnets QR</span><i class="fas fa-qrcode"></i>
                    </a>
                </div>
            </div>

            <div class="asoc-po-card asoc-po-card--finanzas">
                <h3 class="asoc-po-card__title">Finanzas</h3>
                <div class="asoc-po-actions">
                    <a href="<?= htmlspecialchars($urlFinanzas) ?>" class="asoc-po-btn asoc-po-btn--primary-fin asoc-proc-link">
                        <span>Estado de cuenta</span><i class="fas fa-file-invoice-dollar"></i>
                    </a>
                    <a href="<?= htmlspecialchars($tab2Href) ?>" class="asoc-po-btn asoc-po-btn--violet asoc-proc-link">
                        <span>Eventos nacionales</span><i class="fas fa-flag"></i>
                    </a>
                    <a href="<?= htmlspecialchars($tab3Href) ?>" class="asoc-po-btn asoc-po-btn--blue asoc-proc-link">
                        <span>Consultar otros torneos</span><i class="fas fa-trophy"></i>
                    </a>
                </div>
            </div>
        </div>

        <p class="text-end mt-3 mb-0 pt-2 border-top border-slate-200">
            <span class="text-[10px] font-mono text-slate-400"><?= htmlspecialchars(FvdBranding::soporteTecnico()) ?> · <?= htmlspecialchars(FvdBranding::nombre()) ?></span>
        </p>
    </div>
    <button type="button" class="btn btn-warning rounded-circle shadow-lg asoc-fab" title="Acción rápida" aria-label="Acción rápida" onclick="var e=document.getElementById('asocQuickSearch'); if(e){e.focus();e.select();}">
        <i class="fas fa-plus"></i>
    </button>
</div>

<style>
.asoc-fvd-wrap { background: #f4f6f9; min-height: 60vh; }
.asoc-fvd-h1 {
    font-family: 'Montserrat', system-ui, sans-serif;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-size: clamp(1.15rem, 2.5vw, 1.65rem);
    color: #0a1628;
}
.nav-tabs-asoc .nav-link {
    color: #495057;
    border: none;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
    font-weight: 600;
    font-size: 0.85rem;
}
.nav-tabs-asoc .nav-link:hover { border-color: #dee2e6; color: #0a1628; }
.nav-tabs-asoc .nav-link.active {
    color: #0a1628;
    background: transparent;
    border-color: #c9a227 #c9a227 #f4f6f9;
}
.asoc-fvd-identity { background: #fff; border-left: 4px solid #c9a227 !important; }
.asoc-fvd-identity__body {
    padding-top: 0.4rem !important;
    padding-bottom: 0.4rem !important;
}
.asoc-club-logo { width: 64px; height: 64px; object-fit: contain; }
.asoc-club-logo--active,
.asoc-club-logo-placeholder.asoc-club-logo--active {
    width: 32px;
    height: 32px;
    object-fit: contain;
    font-size: 0.875rem;
}
.asoc-club-logo-placeholder:not(.asoc-club-logo--active) { width: 64px; height: 64px; }
.asoc-entity-name { font-size: 0.95rem; letter-spacing: .04em; line-height: 1.2; }
.asoc-fvd-identity__body .small { font-size: 0.75rem; }
.asoc-col-header {
    background: #0a1628;
    color: #fff;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .12em;
    font-size: 0.75rem;
    padding: .65rem 1rem;
    border-radius: .35rem .35rem 0 0;
}
.asoc-col {
    background: #fff;
    border-radius: .35rem;
    box-shadow: 0 4px 14px rgba(10,22,40,.08);
    overflow: hidden;
}
.asoc-proc-list .list-group-item { border-color: #eef1f5; }
.asoc-proc-link:hover { background: #f8fafc; }
.asoc-ico {
    width: 2.5rem;
    height: 2.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: .5rem;
    background: #eef2f7;
    flex-shrink: 0;
}
.asoc-torneo-chip { text-decoration: none; }
.asoc-torneo-chip:hover { text-decoration: underline; }
.asoc-fab {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    width: 3.25rem;
    height: 3.25rem;
    z-index: 1040;
}
</style>
<script>
(function () {
    var inp = document.getElementById('asocQuickSearch');
    if (!inp) return;
    function filter() {
        var q = (inp.value || '').toLowerCase().trim();
        document.querySelectorAll('.asoc-proc-link').forEach(function (a) {
            var t = (a.textContent || '').toLowerCase();
            a.style.display = (!q || t.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    inp.addEventListener('input', filter);
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && (e.key === 'q' || e.key === 'Q')) {
            e.preventDefault();
            inp.focus();
            inp.select();
        }
    });
    var sel = document.getElementById('asocTorneoSelect');
    if (sel) {
        sel.addEventListener('change', function () {
            if (sel.value) {
                window.location.href = sel.value;
            }
        });
    }
})();
</script>

