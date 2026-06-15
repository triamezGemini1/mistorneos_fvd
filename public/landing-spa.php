<?php
/**
 * Landing Page SPA - Portal Oficial FVD (Federación Venezolana de Dominó)
 * Single Page Application con Vue 3 para mejor UX
 * URL oficial: .../public/landing-spa.php
 * Base y logo vía AppHelpers para que funcionen en /pruebas/public, /mistorneos_beta/public, etc.
 */
try {
    require_once __DIR__ . '/../config/bootstrap.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
    require_once __DIR__ . '/../lib/FvdConfig.php';
    $base_url = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';
    $api_url = $base_url . 'api/landing_data.php';
    $landing_inscripcion_linea_publica = FvdConfig::adminModuleEnabled();
    // Logo institucional: archivo estático en public/assets/img/ (no view_image ni getPublicUrl)
    $entidad_param = isset($_GET['entidad']) ? (int)$_GET['entidad'] : 0;
    if ($entidad_param > 0) {
        $api_url .= '?entidad=' . $entidad_param;
    }
} catch (Throwable $e) {
    error_log('landing-spa.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body><p>Error al cargar la página. <a href="login.php">Ir al login</a></p></body></html>';
    exit;
}

// Assets: ruta web absoluta /mistorneos_fvd/public/... (misma lógica que el dashboard)
$landing_web_path = AppHelpers::getPublicWebPath();
$landing_base_href = AppHelpers::getPublicBaseHref();
$landing_asset_href = static function (string $rel) use ($landing_web_path): string {
    return AppHelpers::assetHref($rel, rtrim($landing_web_path, '/'));
};
/** URL absoluta a asset en public/ (evita rutas rotas con &lt;base&gt; en producción). */
$landing_public_asset = static function (string $rel, ?string $fallbackRel = null): string {
    $candidates = array_filter([$rel, $fallbackRel]);
    foreach ($candidates as $path) {
        $disk = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($disk)) {
            return AppHelpers::publicAssetUrl($path);
        }
    }

    return AppHelpers::publicAssetUrl($rel);
};
$landing_page_script = basename($_SERVER['SCRIPT_NAME'] ?? 'landing-spa.php');
if ($landing_page_script === '' || !str_ends_with(strtolower($landing_page_script), '.php')) {
    $landing_page_script = 'landing-spa.php';
}

// Logos institucionales: public/assets/vendor/img/logofvd.png | logoled.png
$landing_resolve_brand_logo = static function (string $basename) use ($landing_asset_href): string {
    $exts = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
    foreach ($exts as $ext) {
        $rel = 'assets/vendor/img/' . $basename . '.' . $ext;
        $disk = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($disk)) {
            return $landing_asset_href($rel);
        }
    }
    $fallbacks = $basename === 'logofvd'
        ? ['assets/img/logo-fvd.png', 'assets/logo.png']
        : ['assets/logo.png', 'assets/img/logo-fvd.png'];
    foreach ($fallbacks as $rel) {
        if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
            return $landing_asset_href($rel);
        }
    }
    return $landing_asset_href('assets/vendor/img/' . $basename . '.png');
};
$landing_logo_href = $landing_resolve_brand_logo('logofvd');
$landing_estacion_logo_href = $landing_resolve_brand_logo('logoled');

