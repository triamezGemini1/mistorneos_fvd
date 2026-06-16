<?php
/**
 * Barra superior del área de contenido: búsqueda global (no en header).
 * Requiere variables del layout: $layout_show_page_search, $layout_toolbar_mi_org (opcional).
 */
if (empty($layout_show_page_search)) {
    return;
}
$toolbar_mi_org = (isset($layout_toolbar_mi_org) && is_array($layout_toolbar_mi_org)) ? $layout_toolbar_mi_org : null;
?>
<div class="fvd-page-toolbar-row<?= $toolbar_mi_org ? ' fvd-page-toolbar-row--with-logo' : '' ?>">
    <div class="fvd-page-toolbar-search search-box">
        <label for="topbarSearchInput" class="form-label fvd-page-toolbar-search__label mb-1">Buscar</label>
        <div class="input-group shadow-sm">
            <span class="input-group-text bg-light border-end-0">
                <i class="fas fa-search text-muted" aria-hidden="true"></i>
            </span>
            <input type="text"
                   class="form-control border-start-0 app-search-blur-input"
                   placeholder="Personas, clubes, torneos… (mín. 3 caracteres; al salir del campo)"
                   id="topbarSearchInput"
                   minlength="3"
                   autocomplete="off">
        </div>
    </div>
    <?php if ($toolbar_mi_org): ?>
    <div class="fvd-page-toolbar-logo" title="<?= htmlspecialchars((string) ($toolbar_mi_org['nombre'] ?? '')) ?>">
        <?php if (!empty($toolbar_mi_org['url'])): ?>
            <img src="<?= htmlspecialchars((string) $toolbar_mi_org['url']) ?>"
                 alt="<?= htmlspecialchars((string) ($toolbar_mi_org['nombre'] ?? 'Asociación')) ?>"
                 class="fvd-mi-org-logo fvd-mi-org-logo--toolbar">
        <?php else: ?>
            <div class="fvd-mi-org-logo fvd-mi-org-logo--toolbar fvd-mi-org-logo-placeholder" aria-hidden="true">
                <?= htmlspecialchars((string) ($toolbar_mi_org['inicial'] ?? '?')) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
