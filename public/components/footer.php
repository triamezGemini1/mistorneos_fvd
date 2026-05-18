<?php
/**
 * Componente Footer - Pie de página con contacto
 * Variables globales: $SITE_NAME, $SITE_TAGLINE, $SITE_EMAIL (desde config.php), app_base_url()
 */
$logo_url = class_exists('AppHelpers') ? AppHelpers::getAppLogo() : (rtrim(app_base_url(), '/') . '/public/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png'));
$site_name = $SITE_NAME ?? (class_exists('FvdBranding') ? FvdBranding::nombre() : 'FVD');
$site_tagline = $SITE_TAGLINE ?? 'Sistema integral para la gestión de torneos de dominó';
$site_email = $SITE_EMAIL ?? 'info@laestaciondeldomino.com';
?>
    <footer id="contacto" class="bg-gray-900 text-white py-12">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center justify-between">
                <div class="mb-4 md:mb-0">
                    <h5 class="text-xl font-bold mb-2 flex items-center">
                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($site_name) ?>" class="h-6 mr-2">
                        <?= htmlspecialchars($site_name) ?>
                    </h5>
                    <p class="text-gray-400"><?= htmlspecialchars($site_tagline) ?></p>
                </div>
                <div class="text-center md:text-right">
                    <p class="text-gray-400 mb-1 flex items-center justify-center md:justify-end">
                        <i class="fas fa-envelope mr-2"></i><?= htmlspecialchars($site_email) ?>
                    </p>
                    <p class="text-gray-500 text-sm">&copy; <?= date('Y') ?> <?= htmlspecialchars($site_name) ?>. Todos los derechos reservados.</p>
                </div>
            </div>
        </div>
    </footer>
