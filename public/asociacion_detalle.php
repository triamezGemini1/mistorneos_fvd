<?php
/**
 * Ficha pública de una asociación activa.
 * URL: asociacion_detalle.php?id={club_id}
 */

header('Cache-Control: public, max-age=120, stale-while-revalidate=300');

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/AsociacionesActivasLandingService.php';
if (!class_exists('FvdBranding', false) && is_file(__DIR__ . '/../lib/FvdBranding.php')) {
    require_once __DIR__ . '/../lib/FvdBranding.php';
}

$clubId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$asociacionesUrl = AsociacionesActivasLandingService::landingAsociacionesUrl();
$landingUrl = rtrim(AppHelpers::url('landing-spa.php'), '/');
$fvdNombre = class_exists('FvdBranding', false) ? FvdBranding::nombre() : 'Federación Venezolana de Dominó';
$fvdLogoUrl = class_exists('FvdBranding', false) ? FvdBranding::logoHref() : AppHelpers::getAppLogoHref();

if ($clubId <= 0) {
    header('Location: ' . $asociacionesUrl);
    exit;
}

$asoc = (new AsociacionesActivasLandingService(DB::pdo()))->obtenerDetallePublico($clubId);

if ($asoc === null) {
    header('Location: ' . $asociacionesUrl);
    exit;
}

$asociacionesUrl = (string) ($asoc['asociaciones_url'] ?? $asociacionesUrl);
$nombre = (string) ($asoc['nombre'] ?? 'Asociación');
$representante = (string) ($asoc['representante'] ?? '');
$telefono = (string) ($asoc['telefono'] ?? '');
$email = (string) ($asoc['email'] ?? '');
$direccion = (string) ($asoc['direccion'] ?? '');
$logoUrl = $asoc['logo_url'] ?? null;
$telHref = preg_replace('/\D+/', '', $telefono);

$totalAfiliados = (int) ($asoc['total_afiliados'] ?? 0);
$hombres = (int) ($asoc['hombres'] ?? 0);
$mujeres = (int) ($asoc['mujeres'] ?? 0);
$activos = (int) ($asoc['afiliados_activos'] ?? 0);
$inactivos = (int) ($asoc['afiliados_inactivos'] ?? 0);

$adminFields = [
    ['label' => 'Delegado / representante', 'value' => $representante, 'icon' => 'fa-user-tie', 'href' => ''],
    ['label' => 'Teléfono', 'value' => $telefono, 'icon' => 'fa-phone', 'href' => $telHref !== '' ? 'tel:' . $telHref : ''],
    ['label' => 'Correo electrónico', 'value' => $email, 'icon' => 'fa-envelope', 'href' => $email !== '' ? 'mailto:' . $email : ''],
    ['label' => 'Dirección', 'value' => $direccion, 'icon' => 'fa-map-marker-alt', 'href' => ''],
];

