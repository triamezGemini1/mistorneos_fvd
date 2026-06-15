<?php
/**
 * Página pública de detalle de torneo: información, anexos y galería.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/UrlHelper.php';
require_once __DIR__ . '/../lib/TorneoDetallePublicoService.php';

$pdo = DB::pdo();
$publicBase = rtrim(AppHelpers::getPublicUrl(), '/') . '/';
$torneo_id = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;

if ($torneo_id <= 0 && isset($_SERVER['REQUEST_URI']) && preg_match('#/torneo/(\d+)/#', (string) $_SERVER['REQUEST_URI'], $m)) {
    $torneo_id = (int) $m[1];
}

if ($torneo_id <= 0) {
    header('Location: ' . $publicBase . 'landing-spa.php#eventos');
    exit;
}

$ctx = TorneoDetallePublicoService::cargar($pdo, $torneo_id, $publicBase);
if ($ctx === null) {
    header('Location: ' . $publicBase . 'landing-spa.php#eventos');
    exit;
}

$torneo = $ctx['torneo'];
$org = $ctx['organizacion'];
$fotos = $ctx['fotos'];
$archivos = $ctx['archivos'];
$esPasado = (bool) $ctx['es_pasado'];
$landingUrl = (string) $ctx['landing_url'];
$galeriaUrl = (string) $ctx['galeria_url'];
$portadaUrl = (string) ($ctx['portada_url'] ?? '');

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
$clases = [1 => 'Torneo', 2 => 'Campeonato'];
$nombreTorneo = (string) ($torneo['nombre'] ?? 'Torneo');
$orgNombre = (string) ($org['nombre'] ?? 'Organizador');
$fechaFmt = !empty($torneo['fechator']) ? date('d/m/Y', strtotime((string) $torneo['fechator'])) : '';
$resultadosUrl = UrlHelper::resultadosUrl($torneo_id, $nombreTorneo);
$ogImage = $portadaUrl !== '' ? $portadaUrl : ($org['logo_url'] ?? AppHelpers::getAppLogo());
$canonical = AppHelpers::url('torneo_detalle.php', ['torneo_id' => $torneo_id]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title><?= htmlspecialchars($nombreTorneo) ?> — <?= htmlspecialchars($orgNombre) ?></title>
    <meta name="description" content="Información del torneo <?= htmlspecialchars($nombreTorneo) ?>. Organiza <?= htmlspecialchars($orgNombre) ?>. Fecha: <?= htmlspecialchars($fechaFmt) ?>">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= htmlspecialchars($nombreTorneo) ?>">
    <meta property="og:description" content="Torneo de dominó — <?= htmlspecialchars($fechaFmt) ?> — <?= htmlspecialchars($orgNombre) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/fvd-landing-shell.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; }
        .td-topbar { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 55%, #0f766e 100%); color: #fff; }
        .td-topbar .logo-org { max-height: 52px; width: auto; object-fit: contain; background: #fff; border-radius: 8px; padding: 4px; }
        .td-hero { min-height: 220px; background: linear-gradient(135deg, #1e3a8a 0%, #334155 100%); color: #fff; position: relative; overflow: hidden; }
        .td-hero.has-cover { background-size: cover; background-position: center; }
        .td-hero-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(15,23,42,.55) 0%, rgba(15,23,42,.88) 100%); }
        .td-card { border: 0; border-radius: 1rem; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
        .td-gallery img { width: 100%; height: 200px; object-fit: cover; border-radius: .75rem; cursor: pointer; transition: transform .2s; }
        .td-gallery img:hover { transform: scale(1.02); }
        .td-archivo { border-radius: .75rem; transition: transform .2s; }
        .td-archivo:hover { transform: translateY(-2px); }
        #lightbox { display: none; position: fixed; inset: 0; z-index: 1080; background: rgba(0,0,0,.92); align-items: center; justify-content: center; padding: 1rem; }
        #lightbox.open { display: flex; }
        #lightbox img { max-width: 100%; max-height: 90vh; border-radius: .5rem; }
    </style>
</head>
<body>
<header class="td-topbar py-3 sticky-top shadow-sm">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <a href="<?= htmlspecialchars($landingUrl) ?>" class="d-flex align-items-center text-white text-decoration-none gap-3">
                <img src="<?= htmlspecialchars((string) $org['logo_url']) ?>" alt="<?= htmlspecialchars($orgNombre) ?>" class="logo-org">
                <div>
                    <strong class="d-block"><?= htmlspecialchars($orgNombre) ?></strong>
                    <small class="opacity-85">Organizador del evento</small>
                </div>
            </a>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($org['telefono'] !== ''): ?>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $org['telefono'])) ?>" class="btn btn-sm btn-outline-light"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($org['telefono']) ?></a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($landingUrl) ?>" class="btn btn-sm btn-light"><i class="fas fa-home me-1"></i>Inicio</a>
            </div>
        </div>
    </div>
</header>

<section class="td-hero <?= $portadaUrl !== '' ? 'has-cover' : '' ?>" <?= $portadaUrl !== '' ? 'style="background-image:url(' . htmlspecialchars($portadaUrl, ENT_QUOTES) . ')"' : '' ?>>
    <div class="td-hero-overlay"></div>
    <div class="container position-relative py-5">
        <div class="row align-items-end">
            <div class="col-lg-9">
                <span class="badge bg-warning text-dark mb-3"><i class="fas fa-calendar me-1"></i><?= htmlspecialchars($fechaFmt) ?></span>
                <h1 class="display-6 fw-bold mb-2"><?= htmlspecialchars($nombreTorneo) ?></h1>
                <p class="mb-0 opacity-90"><i class="fas fa-map-marker-alt me-2"></i><?= htmlspecialchars((string) ($torneo['lugar'] ?? 'Lugar por confirmar')) ?></p>
            </div>
        </div>
    </div>
</section>

<main class="container py-4 py-lg-5">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card td-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3"><i class="fas fa-info-circle text-primary me-2"></i>Información del torneo</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Modalidad:</strong> <?= htmlspecialchars($modalidades[(int) ($torneo['modalidad'] ?? 1)] ?? 'Individual') ?></p>
                            <p class="mb-2"><strong>Clase:</strong> <?= htmlspecialchars($clases[(int) ($torneo['clase'] ?? 1)] ?? 'Torneo') ?></p>
                            <p class="mb-0"><strong>Rondas:</strong> <?= htmlspecialchars((string) ($torneo['rondas'] ?? '—')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Inscritos:</strong> <?= number_format((int) ($torneo['total_inscritos'] ?? 0)) ?></p>
                            <?php if ((float) ($torneo['costo'] ?? 0) > 0): ?>
                                <p class="mb-2"><strong>Costo:</strong> <?= htmlspecialchars((string) $torneo['costo']) ?> Bs.</p>
                            <?php endif; ?>
                            <?php if ($org['responsable'] !== ''): ?>
                                <p class="mb-0"><strong>Contacto:</strong> <?= htmlspecialchars($org['responsable']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($torneo['observaciones'])): ?>
                        <hr>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars((string) $torneo['observaciones'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($archivos !== []): ?>
            <div class="card td-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3"><i class="fas fa-paperclip text-info me-2"></i>Documentos y anexos</h2>
                    <div class="row g-3">
                        <?php foreach ($archivos as $arch): ?>
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 h-100 text-center td-archivo bg-light">
                                <i class="fas <?= htmlspecialchars((string) $arch['icon']) ?> fa-2x text-primary mb-2"></i>
                                <h3 class="h6"><?= htmlspecialchars((string) $arch['titulo']) ?></h3>
                                <a href="<?= htmlspecialchars((string) $arch['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Ver / Descargar</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($fotos !== []): ?>
            <div class="card td-card mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h4 mb-0"><i class="fas fa-images text-success me-2"></i>Galería (<?= count($fotos) ?>)</h2>
                        <a href="<?= htmlspecialchars($galeriaUrl) ?>" class="btn btn-sm btn-outline-secondary">Ver galería completa</a>
                    </div>
                    <div class="row g-3 td-gallery">
                        <?php foreach ($fotos as $foto): ?>
                        <div class="col-6 col-md-4">
                            <img src="<?= htmlspecialchars((string) ($foto['url'] ?? '')) ?>"
                                 alt="<?= htmlspecialchars((string) ($foto['titulo'] ?? 'Foto del torneo')) ?>"
                                 loading="lazy"
                                 data-lightbox="<?= htmlspecialchars((string) ($foto['url'] ?? '')) ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card td-card sticky-lg-top" style="top: 5.5rem;">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3"><?= htmlspecialchars($orgNombre) ?></h2>
                    <?php if ($org['direccion'] !== ''): ?>
                        <p class="small mb-2"><i class="fas fa-map-marker-alt me-2 text-muted"></i><?= htmlspecialchars($org['direccion']) ?></p>
                    <?php endif; ?>
                    <?php if ($org['email'] !== ''): ?>
                        <p class="small mb-2"><i class="fas fa-envelope me-2 text-muted"></i><a href="mailto:<?= htmlspecialchars($org['email']) ?>"><?= htmlspecialchars($org['email']) ?></a></p>
                    <?php endif; ?>
                    <hr>
                    <div class="d-grid gap-2">
                        <?php if ($esPasado): ?>
                            <a href="<?= htmlspecialchars($resultadosUrl) ?>" class="btn btn-success"><i class="fas fa-trophy me-2"></i>Ver resultados</a>
                        <?php endif; ?>
                        <?php if (!$esPasado && (int) ($torneo['permite_inscripcion_linea'] ?? 1) === 1): ?>
                            <a href="<?= htmlspecialchars($publicBase) ?>tournament_register.php?torneo_id=<?= $torneo_id ?>" class="btn btn-warning text-dark fw-semibold"><i class="fas fa-user-plus me-2"></i>Inscribirme</a>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($landingUrl) ?>#eventos" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2"></i>Volver al landing</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<div id="lightbox" role="dialog" aria-label="Vista ampliada">
    <button type="button" class="btn btn-light position-absolute top-0 end-0 m-3" id="lightbox-close"><i class="fas fa-times"></i></button>
    <img src="" alt="" id="lightbox-img">
</div>

<script>
document.querySelectorAll('[data-lightbox]').forEach(img => {
    img.addEventListener('click', () => {
        const lb = document.getElementById('lightbox');
        const target = document.getElementById('lightbox-img');
        target.src = img.getAttribute('data-lightbox') || img.src;
        lb.classList.add('open');
    });
});
document.getElementById('lightbox-close')?.addEventListener('click', () => document.getElementById('lightbox').classList.remove('open'));
document.getElementById('lightbox')?.addEventListener('click', e => { if (e.target.id === 'lightbox') e.currentTarget.classList.remove('open'); });
</script>
</body>
</html>
