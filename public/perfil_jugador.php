<?php
/**
 * SPA móvil: Perfil jugador por QR + Cédula (solo inscritos).
 * Acceso: perfil_jugador.php?torneo_id=X → ingresa cédula → 4 secciones críticas.
 * Diseño: max-width 480px, fuentes grandes, dashboard minimalista.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/app_helpers.php';

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$base_url = rtrim(AppHelpers::getPublicUrl(), '/') . '/';
$api_url = $base_url . 'api_perfil_jugador.php';
$logo_url = AppHelpers::getAppLogo();
$landing_url = $base_url . 'landing-spa.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Mi torneo - La Estación del Dominó</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <style>
        * { box-sizing: border-box; }
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --success: #34d399;
            --max-w: 480px;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 18px;
            line-height: 1.5;
            padding: 16px;
            padding-bottom: 32px;
        }
        .wrap {
            max-width: var(--max-w);
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .header img { height: 40px; width: auto; display: block; }
        .btn-retorno-header { display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; font-size: 0.95rem; text-decoration: none; color: var(--text); border-radius: 12px; background: var(--card); border: 1px solid rgba(255,255,255,0.1); }
        .btn-retorno-header:hover { background: #334155; color: var(--accent); }
        .btn-icon {
            background: var(--card);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text);
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
            font-size: 1.1rem;
        }
        .btn-icon:hover { background: #334155; color: var(--accent); }
        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--muted);
            margin: 0 0 12px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card h2 i { color: var(--accent); width: 22px; text-align: center; }
        .card .value { font-size: 1.35rem; font-weight: 700; color: var(--text); }
        .card .sub { font-size: 0.95rem; color: var(--muted); margin-top: 4px; }
        .btn-primary-spa {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--accent);
            color: var(--bg);
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            width: 100%;
            margin-top: 8px;
        }
        .btn-primary-spa:hover { background: #7dd3fc; color: #0f172a; }
        .btn-secondary-spa {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: transparent;
            color: var(--accent);
            border: 1px solid var(--accent);
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            width: 100%;
        }
        .btn-secondary-spa:hover { background: rgba(56,189,248,0.15); }
        input[type="text"] {
            width: 100%;
            padding: 16px 18px;
            font-size: 1.1rem;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            background: var(--card);
            color: var(--text);
            margin-bottom: 12px;
        }
        input[type="text"]::placeholder { color: var(--muted); }
        input[type="text"]:focus {
            outline: none;
            border-color: var(--accent);
        }
        .hidden { display: none !important; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .oponentes-list { margin: 8px 0 0 0; padding-left: 20px; color: var(--muted); font-size: 0.95rem; }
        .oponentes-list li { margin-bottom: 4px; }
        .last-update { font-size: 0.8rem; color: var(--muted); margin-top: 8px; }
        .link-external {
            color: var(--accent);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
        .link-external:hover { text-decoration: underline; }
        #screen-dashboard .user-bar {
            background: var(--card);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #screen-dashboard .user-bar strong { font-size: 1.05rem; }
        #screen-dashboard .user-bar span { color: var(--muted); font-size: 0.9rem; }
        .resumen-stats-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 10px; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 10px; border: 1px solid rgba(255,255,255,0.06); }
        .resumen-stats-row .stat-item { display: flex; align-items: baseline; gap: 4px; }
        .resumen-stats-row .stat-item .label { font-size: 0.65rem; color: var(--muted); text-transform: uppercase; }
        .resumen-stats-row .stat-item .value { font-size: 0.9rem; font-weight: 700; }
        .resumen-stats-row-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .resumen-stats-row-2 .stat-item { flex-direction: column; align-items: center; padding: 8px; background: rgba(0,0,0,0.15); border-radius: 8px; }
        .resumen-stats-row-2 .stat-item .value { font-size: 1rem; }
        @media (min-width: 360px) { .resumen-stats-row-2 { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 480px) { .resumen-stats-row-2 { grid-template-columns: repeat(5, 1fr); } }
        .resumen-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 12px; }
        .resumen-table-wrap table { width: 100%; min-width: 520px; border-collapse: collapse; font-size: 0.8rem; }
        .resumen-table-wrap th, .resumen-table-wrap td { padding: 6px 4px; border-bottom: 1px solid rgba(255,255,255,0.08); text-align: left; }
        .resumen-table-wrap th { color: var(--muted); font-weight: 600; }
        .resumen-table-wrap td.num, .resumen-table-wrap th.num { text-align: center; }
        .resumen-table-wrap .nombre-cell { max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .resumen-table-wrap .tfoot-row { background: rgba(34, 197, 94, 0.2); font-weight: 700; }
        @media (min-width: 481px) {
            body { background: #0c1222; }
            .wrap { box-shadow: 0 0 0 1px rgba(255,255,255,0.06); border-radius: 24px; padding: 20px; background: var(--bg); }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="header">
            <a href="<?= htmlspecialchars($landing_url) ?>" id="btn-retorno-header" class="btn-retorno-header" title="Retorno" style="margin-right: auto;"><i class="fas fa-arrow-left"></i><span>Retorno</span></a>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="La Estación del Dominó">
            <div id="header-actions"></div>
        </header>

        <!-- Pantalla: Sin torneo -->
        <div id="screen-error" class="<?= $torneo_id > 0 ? 'hidden' : '' ?>">
            <div class="card">
                <a href="<?= htmlspecialchars($landing_url) ?>" class="btn-secondary-spa mb-3"><i class="fas fa-arrow-left me-2"></i>Retorno</a>
                <h2><i class="fas fa-exclamation-triangle"></i> Enlace inválido</h2>
                <p class="value">Use el código QR del evento para acceder.</p>
                <p class="sub">La URL debe incluir el número del torneo (ej. perfil_jugador.php?torneo_id=1).</p>
            </div>
        </div>

        <!-- Pantalla: Formulario cédula -->
        <div id="screen-login" class="<?= $torneo_id > 0 ? '' : 'hidden' ?>">
            <div class="card">
                <a href="<?= htmlspecialchars($landing_url) ?>" class="btn-secondary-spa mb-3"><i class="fas fa-arrow-left me-2"></i>Retorno</a>
                <h2><i class="fas fa-id-card"></i> Acceso al torneo</h2>
                <p class="sub" style="margin-bottom: 16px;">Ingrese su cédula para ver su mesa y resumen.</p>
                <form id="form-cedula">
                    <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id ?>">
                    <input type="text" id="input-cedula" name="cedula" placeholder="Ej: V12345678 o 12345678" autocomplete="off" required>
                    <button type="submit" class="btn-primary-spa"><i class="fas fa-sign-in-alt"></i> Entrar</button>
                </form>
            </div>
        </div>

        <!-- Pantalla: Dashboard (4 secciones) -->
        <div id="screen-dashboard" class="hidden">
            <a href="#" id="btn-retorno-dashboard" class="btn-secondary-spa mb-3" style="display: inline-flex;"><i class="fas fa-arrow-left me-2"></i>Retorno</a>
            <div class="user-bar">
                <div>
                    <strong id="user-name">—</strong>
                    <span id="user-cedula" class="d-block"></span>
                </div>
            </div>

            <!-- 1. Mesa asignada -->
            <div class="card" id="card-mesa">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <h2><i class="fas fa-table"></i> Mesa asignada</h2>
                    <button type="button" class="btn-icon" id="btn-refresh-mesa" title="Actualizar"><i class="fas fa-sync-alt"></i></button>
                </div>
                <div id="mesa-content">
                    <p class="sub">Cargando…</p>
                </div>
                <p class="last-update" id="mesa-last-update"></p>
            </div>

            <!-- 2. Resumen individual -->
            <div class="card" id="card-resumen">
                <h2><i class="fas fa-chart-line"></i> Resumen individual</h2>
                <div id="resumen-content">
                    <p class="sub">—</p>
                </div>
            </div>

            <!-- 3. Resultados generales -->
            <div class="card">
                <h2><i class="fas fa-list-ol"></i> Resultados generales</h2>
                <p class="sub">Clasificación del torneo.</p>
                <a id="link-clasificacion" href="clasificacion.php?torneo_id=<?= (int)$torneo_id ?>" target="_blank" rel="noopener" class="btn-secondary-spa"><i class="fas fa-external-link-alt"></i> Ver clasificación</a>
            </div>

            <div style="margin-top: 24px;">
                <button type="button" class="btn-secondary-spa" id="btn-cerrar-sesion"><i class="fas fa-sign-out-alt"></i> Cerrar sesión</button>
            </div>
        </div>
    </div>

    <script>
(function() {
    const TORNEO_ID = <?= (int)$torneo_id ?>;
    const API_URL = <?= json_encode($api_url) ?>;
    const LANDING_URL = <?= json_encode($landing_url) ?>;
    const STORAGE_KEY = 'perfil_jugador_cedula_' + TORNEO_ID;
    const REFRESH_MS = 60000;

    let refreshTimer = null;
    let lastData = null;

    function getCedula() {
        return (typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null) || '';
    }
    function setCedula(cedula) {
        if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, cedula);
    }
    function clearCedula() {
        if (typeof localStorage !== 'undefined') localStorage.removeItem(STORAGE_KEY);
    }

    function showScreen(id) {
        ['screen-error', 'screen-login', 'screen-dashboard'].forEach(function(s) {
            document.getElementById(s).classList.toggle('hidden', s !== id);
        });
    }

    function renderHeaderActions() {
        const wrap = document.getElementById('header-actions');
        const retorno = document.getElementById('btn-retorno-header');
        if (retorno) {
            if (getCedula() && document.getElementById('screen-dashboard') && !document.getElementById('screen-dashboard').classList.contains('hidden')) {
                retorno.href = '#';
                retorno.removeAttribute('target');
                retorno.onclick = function(e) { e.preventDefault(); clearCedula(); if (refreshTimer) clearInterval(refreshTimer); refreshTimer = null; showScreen('screen-login'); document.getElementById('input-cedula').value = ''; renderHeaderActions(); };
            } else {
                retorno.href = LANDING_URL;
                retorno.setAttribute('target', '_self');
                retorno.onclick = null;
            }
        }
        if (!wrap) return;
        if (getCedula() && document.getElementById('screen-dashboard') && !document.getElementById('screen-dashboard').classList.contains('hidden')) {
            wrap.innerHTML = '<button type="button" class="btn-icon" id="btn-logout-header" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></button>';
            const btn = document.getElementById('btn-logout-header');
            if (btn) btn.addEventListener('click', doLogout);
        } else {
            wrap.innerHTML = '';
        }
    }

    function doLogout() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '¿Cerrar sesión?',
                text: 'Se borrará su cédula de este dispositivo.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, cerrar',
                cancelButtonText: 'Cancelar'
            }).then(function(res) {
                if (res.isConfirmed) {
                    clearCedula();
                    if (refreshTimer) clearInterval(refreshTimer);
                    refreshTimer = null;
                    showScreen('screen-login');
                    document.getElementById('input-cedula').value = '';
                    renderHeaderActions();
                }
            });
        } else {
            clearCedula();
            if (refreshTimer) clearInterval(refreshTimer);
            showScreen('screen-login');
            document.getElementById('input-cedula').value = '';
            renderHeaderActions();
        }
    }

    function fetchPerfil() {
        const cedula = getCedula();
        if (!cedula || !TORNEO_ID) return Promise.resolve(null);
        return fetch(API_URL + '?torneo_id=' + encodeURIComponent(TORNEO_ID) + '&cedula=' + encodeURIComponent(cedula))
            .then(function(r) { return r.json(); })
            .catch(function() { return { ok: false, error: 'Error de conexión' }; });
    }

    function renderDashboard(data) {
        lastData = data;
        if (!data || !data.ok) return;

        document.getElementById('user-name').textContent = data.jugador.nombre || '—';
        document.getElementById('user-cedula').textContent = 'Cédula ' + (data.jugador.cedula || '');

        const mesa = data.mesa_actual;
        const mesaContent = document.getElementById('mesa-content');

        if (mesa && mesa.jugadores_mesa && mesa.jugadores_mesa.length) {
            let listHtml = '<p class="value">Ronda ' + mesa.ronda + ' · Mesa ' + mesa.mesa_numero + '</p><ul class="oponentes-list" style="list-style: none; padding-left: 0;">';
            mesa.jugadores_mesa.forEach(function(j) {
                listHtml += '<li><strong>' + escapeHtml(j.ubicacion) + '</strong> — ID ' + j.id_usuario + ' · ' + escapeHtml(j.nombre) + '</li>';
            });
            listHtml += '</ul>';
            mesaContent.innerHTML = listHtml;
        } else if (mesa) {
            mesaContent.innerHTML = '<p class="value">Ronda ' + mesa.ronda + ' · Mesa ' + mesa.mesa_numero + '</p><p class="sub">Sin jugadores cargados.</p>';
        } else {
            mesaContent.innerHTML = '<p class="sub">No hay mesa asignada para la ronda actual.</p>';
        }

        document.getElementById('mesa-last-update').textContent = 'Actualizado ' + new Date().toLocaleTimeString('es');

        const resumen = data.resumen || {};
        const partidas = data.partidas || [];
        const jugador = data.jugador || {};
        let resumenHtml = '<div class="resumen-stats-row">';
        resumenHtml += '<div class="stat-item"><span class="label">Número</span><span class="value">' + (jugador.id_usuario || '—') + '</span></div>';
        resumenHtml += '<div class="stat-item"><span class="label">Nombre</span><span class="value">' + escapeHtml(jugador.nombre || '—') + '</span></div>';
        resumenHtml += '</div>';
        resumenHtml += '<div class="resumen-stats-row resumen-stats-row-2">';
        resumenHtml += '<div class="stat-item"><span class="label">Posición</span><span class="value">' + (resumen.posicion ?? '—') + '</span></div>';
        resumenHtml += '<div class="stat-item"><span class="label">Ganados</span><span class="value">' + (resumen.ganados ?? 0) + '</span></div>';
        resumenHtml += '<div class="stat-item"><span class="label">Perdidos</span><span class="value">' + (resumen.perdidos ?? 0) + '</span></div>';
        resumenHtml += '<div class="stat-item"><span class="label">Efectividad</span><span class="value">' + (resumen.efectividad ?? 0) + '</span></div>';
        resumenHtml += '<div class="stat-item"><span class="label">Puntos</span><span class="value">' + (resumen.puntos ?? 0) + '</span></div>';
        resumenHtml += '</div>';
        if (partidas.length === 0) {
            resumenHtml += '<p class="sub">Aún no hay partidas registradas.</p>';
        } else {
            var sumR1 = 0, sumR2 = 0, sumEf = 0;
            resumenHtml += '<div class="resumen-table-wrap"><table><thead><tr>';
            resumenHtml += '<th class="num">Partida</th><th class="num">Mesa</th><th>Compañero</th><th>Contrario 1</th><th>Contrario 2</th>';
            resumenHtml += '<th class="num">R1</th><th class="num">R2</th><th class="num">Efectiv.</th><th class="num">Ganados</th></tr></thead><tbody>';
            partidas.forEach(function(p, idx) {
                var mesa = parseInt(p.mesa, 10) || 0;
                var esBye = (mesa === 0 || p.mesa === '0' || String(p.mesa) === '0');
                var r1 = parseInt(p.resultado1, 10) || 0, r2 = parseInt(p.resultado2, 10) || 0, ef = parseInt(p.efectividad, 10) || 0;
                sumR1 += r1; sumR2 += r2; sumEf += ef;
                resumenHtml += '<tr>';
                resumenHtml += '<td class="num">' + (idx + 1) + '</td><td class="num">' + (esBye ? 'BYE' : mesa) + '</td>';
                resumenHtml += '<td class="nombre-cell">' + escapeHtml(p.compañero || p.companero || '—') + '</td>';
                resumenHtml += '<td class="nombre-cell">' + escapeHtml(p.contrario1 || '—') + '</td><td class="nombre-cell">' + escapeHtml(p.contrario2 || '—') + '</td>';
                resumenHtml += '<td class="num">' + r1 + '</td><td class="num">' + r2 + '</td><td class="num">' + ef + '</td><td class="num">' + (p.ganada || 0) + '</td></tr>';
            });
            resumenHtml += '</tbody><tfoot><tr class="tfoot-row"><td colspan="5" class="num"><strong>TOTALES / SUMAS</strong></td>';
            resumenHtml += '<td class="num">' + sumR1 + '</td><td class="num">' + sumR2 + '</td><td class="num">' + sumEf + '</td><td class="num">' + (resumen.ganados || 0) + '</td></tr></tfoot></table></div>';
        }
        document.getElementById('resumen-content').innerHTML = resumenHtml;

        var linkClas = document.getElementById('link-clasificacion');
        if (linkClas) {
            linkClas.href = 'clasificacion.php?torneo_id=' + encodeURIComponent(TORNEO_ID);
            linkClas.target = '_blank';
            linkClas.rel = 'noopener';
        }
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function refreshMesaSection() {
        fetchPerfil().then(function(data) {
            if (data && data.ok) {
                lastData = data;
                const mesa = data.mesa_actual;
                const mesaContent = document.getElementById('mesa-content');
                if (mesa && mesa.jugadores_mesa && mesa.jugadores_mesa.length) {
                    let listHtml = '<p class="value">Ronda ' + mesa.ronda + ' · Mesa ' + mesa.mesa_numero + '</p><ul class="oponentes-list" style="list-style: none; padding-left: 0;">';
                    mesa.jugadores_mesa.forEach(function(j) {
                        listHtml += '<li><strong>' + escapeHtml(j.ubicacion) + '</strong> — ID ' + j.id_usuario + ' · ' + escapeHtml(j.nombre) + '</li>';
                    });
                    listHtml += '</ul>';
                    mesaContent.innerHTML = listHtml;
                } else if (mesa) {
                    mesaContent.innerHTML = '<p class="value">Ronda ' + mesa.ronda + ' · Mesa ' + mesa.mesa_numero + '</p><p class="sub">Sin jugadores cargados.</p>';
                }
                document.getElementById('mesa-last-update').textContent = 'Actualizado ' + new Date().toLocaleTimeString('es');
                renderDashboard(data);
            }
        });
    }

    function attachDashboardListeners() {
        var btnRetornoDash = document.getElementById('btn-retorno-dashboard');
        if (btnRetornoDash && !btnRetornoDash._listener) {
            btnRetornoDash._listener = true;
            btnRetornoDash.addEventListener('click', function(e) {
                e.preventDefault();
                clearCedula();
                if (refreshTimer) clearInterval(refreshTimer);
                refreshTimer = null;
                showScreen('screen-login');
                document.getElementById('input-cedula').value = '';
                renderHeaderActions();
            });
        }
        var btnRefresh = document.getElementById('btn-refresh-mesa');
        var btnCerrar = document.getElementById('btn-cerrar-sesion');
        if (btnRefresh && !btnRefresh._listener) {
            btnRefresh._listener = true;
            btnRefresh.addEventListener('click', function() {
                document.getElementById('mesa-last-update').textContent = 'Actualizando…';
                refreshMesaSection();
            });
        }
        if (btnCerrar && !btnCerrar._listener) {
            btnCerrar._listener = true;
            btnCerrar.addEventListener('click', doLogout);
        }
    }

    function init() {
        if (TORNEO_ID <= 0) {
            showScreen('screen-error');
            return;
        }
        attachDashboardListeners();

        const cedula = getCedula();
        if (!cedula) {
            showScreen('screen-login');
            document.getElementById('form-cedula').addEventListener('submit', function(e) {
                e.preventDefault();
                const val = (document.getElementById('input-cedula').value || '').trim();
                if (!val) return;
                setCedula(val);
                fetchPerfil().then(function(data) {
                    if (data && data.ok) {
                        renderDashboard(data);
                        showScreen('screen-dashboard');
                        renderHeaderActions();
                        if (refreshTimer) clearInterval(refreshTimer);
                        refreshTimer = setInterval(refreshMesaSection, REFRESH_MS);
                    } else {
                        clearCedula();
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({ title: 'Acceso denegado', text: data && data.error ? data.error : 'Cédula no inscrita en este torneo.', icon: 'error' });
                        } else {
                            alert(data && data.error ? data.error : 'Cédula no inscrita en este torneo.');
                        }
                    }
                });
            });
            return;
        }

        showScreen('screen-dashboard');
        document.getElementById('mesa-content').innerHTML = '<p class="sub">Cargando…</p>';
        fetchPerfil().then(function(data) {
            if (data && data.ok) {
                renderDashboard(data);
                renderHeaderActions();
                if (refreshTimer) clearInterval(refreshTimer);
                refreshTimer = setInterval(refreshMesaSection, REFRESH_MS);
            } else {
                clearCedula();
                showScreen('screen-login');
                document.getElementById('input-cedula').value = '';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: 'Sesión inválida', text: data && data.error ? data.error : 'Vuelva a ingresar su cédula.', icon: 'error' });
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
    </script>
</body>
</html>
