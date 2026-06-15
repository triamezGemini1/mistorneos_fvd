<?php
/**
 * Resumen público del jugador en un torneo (solo estadísticas generales).
 * No muestra trayectoria partida a partida (reservada al panel admin).
 * Acceso: resumen_jugador.php?torneo_id=X&id_usuario=Y
 */

header('Cache-Control: public, max-age=60, stale-while-revalidate=120');

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';
require_once __DIR__ . '/../lib/InscritosReporteStatsHelper.php';

$torneo_id = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
$id_usuario = isset($_GET['id_usuario']) ? (int) $_GET['id_usuario'] : 0;

$publicBase = rtrim(AppHelpers::getPublicUrl(), '/');

if ($torneo_id <= 0 || $id_usuario <= 0) {
    header('Location: ' . $publicBase . '/landing-spa.php');
    exit;
}

$pdo = DB::pdo();
$torneo = null;
$inscrito = null;
$error = null;

try {
    $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? LIMIT 1');
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$torneo || !TournamentScopeHelper::canAccessResultsPublicly($torneo)) {
        header('Location: ' . $publicBase . '/resultados.php');
        exit;
    }

    $whereActivo = InscritosHelper::sqlWhereNoRetiradoConAlias('i');
    $colsGff = InscritosReporteStatsHelper::expresionesSelectClasificacion('i');

    $stmt = $pdo->prepare("
        SELECT i.*, COALESCE(u.nombre, u.username) AS nombre_completo, u.cedula, u.sexo,
               c.nombre AS club_nombre,
               {$colsGff['ganadas_por_forfait']}
        FROM inscritos i
        LEFT JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ? AND i.id_usuario = ? AND {$whereActivo}
        LIMIT 1
    ");
    $stmt->execute([$torneo_id, $id_usuario]);
    $inscrito = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inscrito) {
        header('Location: ' . $publicBase . '/evento_resultados.php?torneo_id=' . $torneo_id);
        exit;
    }
} catch (Throwable $e) {
    error_log('resumen_jugador.php: ' . $e->getMessage());
    $error = 'No se pudo cargar el resumen del jugador.';
}

$url_retorno = $publicBase . '/evento_resultados.php?torneo_id=' . $torneo_id;
$torneo_nombre = $torneo['nombre'] ?? 'Torneo';
$nombre_jugador = $inscrito['nombre_completo'] ?? '—';
$posicion = (int) ($inscrito['posicion'] ?? 0);
$ganados = (int) ($inscrito['ganados'] ?? 0);
$perdidos = (int) ($inscrito['perdidos'] ?? 0);
$efectividad = (int) ($inscrito['efectividad'] ?? 0);
$puntos = (int) ($inscrito['puntos'] ?? 0);
$ptosrnk = (int) ($inscrito['ptosrnk'] ?? 0);
$gff = (int) ($inscrito['ganadas_por_forfait'] ?? $inscrito['gff'] ?? 0);
$club = $inscrito['club_nombre'] ?? '—';
$fecha_torneo = !empty($torneo['fechator']) ? date('d/m/Y', strtotime((string) $torneo['fechator'])) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title>Resumen — <?= htmlspecialchars($nombre_jugador) ?> · <?= htmlspecialchars($torneo_nombre) ?></title>
    <link href="<?= htmlspecialchars(AppHelpers::publicAssetUrl('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(AppHelpers::publicAssetUrl('vendor/fontawesome/css/all.min.css')) ?>" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #f8fafc;
        }
        .card-resumen {
            background: rgba(255, 255, 255, 0.98);
            color: #1e293b;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            max-width: 720px;
            margin: 0 auto;
        }
        .header-evento {
            background: linear-gradient(135deg, #0f172a 0%, #1e40af 100%);
            color: #fff;
            padding: 1.5rem 1.75rem;
            text-align: center;
        }
        .header-evento h4, .header-evento h5 { margin: 0; }
        .header-evento .sub { opacity: 0.9; font-size: 0.95rem; margin-top: 0.35rem; }
        .btn-volver {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .btn-volver:hover { background: rgba(255, 255, 255, 0.25); color: #fff; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        @media (min-width: 576px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        .stat-box {
            text-align: center;
            padding: 1rem 0.75rem;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .stat-box .num {
            display: block;
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        .stat-box .lbl {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-top: 0.25rem;
        }
        .info-list .row-line {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.65rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-list .row-line:last-child { border-bottom: 0; }
        .aviso-publico {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="card-resumen">
        <div class="header-evento">
            <div class="d-flex justify-content-between align-items-center mb-3 text-start">
                <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn btn-sm btn-volver">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
                <div class="flex-grow-1 text-center px-2">
                    <?= AppHelpers::appLogo('', 'La Estación del Dominó', 36) ?>
                </div>
                <span style="width: 88px;" aria-hidden="true"></span>
            </div>
            <h4><i class="fas fa-user me-2"></i>Resumen del jugador</h4>
            <h5 class="mt-2"><?= htmlspecialchars($nombre_jugador) ?></h5>
            <p class="sub mb-0"><?= htmlspecialchars($torneo_nombre) ?><?= $fecha_torneo !== '' ? ' · ' . $fecha_torneo : '' ?></p>
        </div>

        <div class="p-4">
            <?php if ($error !== null): ?>
                <div class="alert alert-danger mb-0"><?= htmlspecialchars($error) ?></div>
            <?php else: ?>
                <div class="aviso-publico mb-4">
                    <i class="fas fa-info-circle me-1"></i>
                    Consulta pública: solo se muestran los resultados generales del torneo.
                </div>

                <div class="info-list mb-4">
                    <div class="row-line"><span class="text-muted">ID FVD</span><strong><?= $id_usuario ?></strong></div>
                    <div class="row-line"><span class="text-muted">Club</span><strong><?= htmlspecialchars($club) ?></strong></div>
                    <div class="row-line"><span class="text-muted">Posición</span><strong><?= $posicion > 0 ? $posicion : '—' ?></strong></div>
                </div>

                <h6 class="text-muted text-uppercase fw-semibold mb-3 text-center" style="letter-spacing:0.05em;">Estadísticas generales</h6>
                <div class="stats-grid">
                    <div class="stat-box">
                        <span class="num text-success"><?= $ganados ?></span>
                        <span class="lbl">Ganados</span>
                    </div>
                    <div class="stat-box">
                        <span class="num text-danger"><?= $perdidos ?></span>
                        <span class="lbl">Perdidos</span>
                    </div>
                    <div class="stat-box">
                        <span class="num"><?= $gff ?></span>
                        <span class="lbl">GFF</span>
                    </div>
                    <div class="stat-box">
                        <span class="num"><?= $efectividad ?></span>
                        <span class="lbl">Efectividad</span>
                    </div>
                    <div class="stat-box">
                        <span class="num"><?= $puntos ?></span>
                        <span class="lbl">Puntos</span>
                    </div>
                    <div class="stat-box">
                        <span class="num text-primary"><?= $ptosrnk ?></span>
                        <span class="lbl">Pts. ranking</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-4 border-top bg-light text-center">
            <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-list-ol me-1"></i>Ver clasificación del evento
            </a>
            <a href="<?= htmlspecialchars(AppHelpers::url('landing-spa.php')) ?>" class="btn btn-outline-secondary btn-sm ms-2">
                <i class="fas fa-home me-1"></i>Inicio
            </a>
        </div>
    </div>
</div>
</body>
</html>
