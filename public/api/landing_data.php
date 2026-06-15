<?php
/**
 * API Landing - Datos para la SPA de la landing page
 * GET: Retorna todos los datos necesarios para renderizar la landing
 * Usa exclusivamente LandingDataService como fuente de datos.
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/UrlHelper.php';
require_once __DIR__ . '/../../lib/LandingDataService.php';
require_once __DIR__ . '/../../lib/PodiosAsociacionesLandingService.php';
require_once __DIR__ . '/../../lib/AsociacionesActivasLandingService.php';
require_once __DIR__ . '/../../lib/TournamentPhotoService.php';
require_once __DIR__ . '/../../lib/InvitacionesFvdWebService.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// URL base absoluta (misma que la web) para que logos e imágenes carguen en cualquier entorno
$baseUrl = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';
$entidadParam = isset($_GET['entidad']) ? (int)$_GET['entidad'] : 0;

/** TTL caché landing (segundos): TTFB bajo en calientes */
const LANDING_DATA_CACHE_TTL = 90;

/**
 * Clave de caché estable por entidad + usuario (la respuesta depende de ambos).
 */
function landingDataCacheKey(int $entidadParam): string
{
    $uid = 0;
    try {
        $uid = (int)(Auth::id() ?: 0);
    } catch (Throwable $e) {
        $uid = 0;
    }
    $role = '';
    try {
        $u = Auth::user();
        $role = (string)($u['role'] ?? '');
    } catch (Throwable $e) {
    }

    return 'landing_data_v2_' . hash('sha256', json_encode([$entidadParam, $uid, $role, 'galeria_v1', 'inv_vigencia_v1']));
}

function landingDataCacheGet(string $key): ?array
{
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($key);
        if (is_array($v) && isset($v['exp'], $v['payload']) && $v['exp'] >= time()) {
            return $v['payload'];
        }
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    if (!is_readable($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta) || !isset($meta['exp'], $meta['payload']) || $meta['exp'] < time()) {
        return null;
    }

    return $meta['payload'];
}