$fmt = static function (int $n): string {
    return number_format($n, 0, ',', '.');
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#338CFF">
    <title><?= htmlspecialchars($nombre) ?> · Asociaciones FVD</title>
    <link href="<?= htmlspecialchars(AppHelpers::publicAssetUrl('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(AppHelpers::publicAssetUrl('vendor/fontawesome/css/all.min.css')) ?>" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/analytics-tracker.php'; ?>
    <style>
        :root {
            --fvd-container: #338CFF;
            --fvd-content: #00C0FA;
            --fvd-badge-stat: #5196DB;
            --fvd-badge-admin: #19385E;
            --fvd-org-header: #173B7E;
        }
        body {
            min-height: 100vh;
            margin: 0;
            background: var(--fvd-container);
            color: #fff;
            font-weight: 700;
        }
        .fvd-font-title {
            font-family: 'Montserrat', system-ui, sans-serif;
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .fvd-asoc-viewport {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 0.75rem 0 1.5rem;
            box-sizing: border-box;
        }
        .fvd-asoc-shell {
            width: 50vw;
            max-width: 50vw;
            min-width: 280px;
            box-sizing: border-box;
        }
        .fvd-org-header {
            background: rgba(23, 59, 126, 0.98);
            border: 1px solid rgba(251, 191, 36, 0.35);
            border-radius: 0.85rem 0.85rem 0 0;
            padding: 0.75rem 1rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }
        .fvd-org-header__brand {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            min-width: 0;
        }
        .fvd-org-header__brand:hover { color: #fde68a; }
        .fvd-org-header__logo {
            height: 2.5rem;
            width: auto;
            max-width: 5.5rem;
            object-fit: contain;
            flex-shrink: 0;
        }
        .fvd-org-header__name {
            font-size: clamp(0.65rem, 1.2vw, 0.82rem);
            line-height: 1.2;
            font-weight: 800;
        }
        .fvd-org-header__tag {
            font-size: clamp(0.6rem, 1vw, 0.72rem);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.25rem 0.55rem;
            border-radius: 9999px;
            background: rgba(251, 191, 36, 0.2);
            border: 1px solid rgba(251, 191, 36, 0.45);
            color: #fde68a;
            white-space: nowrap;
        }
        .fvd-asoc-page {
            width: 100%;
            padding: 0.65rem 0 0;
            box-sizing: border-box;
        }
        .fvd-asoc-page a.fvd-link-nav {
            color: #fff;
            font-weight: 700;
            text-decoration: none;
        }
        .fvd-asoc-page a.fvd-link-nav:hover { text-decoration: underline; }
        .fvd-asoc-card {
            background: var(--fvd-container);
            border-radius: 0 0 1rem 1rem;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
            width: 100%;
        }
        .fvd-asoc-hero {
            padding: 1.25rem 1rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            color: #fff;
            font-weight: 700;
        }
        .fvd-asoc-logo {
            width: 4.5rem;
            height: 4.5rem;
            border-radius: 0.85rem;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.4rem;
            flex-shrink: 0;
        }
        .fvd-asoc-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .fvd-asoc-logo i { font-size: 1.85rem; color: var(--fvd-container); }
        .fvd-asoc-hero__kicker {
            font-size: clamp(0.7rem, 1.5vw, 0.85rem);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.2rem;
        }
        .fvd-asoc-hero__title {
            font-size: clamp(1.15rem, 3vw, 1.65rem);
            margin: 0;
            font-weight: 700;
            color: #fff;
        }
        .fvd-asoc-body {
            background: var(--fvd-content);
            padding: 1rem;
            width: 100%;
            box-sizing: border-box;
        }
        @media (min-width: 768px) {
            .fvd-asoc-body { padding: 1.25rem 1rem; }
        }
        .fvd-asoc-columns {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            width: 100%;
        }
        @media (min-width: 768px) {
            .fvd-asoc-columns {
                flex-direction: row;
                align-items: flex-start;
                gap: 2%;
            }
            .fvd-asoc-col--admin { flex: 0 0 75%; max-width: 75%; width: 75%; }
            .fvd-asoc-col--stats { flex: 0 0 23%; max-width: 23%; width: 23%; }
        }
        .fvd-asoc-col-title {
            font-size: clamp(0.9rem, 2vw, 1.05rem);
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.75rem;
        }
        .fvd-asoc-badge {
            font-weight: 700;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            margin-bottom: 0.65rem;
        }
        .fvd-asoc-badge:last-child { margin-bottom: 0; }
        .fvd-asoc-badge--stat {
            background: var(--fvd-badge-stat);
            color: #fff;
            text-align: center;
            padding: 0.75rem 0.5rem;
        }
        .fvd-asoc-badge--stat .fvd-asoc-badge__num,
        .fvd-asoc-badge--stat .fvd-asoc-badge__label {
            color: #fff;
        }
        .fvd-asoc-badge--stat .fvd-asoc-badge__num {
            display: block;
            font-size: clamp(1.35rem, 3vw, 2rem);
            line-height: 1.1;
            font-weight: 700;
        }
        .fvd-asoc-badge--total .fvd-asoc-badge__num {
            font-size: clamp(1.75rem, 4vw, 2.5rem);
        }
        .fvd-asoc-badge--stat .fvd-asoc-badge__label {
            display: block;
            margin-top: 0.25rem;
            margin-bottom: 0;
            font-size: clamp(0.72rem, 1.6vw, 0.85rem);
            font-weight: 700;
        }
        .fvd-asoc-admin-grid .fvd-asoc-badge {
            background: var(--fvd-badge-admin);
            color: #fff;
            margin-bottom: 0;
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            min-height: 4.5rem;
        }
        .fvd-asoc-admin-grid .fvd-asoc-badge a {
            color: #fff;
            font-weight: 700;
            text-decoration: underline;
        }
        .fvd-asoc-badge__label {
            display: block;
            font-size: clamp(0.65rem, 1.4vw, 0.75rem);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.88);
            margin-bottom: 0.2rem;
        }
        .fvd-asoc-badge__value {
            display: block;
            font-size: clamp(0.88rem, 2vw, 1.05rem);
            font-weight: 700;
            color: #fff;
            word-break: break-word;
        }
        .fvd-asoc-admin-grid .fvd-asoc-badge__icon {
            flex-shrink: 0;
            font-size: 1.1rem;
            color: #fff;
            margin-top: 0.1rem;
        }
        .fvd-asoc-admin-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.65rem;
        }
        .fvd-asoc-back-btn {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.6rem 1.25rem;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            font-weight: 700;
            font-size: clamp(0.85rem, 2vw, 0.95rem);
            border: 2px solid rgba(255, 255, 255, 0.45);
            text-decoration: none;
        }
        .fvd-asoc-back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: #fff;
        }
    </style>
