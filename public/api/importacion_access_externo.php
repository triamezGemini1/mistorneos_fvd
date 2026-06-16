<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/session_start_early.php';
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ImportacionAccessExternoService.php';
require_once __DIR__ . '/../../lib/TournamentAdminAccess.php';

header('Content-Type: application/json; charset=utf-8');

TournamentAdminAccess::requireFullTorneoAdminJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido. Recargue la página.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$torneoId = (int) ($_POST['torneo_id'] ?? 0);

if ($torneoId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Seleccione un torneo activo.']);
    exit;
}

$pdo = DB::pdo();
$stT = $pdo->prepare('SELECT id, nombre, modalidad, fechator FROM tournaments WHERE id = ? LIMIT 1');
$stT->execute([$torneoId]);
$torneo = $stT->fetch(PDO::FETCH_ASSOC);
if (!$torneo) {
    echo json_encode(['success' => false, 'error' => 'Torneo no encontrado.']);
    exit;
}

$modalidad = (int) ($torneo['modalidad'] ?? 1);

try {
    switch ($action) {
        case 'verificar_atletas':
            $file = $_FILES['archivo_parejas'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Suba el archivo de parejas inscritas para verificar atletas.');
            }
            $leido = ImportacionAccessExternoService::leerArchivo((string) $file['tmp_name'], (string) $file['name']);
            if ($leido['error']) {
                throw new RuntimeException($leido['error']);
            }
            $cedulas = ImportacionAccessExternoService::extraerCedulasParejas($leido['rows']);
            $stats = ImportacionAccessExternoService::sincronizarAtletasParaImportacion($pdo, $cedulas, false);
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;

        case 'sincronizar_atletas':
            $file = $_FILES['archivo_parejas'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Suba el archivo de parejas inscritas.');
            }
            $leido = ImportacionAccessExternoService::leerArchivo((string) $file['tmp_name'], (string) $file['name']);
            if ($leido['error']) {
                throw new RuntimeException($leido['error']);
            }
            $cedulas = ImportacionAccessExternoService::extraerCedulasParejas($leido['rows']);
            $stats = ImportacionAccessExternoService::sincronizarAtletasParaImportacion($pdo, $cedulas, true);
            echo json_encode(['success' => !empty($stats['ok']), 'stats' => $stats]);
            exit;

        case 'verificar_padron_completo':
            $stats = ImportacionAccessExternoService::sincronizarPadronCompletoAtletas($pdo, false);
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;

        case 'sincronizar_padron_completo':
            $stats = ImportacionAccessExternoService::sincronizarPadronCompletoAtletas($pdo, true);
            echo json_encode(['success' => !empty($stats['ok']), 'stats' => $stats]);
            exit;

        case 'analizar_parejas':
            $file = $_FILES['archivo_parejas'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Suba el archivo de parejas inscritas.');
            }
            $leido = ImportacionAccessExternoService::leerArchivo((string) $file['tmp_name'], (string) $file['name']);
            if ($leido['error']) {
                throw new RuntimeException($leido['error']);
            }
            $stats = ImportacionAccessExternoService::analizarParejasInscritas($pdo, $torneoId, $leido['rows']);
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;

        case 'analizar_parti':
            $fileP = $_FILES['archivo_parti'] ?? null;
            $filePar = $_FILES['archivo_parejas_ref'] ?? null;
            if (!$fileP || ($fileP['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Suba el archivo parti2017.');
            }
            $leidoP = ImportacionAccessExternoService::leerArchivo((string) $fileP['tmp_name'], (string) $fileP['name']);
            if ($leidoP['error']) {
                throw new RuntimeException($leidoP['error']);
            }
            $extra = null;
            $parejasRowsRef = null;
            if ($filePar && ($filePar['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $leidoPar = ImportacionAccessExternoService::leerArchivo((string) $filePar['tmp_name'], (string) $filePar['name']);
                if (!$leidoPar['error']) {
                    $parejasRowsRef = $leidoPar['rows'];
                    $extra = ImportacionAccessExternoService::extraerNumfvdParejas($pdo, $parejasRowsRef);
                }
            }
            $stats = ImportacionAccessExternoService::analizarParti2017($pdo, $torneoId, $leidoP['rows'], $extra, $parejasRowsRef);
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;

        case 'analizar_clasiequi':
            if (!ImportacionAccessExternoService::requiereClasiequi($modalidad)) {
                echo json_encode(['success' => true, 'stats' => ['ok' => true, 'mensaje' => 'No aplica (torneo individual).']]);
                exit;
            }
            $fileC = $_FILES['archivo_clasiequi'] ?? null;
            $filePar = $_FILES['archivo_parejas_ref'] ?? null;
            if (!$fileC || ($fileC['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Suba el archivo clasiequi.');
            }
            $leidoC = ImportacionAccessExternoService::leerArchivo((string) $fileC['tmp_name'], (string) $fileC['name']);
            if ($leidoC['error']) {
                throw new RuntimeException($leidoC['error']);
            }
            $statsC = ImportacionAccessExternoService::analizarClasiequi($pdo, $torneoId, $leidoC['rows'], $modalidad);
            $statsE = [
                'ok' => false,
                'equipos_incompletos' => ['Suba el archivo parejas inscritas para comparar con clasiequi.'],
                'equipos_incompletos_detalle' => [],
                'situaciones_detalle' => [
                    ImportacionAccessExternoService::situacionImportacion('parejas_ref_faltante', [
                        'explicacion' => 'No se subió parejas inscritas al analizar clasiequi; no es posible comparar equipos.',
                    ]),
                ],
            ];
            if ($filePar && ($filePar['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $leidoPar = ImportacionAccessExternoService::leerArchivo((string) $filePar['tmp_name'], (string) $filePar['name']);
                if (!$leidoPar['error']) {
                    $statsE = ImportacionAccessExternoService::analizarIntegridadEquipos(
                        $pdo,
                        $torneoId,
                        $modalidad,
                        $leidoPar['rows'],
                        $leidoC['rows']
                    );
                }
            }
            $statsC['integridad_equipos'] = $statsE;
            $statsC['situaciones_detalle'] = array_merge(
                $statsC['situaciones_detalle'] ?? [],
                $statsE['situaciones_detalle'] ?? []
            );
            $statsC['reporte_banca'] = $statsE['reporte_banca'] ?? ['total' => 0, 'por_asociacion' => [], 'detalle' => []];
            $statsC['ok'] = ($statsC['ok'] ?? false) && ($statsE['ok'] ?? false);
            echo json_encode(['success' => true, 'stats' => $statsC]);
            exit;

        case 'ejecutar':
            $reemplazarPartiresul = !empty($_POST['reemplazar_partiresul']);
            $reemplazarInscripcion = !empty($_POST['reemplazar_inscripcion']);
            $files = [
                'parejas' => $_FILES['archivo_parejas'] ?? null,
                'parti' => $_FILES['archivo_parti'] ?? null,
                'clasiequi' => $_FILES['archivo_clasiequi'] ?? null,
            ];
            if (!$files['parejas'] || ($files['parejas']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Falta archivo parejas inscritas.');
            }
            if (!$files['parti'] || ($files['parti']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Falta archivo parti2017.');
            }
            if (ImportacionAccessExternoService::requiereClasiequi($modalidad)
                && (!$files['clasiequi'] || ($files['clasiequi']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
                throw new RuntimeException('Falta archivo clasiequi para esta modalidad.');
            }

            $rowsP = ImportacionAccessExternoService::leerArchivo((string) $files['parejas']['tmp_name'], (string) $files['parejas']['name']);
            $rowsR = ImportacionAccessExternoService::leerArchivo((string) $files['parti']['tmp_name'], (string) $files['parti']['name']);
            if ($rowsP['error'] || $rowsR['error']) {
                throw new RuntimeException($rowsP['error'] ?: $rowsR['error']);
            }
            $rowsC = null;
            if (ImportacionAccessExternoService::requiereClasiequi($modalidad)) {
                $rowsC = ImportacionAccessExternoService::leerArchivo(
                    (string) $files['clasiequi']['tmp_name'],
                    (string) $files['clasiequi']['name']
                );
                if ($rowsC['error']) {
                    throw new RuntimeException($rowsC['error']);
                }
                $rowsC = $rowsC['rows'];
            }

            $userId = (int) (Auth::id() ?: 0);
            $res = ImportacionAccessExternoService::ejecutarImportacion(
                $pdo,
                $torneoId,
                $userId,
                $rowsP['rows'],
                $rowsR['rows'],
                $rowsC,
                $modalidad,
                $reemplazarPartiresul,
                $reemplazarInscripcion
            );
            echo json_encode(['success' => !empty($res['ok']), 'resultado' => $res]);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
