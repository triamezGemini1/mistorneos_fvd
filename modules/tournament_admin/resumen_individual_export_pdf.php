<?php
/**
 * PDF del resumen individual de un jugador (Letter, a solicitud).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ResumenIndividualPdfHtml.php';

$torneoId = (int) ($_GET['torneo_id'] ?? 0);
$inscritoId = (int) ($_GET['inscrito_id'] ?? 0);

if ($torneoId <= 0 || $inscritoId <= 0) {
    http_response_code(400);
    exit('Parámetros inválidos');
}

$user = Auth::user();
$role = (string) ($user['role'] ?? '');
if ($role === 'usuario') {
    if (Auth::id() !== $inscritoId) {
        http_response_code(403);
        exit('Acceso denegado');
    }
} else {
    Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
    if (! Auth::canAccessTournament($torneoId)) {
        http_response_code(403);
        exit('Acceso denegado');
    }
}

define('TORNEO_GESTION_SKIP_AUTH', true);
define('TORNEO_GESTION_SKIP_ROUTER', true);
require_once __DIR__ . '/../torneo_gestion.php';

try {
    $viewData = obtenerDatosResumenIndividual($torneoId, $inscritoId);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Jugador no encontrado en este torneo');
}

$html = ResumenIndividualPdfHtml::render($viewData);
$inscrito = (array) ($viewData['inscrito'] ?? []);
$slugNombre = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($inscrito['nombre_completo'] ?? 'jugador'));
$baseName = 'resumen_individual_' . $torneoId . '_' . $inscritoId . '_' . trim($slugNombre, '_') . '_' . date('Y-m-d');

$autoload = __DIR__ . '/../../vendor/autoload.php';
$dompdfOk = is_file($autoload) && is_readable($autoload);

if ($dompdfOk) {
    try {
        if (! class_exists(\Dompdf\Dompdf::class, false)) {
            require_once $autoload;
        }
        if (! class_exists(\Dompdf\Dompdf::class, false)) {
            $dompdfOk = false;
        }
    } catch (Throwable $e) {
        $dompdfOk = false;
    }
}

if ($dompdfOk) {
    try {
        @ini_set('memory_limit', '256M');
        @set_time_limit(120);
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        while (ob_get_level()) {
            ob_end_clean();
        }
        $dompdf->stream($baseName . '.pdf', ['Attachment' => true]);
        exit;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[resumen_individual_export_pdf] ' . $e->getMessage());
        }
        $dompdfOk = false;
    }
}

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $baseName . '_imprimir.html"');
echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . htmlspecialchars($baseName, ENT_QUOTES, 'UTF-8') . '</title>';
echo '<style>@page{size:letter portrait;margin:10mm}body{font-family:DejaVu Sans,sans-serif;padding:0;margin:0}</style></head><body>';
echo '<p style="background:#fff3cd;padding:10px;border:1px solid #856404"><strong>PDF no disponible en el servidor.</strong> ';
echo 'Use <strong>Imprimir → Guardar como PDF</strong> o ejecute <code>composer install</code>.</p>';
echo $html;
echo '</body></html>';
exit;
