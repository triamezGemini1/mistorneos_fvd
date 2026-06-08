<?php
/**
 * Reporte de clasificación del torneo (ranking).
 * Página pública, optimizada para dispositivos móviles.
 * Acceso: clasificacion.php?torneo_id=X
 *
 * Nota: no usar declare(strict_types) aquí: debe ser lo primero tras <?php (sin comentarios previos)
 * y en algunos despliegues un BOM o orden incorrecto provoca fatal error.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/ResultadosReporteData.php';

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$highlight_user = isset($_GET['highlight_user']) ? (int)$_GET['highlight_user'] : 0;
$genero_get = isset($_GET['genero']) ? (string) $_GET['genero'] : null;
if ($torneo_id <= 0) {
    header('Location: ' . (rtrim(AppHelpers::getPublicUrl(), '/') . '/landing-spa.php'));
    exit;
}

$pdo = DB::pdo();
$torneo = null;
$clasificacion = [];

try {
    $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND estatus = 1');
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        header('Location: ' . (rtrim(AppHelpers::getPublicUrl(), '/') . '/landing-spa.php'));
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            i.id_usuario,
            i.posicion,
            i.ganados,
            i.perdidos,
            i.efectividad,
            i.puntos,
            i.ptosrnk,
            i.codigo_equipo,
            COALESCE(u.nombre, u.username) as nombre_jugador,
            u.sexo,
            c.nombre as club_nombre
        FROM inscritos i
        LEFT JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ?
        AND (i.estatus IN (1, 2, '1', '2', 'confirmado', 'solvente'))
        ORDER BY CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                 i.ganados DESC, i.efectividad DESC, i.puntos DESC, i.id_usuario ASC
    ");
    $stmt->execute([$torneo_id]);
    $clasificacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $genero_ranking = ResultadosReporteData::generoFiltroDesdeParametro($genero_get);
    $modalidadTor = (int) ($torneo['modalidad'] ?? 0);
    $clasificacion = ResultadosReporteData::aplicarRankingPorGenero($clasificacion, $genero_ranking, $modalidadTor);
} catch (Throwable $e) {
    error_log('clasificacion.php: ' . $e->getMessage());
}
$genero_ranking = $genero_ranking ?? 'M';

$base_public = rtrim(AppHelpers::getPublicUrl(), '/');
$url_retorno = $base_public . '/perfil_jugador.php?torneo_id=' . $torneo_id;
$logo_url = AppHelpers::getAppLogo();
$torneo_nombre = $torneo['nombre'] ?? 'Torneo';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0f172a">
    <title>Clasificación — <?= htmlspecialchars($torneo_nombre) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            font-size: 15px;
            padding: 12px;
            padding-bottom: 2rem;
        }
        .wrap { max-width: 480px; margin: 0 auto; }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .header img { height: 36px; width: auto; }
        .btn-retorno {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .btn-retorno:hover { background: #334155; color: #38bdf8; }
        h1 { font-size: 1.15rem; margin: 0 0 4px 0; color: #94a3b8; font-weight: 600; }
        .sub { font-size: 0.85rem; color: #64748b; margin-bottom: 16px; }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -12px; padding: 0 12px; }
        table { width: 100%; min-width: 320px; border-collapse: collapse; font-size: 0.95rem; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); }
        th { color: #94a3b8; font-weight: 600; font-size: 0.85rem; }
        td { color: #f1f5f9; }
        .pos { font-weight: 700; width: 2.2em; text-align: center; }
        .pos-1 { color: #fbbf24; }
        .pos-2 { color: #94a3b8; }
        .pos-3 { color: #d97706; }
        .num { text-align: right; white-space: nowrap; }
        .nombre { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        tr.row-highlight { background: rgba(248, 113, 113, 0.18) !important; outline: 2px solid #f87171; }
        .nombre-link { color: #f1f5f9; text-decoration: none; }
        .nombre-link:hover { color: #38bdf8; text-decoration: underline; }
        .empty { text-align: center; padding: 2rem; color: #64748b; }
        @media (min-width: 481px) {
            body { padding: 20px; }
            .wrap { box-shadow: 0 0 0 1px rgba(255,255,255,0.06); border-radius: 16px; padding: 20px; background: #0f172a; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="header">
            <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn-retorno"><i class="fas fa-arrow-left"></i> Retorno</a>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="La Estación del Dominó">
        </header>

        <h1><i class="fas fa-trophy" style="color: #38bdf8;"></i> Clasificación</h1>
        <p class="sub"><?= htmlspecialchars($torneo_nombre) ?></p>
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
            <a href="clasificacion.php?torneo_id=<?= (int) $torneo_id ?>&amp;genero=M" style="padding:8px 14px;border-radius:10px;text-decoration:none;font-size:0.9rem;<?= $genero_ranking === 'M' ? 'background:#2563eb;color:#fff;' : 'background:#1e293b;color:#94a3b8;border:1px solid rgba(255,255,255,0.12);' ?>">Masculino</a>
            <a href="clasificacion.php?torneo_id=<?= (int) $torneo_id ?>&amp;genero=F" style="padding:8px 14px;border-radius:10px;text-decoration:none;font-size:0.9rem;<?= $genero_ranking === 'F' ? 'background:#2563eb;color:#fff;' : 'background:#1e293b;color:#94a3b8;border:1px solid rgba(255,255,255,0.12);' ?>">Femenino</a>
        </div>

        <div class="table-wrap">
            <?php if (empty($clasificacion)): ?>
                <p class="empty">No hay clasificación disponible aún.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th class="pos">Pos</th>
                            <th>Jugador</th>
                            <th class="num">G</th>
                            <th class="num">P</th>
                            <th class="num">Efect.</th>
                            <th class="num">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pos = 0;
                        foreach ($clasificacion as $row):
                            $pos++;
                            $posClass = $pos <= 3 ? 'pos-' . $pos : '';
                            $uidRow = (int)($row['id_usuario'] ?? 0);
                            $hi = ($highlight_user > 0 && $uidRow === $highlight_user);
                        ?>
                        <tr<?= $hi ? ' class="row-highlight" id="jugador-highlight"' : '' ?>>
                            <td class="pos <?= $posClass ?>"><?= $pos ?></td>
                            <td class="nombre"><a href="resumen_jugador.php?torneo_id=<?= (int)$torneo_id ?>&id_usuario=<?= (int)($row['id_usuario'] ?? 0) ?>" class="nombre-link" title="<?= htmlspecialchars($row['nombre_jugador'] ?? '') ?>"><?= htmlspecialchars($row['nombre_jugador'] ?? '—') ?></a></td>
                            <td class="num"><?= (int)($row['ganados'] ?? 0) ?></td>
                            <td class="num"><?= (int)($row['perdidos'] ?? 0) ?></td>
                            <td class="num"><?= (int)($row['efectividad'] ?? 0) ?></td>
                            <td class="num"><strong><?= (int)($row['puntos'] ?? 0) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php if ($highlight_user > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('jugador-highlight');
    if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
});
</script>
<?php endif; ?>
</body>
</html>
