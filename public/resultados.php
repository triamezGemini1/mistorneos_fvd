<?php
/**
 * Página Pública de Resultados de Eventos
 * Listado completo de eventos con paginación
 * - Usuarios no registrados: solo pueden ver resultados generales
 * - Usuarios registrados: pueden acceder a reportes de resumen
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/InscritosPartiresulHelper.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/Pagination.php';

$pdo = DB::pdo();
$base_url = app_base_url();
$user = Auth::user();
$is_logged_in = !empty($user);
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON ((t.club_responsable = o.id OR t.club_responsable = o.cod_org) AND o.estatus = 1)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id AND o.estatus = 1";

// Paginación
$current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 20;

// Obtener total de torneos
$total_torneos = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM tournaments t
        WHERE t.estatus = 1
    ");
    $total_torneos = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error contando torneos: " . $e->getMessage());
}

// Crear objeto de paginación
$pagination = new Pagination($total_torneos, $current_page, $per_page);

// Obtener lista de torneos con paginación
$torneos = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.nombre,
            t.fechator,
            t.lugar,
            t.estatus,
            t.modalidad,
            t.clase,
            o.nombre as organizacion_nombre,
            o.responsable as club_delegado,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus IS NULL OR (estatus != 4 AND estatus != 'retirado'))) as total_inscritos,
            (SELECT COUNT(*) FROM partiresul WHERE id_torneo = t.id AND registrado = 1) as total_partidas,
            (SELECT COUNT(*) FROM club_photos WHERE torneo_id = t.id) as total_fotos
        FROM tournaments t
        {$org_join}
        WHERE t.estatus = 1
        ORDER BY t.fechator DESC
        LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}
    ");
    $stmt->execute();
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo torneos: " . $e->getMessage());
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Abierto', 2 => 'Por Categorías'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a365d">
    
    <!-- SEO Meta Tags -->
    <title>Resultados de Torneos de Dominó - La Estación del Dominó</title>
    <meta name="description" content="Consulta los resultados de todos los torneos de dominó realizados. Clasificaciones, estadísticas y fotos de eventos en Venezuela.">
    <meta name="keywords" content="resultados dominó, torneos dominó venezuela, clasificaciones dominó, estadísticas torneos">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars(AppHelpers::url('resultados.php')) ?>">
    <meta property="og:title" content="Resultados de Torneos - La Estación del Dominó">
    <meta property="og:description" content="Consulta los resultados de todos los torneos de dominó realizados en Venezuela.">
    <meta property="og:image" content="<?= htmlspecialchars(AppHelpers::getAppLogo()) ?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Resultados de Torneos - La Estación del Dominó">
    <meta name="twitter:description" content="Consulta los resultados de todos los torneos de dominó realizados en Venezuela.">
    
    <!-- Canonical -->
    <link rel="canonical" href="<?= htmlspecialchars(AppHelpers::url('resultados.php')) ?>">
    
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
            max-width: 1400px;
        }
        .header {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .torneo-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
            background: white;
        }
        .torneo-card:hover {
            border-color: #1a365d;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .torneo-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        .torneo-info {
            flex: 1;
        }
        .torneo-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-card mx-auto">
            <div class="header">
                <?= AppHelpers::appLogo('mb-3', 'La Estación del Dominó', 48) ?>
                <h2 class="mb-0">Resultados de Eventos</h2>
                <p class="mb-0 mt-2 opacity-75">Consulta los resultados de todos los eventos realizados y en desarrollo</p>
                <p class="mb-0 mt-3">
                    <a href="ranking_atletas.php?genero=F" class="btn btn-sm btn-light text-primary fw-semibold me-1"><i class="fas fa-medal me-1"></i>Ranking atletas</a>
                    <a href="landing-spa.php" class="btn btn-sm btn-outline-light">Inicio</a>
                </p>
            </div>
            
            <div class="p-4">
                <?php if (empty($torneos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle me-2"></i>
                        No hay eventos disponibles en este momento.
                    </div>
                <?php else: ?>
                    <!-- Listado de Torneos -->
                    <div class="mb-4">
                        <?php foreach ($torneos as $torneo): ?>
                            <div class="torneo-card">
                                <div class="torneo-header">
                                    <div class="torneo-info">
                                        <h4 class="mb-2">
                                            <i class="fas fa-trophy text-warning me-2"></i>
                                            <?= htmlspecialchars($torneo['nombre']) ?>
                                        </h4>
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <p class="mb-1">
                                                    <i class="fas fa-calendar text-primary me-2"></i>
                                                    <strong>Fecha:</strong> <?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                                                </p>
                                                <?php if ($torneo['lugar']): ?>
                                                    <p class="mb-1">
                                                        <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                                        <strong>Lugar:</strong> <?= htmlspecialchars($torneo['lugar']) ?>
                                                    </p>
                                                <?php endif; ?>
                                                <p class="mb-1">
                                                    <i class="fas fa-building text-info me-2"></i>
                                                    <strong>Organización:</strong> <?= htmlspecialchars($torneo['organizacion_nombre'] ?? 'N/A') ?>
                                                </p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1">
                                                    <i class="fas fa-users text-success me-2"></i>
                                                    <strong>Modalidad:</strong> <?= $modalidades[$torneo['modalidad']] ?? 'N/A' ?>
                                                </p>
                                                <p class="mb-1">
                                                    <i class="fas fa-tag text-warning me-2"></i>
                                                    <strong>Clase:</strong> <?= $clases[$torneo['clase']] ?? 'N/A' ?>
                                                </p>
                                                <?php if ($torneo['club_delegado']): ?>
                                                    <p class="mb-1">
                                                        <i class="fas fa-user-tie text-secondary me-2"></i>
                                                        <strong>Delegado:</strong> <?= htmlspecialchars($torneo['club_delegado']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-primary badge-custom">
                                                <i class="fas fa-users me-1"></i>
                                                <?= number_format($torneo['total_inscritos']) ?> Inscritos
                                            </span>
                                            <?php if ($torneo['total_partidas'] > 0): ?>
                                                <span class="badge bg-success badge-custom">
                                                    <i class="fas fa-gamepad me-1"></i>
                                                    <?= number_format($torneo['total_partidas']) ?> Partidas
                                                </span>
                                            <?php endif; ?>
                                            <?php if (strtotime($torneo['fechator']) < strtotime('today')): ?>
                                                <span class="badge bg-secondary badge-custom">
                                                    <i class="fas fa-check-circle me-1"></i>Finalizado
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning badge-custom">
                                                    <i class="fas fa-clock me-1"></i>En Desarrollo
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="torneo-actions">
                                        <a href="evento_resultados.php?torneo_id=<?= $torneo['id'] ?>" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-chart-bar me-1"></i>Ver Resultados
                                        </a>
                                        <a href="torneo_detalle.php?torneo_id=<?= $torneo['id'] ?>" 
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-info-circle me-1"></i>Ver Detalles
                                        </a>
                                        <?php if (($torneo['total_fotos'] ?? 0) > 0): ?>
                                            <a href="galeria_fotos.php?torneo_id=<?= $torneo['id'] ?>" 
                                               class="btn btn-info btn-sm">
                                                <i class="fas fa-images me-1"></i>Ver Fotos (<?= $torneo['total_fotos'] ?>)
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($is_logged_in): ?>
                                            <a href="<?= htmlspecialchars($base_url) ?>/public/index.php?page=registrants_report&filter_torneo=<?= $torneo['id'] ?>" 
                                               class="btn btn-success btn-sm">
                                                <i class="fas fa-file-alt me-1"></i>Reportes
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Paginación -->
                    <?= $pagination->render() ?>
                <?php endif; ?>
                
                <!-- Botón Volver -->
                <div class="mt-4 text-center">
                    <a href="<?= htmlspecialchars($base_url) ?>/public/landing.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i>Volver al Inicio
                    </a>
                    <a href="torneos_historico.php" class="btn btn-outline-primary ms-2">
                        <i class="fas fa-history me-2"></i>Torneos Realizados
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