</head>
<body>
<div class="fvd-asoc-viewport">
<div class="fvd-asoc-shell">
    <header class="fvd-org-header">
        <a href="<?= htmlspecialchars($landingUrl) ?>" class="fvd-org-header__brand fvd-font-title" title="<?= htmlspecialchars($fvdNombre) ?>">
            <?php if ($fvdLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($fvdLogoUrl) ?>" alt="<?= htmlspecialchars($fvdNombre) ?>" class="fvd-org-header__logo" width="88" height="40" loading="eager" decoding="async">
            <?php else: ?>
                <i class="fas fa-certificate fa-lg" style="color:#fde68a"></i>
            <?php endif; ?>
            <span class="fvd-org-header__name"><?= htmlspecialchars($fvdNombre) ?></span>
        </a>
        <span class="fvd-org-header__tag">Asociaciones FVD</span>
    </header>

<div class="fvd-asoc-page">
    <p class="mb-2">
        <a href="<?= htmlspecialchars($asociacionesUrl) ?>" class="fvd-link-nav">
            <i class="fas fa-arrow-left me-1"></i>Volver a Asociaciones
        </a>
    </p>

    <article class="fvd-asoc-card">
        <header class="fvd-asoc-hero">
            <div class="fvd-asoc-logo" aria-hidden="true">
                <?php if ($logoUrl): ?>
                    <img src="<?= htmlspecialchars((string) $logoUrl) ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-shield-alt"></i>
                <?php endif; ?>
            </div>
            <div class="flex-grow-1">
                <p class="fvd-asoc-hero__kicker mb-0">Asociación activa · FVD</p>
                <h1 class="fvd-asoc-hero__title"><?= htmlspecialchars($nombre) ?></h1>
            </div>
        </header>

        <div class="fvd-asoc-body">
            <div class="fvd-asoc-columns">
                <div class="fvd-asoc-col fvd-asoc-col--admin">
                    <h2 class="fvd-asoc-col-title"><i class="fas fa-id-card me-2"></i>Datos administrativos</h2>
                    <div class="fvd-asoc-admin-grid">
                        <?php foreach ($adminFields as $field): ?>
                        <div class="fvd-asoc-badge">
                            <span class="fvd-asoc-badge__icon" aria-hidden="true">
                                <i class="fas <?= htmlspecialchars($field['icon']) ?>"></i>
                            </span>
                            <div>
                                <span class="fvd-asoc-badge__label"><?= htmlspecialchars($field['label']) ?></span>
                                <span class="fvd-asoc-badge__value">
                                    <?php if (($field['value'] ?? '') !== '' && ($field['href'] ?? '') !== ''): ?>
                                        <a href="<?= htmlspecialchars($field['href']) ?>"><?= htmlspecialchars($field['value']) ?></a>
                                    <?php elseif (($field['value'] ?? '') !== ''): ?>
                                        <?= htmlspecialchars($field['value']) ?>
                                    <?php else: ?>
                                        No registrado
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fvd-asoc-col fvd-asoc-col--stats">
                    <h2 class="fvd-asoc-col-title"><i class="fas fa-chart-bar me-2"></i>Estadísticas</h2>

                    <div class="fvd-asoc-badge fvd-asoc-badge--stat fvd-asoc-badge--total">
                        <span class="fvd-asoc-badge__num"><?= $fmt($totalAfiliados) ?></span>
                        <span class="fvd-asoc-badge__label">Total afiliados</span>
                    </div>
                    <div class="fvd-asoc-badge fvd-asoc-badge--stat">
                        <span class="fvd-asoc-badge__num"><?= $fmt($hombres) ?></span>
                        <span class="fvd-asoc-badge__label"><i class="fas fa-mars me-1"></i>Hombres</span>
                    </div>
                    <div class="fvd-asoc-badge fvd-asoc-badge--stat">
                        <span class="fvd-asoc-badge__num"><?= $fmt($mujeres) ?></span>
                        <span class="fvd-asoc-badge__label"><i class="fas fa-venus me-1"></i>Mujeres</span>
                    </div>
                    <div class="fvd-asoc-badge fvd-asoc-badge--stat">
                        <span class="fvd-asoc-badge__num"><?= $fmt($activos) ?></span>
                        <span class="fvd-asoc-badge__label"><i class="fas fa-user-check me-1"></i>Activos</span>
                    </div>
                    <div class="fvd-asoc-badge fvd-asoc-badge--stat">
                        <span class="fvd-asoc-badge__num"><?= $fmt($inactivos) ?></span>
                        <span class="fvd-asoc-badge__label"><i class="fas fa-user-clock me-1"></i>Inactivos</span>
                    </div>
                </div>
            </div>
        </div>
    </article>

    <div class="text-center">
        <a href="<?= htmlspecialchars($asociacionesUrl) ?>" class="fvd-asoc-back-btn">
            <i class="fas fa-shield-alt me-2"></i>Volver a Asociaciones
        </a>
    </div>
</div>
</div>
</div>
</body>
</html>
