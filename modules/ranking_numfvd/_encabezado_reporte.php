<?php
/**
 * Encabezado institucional del reporte de ranking (web).
 * Requiere: $org, $subtituloRanking
 */
declare(strict_types=1);

$logoAlt = (string) ($org['nombre'] ?? 'FVD');
$logoHtml = '';
if (class_exists('AppHelpers')) {
    $logoUrl = AppHelpers::getBrandLogoUrl(true);
    $logoHtml = '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($logoAlt) . '" class="rnk-det-org-logo-img" loading="eager" decoding="async">';
}
?>
<div class="rnk-det-org mb-3 shadow-sm">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-stretch gap-3 flex-grow-1 min-w-0">
            <?php if ($logoHtml !== ''): ?>
                <div class="rnk-det-org-logo-wrap flex-shrink-0" aria-hidden="false">
                    <?= $logoHtml ?>
                </div>
            <?php endif; ?>
            <div class="min-w-0 flex-grow-1 d-flex flex-column justify-content-center">
                <h1 class="rnk-det-org-titulo"><?= htmlspecialchars($org['nombre']) ?></h1>
                <div class="rnk-det-org-subtitulo"><?= htmlspecialchars($subtituloRanking) ?></div>
            </div>
        </div>
        <div class="rnk-det-org-fecha flex-shrink-0"><?= date('d/m/Y H:i') ?></div>
    </div>
</div>
