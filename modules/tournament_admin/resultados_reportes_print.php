<?php
/**
 * Impresión por tipo (mismo contenido que PDF). Letter. tipo en GET. tipo=todos: todos los bloques de clasificación/ranking.
 */
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/ReportReturnNavigation.php';
require_once __DIR__ . '/../../lib/ResultadosReportesPrintHtml.php';

$tipo = preg_replace('/[^a-z_]/', '', (string)($_GET['tipo'] ?? 'consolidado'));
$allowed = ['por_club', 'clubes_resumido', 'clubes_detallado', 'general', 'posiciones', 'equipos_resumido', 'equipos_detallado', 'consolidado', 'todos'];
if (!in_array($tipo, $allowed, true)) {
    $tipo = 'consolidado';
}
if ($tipo === 'por_club') {
    $tipo = 'clubes_detallado';
}
$esEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
if ($tipo !== 'todos') {
    if ($tipo === 'general' && !$esEquipos) {
        $tipo = 'consolidado';
    }
    if (in_array($tipo, ['equipos_resumido', 'equipos_detallado'], true) && !$esEquipos) {
        $tipo = 'consolidado';
    }
}

$pdo = DB::pdo();
$generoGet = isset($_GET['genero']) ? (string) $_GET['genero'] : null;
$esc = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$titles = [
    'clubes_resumido' => 'Clubes — resumido',
    'clubes_detallado' => 'Clubes — detallado',
    'general' => 'Clasificación con equipos',
    'posiciones' => 'Clasificación general',
    'equipos_resumido' => 'Equipos — resumido',
    'equipos_detallado' => 'Equipos — detallado',
    'consolidado' => 'Reporte consolidado',
    'todos' => 'Todos los reportes (clasificación y ranking)',
];
$title = $titles[$tipo] ?? 'Reporte';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $esc($title) ?> — <?= $esc($torneo['nombre'] ?? '') ?></title>
    <style>
        @page { size: letter portrait; margin: 12mm; }
        @media print { .no-print { display: none !important; } }
        body { font-family: system-ui, sans-serif; font-size: 10pt; margin: 12px; }
        h1 { font-size: 14pt; margin: 0 0 6px 0; }
        h2 { font-size: 11pt; margin: 12px 0 4px 0; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
        th, td { border: 1px solid #666; padding: 4px 6px; }
        th { background: #eee; }
        td.num { text-align: center; }
        .meta { font-size: 9pt; color: #444; margin-bottom: 8px; }
        .club-block { page-break-inside: avoid; margin-bottom: 12px; }
        .no-print { margin-bottom: 12px; }
        .no-print a, .no-print button {
            display: inline-block; margin-right: 8px; margin-bottom: 8px;
            padding: 10px 16px; font-weight: 700; color: #000 !important;
            background: #fde68a; border: 2px solid #000; border-radius: 8px; text-decoration: none;
        }
        .salto-reporte { page-break-before: always; }
        <?= ResultadosReportesPrintHtml::cssZebraPrint() ?>
    </style>
</head>
<body>
<div class="no-print">
    <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    <a href="<?= $esc(ReportReturnNavigation::getReturnAbsoluteUrl()) ?>">Volver a pantalla anterior</a>
    <a href="<?= $esc(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes', 'torneo_id' => (int)$torneo_id, 'genero' => ResultadosReporteData::generoFiltroDesdeParametro($generoGet)])) ?>">Volver a reportes</a>
    <?php
    $origen = [
        'clubes_resumido' => 'resultados_por_club',
        'clubes_detallado' => 'resultados_por_club',
        'general' => 'resultados_general',
        'posiciones' => 'posiciones',
        'equipos_resumido' => 'resultados_equipos_resumido',
        'equipos_detallado' => 'resultados_equipos_detallado',
        'consolidado' => 'resultados_reportes',
        'todos' => 'resultados_reportes',
    ];
    $act = $origen[$tipo] ?? 'resultados_reportes';
    ?>
    <a href="<?= $esc(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => $act, 'torneo_id' => (int)$torneo_id])) ?>">Volver a la vista en torneo</a>
</div>

<?php
if ($tipo === 'todos') {
    $partes = ResultadosReportesPrintHtml::tiposParaTodos($esEquipos);
    $primero = true;
    foreach ($partes as $subTipo) {
        if (!$primero) {
            echo '<div class="salto-reporte"></div>';
        }
        $primero = false;
        echo ResultadosReportesPrintHtml::renderBody($pdo, (int)$torneo_id, $torneo, $subTipo, $esc, $generoGet);
    }
} else {
    echo ResultadosReportesPrintHtml::renderBody($pdo, (int)$torneo_id, $torneo, $tipo, $esc, $generoGet);
}
?>
</body>
</html>