/** Tailwind precompilado (public/assets/css/); no usar assets/dist/ en producción. */
const LANDING_TAILWIND_CSS = 'assets/css/landing-precompiled.css';
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth fvd-landing-page">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#1e3a8a">
    <title>Federación Venezolana de Dominó | Portal Oficial FVD</title>
    <meta name="description" content="Plataforma integral para la gestión de torneos de dominó en Venezuela. Participa en eventos, consulta resultados, inscríbete en torneos y únete a nuestra comunidad de jugadores.">
    <meta name="keywords" content="dominó, torneos dominó, dominó venezuela, torneos, campeonatos, clubes dominó, resultados dominó, inscripciones torneos">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>">
    <meta property="og:title" content="Federación Venezolana de Dominó - Portal Oficial">
    <meta property="og:description" content="Plataforma integral para la gestión de torneos de dominó en Venezuela.">
    <link rel="canonical" href="<?= htmlspecialchars($base_url . $landing_page_script) ?>">
    <base href="<?= htmlspecialchars($landing_base_href) ?>">

    <link rel="stylesheet" href="<?= htmlspecialchars($landing_public_asset(LANDING_TAILWIND_CSS)) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($landing_public_asset('assets/vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($landing_public_asset('assets/css/fvd-landing-shell.css')) ?>">
    
    <style>
        /* ========== Tema institucional FVD ========== */
        :root {
            --fvd-blue-900: #1e3a8a;
            --fvd-blue-950: #172554;
            --fvd-gold: #fbbf24;
            --fvd-gold-hover: #f59e0b;
            --fvd-gold-glow: rgba(251, 191, 36, 0.35);
        }
        body { font-family: 'Inter', system-ui, sans-serif; }
        body.fvd-theme { background: var(--fvd-blue-950); color: #e2e8f0; min-height: 100vh; }
        .fvd-font-title { font-family: 'Montserrat', system-ui, sans-serif; font-weight: 800; letter-spacing: 0.03em; }
        .fvd-btn-primary {
            background: linear-gradient(180deg, #fcd34d 0%, #fbbf24 50%, #f59e0b 100%);
            color: #1e3a8a;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 14px var(--fvd-gold-glow);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }
        .fvd-btn-primary:hover { filter: brightness(1.05); box-shadow: 0 6px 20px var(--fvd-gold-glow); transform: translateY(-1px); }
        .fvd-btn-ghost {
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.35);
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .fvd-btn-ghost:hover { background: rgba(255, 255, 255, 0.08); border-color: var(--fvd-gold); color: #fff; }
        .fvd-nav-link {
            color: #cbd5e1;
            transition: color 0.2s ease, background 0.2s ease;
        }
        .fvd-nav-link:hover { color: var(--fvd-gold); background: rgba(255, 255, 255, 0.06); }
        .fvd-section-label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--fvd-gold);
        }
        .fvd-section-title { color: #1e3a8a; }
        .fvd-section-dark .fvd-section-title { color: #fff; }
        .fvd-card {
            background: #fff;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.08), 0 2px 4px -2px rgba(30, 58, 138, 0.06);
            transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease;
        }
        .fvd-card:hover {
            box-shadow: 0 20px 25px -5px rgba(30, 58, 138, 0.12), 0 8px 10px -6px rgba(30, 58, 138, 0.08);
            border-color: #fcd34d;
            transform: translateY(-2px);
        }
        .fvd-hero-pattern {
            background-image: radial-gradient(circle at 1px 1px, rgba(251, 191, 36, 0.12) 1px, transparent 0);
            background-size: 28px 28px;
        }
        .fvd-hero-logo-frame {
            background: linear-gradient(145deg, rgba(255,255,255,0.14) 0%, rgba(255,255,255,0.04) 100%);
            border: 2px solid rgba(251, 191, 36, 0.45);
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 24px 48px rgba(0, 0, 0, 0.25);
        }
        #static-landing-shell { flex-shrink: 0; }
        .landing-loading-below-fold { min-height: min(50vh, 28rem); flex: 1 1 auto; }
        #hero { overflow-x: hidden; }
        #hero .hero-inner { padding-top: 2.5rem; padding-bottom: 3rem; }
        @media (min-width: 768px) { #hero .hero-inner { padding-top: 3.5rem; padding-bottom: 4rem; } }
        @media (min-width: 1024px) { #hero .hero-inner { padding-top: 4rem; padding-bottom: 5rem; } }
        .landing-logo-org { max-height: 55%; max-width: 70%; width: auto; height: auto; object-fit: contain; }
        .fvd-event-card-dark {
            background: linear-gradient(160deg, rgba(30, 58, 138, 0.95) 0%, rgba(23, 37, 84, 0.98) 100%);
            border: 1px solid rgba(251, 191, 36, 0.25);
        }
        .fvd-event-card-dark:hover { border-color: rgba(251, 191, 36, 0.55); }
        #calendario .cal-contenedor-anual { height: calc(100vh - 160px); min-height: 380px; max-height: 80vh; overflow: hidden; max-width: 1200px; margin: 0 auto; }
        #grid-anual { display: grid; grid-template-columns: repeat(4, 1fr); grid-template-rows: repeat(3, 1fr); gap: 6px; height: 100%; overflow: hidden; }
        .cal-mini { min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .cal-mini .cal-grid-unico { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(7, 1fr); grid-auto-rows: minmax(0, 1fr); gap: 1px; padding: 2px; }
        .cal-mini .cal-dia-celda { display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: clamp(6px, 1.2vw, 10px); border-radius: 2px; cursor: pointer; position: relative; }
        .cal-indicadores-multiples { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 2px; margin-top: 2px; }
        .cal-dot-actividad { border-radius: 50%; flex-shrink: 0; }
        .cal-mini .cal-dot-actividad { width: 4px; height: 4px; }
        .cal-mes-ampliado .cal-dot-actividad { width: 8px; height: 8px; }
        #cal-mes-header, #grid-mes-ampliado { grid-template-columns: repeat(7, minmax(0, 1fr)); }
        @media (max-width: 640px) { #grid-anual { grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(4, 1fr); } #calendario .cal-contenedor-anual { height: calc(100vh - 120px); } }
        .fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
        .slide-enter-active, .slide-leave-active { transition: transform 0.3s ease; }
        .slide-enter-from { transform: translateY(-10px); opacity: 0; }
        .slide-leave-to { transform: translateY(10px); opacity: 0; }
        .fvd-panel-light {
            background: #fff;
            border-radius: 1.25rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgba(30, 58, 138, 0.08);
        }
        /* ========== Mobile-First: formularios ========== */
        .landing-form-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; width: 100%; }
        @media (min-width: 768px) { .landing-form-grid { grid-template-columns: repeat(2, 1fr); gap: 1.25rem; } }
        @media (min-width: 1024px) { .landing-form-grid { grid-template-columns: repeat(3, 1fr); gap: 1.5rem; } }
        .landing-form-grid-1-3 { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 1024px) { .landing-form-grid-1-3 { grid-template-columns: 1fr 2fr; } }
        .landing-form-grid-full { grid-column: 1 / -1; }
        .landing-input-touch { min-height: 44px; padding: 12px 16px; font-size: 16px; width: 100%; box-sizing: border-box; border-radius: 0.5rem; }
        .landing-btn-touch { min-height: 44px; padding: 12px 20px; font-size: 16px; }
        .landing-label-block { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        @media (max-width: 359px) { .landing-label-block { width: 100%; min-width: 0; } .landing-label-block label { order: -1; } }
        .landing-field { width: 100%; min-width: 0; }
        @media (max-width: 359px) { .landing-field { max-width: 100%; } }
        .landing-card-mobile { width: 100%; max-width: 100%; }
        @media (min-width: 1024px) { .landing-card-mobile { max-width: 42rem; } }
        /* Tablas → Cards en móvil (no se usa tabla en esta página; útil si se incluye después) */
        @media (max-width: 767px) {
            .landing-table-as-cards { display: block; }
            .landing-table-as-cards thead { display: none; }
            .landing-table-as-cards tr { display: block; margin-bottom: 1rem; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
            .landing-table-as-cards td { display: block; padding: 0.5rem 0; border: none; }
            .landing-table-as-cards td::before { content: attr(data-label); font-weight: 600; color: #374151; display: block; margin-bottom: 0.25rem; }
        }

        /* ========== Formulario "Envía tu comentario" – Mobile First + estética ========== */
        .comment-form-container { max-width: 800px; margin-left: auto; margin-right: auto; }
        .comment-form-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; width: 100%; padding: 0; }
        @media (min-width: 768px) {
            .comment-form-grid { grid-template-columns: repeat(2, 1fr); gap: 1.25rem; }
        }
        .comment-form-full { grid-column: 1 / -1; }
        .comment-form-field { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0; }
        .comment-form-field label { font-size: 0.875rem; font-weight: 600; color: #374151; }
        .comment-form-input-wrap { position: relative; width: 100%; }
        .comment-form-input-wrap .comment-form-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 1rem; pointer-events: none; z-index: 1; }
        .comment-form-input-wrap.comment-form-icon-textarea .comment-form-icon { top: 18px; transform: none; }
        .comment-form-input {
            width: 100%; min-height: 44px; padding: 12px 16px; padding-left: 2.75rem;
            font-size: 16px; box-sizing: border-box;
            border: 1px solid #e5e7eb; border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, outline 0.2s ease;
        }
        .comment-form-input:focus { outline: none; border-color: #1e3a8a; box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.18); }
        .comment-form-input-wrap textarea.comment-form-input { padding-top: 12px; min-height: 120px; resize: vertical; }
        .comment-form-input-wrap textarea.comment-form-input { padding-left: 2.75rem; }
        .comment-form-btn {
            min-height: 44px; padding: 12px 24px; font-size: 16px; font-weight: 700;
            border: none; border-radius: 8px; cursor: pointer;
            background: linear-gradient(180deg, #2563eb 0%, #1e3a8a 100%); color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        }
        .comment-form-btn:hover:not(:disabled) { background: linear-gradient(180deg, #1d4ed8 0%, #172554 100%); box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .comment-form-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        @media (max-width: 767px) { .comment-form-btn { width: 100%; } }
        @media (min-width: 768px) { .comment-form-btn { width: auto; margin-left: auto; display: block; } }
        .comment-form-stars { display: flex; align-items: center; flex-wrap: wrap; gap: 0.25rem; min-height: 44px; }
        .comment-form-stars label { cursor: pointer; padding: 8px; margin: -8px; }
        /* Pie institucional de reportes / cards (patrón reutilizable en el sistema) */
        .fvd-report-disclaimer { margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #e2e8f0; }
        .fvd-report-disclaimer p { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 10px; line-height: 1.45; color: #64748b; text-align: center; }
        @media (min-width: 640px) { .fvd-report-disclaimer p { font-size: 0.75rem; } }
        .fvd-section-dark .fvd-report-disclaimer { border-top-color: rgba(255, 255, 255, 0.12); }
        .fvd-section-dark .fvd-report-disclaimer p { color: rgba(148, 163, 184, 0.85); }
        /* Jerarquía de marca: FVD protagonista en cabecera/hero */
        .fvd-logo-fvd { object-fit: contain; object-position: left center; filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.25)); }
        /* Navbar: logo FVD (escala legible en barra) */
        .fvd-logo-nav { height: 2.7rem; width: auto; max-width: 8rem; }
        @media (min-width: 640px) { .fvd-logo-nav { height: 3rem; max-width: 9rem; } }
        @media (min-width: 768px) { .fvd-logo-nav { height: 3.3rem; max-width: 10rem; } }
        /* Pie de página: ambos logos a la misma escala */
        .fvd-footer-credits-logo {
            height: 1.75rem;
            width: auto;
            max-width: 8rem;
            object-fit: contain;
            object-position: center;
            flex-shrink: 0;
        }
        @media (min-width: 768px) {
            .fvd-footer-credits-logo { height: 2rem; max-width: 9rem; }
        }
        .fvd-footer-credits-led { opacity: 0.88; filter: brightness(1.05); transition: opacity 0.2s ease; }
        .fvd-footer-credits-led:hover { opacity: 1; }
        .fvd-footer-credits-text {
            font-size: 0.8125rem;
            line-height: 1.55;
            letter-spacing: 0.01em;
            color: rgba(191, 219, 254, 0.82);
        }
        @media (min-width: 640px) {
            .fvd-footer-credits-text { font-size: 0.875rem; line-height: 1.6; }
        }

        /* Podios asociaciones — tipografía institucional */
        .fvd-podios {
            --fvd-podios-bg: #00D0FA;
            --fvd-podios-bg-alt: #00b8e0;
            --fvd-podios-text: #172553;
            --fvd-podios-fs: 1.3125rem;
            background: var(--fvd-podios-bg);
            color: var(--fvd-podios-text);
            border-color: rgba(23, 37, 83, 0.22) !important;
        }
        .fvd-podios__head {
            background: var(--fvd-podios-bg-alt);
            border-bottom: 1px solid rgba(23, 37, 83, 0.18);
        }
        .fvd-podios__title {
            font-size: calc(var(--fvd-podios-fs) * 1.15);
            font-weight: 700;
            color: var(--fvd-podios-text);
            line-height: 1.25;
        }
        .fvd-podios__criterio,
        .fvd-podios__hint,
        .fvd-podios__empty {
            font-size: var(--fvd-podios-fs);
            font-weight: 700;
            color: var(--fvd-podios-text);
            line-height: 1.4;
        }
        .fvd-podios__criterio { opacity: 0.9; }
        .fvd-podios__hint { opacity: 0.92; }
        .fvd-podios__body { background: var(--fvd-podios-bg); }
        .fvd-podios__table-wrap {
            border: 1px solid rgba(23, 37, 83, 0.22);
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .fvd-podios table {
            width: 100%;
            text-align: left;
            font-size: var(--fvd-podios-fs);
            font-weight: 700;
            color: var(--fvd-podios-text);
            border-collapse: collapse;
        }
        .fvd-podios th,
        .fvd-podios td {
            color: var(--fvd-podios-text);
            font-weight: 700;
            padding: 0.85rem 1rem;
            vertical-align: middle;
        }
        .fvd-podios thead th {
            background: rgba(23, 37, 83, 0.08);
            border-bottom: 1px solid rgba(23, 37, 83, 0.2);
        }
        .fvd-podios tbody tr {
            border-top: 1px solid rgba(23, 37, 83, 0.14);
            transition: background 0.15s ease;
        }
        .fvd-podios tbody tr:nth-child(4n+1),
        .fvd-podios tbody tr:nth-child(4n+2) {
            background: rgba(23, 37, 83, 0.04);
        }
        .fvd-podios .podio-resumen-row {
            cursor: pointer;
        }
        .fvd-podios .podio-resumen-row:hover {
            background: rgba(23, 37, 83, 0.08) !important;
        }
        .fvd-podios .podio-resumen-row.fvd-podios-row--active {
            background: rgba(23, 37, 83, 0.12) !important;
            box-shadow: inset 0 0 0 2px rgba(23, 37, 83, 0.45);
        }
        .fvd-podios .fvd-podios-chevron {
            color: var(--fvd-podios-text);
            font-size: 0.85em;
            opacity: 0.85;
        }
        .fvd-podios-desglose-cell {
            background: var(--fvd-podios-bg-alt) !important;
            border-top: 1px solid rgba(23, 37, 83, 0.18) !important;
            padding: 0.75rem 1rem !important;
        }
        .fvd-podios-desglose {
            border: 1px solid rgba(23, 37, 83, 0.2);
            border-radius: 0.75rem;
            overflow: hidden;
            background: rgba(23, 37, 83, 0.04);
        }
        .fvd-podios-desglose thead th {
            background: rgba(23, 37, 83, 0.08);
        }
        .fvd-podios-desglose tbody tr:nth-child(even) {
            background: rgba(23, 37, 83, 0.04);
        }
        .fvd-podios tfoot td,
        .fvd-podios tfoot th {
            background: rgba(23, 37, 83, 0.12);
            border-top: 1px solid rgba(23, 37, 83, 0.22);
            font-weight: 700;
            color: var(--fvd-podios-text);
        }
        @media (min-width: 768px) {
            .fvd-podios {
                --fvd-podios-fs: 1.40625rem;
            }
        }
        /* Asociaciones activas — grid uniforme (6 por fila en desktop) */
        .fvd-asoc-badges {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }
        @media (min-width: 640px) {
            .fvd-asoc-badges { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 992px) {
            .fvd-asoc-badges { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1200px) {
            .fvd-asoc-badges { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        }
        .fvd-asoc-badge {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 0.5rem;
            min-height: 8.25rem;
            height: 100%;
            width: 100%;
            padding: 0.75rem 0.5rem;
            border-radius: 1rem;
            border: 2px solid transparent;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.25;
            text-align: center;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            box-shadow: 0 4px 14px rgba(23, 59, 126, 0.1);
        }
        .fvd-asoc-badge:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(23, 59, 126, 0.16);
        }
        .fvd-asoc-badge__logo {
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.08);
        }
        .fvd-asoc-badge__logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 0.2rem;
        }
        .fvd-asoc-badge__logo i { font-size: 1.15rem; }
        .fvd-asoc-badge__name {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            width: 100%;
            min-height: 2.85rem;
            word-break: break-word;
        }
        .fvd-asoc-badge--blue {
            background: linear-gradient(135deg, #dbeafe, #eff6ff);
            color: #173B7E;
            border-color: rgba(23, 59, 126, 0.22);
        }
        .fvd-asoc-badge--blue .fvd-asoc-badge__logo i { color: #173B7E; }
        .fvd-asoc-badge--amber {
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            color: #92400e;
            border-color: rgba(245, 158, 11, 0.35);
        }
        .fvd-asoc-badge--amber .fvd-asoc-badge__logo i { color: #b45309; }
        .fvd-asoc-badge--indigo {
            background: linear-gradient(135deg, #e0e7ff, #eef2ff);
            color: #3730a3;
            border-color: rgba(79, 70, 229, 0.25);
        }
        .fvd-asoc-badge--indigo .fvd-asoc-badge__logo i { color: #4338ca; }
        .fvd-asoc-badge--sky {
            background: linear-gradient(135deg, #e0f2fe, #f0f9ff);
            color: #0369a1;
            border-color: rgba(14, 165, 233, 0.28);
        }
        .fvd-asoc-badge--sky .fvd-asoc-badge__logo i { color: #0284c7; }
    </style>
    <?php include_once __DIR__ . '/includes/analytics-tracker.php'; ?>
</head>
<body class="fvd-theme antialiased">
    <div id="app" class="min-h-screen flex flex-col">
        <div v-if="loading" class="min-h-screen flex flex-col bg-blue-950">
            <div id="static-landing-shell" class="static-landing-shell">
                <nav class="fvd-landing-nav bg-blue-900/95 border-b border-amber-400/25 shadow-lg backdrop-blur-md" aria-label="Principal">
                    <div class="fvd-landing-nav__inner">
                            <a href="<?= htmlspecialchars($base_url . $landing_page_script) ?>" class="fvd-landing-nav__brand fvd-font-title" title="Federación Venezolana de Dominó">
                                <img src="<?= htmlspecialchars($landing_logo_href) ?>" alt="Logo FVD" width="192" height="78" fetchpriority="high" loading="eager" decoding="async" class="fvd-logo-fvd fvd-logo-nav shrink-0">
                                <span class="hidden sm:inline">Federación Venezolana de Dominó</span>
                            </a>
                            <div class="fvd-landing-nav__links">
                                <a href="#documentos" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Documentos</a>
                                <a href="#eventos-masivos" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Eventos Nacionales</a>
                                <a href="#eventos" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Eventos</a>
                                <a href="#calendario" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Calendario</a>
                                <a href="#galeria" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Galería</a>
                                <a href="#faq" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">FAQ</a>
                                <a href="#comentarios" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Comentarios</a>
                                <a href="#ranking-oficial" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Ranking oficial</a>
                                <a href="#asociaciones-activas" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Asociaciones</a>
                                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="ml-4 px-6 py-2.5 fvd-btn-primary rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                            </div>
                            <span class="fvd-landing-nav__menu-btn" aria-hidden="true"><i class="fas fa-bars text-xl"></i></span>
                    </div>
                </nav>
                <section id="hero" class="relative bg-blue-900 overflow-hidden">
                    <div class="absolute inset-0 fvd-hero-pattern opacity-50" aria-hidden="true"></div>
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-900 via-blue-800 to-blue-950" aria-hidden="true"></div>
                    <div class="absolute top-0 right-0 w-full lg:w-1/2 h-full bg-gradient-to-l from-amber-400/10 to-transparent pointer-events-none" aria-hidden="true"></div>
                    <div class="fvd-landing-hero__inner hero-inner relative z-10">
                        <div class="fvd-landing-hero__grid">
                            <div class="fvd-landing-hero__text">
                                <p class="fvd-section-label mb-4 justify-center lg:justify-start"><i class="fas fa-certificate" aria-hidden="true"></i> Federación Venezolana de Dominó</p>
                                <h1 class="fvd-font-title text-3xl sm:text-4xl md:text-5xl xl:text-6xl text-white leading-tight mb-5">
                                    Gestión oficial de <span class="text-amber-400">torneos de dominó</span>
                                </h1>
                                <p class="text-base md:text-lg text-blue-100/90 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">Inscripciones, calendario, resultados y documentación institucional en un solo portal para atletas, clubes y organizadores.</p>
                                <div class="fvd-landing-hero__actions">
                                    <a href="#eventos" class="fvd-btn-primary inline-flex items-center justify-center rounded-xl px-8 py-4 text-base md:text-lg font-bold"><i class="fas fa-trophy mr-2 text-blue-900" aria-hidden="true"></i>Torneos activos</a>
                                    <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="fvd-btn-ghost inline-flex items-center justify-center rounded-xl px-8 py-4 text-base font-semibold"><i class="fas fa-sign-in-alt mr-2" aria-hidden="true"></i>Acceso miembros</a>
                                </div>
                            </div>
                            <div class="fvd-landing-hero__logo-col">
                                <div class="fvd-hero-logo-frame rounded-2xl p-6 sm:p-8 flex flex-col items-center w-full max-w-xs sm:max-w-sm">
                                    <div class="rounded-xl bg-white p-4 sm:p-5 shadow-lg w-full flex justify-center">
                                        <img src="<?= htmlspecialchars($landing_logo_href) ?>" alt="Logo Federación Venezolana de Dominó" width="520" height="520" fetchpriority="high" loading="eager" decoding="async" class="fvd-logo-fvd w-full max-w-[200px] sm:max-w-[240px] md:max-w-[280px] mx-auto h-auto">
                                    </div>
                                    <p class="mt-5 text-center px-1"><span class="text-xs text-slate-400 leading-relaxed">Plataforma Oficial de la FVD | Soporte Tecnológico por La Estación del Dominó</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 pointer-events-none" aria-hidden="true"><svg class="w-full h-10 md:h-14 block" viewBox="0 0 1200 80" preserveAspectRatio="none"><path d="M0,40 C200,80 400,0 600,30 C800,60 1000,20 1200,40 L1200,80 L0,80 Z" fill="#f8fafc"></path></svg></div>
                </section>
                <?php require __DIR__ . '/includes/landing_ranking_oficial_section.php'; ?>
            </div>
            <div class="landing-loading-below-fold flex flex-col items-center justify-center py-12 px-4 border-t border-white/5">
                <div class="flex flex-col items-center gap-4">
                    <i class="fas fa-spinner fa-spin text-5xl text-amber-400" aria-hidden="true"></i>
                    <p class="text-slate-400 font-medium">Cargando contenido…</p>
                </div>
            </div>
        </div>
        <div v-else-if="error" class="min-h-screen flex flex-col items-center justify-center py-20 px-4 flex-1">
            <div class="bg-red-50 border border-red-200 rounded-xl p-8 max-w-md text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
                <p class="text-red-700 font-medium mb-4">{{ error }}</p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <button @click="cargarDatos" class="px-6 py-2 bg-primary-500 text-white rounded-lg font-semibold hover:bg-primary-600">
                        Reintentar
                    </button>
                    <a :href="baseUrl + 'landing.php'" class="px-6 py-2 bg-gray-600 text-white rounded-lg font-semibold hover:bg-gray-700 text-center no-underline">
                        Usar versión clásica
                    </a>
                </div>
            </div>
        </div>
        <landing-content v-else :data="data" :base-url="baseUrl" :logo-url="logoUrl" :inscripcion-linea-publica="inscripcionLineaPublica" @refresh-comentarios="cargarDatos"></landing-content>
    </div>

    <script type="text/x-template" id="landing-template">
        <div class="min-h-screen flex flex-col">
            <!-- Navbar -->
            <nav class="fvd-landing-nav bg-blue-900/95 border-b border-amber-400/25 shadow-lg backdrop-blur-md" aria-label="Principal">
                <div class="fvd-landing-nav__inner">
                    <a :href="baseUrl + 'landing-spa.php'" @click.prevent="scrollToSection('hero')" class="fvd-landing-nav__brand fvd-font-title" title="Federación Venezolana de Dominó">
                        <img :src="logoUrl" alt="Logo FVD" width="192" height="78" fetchpriority="high" loading="eager" decoding="async" class="fvd-logo-fvd fvd-logo-nav shrink-0">
                        <span class="hidden sm:inline">Federación Venezolana de Dominó</span>
                    </a>
                    <div class="fvd-landing-nav__links">
                            <a href="#documentos" @click.prevent="scrollToSection('documentos')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Documentos</a>
                            <a href="#eventos-masivos" @click.prevent="scrollToSection('eventos-masivos')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Eventos Nacionales</a>
                            <a href="#eventos" @click.prevent="scrollToSection('eventos')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Eventos</a>
                            <a href="#calendario" @click.prevent="scrollToSection('calendario')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Calendario</a>
                            <a href="#galeria" @click.prevent="scrollToSection('galeria')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Galería</a>
                            <a href="#faq" @click.prevent="scrollToSection('faq')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">FAQ</a>
                            <a href="#comentarios" @click.prevent="scrollToSection('comentarios')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Comentarios</a>
                            <a href="#ranking-oficial" @click.prevent="scrollToSection('ranking-oficial')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Ranking oficial</a>
                            <a href="#asociaciones-activas" @click.prevent="scrollToSection('asociaciones-activas')" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Asociaciones</a>
                            <a :href="baseUrl + 'login.php'" class="ml-4 px-6 py-2.5 fvd-btn-primary rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                        </div>
                    <button type="button" @click="mobileMenuOpen = !mobileMenuOpen" class="fvd-landing-nav__menu-btn" aria-label="Menú"><i class="fas fa-bars text-xl"></i></button>
                </div>
                <div v-show="mobileMenuOpen" class="fvd-landing-nav__mobile">
                    <div class="fvd-landing-nav__mobile-inner">
                            <a href="#" @click.prevent="scrollToSection('documentos')" class="px-4 py-2 fvd-nav-link rounded-lg">Documentos</a>
                            <a href="#" @click.prevent="scrollToSection('eventos-masivos')" class="px-4 py-2 fvd-nav-link rounded-lg">Eventos Nacionales</a>
                            <a href="#" @click.prevent="scrollToSection('eventos')" class="px-4 py-2 fvd-nav-link rounded-lg">Eventos</a>
                            <a href="#" @click.prevent="scrollToSection('calendario')" class="px-4 py-2 fvd-nav-link rounded-lg">Calendario</a>
                            <a href="#" @click.prevent="scrollToSection('galeria')" class="px-4 py-2 fvd-nav-link rounded-lg">Galería</a>
                            <a href="#" @click.prevent="scrollToSection('faq')" class="px-4 py-2 fvd-nav-link rounded-lg">FAQ</a>
                            <a href="#" @click.prevent="scrollToSection('comentarios')" class="px-4 py-2 fvd-nav-link rounded-lg">Comentarios</a>
                            <a href="#" @click.prevent="scrollToSection('ranking-oficial')" class="px-4 py-2 fvd-nav-link rounded-lg">Ranking oficial</a>
                            <a href="#" @click.prevent="scrollToSection('asociaciones-activas')" class="px-4 py-2 fvd-nav-link rounded-lg">Asociaciones</a>
                        <a :href="baseUrl + 'login.php'" class="mt-2 px-4 py-2.5 fvd-btn-primary rounded-lg text-center inline-block"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                    </div>
                </div>
            </nav>

            <!-- Hero -->
            <section id="hero" class="relative bg-blue-900 overflow-hidden">
                <div class="absolute inset-0 fvd-hero-pattern opacity-50" aria-hidden="true"></div>
                <div class="absolute inset-0 bg-gradient-to-br from-blue-900 via-blue-800 to-blue-950" aria-hidden="true"></div>
                <div class="absolute top-0 right-0 w-full lg:w-1/2 h-full bg-gradient-to-l from-amber-400/10 to-transparent pointer-events-none" aria-hidden="true"></div>
                <div class="fvd-landing-hero__inner hero-inner relative z-10">
                    <div class="fvd-landing-hero__grid">
                        <div class="fvd-landing-hero__text">
                             <p class="fvd-section-label mb-4 justify-center lg:justify-start"><i class="fas fa-certificate" aria-hidden="true"></i> Federación Venezolana de Dominó</p>
                             <h1 class="fvd-font-title text-3xl sm:text-4xl md:text-5xl xl:text-6xl text-white leading-tight mb-5">
                                 Gestión oficial de <span class="text-amber-400">torneos de dominó</span>
                             </h1>
                             <p class="text-base md:text-lg text-blue-100/90 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">Inscripciones, calendario, resultados y documentación institucional en un solo portal para atletas, clubes y organizadores.</p>
                            <div class="fvd-landing-hero__actions">
                                <a href="#" @click.prevent="scrollToSection('eventos')" class="fvd-btn-primary inline-flex items-center justify-center rounded-xl px-8 py-4 text-base md:text-lg font-bold"><i class="fas fa-trophy mr-2 text-blue-900" aria-hidden="true"></i>Torneos activos</a>
                                <a :href="baseUrl + 'login.php'" class="fvd-btn-ghost inline-flex items-center justify-center rounded-xl px-8 py-4 text-base font-semibold"><i class="fas fa-sign-in-alt mr-2" aria-hidden="true"></i>Acceso miembros</a>
                            </div>
                        </div>
                        <div class="fvd-landing-hero__logo-col">
                             <div class="fvd-hero-logo-frame rounded-2xl p-6 sm:p-8 flex flex-col items-center w-full max-w-xs sm:max-w-sm">
                                 <div class="rounded-xl bg-white p-4 sm:p-5 shadow-lg w-full flex justify-center">
                                     <img :src="logoUrl" alt="Logo Federación Venezolana de Dominó" width="520" height="520" fetchpriority="high" loading="eager" decoding="async" class="fvd-logo-fvd w-full max-w-[200px] sm:max-w-[240px] md:max-w-[280px] mx-auto h-auto">
                                 </div>
                                 <p class="mt-5 text-center px-1"><span class="text-xs text-slate-400 leading-relaxed">Plataforma Oficial de la FVD | Soporte Tecnológico por La Estación del Dominó</span></p>
                             </div>
                         </div>
                     </div>
                 </div>
                <div class="absolute bottom-0 left-0 right-0 pointer-events-none" aria-hidden="true"><svg class="w-full h-10 md:h-14" viewBox="0 0 1200 80" preserveAspectRatio="none"><path d="M0,40 C200,80 400,0 600,30 C800,60 1000,20 1200,40 L1200,80 L0,80 Z" fill="#f8fafc"></path></svg></div>
            </section>

            <!-- Ranking oficial -->
            <section id="ranking-oficial" class="py-10 md:py-16 bg-gradient-to-b from-slate-50 to-white border-b border-slate-200/80">
                <div class="fvd-landing-container">
                    <div class="text-center mb-8 md:mb-10">
                        <p class="fvd-section-label mb-3 justify-center"><i class="fas fa-medal" aria-hidden="true"></i> Clasificación nacional</p>
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3">Ranking oficial</h2>
                        <p class="text-base md:text-lg text-slate-600 max-w-3xl mx-auto">Consulta el acumulado por categoría y sexo en torneos con ranking activado de la FVD.</p>
                    </div>
                    <div class="max-w-5xl mx-auto space-y-6 md:space-y-8">
                        <div class="fvd-card overflow-hidden border border-blue-100/80 shadow-md">
                            <div class="px-5 py-4 md:px-6 md:py-5 bg-gradient-to-r from-blue-900 to-blue-800">
                                <h3 class="text-lg md:text-xl font-bold text-white m-0"><i class="fas fa-crown mr-2 text-amber-400" aria-hidden="true"></i>Categoría libre</h3>
                            </div>
                            <div class="p-4 md:p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <a :href="urlRankingOficial('absoluto', 'M')" class="group flex flex-col items-center justify-center rounded-xl border-2 border-blue-100 bg-white px-6 py-8 text-center shadow-sm transition-all hover:border-amber-400/60 hover:shadow-lg hover:-translate-y-0.5">
                                    <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-blue-50 text-blue-700 mb-3 group-hover:bg-amber-50 group-hover:text-amber-700 transition-colors"><i class="fas fa-mars text-2xl" aria-hidden="true"></i></span>
                                    <span class="text-lg font-bold text-blue-900">Masculino</span>
                                    <span class="text-sm text-slate-500 mt-1">Ranking absoluto</span>
                                </a>
                                <a :href="urlRankingOficial('absoluto', 'F')" class="group flex flex-col items-center justify-center rounded-xl border-2 border-blue-100 bg-white px-6 py-8 text-center shadow-sm transition-all hover:border-amber-400/60 hover:shadow-lg hover:-translate-y-0.5">
                                    <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-pink-50 text-pink-700 mb-3 group-hover:bg-amber-50 group-hover:text-amber-700 transition-colors"><i class="fas fa-venus text-2xl" aria-hidden="true"></i></span>
                                    <span class="text-lg font-bold text-blue-900">Femenino</span>
                                    <span class="text-sm text-slate-500 mt-1">Ranking absoluto</span>
                                </a>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-5">
                            <div v-for="sub in rankingSubs" :key="sub.slug" class="fvd-card overflow-hidden border border-slate-200/90 shadow-md flex flex-col h-full">
                                <div class="px-4 py-3 md:py-4 bg-slate-100 border-b border-slate-200">
                                    <h3 class="text-base md:text-lg font-bold text-blue-900 m-0 text-center">{{ sub.titulo }}</h3>
                                </div>
                                <div class="p-4 flex flex-col gap-3 flex-1">
                                    <a :href="urlRankingOficial(sub.slug, 'M')" class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 font-semibold text-sm bg-blue-900 text-white hover:bg-blue-800 transition-colors shadow-sm"><i class="fas fa-mars" aria-hidden="true"></i> Masculino</a>
                                    <a :href="urlRankingOficial(sub.slug, 'F')" class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 font-semibold text-sm border-2 border-blue-900 text-blue-900 bg-white hover:bg-blue-50 transition-colors"><i class="fas fa-venus" aria-hidden="true"></i> Femenino</a>
                                </div>
                            </div>
                        </div>
                        <div class="fvd-card fvd-podios overflow-hidden shadow-lg">
                            <div class="fvd-podios__head px-5 py-4 md:px-6 md:py-5">
                                <h3 class="fvd-podios__title m-0"><i class="fas fa-trophy mr-2" aria-hidden="true"></i>Podios asociaciones</h3>
                                <p v-if="podiosAsociaciones.criterio" class="fvd-podios__criterio mb-0 mt-2">{{ podiosAsociaciones.criterio }}</p>
                            </div>
                            <div class="fvd-podios__body p-4 md:p-6">
                                <div v-if="!podiosResumen.length" class="text-center py-10 fvd-podios__empty">
                                    <i class="fas fa-medal text-4xl mb-3 opacity-80" aria-hidden="true"></i>
                                    <p class="mb-0">Aún no hay podios registrados en torneos finalizados.</p>
                                </div>
                                <template v-else>
                                    <p class="fvd-podios__hint mb-3"><i class="fas fa-hand-pointer mr-1" aria-hidden="true"></i>Seleccione una asociación para desplegar el desglose por torneo.</p>
                                    <div class="fvd-podios__table-wrap overflow-x-auto">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Asociación</th>
                                                    <th class="text-center"><i class="fas fa-medal" aria-hidden="true"></i> Oro</th>
                                                    <th class="text-center">Plata</th>
                                                    <th class="text-center">Bronce</th>
                                                    <th class="text-right">Puntos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template v-for="(row, idx) in podiosResumen" :key="row.entidad_id">
                                                    <tr role="button" tabindex="0"
                                                        class="podio-resumen-row"
                                                        :class="{ 'fvd-podios-row--active': podioAsociacionSeleccionada && podioAsociacionSeleccionada.entidad_id === row.entidad_id }"
                                                        @click="seleccionarPodioAsociacion(row)"
                                                        @keydown.enter.prevent="seleccionarPodioAsociacion(row)"
                                                        @keydown.space.prevent="seleccionarPodioAsociacion(row)">
                                                        <td>{{ idx + 1 }}</td>
                                                        <td>
                                                            <i class="fas fa-chevron-right fvd-podios-chevron mr-2 transition-transform" :class="{ 'rotate-90': podioAsociacionSeleccionada && podioAsociacionSeleccionada.entidad_id === row.entidad_id }" aria-hidden="true"></i>
                                                            {{ row.asociacion }}
                                                        </td>
                                                        <td class="text-center">{{ row.oro }}</td>
                                                        <td class="text-center">{{ row.plata }}</td>
                                                        <td class="text-center">{{ row.bronce }}</td>
                                                        <td class="text-right">{{ row.total_puntos }}</td>
                                                    </tr>
                                                    <tr v-if="podioAsociacionSeleccionada && podioAsociacionSeleccionada.entidad_id === row.entidad_id">
                                                        <td colspan="6" class="fvd-podios-desglose-cell">
                                                            <div class="fvd-podios-desglose overflow-x-auto">
                                                                <table>
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Torneo</th>
                                                                            <th class="text-center">Fecha</th>
                                                                            <th class="text-center"><i class="fas fa-medal" aria-hidden="true"></i> Oro</th>
                                                                            <th class="text-center">Plata</th>
                                                                            <th class="text-center">Bronce</th>
                                                                            <th class="text-right">Puntos</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr v-if="!(row.por_torneo || []).length">
                                                                            <td colspan="6" class="text-center">Sin podios en torneos con ranking.</td>
                                                                        </tr>
                                                                        <tr v-for="t in (row.por_torneo || [])" :key="t.torneo_id">
                                                                            <td>{{ t.torneo_nombre }}</td>
                                                                            <td class="text-center whitespace-nowrap">{{ formatFecha(t.fechator) }}</td>
                                                                            <td class="text-center">{{ t.oro }}</td>
                                                                            <td class="text-center">{{ t.plata }}</td>
                                                                            <td class="text-center">{{ t.bronce }}</td>
                                                                            <td class="text-right">{{ t.total_puntos }}</td>
                                                                        </tr>
                                                                    </tbody>
                                                                    <tfoot>
                                                                        <tr>
                                                                            <td colspan="2">Total asociación</td>
                                                                            <td class="text-center">{{ row.oro }}</td>
                                                                            <td class="text-center">{{ row.plata }}</td>
                                                                            <td class="text-center">{{ row.bronce }}</td>
                                                                            <td class="text-right">{{ row.total_puntos }}</td>
                                                                        </tr>
                                                                    </tfoot>
                                                                </table>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Asociaciones activas -->
            <section id="asociaciones-activas" class="py-10 md:py-14 bg-white border-b border-slate-200/80">
                <div class="fvd-landing-container">
                    <div class="text-center mb-8 md:mb-10">
                        <p class="fvd-section-label mb-3 justify-center"><i class="fas fa-shield-alt" aria-hidden="true"></i> Red nacional FVD</p>
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3">Asociaciones Activas</h2>
                        <p class="text-base md:text-lg text-slate-600 max-w-3xl mx-auto">Directorio de asociaciones afiliadas. Seleccione una para ver representante, contacto y estadísticas de afiliados.</p>
                    </div>
                    <div v-if="asociacionesActivas.length" class="fvd-asoc-badges w-full">
                        <a
                            v-for="(asoc, idx) in asociacionesActivas"
                            :key="asoc.id"
                            :href="urlAsociacionDetalle(asoc.id)"
                            :class="['fvd-asoc-badge', badgeToneClass(idx)]"
                            :title="'Ver ficha de ' + asoc.nombre"
                        >
                            <span class="fvd-asoc-badge__logo" aria-hidden="true">
                                <img v-if="urlAsociacionLogo(asoc)" :src="urlAsociacionLogo(asoc)" :alt="''" loading="lazy" decoding="async" @error="onAsocLogoError(asoc.id)">
                                <i v-else class="fas fa-shield-alt"></i>
                            </span>
                            <span class="fvd-asoc-badge__name">{{ asoc.nombre }}</span>
                        </a>
                    </div>
                    <div v-else class="text-center py-10 text-slate-500 max-w-xl mx-auto">
                        <i class="fas fa-building text-4xl mb-3 opacity-60" aria-hidden="true"></i>
                        <p class="mb-0">No hay asociaciones activas publicadas en este momento.</p>
                    </div>
                </div>
            </section>

            <!-- Documentos oficiales de dominó -->
            <section id="documentos" class="py-12 md:py-20 bg-slate-50">
                <div class="fvd-landing-container">
                    <div class="text-center mb-10 md:mb-12">
                        <p class="fvd-section-label mb-3 justify-center"><i class="fas fa-link" aria-hidden="true"></i> Enlaces institucionales</p>
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3"><i class="fas fa-file-alt mr-2 text-amber-500"></i>Documentos oficiales</h2>
                        <p class="text-base md:text-lg text-slate-600 max-w-2xl mx-auto">Reglamentos, normas e invitaciones oficiales de la FVD.</p>
                    </div>
                    <div v-if="data.documentos_oficiales?.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8 max-w-5xl mx-auto">
                        <div v-for="doc in data.documentos_oficiales" :key="doc.path" class="fvd-card overflow-hidden">
                            <div class="p-6">
                                <div class="flex items-center justify-center w-14 h-14 bg-primary-100 rounded-xl mb-4"><i class="fas fa-file-pdf text-2xl text-primary-600"></i></div>
                                <h3 class="text-xl font-bold text-gray-900 mb-3">{{ doc.titulo }}</h3>
                                <div class="flex flex-wrap gap-2">
                                    <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 fvd-btn-primary rounded-lg font-semibold text-blue-900 transition-all text-sm"><i class="fas fa-external-link-alt mr-2"></i>Ver en línea</a>
                                    <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path) + '&download=1'" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-semibold rounded-lg hover:bg-gray-700 transition-all text-sm" download><i class="fas fa-download mr-2"></i>Descargar</a>
                                </div>
                                <div class="fvd-report-disclaimer">
                                    <p>Documento Oficial Emitido por la Federación Venezolana de Dominó (FVD). Soporte y tecnología de infraestructura por La Estación del Dominó.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-12 bg-white/60 rounded-2xl max-w-xl mx-auto">
                        <i class="fas fa-folder-open text-5xl text-gray-300 mb-4"></i>
                        <p class="text-gray-600">Próximamente se publicarán aquí los documentos oficiales. Los archivos se colocan en <code class="text-sm bg-gray-100 px-2 py-1 rounded">upload/documentos_oficiales/</code>.</p>
                    </div>
                    <div v-if="data.invitaciones_fvd?.length" class="mt-16 pt-12 border-t border-gray-200">
                        <h3 class="text-2xl font-bold text-primary-700 mb-6 text-center"><i class="fas fa-envelope-open-text mr-2 text-amber-500"></i>Invitaciones FVD</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8 max-w-5xl mx-auto">
                            <div v-for="doc in data.invitaciones_fvd" :key="doc.path" class="fvd-card overflow-hidden">
                                <div class="p-6">
                                    <div class="flex items-center justify-center w-14 h-14 bg-green-100 rounded-xl mb-4"><i class="fas fa-file-pdf text-2xl text-green-600"></i></div>
                                    <h4 class="text-lg font-bold text-gray-900 mb-1">{{ doc.titulo }}</h4>
                                    <p v-if="doc.fecha_limite" class="text-xs text-gray-500 mb-3"><i class="fas fa-calendar-check mr-1"></i>Vigente hasta {{ doc.fecha_limite }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 fvd-btn-primary rounded-lg font-semibold text-blue-900 transition-all text-sm"><i class="fas fa-external-link-alt mr-2"></i>Ver en línea</a>
                                        <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path) + '&download=1'" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-semibold rounded-lg hover:bg-gray-700 transition-all text-sm" download><i class="fas fa-download mr-2"></i>Descargar</a>
                                    </div>
                                    <div class="fvd-report-disclaimer">
                                        <p>Documento Oficial Emitido por la Federación Venezolana de Dominó (FVD). Soporte y tecnología de infraestructura por La Estación del Dominó.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Eventos Nacionales (Masivos) -->
            <section v-if="data.eventos_masivos?.length" id="eventos-masivos" class="py-12 md:py-20 bg-blue-900 text-white fvd-section-dark">
                <div class="fvd-landing-container">
                    <div class="text-center mb-12">
                        <p class="fvd-section-label mb-3 justify-center"><i class="fas fa-flag" aria-hidden="true"></i> Torneos activos</p>
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-white mb-4"><i class="fas fa-users-cog mr-2 text-amber-400"></i>Eventos Nacionales</h2>
                        <p class="text-lg md:text-xl text-white/90 max-w-3xl mx-auto">{{ inscripcionLineaPublica ? 'Inscríbete desde tu dispositivo móvil en estos eventos. Abierto a jugadores de todas las entidades.' : 'Consulta fechas y datos de contacto. La inscripción en línea no está disponible; coordina tu participación en sitio con el organizador.' }}</p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                        <div v-for="ev in data.eventos_masivos" :key="ev.id" class="fvd-event-card-dark rounded-2xl shadow-xl hover:shadow-2xl transition-all duration-300 overflow-hidden transform hover:-translate-y-1 text-center">
                            <div class="w-full h-48 flex flex-col items-center justify-center p-4 bg-white/20 bg-cover bg-center" :style="ev.portada_url ? { backgroundImage: 'url(' + ev.portada_url + ')' } : {}">
                                <template v-if="!ev.portada_url">
                                    <img v-if="ev.logo_url" :src="ev.logo_url" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">
                                    <span class="text-white text-xl font-bold">{{ ev.organizacion_nombre || 'Organizador' }}</span>
                                </template>
                            </div>
                            <div class="p-6 text-center">
                                <div class="inline-flex items-center px-3 py-1 bg-amber-400 text-blue-900 rounded-full text-sm font-bold mb-4"><i class="fas fa-calendar mr-2"></i>{{ formatFecha(ev.fechator) }}</div>
                                <h5 class="text-xl font-bold text-white mb-2">{{ ev.nombre_limpio || ev.nombre }}</h5>
                                <p class="text-white/80 text-sm mb-4 flex items-center justify-center"><i class="fas fa-map-marker-alt mr-2 text-yellow-400"></i>{{ ev.lugar || 'No especificado' }}</p>
                                <div class="flex flex-wrap gap-2 mb-4 justify-center">
                                    <span class="px-3 py-1 bg-blue-500/80 text-white rounded-full text-xs font-semibold">{{ CLASES[parseInt(ev.clase)||1] || 'Torneo' }}</span>
                                    <span class="px-3 py-1 bg-cyan-500/80 text-white rounded-full text-xs font-semibold">{{ MODALIDADES[parseInt(ev.modalidad)||1] || 'Individual' }}</span>
                                    <span v-if="ev.costo > 0" class="px-3 py-1 bg-green-500/80 text-white rounded-full text-xs font-semibold">${{ parseFloat(ev.costo).toFixed(2) }}</span>
                                    <span class="px-3 py-1 bg-amber-400 text-blue-900 rounded-full text-xs font-bold"><i class="fas fa-users mr-1"></i>{{ ev.total_inscritos||0 }} inscritos</span>
                                </div>
                                <a :href="ev.detalle_url || (baseUrl + 'torneo_detalle.php?torneo_id=' + ev.id)" class="block w-full px-4 py-2 mb-2 bg-white/15 text-white font-semibold rounded-lg hover:bg-white/25 transition-all text-center border border-white/30"><i class="fas fa-info-circle mr-2"></i>Ver ficha del torneo</a>
                                <a v-if="inscripcionLineaPublica && parseInt(ev.permite_inscripcion_linea||1)===1 && !esHoy(ev.fechator)" :href="baseUrl + 'inscribir_evento_masivo.php?torneo_id=' + ev.id" class="block w-full px-4 py-3 bg-gradient-to-r from-yellow-400 to-orange-500 text-blue-900 font-bold rounded-lg hover:from-yellow-500 hover:to-orange-600 transition-all text-center shadow-lg"><i class="fas fa-mobile-alt mr-2"></i>Inscribirme Ahora</a>
                                <div v-else-if="inscripcionLineaPublica && parseInt(ev.permite_inscripcion_linea||1)===1 && esHoy(ev.fechator)" class="bg-yellow-400/20 rounded-lg p-3 border border-yellow-400/50"><p class="text-xs text-blue-900 text-center mb-0"><i class="fas fa-info-circle mr-1"></i>Inscripción deshabilitada el día del torneo.</p></div>
                                <div v-else class="bg-yellow-400/20 rounded-lg p-3 border border-yellow-400/50">
                                    <p class="text-xs text-blue-900 text-center mb-2"><i class="fas fa-info-circle mr-1"></i>Inscripción en sitio. Contacta al organizador.</p>
                                    <a v-if="ev.admin_celular || ev.club_telefono" :href="'tel:' + (ev.admin_celular || ev.club_telefono || '').replace(/\D/g,'')" class="block w-full px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-bold rounded-lg text-center shadow-lg"><i class="fas fa-phone mr-2"></i>Contactar administración</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Eventos (Futuros + Realizados) -->
            <section id="eventos" class="py-12 md:py-20 bg-white">
                <div class="fvd-landing-container">
                    <div v-if="data.eventos_futuros?.length" class="mb-16">
                        <div class="text-center mb-12">
                            <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3"><i class="fas fa-calendar-check mr-3 text-amber-500"></i>Próximos Eventos</h2>
                            <p class="text-lg text-gray-600">Eventos programados que puedes esperar</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                            <div v-for="ev in data.eventos_futuros" :key="ev.id" class="fvd-card overflow-hidden text-center">
                                <div class="w-full h-48 bg-gray-100 flex flex-col items-center justify-center p-4 bg-cover bg-center" :style="ev.portada_url ? { backgroundImage: 'url(' + ev.portada_url + ')' } : {}">
                                    <template v-if="!ev.portada_url">
                                        <img v-if="ev.logo_url" :src="ev.logo_url" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">
                                        <span class="text-gray-900 text-xl font-bold">{{ ev.organizacion_nombre || 'Organizador' }}</span>
                                    </template>
                                </div>
                                <div class="p-6 text-center">
                                    <div class="inline-flex items-center px-3 py-1 bg-primary-500 text-white rounded-full text-sm font-semibold mb-4"><i class="fas fa-calendar mr-2"></i>{{ formatFecha(ev.fechator) }}</div>
                                    <h5 class="text-xl font-bold text-gray-900 mb-2">{{ ev.nombre }}</h5>
                                    <p class="text-gray-600 text-sm mb-4"><i class="fas fa-map-marker-alt mr-2 text-primary-500"></i>{{ ev.lugar || 'No especificado' }}</p>
                                    <div class="flex flex-wrap gap-2 mb-4 justify-center">
                                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">{{ CLASES[parseInt(ev.clase)||1] || 'Torneo' }}</span>
                                        <span class="px-3 py-1 bg-cyan-100 text-cyan-700 rounded-full text-xs font-semibold">{{ MODALIDADES[parseInt(ev.modalidad)||1] || 'Individual' }}</span>
                                        <span v-if="ev.costo > 0" class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">${{ parseFloat(ev.costo).toFixed(2) }}</span>
                                    </div>
                                    <a :href="ev.detalle_url || (baseUrl + 'torneo_detalle.php?torneo_id=' + ev.id)" class="block w-full px-4 py-2 fvd-btn-primary rounded-lg font-semibold text-blue-900 transition-all text-center mb-2"><i class="fas fa-info-circle mr-2"></i>Ver ficha del torneo</a>
                                    <a v-if="inscripcionLineaPublica && parseInt(ev.permite_inscripcion_linea||1)===1 && !esHoy(ev.fechator)" :href="baseUrl + 'tournament_register.php?torneo_id=' + ev.id" class="block w-full px-4 py-2 bg-green-500 text-white font-semibold rounded-lg hover:bg-green-600 transition-all text-center mb-2"><i class="fas fa-sign-in-alt mr-2"></i>Inscribirme</a>
                                    <p v-else-if="inscripcionLineaPublica && parseInt(ev.permite_inscripcion_linea||1)===1 && esHoy(ev.fechator)" class="text-xs text-gray-500 text-center mb-2">Inscripción deshabilitada el día del torneo.</p>
                                    <a v-else-if="ev.admin_celular || ev.club_telefono" :href="'tel:' + (ev.admin_celular || ev.club_telefono || '').replace(/\D/g,'')" class="block w-full px-4 py-2 bg-green-500 text-white font-semibold rounded-lg text-center mb-2"><i class="fas fa-phone mr-2"></i>Contactar</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="data.eventos_realizados?.length">
                        <div class="text-center mb-12">
                            <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3"><i class="fas fa-history mr-3 text-amber-500"></i>Eventos Realizados</h2>
                            <p class="text-lg text-gray-600">Revisa los resultados y fotografías de eventos pasados</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                            <div v-for="ev in data.eventos_realizados" :key="ev.id" class="fvd-card overflow-hidden text-center">
                                <div class="w-full h-48 bg-gray-100 flex flex-col items-center justify-center p-4 bg-cover bg-center" :style="ev.portada_url ? { backgroundImage: 'url(' + ev.portada_url + ')' } : {}">
                                    <template v-if="!ev.portada_url">
                                        <img v-if="ev.logo_url" :src="ev.logo_url" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">
                                        <span class="text-gray-900 text-xl font-bold">{{ ev.organizacion_nombre || 'Organizador' }}</span>
                                    </template>
                                </div>
                                <div class="p-6 text-center">
                                    <div class="inline-flex items-center px-3 py-1 bg-gray-600 text-white rounded-full text-sm font-semibold mb-4"><i class="fas fa-calendar mr-2"></i>{{ formatFecha(ev.fechator) }}</div>
                                    <h5 class="text-xl font-bold text-gray-900 mb-2">{{ ev.nombre }}</h5>
                                    <p class="text-gray-600 text-sm mb-4"><i class="fas fa-users mr-2 text-primary-500"></i>{{ ev.total_inscritos||0 }} participantes</p>
                                    <div class="flex flex-wrap gap-2 mb-4 justify-center">
                                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">{{ CLASES[parseInt(ev.clase)||1] || 'Torneo' }}</span>
                                        <span class="px-3 py-1 bg-cyan-100 text-cyan-700 rounded-full text-xs font-semibold">{{ MODALIDADES[parseInt(ev.modalidad)||1] || 'Individual' }}</span>
                                    </div>
                                    <a :href="ev.detalle_url || (baseUrl + 'torneo_detalle.php?torneo_id=' + ev.id)" class="block w-full px-4 py-2 fvd-btn-primary rounded-lg font-semibold text-blue-900 transition-all text-center mb-2"><i class="fas fa-info-circle mr-2"></i>Ver ficha del torneo</a>
                                    <a :href="baseUrl + 'evento_resultados.php?torneo_id=' + ev.id" class="block w-full px-4 py-2 bg-green-500 text-white font-semibold rounded-lg hover:bg-green-600 transition-all text-center mb-2"><i class="fas fa-chart-bar mr-2"></i>Ver Resultados</a>
                                    <button v-if="ev.total_fotos > 0" type="button" @click="viewEventPhotos(ev.id, ev.nombre)" class="w-full px-4 py-2 fvd-btn-primary rounded-lg font-semibold text-blue-900 transition-all"><i class="fas fa-images mr-2"></i>Ver Fotos ({{ ev.total_fotos }})</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Calendario simplificado (placeholder - se puede expandir) -->
            <section id="calendario" class="py-12 md:py-20 bg-blue-50">
                <div class="fvd-landing-container">
                    <div class="text-center mb-8">
                        <h2 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2"><i class="fas fa-calendar-alt mr-2 text-teal-600"></i>Calendario de Torneos</h2>
                        <p class="text-slate-600">Próximos eventos por fecha</p>
                    </div>
                    <div class="max-w-4xl mx-auto">
                        <div v-for="[fecha, eventos] in calendarioFuturo" :key="fecha" class="mb-4">
                            <div v-if="eventos.length > 0" class="bg-white rounded-xl p-4 shadow-md">
                                <h4 class="font-bold text-slate-800 mb-3">{{ fecha.split('-').reverse().join('/') }}</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <a v-for="ev in eventos" :key="ev.id" :href="baseUrl + 'evento_resultados.php?torneo_id=' + ev.id" class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg hover:bg-teal-50 transition-colors">
                                        <span class="text-primary-600 font-semibold">{{ ev.nombre_limpio || ev.nombre }}</span>
                                        <span class="text-sm text-gray-600">{{ ev.organizacion_nombre }}</span>
                                    </a>
                                </div>
                                <div class="fvd-report-disclaimer">
                                    <p>Documento Oficial Emitido por la Federación Venezolana de Dominó (FVD). Soporte y tecnología de infraestructura por La Estación del Dominó.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="fvd-report-disclaimer max-w-4xl mx-auto mt-2 px-1">
                        <p>Documento Oficial Emitido por la Federación Venezolana de Dominó (FVD). Soporte y tecnología de infraestructura por La Estación del Dominó.</p>
                    </div>
                </div>
            </section>

            <!-- Galería -->
            <section id="galeria" class="py-12 md:py-20 bg-white">
                <div class="fvd-landing-container">
                    <div class="text-center mb-12">
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3"><i class="fas fa-images mr-3 text-amber-500"></i>Galería de Torneos</h2>
                        <p class="text-lg text-gray-600">Momentos destacados de nuestros eventos</p>
                    </div>
                    <div v-if="data.galeria_destacada?.length" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">
                        <a v-for="foto in data.galeria_destacada" :key="foto.id" :href="foto.detalle_url || (baseUrl + 'torneo_detalle.php?torneo_id=' + foto.torneo_id)" class="group block rounded-xl overflow-hidden shadow-md hover:shadow-xl transition-all">
                            <img :src="foto.url" :alt="foto.torneo_nombre" class="w-full h-40 object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                            <div class="p-3 bg-slate-50 text-left">
                                <p class="text-sm font-semibold text-blue-900 truncate">{{ foto.torneo_nombre }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ foto.organizacion_nombre }}</p>
                            </div>
                        </a>
                    </div>
                    <div class="text-center py-6">
                        <a :href="baseUrl + 'galeria_fotos.php'" class="inline-block bg-primary-500 text-white px-6 py-2 rounded-lg font-semibold hover:bg-primary-600 transition-all"><i class="fas fa-images mr-2"></i>Ver galería completa</a>
                    </div>
                    <div v-if="!data.galeria_destacada?.length" class="text-center py-12 bg-white rounded-2xl shadow-lg">
                        <i class="fas fa-images text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-600 text-lg mb-4">Momentos de nuestros torneos</p>
                        <a :href="baseUrl + 'galeria_fotos.php'" class="inline-block bg-primary-500 text-white px-6 py-2 rounded-lg font-semibold hover:bg-primary-600 transition-all"><i class="fas fa-images mr-2"></i>Ver Galería</a>
                    </div>
                </div>
            </section>

            <!-- FAQ -->
            <section id="faq" class="py-12 md:py-20 bg-slate-100">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 fvd-panel-light">
                    <div class="text-center mb-12">
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3">Preguntas Frecuentes</h2>
                        <p class="text-lg md:text-xl text-gray-600 max-w-2xl mx-auto">Todo lo que necesitas saber sobre el portal oficial de la FVD</p>
                    </div>
                    <div class="max-w-4xl mx-auto space-y-4">
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Cómo me inscribo en un torneo?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed" v-if="inscripcionLineaPublica">En la sección <strong>Próximos eventos</strong> o <strong>Eventos nacionales</strong> elige el torneo y sigue el enlace de inscripción cuando esté habilitada la inscripción en línea; si no, contacta al organizador con los datos indicados en la ficha.</p>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed" v-else>Consulta la ficha del torneo en <strong>Próximos eventos</strong> y contacta al organizador con los datos indicados para inscribirte en sitio el día del evento.</p>
                        </details>
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Es gratuito participar en los torneos?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed">Depende del torneo. Toda la información sobre costos está disponible en la ficha de cada torneo.</p>
                        </details>
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Puedo ver los resultados de torneos anteriores?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed">¡Por supuesto! Todos los resultados están disponibles en la sección "Eventos Realizados".</p>
                        </details>
                    </div>
                    <div class="text-center mt-12">
                        <a href="#" @click.prevent="scrollToSection('comentarios')" class="inline-block fvd-btn-primary px-8 py-3 rounded-xl font-bold shadow-lg"><i class="fas fa-comments mr-2"></i>Envíanos tu Consulta</a>
                    </div>
                </div>
            </section>

            <!-- Comentarios -->
            <section id="comentarios" class="py-12 md:py-20 bg-slate-100">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 fvd-panel-light">
                    <div class="text-center mb-12">
                        <h2 class="fvd-font-title text-2xl sm:text-3xl md:text-4xl font-bold text-blue-900 mb-3"><i class="fas fa-comments mr-3 text-amber-500"></i>Comentarios y Testimonios</h2>
                        <p class="text-lg text-gray-600">La opinión de nuestra comunidad es muy importante</p>
                    </div>
                    <div v-if="commentSuccess" class="max-w-4xl mx-auto mb-6">
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg shadow-md"><i class="fas fa-check-circle mr-3"></i>{{ commentSuccess }}</div>
                    </div>
                    <div v-if="commentErrors.length" class="max-w-4xl mx-auto mb-6">
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg shadow-md">
                            <div v-for="err in commentErrors" :key="err">{{ err }}</div>
                        </div>
                    </div>
                    <div class="max-w-7xl mx-auto">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="bg-white rounded-2xl shadow-lg p-6 border border-gray-200 hover:shadow-xl transition-shadow">
                                    <h3 class="text-2xl font-bold text-gray-900 mb-6"><i class="fas fa-comment-dots text-primary-500 mr-2"></i>Envía tu Comentario</h3>
                                    <template v-if="data.user">
                                        <form @submit.prevent="enviarComentario" class="comment-form-grid">
                                            <div class="bg-primary-50 p-3 rounded-lg comment-form-full" style="margin-bottom: 0;">
                                                <p class="text-sm text-primary-700 mb-0"><i class="fas fa-user-check mr-2"></i>Comentando como: <strong>{{ data.user.nombre }}</strong></p>
                                            </div>
                                            <div class="comment-form-field">
                                                <label>Tipo *</label>
                                                <div class="comment-form-input-wrap">
                                                    <i class="fas fa-tag comment-form-icon" aria-hidden="true"></i>
                                                    <select v-model="commentForm.tipo" required class="comment-form-input">
                                                        <option value="comentario">Comentario</option>
                                                        <option value="sugerencia">Sugerencia</option>
                                                        <option value="testimonio">Testimonio</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="comment-form-field">
                                                <label>Calificación (opcional)</label>
                                                <div class="comment-form-stars">
                                                    <label v-for="i in 5" :key="i">
                                                        <input type="radio" v-model="commentForm.calificacion" :value="i" class="hidden">
                                                        <i class="far fa-star text-2xl hover:text-yellow-500 transition-colors" :class="commentForm.calificacion >= i ? 'fas text-yellow-400' : 'text-yellow-400'"></i>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="comment-form-field comment-form-full">
                                                <label>Mensaje *</label>
                                                <div class="comment-form-input-wrap comment-form-icon-textarea">
                                                    <i class="fas fa-comment comment-form-icon" aria-hidden="true"></i>
                                                    <textarea v-model="commentForm.contenido" rows="5" required placeholder="Escribe tu comentario..." class="comment-form-input"></textarea>
                                                </div>
                                            </div>
                                            <div class="comment-form-full">
                                                <button type="submit" :disabled="commentSending" class="comment-form-btn">
                                                    <i v-if="commentSending" class="fas fa-spinner fa-spin mr-2"></i>
                                                    <i v-else class="fas fa-paper-plane mr-2"></i>{{ commentSending ? 'Enviando...' : 'Enviar Comentario' }}
                                                </button>
                                            </div>
                                            <p class="text-xs text-gray-500 text-center comment-form-full mb-0"><i class="fas fa-shield-alt mr-1"></i>Los comentarios son moderados antes de publicarse</p>
                                        </form>
                                    </template>
                                    <div v-else class="text-center py-8">
                                        <i class="fas fa-lock text-4xl text-gray-400 mb-4"></i>
                                        <p class="text-gray-600 mb-4">Debes iniciar sesión para publicar comentarios</p>
                                        <a :href="baseUrl + 'login.php?redirect=' + encodeURIComponent(baseUrl + 'landing-spa.php#comentarios')" class="inline-block bg-primary-500 text-white px-6 py-3 rounded-lg font-semibold hover:bg-primary-600 transition-all"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                                    </div>
                                </div>
                            <div class="space-y-6">
                                <div v-if="data.comentarios?.length" class="space-y-6">
                                    <div class="bg-white rounded-2xl shadow-lg p-6 mb-6 border border-gray-200">
                                        <div class="grid grid-cols-3 gap-4 text-center">
                                            <div><div class="text-3xl font-bold text-primary-500">{{ data.comentarios.filter(c => c.tipo === 'comentario').length }}</div><div class="text-sm text-gray-600">Comentarios</div></div>
                                            <div><div class="text-3xl font-bold text-purple-600">{{ data.comentarios.filter(c => c.tipo === 'sugerencia').length }}</div><div class="text-sm text-gray-600">Sugerencias</div></div>
                                            <div><div class="text-3xl font-bold text-yellow-600">{{ data.comentarios.filter(c => c.tipo === 'testimonio').length }}</div><div class="text-sm text-gray-600">Testimonios</div></div>
                                        </div>
                                        <div class="fvd-report-disclaimer">
                                            <p>Documento Oficial Emitido por la Federación Venezolana de Dominó (FVD). Soporte y tecnología de infraestructura por La Estación del Dominó.</p>
                                        </div>
                                    </div>
                                    <div v-for="c in data.comentarios" :key="c.id" class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-xl transition-shadow border border-gray-200">
                                        <div class="flex items-start justify-between mb-4">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-12 h-12 bg-gradient-to-br from-primary-500 to-primary-700 rounded-full flex items-center justify-center text-white font-bold">{{ (c.nombre || 'U').charAt(0).toUpperCase() }}</div>
                                                <div>
                                                    <h4 class="font-bold text-gray-900">{{ c.nombre }} <span v-if="c.usuario_username" class="text-xs text-primary-500 ml-2"><i class="fas fa-user-check"></i> Usuario registrado</span></h4>
                                                    <span class="text-xs text-gray-500">{{ new Date(c.fecha_creacion).toLocaleString('es-VE') }}</span>
                                                </div>
                                            </div>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold" :class="c.tipo === 'comentario' ? 'bg-blue-100 text-blue-800' : c.tipo === 'sugerencia' ? 'bg-purple-100 text-purple-800' : 'bg-yellow-100 text-yellow-800'">{{ (c.tipo || 'comentario').charAt(0).toUpperCase() + (c.tipo || 'comentario').slice(1) }}</span>
                                        </div>
                                        <div v-if="c.calificacion" class="mb-3">
                                            <i v-for="i in 5" :key="i" class="fas fa-star" :class="i <= c.calificacion ? 'text-yellow-400' : 'text-gray-300'"></i>
                                        </div>
                                        <p class="text-gray-700 leading-relaxed whitespace-pre-wrap">{{ c.contenido }}</p>
                                    </div>
                                </div>
                                <div v-else class="bg-white rounded-2xl shadow-lg p-12 text-center border border-gray-200">
                                    <i class="fas fa-comment-slash text-gray-400 text-6xl mb-4"></i>
                                    <h3 class="text-2xl font-bold text-gray-900 mb-2">No hay comentarios aún</h3>
                                    <p class="text-gray-600 mb-6">Sé el primero en compartir tu opinión con la comunidad.</p>
                                    <a v-if="!data.user" :href="baseUrl + 'login.php?redirect=' + encodeURIComponent(baseUrl + 'landing-spa.php#comentarios')" class="inline-block bg-gradient-to-r from-primary-500 to-primary-700 text-white px-6 py-3 rounded-lg font-semibold hover:from-primary-600 hover:to-primary-800 transition-all"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión para Comentar</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer class="bg-blue-950 border-t border-amber-400/20 text-white">
                <div class="fvd-landing-container py-10 md:py-12">
                    <div class="flex flex-col lg:flex-row items-center lg:items-start justify-between gap-8 text-center lg:text-left">
                        <div class="flex flex-col sm:flex-row items-center gap-4 max-w-lg">
                            <img :src="logoUrl" alt="Logo FVD" class="fvd-logo-fvd h-11 w-auto md:h-14 shrink-0">
                            <div>
                                <h5 class="fvd-font-title text-lg md:text-xl text-white mb-1">Federación Venezolana de Dominó</h5>
                                <p class="text-blue-200/80 text-sm leading-relaxed">Portal oficial de torneos, inscripciones, resultados y documentación institucional.</p>
                            </div>
                        </div>
                        <div class="text-blue-200/70 text-sm">
                            <p class="flex items-center justify-center lg:justify-end gap-2"><i class="fas fa-envelope text-amber-400/80"></i>info@laestaciondeldomino.com</p>
                        </div>
                    </div>
                </div>
                <div class="border-t border-white/10 bg-blue-950">
                    <div class="fvd-landing-container py-5 md:py-6">
                        <div class="flex flex-col md:flex-row items-center justify-between gap-6 md:gap-8">
                            <div class="flex items-center gap-3 text-center md:text-left w-full md:w-auto justify-center md:justify-start">
                                <img :src="logoUrl" alt="Logo FVD" width="128" height="32" loading="lazy" decoding="async" class="fvd-footer-credits-logo">
                                <p class="fvd-footer-credits-text text-left">
                                    <span class="text-blue-100/95 font-medium">&copy; <?= date('Y') ?> Federación Venezolana de Dominó.</span>
                                    <span class="text-blue-300/70"> Todos los derechos reservados.</span>
                                </p>
                            </div>
                            <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 text-center sm:text-right w-full md:w-auto justify-center md:justify-end">
                                <p class="fvd-footer-credits-text max-w-md">
                                    Desarrollo, infraestructura y soporte tecnológico provisto por
                                </p>
                                <div class="flex items-center gap-2.5 shrink-0" title="La Estación del Dominó">
                                    <img src="<?= htmlspecialchars($landing_estacion_logo_href) ?>" alt="La Estación del Dominó" width="128" height="32" loading="lazy" decoding="async" class="fvd-footer-credits-logo fvd-footer-credits-led">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </footer>

            <div id="modal-container"></div>
        </div>
    </script>
    <script>
        window.APP_CONFIG = {
            apiUrl: <?= json_encode($api_url) ?>,
            baseUrl: <?= json_encode($base_url) ?>,
            logoUrl: <?= json_encode($landing_logo_href) ?>,
            estacionLogoUrl: <?= json_encode($landing_estacion_logo_href) ?>,
            inscripcionLineaPublica: <?= json_encode($landing_inscripcion_linea_publica) ?>
        };
    </script>
    <script src="<?= htmlspecialchars($landing_public_asset('assets/vendor/vue/vue.global.prod.js')) ?>"></script>
    <script>
        if (typeof Vue === 'undefined') {
            document.write('<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"><\/script>');
        }
    </script>
    <script src="<?= htmlspecialchars($landing_public_asset('assets/landing-spa.js')) ?>"></script>
</body>
</html>
