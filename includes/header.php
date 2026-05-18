<?php
/**
 * Cabecera común: estructura HTML superior, metadatos y carga de assets (mistorneos).
 * Favicon y rutas base dinámicos según entorno (/pruebas/public, /mistorneos_beta/public, etc.).
 * Uso: definir $header_title opcional; luego include_once __DIR__ . '/../includes/header.php';
 * No cierra </head> para que la página pueda añadir estilos o meta adicionales.
 */
$header_title = $header_title ?? (class_exists('FvdBranding', false) ? FvdBranding::nombre() : 'FVD');
$header_embedded = !empty($header_embedded);
// Favicon: EXCLUSIVAMENTE PNG (favicon.png ~88ms). No usar favicon.ico (363KB). Innegociable para rendimiento.
$header_asset_base = '';
if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
    $header_asset_base = rtrim(URL_BASE, '/');
} elseif (class_exists('AppHelpers')) {
    $pu = AppHelpers::getPublicUrl();
    $header_asset_base = (strpos($pu, 'http') === 0) ? parse_url($pu, PHP_URL_PATH) : $pu;
}
if ($header_asset_base === null || $header_asset_base === '') {
    if (class_exists('AppHelpers', false)) {
        $header_asset_base = AppHelpers::getPublicWebPath();
    }
    if ($header_asset_base === '') {
        $header_asset_base = class_exists('FvdConfig', false) ? rtrim(FvdConfig::BASE_PATH, '/') : '/mistorneos_fvd/public';
    }
}
$header_asset_base = rtrim((string) $header_asset_base, '/');
// Favicon: ruta absoluta desde la raíz del sitio
$header_favicon_url = $header_asset_base . '/favicon.png';
?>
<?php if (!$header_embedded): ?><head>
<?php endif; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#1a365d">
  <!-- Favicon: solo PNG (favicon.png). Nunca .ico. Ejecutar make_favicon.php para generar. -->
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($header_favicon_url) ?>">
  <title><?= htmlspecialchars($header_title) ?></title>
  <meta name="description" content="mistorneos - La Estación del Dominó. Gestión de torneos, inscripciones y resultados.">
