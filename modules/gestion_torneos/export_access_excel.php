<?php

declare(strict_types=1);

/**
 * Descarga Excel para Microsoft Access (inscritos + partidas).
 * GET: torneo_id, tipo=inscritos|partidas|ambos
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/AccessExportService.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneoId = (int) ($_GET['torneo_id'] ?? 0);
$tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'ambos')));

$pdo = DB::pdo();
$preferido = $torneoId > 0 ? $torneoId : 1;
$torneo = AccessExportService::resolverTorneoActivo($pdo, $preferido);
if (!$torneo || ($torneoId > 0 && !Auth::canAccessTournament($torneoId))) {
    http_response_code(403);
    exit('Acceso denegado');
}
$tid = (int) $torneo['id'];

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fvd_access_' . $tid . '_' . getmypid();
mkdir($tmpDir, 0755, true);

try {
    $res = AccessExportService::generarArchivosAccess($pdo, $tid, $tmpDir);

    if ($tipo === 'inscritos') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="inscritos para access.xls"');
        readfile($res['inscritos']);
        exit;
    }
    if ($tipo === 'partidas') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="partidas para access.xls"');
        readfile($res['partidas']);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZipArchive no disponible. Descargue tipo=inscritos o tipo=partidas.');
    }

    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'access_export.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear ZIP.');
    }
    $zip->addFile($res['inscritos'], 'inscritos para access.xls');
    $zip->addFile($res['partidas'], 'partidas para access.xls');
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="access_torneo_' . $tid . '.zip"');
    header('Content-Length: ' . (string) filesize($zipPath));
    readfile($zipPath);
} finally {
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
