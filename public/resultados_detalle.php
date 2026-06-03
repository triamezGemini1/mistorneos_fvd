<?php
/**
 * Página de Resultados de un Torneo
 * Utiliza el mismo modelo de Posiciones del Panel de Control
 * Control de acceso:
 * - Usuarios no registrados: Resultados generales + lista de participantes + fotos
 * - Usuarios registrados NO inscritos: igual que no registrados  
 * - Usuarios registrados E inscritos: Resultados completos + sus stats destacados
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/UrlHelper.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';
require_once __DIR__ . '/../lib/LandingDataService.php';

$pdo = DB::pdo();
$base_url = app_base_url();
$user = Auth::user();
$is_logged_in = !empty($user);

// Obtener ID del torneo
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

// Redirigir a la página dinámica de resultados (compatible con enlaces antiguos)
if ($torneo_id > 0) {
    header('Location: evento_resultados.php?torneo_id=' . $torneo_id . (isset($_GET['msg']) ? '&msg=' . urlencode($_GET['msg']) : ''));
    exit;
}

if ($torneo_id <= 0) {
    header('Location: resultados.php');
    exit;
}

// Paginación
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$per_page = 25;

// Verificar si el usuario está inscrito en el torneo
$usuario_inscrito = false;
$inscripcion_usuario = null;
$mi_posicion = 0;

if ($is_logged_in && $user && Auth::id() > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM inscritos 
            WHERE torneo_id = ? AND id_usuario = ? 
            AND (estatus = 'confirmado' OR estatus IS NULL)
        ");
        $stmt->execute([$torneo_id, Auth::id()]);
        $inscripcion_usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $usuario_inscrito = !empty($inscripcion_usuario);
    } catch (Exception $e) {
        error_log("Error verificando inscripción: " . $e->getMessage());
    }
}

// Verificar si existe la tabla partiresul
$tabla_partiresul_existe = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'partiresul'");
    $tabla_partiresul_existe = $stmt->rowCount() > 0;
} catch (Exception $e) {}

// Obtener datos del torneo
$torneo_data = null;
$posiciones = [];
$estadisticas_partidas = [];
$total_fotos = 0;
$total_posiciones = 0;
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

try {
    // Datos del torneo
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            o.nombre as organizacion_nombre,
            o.responsable as organizacion_responsable,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND estatus = 'confirmado') as total_inscritos,
            (SELECT COUNT(*) FROM club_photos WHERE torneo_id = t.id) as total_fotos
        FROM tournaments t
        {$org_join}
        WHERE t.id = ?
    ");
    $stmt->execute([$torneo_id]);
    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo_data) {
        header('Location: resultados.php');
        exit;
    }

    // Validar acceso público (TournamentScopeHelper: publicar_landing)
    if (!TournamentScopeHelper::canAccessResultsPublicly($torneo_data)) {
        header('Location: resultados.php');
        exit;
    }
    
    $total_fotos = (int)($torneo_data['total_fotos'] ?? 0);
    
    // Podio para Acta Final
    $landingService = new LandingDataService($pdo);
    $podio_acta = $landingService->getPodioPorTorneo($torneo_id);
    
    if ($tabla_partiresul_existe) {
        // Contar total para paginación
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM inscritos 
            WHERE torneo_id = ? 
            AND (estatus = 'confirmado' OR estatus IS NULL)
        ");
        $stmt->execute([$torneo_id]);
        $total_posiciones = (int)$stmt->fetchColumn();
        
        // Calcular paginación
        $total_pages = ceil($total_posiciones / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // Obtener posiciones con paginación
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                COALESCE(u.nombre, u.username) as nombre_completo,
                u.sexo,
                c.nombre as club_nombre,
                (SELECT COUNT(*) FROM partiresul WHERE id_usuario = i.id_usuario AND id_torneo = ? AND ff = 1) as ganadas_por_forfait,
                (SELECT COUNT(*) FROM partiresul WHERE id_usuario = i.id_usuario AND id_torneo = ? AND registrado = 1 AND mesa = 0 AND resultado1 > resultado2) as partidas_bye
            FROM inscritos i
            LEFT JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ?
              AND (i.estatus = 'confirmado' OR i.estatus IS NULL)
            ORDER BY i.ptosrnk DESC, i.efectividad DESC, i.ganados DESC, i.puntos DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$torneo_id, $torneo_id, $torneo_id, $per_page, $offset]);
        $posiciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Encontrar mi posición si estoy inscrito
        if ($usuario_inscrito && $user) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) + 1 as mi_posicion
                FROM inscritos i
                WHERE i.torneo_id = ?
                  AND (i.estatus = 'confirmado' OR i.estatus IS NULL)
                  AND (
                      i.ptosrnk > ? 
                      OR (i.ptosrnk = ? AND i.efectividad > ?)
                      OR (i.ptosrnk = ? AND i.efectividad = ? AND i.ganados > ?)
                  )
            ");
            $ptosrnk = (int)($inscripcion_usuario['ptosrnk'] ?? 0);
            $efectividad = (int)($inscripcion_usuario['efectividad'] ?? 0);
            $ganados = (int)($inscripcion_usuario['ganados'] ?? 0);
            $stmt->execute([$torneo_id, $ptosrnk, $ptosrnk, $efectividad, $ptosrnk, $efectividad, $ganados]);
            $mi_posicion = (int)$stmt->fetchColumn();
        }
        
        // Estadísticas de partidas
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT partida) as total_rondas,
                COUNT(*) as total_partidas,
                COUNT(CASE WHEN registrado = 1 THEN 1 END) as partidas_registradas
            FROM partiresul
            WHERE id_torneo = ?
        ");
        $stmt->execute([$torneo_id]);
        $estadisticas_partidas = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {
    error_log("Error obteniendo datos: " . $e->getMessage());
    header('Location: resultados.php');
    exit;
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = ['torneo' => 'Torneo', 'campeonato' => 'Campeonato', 1 => 'Torneo', 2 => 'Campeonato'];
$total_pages = ceil($total_posiciones / $per_page);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a365d">
    
    <!-- SEO Meta Tags -->
    <title>Resultados <?= htmlspecialchars($torneo_data['nombre']) ?> - La Estación del Dominó</title>
    <meta name="description" content="Resultados y clasificación del torneo <?= htmlspecialchars($torneo_data['nombre']) ?>. <?= (int)($torneo_data['total_inscritos'] ?? 0) ?> participantes. Fecha: <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?>">
    <meta name="keywords" content="resultados <?= htmlspecialchars($torneo_data['nombre']) ?>, torneo dominó, clasificación dominó">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= htmlspecialchars(AppHelpers::url('resultados_detalle.php', ['torneo_id' => $torneo_id])) ?>">
    <meta property="og:title" content="Resultados: <?= htmlspecialchars($torneo_data['nombre']) ?>">
    <meta property="og:description" content="Clasificación y resultados del torneo de dominó <?= htmlspecialchars($torneo_data['nombre']) ?>">
    <meta property="og:image" content="<?= htmlspecialchars(AppHelpers::getAppLogo()) ?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Resultados: <?= htmlspecialchars($torneo_data['nombre']) ?>">
    <meta name="twitter:description" content="Clasificación del torneo de dominó <?= htmlspecialchars($torneo_data['nombre']) ?>">
    
    <!-- Canonical -->
    <link rel="canonical" href="<?= htmlspecialchars(AppHelpers::url('resultados_detalle.php', ['torneo_id' => $torneo_id])) ?>">
    
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
        }
    }
    </script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-dark: #1a365d;
            --primary: #2d4a7c;
            --accent: #f6ad55;
        }
        body {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 1.5rem 0;
        }
        .main-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header-section {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: white;
            padding: 2rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .info-item i {
            width: 20px;
            opacity: 0.8;
        }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .stat-box {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-box .number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-dark);
        }
        .stat-box .label {
            font-size: 0.85rem;
            color: #64748b;
        }
        .user-highlight {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            padding: 1rem 1.5rem;
            border-left: 4px solid #10b981;
            margin: 1rem 1.5rem;
            border-radius: 8px;
        }
        .table-container {
            padding: 1.5rem;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        .results-table thead th {
            background: var(--primary-dark);
            color: white;
            padding: 0.75rem 0.5rem;
            font-size: 0.85rem;
            text-align: center;
            white-space: nowrap;
        }
        .results-table thead th:nth-child(2) {
            text-align: left;
        }
        .results-table tbody td {
            padding: 0.6rem 0.5rem;
            text-align: center;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }
        .results-table tbody td:nth-child(2) {
            text-align: left;
        }
        .results-table tbody tr:hover {
            background: #f1f5f9;
        }
        .results-table tbody tr.gold {
            background: linear-gradient(90deg, #fef3c7 0%, #fde68a 100%);
        }
        .results-table tbody tr.silver {
            background: linear-gradient(90deg, #f1f5f9 0%, #e2e8f0 100%);
        }
        .results-table tbody tr.bronze {
            background: linear-gradient(90deg, #fed7aa 0%, #fdba74 100%);
        }
        .results-table tbody tr.my-row {
            background: linear-gradient(90deg, #d1fae5 0%, #a7f3d0 100%) !important;
            font-weight: 600;
        }
        .medal {
            font-size: 1.1rem;
        }
        .badge-stat {
            display: inline-block;
            min-width: 28px;
            padding: 0.25rem 0.4rem;
            font-size: 0.8rem;
            border-radius: 4px;
        }
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            flex-wrap: wrap;
        }
        .pagination-wrapper a, .pagination-wrapper span {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary-dark);
            font-size: 0.9rem;
        }
        .pagination-wrapper a:hover {
            background: var(--primary-dark);
            color: white;
        }
        .pagination-wrapper .active {
            background: var(--primary-dark);
            color: white;
        }
        .legend {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            font-size: 0.85rem;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }
        .photos-section {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }
        .acta-final {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1rem 1.5rem;
        }
        .acta-final h5 {
            border-bottom: 2px solid #1a365d;
            padding-bottom: 0.5rem;
            color: #1a365d;
        }
        .acta-podio-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
            gap: 0.75rem;
        }
        .acta-podio-item .medal {
            font-size: 1.5rem;
        }
        .nav-buttons {
            padding: 1.5rem;
            text-align: center;
            background: #f8fafc;
        }
        .btn-nav {
            padding: 0.6rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            margin: 0.25rem;
            display: inline-block;
        }
        .btn-primary-custom {
            background: var(--primary-dark);
            color: white;
        }
        .btn-primary-custom:hover {
            background: var(--primary);
            color: white;
        }
        .btn-secondary-custom {
            background: #64748b;
            color: white;
        }
        .btn-secondary-custom:hover {
            background: #475569;
            color: white;
        }
        @media (max-width: 768px) {
            .results-table {
                font-size: 0.8rem;
            }
            .results-table thead th,
            .results-table tbody td {
                padding: 0.4rem 0.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-card mx-auto" style="max-width: 1100px;">
            <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-info mx-3 mt-3 mb-0">
                <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($_GET['msg']) ?>
            </div>
            <?php endif; ?>
            <!-- Header -->
            <div class="header-section">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <?= AppHelpers::appLogo('', 'La Estación del Dominó', 40) ?>
                    </div>
                    <div class="text-end">
                        <h4 class="mb-1"><i class="fas fa-trophy me-2"></i>Resultados</h4>
                        <h5 class="mb-0 opacity-90"><?= htmlspecialchars($torneo_data['nombre']) ?></h5>
                    </div>
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <span><?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></span>
                    </div>
                    <?php if ($torneo_data['lugar']): ?>
                    <div class="info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= htmlspecialchars($torneo_data['lugar']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <i class="fas fa-building"></i>
                        <span><?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'N/A') ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-users"></i>
                        <span><?= $modalidades[$torneo_data['modalidad']] ?? 'N/A' ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Acta Final -->
            <div class="acta-final">
                <h5 class="mb-3"><i class="fas fa-file-signature me-2"></i>Acta Final del Torneo</h5>
                <p class="mb-2">
                    <strong>Evento:</strong> <?= htmlspecialchars($torneo_data['nombre']) ?> |
                    <strong>Fecha:</strong> <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?>
                    <?php if (!empty($torneo_data['lugar'])): ?> |
                    <strong>Lugar:</strong> <?= htmlspecialchars($torneo_data['lugar']) ?><?php endif; ?>
                </p>
                <p class="mb-2">
                    <strong>Organizador:</strong> <?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'N/A') ?> |
                    <strong>Participantes:</strong> <?= (int)($torneo_data['total_inscritos'] ?? 0) ?>
                </p>
                <?php if (!empty($podio_acta)): ?>
                <div class="mt-3 pt-3 border-top">
                    <strong>Podio oficial:</strong>
                    <?php foreach ($podio_acta as $p): 
                        $pos = (int)($p['posicion_display'] ?? 0);
                        $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : '🥉');
                    ?>
                    <div class="acta-podio-item">
                        <span class="medal"><?= $medal ?></span>
                        <span><strong><?= $pos ?>° Lugar:</strong> <?= htmlspecialchars($p['nombre']) ?>
                        <?php if (!empty($p['club_nombre'])): ?> (<?= htmlspecialchars($p['club_nombre']) ?>)<?php endif; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Estadísticas -->
            <div class="stats-row">
                <div class="stat-box">
                    <div class="number"><?= (int)($torneo_data['total_inscritos'] ?? 0) ?></div>
                    <div class="label"><i class="fas fa-users me-1"></i>Participantes</div>
                </div>
                <div class="stat-box">
                    <div class="number"><?= $estadisticas_partidas['total_rondas'] ?? 0 ?></div>
                    <div class="label"><i class="fas fa-sync-alt me-1"></i>Rondas</div>
                </div>
                <div class="stat-box">
                    <div class="number"><?= $estadisticas_partidas['partidas_registradas'] ?? 0 ?></div>
                    <div class="label"><i class="fas fa-gamepad me-1"></i>Partidas</div>
                </div>
            </div>
            
            <?php if ($is_logged_in && $usuario_inscrito && $inscripcion_usuario): ?>
            <!-- Mi Posición (solo usuarios inscritos) -->
            <div class="user-highlight">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <strong><i class="fas fa-user-check me-2"></i>Tu Posición:</strong>
                        <span class="badge bg-primary fs-6 ms-2">#<?= $mi_posicion ?></span>
                    </div>
                    <div class="d-flex gap-3 flex-wrap">
                        <span><strong>G:</strong> <?= (int)($inscripcion_usuario['ganados'] ?? 0) ?></span>
                        <span><strong>P:</strong> <?= (int)($inscripcion_usuario['perdidos'] ?? 0) ?></span>
                        <span><strong>Efect:</strong> <?= (int)($inscripcion_usuario['efectividad'] ?? 0) ?></span>
                        <span><strong>Pts Rnk:</strong> <span class="badge bg-success"><?= (int)($inscripcion_usuario['ptosrnk'] ?? 0) ?></span></span>
                    </div>
                </div>
            </div>
            <?php elseif (!$is_logged_in): ?>
            <div class="user-highlight" style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-left-color: #3b82f6;">
                <i class="fas fa-info-circle me-2"></i>
                <a href="<?= htmlspecialchars($base_url) ?>/public/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="fw-bold">
                    Inicia sesión
                </a> para ver tu posición destacada si participas en este torneo.
            </div>
            <?php endif; ?>
            
            <!-- Tabla de Resultados -->
            <div class="table-container">
                <?php if (empty($posiciones)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Aún no hay resultados disponibles para este torneo.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Pos</th>
                                    <th>ID Usuario</th>
                                    <th>Nombre</th>
                                    <th>Club</th>
                                    <th>G</th>
                                    <th>P</th>
                                    <th>GFF</th>
                                    <th>Efect.</th>
                                    <th>Puntos</th>
                                    <th>Pts. Rnk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $posicion_inicio = ($page - 1) * $per_page + 1;
                                $posicion = $posicion_inicio;
                                
                                foreach ($posiciones as $pos): 
                                    $es_mi_fila = ($is_logged_in && $usuario_inscrito && ($pos['id_usuario'] ?? 0) == Auth::id());
                                    
                                    $row_class = '';
                                    if ($es_mi_fila) {
                                        $row_class = 'my-row';
                                    } elseif ($posicion == 1) {
                                        $row_class = 'gold';
                                    } elseif ($posicion == 2) {
                                        $row_class = 'silver';
                                    } elseif ($posicion == 3) {
                                        $row_class = 'bronze';
                                    }
                                ?>
                                    <tr class="<?= $row_class ?>">
                                        <td>
                                            <strong><?= $posicion ?></strong>
                                            <?php if ($posicion == 1): ?>
                                                <span class="medal">🥇</span>
                                            <?php elseif ($posicion == 2): ?>
                                                <span class="medal">🥈</span>
                                            <?php elseif ($posicion == 3): ?>
                                                <span class="medal">🥉</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($pos['id_usuario'] ?? 'N/A') ?></code></td>
                                        <td>
                                            <i class="fas fa-user text-muted me-1"></i>
                                            <?= htmlspecialchars($pos['nombre_completo'] ?? 'N/A') ?>
                                            <?php if (!empty($pos['sexo'])): ?>
                                                <small class="text-muted">(<?= $pos['sexo'] == 'M' ? '♂' : '♀' ?>)</small>
                                            <?php endif; ?>
                                            <?php if ($es_mi_fila): ?>
                                                <span class="badge bg-success ms-1">Tú</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?= htmlspecialchars($pos['club_nombre'] ?? 'Sin Club') ?></small></td>
                                        <td><span class="badge-stat bg-success text-white"><?= (int)($pos['ganados'] ?? 0) ?></span></td>
                                        <td><span class="badge-stat bg-danger text-white"><?= (int)($pos['perdidos'] ?? 0) ?></span></td>
                                        <td>
                                            <span class="badge-stat bg-warning text-dark"><?= (int)($pos['ganadas_por_forfait'] ?? 0) ?></span>
                                            <?php $partidas_bye = (int)($pos['partidas_bye'] ?? 0); if ($partidas_bye > 0): ?>
                                                <span class="badge bg-info ms-1" title="Partidas con descanso (BYE)"><?= $partidas_bye ?> BYE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)($pos['efectividad'] ?? 0) ?></td>
                                        <td><?= (int)($pos['puntos'] ?? 0) ?></td>
                                        <td><strong class="text-primary"><?= (int)($pos['ptosrnk'] ?? 0) ?></strong></td>
                                    </tr>
                                    <?php $posicion++; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <!-- Paginación -->
                    <div class="pagination-wrapper">
                        <?php if ($page > 1): ?>
                            <a href="?torneo_id=<?= $torneo_id ?>&p=1"><i class="fas fa-angle-double-left"></i></a>
                            <a href="?torneo_id=<?= $torneo_id ?>&p=<?= $page - 1 ?>"><i class="fas fa-angle-left"></i></a>
                        <?php endif; ?>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?torneo_id=<?= $torneo_id ?>&p=<?= $i ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?torneo_id=<?= $torneo_id ?>&p=<?= $page + 1 ?>"><i class="fas fa-angle-right"></i></a>
                            <a href="?torneo_id=<?= $torneo_id ?>&p=<?= $total_pages ?>"><i class="fas fa-angle-double-right"></i></a>
                        <?php endif; ?>
                        
                        <small class="text-muted ms-2">Página <?= $page ?> de <?= $total_pages ?></small>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Leyenda -->
            <div class="legend">
                <strong>Leyenda:</strong>
                <span class="badge bg-warning text-dark">🥇</span> Oro |
                <span class="badge bg-secondary">🥈</span> Plata |
                <span class="badge bg-light text-dark border">🥉</span> Bronce
                <br>
                <strong>G:</strong> Ganados | <strong>P:</strong> Perdidos | <strong>GFF:</strong> Ganadas por Forfait | <strong>BYE:</strong> Partidas con descanso (información) | <strong>Efect.:</strong> Efectividad | <strong>Pts. Rnk:</strong> Puntos de Ranking
            </div>
            
            <?php if ($total_fotos > 0): ?>
            <!-- Fotos -->
            <div class="photos-section">
                <p class="mb-2"><i class="fas fa-images me-2"></i>Este torneo tiene <strong><?= $total_fotos ?></strong> foto(s)</p>
                <a href="galeria_fotos.php?torneo_id=<?= $torneo_id ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-images me-1"></i>Ver Galería
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Navegación -->
            <div class="nav-buttons">
                <a href="resultados.php" class="btn-nav btn-primary-custom">
                    <i class="fas fa-arrow-left me-1"></i>Volver a Listado
                </a>
                <a href="<?= htmlspecialchars($base_url) ?>/public/landing.php" class="btn-nav btn-secondary-custom">
                    <i class="fas fa-home me-1"></i>Inicio
                </a>
            </div>
        </div>
    </div>
</body>
</html>
