<?php
/**
 * Componente de Analítica Web - Umami Producción
 * Ubicación: mistorneos_fvd / public / includes / analytics-tracker.php
 */

require_once __DIR__ . '/../../lib/UmamiAnalyticsHelper.php';

if (!UmamiAnalyticsHelper::shouldTrack()) {
    return;
}
?>
    <script
        defer
        src="<?= htmlspecialchars(UmamiAnalyticsHelper::scriptUrl(), ENT_QUOTES, 'UTF-8') ?>"
        data-website-id="<?= htmlspecialchars(UmamiAnalyticsHelper::websiteId(), ENT_QUOTES, 'UTF-8') ?>"
    ></script>
