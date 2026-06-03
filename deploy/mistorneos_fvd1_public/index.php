<?php

declare(strict_types=1);

/**
 * Hub de entrada monorepo — desplegar como:
 *   public_html/mistorneos_fvd1/public/index.php
 *
 * Redirige peticiones de torneos (page=torneo_gestion, etc.) a la app anidada:
 *   /mistorneos_fvd1/mistorneos_fvd/public/index.php
 */

$torneosPublic = '/mistorneos_fvd1/mistorneos_fvd/public';
$hubPublic = '/mistorneos_fvd1/public';

if (is_file(__DIR__ . '/../../mistorneos_fvd/config/bootstrap.php')) {
    require_once __DIR__ . '/../../mistorneos_fvd/config/bootstrap.php';
    require_once __DIR__ . '/../../mistorneos_fvd/lib/IntegralUrl.php';
    IntegralUrl::redirectHubTorneosRequestsIfNeeded();
    $torneosPublic = IntegralUrl::torneosPublicWebPath();
    $hubPublic = IntegralUrl::hubPublicWebPath();
}

$page = trim((string) ($_GET['page'] ?? ''));

$torneosPages = [
    'torneo_gestion', 'tournament_admin', 'registrants', 'tournaments',
    'invitations', 'notificaciones_masivas', 'finances', 'users', 'clubs',
    'control_admin', 'asociacion_panel', 'estadisticas_torneos',
];

$isTorneosPage = $page !== '' && (
    in_array($page, $torneosPages, true)
    || str_starts_with($page, 'torneo_')
    || str_starts_with($page, 'gestion_')
);

if ($isTorneosPage) {
    $qs = $_GET;
    header('Location: ' . rtrim($torneosPublic, '/') . '/index.php?' . http_build_query($qs), true, 302);
    exit;
}

// Entrada hub por defecto → dashboard torneos (app anidada)
if ($page === '' || $page === 'home') {
    header('Location: ' . rtrim($torneosPublic, '/') . '/index.php?page=home', true, 302);
    exit;
}

// Fallback: delegar al index completo de la app si existe copia local
$nestedIndex = dirname(__DIR__) . '/mistorneos_fvd/public/index.php';
if (is_file($nestedIndex)) {
    require $nestedIndex;
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
http_response_code(503);
echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Configuración</title></head><body>';
echo '<h1>Hub FVD</h1><p>Instale la app en <code>mistorneos_fvd1/mistorneos_fvd/</code>.</p>';
echo '<p><a href="' . htmlspecialchars(rtrim($torneosPublic, '/') . '/index.php?page=home') . '">Ir al panel</a></p>';
echo '</body></html>';
