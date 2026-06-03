<?php
/**
 * Cabecera estática del portal (nav + hero). No depende de Vue: evita pantalla azul vacía y parpadeo del logo.
 * Variables requeridas: $landing_logo_href, $base_url, $landing_page_script
 */
$landing_page_script = $landing_page_script ?? 'landing-spa.php';
?>
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
                <a href="<?= htmlspecialchars($base_url . 'ranking_atletas.php') ?>" class="px-4 py-2 fvd-nav-link rounded-lg transition-all font-medium">Ranking atletas</a>
                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="ml-4 px-6 py-2.5 fvd-btn-primary rounded-lg font-semibold transition-all shadow-lg"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
            </div>
            <button type="button" class="fvd-landing-nav__menu-btn" id="landing-mobile-menu-btn" aria-label="Abrir menú" aria-expanded="false"><i class="fas fa-bars text-xl"></i></button>
        </div>
        <div class="fvd-landing-nav__mobile" id="landing-mobile-menu" hidden>
            <div class="fvd-landing-nav__mobile-inner">
                <a href="#documentos" class="px-4 py-2 fvd-nav-link rounded-lg">Documentos</a>
                <a href="#eventos-masivos" class="px-4 py-2 fvd-nav-link rounded-lg">Eventos Nacionales</a>
                <a href="#eventos" class="px-4 py-2 fvd-nav-link rounded-lg">Eventos</a>
                <a href="#calendario" class="px-4 py-2 fvd-nav-link rounded-lg">Calendario</a>
                <a href="#galeria" class="px-4 py-2 fvd-nav-link rounded-lg">Galería</a>
                <a href="#faq" class="px-4 py-2 fvd-nav-link rounded-lg">FAQ</a>
                <a href="#comentarios" class="px-4 py-2 fvd-nav-link rounded-lg">Comentarios</a>
                <a href="<?= htmlspecialchars($base_url . 'ranking_atletas.php') ?>" class="px-4 py-2 fvd-nav-link rounded-lg">Ranking atletas</a>
                <a href="<?= htmlspecialchars($base_url . 'login.php') ?>" class="mt-2 px-4 py-2.5 fvd-btn-primary rounded-lg text-center inline-block"><i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión</a>
            </div>
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
        <div class="absolute bottom-0 left-0 right-0 pointer-events-none" aria-hidden="true"><svg class="w-full h-10 md:h-14 block" viewBox="0 0 1200 80" preserveAspectRatio="none"><path d="M0,40 C200,80 400,0 600,30 C800,60 1000,20 1200,40 L1200,80 L0,80 Z" fill="#ffffff"></path></svg></div>
    </section>
</div>
