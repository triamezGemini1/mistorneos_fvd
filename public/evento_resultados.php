<?php
/**
 * Página pública de Resultados de Evento.
 * Patrón en bloque: db_config (conexión única). Sin requireAuth (acceso público a resultados).
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/UrlHelper.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';
require_once __DIR__ . '/../lib/ResultadosPublicHelper.php';
require_once __DIR__ . '/../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../lib/LandingDataService.php';

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
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$vista = $_GET['vista'] ?? 'general';
$pagina = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$genero_get = isset($_GET['genero']) ? (string) $_GET['genero'] : null;
$per_page = 50;

if ($torneo_id <= 0) {
    header('Location: resultados.php');
    exit;
}

$torneo_data = null;
try {
    $stmt = $pdo->prepare("
        SELECT t.*, o.nombre as organizacion_nombre,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus IS NULL OR (estatus != 4 AND estatus != 'retirado'))) as total_inscritos,
            (SELECT COUNT(*) FROM club_photos WHERE torneo_id = t.id) as total_fotos
        FROM tournaments t
        {$org_join}
        WHERE t.id = ?
    ");
    $stmt->execute([$torneo_id]);
    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("evento_resultados: " . $e->getMessage());
}

if (!$torneo_data || !TournamentScopeHelper::canAccessResultsPublicly($torneo_data)) {
    header('Location: resultados.php');
    exit;
}

$modalidad = (int)($torneo_data['modalidad'] ?? 1);
$es_equipos = ($modalidad === 3);
$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
$genero_evt = ResultadosReporteData::generoFiltroDesdeParametro($genero_get);
$gen_q = 'genero=' . urlencode($genero_evt);

$rounds_info = ResultadosPublicHelper::getRoundsInfo($pdo, $torneo_id);
$landingService = new LandingDataService($pdo);
$podio_acta = $landingService->getPodioPorTorneo($torneo_id);

$posiciones = [];
$total_posiciones = 0;
if ($vista === 'general') {
    $total_posiciones = ResultadosPublicHelper::getPosicionesCount($pdo, $torneo_id, $genero_get);
    $offset = ($pagina - 1) * $per_page;
    $posiciones = ResultadosPublicHelper::getPosiciones($pdo, $torneo_id, $per_page, $offset, $genero_get);
}

$clubes_data = [];
$pareclub = (int)($torneo_data['pareclub'] ?? 0);
$limite_club = ($pareclub > 0) ? $pareclub : 8;
if ($vista === 'club' || $vista === 'club_resumido' || $vista === 'club_detallado') {
    $clubes_data = ResultadosPublicHelper::getResultadosPorClub($pdo, $torneo_id, $limite_club);
}

$equipos_resumido = [];
$equipos_detallado = [];
if ($es_equipos) {
    if ($vista === 'equipos_resumido') {
        $equipos_resumido = ResultadosPublicHelper::getResultadosEquiposResumido($pdo, $torneo_id, 100, 0);
    }
    if ($vista === 'equipos_detallado') {
        $equipos_detallado = ResultadosPublicHelper::getResultadosEquiposDetallado($pdo, $torneo_id, 50, 0);
    }
}

$total_pages = $vista === 'general' ? max(1, ceil($total_posiciones / $per_page)) : 1;
$url_base = 'evento_resultados.php?torneo_id=' . $torneo_id;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title>Resultados <?= htmlspecialchars($torneo_data['nombre']) ?> - La Estación del Dominó</title>
    <meta name="description" content="Consulta resultados del torneo <?= htmlspecialchars($torneo_data['nombre']) ?>. Clasificación, resultados por club y equipos. <?= $rounds_info['ejecutadas'] ?> de <?= $rounds_info['total'] ?> rondas.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0f172a;
            --accent: #f59e0b;
            --success: #10b981;
            --muted: #64748b;
        }
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #f8fafc;
        }
        .card-resultados {
            background: rgba(255,255,255,0.98);
            color: #1e293b;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);
            overflow: hidden;
        }
        .header-evento {
            background: linear-gradient(135deg, #0f172a 0%, #1e40af 100%);
            color: white;
            padding: 1.75rem;
        }
        .rondas-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-weight: 600;
            background: rgba(255,255,255,0.15);
        }
        .progress-rondas {
            height: 10px;
            border-radius: 9999px;
            background: rgba(255,255,255,0.2);
        }
        .progress-rondas .progress-bar {
            background: linear-gradient(90deg, #f59e0b, #10b981);
        }
        .nav-vistas .nav-link {
            color: #64748b;
            font-weight: 500;
            border-radius: 10px;
            padding: 0.6rem 1rem;
            transition: all 0.2s;
        }
        .nav-vistas .nav-link:hover { color: #0f172a; background: #f1f5f9; }
        .nav-vistas .nav-link.active {
            background: linear-gradient(135deg, #0f172a, #1e40af);
            color: white;
        }
        .tabla-resultados th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 0.8rem;
        }
        .tabla-resultados tbody tr:hover { background: #f8fafc; }
        .pos-1 { background: linear-gradient(90deg, #fef3c7, #fde68a); }
        .pos-2 { background: linear-gradient(90deg, #f1f5f9, #e2e8f0); }
        .pos-3 { background: linear-gradient(90deg, #fed7aa, #fdba74); }
        .link-jugador {
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }
        .link-jugador:hover { color: #1d4ed8; text-decoration: underline; }
        .club-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        .club-card .club-header {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            font-weight: 600;
        }
        .btn-volver {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .btn-volver:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="card-resultados mx-auto" style="max-width: 1200px;">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-info m-4 mb-0">
            <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($_GET['msg']) ?>
        </div>
        <?php endif; ?>
        <!-- Header -->
        <div class="header-evento">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <?= AppHelpers::appLogo('', 'La Estación del Dominó', 36) ?>
                    <h4 class="mt-2 mb-1"><i class="fas fa-trophy me-2"></i>Resultados del evento</h4>
                    <h5 class="mb-0 opacity-90"><?= htmlspecialchars($torneo_data['nombre']) ?></h5>
                </div>
                <div>
                    <a href="resultados.php" class="btn btn-sm btn-volver">
                        <i class="fas fa-arrow-left me-1"></i>Volver a eventos
                    </a>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-auto">
                    <small><i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></small>
                </div>
                <?php if (!empty($torneo_data['lugar'])): ?>
                <div class="col-auto">
                    <small><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($torneo_data['lugar']) ?></small>
                </div>
                <?php endif; ?>
                <div class="col-auto">
                    <small><i class="fas fa-building me-1"></i><?= htmlspecialchars($torneo_data['organizacion_nombre'] ?? 'N/A') ?></small>
                </div>
                <div class="col-auto">
                    <small><i class="fas fa-users me-1"></i><?= $modalidades[$modalidad] ?? 'N/A' ?></small>
                </div>
            </div>

            <!-- Rondas ejecutadas / faltantes -->
            <div class="mt-4">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <span class="rondas-badge">
                        <i class="fas fa-sync-alt"></i>
                        Rondas: <strong><?= $rounds_info['ejecutadas'] ?></strong> ejecutadas
                        <?php if ($rounds_info['total'] > 0): ?>
                            de <strong><?= $rounds_info['total'] ?></strong>
                            <?php if ($rounds_info['faltantes'] > 0): ?>
                                — Faltan <strong><?= $rounds_info['faltantes'] ?></strong>
                            <?php else: ?>
                                — <span class="text-success">Completado</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($rounds_info['total'] > 0): ?>
                <div class="progress progress-rondas mt-2" style="max-width: 400px;">
                    <div class="progress-bar" role="progressbar"
                         style="width: <?= $rounds_info['total'] > 0 ? min(100, ($rounds_info['ejecutadas'] / $rounds_info['total']) * 100) : 0 ?>%">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabs de vistas -->
        <div class="p-4 border-bottom">
            <ul class="nav nav-pills nav-vistas flex-wrap gap-2">
                <li class="nav-item">
                    <a class="nav-link <?= $vista === 'general' ? 'active' : '' ?>"
                       href="<?= $url_base ?>&vista=general&<?= $gen_q ?>">
                        <i class="fas fa-list-ol me-1"></i>Clasificación general
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $vista === 'club_resumido' ? 'active' : '' ?>"
                       href="<?= $url_base ?>&vista=club_resumido">
                        <i class="fas fa-chart-bar me-1"></i>Por club (resumido)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($vista === 'club' || $vista === 'club_detallado') ? 'active' : '' ?>"
                       href="<?= $url_base ?>&vista=club_detallado">
                        <i class="fas fa-list-ul me-1"></i>Por club (detallado)
                    </a>
                </li>
                <?php if ($es_equipos): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $vista === 'equipos_resumido' ? 'active' : '' ?>"
                       href="<?= $url_base ?>&vista=equipos_resumido">
                        <i class="fas fa-users me-1"></i>Equipos (resumido)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $vista === 'equipos_detallado' ? 'active' : '' ?>"
                       href="<?= $url_base ?>&vista=equipos_detallado">
                        <i class="fas fa-list-ul me-1"></i>Equipos (detallado)
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Contenido -->
        <div class="p-4">
            <?php if ($vista === 'general'): ?>
                <!-- Clasificación general -->
                <h5 class="mb-2"><i class="fas fa-trophy text-warning me-2"></i>Clasificación individual</h5>
                <div class="btn-group btn-group-sm mb-4" role="group" aria-label="Género">
                    <a href="<?= $url_base ?>&vista=general&genero=M" class="btn <?= $genero_evt === 'M' ? 'btn-primary' : 'btn-outline-primary' ?>">Masculino</a>
                    <a href="<?= $url_base ?>&vista=general&genero=F" class="btn <?= $genero_evt === 'F' ? 'btn-primary' : 'btn-outline-primary' ?>">Femenino</a>
                </div>
                <?php if (empty($posiciones)): ?>
                    <p class="text-muted">Aún no hay resultados disponibles.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table tabla-resultados table-hover">
                            <thead>
                                <tr>
                                    <th>Pos</th>
                                    <th>ID</th>
                                    <th>Jugador</th>
                                    <th>Club</th>
                                    <th class="text-center">G</th>
                                    <th class="text-center">P</th>
                                    <th class="text-center">GFF</th>
                                    <th class="text-center">Efect.</th>
                                    <th class="text-center">Pts</th>
                                    <th class="text-center">Pts.Rnk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $pos_display = ($pagina - 1) * $per_page + 1;
                                foreach ($posiciones as $p):
                                    $row_class = '';
                                    if ($pos_display == 1) $row_class = 'pos-1';
                                    elseif ($pos_display == 2) $row_class = 'pos-2';
                                    elseif ($pos_display == 3) $row_class = 'pos-3';
                                    $resumen_url = 'resumen_jugador.php?torneo_id=' . $torneo_id . '&id_usuario=' . (int)($p['id_usuario'] ?? 0);
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td><strong><?= $pos_display ?></strong>
                                        <?php if ($pos_display <= 3): ?>
                                            <span><?= ['🥇','🥈','🥉'][$pos_display - 1] ?? '' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($p['id_usuario'] ?? '') ?></code></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($resumen_url) ?>" class="link-jugador">
                                            <?= htmlspecialchars($p['nombre_completo'] ?? 'N/A') ?>
                                        </a>
                                    </td>
                                    <td><small><?= htmlspecialchars($p['club_nombre'] ?? '—') ?></small></td>
                                    <td class="text-center"><span class="badge bg-success"><?= (int)($p['ganados'] ?? 0) ?></span></td>
                                    <td class="text-center"><span class="badge bg-danger"><?= (int)($p['perdidos'] ?? 0) ?></span></td>
                                    <td class="text-center"><?= (int)($p['ganadas_por_forfait'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($p['efectividad'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($p['puntos'] ?? 0) ?></td>
                                    <td class="text-center"><strong><?= (int)($p['ptosrnk'] ?? 0) ?></strong></td>
                                </tr>
                                <?php $pos_display++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center">
                            <?php for ($i = 1; $i <= min($total_pages, 10); $i++): ?>
                                <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $url_base ?>&vista=general&p=<?= $i ?>&<?= $gen_q ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ($vista === 'club_resumido'): ?>
                <!-- Resultados por club - Vista resumida (una fila por club) -->
                <h5 class="mb-4"><i class="fas fa-chart-bar text-primary me-2"></i>Resultados por club (resumido)</h5>
                <p class="text-muted small mb-3">Se consideran los mejores <?= $limite_club ?> jugadores de cada club para las estadísticas.</p>
                <?php if (empty($clubes_data)): ?>
                    <p class="text-muted">No hay datos por club disponibles.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table tabla-resultados table-hover">
                            <thead>
                                <tr>
                                    <th class="text-center">Pos</th>
                                    <th>Club</th>
                                    <th class="text-center">Jugadores</th>
                                    <th class="text-center">Ganados</th>
                                    <th class="text-center">Perdidos</th>
                                    <th class="text-center">GFF</th>
                                    <th class="text-center">Efect. prom.</th>
                                    <th class="text-center">Puntos prom.</th>
                                    <th class="text-center">Pts. Rnk total</th>
                                    <th class="text-center">Mejor pos.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $pos_club = 1; foreach ($clubes_data as $club): ?>
                                <tr class="<?= $pos_club <= 3 ? ($pos_club == 1 ? 'pos-1' : ($pos_club == 2 ? 'pos-2' : 'pos-3')) : '' ?>">
                                    <td class="text-center"><strong><?= $pos_club ?></strong><?php if ($pos_club <= 3): ?> <i class="fas fa-medal text-warning"></i><?php endif; ?></td>
                                    <td><strong><?= htmlspecialchars($club['club_nombre']) ?></strong></td>
                                    <td class="text-center"><?= count($club['jugadores'] ?? []) ?></td>
                                    <td class="text-center text-success fw-bold"><?= (int)($club['total_ganados'] ?? 0) ?></td>
                                    <td class="text-center text-danger"><?= (int)($club['total_perdidos'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($club['total_gff'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($club['promedio_efectividad'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($club['promedio_puntos'] ?? 0) ?></td>
                                    <td class="text-center fw-bold"><?= (int)($club['total_ptosrnk'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($club['mejor_posicion'] ?? 0) ?: '—' ?></td>
                                </tr>
                                <?php $pos_club++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($vista === 'club' || $vista === 'club_detallado'): ?>
                <!-- Resultados por club - Vista detallada (tarjeta por club con jugadores) -->
                <h5 class="mb-4"><i class="fas fa-list-ul text-primary me-2"></i>Resultados por club (detallado)</h5>
                <p class="text-muted small mb-3">Se consideran los mejores <?= $limite_club ?> jugadores de cada club.</p>
                <?php if (empty($clubes_data)): ?>
                    <p class="text-muted">No hay datos por club disponibles.</p>
                <?php else: ?>
                    <?php $pos_club = 1; foreach ($clubes_data as $club): ?>
                    <div class="club-card">
                        <div class="club-header d-flex justify-content-between align-items-center">
                            <span>
                                <?= $pos_club ?>°
                                <?php if ($pos_club <= 3): ?><i class="fas fa-medal text-warning ms-1"></i><?php endif; ?>
                                <strong><?= htmlspecialchars($club['club_nombre']) ?></strong>
                            </span>
                            <span class="badge bg-primary"><?= $club['total_ganados'] ?> G</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm tabla-resultados mb-0">
                                <thead>
                                    <tr>
                                        <th>Pos</th>
                                        <th>Jugador</th>
                                        <th class="text-center">G</th>
                                        <th class="text-center">P</th>
                                        <th class="text-center">Efect.</th>
                                        <th class="text-center">Pts.Rnk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($club['jugadores'] as $j): ?>
                                    <tr>
                                        <td><?= (int)($j['posicion'] ?? 0) ?: '—' ?></td>
                                        <td>
                                            <a href="resumen_jugador.php?torneo_id=<?= $torneo_id ?>&id_usuario=<?= (int)($j['id_usuario'] ?? 0) ?>" class="link-jugador">
                                                <?= htmlspecialchars($j['nombre_completo'] ?? $j['username'] ?? 'N/A') ?>
                                            </a>
                                        </td>
                                        <td class="text-center"><?= (int)($j['ganados'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int)($j['perdidos'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int)($j['efectividad'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int)($j['ptosrnk'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php $pos_club++; endforeach; ?>
                <?php endif; ?>

            <?php elseif ($vista === 'equipos_resumido' && $es_equipos): ?>
                <!-- Equipos resumido -->
                <h5 class="mb-4"><i class="fas fa-users text-indigo me-2"></i>Resultados por equipos (resumido)</h5>
                <?php if (empty($equipos_resumido)): ?>
                    <p class="text-muted">No hay equipos registrados.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table tabla-resultados table-hover">
                            <thead>
                                <tr>
                                    <th>Pos</th>
                                    <th>Equipo</th>
                                    <th>Club</th>
                                    <th class="text-center">G</th>
                                    <th class="text-center">P</th>
                                    <th class="text-center">Efect.</th>
                                    <th class="text-center">Puntos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $pos_eq = 1; foreach ($equipos_resumido as $eq): ?>
                                <tr>
                                    <td><?= $pos_eq ?><?php if ($pos_eq <= 3): ?> <i class="fas fa-medal text-warning"></i><?php endif; ?></td>
                                    <td><strong><?= htmlspecialchars($eq['nombre_equipo'] ?? 'Equipo ' . ($eq['codigo_equipo'] ?? '')) ?></strong></td>
                                    <td><?= htmlspecialchars($eq['club_nombre'] ?? '—') ?></td>
                                    <td class="text-center"><?= (int)($eq['ganados'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($eq['perdidos'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($eq['efectividad'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int)($eq['puntos'] ?? 0) ?></td>
                                </tr>
                                <?php $pos_eq++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($vista === 'equipos_detallado' && $es_equipos): ?>
                <!-- Equipos detallado -->
                <h5 class="mb-4"><i class="fas fa-list-ul text-indigo me-2"></i>Resultados por equipos (detallado)</h5>
                <?php if (empty($equipos_detallado)): ?>
                    <p class="text-muted">No hay equipos registrados.</p>
                <?php else: ?>
                    <?php $pos_eq = 1; foreach ($equipos_detallado as $eq): ?>
                    <div class="club-card">
                        <div class="club-header">
                            <?= $pos_eq ?>° <?= htmlspecialchars($eq['nombre_equipo'] ?? 'Equipo ' . ($eq['codigo_equipo'] ?? '')) ?>
                            — <?= htmlspecialchars($eq['club_nombre'] ?? '') ?>
                            <span class="badge bg-success ms-2"><?= (int)($eq['ganados'] ?? 0) ?> G</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm tabla-resultados mb-0">
                                <thead>
                                    <tr>
                                        <th>Pos</th>
                                        <th>Jugador</th>
                                        <th class="text-center">G</th>
                                        <th class="text-center">P</th>
                                        <th class="text-center">Efect.</th>
                                        <th class="text-center">Pts.Rnk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($eq['jugadores'] ?? [] as $j): ?>
                                    <tr>
                                        <td><?= (int)($j['posicion'] ?? 0) ?: '—' ?></td>
                                        <td>
                                            <a href="resumen_jugador.php?torneo_id=<?= $torneo_id ?>&id_usuario=<?= (int)($j['id_usuario'] ?? 0) ?>" class="link-jugador">
                                                <?= htmlspecialchars($j['nombre_completo'] ?? 'N/A') ?>
                                            </a>
                                        </td>
                                        <td class="text-center"><?= (int)($j['ganados'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int)($j['perdidos'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int)($j['efectividad'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int)($j['ptosrnk'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php $pos_eq++; endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Acta final y enlaces -->
        <?php if (!empty($podio_acta)): ?>
        <div class="p-4 border-top bg-light">
            <h6><i class="fas fa-file-signature me-2"></i>Podio oficial</h6>
            <?php foreach ($podio_acta as $p):
                $medal = [1 => '🥇', 2 => '🥈', 3 => '🥉'][(int)($p['posicion_display'] ?? 0)] ?? '•';
            ?>
            <div class="mb-1"><?= $medal ?> <strong><?= (int)($p['posicion_display'] ?? 0) ?>°</strong> <?= htmlspecialchars($p['nombre'] ?? '') ?>
                <?php if (!empty($p['club_nombre'])): ?>(<?= htmlspecialchars($p['club_nombre']) ?>)<?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="p-4 border-top bg-light d-flex flex-wrap gap-2 justify-content-center">
            <a href="resultados.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-list me-1"></i>Ver todos los eventos
            </a>
            <a href="clasificacion.php?torneo_id=<?= $torneo_id ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-chart-bar me-1"></i>Clasificación móvil
            </a>
            <?php if (($torneo_data['total_fotos'] ?? 0) > 0): ?>
            <a href="galeria_fotos.php?torneo_id=<?= $torneo_id ?>" class="btn btn-outline-info btn-sm">
                <i class="fas fa-images me-1"></i>Galería
            </a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(AppHelpers::url('landing-spa.php')) ?>" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-home me-1"></i>Inicio
            </a>
        </div>
    </div>
</div>
</body>
</html>
