<?php
/**
 * Página de Detalles de un Torneo
 * Muestra información completa del torneo
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/UrlHelper.php';

$pdo = DB::pdo();
$base_url = app_base_url();
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org) AND o.estatus = 1"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id AND o.estatus = 1";

// Obtener ID del torneo desde GET (soporta URLs amigables y tradicionales)
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

// Si viene un slug en la URL, intentar resolverlo
if ($torneo_id <= 0 && isset($_SERVER['REQUEST_URI'])) {
    // Extraer slug de la URL si está en formato amigable
    if (preg_match('#/torneo/(\d+)/([^/]+)#', $_SERVER['REQUEST_URI'], $matches)) {
        $torneo_id = (int)$matches[1];
    }
}

if ($torneo_id <= 0) {
    header('Location: resultados.php');
    exit;
}

// Obtener datos del torneo (organización o club como responsable)
$torneo_data = null;
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            COALESCE(o.nombre, c.nombre) as organizacion_nombre,
            COALESCE(o.responsable, c.delegado) as organizacion_responsable,
            COALESCE(o.telefono, c.telefono) as organizacion_telefono,
            COALESCE(o.direccion, c.direccion) as organizacion_direccion,
            COALESCE(o.email, c.email) as organizacion_email,
            COALESCE(o.logo, c.logo) as organizacion_logo,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus = 'confirmado' OR estatus IS NULL)) as total_inscritos
        FROM tournaments t
        {$org_join}
        LEFT JOIN clubes c ON t.club_responsable = c.id AND c.estatus = 1
        WHERE t.id = ? AND t.estatus = 1
    ");
    $stmt->execute([$torneo_id]);
    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo_data) {
        header('Location: resultados.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Error obteniendo datos del torneo: " . $e->getMessage());
    header('Location: resultados.php');
    exit;
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Torneo', 2 => 'Campeonato'];

// URLs de archivos adjuntos (afiche, invitación, normas) y del sitio
$public_url = class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim($base_url, '/') . '/public');
$file_base_url = rtrim($public_url, '/') . '/view_tournament_file.php';
$tournament_file_url = function($path) use ($file_base_url) {
    if (empty($path)) return null;
    $file = str_replace('upload/tournaments/', '', $path);
    return $file_base_url . '?file=' . urlencode($file);
};
$afiche_url = $tournament_file_url($torneo_data['afiche'] ?? '');
$invitacion_url = $tournament_file_url($torneo_data['invitacion'] ?? '');
$normas_url = $tournament_file_url($torneo_data['normas'] ?? '');
if (!empty($torneo_data['organizacion_logo'])) {
    $logo_org_url = AppHelpers::imageUrl($torneo_data['organizacion_logo']);
} else {
    $logo_org_url = AppHelpers::getAppLogo();
}
$landing_url = rtrim($public_url, '/') . '/landing.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a365d">
    
    <!-- SEO Meta Tags -->
    <title><?= htmlspecialchars($torneo_data['nombre']) ?> - Torneo de Dominó - La Estación del Dominó</title>
    <meta name="description" content="Información del torneo <?= htmlspecialchars($torneo_data['nombre']) ?>. Fecha: <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?>. Modalidad: <?= $modalidades[$torneo_data['modalidad']] ?? 'N/A' ?>. Organizado por <?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'La Estación del Dominó') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($torneo_data['nombre']) ?>, torneo dominó, dominó venezuela, <?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? '') ?>">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= htmlspecialchars(AppHelpers::url('torneo_detalle.php', ['torneo_id' => $torneo_id])) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($torneo_data['nombre']) ?> - Torneo de Dominó">
    <meta property="og:description" content="Información del torneo de dominó <?= htmlspecialchars($torneo_data['nombre']) ?>. <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?>">
    <meta property="og:image" content="<?= htmlspecialchars(AppHelpers::getAppLogo()) ?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($torneo_data['nombre']) ?>">
    <meta name="twitter:description" content="Torneo de dominó - <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?>">
    
    <!-- Canonical -->
    <link rel="canonical" href="<?= htmlspecialchars(AppHelpers::url('torneo_detalle.php', ['torneo_id' => $torneo_id])) ?>">
    
    <!-- Schema.org Event -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "SportsEvent",
        "name": "<?= htmlspecialchars($torneo_data['nombre']) ?>",
        "startDate": "<?= date('Y-m-d', strtotime($torneo_data['fechator'])) ?>",
        "location": {
            "@type": "Place",
            "name": "<?= htmlspecialchars($torneo_data['lugar'] ?? 'Venezuela') ?>"
        },
        "organizer": {
            "@type": "Organization",
            "name": "<?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'La Estación del Dominó') ?>"
        },
        "sport": "Dominó"
    }
    </script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .main-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 1200px;
        }
        .header {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .info-section {
            background: #f7fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .topbar {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .topbar .logo-org { max-height: 48px; width: auto; object-fit: contain; }
        .archivo-card { transition: transform 0.2s; }
        .archivo-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <!-- Encabezado: esquema web, organización y retorno al landing -->
    <header class="topbar sticky-top">
        <div class="container">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <a href="<?= htmlspecialchars($landing_url) ?>" class="d-flex align-items-center text-white text-decoration-none">
                        <img src="<?= htmlspecialchars($logo_org_url) ?>" alt="<?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'Organizador') ?>" class="logo-org me-2">
                        <div>
                            <strong class="d-block"><?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'Organizador del evento') ?></strong>
                            <small class="opacity-85">Organizador del evento</small>
                        </div>
                    </a>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <?php if ($torneo_data['organizacion_telefono'] ?? ''): ?>
                        <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $torneo_data['organizacion_telefono'])) ?>" class="text-white text-decoration-none small">
                            <i class="fas fa-phone me-1"></i><?= htmlspecialchars($torneo_data['organizacion_telefono']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($torneo_data['organizacion_email'] ?? ''): ?>
                        <a href="mailto:<?= htmlspecialchars($torneo_data['organizacion_email']) ?>" class="text-white text-decoration-none small">
                            <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($torneo_data['organizacion_email']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($torneo_data['organizacion_direccion'] ?? ''): ?>
                        <span class="text-white-50 small d-none d-md-inline">
                            <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($torneo_data['organizacion_direccion']) ?>
                        </span>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-home me-1"></i>Volver al inicio
                    </a>
                </div>
            </div>
            <?php if ($torneo_data['organizacion_responsable'] ?? ''): ?>
                <div class="mt-2 pt-2 border-top border-white-25 small text-white-50">
                    <i class="fas fa-user-tie me-1"></i>Contacto: <?= htmlspecialchars($torneo_data['organizacion_responsable']) ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="container py-4">
        <div class="main-card mx-auto">
            <div class="header">
                <h2 class="mb-0"><?= htmlspecialchars($torneo_data['nombre']) ?></h2>
                <p class="mb-0 mt-2 opacity-75">Información del torneo</p>
            </div>
            
            <div class="p-4">
                <!-- Información General -->
                <div class="info-section">
                    <h4 class="mb-3"><i class="fas fa-info-circle text-primary me-2"></i>Información General</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-calendar me-2"></i>Fecha:</strong> <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></p>
                            <?php if (!empty($torneo_data['lugar'])): ?>
                                <p><strong><i class="fas fa-map-marker-alt me-2"></i>Lugar:</strong> <?= htmlspecialchars($torneo_data['lugar']) ?></p>
                            <?php endif; ?>
                            <p><strong><i class="fas fa-users me-2"></i>Modalidad:</strong> <?= $modalidades[$torneo_data['modalidad']] ?? 'N/A' ?></p>
                            <p><strong><i class="fas fa-tag me-2"></i>Clase:</strong> <?= $clases[$torneo_data['clase']] ?? 'N/A' ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-building me-2"></i>Organización:</strong> <?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'N/A') ?></p>
                            <?php if (!empty($torneo_data['organizacion_responsable'])): ?>
                                <p><strong><i class="fas fa-user-tie me-2"></i>Responsable:</strong> <?= htmlspecialchars($torneo_data['organizacion_responsable']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($torneo_data['organizacion_telefono'])): ?>
                                <p><strong><i class="fas fa-phone me-2"></i>Teléfono:</strong> <?= htmlspecialchars($torneo_data['organizacion_telefono']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($torneo_data['organizacion_direccion'])): ?>
                                <p><strong><i class="fas fa-map-marker-alt me-2"></i>Dirección:</strong> <?= htmlspecialchars($torneo_data['organizacion_direccion']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Archivos del torneo: afiche, invitación, condiciones -->
                <?php if ($afiche_url || $invitacion_url || $normas_url): ?>
                <div class="info-section">
                    <h4 class="mb-3"><i class="fas fa-file-download text-info me-2"></i>Archivos del torneo</h4>
                    <div class="row g-3">
                        <?php if ($afiche_url): ?>
                        <div class="col-md-4">
                            <div class="card archivo-card h-100 border-primary">
                                <div class="card-body text-center">
                                    <i class="fas fa-image text-primary fa-3x mb-2"></i>
                                    <h6 class="card-title">Afiche</h6>
                                    <a href="<?= htmlspecialchars($afiche_url) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye me-1"></i>Ver / Descargar
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($invitacion_url): ?>
                        <div class="col-md-4">
                            <div class="card archivo-card h-100 border-secondary">
                                <div class="card-body text-center">
                                    <i class="fas fa-envelope text-secondary fa-3x mb-2"></i>
                                    <h6 class="card-title">Invitación oficial</h6>
                                    <a href="<?= htmlspecialchars($invitacion_url) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-eye me-1"></i>Ver / Descargar
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($normas_url): ?>
                        <div class="col-md-4">
                            <div class="card archivo-card h-100 border-success">
                                <div class="card-body text-center">
                                    <i class="fas fa-file-alt text-success fa-3x mb-2"></i>
                                    <h6 class="card-title">Normas / Condiciones</h6>
                                    <a href="<?= htmlspecialchars($normas_url) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-eye me-1"></i>Ver / Descargar
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Estadísticas -->
                <div class="info-section">
                    <h4 class="mb-3"><i class="fas fa-chart-bar text-success me-2"></i>Estadísticas</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center">
                                <h3 class="text-primary"><?= number_format($torneo_data['total_inscritos']) ?></h3>
                                <p class="mb-0">Total Inscritos</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <h3 class="text-info"><?= $torneo_data['rondas'] ?? 'N/A' ?></h3>
                                <p class="mb-0">Rondas</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <h3 class="text-warning"><?= $torneo_data['costo'] ?? '0' ?> Bs.</h3>
                                <p class="mb-0">Costo de Inscripción</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Botones de Navegación -->
                <div class="mt-4 text-center">
                    <?php 
                    $resultados_url = UrlHelper::resultadosUrl($torneo_id, $torneo_data['nombre']);
                    ?>
                    <a href="<?= htmlspecialchars($resultados_url) ?>" class="btn btn-primary">
                        <i class="fas fa-chart-bar me-2"></i>Ver Resultados
                    </a>
                    <a href="resultados.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Volver a Listado
                    </a>
                    <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-primary">
                        <i class="fas fa-home me-2"></i>Volver al inicio
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>