function landingDataCacheSet(string $key, array $payload): void
{
    $wrapped = ['exp' => time() + LANDING_DATA_CACHE_TTL, 'payload' => $payload];
    if (function_exists('apcu_store')) {
        @apcu_store($key, $wrapped, LANDING_DATA_CACHE_TTL + 5);
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    @file_put_contents($file, json_encode($wrapped), LOCK_EX);
}

function getAficheUrlApi($torneo, $baseUrl) {
    if (!empty($torneo['afiche'])) {
        $file = basename($torneo['afiche']);
        return $baseUrl . 'view_tournament_file.php?file=' . urlencode($file);
    }
    return null;
}

function getLogoOrganizacionUrlApi($evento, $baseUrl) {
    if (!empty($evento['organizacion_logo'])) {
        if (class_exists('AppHelpers')) {
            return AppHelpers::imageUrl($evento['organizacion_logo']);
        }
        return $baseUrl . 'view_image.php?path=' . rawurlencode($evento['organizacion_logo']);
    }
    return null;
}

function limpiarNombreTorneo($nombre) {
    if (empty($nombre)) return $nombre;
    $nombre = preg_replace('/\bmasivos?\b/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos\s*/i', ' ', $nombre);
    $nombre = preg_replace('/^Masivos\s+/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos$/i', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    return trim($nombre);
}

function enriquecerEvento(&$ev, $baseUrl) {
    $ev['logo_url'] = getLogoOrganizacionUrlApi($ev, $baseUrl);
    $ev['afiche_url'] = getAficheUrlApi($ev, $baseUrl);
    $ev['nombre_limpio'] = limpiarNombreTorneo($ev['nombre'] ?? '');
    $tid = (int) ($ev['id'] ?? 0);
    $ev['detalle_url'] = $tid > 0 ? ($baseUrl . 'torneo_detalle.php?torneo_id=' . $tid) : null;
    $ev['total_fotos'] = (int) ($ev['total_fotos'] ?? 0);
    $primera = trim((string) ($ev['primera_foto'] ?? ''));
    if ($primera !== '') {
        $ev['portada_url'] = TournamentPhotoService::publicUrl($primera, $baseUrl);
    } elseif (!empty($ev['afiche_url'])) {
        $ev['portada_url'] = $ev['afiche_url'];
    } else {
        $ev['portada_url'] = $ev['logo_url'] ?? null;
    }
}

try {
    $pdo = DB::pdo();
    $user = Auth::user();
    $skipCache = isset($_GET['nocache']) && $_GET['nocache'] === '1';

    if (!$skipCache) {
        $cacheKey = landingDataCacheKey($entidadParam);
        $cachedPayload = landingDataCacheGet($cacheKey);
        if (is_array($cachedPayload)) {
            $cachedPayload['csrf_token'] = CSRF::token();
            $cachedPayload['user'] = $user ? [
                'id' => Auth::id() ?: null,
                'nombre' => $user['nombre'] ?? $user['username'] ?? '',
                'username' => $user['username'] ?? '',
            ] : null;
            header('X-Landing-Cache: HIT');
            echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $service = new LandingDataService($pdo);
    $podios_asociaciones = (new PodiosAsociacionesLandingService($pdo))->construirResumen();
    $asociaciones_activas = (new AsociacionesActivasLandingService($pdo))->listarParaLanding();
    foreach ($asociaciones_activas as &$asocRow) {
        $logoPath = AppHelpers::normalizeStoragePath((string) ($asocRow['logo_path'] ?? ''));
        $logoUrl = $logoPath !== '' ? AppHelpers::publicImageUrl($logoPath, $baseUrl) : '';
        $asocRow['logo_url'] = $logoUrl !== '' ? $logoUrl : null;
    }
    unset($asocRow);

    // Eventos realizados (LandingDataService)
    $eventos_realizados = $service->getEventosRealizados(50);
    foreach ($eventos_realizados as &$ev) {
        enriquecerEvento($ev, $baseUrl);
    }
    unset($ev);

    // Eventos futuros (LandingDataService)
    $eventos_todos_futuros = $service->getProximosEventos(500);
    $eventos_futuros = array_values(array_filter($eventos_todos_futuros, fn($e) => ($e['es_evento_masivo'] ?? null) === null || $e['es_evento_masivo'] === '' || in_array((int)($e['es_evento_masivo'] ?? 0), [0, 4])));
    $eventos_inscripcion_linea = array_values(array_filter($eventos_todos_futuros, fn($e) => in_array((int)($e['es_evento_masivo'] ?? 0), [1, 2, 3])));
    $eventos_masivos = $eventos_inscripcion_linea;
    $eventos_privados = array_values(array_filter($eventos_todos_futuros, fn($e) => (int)($e['es_evento_masivo'] ?? 0) === 4));

    foreach (array_merge($eventos_futuros, $eventos_masivos, $eventos_privados) as &$ev) {
        enriquecerEvento($ev, $baseUrl);
    }
    unset($ev);

    // Entidades con eventos (LandingDataService)
    $entidades_con_eventos = $service->getEntidadesConEventos();

    // Eventos por entidad/club (filtro de usuario o parámetro)
    $eventos_mi_entidad = [];
    $filtro_aplicado_entidad = '';
    $entidad_nombre_usuario = '';
    $entidad_filtro = $entidadParam;

    if ($user && $entidadParam === 0) {
        $user_entidad = (int)($user['entidad'] ?? 0);
        $user_club_id = (int)($user['club_id'] ?? 0);
        $user_role = $user['role'] ?? 'usuario';
        if ($user_role === 'admin_club' || $user_role === 'admin_torneo') {
            $entidad_filtro = $user_entidad;
        } elseif ($user_club_id > 0) {
            $org_id = $service->getOrgIdPorClub($user_club_id);
            if ($org_id) {
                $eventos_mi_entidad = $service->getProximosEventosPorOrganizaciones([$org_id], 12);
                try {
                    $stmt = $pdo->prepare("SELECT nombre FROM clubes WHERE id = ? LIMIT 1");
                    $stmt->execute([$user_club_id]);
                    $club_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($club_data) {
                        $entidad_nombre_usuario = $club_data['nombre'];
                        $filtro_aplicado_entidad = "de su club: " . $club_data['nombre'];
                    }
                } catch (Exception $e) {}
            }
            $entidad_filtro = 0;
        } elseif ($user_entidad > 0) {
            $entidad_filtro = $user_entidad;
        }
    } elseif ($entidadParam > 0) {
        $entidad_filtro = $entidadParam;
    }

    if ($entidad_filtro > 0 && empty($eventos_mi_entidad)) {
        try {
            $stmt = $pdo->prepare("SELECT nombre FROM entidad WHERE id = ? LIMIT 1");
            $stmt->execute([$entidad_filtro]);
            $ent_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ent_data) {
                $entidad_nombre_usuario = $ent_data['nombre'];
                $filtro_aplicado_entidad = "de la entidad: " . $entidad_nombre_usuario;
            }
        } catch (Exception $e) {}
        $eventos_mi_entidad = $service->getProximosEventosPorEntidad($entidad_filtro, 12);
    }

    foreach ($eventos_mi_entidad as &$ev) {
        enriquecerEvento($ev, $baseUrl);
    }
    unset($ev);

    // Calendario (LandingDataService)
    $eventos_calendario = $service->getEventosCalendario();
    $eventos_por_fecha = [];
    foreach ($eventos_calendario as $ev) {
        $fecha_key = date('Y-m-d', strtotime($ev['fechator'] ?? ''));
        if (!isset($eventos_por_fecha[$fecha_key])) {
            $eventos_por_fecha[$fecha_key] = [];
        }
        enriquecerEvento($ev, $baseUrl);
        $eventos_por_fecha[$fecha_key][] = $ev;
    }

    // Comentarios aprobados
    $comentarios = [];
    try {
        $comentarios = $pdo->query("
            SELECT c.*, u.username as usuario_username, u.nombre as usuario_nombre
            FROM comentariossugerencias c
            LEFT JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.estatus = 'aprobado'
            ORDER BY c.fecha_creacion DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Logos de clientes: desde clubes + upload/logos (fallback) + upload/logos_clientes/
    // Incluir 'url' absoluta para que el frontend muestre la imagen sin depender de baseUrl
    $logos_clientes = [];
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, logo FROM clubes WHERE logo IS NOT NULL AND logo != '' AND (estatus = 1 OR estatus = '1') ORDER BY nombre ASC");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = trim((string)($row['logo'] ?? ''));
            if ($path !== '') {
                $url = class_exists('AppHelpers') ? AppHelpers::imageUrl($path) : ($baseUrl . 'view_image.php?path=' . rawurlencode($path));
                $logos_clientes[] = ['nombre' => $row['nombre'] ?? 'Club', 'path' => $path, 'url' => $url];
            }
        }
    } catch (Exception $e) {}
    if (empty($logos_clientes)) {
        $upload_logos_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'logos';
        $extensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
        if (is_dir($upload_logos_dir)) {
            foreach (new DirectoryIterator($upload_logos_dir) as $f) {
                if ($f->isDot() || !$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $path = 'upload/logos/' . $f->getFilename();
                    $url = class_exists('AppHelpers') ? AppHelpers::imageUrl($path) : ($baseUrl . 'view_image.php?path=' . rawurlencode($path));
                    $logos_clientes[] = ['nombre' => pathinfo($f->getFilename(), PATHINFO_FILENAME), 'path' => $path, 'url' => $url];
                }
            }
        }
    }
    $logos_clientes_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'logos_clientes';
    if (is_dir($logos_clientes_dir)) {
        foreach (new DirectoryIterator($logos_clientes_dir) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                $path = 'upload/logos_clientes/' . $f->getFilename();
                $url = class_exists('AppHelpers') ? AppHelpers::imageUrl($path) : ($baseUrl . 'view_image.php?path=' . rawurlencode($path));
                $logos_clientes[] = ['nombre' => pathinfo($f->getFilename(), PATHINFO_FILENAME), 'path' => $path, 'url' => $url];
            }
        }
    }

    // Documentos oficiales de dominó (upload/documentos_oficiales/)
    $documentos_oficiales = [];
    $doc_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'documentos_oficiales';
    $doc_extensions = ['pdf', 'doc', 'docx'];
    if (is_dir($doc_dir)) {
        foreach (new DirectoryIterator($doc_dir) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, $doc_extensions, true)) {
                $nombre = pathinfo($f->getFilename(), PATHINFO_FILENAME);
                $path_rel = 'upload/documentos_oficiales/' . $f->getFilename();
                $documentos_oficiales[] = ['titulo' => $nombre, 'path' => $path_rel, 'archivo' => $f->getFilename()];
            }
        }
    }

    $invitaciones_fvd = InvitacionesFvdWebService::listarActivos($pdo);
    foreach ($invitaciones_fvd as &$invRow) {
        if (!empty($invRow['fecha_limite'])) {
            $invRow['fecha_limite'] = date('d/m/Y', strtotime((string) $invRow['fecha_limite']));
        }
    }
    unset($invRow);

    $galeria_destacada = [];
    foreach ($service->getGaleriaDestacada(12) as $fotoRow) {
        $url = TournamentPhotoService::publicUrl((string) ($fotoRow['ruta_imagen'] ?? ''), $baseUrl);
        if ($url === '') {
            continue;
        }
        $tid = (int) ($fotoRow['torneo_id'] ?? 0);
        $galeria_destacada[] = [
            'id' => (int) ($fotoRow['id'] ?? 0),
            'url' => $url,
            'titulo' => (string) ($fotoRow['titulo'] ?? ''),
            'torneo_id' => $tid,
            'torneo_nombre' => (string) ($fotoRow['torneo_nombre'] ?? ''),
            'organizacion_nombre' => (string) ($fotoRow['organizacion_nombre'] ?? ''),
            'detalle_url' => $tid > 0 ? ($baseUrl . 'torneo_detalle.php?torneo_id=' . $tid) : null,
        ];
    }

    $csrf_token = CSRF::token();

    $response = [
        'success' => true,
        'base_url' => $baseUrl,
        'user' => $user ? [
            'id' => Auth::id() ?: null,
            'nombre' => $user['nombre'] ?? $user['username'] ?? '',
            'username' => $user['username'] ?? '',
        ] : null,
        'csrf_token' => $csrf_token,
        'eventos_realizados' => $eventos_realizados,
        'eventos_futuros' => $eventos_futuros,
        'eventos_masivos' => $eventos_masivos,
        'eventos_inscripcion_linea' => $eventos_inscripcion_linea,
        'eventos_privados' => $eventos_privados,
        'entidades_con_eventos' => $entidades_con_eventos,
        'eventos_mi_entidad' => $eventos_mi_entidad,
        'eventos_por_fecha' => $eventos_por_fecha,
        'comentarios' => $comentarios,
        'entidad_seleccionada' => $entidadParam,
        'filtro_aplicado_entidad' => $filtro_aplicado_entidad,
        'entidad_nombre_usuario' => $entidad_nombre_usuario,
        'logos_clientes' => $logos_clientes,
        'documentos_oficiales' => $documentos_oficiales,
        'invitaciones_fvd' => $invitaciones_fvd,
        'podios_asociaciones' => $podios_asociaciones,
        'asociaciones_activas' => $asociaciones_activas,
        'galeria_destacada' => $galeria_destacada,
    ];

    if (!$skipCache) {
        $toStore = $response;
        $toStore['csrf_token'] = '';
        $toStore['user'] = null;
        landingDataCacheSet(landingDataCacheKey($entidadParam), $toStore);
    }

    header('X-Landing-Cache: MISS');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("API landing_data: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar los datos',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
