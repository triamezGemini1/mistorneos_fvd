<?php
/**
 * Configuración de la Landing Page - Variables centralizadas
 * Incluir antes de los componentes para tenerlas disponibles
 */

if (!class_exists('FvdConfig', false)) {
    require_once dirname(__DIR__) . '/lib/FvdConfig.php';
}
if (!class_exists('FvdBranding', false)) {
    require_once dirname(__DIR__) . '/lib/FvdBranding.php';
}

$fvdNombre = FvdBranding::nombre();
$fvdSiglas = FvdBranding::siglas();

$SITE_NAME = $fvdNombre;
$SITE_TAGLINE = 'Sistema integral para la gestión de torneos de dominó — ' . $fvdSiglas;
$META_TITLE = $fvdNombre . ' - Sistema de Gestión de Torneos de Dominó en Venezuela';
$META_DESCRIPTION = 'Plataforma oficial de ' . $fvdSiglas . ' para la gestión de torneos de dominó en Venezuela. Participa en eventos, consulta resultados, inscríbete en torneos y únete a la comunidad.';
$META_KEYWORDS = 'dominó, torneos dominó, dominó venezuela, FVD, federación dominó, campeonatos, clubes dominó, resultados dominó, inscripciones torneos';
$META_AUTHOR = $fvdNombre;
$META_OG_TITLE = $fvdNombre . ' - Sistema de Gestión de Torneos';
$META_OG_DESCRIPTION = 'Plataforma oficial de ' . $fvdSiglas . ' para torneos de dominó en Venezuela.';
$SITE_EMAIL = 'info@fvd.com.ve';
$SITE_URL = class_exists('AppHelpers', false)
    ? AppHelpers::url('landing-spa.php')
    : (rtrim(app_base_url(), '/') . '/public/landing-spa.php');
$OG_IMAGE = class_exists('AppHelpers') ? AppHelpers::getAppLogo() : FvdBranding::logoUrl();
