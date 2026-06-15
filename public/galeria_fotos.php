<?php
/**
 * Galería Pública de Fotos de Torneos
 * Accesible sin login, con filtros por club y torneo
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/TournamentPhotoService.php';

function getImageUrl($ruta_imagen) {
    return TournamentPhotoService::publicUrl($ruta_imagen);
}

$pdo = DB::pdo();
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org) AND o.estatus = 1"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id AND o.estatus = 1";

// Obtener filtros (club_id/organizacion_id: club_responsable almacena ID de organización)
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

// Lista de organizaciones para el filtro (club_responsable = org.id)
$organizaciones_filtro = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM organizaciones WHERE estatus = 1 ORDER BY nombre ASC");
    $organizaciones_filtro = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo organizaciones: " . $e->getMessage());
}

// Obtener lista de torneos para el filtro
$torneos = [];
try {
    $query = "SELECT t.id, t.nombre, t.fechator, o.nombre as club_nombre 
              FROM tournaments t 
              {$org_join}
              WHERE t.estatus = 1";
    
    if ($club_id > 0) {
        $query .= " AND t.club_responsable = ?";
    }
    
    $query .= " ORDER BY t.fechator DESC, t.nombre ASC";
    
    $stmt = $pdo->prepare($query);
    if ($club_id > 0) {
        $stmt->execute([$club_id]);
    } else {
        $stmt->execute();
    }
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo torneos: " . $e->getMessage());
}

// Obtener fotos según filtros
$fotos = [];
$totalFotos = 0;
$tabla_existe = false;

try {
    $stmt_check = $pdo->query("SHOW TABLES LIKE 'club_photos'");
    $tabla_existe = $stmt_check->rowCount() > 0;
    
    if ($tabla_existe) {
        $query = "
            SELECT 
                tp.*,
                t.nombre as torneo_nombre,
                t.fechator,
                o.nombre as club_nombre,
                o.id as club_id
            FROM club_photos tp
            INNER JOIN tournaments t ON tp.torneo_id = t.id
            {$org_join}
            WHERE t.estatus = 1 AND (tp.activa = 1 OR tp.activa IS NULL)
        ";
        
        $params = [];
        
        if ($club_id > 0) {
            $query .= " AND t.club_responsable = ?";
            $params[] = $club_id;
        }
        
        if ($torneo_id > 0) {
            $query .= " AND tp.torneo_id = ?";
            $params[] = $torneo_id;
        }
        
        $query .= " ORDER BY t.fechator DESC, tp.orden ASC, tp.fecha_subida DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $fotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalFotos = count($fotos);
    }
} catch (Exception $e) {
    error_log("Error obteniendo fotos: " . $e->getMessage());
}

// Agrupar fotos por torneo
$fotos_por_torneo = [];
foreach ($fotos as $foto) {
    $torneo_key = $foto['torneo_id'];
    if (!isset($fotos_por_torneo[$torneo_key])) {
        $fotos_por_torneo[$torneo_key] = [
            'torneo_id' => $foto['torneo_id'],
            'torneo_nombre' => $foto['torneo_nombre'],
            'fechator' => $foto['fechator'],
            'club_nombre' => $foto['club_nombre'],
            'club_id' => $foto['club_id'],
            'fotos' => []
        ];
    }
    $fotos_por_torneo[$torneo_key]['fotos'][] = $foto;
}

$app_url = rtrim(AppHelpers::getPublicUrl(), '/');
$logo_url = AppHelpers::getAppLogo();
$landing_url = $app_url . '/landing-spa.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galería de Fotos - La Estación del Dominó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .navbar {
            background: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .main-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            margin: 2rem auto;
            padding: 2rem;
            max-width: 1400px;
        }
        .filter-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .torneo-section {
            margin-bottom: 3rem;
        }
        .torneo-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
            margin-bottom: 0;
        }
        .torneo-body {
            background: white;
            padding: 1.5rem;
            border-radius: 0 0 15px 15px;
            border: 1px solid #e0e0e0;
            border-top: none;
        }
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .photo-item {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.3s;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .photo-item:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }
        .photo-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?= htmlspecialchars($landing_url) ?>">
                <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" style="height: 40px; width: auto;" class="me-2">
                <span class="fw-bold">Portal de Torneos</span>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="<?= htmlspecialchars($landing_url) ?>">
                    <i class="fas fa-home me-1"></i>Inicio
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="main-container">
            <h1 class="text-center mb-4">
                <i class="fas fa-images me-2"></i>Galería de Fotos de Torneos
            </h1>

            <div class="filter-card">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" for="club_id">Organización</label>
                        <select name="club_id" id="club_id" class="form-select">
                            <option value="0">Todas las organizaciones</option>
                            <?php foreach ($organizaciones_filtro as $orgRow): ?>
                                <option value="<?= (int) $orgRow['id'] ?>" <?= $club_id === (int) $orgRow['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $orgRow['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" for="torneo_id">Torneo</label>
                        <select name="torneo_id" id="torneo_id" class="form-select">
                            <option value="0">Todos los torneos</option>
                            <?php foreach ($torneos as $tRow): ?>
                                <option value="<?= (int) $tRow['id'] ?>" <?= $torneo_id === (int) $tRow['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $tRow['nombre']) ?> (<?= date('d/m/Y', strtotime((string) $tRow['fechator'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filtrar</button>
                    </div>
                </form>
            </div>

            <!-- Resultados -->
            <?php if (empty($fotos_por_torneo)): ?>
                <div class="empty-state">
                    <i class="fas fa-images"></i>
                    <h3>No hay fotos disponibles</h3>
                    <p>No se encontraron fotos con los filtros seleccionados.</p>
                    <?php if (isset($_GET['debug'])): ?>
                        <div class="mt-4 p-3 bg-light rounded">
                            <h6>Información de depuración:</h6>
                            <ul class="text-start">
                                <li>Total fotos en BD: <?= $totalFotos ?></li>
                                <li>Club ID filtro: <?= $club_id > 0 ? $club_id : 'Todos' ?></li>
                                <li>Torneo ID filtro: <?= $torneo_id > 0 ? $torneo_id : 'Todos' ?></li>
                                <li>Tabla existe: <?= $tabla_existe ? 'Sí' : 'No' ?></li>
                                <li>App URL: <?= htmlspecialchars($app_url) ?></li>
                            </ul>
                            <p class="mt-2">
                                <a href="check_tournament_photos.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-search me-1"></i>Verificar Fotos en BD
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <p class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Mostrando <strong><?= $totalFotos ?></strong> foto(s) en <strong><?= count($fotos_por_torneo) ?></strong> torneo(s)
                    </p>
                </div>

                <?php foreach ($fotos_por_torneo as $torneo_data): ?>
                    <div class="torneo-section">
                        <div class="torneo-header">
                            <h3 class="mb-1">
                                <i class="fas fa-trophy me-2"></i><?= htmlspecialchars($torneo_data['torneo_nombre']) ?>
                            </h3>
                            <div class="d-flex flex-wrap gap-3 mt-2">
                                <?php if ($torneo_data['club_nombre']): ?>
                                    <span>
                                        <i class="fas fa-building me-1"></i>
                                        <?= htmlspecialchars($torneo_data['club_nombre']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($torneo_data['fechator']): ?>
                                    <span>
                                        <i class="fas fa-calendar me-1"></i>
                                        <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?>
                                    </span>
                                <?php endif; ?>
                                <a href="torneo_detalle.php?torneo_id=<?= (int) $torneo_data['torneo_id'] ?>" class="btn btn-sm btn-light ms-auto">
                                    <i class="fas fa-info-circle me-1"></i>Ver torneo
                                </a>
                                <span>
                                    <i class="fas fa-images me-1"></i>
                                    <?= count($torneo_data['fotos']) ?> foto(s)
                                </span>
                            </div>
                        </div>
                        <div class="torneo-body">
                            <div class="photo-grid">
                                <?php foreach ($torneo_data['fotos'] as $foto): ?>
                                    <?php 
                                    $imagenUrl = getImageUrl($foto['ruta_imagen']);
                                    ?>
                                    <div class="photo-item" onclick="abrirModal('<?= htmlspecialchars($imagenUrl, ENT_QUOTES) ?>', '<?= htmlspecialchars($torneo_data['torneo_nombre'], ENT_QUOTES) ?>')">
                                        <img src="<?= htmlspecialchars($imagenUrl) ?>" 
                                             alt="<?= htmlspecialchars($torneo_data['torneo_nombre']) ?>"
                                             loading="lazy"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext fill=\'%23999\' font-family=\'sans-serif\' font-size=\'14\' dy=\'10.5\' font-weight=\'bold\' x=\'50%25\' y=\'50%25\' text-anchor=\'middle\'%3EImagen no disponible%3C/text%3E%3C/svg%3E';">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para ver imagen completa -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0">
                    <img id="modalImage" src="" alt="" class="img-fluid" style="max-height: 80vh; width: auto;">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
        function abrirModal(imageUrl, torneoNombre) {
            document.getElementById('modalImage').src = imageUrl;
            document.getElementById('modalTitle').textContent = torneoNombre;
            const modal = new bootstrap.Modal(document.getElementById('imageModal'));
            modal.show();
        }
    </script>
</body>
</html>

