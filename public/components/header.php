<?php
/**
 * Componente Header - Navbar + Menú flotante lateral
 * Variables globales: $user, app_base_url(), $SITE_NAME (desde config.php)
 */
$logo_url = class_exists('AppHelpers') ? AppHelpers::getAppLogo() : (rtrim(app_base_url(), '/') . '/public/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png'));
?>
    <!-- Navbar -->
    <nav class="bg-gradient-to-b from-primary-700 to-primary-600 shadow-lg sticky top-0 z-50 backdrop-blur-sm">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 md:h-20">
                <?php $siteNameHeader = $SITE_NAME ?? (class_exists('FvdBranding') ? FvdBranding::nombre() : 'FVD'); ?>
                <a href="<?= htmlspecialchars(($SITE_URL ?? rtrim(app_base_url(), '/') . '/public/landing.php')) ?>" class="flex items-center text-white font-bold hover:opacity-90 transition-opacity" title="<?= htmlspecialchars($siteNameHeader) ?>">
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($siteNameHeader) ?>" class="h-8 md:h-10 w-auto">
                </a>
                
                <div class="hidden md:flex items-center space-x-1">
                    <a href="#documentos" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Documentos</a>
                    <a href="#eventos-masivos" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Eventos Nacionales</a>
                    <a href="#eventos" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Eventos</a>
                    <a href="#calendario" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Calendario</a>
                    <a href="#registro" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Registro</a>
                    <a href="#servicios" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Servicios</a>
                    <a href="#precios" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Precios</a>
                    <a href="#galeria" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Galería</a>
                    <a href="#faq" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">FAQ</a>
                    <a href="#comentarios" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200 font-medium">Comentarios</a>
                    <a href="login.php" class="ml-4 px-6 py-2 bg-accent text-primary-700 font-semibold rounded-lg hover:bg-accentDark hover:text-white transition-all duration-200 shadow-md hover:shadow-lg">
                        <i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión
                    </a>
                </div>
                
                <button id="mobile-menu-btn" class="md:hidden text-white p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
            
            <div id="mobile-menu" class="hidden md:hidden pb-4">
                <div class="flex flex-col space-y-2">
                    <a href="#documentos" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Documentos</a>
                    <a href="#eventos-masivos" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Eventos Nacionales</a>
                    <a href="#eventos" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Eventos</a>
                    <a href="#calendario" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Calendario</a>
                    <a href="#registro" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Registro</a>
                    <a href="#servicios" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Servicios</a>
                    <a href="#precios" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Precios</a>
                    <a href="#galeria" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Galería</a>
                    <a href="#faq" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">FAQ</a>
                    <a href="#comentarios" class="px-4 py-2 text-white/90 hover:text-white hover:bg-white/10 rounded-lg transition-all">Comentarios</a>
                    <a href="login.php" class="mt-2 px-4 py-2 bg-accent text-primary-700 font-semibold rounded-lg hover:bg-accentDark text-center transition-all">
                        <i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <?php require __DIR__ . '/side_nav.php'; ?>
