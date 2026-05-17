<?php
/**
 * Landing Page SPA - La Estación del Dominó (OFICIAL)
 * Single Page Application con Vue 3 para mejor UX
 * URL oficial: .../public/landing-spa.php
 * Base y logo vía AppHelpers para que funcionen en /pruebas/public, /mistorneos_beta/public, etc.
 */
try {
    require_once __DIR__ . '/../config/bootstrap.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
    $base_url = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';
    $api_url = $base_url . 'api/landing_data.php';
    $logo_url = class_exists('AppHelpers') ? AppHelpers::getAppLogo() : ($base_url . 'view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png'));
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
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0f172a">
    <title>La Estación del Dominó - Sistema de Gestión de Torneos de Dominó en Venezuela</title>
    <meta name="description" content="Plataforma integral para la gestión de torneos de dominó en Venezuela. Participa en eventos, consulta resultados, inscríbete en torneos y únete a nuestra comunidad de jugadores.">
    <meta name="keywords" content="dominó, torneos dominó, dominó venezuela, torneos, campeonatos, clubes dominó, resultados dominó, inscripciones torneos">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>">
    <meta property="og:title" content="La Estación del Dominó - Sistema de Gestión de Torneos">
    <meta property="og:description" content="Plataforma integral para la gestión de torneos de dominó en Venezuela.">
    <link rel="canonical" href="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>">
    
    <?php $landing_css_v = @filemtime(__DIR__ . '/assets/dist/output.css') ?: time(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($base_url . 'assets/dist/output.css') ?>?v=<?= (int)$landing_css_v ?>">
    <noscript><link rel="stylesheet" href="<?= htmlspecialchars($base_url . 'assets/dist/output.css') ?>"></noscript>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"></noscript>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet"></noscript>
    
    <style>
        /* ========== Tema E-sports: variables y base ========== */
        :root {
            --esports-bg: #0f172a;
            --esports-bg-subtle: linear-gradient(180deg, #0f172a 0%, #0f172a 50%, #0c1222 100%);
            --esports-card-bg: #1e293b;
            --esports-card-border: rgba(255, 255, 255, 0.08);
            --esports-accent: #00f2ff;
            --esports-accent-hover: #33f5ff;
            --esports-accent-glow: rgba(0, 242, 255, 0.4);
            --esports-text: #e2e8f0;
            --esports-text-muted: #94a3b8;
        }
        body { font-family: 'Inter', system-ui, sans-serif; }
        body.esports-theme { background: var(--esports-bg); color: var(--esports-text); min-height: 100vh; }
        body.esports-theme .esports-font-title { font-family: 'Montserrat', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }
        .esports-theme section:not(#hero) .container { background: var(--esports-card-bg); border-radius: 12px; border: 1px solid var(--esports-card-border); }
        .esports-theme .btn-accent { background: var(--esports-accent); color: #0f172a; font-family: 'Montserrat', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; border: none; transition: box-shadow 0.25s ease, transform 0.2s ease, background 0.2s ease; }
        .esports-theme .btn-accent:hover { background: var(--esports-accent-hover); box-shadow: 0 0 24px var(--esports-accent-glow); transform: translateY(-2px); }
        .esports-theme .btn-accent.hero-cta-primary { font-size: 1.125rem; padding: 1rem 2.5rem; border-radius: 12px; box-shadow: 0 0 20px var(--esports-accent-glow); }
        .esports-theme .btn-accent.hero-cta-primary:hover { box-shadow: 0 0 32px var(--esports-accent-glow), 0 0 48px rgba(0, 242, 255, 0.2); }
        .esports-theme section:not(#hero) { margin-top: 1.5rem; margin-bottom: 1.5rem; }
        .esports-theme section:not(#hero):first-of-type { margin-top: 2rem; }
        .esports-theme section:not(#hero) .container { padding-top: 2rem; padding-bottom: 2rem; }
        .esports-theme #app > div.min-h-screen > p { color: var(--esports-text-muted); }
        /* Shell estático: sin parpadeo antes de Vue (misma jerarquía que nav+hero) */
        #static-landing-shell { flex-shrink: 0; }
        .landing-loading-below-fold { min-height: min(50vh, 28rem); flex: 1 1 auto; }
        #hero { overflow-x: hidden; overflow-y: visible; }
        #hero .esports-font-title { line-height: 1.12; }
        #hero .hero-inner { padding-top: 3rem; padding-bottom: 3rem; }
        @media (min-width: 768px) {
            #hero .hero-inner { padding-top: 5rem; padding-bottom: 5rem; }
        }
        .landing-nav-logo { height: 2.25rem; width: 2.25rem; object-fit: contain; object-position: center; border-radius: 0.35rem; }
        @media (min-width: 640px) { .landing-brand-text { display: inline !important; } }
        .esports-theme section:not(#hero) .container .text-center h2,
        .esports-theme section:not(#hero) .container h2 { color: #f1f5f9 !important; font-family: 'Montserrat', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }
        .esports-theme section:not(#hero) .container h3,
        .esports-theme section:not(#hero) .container h4,
        .esports-theme section:not(#hero) .container h5 { color: #e2e8f0 !important; }
        .esports-theme section:not(#hero) .container p { color: var(--esports-text-muted) !important; }
        .esports-theme section:not(#hero) .container .text-primary-700,
        .esports-theme section:not(#hero) .container .text-gray-900 { color: #e2e8f0 !important; }
        .esports-theme section:not(#hero) .container .text-gray-600 { color: var(--esports-text-muted) !important; }
        .esports-theme section:not(#hero) .container a:not(.btn-accent):not([class*="bg-"]) { color: var(--esports-accent); }
        .esports-theme section:not(#hero) .container a:not(.btn-accent):not([class*="bg-"]):hover { color: var(--esports-accent-hover); }
        .landing-logo-org { max-height: 60%; max-width: 60%; width: auto; height: auto; object-fit: contain; }
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
        /* Tarjetas y secciones con fondo claro: texto negro (especificidad alta para ganar al tema .esports-theme) */
        .esports-theme #documentos .container,
        .esports-theme section[class*="from-slate-50"] .container,
        .esports-theme section[class*="to-blue-50"] .container {
            background: transparent !important;
            border: none !important;
            color: #111827 !important;
        }
        .esports-theme #documentos .container h2,
        .esports-theme #documentos .container h3,
        .esports-theme #documentos .container h4,
        .esports-theme #documentos .container p,
        .esports-theme section[class*="from-slate-50"] .container h2,
        .esports-theme section[class*="from-slate-50"] .container h3,
        .esports-theme section[class*="from-slate-50"] .container p { color: #111827 !important; }

        /* Tarjetas de documentos e invitaciones FVD: fondo blanco y letras negras */
        .esports-theme #documentos .bg-white.rounded-2xl,
        .esports-theme #documentos .bg-white.rounded-xl,
        .esports-theme #documentos .bg-white\/60 {
            background: #ffffff !important;
            color: #111827 !important;
        }
        .esports-theme #documentos .bg-white.rounded-2xl h3,
        .esports-theme #documentos .bg-white.rounded-2xl h4,
        .esports-theme #documentos .bg-white.rounded-2xl p,
        .esports-theme #documentos .bg-white.rounded-xl h3,
        .esports-theme #documentos .bg-white.rounded-xl h4,
        .esports-theme #documentos .bg-white.rounded-xl p,
        .esports-theme #documentos .bg-white\/60 p,
        .esports-theme #documentos .bg-white\/60 code { color: #111827 !important; }
        .esports-theme #documentos .text-gray-600 { color: #374151 !important; }
        .esports-theme #documentos .text-primary-700 { color: #1e40af !important; }

        /* Contenedor tipo tarjeta blanca (faq, comentarios): forzar sobre tema */
        .esports-theme .landing-card-light,
        .esports-theme section#faq .container.landing-card-light,
        .esports-theme section#comentarios .container.landing-card-light {
            background: #ffffff !important;
            color: #111827 !important;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            padding: 2rem !important;
        }
        .esports-theme .landing-card-light h2,
        .esports-theme .landing-card-light h3,
        .esports-theme .landing-card-light h4,
        .esports-theme .landing-card-light h5,
        .esports-theme .landing-card-light h6,
        .esports-theme .landing-card-light p,
        .esports-theme .landing-card-light li,
        .esports-theme .landing-card-light span:not([class*="bg-"]):not([class*="text-white"]),
        .esports-theme .landing-card-light label,
        .esports-theme .landing-card-light summary { color: #111827 !important; }
        /* Títulos en negro: mayor especificidad que .esports-theme section:not(#hero) .container h2/h3 */
        .esports-theme section#faq .container.landing-card-light .text-center h2,
        .esports-theme section#faq .container.landing-card-light h2,
        .esports-theme section#faq .container.landing-card-light h3,
        .esports-theme section#faq .container.landing-card-light h4,
        .esports-theme section#faq .container.landing-card-light details summary,
        .esports-theme section#comentarios .container.landing-card-light .text-center h2,
        .esports-theme section#comentarios .container.landing-card-light h2,
        .esports-theme section#comentarios .container.landing-card-light h3,
        .esports-theme section#comentarios .container.landing-card-light h4 { color: #111827 !important; }
        .esports-theme .landing-card-light .text-gray-600,
        .esports-theme .landing-card-light .text-gray-500 { color: #374151 !important; }
        .esports-theme .landing-card-light .text-primary-700 { color: #1e40af !important; }
        .esports-theme .landing-card-light a:not([class*="bg-"]):not(.btn-accent) { color: #1d4ed8 !important; }
        .esports-theme .landing-card-light a:not([class*="bg-"]):not(.btn-accent):hover { color: #1e40af !important; }

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
        .comment-form-input:focus { outline: none; border-color: #1a365d; box-shadow: 0 0 0 3px rgba(26,54,93,0.15); }
        .comment-form-input-wrap textarea.comment-form-input { padding-top: 12px; min-height: 120px; resize: vertical; }
        .comment-form-input-wrap textarea.comment-form-input { padding-left: 2.75rem; }
        .comment-form-btn {
            min-height: 44px; padding: 12px 24px; font-size: 16px; font-weight: 700;
            border: none; border-radius: 8px; cursor: pointer;
            background: #1a365d; color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        }
        .comment-form-btn:hover:not(:disabled) { background: #152b4a; box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .comment-form-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        @media (max-width: 767px) { .comment-form-btn { width: 100%; } }
        @media (min-width: 768px) { .comment-form-btn { width: auto; margin-left: auto; display: block; } }
        .comment-form-stars { display: flex; align-items: center; flex-wrap: wrap; gap: 0.25rem; min-height: 44px; }
        .comment-form-stars label { cursor: pointer; padding: 8px; margin: -8px; }
    </style>
</head>
<body class="esports-theme antialiased">
    <div id="app" class="min-h-screen flex flex-col">
        <div v-if="loading" class="min-h-screen flex flex-col bg-[#0f172a]">
            <div id="static-landing-shell" class="static-landing-shell">
                <nav class="esports-nav bg-[#0f172a]/95 border-b border-white/10 shadow-lg sticky top-0 z-50 backdrop-blur-md" aria-label="Principal">
                    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center justify-between h-16 md:h-20">
                            <a href="<?= htmlspecialchars($base_url . 'landing-spa.php') ?>" class="flex items-center gap-2 text-white font-bold hover:opacity-90 transition-opacity" title="La Estación del Dominó">
                                <img src="<?= htmlspecialchars($logo_url) ?>" alt="" width="40" height="40" fetchpriority="high" loading="eager" decoding="async" class="landing-nav-logo shrink-0">
                                <span class="esports-font-title text-sm sm:text-base leading-tight landing-brand-text" style="display:none">La Estación del Dominó</span>
                            </a>
                            <div class="hidden md:flex items-center space-x-1">
                                <a href="#documentos" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Documentos</a>
                                <a href="#eventos-masivos" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Eventos Nacionales</a>
                                <a href="#eventos" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Eventos</a>
                                <a href="#calendario" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Calendario</a>
                                <a href="#galeria" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Galería</a>
                                <a href="#faq" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">FAQ</a>
                                <a href="#comentarios" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Comentarios</a>
                                <a href="<?= htmlspecialchars($base_url . 'ranking_atletas.php') ?>" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Ranking atletas</a>
                                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="ml-4 px-6 py-2.5 btn-accent rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                            </div>
                            <span class="md:hidden text-white p-2 rounded-lg opacity-90" aria-hidden="true"><i class="fas fa-bars text-xl"></i></span>
                        </div>
                    </div>
                </nav>
                <section id="hero" class="relative" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);">
                    <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 2px 2px, rgba(0,242,255,0.15) 1px, transparent 0); background-size: 32px 32px;"></div>
                    <div class="absolute inset-0 bg-gradient-to-r from-[#00f2ff]/10 via-transparent to-[#00f2ff]/10"></div>
                    <div class="container mx-auto px-4 sm:px-6 lg:px-8 hero-inner relative z-10">
                        <div class="max-w-4xl mx-auto text-center">
                            <h1 class="esports-font-title text-4xl md:text-5xl lg:text-7xl mb-6 leading-tight text-white tracking-tight">
                                Domina la Mesa,<br><span class="text-[#00f2ff]">Conviértete en Leyenda</span>
                            </h1>
                            <p class="text-lg md:text-xl text-slate-400 mb-10 max-w-2xl mx-auto leading-relaxed">La plataforma de torneos de dominó. Inscríbete en eventos, sigue resultados en vivo y únete a la comunidad.</p>
                            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                                <a href="#eventos" class="hero-cta-primary btn-accent inline-flex items-center justify-center w-full sm:w-auto px-10 py-5 text-lg"><i class="fas fa-calendar-check mr-3"></i>Ver eventos</a>
                                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="w-full sm:w-auto px-8 py-4 text-slate-300 font-semibold rounded-xl border border-slate-500 hover:bg-white/5 hover:text-white transition-all text-center"><i class="fas fa-sign-in-alt mr-2"></i>Ya tengo cuenta</a>
                            </div>
                        </div>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0"><svg class="w-full h-12 md:h-20 block" viewBox="0 0 1200 120" preserveAspectRatio="none" aria-hidden="true"><path d="M0,0 C150,80 350,80 600,40 C850,0 1050,0 1200,40 L1200,120 L0,120 Z" fill="#0f172a"></path></svg></div>
                </section>
            </div>
            <div class="landing-loading-below-fold flex flex-col items-center justify-center py-12 px-4 border-t border-white/5">
                <div class="flex flex-col items-center gap-4">
                    <i class="fas fa-spinner fa-spin text-5xl text-[#00f2ff]" aria-hidden="true"></i>
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
        <landing-content v-else :data="data" :base-url="baseUrl" :logo-url="logoUrl" @refresh-comentarios="cargarDatos"></landing-content>
    </div>

    <script type="text/x-template" id="landing-template">
        <div class="min-h-screen flex flex-col">
            <!-- Navbar -->
            <nav class="esports-nav bg-[#0f172a]/95 border-b border-white/10 shadow-lg sticky top-0 z-50 backdrop-blur-md">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between h-16 md:h-20">
                        <a :href="baseUrl + 'landing-spa.php'" @click.prevent="scrollToSection('hero')" class="flex items-center gap-2 text-white font-bold hover:opacity-90 transition-opacity" title="La Estación del Dominó">
                            <img :src="logoUrl" alt="" width="40" height="40" fetchpriority="high" loading="eager" decoding="async" class="landing-nav-logo shrink-0">
                            <span class="esports-font-title text-sm sm:text-base leading-tight hidden sm:inline">La Estación del Dominó</span>
                        </a>
                        <div class="hidden md:flex items-center space-x-1">
                            <a href="#documentos" @click.prevent="scrollToSection('documentos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Documentos</a>
                            <a href="#eventos-masivos" @click.prevent="scrollToSection('eventos-masivos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Eventos Nacionales</a>
                            <a href="#eventos" @click.prevent="scrollToSection('eventos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Eventos</a>
                            <a href="#calendario" @click.prevent="scrollToSection('calendario')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Calendario</a>
                            <a href="#galeria" @click.prevent="scrollToSection('galeria')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Galería</a>
                            <a href="#faq" @click.prevent="scrollToSection('faq')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">FAQ</a>
                            <a href="#comentarios" @click.prevent="scrollToSection('comentarios')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Comentarios</a>
                            <a :href="baseUrl + 'ranking_atletas.php'" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg transition-all font-medium">Ranking atletas</a>
                            <a :href="baseUrl + 'login.php'" class="ml-4 px-6 py-2.5 btn-accent rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                        </div>
                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden text-white p-2 rounded-lg hover:bg-white/10"><i class="fas fa-bars text-xl"></i></button>
                    </div>
                    <div v-show="mobileMenuOpen" class="md:hidden pb-4">
                        <div class="flex flex-col space-y-2">
                            <a href="#" @click.prevent="scrollToSection('documentos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Documentos</a>
                            <a href="#" @click.prevent="scrollToSection('eventos-masivos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Eventos Nacionales</a>
                            <a href="#" @click.prevent="scrollToSection('eventos')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Eventos</a>
                            <a href="#" @click.prevent="scrollToSection('calendario')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Calendario</a>
                            <a href="#" @click.prevent="scrollToSection('galeria')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Galería</a>
                            <a href="#" @click.prevent="scrollToSection('faq')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">FAQ</a>
                            <a href="#" @click.prevent="scrollToSection('comentarios')" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Comentarios</a>
                            <a :href="baseUrl + 'ranking_atletas.php'" class="px-4 py-2 text-slate-300 hover:text-[#00f2ff] hover:bg-white/5 rounded-lg">Ranking atletas</a>
                            <a :href="baseUrl + 'login.php'" class="mt-2 px-4 py-2.5 btn-accent rounded-lg text-center inline-block"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Hero -->
            <section id="hero" class="relative" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);">
                <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 2px 2px, rgba(0,242,255,0.15) 1px, transparent 0); background-size: 32px 32px;"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-[#00f2ff]/10 via-transparent to-[#00f2ff]/10"></div>
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 hero-inner relative z-10">
                    <div class="max-w-4xl mx-auto text-center">
                        <h1 class="esports-font-title text-4xl md:text-5xl lg:text-7xl mb-6 leading-tight text-white tracking-tight">
                            Domina la Mesa,<br><span class="text-[#00f2ff]">Conviértete en Leyenda</span>
                        </h1>
                        <p class="text-lg md:text-xl text-slate-400 mb-10 max-w-2xl mx-auto leading-relaxed">La plataforma de torneos de dominó. Inscríbete en eventos, sigue resultados en vivo y únete a la comunidad.</p>
                        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                            <a href="#" @click.prevent="scrollToSection('eventos')" class="hero-cta-primary btn-accent inline-flex items-center justify-center w-full sm:w-auto px-10 py-5 text-lg"><i class="fas fa-calendar-check mr-3"></i>Ver eventos</a>
                            <a :href="baseUrl + 'login.php'" class="w-full sm:w-auto px-8 py-4 text-slate-300 font-semibold rounded-xl border border-slate-500 hover:bg-white/5 hover:text-white transition-all text-center"><i class="fas fa-sign-in-alt mr-2"></i>Ya tengo cuenta</a>
                        </div>
                    </div>
                </div>
                <div class="absolute bottom-0 left-0 right-0"><svg class="w-full h-12 md:h-20" viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M0,0 C150,80 350,80 600,40 C850,0 1050,0 1200,40 L1200,120 L0,120 Z" fill="#0f172a"></path></svg></div>
            </section>

            <!-- Documentos oficiales de dominó -->
            <section id="documentos" class="py-16 md:py-24 bg-gradient-to-br from-slate-50 to-blue-50">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-primary-700 mb-4"><i class="fas fa-file-alt mr-3 text-accent"></i>Documentos oficiales de dominó</h2>
                        <p class="text-lg text-gray-600 max-w-2xl mx-auto">Consulte en línea, lea o descargue reglamentos, normas y documentos oficiales del dominó.</p>
                    </div>
                    <div v-if="data.documentos_oficiales?.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8 max-w-5xl mx-auto">
                        <div v-for="doc in data.documentos_oficiales" :key="doc.path" class="bg-white rounded-2xl shadow-lg hover:shadow-xl transition-all border border-gray-100 overflow-hidden">
                            <div class="p-6">
                                <div class="flex items-center justify-center w-14 h-14 bg-primary-100 rounded-xl mb-4"><i class="fas fa-file-pdf text-2xl text-primary-600"></i></div>
                                <h3 class="text-xl font-bold text-gray-900 mb-3">{{ doc.titulo }}</h3>
                                <div class="flex flex-wrap gap-2">
                                    <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 bg-primary-500 text-white font-semibold rounded-lg hover:bg-primary-600 transition-all text-sm"><i class="fas fa-external-link-alt mr-2"></i>Ver en línea</a>
                                    <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path) + '&download=1'" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-semibold rounded-lg hover:bg-gray-700 transition-all text-sm" download><i class="fas fa-download mr-2"></i>Descargar</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-12 bg-white/60 rounded-2xl max-w-xl mx-auto">
                        <i class="fas fa-folder-open text-5xl text-gray-300 mb-4"></i>
                        <p class="text-gray-600">Próximamente se publicarán aquí los documentos oficiales. Los archivos se colocan en <code class="text-sm bg-gray-100 px-2 py-1 rounded">upload/documentos_oficiales/</code>.</p>
                    </div>
                    <div v-if="data.invitaciones_fvd?.length" class="mt-16 pt-12 border-t border-gray-200">
                        <h3 class="text-2xl font-bold text-primary-700 mb-6 text-center"><i class="fas fa-envelope-open-text mr-2 text-accent"></i>Invitaciones FVD</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8 max-w-5xl mx-auto">
                            <div v-for="doc in data.invitaciones_fvd" :key="doc.path" class="bg-white rounded-2xl shadow-lg hover:shadow-xl transition-all border border-gray-100 overflow-hidden">
                                <div class="p-6">
                                    <div class="flex items-center justify-center w-14 h-14 bg-green-100 rounded-xl mb-4"><i class="fas fa-file-pdf text-2xl text-green-600"></i></div>
                                    <h4 class="text-lg font-bold text-gray-900 mb-3">{{ doc.titulo }}</h4>
                                    <div class="flex flex-wrap gap-2">
                                        <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 bg-primary-500 text-white font-semibold rounded-lg hover:bg-primary-600 transition-all text-sm"><i class="fas fa-external-link-alt mr-2"></i>Ver en línea</a>
                                        <a :href="'view_documento.php?path=' + encodeURIComponent(doc.path) + '&download=1'" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white font-semibold rounded-lg hover:bg-gray-700 transition-all text-sm" download><i class="fas fa-download mr-2"></i>Descargar</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Eventos Nacionales (Masivos) -->
            <section v-if="data.eventos_masivos?.length" id="eventos-masivos" class="py-16 md:py-24 bg-gradient-to-br from-purple-600 via-purple-700 to-indigo-800 text-white">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold mb-4"><i class="fas fa-users-cog mr-3 text-yellow-400"></i>Eventos Nacionales</h2>
                        <p class="text-lg md:text-xl text-white/90 max-w-3xl mx-auto">Inscríbete desde tu dispositivo móvil en estos eventos. Abierto a jugadores de todas las entidades.</p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                        <div v-for="ev in data.eventos_masivos" :key="ev.id" class="bg-white/10 backdrop-blur-md rounded-2xl shadow-2xl hover:shadow-3xl transition-all duration-300 overflow-hidden border-2 border-white/20 hover:border-yellow-400 transform hover:-translate-y-2 text-center">
                            <div class="w-full h-48 bg-white/20 flex flex-col items-center justify-center p-4">
                                <img v-if="ev.logo_url" :src="ev.logo_url" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">
                                <span class="text-white text-xl font-bold">{{ ev.organizacion_nombre || 'Organizador' }}</span>
                            </div>
                            <div class="p-6 text-center">
                                <div class="inline-flex items-center px-3 py-1 bg-yellow-400 text-purple-900 rounded-full text-sm font-bold mb-4"><i class="fas fa-calendar mr-2"></i>{{ formatFecha(ev.fechator) }}</div>
                                <h5 class="text-xl font-bold text-white mb-2">{{ ev.nombre_limpio || ev.nombre }}</h5>
                                <p class="text-white/80 text-sm mb-4 flex items-center justify-center"><i class="fas fa-map-marker-alt mr-2 text-yellow-400"></i>{{ ev.lugar || 'No especificado' }}</p>
                                <div class="flex flex-wrap gap-2 mb-4 justify-center">
                                    <span class="px-3 py-1 bg-blue-500/80 text-white rounded-full text-xs font-semibold">{{ CLASES[parseInt(ev.clase)||1] || 'Torneo' }}</span>
                                    <span class="px-3 py-1 bg-cyan-500/80 text-white rounded-full text-xs font-semibold">{{ MODALIDADES[parseInt(ev.modalidad)||1] || 'Individual' }}</span>
                                    <span v-if="ev.costo > 0" class="px-3 py-1 bg-green-500/80 text-white rounded-full text-xs font-semibold">${{ parseFloat(ev.costo).toFixed(2) }}</span>
                                    <span class="px-3 py-1 bg-yellow-400 text-purple-900 rounded-full text-xs font-bold"><i class="fas fa-users mr-1"></i>{{ ev.total_inscritos||0 }} inscritos</span>
                                </div>
                                <a v-if="parseInt(ev.permite_inscripcion_linea||1)===1 && !esHoy(ev.fechator)" :href="baseUrl + 'inscribir_evento_masivo.php?torneo_id=' + ev.id" class="block w-full px-4 py-3 bg-gradient-to-r from-yellow-400 to-orange-500 text-purple-900 font-bold rounded-lg hover:from-yellow-500 hover:to-orange-600 transition-all text-center shadow-lg"><i class="fas fa-mobile-alt mr-2"></i>Inscribirme Ahora</a>
                                <div v-else-if="parseInt(ev.permite_inscripcion_linea||1)===1 && esHoy(ev.fechator)" class="bg-yellow-400/20 rounded-lg p-3 border border-yellow-400/50"><p class="text-xs text-purple-900 text-center mb-0"><i class="fas fa-info-circle mr-1"></i>Inscripción deshabilitada el día del torneo.</p></div>
                                <div v-else class="bg-yellow-400/20 rounded-lg p-3 border border-yellow-400/50">
                                    <p class="text-xs text-purple-900 text-center mb-2"><i class="fas fa-info-circle mr-1"></i>Inscripción en sitio. Contacta al organizador.</p>
                                    <a v-if="ev.admin_celular || ev.club_telefono" :href="'tel:' + (ev.admin_celular || ev.club_telefono || '').replace(/\D/g,'')" class="block w-full px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-bold rounded-lg text-center shadow-lg"><i class="fas fa-phone mr-2"></i>Contactar administración</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Eventos (Futuros + Realizados) -->
            <section id="eventos" class="py-16 md:py-24 bg-gray-50">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div v-if="data.eventos_futuros?.length" class="mb-16">
                        <div class="text-center mb-12">
                            <h2 class="text-3xl md:text-4xl font-bold text-primary-700 mb-4"><i class="fas fa-calendar-check mr-3 text-accent"></i>Próximos Eventos</h2>
                            <p class="text-lg text-gray-600">Eventos programados que puedes esperar</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                            <div v-for="ev in data.eventos_futuros" :key="ev.id" class="bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all overflow-hidden border border-gray-200 hover:border-primary-500 transform hover:-translate-y-2 text-center">
                                <div class="w-full h-48 bg-gray-100 flex flex-col items-center justify-center p-4">
                                    <img v-if="ev.logo_url" :src="ev.logo_url" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">
                                    <span class="text-gray-900 text-xl font-bold">{{ ev.organizacion_nombre || 'Organizador' }}</span>
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
                                    <a v-if="parseInt(ev.permite_inscripcion_linea||1)===1 && !esHoy(ev.fechator)" :href="baseUrl + 'tournament_register.php?torneo_id=' + ev.id" class="block w-full px-4 py-2 bg-green-500 text-white font-semibold rounded-lg hover:bg-green-600 transition-all text-center mb-2"><i class="fas fa-sign-in-alt mr-2"></i>Inscribirme</a>
                                    <p v-else-if="parseInt(ev.permite_inscripcion_linea||1)===1 && esHoy(ev.fechator)" class="text-xs text-gray-500 text-center mb-2">Inscripción deshabilitada el día del torneo.</p>
                                    <a v-else-if="ev.admin_celular || ev.club_telefono" :href="'tel:' + (ev.admin_celular || ev.club_telefono || '').replace(/\D/g,'')" class="block w-full px-4 py-2 bg-green-500 text-white font-semibold rounded-lg text-center mb-2"><i class="fas fa-phone mr-2"></i>Contactar</a>
                                    <a :href="baseUrl + 'consulta_credencial.php'" class="block w-full px-4 py-2 bg-primary-500 text-white font-semibold rounded-lg hover:bg-primary-600 transition-all text-center"><i class="fas fa-info-circle mr-2"></i>Ver Información</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="data.eventos_realizados?.length">
                        <div class="text-center mb-12">
                            <h2 class="text-3xl md:text-4xl font-bold text-primary-700 mb-4"><i class="fas fa-history mr-3 text-accent"></i>Eventos Realizados</h2>
                            <p class="text-lg text-gray-600">Revisa los resultados y fotografías de eventos pasados</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                            <div v-for="ev in data.eventos_realizados" :key="ev.id" class="bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all overflow-hidden border border-gray-200 hover:border-primary-500 transform hover:-translate-y-2 text-center">
                                <div class="w-full h-48 bg-gray-100 flex flex-col items-center justify-center p-4">
                                    <img v-if="ev.logo_url" :src="ev.logo_url" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">
                                    <span class="text-gray-900 text-xl font-bold">{{ ev.organizacion_nombre || 'Organizador' }}</span>
                                </div>
                                <div class="p-6 text-center">
                                    <div class="inline-flex items-center px-3 py-1 bg-gray-600 text-white rounded-full text-sm font-semibold mb-4"><i class="fas fa-calendar mr-2"></i>{{ formatFecha(ev.fechator) }}</div>
                                    <h5 class="text-xl font-bold text-gray-900 mb-2">{{ ev.nombre }}</h5>
                                    <p class="text-gray-600 text-sm mb-4"><i class="fas fa-users mr-2 text-primary-500"></i>{{ ev.total_inscritos||0 }} participantes</p>
                                    <div class="flex flex-wrap gap-2 mb-4 justify-center">
                                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">{{ CLASES[parseInt(ev.clase)||1] || 'Torneo' }}</span>
                                        <span class="px-3 py-1 bg-cyan-100 text-cyan-700 rounded-full text-xs font-semibold">{{ MODALIDADES[parseInt(ev.modalidad)||1] || 'Individual' }}</span>
                                    </div>
                                    <a :href="baseUrl + 'evento_resultados.php?torneo_id=' + ev.id" class="block w-full px-4 py-2 bg-green-500 text-white font-semibold rounded-lg hover:bg-green-600 transition-all text-center mb-2"><i class="fas fa-chart-bar mr-2"></i>Ver Resultados</a>
                                    <button v-if="ev.total_fotos > 0" type="button" @click="viewEventPhotos(ev.id, ev.nombre)" class="w-full px-4 py-2 bg-primary-500 text-white font-semibold rounded-lg hover:bg-primary-600 transition-all"><i class="fas fa-images mr-2"></i>Ver Fotos ({{ ev.total_fotos }})</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Calendario simplificado (placeholder - se puede expandir) -->
            <section id="calendario" class="py-16 md:py-24" style="background-color: #83e3f7;">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
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
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Galería -->
            <section id="galeria" class="py-16 md:py-24 bg-gray-50">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl font-bold text-primary-700 mb-4"><i class="fas fa-images mr-3 text-accent"></i>Galería de Torneos</h2>
                        <p class="text-lg text-gray-600">Momentos destacados de nuestros eventos</p>
                    </div>
                    <div class="text-center py-12 bg-white rounded-2xl shadow-lg">
                        <i class="fas fa-images text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-600 text-lg mb-4">Momentos de nuestros torneos</p>
                        <a :href="baseUrl + 'galeria_fotos.php'" class="inline-block bg-primary-500 text-white px-6 py-2 rounded-lg font-semibold hover:bg-primary-600 transition-all"><i class="fas fa-images mr-2"></i>Ver Galería</a>
                    </div>
                </div>
            </section>

            <!-- FAQ -->
            <section id="faq" class="py-16 md:py-24 bg-gray-100">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 landing-card-light">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-primary-700 mb-4">Preguntas Frecuentes</h2>
                        <p class="text-lg md:text-xl text-gray-600 max-w-2xl mx-auto">Todo lo que necesitas saber sobre La Estación del Dominó</p>
                    </div>
                    <div class="max-w-4xl mx-auto space-y-4">
                        <details class="group bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-all border border-gray-200">
                            <summary class="flex items-center justify-between cursor-pointer font-bold text-lg text-gray-900 list-none">
                                <span><i class="fas fa-question-circle text-primary-500 mr-3"></i>¿Cómo me inscribo en un torneo?</span>
                                <i class="fas fa-chevron-down text-primary-500 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <p class="mt-4 text-gray-600 pl-10 leading-relaxed">En la sección <strong>Próximos eventos</strong> o <strong>Eventos nacionales</strong> elige el torneo y sigue el enlace de inscripción cuando esté habilitada la inscripción en línea; si no, contacta al organizador con los datos indicados en la ficha.</p>
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
                        <a href="#" @click.prevent="scrollToSection('comentarios')" class="inline-block bg-primary-500 text-white px-8 py-3 rounded-lg font-semibold hover:bg-primary-600 transition-all shadow-lg"><i class="fas fa-comments mr-2"></i>Envíanos tu Consulta</a>
                    </div>
                </div>
            </section>

            <!-- Comentarios -->
            <section id="comentarios" class="py-16 md:py-24 bg-gray-100">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 landing-card-light">
                    <div class="text-center mb-12">
                        <h2 class="text-3xl md:text-4xl font-bold text-primary-700 mb-4"><i class="fas fa-comments mr-3 text-accent"></i>Comentarios y Testimonios</h2>
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
            <footer class="bg-gray-900 text-white py-12">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex flex-col md:flex-row items-center justify-between">
                        <div class="mb-4 md:mb-0">
                            <h5 class="text-xl font-bold mb-2 flex items-center">
                                <img :src="logoUrl" alt="La Estación del Dominó" class="h-6 mr-2">
                                La Estación del Dominó
                            </h5>
                            <p class="text-gray-400">Sistema integral para la gestión de torneos de dominó</p>
                        </div>
                        <div class="text-center md:text-right">
                            <p class="text-gray-400 mb-1 flex items-center justify-center md:justify-end"><i class="fas fa-envelope mr-2"></i>info@laestaciondeldomino.com</p>
                            <p class="text-gray-500 text-sm">&copy; 2025 La Estación del Dominó. Todos los derechos reservados.</p>
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
            logoUrl: <?= json_encode($logo_url) ?>
        };
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <?php $landing_js_v = @filemtime(__DIR__ . '/assets/landing-spa.js') ?: time(); ?>
    <script src="<?= htmlspecialchars($base_url . 'assets/landing-spa.js') ?>?v=<?= (int)$landing_js_v ?>"></script>
</body>
</html>
