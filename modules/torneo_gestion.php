<?php
/**
 * Módulo de Gestión Completa de Torneos
 * Integra funcionalidades de:
 * - AdminTorneoController: Dashboard y gestión básica
 * - RondasController: Gestión de rondas, cuadrícula
 * - TorneoGestionController: Panel avanzado, resultados, posiciones, resumen individual, hojas de anotación
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';
require_once __DIR__ . '/../lib/TorneoCampoNumerico.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/TournamentActionHandler.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/RoundManagerHandler.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/TournamentStatusHandler.php';
require_once __DIR__ . '/../lib/FvdConfig.php';

// Carga solo funciones (p. ej. actualizarEstadisticasInscritos) sin sesión ni router: definir
// TORNEO_GESTION_SKIP_AUTH y TORNEO_GESTION_SKIP_ROUTER antes de incluir este archivo.
if (! defined('TORNEO_GESTION_SKIP_AUTH') || ! TORNEO_GESTION_SKIP_AUTH) {
    $current_user = Auth::user();
    $user_role = $current_user['role'] ?? '';
    $user_id = Auth::id();

    // Jugadores (usuario) solo pueden ver resumen_individual (el propio) y posiciones
    if ($user_role === 'usuario') {
        $action = $_GET['action'] ?? '';
        $torneo_id = (int)($_GET['torneo_id'] ?? 0);
        $inscrito_id = (int)($_GET['inscrito_id'] ?? 0);
        $allowed = ($torneo_id > 0 && in_array($action, ['resumen_individual', 'resumen_individual_pdf', 'posiciones'], true));
        if ($allowed && in_array($action, ['resumen_individual', 'resumen_individual_pdf'], true)) {
            $allowed = ($inscrito_id > 0 && $inscrito_id === $user_id);
        }
        if (! $allowed) {
            require_once __DIR__ . '/../lib/app_helpers.php';
            header('Location: ' . AppHelpers::url('user_portal.php'));
            exit;
        }
    } else {
        Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
    }

    $current_user = Auth::user();
    $user_role = $current_user['role'];
    $user_id = Auth::id();
    $is_admin_general = Auth::isAdminGeneral();
    $is_admin_torneo = Auth::isAdminTorneo();
    $is_admin_club = Auth::isAdminClub();

    if (Auth::isOperativoSoloAsociacion() && !defined('TORNEO_GESTION_OPERATIVO_VALIDATED')) {
        require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
        require_once __DIR__ . '/../lib/app_helpers.php';
        $accionOper = trim((string) ($_GET['action'] ?? 'index'));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $accionOper = trim((string) ($_POST['action'] ?? $accionOper));
        }
        $torneoOper = (int) ($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
        if (in_array($accionOper, ['', 'index', 'panel'], true)) {
            $redirQs = $torneoOper > 0 ? ['torneo_id' => $torneoOper] : [];
            if (!headers_sent()) {
                header('Location: ' . AppHelpers::dashboard('asociacion_panel', $redirQs));
                exit;
            }
            echo '<div class="alert alert-info m-3"><a href="' . htmlspecialchars(AppHelpers::dashboard('asociacion_panel', $redirQs)) . '">Ir al panel de asociación</a></div>';
            return;
        }
        if (!AsociacionAdminHelper::accionTorneoGestionPermitida($accionOper)) {
            $denyQs = $torneoOper > 0 ? ['torneo_id' => $torneoOper] : [];
            $denyQs['error'] = 'No tiene permiso para gestionar el torneo. Use el panel de asociación.';
            $urlPanel = AppHelpers::dashboard('asociacion_panel', $denyQs);
            if (!headers_sent()) {
                header('Location: ' . $urlPanel);
                exit;
            }
            echo '<div class="alert alert-warning m-3"><a href="' . htmlspecialchars($urlPanel) . '">Volver al panel de asociación</a></div>';
            return;
        }
        if ($torneoOper > 0 && !Auth::canAccessTournament($torneoOper)) {
            $urlPanel = AppHelpers::dashboard('asociacion_panel', [
                'error' => 'Torneo fuera del ámbito de su asociación.',
            ]);
            if (!headers_sent()) {
                header('Location: ' . $urlPanel);
                exit;
            }
            echo '<div class="alert alert-warning m-3"><a href="' . htmlspecialchars($urlPanel) . '">Volver al panel de asociación</a></div>';
            return;
        }
    }
} else {
    $current_user = [];
    $user_role = '';
    $user_id = 0;
    $is_admin_general = false;
    $is_admin_torneo = false;
    $is_admin_club = false;
}

// Función auxiliar para determinar la URL base según el contexto
function getBaseUrl() {
    $script = basename($_SERVER['PHP_SELF'] ?? '');
    if ($script === 'panel_torneo.php') return 'panel_torneo.php';
    if ($script === 'admin_torneo.php') return 'admin_torneo.php';
    return 'index.php?page=torneo_gestion';
}

// Función auxiliar para construir URLs de redirección
function buildRedirectUrl($action, $params = []) {
    if ($action === 'panel' && class_exists('Auth') && Auth::isOperativoSoloAsociacion()) {
        $tid = (int) ($params['torneo_id'] ?? 0);
        require_once __DIR__ . '/../lib/app_helpers.php';
        return AppHelpers::dashboard('asociacion_panel', $tid > 0 ? ['torneo_id' => $tid] : []);
    }

    $base = getBaseUrl();
    $url = $base;
    
    $usa_script_simple = ($base === 'admin_torneo.php' || $base === 'panel_torneo.php');
    if ($usa_script_simple) {
        $url .= '?action=' . $action;
        foreach ($params as $key => $value) {
            $url .= '&' . $key . '=' . urlencode($value);
        }
    } else {
        $url .= '&action=' . $action;
        foreach ($params as $key => $value) {
            $url .= '&' . $key . '=' . urlencode($value);
        }
    }
    
    return $url;
}

/**
 * Verifica dinámicamente si una columna existe en tournaments.
 */
function tournamentsColumnExists(string $columnName): bool {
    static $cache = [];
    if (isset($cache[$columnName])) {
        return $cache[$columnName];
    }
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tournaments'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$columnName]);
        $cache[$columnName] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$columnName] = false;
    }
    return $cache[$columnName];
}

/**
 * Verifica dinámicamente si una columna existe en usuarios.
 */
function usuariosColumnExists(string $columnName): bool {
    static $cache = [];
    if (isset($cache[$columnName])) {
        return $cache[$columnName];
    }
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'usuarios'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$columnName]);
        $cache[$columnName] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$columnName] = false;
    }
    return $cache[$columnName];
}

/**
 * Verifica si organizaciones tiene columna cod_org.
 */
function organizacionesHasCodOrg(): bool {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $pdo = DB::pdo();
        $has = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * JOIN de organizaciones compatible con cod_org.
 */
function torneoOrgJoinExpr(string $tAlias = 't', string $oAlias = 'o', bool $leftJoin = true): string {
    $joinType = $leftJoin ? 'LEFT JOIN' : 'INNER JOIN';
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tAlias)) {
        $tAlias = 't';
    }
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $oAlias)) {
        $oAlias = 'o';
    }
    // Una sola fila de organización: el OR (id | cod_org) en JOIN duplicaba torneos si coincidían varias filas.
    if (organizacionesHasCodOrg()) {
        return "{$joinType} organizaciones {$oAlias} ON {$oAlias}.id = (
            SELECT o2.id FROM organizaciones o2
            WHERE o2.id = {$tAlias}.club_responsable OR o2.cod_org = {$tAlias}.club_responsable
            ORDER BY (o2.id = {$tAlias}.club_responsable) DESC, o2.id ASC
            LIMIT 1
        )";
    }

    return "{$joinType} organizaciones {$oAlias} ON {$oAlias}.id = {$tAlias}.club_responsable";
}

/**
 * Devuelve expresión SQL segura para teléfono de usuarios.
 * Prioriza columnas realmente consultables en runtime.
 */
function usuariosTelefonoExprSeguro(PDO $pdo): string {
    try {
        $pdo->query("SELECT telefono FROM usuarios LIMIT 1");
        return 'u.telefono';
    } catch (Throwable $e) {
        // continue
    }
    try {
        $pdo->query("SELECT celular FROM usuarios LIMIT 1");
        return 'u.celular';
    } catch (Throwable $e) {
        // continue
    }
    return "''";
}

/**
 * COALESCE de teléfono entre dos alias de usuarios (evita duplicar filas por OR en JOIN).
 */
function usuariosTelefonoCoalesceDosAliases(PDO $pdo, string $aliasA = 'u', string $aliasB = 'u_alt'): string {
    $e = usuariosTelefonoExprSeguro($pdo);
    if ($e === "''") {
        return "''";
    }
    $ea = str_replace('u.', $aliasA . '.', $e);
    $eb = str_replace('u.', $aliasB . '.', $e);
    return "COALESCE({$ea}, {$eb})";
}

/**
 * Columna en tabla clubes (cache por request).
 */
function clubesColumnExists(PDO $pdo, string $columnName): bool {
    static $cache = [];
    if (isset($cache[$columnName])) {
        return $cache[$columnName];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'clubes'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$columnName]);
        $cache[$columnName] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$columnName] = false;
    }
    return $cache[$columnName];
}

/**
 * Columna en tabla inscritos (cache por request).
 */
function inscritosColumnExists(PDO $pdo, string $columnName): bool {
    static $cache = [];
    if (isset($cache[$columnName])) {
        return $cache[$columnName];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inscritos'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$columnName]);
        $cache[$columnName] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$columnName] = false;
    }
    return $cache[$columnName];
}

/**
 * Logo de club para embeber en PDF (Dompdf): data URI seguro.
 */
function reporteInscritosLogoDataUri(?string $relativePath): string {
    if ($relativePath === null || trim($relativePath) === '') {
        return '';
    }
    $relativePath = str_replace(["\0", '\\'], ['', '/'], $relativePath);
    if (strpos($relativePath, '..') !== false) {
        return '';
    }
    $full = realpath(__DIR__ . '/../' . $relativePath);
    $root = realpath(__DIR__ . '/../');
    if ($full === false || $root === false || strpos($full, $root) !== 0 || !is_file($full)) {
        return '';
    }
    $bin = @file_get_contents($full);
    if ($bin === false || $bin === '') {
        return '';
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($full) ?: 'image/png') : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/**
 * Inscritos del torneo agrupados por asociación (club) y equipo (nombre lógico).
 */
function torneoGestionInscripcionesEquiposAgrupadas(PDO $pdo, int $torneoId): array {
    $usuarioTelefonoCoalesce = usuariosTelefonoCoalesceDosAliases($pdo);
    $exprInsNumfvd = inscritosColumnExists($pdo, 'numfvd') ? 'COALESCE(i.numfvd, 0)' : '0';
    $exprInsCedula = inscritosColumnExists($pdo, 'cedula') ? 'i.cedula' : "''";
    $stmt = $pdo->prepare("
        SELECT i.id_usuario, {$exprInsNumfvd} AS inscrito_numfvd, {$exprInsCedula} AS cedula_inscrita,
               TRIM(COALESCE(i.codigo_equipo, '')) AS codigo_equipo,
               COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u_alt.nombre), ''), CONCAT('ID ', CAST(i.id_usuario AS CHAR))) AS usuario_nombre,
               COALESCE(u.cedula, u_alt.cedula) AS usuario_cedula,
               COALESCE(u.numfvd, u_alt.numfvd, 0) AS usuario_numfvd,
               COALESCE(u.sexo, u_alt.sexo) AS usuario_sexo,
               {$usuarioTelefonoCoalesce} AS usuario_telefono,
               COALESCE(NULLIF(TRIM(c.nombre), ''), 'Sin asociación') AS asociacion_nombre,
               COALESCE(NULLIF(TRIM(e.nombre_equipo), ''), NULLIF(TRIM(i.codigo_equipo), ''), 'Sin equipo') AS equipo_nombre
        FROM inscritos i
        LEFT JOIN usuarios u ON u.id = i.id_usuario
        LEFT JOIN usuarios u_alt ON u.id IS NULL
            AND u_alt.numfvd = i.id_usuario
            AND EXISTS (SELECT 1 FROM tournaments tx WHERE tx.id = i.torneo_id AND tx.club_responsable = 7)
        LEFT JOIN equipos e ON e.id_torneo = i.torneo_id AND e.codigo_equipo = i.codigo_equipo AND e.estatus = 0
        LEFT JOIN clubes c ON c.id = COALESCE(e.id_club, i.id_club)
        WHERE i.torneo_id = ?
        ORDER BY asociacion_nombre ASC, equipo_nombre ASC, i.codigo_equipo ASC, i.id ASC
    ");
    $stmt->execute([$torneoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $agrupado = [];
    foreach ($rows as $r) {
        $asoc = (string)($r['asociacion_nombre'] ?? 'Sin asociación');
        $equipo = (string)($r['equipo_nombre'] ?? 'Sin equipo');
        if (!isset($agrupado[$asoc])) {
            $agrupado[$asoc] = [];
        }
        if (!isset($agrupado[$asoc][$equipo])) {
            $agrupado[$asoc][$equipo] = [];
        }
        $agrupado[$asoc][$equipo][] = $r;
    }
    return $agrupado;
}

/**
 * Nombre del torneo, organizador (club responsable) y logo embebible para reportes PDF.
 */
function torneoGestionDatosEncabezadoReporteInscripciones(PDO $pdo, int $torneoId): ?array {
    $stmt = $pdo->prepare('SELECT id, nombre, club_responsable FROM tournaments WHERE id = ? LIMIT 1');
    $stmt->execute([$torneoId]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        return null;
    }
    $orgNombre = 'Organización';
    $orgLogoDataUri = '';
    $cr = (int)($torneo['club_responsable'] ?? 0);
    if ($cr > 0) {
        $hasLogo = clubesColumnExists($pdo, 'logo');
        $sql = $hasLogo
            ? 'SELECT nombre, logo FROM clubes WHERE id = ? LIMIT 1'
            : 'SELECT nombre FROM clubes WHERE id = ? LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([$cr]);
        $club = $st->fetch(PDO::FETCH_ASSOC);
        if ($club) {
            $orgNombre = trim((string)($club['nombre'] ?? '')) ?: 'Organización';
            if ($hasLogo && !empty($club['logo'])) {
                $orgLogoDataUri = reporteInscritosLogoDataUri(trim((string)$club['logo']));
            }
        }
    }
    return [
        'torneo_nombre' => (string)($torneo['nombre'] ?? ''),
        'org_nombre' => $orgNombre,
        'org_logo_data_uri' => $orgLogoDataUri,
    ];
}

/**
 * Tablas agrupadas: asociación →’ equipo (nombre + código) →’ atletas (orden de columnas detallado).
 */
function torneoGestionHtmlCuerpoInscritosDetalladoEquipos(array $agrupado, callable $esc): string {
    $colspan = 6;
    $html = '';
    foreach ($agrupado as $asoc => $equiposAsoc) {
        $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">'
            . '<tr class="asoc"><td colspan="' . $colspan . '">ASOCIACIÓN: ' . $esc($asoc) . '</td></tr>';
        foreach ($equiposAsoc as $equipo => $integrantes) {
            $codEq = (string)(($integrantes[0]['codigo_equipo'] ?? '') ?: '');
            $eqLabel = 'EQUIPO: ' . $esc($equipo) . ($codEq !== '' ? ' ”” Código: ' . $esc($codEq) : '');
            $html .= '<tr class="equipo"><td colspan="' . $colspan . '">' . $eqLabel . '</td></tr>';
            $html .= '<tr><th>Cédula</th><th>id_usuario</th><th>numfvd</th><th>Nombre</th><th>Sexo</th><th>Teléfono / celular</th></tr>';
            foreach ($integrantes as $r) {
                $numfvd = (int)($r['usuario_numfvd'] ?? 0);
                if ($numfvd <= 0) {
                    $numfvd = (int)($r['inscrito_numfvd'] ?? 0);
                }
                $html .= '<tr><td>' . $esc($r['usuario_cedula'] ?? $r['cedula_inscrita'] ?? '') . '</td>'
                    . '<td>' . (int)($r['id_usuario'] ?? 0) . '</td>'
                    . '<td>' . $numfvd . '</td>'
                    . '<td>' . $esc($r['usuario_nombre'] ?? '') . '</td>'
                    . '<td>' . $esc($r['usuario_sexo'] ?? '') . '</td>'
                    . '<td>' . $esc($r['usuario_telefono'] ?? '') . '</td></tr>';
            }
        }
        $html .= '</table>';
    }
    return $html;
}

/**
 * Normaliza nombre de torneo para comparar base común.
 */
function normalizarNombreBaseTorneo(string $nombre): string {
    $txt = mb_strtolower(trim($nombre), 'UTF-8');
    $txt = preg_replace('/\b(masculino|femenino|masc|fem|caballeros|damas)\b/ui', ' ', $txt);
    $txt = preg_replace('/[^a-z0-9]+/ui', ' ', $txt);
    $txt = preg_replace('/\s+/u', ' ', (string)$txt);
    return trim((string)$txt);
}

/**
 * Detecta género del torneo desde su nombre.
 */
function detectarGeneroTorneoPorNombre(string $nombre): string {
    $txt = mb_strtolower($nombre, 'UTF-8');
    if (preg_match('/\b(femenino|fem|damas)\b/ui', $txt)) {
        return 'F';
    }
    if (preg_match('/\b(masculino|masc|caballeros)\b/ui', $txt)) {
        return 'M';
    }
    return '';
}

/**
 * V3.1: alerta visual si el sexo del usuario no coincide con el género inferido del nombre del torneo (no bloqueante).
 *
 * @param mixed $sexoUsuario valor de usuarios.sexo (M/F/1/2/…)
 */
function torneoGestionAlertaGeneroVsTorneo(string $generoTorneoInferido, $sexoUsuario): bool {
    if ($generoTorneoInferido !== 'M' && $generoTorneoInferido !== 'F') {
        return false;
    }
    $x = is_string($sexoUsuario) ? strtoupper(trim($sexoUsuario)) : (is_numeric($sexoUsuario) ? (string) $sexoUsuario : '');
    if ($x === '1' || $x === 'M') {
        $u = 'M';
    } elseif ($x === '2' || $x === 'F') {
        $u = 'F';
    } else {
        return false;
    }

    return $u !== $generoTorneoInferido;
}

/**
 * Header normalizado para mapeo de columnas.
 */
function tgNormalizeHeader(string $s): string
{
    $s = strtolower(trim($s));
    $s = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim((string)$s, '_');
}

/**
 * Lee filas tabulares desde CSV/TXT/XLSX.
 *
 * @return array{headers:array<int,string>, rows:array<int,array<int,string>>}
 */
function tgReadRowsFromUpload(string $tmpPath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        $raw = (string)@file_get_contents($tmpPath);
        if ($raw === '') {
            return ['headers' => [], 'rows' => []];
        }
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim((string)$l) !== ''));
        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }
        $first = (string)$lines[0];
        $delim = ',';
        if (substr_count($first, "\t") >= 2) {
            $delim = "\t";
        } elseif (substr_count($first, ';') >= 2 && substr_count($first, ',') < 2) {
            $delim = ';';
        }
        $all = [];
        foreach ($lines as $line) {
            $row = $delim === "\t" ? explode("\t", $line) : str_getcsv($line, $delim, '"', '\\');
            $all[] = array_map(static fn ($c) => trim((string)$c), $row);
        }
        $headers = array_map(static fn ($h) => (string)$h, (array)($all[0] ?? []));
        $rows = array_slice($all, 1);
        return ['headers' => $headers, 'rows' => $rows];
    }

    if ($ext === 'xlsx') {
        require_once __DIR__ . '/../lib/CargaMasivaXlsxReader.php';
        $all = CargaMasivaXlsxReader::leerHojas($tmpPath);
        if ($all === []) {
            throw new Exception('No se pudieron leer filas del .xlsx');
        }
        $headers = array_map(static fn ($h) => (string)$h, (array)($all[0] ?? []));
        $rows = array_slice($all, 1);
        return ['headers' => $headers, 'rows' => $rows];
    }

    throw new Exception('Formato no soportado. Use .xlsx, .csv o .txt');
}

/**
 * Base URL del entry point para enlaces del selector de torneos asociados (válida bajo /public/, beta, etc.).
 */
function torneoGestionContextSwitchBaseUrl(): string {
    if (class_exists('AppHelpers', false) && method_exists('AppHelpers', 'getRequestEntryUrl')) {
        return rtrim(AppHelpers::getRequestEntryUrl(), '/') . '/index.php?page=torneo_gestion';
    }

    return 'index.php?page=torneo_gestion';
}

/**
 * Obtiene contexto de torneos unificados para el switch (N torneos).
 * Incluye el torneo raíz del evento (id = evento) aunque su parent_event_id sea NULL,
 * y todos los torneos con parent_event_id = ese id (hermanos).
 */
function obtenerContextoTorneoUnificado(int $torneoId): array {
    if ($torneoId <= 0) {
        return ['active_tournament_id' => 0, 'items' => []];
    }

    $pdo = DB::pdo();
    $hasParentEvent = tournamentsColumnExists('parent_event_id');
    if (!$hasParentEvent) {
        return ['active_tournament_id' => $torneoId, 'items' => []];
    }

    $stmt = $pdo->prepare('SELECT id, nombre, parent_event_id FROM tournaments WHERE id = ? LIMIT 1');
    $stmt->execute([$torneoId]);
    $actual = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$actual) {
        return ['active_tournament_id' => 0, 'items' => []];
    }

    $parentCol = isset($actual['parent_event_id']) ? (int) $actual['parent_event_id'] : 0;
    // ID del evento raíz en BD: si el torneo actual es hijo, el raíz es parent_event_id; si es padre u hoja sin padre, el raíz es su propio id para agrupar hijos.
    $eventRootId = $parentCol > 0 ? $parentCol : (int) $actual['id'];

    $st = $pdo->prepare('
        SELECT id, nombre, parent_event_id
        FROM tournaments
        WHERE parent_event_id = ? OR id = ?
        ORDER BY id ASC
    ');
    $st->execute([$eventRootId, $eventRootId]);
    $candidatos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($candidatos as $cand) {
        $id = (int) ($cand['id'] ?? 0);
        if ($id <= 0 || ! Auth::canAccessTournament($id)) {
            continue;
        }
        $nombreCand = (string) ($cand['nombre'] ?? '');
        $genero = detectarGeneroTorneoPorNombre($nombreCand);
        $items[$id] = [
            'id' => $id,
            'nombre' => $nombreCand,
            'genero' => $genero,
            'parent_event_id' => isset($cand['parent_event_id']) ? (int) $cand['parent_event_id'] : null,
        ];
    }

    if (! isset($items[(int) $actual['id']])) {
        $actualGenero = detectarGeneroTorneoPorNombre((string) ($actual['nombre'] ?? ''));
        $items[(int) $actual['id']] = [
            'id' => (int) $actual['id'],
            'nombre' => (string) ($actual['nombre'] ?? ''),
            'genero' => $actualGenero,
            'parent_event_id' => isset($actual['parent_event_id']) ? (int) $actual['parent_event_id'] : null,
        ];
    }

    if (count($items) <= 1) {
        return ['active_tournament_id' => (int) $actual['id'], 'items' => []];
    }

    usort($items, static function (array $a, array $b): int {
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    foreach ($items as $i => &$it) {
        $it['index'] = $i;
    }
    unset($it);

    return [
        'active_tournament_id' => (int) $actual['id'],
        'items' => array_values($items),
    ];
}

/**
 * Máxima ronda existente en partiresul por torneo (para enlaces del switch de contexto).
 *
 * @param array<int> $torneoIds
 * @return array<int, int> id_torneo => max(partida)
 */
function torneoGestionMapaMaxPartidasPorTorneo(array $torneoIds): array {
    $torneoIds = array_values(array_unique(array_filter(array_map('intval', $torneoIds), static function ($v) {
        return $v > 0;
    })));
    if ($torneoIds === []) {
        return [];
    }
    $pdo = DB::pdo();
    $placeholders = implode(',', array_fill(0, count($torneoIds), '?'));
    $stmt = $pdo->prepare("SELECT id_torneo, COALESCE(MAX(partida), 0) AS mx FROM partiresul WHERE id_torneo IN ($placeholders) GROUP BY id_torneo");
    $stmt->execute($torneoIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['id_torneo']] = (int) $row['mx'];
    }
    foreach ($torneoIds as $id) {
        if (!isset($map[$id])) {
            $map[$id] = 0;
        }
    }

    return $map;
}

/**
 * Ronda a usar en enlaces al cambiar de torneo asociado: no excede la última ronda generada en destino.
 */
function torneoGestionRondaParaEnlaceSwitch(int $torneoDestinoId, int $rondaSolicitada, array $mapaMaxPartida): int {
    $max = (int) ($mapaMaxPartida[$torneoDestinoId] ?? 0);
    $r = max(1, $rondaSolicitada);
    if ($max <= 0) {
        return $r;
    }

    return min($r, $max);
}

/**
 * URL para cambiar de torneo asociado (mismo evento): cuadrícula, hojas, resultados o panel.
 * La ronda en destino es siempre la última generada en ese torneo (map_max de partiresul), no la ronda que se estaba viendo.
 *
 * @param string $mode 'cuadricula'|'hojas_anotacion'|'registrar_resultados'|'panel'
 * @param array<string, mixed> $extra p.ej. ['mesa' => 0] para registrar_resultados (0 = primera mesa de la ronda)
 */
function torneoContextSwitchHref(
    string $baseUrl,
    string $sep,
    string $mode,
    int $switchTorneoId,
    int $rondaBase,
    array $mapMaxPartida,
    array $extra = []
): string {
    if ($mode === 'panel') {
        return $baseUrl . $sep . 'action=panel&torneo_id=' . $switchTorneoId . '&switch_torneo_id=' . $switchTorneoId . '&return_action=panel';
    }
    $maxDest = (int) ($mapMaxPartida[$switchTorneoId] ?? 0);
    $ronda = $maxDest > 0 ? $maxDest : 1;
    $actionMap = [
        'cuadricula' => ['action' => 'cuadricula', 'return' => 'cuadricula'],
        'hojas_anotacion' => ['action' => 'hojas_anotacion', 'return' => 'hojas_anotacion'],
        'registrar_resultados' => ['action' => 'registrar_resultados', 'return' => 'registrar_resultados'],
    ];
    $am = $actionMap[$mode] ?? $actionMap['cuadricula'];
    $u = $baseUrl . $sep . 'action=' . $am['action']
        . '&torneo_id=' . $switchTorneoId
        . '&ronda=' . $ronda
        . '&switch_torneo_id=' . $switchTorneoId
        . '&return_action=' . $am['return'];
    if ($mode === 'registrar_resultados') {
        $mesaSw = array_key_exists('mesa', $extra) ? (int) $extra['mesa'] : 0;
        $u .= '&mesa=' . $mesaSw;
    }

    return $u;
}

/**
 * Estado resumido de ronda/mesas de un torneo.
 */
function obtenerEstadoRondaTorneo(int $torneoId): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(partida), 0) FROM partiresul WHERE id_torneo = ?");
    $stmt->execute([$torneoId]);
    $rondaActual = (int)$stmt->fetchColumn();
    if ($rondaActual <= 0) {
        return ['ronda_actual' => 0, 'mesas_totales' => 0, 'mesas_pendientes' => 0];
    }

    $stmtTot = $pdo->prepare("SELECT COUNT(DISTINCT mesa) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0");
    $stmtTot->execute([$torneoId, $rondaActual]);
    $mesasTotales = (int)$stmtTot->fetchColumn();

    $stmtPend = $pdo->prepare("
        SELECT COUNT(DISTINCT mesa)
        FROM partiresul
        WHERE id_torneo = ?
          AND partida = ?
          AND mesa > 0
          AND (registrado = 0 OR registrado IS NULL)
    ");
    $stmtPend->execute([$torneoId, $rondaActual]);
    $mesasPendientes = (int)$stmtPend->fetchColumn();

    return [
        'ronda_actual' => $rondaActual,
        'mesas_totales' => $mesasTotales,
        'mesas_pendientes' => $mesasPendientes,
    ];
}

/**
 * Estado compacto del grupo unificado (N torneos): resumen por torneo para el panel.
 * `bloqueo` queda siempre null (cada torneo se gestiona de forma independiente).
 */
function obtenerEstadoParTorneosUnificado(int $torneoId): array {
    $contexto = obtenerContextoTorneoUnificado($torneoId);
    $items = $contexto['items'] ?? [];
    if (count($items) <= 1) {
        return ['enabled' => false, 'items' => [], 'bloqueo' => null];
    }

    $activeId = (int)($contexto['active_tournament_id'] ?? $torneoId);
    $estadoItems = [];
    foreach ($items as $item) {
        $tid = (int)($item['id'] ?? 0);
        if ($tid <= 0) {
            continue;
        }
        $st = obtenerEstadoRondaTorneo($tid);
        $estadoItems[] = [
            'id' => $tid,
            'genero' => strtoupper((string)($item['genero'] ?? '')),
            'nombre' => (string)($item['nombre'] ?? ''),
            'index' => (int)($item['index'] ?? 0),
            'activo' => ($tid === $activeId),
            'ronda_actual' => (int)$st['ronda_actual'],
            'mesas_totales' => (int)$st['mesas_totales'],
            'mesas_pendientes' => (int)$st['mesas_pendientes'],
        ];
    }

    // Cada torneo avanza por su cuenta; no bloquear generación/cierre por mesas pendientes en torneos hermanos del mismo evento.
    return ['enabled' => true, 'items' => $estadoItems, 'bloqueo' => null];
}

/**
 * Verifica si existe la columna 'locked' en la tabla tournaments
 */
function tournamentsLockedColumnExists(): bool {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'locked'");
        $stmt->execute();
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Asegura que exista la columna 'locked' en tournaments
 */
function ensureTournamentsLockedColumn(): void {
    if (!tournamentsLockedColumnExists()) {
        try {
            $pdo = DB::pdo();
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_tournaments_locked (locked)");
        } catch (Exception $e) {
            // Ignorar si falla (podría no tener permisos); el flujo continuará sin lock persistente
        }
    }
}

/**
 * Retorna si el torneo está cerrado (locked)
 */
function isTorneoLocked(int $torneoId): bool {
    try {
        if (!tournamentsLockedColumnExists()) {
            return false;
        }
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT locked FROM tournaments WHERE id = ?");
        $stmt->execute([$torneoId]);
        $locked = $stmt->fetchColumn();
        return (int)$locked === 1;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Verifica si existe la columna 'correcciones_cierre_at' en tournaments
 */
function tournamentsCorreccionesCierreColumnExists(): bool {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'correcciones_cierre_at'");
        $stmt->execute();
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Asegura que exista la columna correcciones_cierre_at (fija al guardar última mesa; no se resetea con correcciones)
 */
function ensureTournamentsCorreccionesCierreColumn(): void {
    if (!tournamentsCorreccionesCierreColumnExists()) {
        try {
            $pdo = DB::pdo();
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN correcciones_cierre_at DATETIME NULL COMMENT 'Cierre de correcciones 20 min después de completar última mesa'");
        } catch (Exception $e) {
            // Ignorar si falla
        }
    }
}

if (!defined('TORNEO_GESTION_SKIP_ROUTER') || !TORNEO_GESTION_SKIP_ROUTER) {

// Obtener acción y parámetros
$action = $_GET['action'] ?? 'index';
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : null;
$ronda = isset($_GET['ronda']) ? (int)$_GET['ronda'] : null;
$mesa = isset($_GET['mesa']) ? (int)$_GET['mesa'] : null;
$inscrito_id = isset($_GET['inscrito_id']) ? (int)$_GET['inscrito_id'] : null;

// Context switcher: cambiar torneo activo en sesión y redirigir.
$switch_torneo_id = (int)($_GET['switch_torneo_id'] ?? 0);
if ($switch_torneo_id > 0) {
    if (!Auth::canAccessTournament($switch_torneo_id)) {
        throw new Exception('No tiene permisos para cambiar al torneo seleccionado');
    }
    $_SESSION['active_tournament_id'] = $switch_torneo_id;
    $redir_action = trim((string)($_GET['return_action'] ?? 'panel'));
    if ($redir_action === '') {
        $redir_action = 'panel';
    }
    $redir_params = ['torneo_id' => $switch_torneo_id];
    // Reenviar ronda/mesa/inscrito según la URL del enlace (el enlace ya trae última ronda en destino)
    foreach (['ronda', 'mesa', 'inscrito_id'] as $passthrough) {
        if (isset($_GET[$passthrough]) && $_GET[$passthrough] !== '' && $_GET[$passthrough] !== null) {
            $redir_params[$passthrough] = is_numeric($_GET[$passthrough])
                ? (int)$_GET[$passthrough]
                : $_GET[$passthrough];
        }
    }
    header('Location: ' . buildRedirectUrl($redir_action, $redir_params));
    exit;
}

// Plantilla CSV carga masiva equipos (GET, sin layout; antes del override de torneo activo en sesión)
if ($action === 'carga_masiva_equipos_plantilla' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    require_once __DIR__ . '/../lib/CargaMasivaEquiposSitioService.php';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_carga_equipos_torneo_' . $torneo_id . '.csv"');
    echo CargaMasivaEquiposSitioService::contenidoPlantillaCsv();
    exit;
}
if ($action === 'carga_masiva_parejas_plantilla' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    require_once __DIR__ . '/../lib/CargaMasivaParejasSitioService.php';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_carga_parejas_torneo_' . $torneo_id . '.csv"');
    echo CargaMasivaParejasSitioService::contenidoPlantillaCsv();
    exit;
}
if ($action === 'export_access_excel' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $_GET['torneo_id'] = (string) $torneo_id;
    require __DIR__ . '/gestion_torneos/export_access_excel.php';
    exit;
}
if ($action === 'inscripciones_export_xls' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $stmtT = $pdo->prepare('SELECT id, nombre, modalidad, es_evento_masivo, club_responsable FROM tournaments WHERE id = ? LIMIT 1');
    $stmtT->execute([$torneo_id]);
    $torneo = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        http_response_code(404);
        exit('Torneo no encontrado');
    }
    $agrupado = torneoGestionInscripcionesEquiposAgrupadas($pdo, $torneo_id);
    $filename = 'inscritos_torneo_' . $torneo_id . '_' . date('Y-m-d_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Inscritos</title></head><body><table border="1" cellpadding="5" cellspacing="0">';
    echo '<tr><td colspan="7" style="font-weight:bold;text-align:center;background:#e2e8f0;font-size:16px;">' . $esc($torneo['nombre'] ?? '') . '</td></tr>';
    foreach ($agrupado as $asoc => $equiposAsoc) {
        echo '<tr><td colspan="7" style="font-weight:bold;background:#dbeafe;">ASOCIACIÓN: ' . $esc($asoc) . '</td></tr>';
        foreach ($equiposAsoc as $equipo => $integrantes) {
            echo '<tr><td colspan="7" style="font-weight:bold;background:#f3f4f6;">EQUIPO: ' . $esc($equipo) . '</td></tr>';
            echo '<tr style="font-weight:bold;background:#f8fafc;"><td>cedula</td><td>nombre</td><td>id_usuario</td><td>numfvd</td><td>codigo_equipo</td><td>sexo</td><td>telefono</td></tr>';
            foreach ($integrantes as $r) {
                $numfvd = (int)($r['usuario_numfvd'] ?? 0);
                if ($numfvd <= 0) {
                    $numfvd = (int)($r['inscrito_numfvd'] ?? 0);
                }
                echo '<tr>'
                    . '<td>' . $esc($r['usuario_cedula'] ?? $r['cedula_inscrita'] ?? '') . '</td>'
                    . '<td>' . $esc($r['usuario_nombre'] ?? '') . '</td>'
                    . '<td>' . (int)($r['id_usuario'] ?? 0) . '</td>'
                    . '<td>' . $numfvd . '</td>'
                    . '<td>' . $esc($r['codigo_equipo'] ?? '') . '</td>'
                    . '<td>' . $esc($r['usuario_sexo'] ?? '') . '</td>'
                    . '<td>' . $esc($r['usuario_telefono'] ?? '') . '</td>'
                    . '</tr>';
            }
        }
    }
    echo '</table></body></html>';
    exit;
}
if ($action === 'inscripciones_gestor_excel' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $stmtT = $pdo->prepare('SELECT id, nombre FROM tournaments WHERE id = ? LIMIT 1');
    $stmtT->execute([$torneo_id]);
    $torneo = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        http_response_code(404);
        exit('Torneo no encontrado');
    }
    $tipoReporte = trim((string)($_GET['tipo_reporte'] ?? 'inscritos_detallado'));
    $rondaFiltro = (int)($_GET['ronda'] ?? 0);
    $rondasCantidad = (int)($_GET['rondas_cantidad'] ?? 99);
    $rondaNumeroExacta = (int)($_GET['ronda_numero'] ?? 0);
    $permitidos = ['inscritos_detallado', 'inscritos_por_equipo', 'partiresul_detallado', 'partiresul_por_ronda', 'equipos_detallado'];
    if (!in_array($tipoReporte, $permitidos, true)) {
        $tipoReporte = 'inscritos_detallado';
    }
    $columnasDisponibles = [
        'inscritos_detallado' => [
            'asociacion_nombre' => 'Asociación',
            'equipo_nombre' => 'Equipo',
            'codigo_equipo' => 'Código equipo',
            'id_usuario' => 'ID usuario',
            'numfvd' => 'NUMFVD',
            'cedula' => 'Cédula',
            'usuario_nombre' => 'Nombre usuario',
            'usuario_sexo' => 'Sexo',
            'usuario_telefono' => 'Teléfono',
        ],
        'inscritos_por_equipo' => [
            'asociacion_nombre' => 'Asociación',
            'equipo_nombre' => 'Equipo',
            'codigo_equipo' => 'Código equipo',
            'id_usuario' => 'ID usuario',
            'numfvd' => 'NUMFVD',
            'cedula' => 'Cédula',
            'usuario_nombre' => 'Nombre usuario',
            'usuario_sexo' => 'Sexo',
            'usuario_telefono' => 'Teléfono',
        ],
        'partiresul_detallado' => [
            'partida' => 'Ronda',
            'mesa' => 'Mesa',
            'secuencia' => 'Secuencia',
            'id_usuario' => 'ID usuario',
            'usuario_nombre' => 'Nombre usuario',
            'resultado1' => 'Resultado1',
            'resultado2' => 'Resultado2',
            'ff' => 'FF',
            'tarjeta' => 'Tarjeta',
            'sancion' => 'Sanción',
            'registrado' => 'Registrado',
        ],
        'partiresul_por_ronda' => [
            'partida' => 'Ronda',
            'mesa' => 'Mesa',
            'secuencia' => 'Secuencia',
            'id_usuario' => 'ID usuario',
            'usuario_nombre' => 'Nombre usuario',
            'resultado1' => 'Resultado1',
            'resultado2' => 'Resultado2',
            'ff' => 'FF',
            'tarjeta' => 'Tarjeta',
            'sancion' => 'Sanción',
            'registrado' => 'Registrado',
        ],
        'equipos_detallado' => [
            'codigo_equipo' => 'Código equipo',
            'nombre_equipo' => 'Nombre equipo',
            'id_club' => 'ID club',
            'club_nombre' => 'Club',
            'ganados' => 'Ganados',
            'perdidos' => 'Perdidos',
            'efectividad' => 'Efectividad',
            'puntos' => 'Puntos',
            'sancion' => 'Sanción',
            'posicion' => 'Posición',
            'estatus' => 'Estatus',
            'fecha_actualizacion' => 'Actualización',
        ],
    ];
    $columnasSolicitadas = array_map('strval', (array)($_GET['columnas'] ?? []));
    $columnasOrdenSolicitadoRaw = trim((string)($_GET['columnas_orden'] ?? ''));
    $columnasOrdenSolicitado = $columnasOrdenSolicitadoRaw !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $columnasOrdenSolicitadoRaw)), static fn ($v) => $v !== ''))
        : [];
    $columnasValidas = array_keys($columnasDisponibles[$tipoReporte]);
    $columnasSeleccionadas = array_values(array_intersect($columnasValidas, $columnasSolicitadas));
    if ($columnasOrdenSolicitado !== []) {
        $ordenValidado = array_values(array_intersect($columnasOrdenSolicitado, $columnasSeleccionadas));
        if ($ordenValidado !== []) {
            $faltantes = array_values(array_diff($columnasSeleccionadas, $ordenValidado));
            $columnasSeleccionadas = array_merge($ordenValidado, $faltantes);
        }
    }
    if ($columnasSeleccionadas === []) {
        $columnasSeleccionadas = $columnasValidas;
    }
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $filename = 'reporte_' . $tipoReporte . '_torneo_' . $torneo_id . '_' . date('Y-m-d_His') . '.xls';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Reporte</title></head><body>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    $colsSpan = max(1, count($columnasSeleccionadas));
    echo '<tr><td colspan="' . $colsSpan . '" style="font-weight:bold;text-align:center;background:#e2e8f0;font-size:16px;">' . $esc($torneo['nombre'] ?? '') . '</td></tr>';
    $resumenRonda = 'Todas';
    if ($tipoReporte === 'partiresul_por_ronda') {
        if ($rondasCantidad === -1 && $rondaNumeroExacta > 0) {
            $resumenRonda = 'Solo ronda ' . $rondaNumeroExacta;
        } elseif ($rondasCantidad === 99 || $rondaFiltro === 99) {
            $resumenRonda = 'Todas';
        } elseif ($rondasCantidad > 0) {
            $resumenRonda = 'Últimas ' . $rondasCantidad;
        }
    }
    echo '<tr><td colspan="' . $colsSpan . '" style="font-weight:bold;background:#f8fafc;">Reporte: ' . $esc(strtoupper($tipoReporte)) . ' · Rondas: ' . $esc($resumenRonda) . ' · Generado: ' . $esc(date('d/m/Y H:i')) . '</td></tr>';
    $printHeader = static function (array $keys, array $labels, callable $esc): void {
        echo '<tr style="font-weight:bold;background:#f8fafc;">';
        foreach ($keys as $k) {
            echo '<td>' . $esc($labels[$k] ?? $k) . '</td>';
        }
        echo '</tr>';
    };
    $printRows = static function (array $rows, array $keys, callable $esc): void {
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($keys as $k) {
                echo '<td>' . $esc($r[$k] ?? '') . '</td>';
            }
            echo '</tr>';
        }
    };

    if ($tipoReporte === 'inscritos_detallado' || $tipoReporte === 'inscritos_por_equipo') {
        $usuarioTelefonoCoalesce = usuariosTelefonoCoalesceDosAliases($pdo);
        $exprInsNumfvd = inscritosColumnExists($pdo, 'numfvd') ? 'COALESCE(i.numfvd, 0)' : '0';
        $exprInsCedula = inscritosColumnExists($pdo, 'cedula') ? 'i.cedula' : "''";
        $orden = $tipoReporte === 'inscritos_por_equipo'
            ? 'asociacion_nombre ASC, codigo_equipo ASC, usuario_nombre ASC'
            : 'asociacion_nombre ASC, usuario_nombre ASC';
        $st = $pdo->prepare("
            SELECT
                i.id_usuario,
                {$exprInsNumfvd} AS inscrito_numfvd,
                {$exprInsCedula} AS cedula_inscrita,
                TRIM(COALESCE(i.codigo_equipo, '')) AS codigo_equipo,
                COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u_alt.nombre), ''), CONCAT('ID ', CAST(i.id_usuario AS CHAR))) AS usuario_nombre,
                COALESCE(u.cedula, u_alt.cedula) AS usuario_cedula,
                COALESCE(u.numfvd, u_alt.numfvd, 0) AS usuario_numfvd,
                COALESCE(u.sexo, u_alt.sexo) AS usuario_sexo,
                {$usuarioTelefonoCoalesce} AS usuario_telefono,
                COALESCE(NULLIF(TRIM(c.nombre), ''), 'Sin asociación') AS asociacion_nombre,
                COALESCE(NULLIF(TRIM(e.nombre_equipo), ''), NULLIF(TRIM(i.codigo_equipo), ''), 'Sin equipo') AS equipo_nombre
            FROM inscritos i
            LEFT JOIN usuarios u ON u.id = i.id_usuario
            LEFT JOIN usuarios u_alt ON u.id IS NULL
                AND u_alt.numfvd = i.id_usuario
                AND EXISTS (SELECT 1 FROM tournaments tx WHERE tx.id = i.torneo_id AND tx.club_responsable = 7)
            LEFT JOIN equipos e ON e.id_torneo = i.torneo_id AND e.codigo_equipo = i.codigo_equipo AND e.estatus = 0
            LEFT JOIN clubes c ON c.id = COALESCE(e.id_club, i.id_club)
            WHERE i.torneo_id = ?
            ORDER BY {$orden}
        ");
        $st->execute([$torneo_id]);
        $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($rawRows as $r) {
            $numfvd = (int)($r['usuario_numfvd'] ?? 0);
            if ($numfvd <= 0) {
                $numfvd = (int)($r['inscrito_numfvd'] ?? 0);
            }
            $rows[] = [
                'asociacion_nombre' => $r['asociacion_nombre'] ?? '',
                'equipo_nombre' => $r['equipo_nombre'] ?? '',
                'codigo_equipo' => $r['codigo_equipo'] ?? '',
                'id_usuario' => (int)($r['id_usuario'] ?? 0),
                'numfvd' => $numfvd,
                'cedula' => $r['usuario_cedula'] ?? $r['cedula_inscrita'] ?? '',
                'usuario_nombre' => $r['usuario_nombre'] ?? '',
                'usuario_sexo' => $r['usuario_sexo'] ?? '',
                'usuario_telefono' => $r['usuario_telefono'] ?? '',
            ];
        }
        $printHeader($columnasSeleccionadas, $columnasDisponibles[$tipoReporte], $esc);
        $printRows($rows, $columnasSeleccionadas, $esc);
    } elseif ($tipoReporte === 'equipos_detallado') {
        $st = $pdo->prepare("
            SELECT
                e.codigo_equipo,
                e.nombre_equipo,
                e.id_club,
                COALESCE(c.nombre, '') AS club_nombre,
                e.ganados,
                e.perdidos,
                e.efectividad,
                e.puntos,
                e.sancion,
                e.posicion,
                e.estatus,
                e.fecha_actualizacion
            FROM equipos e
            LEFT JOIN clubes c ON c.id = e.id_club
            WHERE e.id_torneo = ?
            ORDER BY e.posicion ASC, e.codigo_equipo ASC
        ");
        $st->execute([$torneo_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $printHeader($columnasSeleccionadas, $columnasDisponibles[$tipoReporte], $esc);
        $printRows($rows, $columnasSeleccionadas, $esc);
    } else {
        $params = [$torneo_id];
        $whereRonda = '';
        if ($tipoReporte === 'partiresul_por_ronda') {
            if ($rondasCantidad === -1 && $rondaNumeroExacta > 0) {
                $whereRonda = ' AND pr.partida = ? ';
                $params[] = $rondaNumeroExacta;
            } elseif ($rondasCantidad === 99 || $rondaFiltro === 99) {
                $whereRonda = '';
            } elseif ($rondasCantidad > 0) {
                $stMax = $pdo->prepare("SELECT MAX(partida) FROM partiresul WHERE id_torneo = ?");
                $stMax->execute([$torneo_id]);
                $maxPartida = (int)$stMax->fetchColumn();
                if ($maxPartida > 0) {
                    $desde = max(1, $maxPartida - $rondasCantidad + 1);
                    $whereRonda = ' AND pr.partida BETWEEN ? AND ? ';
                    $params[] = $desde;
                    $params[] = $maxPartida;
                }
            }
        }
        $st = $pdo->prepare("
            SELECT
                pr.partida,
                pr.mesa,
                pr.secuencia,
                pr.id_usuario,
                COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u_alt.nombre), ''), CONCAT('ID ', CAST(pr.id_usuario AS CHAR))) AS usuario_nombre,
                pr.resultado1,
                pr.resultado2,
                pr.ff,
                pr.tarjeta,
                pr.sancion,
                pr.registrado
            FROM partiresul pr
            LEFT JOIN usuarios u ON u.id = pr.id_usuario
            LEFT JOIN usuarios u_alt ON u.id IS NULL
                AND u_alt.numfvd = pr.id_usuario
                AND EXISTS (SELECT 1 FROM tournaments tx WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7)
            WHERE pr.id_torneo = ? {$whereRonda}
            ORDER BY pr.partida ASC, pr.mesa ASC, pr.secuencia ASC
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $norm = [];
        foreach ($rows as $r) {
            $norm[] = [
                'partida' => (int)($r['partida'] ?? 0),
                'mesa' => (int)($r['mesa'] ?? 0),
                'secuencia' => (int)($r['secuencia'] ?? 0),
                'id_usuario' => (int)($r['id_usuario'] ?? 0),
                'usuario_nombre' => $r['usuario_nombre'] ?? '',
                'resultado1' => $r['resultado1'] ?? '',
                'resultado2' => $r['resultado2'] ?? '',
                'ff' => $r['ff'] ?? '',
                'tarjeta' => $r['tarjeta'] ?? '',
                'sancion' => $r['sancion'] ?? '',
                'registrado' => $r['registrado'] ?? '',
            ];
        }
        $printHeader($columnasSeleccionadas, $columnasDisponibles[$tipoReporte], $esc);
        $printRows($norm, $columnasSeleccionadas, $esc);
    }
    echo '</table></body></html>';
    exit;
}
if ($action === 'inscripciones_export_pdf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $stmtT = $pdo->prepare('SELECT id, nombre FROM tournaments WHERE id = ? LIMIT 1');
    $stmtT->execute([$torneo_id]);
    $torneo = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        http_response_code(404);
        exit('Torneo no encontrado');
    }
    $agrupado = torneoGestionInscripcionesEquiposAgrupadas($pdo, $torneo_id);
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>@page{size:letter landscape;margin:10mm}body{font-family:DejaVu Sans,sans-serif;font-size:8pt}table{width:100%;border-collapse:collapse;margin-bottom:10px}th,td{border:1px solid #666;padding:2px 4px}th{background:#eee}.titulo{font-size:14pt;font-weight:bold;text-align:center;margin:0 0 8px 0}.asoc{background:#dbeafe;font-weight:bold}.equipo{background:#f3f4f6;font-weight:bold}</style></head><body>';
    $html .= '<p class="titulo">' . $esc($torneo['nombre'] ?? '') . '</p>';
    foreach ($agrupado as $asoc => $equiposAsoc) {
        $html .= '<table><tr class="asoc"><td colspan="7">ASOCIACIÓN: ' . $esc($asoc) . '</td></tr>';
        foreach ($equiposAsoc as $equipo => $integrantes) {
            $html .= '<tr class="equipo"><td colspan="7">EQUIPO: ' . $esc($equipo) . '</td></tr>';
            $html .= '<tr><th>cedula</th><th>nombre</th><th>id_usuario</th><th>numfvd</th><th>codigo_equipo</th><th>sexo</th><th>telefono</th></tr>';
            foreach ($integrantes as $r) {
                $numfvd = (int)($r['usuario_numfvd'] ?? 0);
                if ($numfvd <= 0) {
                    $numfvd = (int)($r['inscrito_numfvd'] ?? 0);
                }
                $html .= '<tr><td>' . $esc($r['usuario_cedula'] ?? $r['cedula_inscrita'] ?? '') . '</td><td>' . $esc($r['usuario_nombre'] ?? '') . '</td><td>' . (int)($r['id_usuario'] ?? 0) . '</td><td>' . $numfvd . '</td><td>' . $esc($r['codigo_equipo'] ?? '') . '</td><td>' . $esc($r['usuario_sexo'] ?? '') . '</td><td>' . $esc($r['usuario_telefono'] ?? '') . '</td></tr>';
            }
        }
        $html .= '</table>';
    }
    $html .= '</body></html>';
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload) && is_readable($autoload)) {
        try {
            require_once $autoload;
            if (class_exists(\Dompdf\Dompdf::class)) {
                $opt = new \Dompdf\Options();
                $opt->set('isRemoteEnabled', false);
                $opt->set('defaultFont', 'DejaVu Sans');
                $pdf = new \Dompdf\Dompdf($opt);
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('letter', 'landscape');
                $pdf->render();
                while (ob_get_level()) ob_end_clean();
                $pdf->stream('inscritos_torneo_' . $torneo_id . '_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
                exit;
            }
        } catch (Throwable $e) {
            // fallback html
        }
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inscritos_torneo_' . $torneo_id . '_imprimir.html"');
    echo $html;
    exit;
}
if ($action === 'retirados_export_pdf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $_GET['torneo_id'] = (string) $torneo_id;
    require __DIR__ . '/registrants/report_pdf_retirados.php';
    exit;
}
if ($action === 'retirados_export_xls' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $_GET['torneo_id'] = (string) $torneo_id;
    require __DIR__ . '/registrants/export_excel_retirados.php';
    exit;
}
if ($action === 'inscripciones_reporte_detallado_pdf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $enc = torneoGestionDatosEncabezadoReporteInscripciones($pdo, $torneo_id);
    if (!$enc) {
        http_response_code(404);
        exit('Torneo no encontrado');
    }
    $agrupado = torneoGestionInscripcionesEquiposAgrupadas($pdo, $torneo_id);
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $logoHtml = ($enc['org_logo_data_uri'] ?? '') !== ''
        ? '<img src="' . $esc($enc['org_logo_data_uri']) . '" style="max-height:56px;max-width:140px;object-fit:contain;" alt="" />'
        : '';
    $css = '@page{size:letter landscape;margin:10mm}'
        . 'body{font-family:DejaVu Sans,sans-serif;font-size:8pt;color:#111}'
        . '.report-head{border-bottom:2px solid #1e40af;margin-bottom:10px;padding-bottom:8px}'
        . '.org-name{font-size:11pt;font-weight:bold;color:#1e3a8a}'
        . '.titulo-torneo{font-size:14pt;font-weight:bold;text-align:center;margin:8px 0 4px}'
        . '.meta-gen{text-align:center;font-size:7.5pt;color:#555;margin:0 0 10px}'
        . 'table{width:100%;border-collapse:collapse;margin-bottom:10px}'
        . 'th,td{border:1px solid #666;padding:3px 4px}'
        . 'th{background:#f1f5f9;font-size:7.5pt}'
        . '.asoc{background:#dbeafe;font-weight:bold}'
        . '.equipo{background:#f3f4f6;font-weight:bold}';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
    $html .= '<div class="report-head"><table style="width:100%;border:none;border-collapse:collapse"><tr>'
        . '<td style="width:110px;border:none;vertical-align:middle">' . $logoHtml . '</td>'
        . '<td style="border:none;vertical-align:middle"><div class="org-name">' . $esc($enc['org_nombre']) . '</div></td>'
        . '</tr></table></div>';
    $html .= '<p class="titulo-torneo">' . $esc($enc['torneo_nombre']) . '</p>';
    $html .= '<p class="meta-gen">Reporte de inscritos por asociación y equipo · Generado: ' . $esc(date('d/m/Y H:i')) . '</p>';
    $html .= torneoGestionHtmlCuerpoInscritosDetalladoEquipos($agrupado, $esc);
    $html .= '</body></html>';
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload) && is_readable($autoload)) {
        try {
            require_once $autoload;
            if (class_exists(\Dompdf\Dompdf::class)) {
                $opt = new \Dompdf\Options();
                $opt->set('isRemoteEnabled', false);
                $opt->set('defaultFont', 'DejaVu Sans');
                $pdf = new \Dompdf\Dompdf($opt);
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('letter', 'landscape');
                $pdf->render();
                while (ob_get_level()) {
                    ob_end_clean();
                }
                $pdf->stream('inscritos_detallado_torneo_' . $torneo_id . '_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
                exit;
            }
        } catch (Throwable $e) {
            // fallback html
        }
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inscritos_detallado_torneo_' . $torneo_id . '_imprimir.html"');
    echo $html;
    exit;
}
if ($action === 'inscripciones_reporte_detallado_xls' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $enc = torneoGestionDatosEncabezadoReporteInscripciones($pdo, $torneo_id);
    if (!$enc) {
        http_response_code(404);
        exit('Torneo no encontrado');
    }
    $agrupado = torneoGestionInscripcionesEquiposAgrupadas($pdo, $torneo_id);
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $filename = 'inscritos_detallado_torneo_' . $torneo_id . '_' . date('Y-m-d_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Inscritos detallado</title></head><body>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="margin-bottom:12px;">';
    echo '<tr><td colspan="6" style="font-weight:bold;text-align:center;background:#e2e8f0;font-size:16px;">' . $esc($enc['org_nombre']) . '</td></tr>';
    echo '<tr><td colspan="6" style="font-weight:bold;text-align:center;background:#eff6ff;font-size:15px;">' . $esc($enc['torneo_nombre']) . '</td></tr>';
    echo '<tr><td colspan="6" style="text-align:center;font-size:10px;color:#555;">Reporte detallado · Generado: ' . $esc(date('d/m/Y H:i')) . '</td></tr>';
    echo '</table>';
    echo torneoGestionHtmlCuerpoInscritosDetalladoEquipos($agrupado, $esc);
    echo '</body></html>';
    exit;
}
if ($action === 'carga_masiva_equipos_reporte_pdf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $repo = $_SESSION['carga_masiva_reportes'][$torneo_id] ?? null;
    if (!is_array($repo) || empty($repo['reporte_proceso'])) {
        http_response_code(404);
        exit('No hay reporte disponible para este torneo.');
    }
    $torneoNombre = (string)($repo['torneo_nombre'] ?? ('Torneo ' . $torneo_id));
    $fechaGen = (string)($repo['fecha'] ?? date('Y-m-d H:i:s'));
    $proc = (array)$repo['reporte_proceso'];
    $res = (array)($proc['resumen'] ?? []);
    $equipos = (array)($proc['equipos'] ?? []);
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . '@page{size:letter portrait;margin:12mm}body{font-family:DejaVu Sans,sans-serif;font-size:9pt;color:#111}'
        . 'h1{font-size:13pt;margin:0 0 4px} .meta{font-size:8pt;color:#444;margin-bottom:8px}'
        . 'table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{border:1px solid #666;padding:4px;vertical-align:top}'
        . 'th{background:#eee} .ok{color:#166534;font-weight:700}.err{color:#991b1b;font-weight:700}'
        . '</style></head><body>';
    $html .= '<h1>Reporte carga automática de equipos</h1>';
    $html .= '<div class="meta">Torneo: <strong>' . $esc($torneoNombre) . '</strong> [#' . (int)$torneo_id . '] · Generado: ' . $esc($fechaGen) . '</div>';
    $html .= '<table><tr><th>Total</th><th>OK</th><th>Error</th></tr><tr>'
        . '<td>' . (int)($res['total'] ?? 0) . '</td>'
        . '<td>' . (int)($res['ok'] ?? 0) . '</td>'
        . '<td>' . (int)($res['error'] ?? 0) . '</td></tr></table>';
    $html .= '<table><tr><th>Equipo</th><th>Integrantes</th><th>Resultado</th><th>Error / Cómo resolver</th></tr>';
    foreach ($equipos as $eq) {
        $ints = '';
        foreach ((array)($eq['integrantes'] ?? []) as $j) {
            $ced = trim((string)($j['cedula'] ?? ''));
            $nom = trim((string)($j['nombre'] ?? ''));
            $idu = (int)($j['id_usuario'] ?? 0);
            $nf = (int)($j['numfvd'] ?? 0);
            $ints .= $esc(($ced !== '' ? $ced : 'S/C') . ' - ' . ($nom !== '' ? $nom : 'SIN NOMBRE') . ' [id_usuario: ' . $idu . ' | numfvd: ' . $nf . ']');
            if (empty($j['completo'])) {
                $ints .= ' (incompleto)';
            }
            $ints .= '<br>';
        }
        $okEq = !empty($eq['ok']);
        $html .= '<tr><td><strong>' . $esc($eq['equipo'] ?? '') . '</strong><br>Línea ' . (int)($eq['linea_inicio'] ?? 0) . '</td>'
            . '<td>' . ($ints !== '' ? $ints : 'Sin integrantes') . '</td>'
            . '<td class="' . ($okEq ? 'ok' : 'err') . '">' . ($okEq ? 'OK' : 'ERROR') . '</td>'
            . '<td>' . ($okEq ? 'Sin acciones pendientes.' : ($esc($eq['error'] ?? '') . '<br><small>Cómo resolver: ' . $esc($eq['como_resolver'] ?? '') . '</small>')) . '</td></tr>';
    }
    $html .= '</table></body></html>';
    $filename = 'reporte_carga_masiva_torneo_' . (int)$torneo_id . '_' . date('Ymd_His');
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $dompdfOk = is_file($autoload) && is_readable($autoload);
    if ($dompdfOk) {
        try {
            require_once $autoload;
            if (class_exists(\Dompdf\Dompdf::class)) {
                $opt = new \Dompdf\Options();
                $opt->set('isRemoteEnabled', false);
                $opt->set('isHtml5ParserEnabled', true);
                $opt->set('defaultFont', 'DejaVu Sans');
                $pdf = new \Dompdf\Dompdf($opt);
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('letter', 'portrait');
                $pdf->render();
                while (ob_get_level()) {
                    ob_end_clean();
                }
                $pdf->stream($filename . '.pdf', ['Attachment' => true]);
                exit;
            }
        } catch (Throwable $e) {
            // fallback html
        }
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_imprimir.html"');
    echo $html;
    exit;
}
if ($action === 'carga_masiva_parejas_reporte_pdf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $repo = $_SESSION['carga_masiva_parejas_reportes'][$torneo_id] ?? null;
    if (!is_array($repo) || empty($repo['reporte_proceso'])) {
        http_response_code(404);
        exit('No hay reporte disponible para este torneo.');
    }
    $torneoNombre = (string)($repo['torneo_nombre'] ?? ('Torneo ' . $torneo_id));
    $fechaGen = (string)($repo['fecha'] ?? date('Y-m-d H:i:s'));
    $proc = (array)$repo['reporte_proceso'];
    $res = (array)($proc['resumen'] ?? []);
    $equipos = (array)($proc['equipos'] ?? []);
    $esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . '@page{size:letter portrait;margin:12mm}body{font-family:DejaVu Sans,sans-serif;font-size:9pt;color:#111}'
        . 'h1{font-size:13pt;margin:0 0 4px} .meta{font-size:8pt;color:#444;margin-bottom:8px}'
        . 'table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{border:1px solid #666;padding:4px;vertical-align:top}'
        . 'th{background:#eee} .ok{color:#166534;font-weight:700}.err{color:#991b1b;font-weight:700}'
        . '</style></head><body>';
    $html .= '<h1>Reporte carga automática de parejas</h1>';
    $html .= '<div class="meta">Torneo: <strong>' . $esc($torneoNombre) . '</strong> [#' . (int)$torneo_id . '] · Generado: ' . $esc($fechaGen) . '</div>';
    $html .= '<table><tr><th>Total</th><th>OK</th><th>Error</th></tr><tr>'
        . '<td>' . (int)($res['total'] ?? 0) . '</td>'
        . '<td>' . (int)($res['ok'] ?? 0) . '</td>'
        . '<td>' . (int)($res['error'] ?? 0) . '</td></tr></table>';
    $html .= '<table><tr><th>Pareja</th><th>Integrantes</th><th>Resultado</th><th>Error / Cómo resolver</th></tr>';
    foreach ($equipos as $eq) {
        $ints = '';
        foreach ((array)($eq['integrantes'] ?? []) as $j) {
            $ced = trim((string)($j['cedula'] ?? ''));
            $nom = trim((string)($j['nombre'] ?? ''));
            $idu = (int)($j['id_usuario'] ?? 0);
            $nf = (int)($j['numfvd'] ?? 0);
            $ints .= $esc(($ced !== '' ? $ced : 'S/C') . ' - ' . ($nom !== '' ? $nom : 'SIN NOMBRE') . ' [id_usuario: ' . $idu . ' | numfvd: ' . $nf . ']');
            if (empty($j['completo'])) {
                $ints .= ' (incompleto)';
            }
            $ints .= '<br>';
        }
        $okEq = !empty($eq['ok']);
        $html .= '<tr><td><strong>' . $esc($eq['equipo'] ?? '') . '</strong><br>Línea ' . (int)($eq['linea_inicio'] ?? 0) . '</td>'
            . '<td>' . ($ints !== '' ? $ints : 'Sin integrantes') . '</td>'
            . '<td class="' . ($okEq ? 'ok' : 'err') . '">' . ($okEq ? 'OK' : 'ERROR') . '</td>'
            . '<td>' . ($okEq ? 'Sin acciones pendientes.' : ($esc($eq['error'] ?? '') . '<br><small>Cómo resolver: ' . $esc($eq['como_resolver'] ?? '') . '</small>')) . '</td></tr>';
    }
    $html .= '</table></body></html>';
    $filename = 'reporte_carga_masiva_parejas_torneo_' . (int)$torneo_id . '_' . date('Ymd_His');
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $dompdfOk = is_file($autoload) && is_readable($autoload);
    if ($dompdfOk) {
        try {
            require_once $autoload;
            if (class_exists(\Dompdf\Dompdf::class)) {
                $opt = new \Dompdf\Options();
                $opt->set('isRemoteEnabled', false);
                $opt->set('isHtml5ParserEnabled', true);
                $opt->set('defaultFont', 'DejaVu Sans');
                $pdf = new \Dompdf\Dompdf($opt);
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('letter', 'portrait');
                $pdf->render();
                while (ob_get_level()) {
                    ob_end_clean();
                }
                $pdf->stream($filename . '.pdf', ['Attachment' => true]);
                exit;
            }
        } catch (Throwable $e) {
            // fallback html
        }
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_imprimir.html"');
    echo $html;
    exit;
}

// Mantener torneo activo en sesión para toda la vista de gestión.
$active_session_torneo_id = (int)($_SESSION['active_tournament_id'] ?? 0);
if ($action === 'panel' && !empty($torneo_id) && Auth::canAccessTournament((int)$torneo_id)) {
    $_SESSION['active_tournament_id'] = (int)$torneo_id;
    $active_session_torneo_id = (int)$torneo_id;
}
if ($action !== 'index') {
    // Blindaje: si viene torneo_id explícito en la URL y es accesible, SIEMPRE prevalece.
    if (!empty($_GET['torneo_id']) && Auth::canAccessTournament((int)$torneo_id)) {
        $_SESSION['active_tournament_id'] = (int)$torneo_id;
        $active_session_torneo_id = (int)$torneo_id;
    } elseif ($active_session_torneo_id > 0 && Auth::canAccessTournament($active_session_torneo_id)) {
        $torneo_id = $active_session_torneo_id;
        $_GET['torneo_id'] = $active_session_torneo_id;
    } elseif (!empty($torneo_id) && Auth::canAccessTournament((int)$torneo_id)) {
        $_SESSION['active_tournament_id'] = (int)$torneo_id;
    }
}

// Manejar acciones POST - DEBE estar antes de cualquier output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? $action;
    $post_torneo_id_ctx = (int)($_POST['torneo_id'] ?? 0);
    $get_torneo_id_ctx = (int)($_GET['torneo_id'] ?? 0);
    $acciones_criticas_ctx = ['guardar_equipo_sitio', 'carga_masiva_equipos_validar', 'carga_masiva_equipos_sitio', 'carga_masiva_parejas_validar', 'carga_masiva_parejas_sitio'];
    if (in_array($post_action, $acciones_criticas_ctx, true) && $post_torneo_id_ctx > 0) {
        $ctx_ok = true;
        $ctx_msg = '';
        if ($get_torneo_id_ctx > 0 && $get_torneo_id_ctx !== $post_torneo_id_ctx) {
            $ctx_ok = false;
            $ctx_msg = 'Contexto inválido: el torneo de la URL no coincide con el formulario. Recargue la pantalla y reintente.';
        }
        $active_ctx = (int)($_SESSION['active_tournament_id'] ?? 0);
        if ($ctx_ok && $active_ctx > 0 && $active_ctx !== $post_torneo_id_ctx) {
            $ctx_ok = false;
            $ctx_msg = 'Contexto inválido: el torneo activo cambió durante la operación. Recargue y confirme el torneo antes de continuar.';
        }
        if (!$ctx_ok) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $ctx_msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // Verificar CSRF
    $csrf_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (!$csrf_token || !$session_token || !hash_equals($session_token, $csrf_token)) {
        $_SESSION['error'] = 'Token de seguridad inválido. Por favor, recarga la página e intenta nuevamente.';
        // Si hay torneo_id en POST, redirigir al panel; de lo contrario, al índice
        $redirect_torneo_id = (int)($_POST['torneo_id'] ?? 0);
        if ($redirect_torneo_id > 0) {
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $redirect_torneo_id]));
        } else {
            header('Location: ' . buildRedirectUrl('index'));
        }
        exit;
    }
    
    // Bloquear acciones de modificación si el torneo está cerrado (las de carga masiva responden JSON)
    $torneo_id_check = (int)($_POST['torneo_id'] ?? 0);
    $post_json_carga_masiva = in_array($post_action, ['carga_masiva_equipos_validar', 'carga_masiva_equipos_sitio', 'carga_masiva_parejas_validar', 'carga_masiva_parejas_sitio'], true);
    if ($torneo_id_check && isTorneoLocked($torneo_id_check) && ($post_action !== 'cerrar_torneo') && !$post_json_carga_masiva) {
        $_SESSION['error'] = 'Este torneo está cerrado y no admite modificaciones.';
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id_check]));
        exit;
    }
    
    switch ($post_action) {
        case 'guardar_equipo_sitio':
            $tid = (int)($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
            if ($tid <= 0) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo no especificado'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            verificarPermisosTorneo($tid, $user_id, $is_admin_general);
            require_once __DIR__ . '/../lib/GuardarEquipoSitioService.php';
            $input = $_POST;
            if ((int)($input['torneo_id'] ?? 0) !== $tid) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'torneo_id no coincide'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            error_log('=== guardar_equipo_sitio POST torneo_gestion (index/admin, sesión OK) ===');
            header('Content-Type: application/json; charset=utf-8');
            try {
                $pdo = DB::pdo();
                $out = GuardarEquipoSitioService::ejecutar($pdo, $input, Auth::id() ?: null);
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                http_response_code(500);
                error_log('guardar_equipo_sitio: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        case 'carga_masiva_equipos_validar':
            $tid = (int)($_POST['torneo_id'] ?? 0);
            if ($tid <= 0) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo no especificado'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            verificarPermisosTorneo($tid, $user_id, $is_admin_general);
            if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Adjunte el archivo.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            require_once __DIR__ . '/../lib/CargaMasivaEquiposSitioService.php';
            header('Content-Type: application/json; charset=utf-8');
            $pdo = DB::pdo();
            $parsed = CargaMasivaEquiposSitioService::parseArchivo(
                (string)$_FILES['archivo']['tmp_name'],
                (string)($_FILES['archivo']['name'] ?? 'upload.csv')
            );
            if (isset($parsed['error'])) {
                echo json_encode(['success' => false, 'message' => $parsed['error']], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $val = CargaMasivaEquiposSitioService::validarPrevio($pdo, $tid, $parsed['bloques']);
            $_SESSION['carga_masiva_reportes'][$tid] = [
                'torneo_nombre' => (string)($_POST['torneo_nombre'] ?? ''),
                'fecha' => date('Y-m-d H:i:s'),
                'validacion' => $val,
            ];
            echo json_encode([
                'success' => $val['puede_proceder'],
                'message' => $val['puede_proceder']
                    ? 'Archivo válido. Revise el aviso de borrado y confirme para ejecutar.'
                    : 'Revise errores antes de continuar.',
                'validacion' => $val,
                'frase_confirmacion' => CargaMasivaEquiposSitioService::CONFIRMACION_REEMPLAZO,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'carga_masiva_equipos_sitio':
            $tid = (int)($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
            if ($tid <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo no especificado o método inválido'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            verificarPermisosTorneo($tid, $user_id, $is_admin_general);
            if (isTorneoLocked($tid)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo cerrado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'No se recibió archivo.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            require_once __DIR__ . '/../lib/CargaMasivaEquiposSitioService.php';
            header('Content-Type: application/json; charset=utf-8');
            try {
                $pdo = DB::pdo();
                $out = CargaMasivaEquiposSitioService::ejecutarDesdeArchivo(
                    $pdo,
                    $tid,
                    (string)$_FILES['archivo']['tmp_name'],
                    (string)($_FILES['archivo']['name'] ?? 'upload.csv'),
                    Auth::id() ?: null,
                    trim((string)($_POST['confirmar_reemplazo'] ?? ''))
                );
                $stmtT = $pdo->prepare('SELECT nombre FROM tournaments WHERE id = ? LIMIT 1');
                $stmtT->execute([$tid]);
                $tn = (string)($stmtT->fetchColumn() ?: ('Torneo ' . $tid));
                $_SESSION['carga_masiva_reportes'][$tid] = [
                    'torneo_nombre' => $tn,
                    'fecha' => date('Y-m-d H:i:s'),
                    'reporte_proceso' => $out['reporte_proceso'] ?? null,
                    'detalles' => $out['detalles'] ?? [],
                ];
                $out['reporte_pdf_url'] = buildRedirectUrl('carga_masiva_equipos_reporte_pdf', ['torneo_id' => $tid, 't' => time()]);
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                http_response_code(500);
                error_log('carga_masiva_equipos_sitio: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        case 'carga_masiva_parejas_validar':
            $tid = (int)($_POST['torneo_id'] ?? 0);
            $clubId = (int)($_POST['club_id'] ?? 0);
            if ($tid <= 0) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo no especificado'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            verificarPermisosTorneo($tid, $user_id, $is_admin_general);
            if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Adjunte el archivo.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            require_once __DIR__ . '/../lib/CargaMasivaParejasSitioService.php';
            header('Content-Type: application/json; charset=utf-8');
            $pdo = DB::pdo();
            $parsed = CargaMasivaParejasSitioService::parseArchivo(
                (string)$_FILES['archivo']['tmp_name'],
                (string)($_FILES['archivo']['name'] ?? 'upload.csv')
            );
            if (isset($parsed['error'])) {
                echo json_encode(['success' => false, 'message' => $parsed['error']], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $val = CargaMasivaParejasSitioService::validarPrevio($pdo, $tid, $parsed['bloques'], $clubId);
            $_SESSION['carga_masiva_parejas_reportes'][$tid] = [
                'torneo_nombre' => (string)($_POST['torneo_nombre'] ?? ''),
                'fecha' => date('Y-m-d H:i:s'),
                'validacion' => $val,
            ];
            echo json_encode([
                'success' => $val['puede_proceder'],
                'message' => $val['puede_proceder']
                    ? 'Archivo válido. Revise el aviso de borrado y confirme para ejecutar.'
                    : 'Revise errores antes de continuar.',
                'validacion' => $val,
                'frase_confirmacion' => CargaMasivaParejasSitioService::CONFIRMACION_REEMPLAZO,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'carga_masiva_parejas_sitio':
            $tid = (int)($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
            $clubId = (int)($_POST['club_id'] ?? 0);
            if ($tid <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo no especificado o método inválido'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            verificarPermisosTorneo($tid, $user_id, $is_admin_general);
            if (isTorneoLocked($tid)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Torneo cerrado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($clubId <= 0) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Seleccione el club al que pertenecen las parejas.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'No se recibió archivo.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            require_once __DIR__ . '/../lib/CargaMasivaParejasSitioService.php';
            header('Content-Type: application/json; charset=utf-8');
            try {
                $pdo = DB::pdo();
                $out = CargaMasivaParejasSitioService::ejecutarDesdeArchivo(
                    $pdo,
                    $tid,
                    (string)$_FILES['archivo']['tmp_name'],
                    (string)($_FILES['archivo']['name'] ?? 'upload.csv'),
                    $clubId,
                    Auth::id() ?: null,
                    trim((string)($_POST['confirmar_reemplazo'] ?? ''))
                );
                $stmtT = $pdo->prepare('SELECT nombre FROM tournaments WHERE id = ? LIMIT 1');
                $stmtT->execute([$tid]);
                $tn = (string)($stmtT->fetchColumn() ?: ('Torneo ' . $tid));
                $_SESSION['carga_masiva_parejas_reportes'][$tid] = [
                    'torneo_nombre' => $tn,
                    'fecha' => date('Y-m-d H:i:s'),
                    'reporte_proceso' => $out['reporte_proceso'] ?? null,
                    'detalles' => $out['detalles'] ?? [],
                ];
                $out['reporte_pdf_url'] = buildRedirectUrl('carga_masiva_parejas_reporte_pdf', ['torneo_id' => $tid, 't' => time()]);
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                http_response_code(500);
                error_log('carga_masiva_parejas_sitio: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        case 'carga_excel_tablas_procesar':
            $tid = (int)($_POST['torneo_id'] ?? 0);
            if ($tid <= 0) {
                $_SESSION['error'] = 'Torneo no especificado.';
                header('Location: ' . buildRedirectUrl('index'));
                exit;
            }
            verificarPermisosTorneo($tid, $user_id, $is_admin_general);
            if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '')) {
                $_SESSION['error'] = 'Adjunte un archivo para procesar.';
                header('Location: ' . buildRedirectUrl('carga_excel_tablas', ['torneo_id' => $tid]));
                exit;
            }

            $tabla = strtolower(trim((string)($_POST['tabla_destino'] ?? '')));
            $permitidas = ['inscritos', 'equipos', 'partiresul'];
            if (!in_array($tabla, $permitidas, true)) {
                $_SESSION['error'] = 'Tabla destino no válida.';
                header('Location: ' . buildRedirectUrl('carga_excel_tablas', ['torneo_id' => $tid]));
                exit;
            }

            $columnasSeleccionadas = array_values(array_unique(array_map(
                static fn ($c) => tgNormalizeHeader((string)$c),
                (array)($_POST['columnas'] ?? [])
            )));
            if ($columnasSeleccionadas === []) {
                $_SESSION['error'] = 'Seleccione al menos una columna.';
                header('Location: ' . buildRedirectUrl('carga_excel_tablas', ['torneo_id' => $tid]));
                exit;
            }

            try {
                $archivo = tgReadRowsFromUpload((string)$_FILES['archivo']['tmp_name'], (string)($_FILES['archivo']['name'] ?? 'archivo.xlsx'));
                $headers = $archivo['headers'];
                $rows = $archivo['rows'];
                if ($headers === [] || $rows === []) {
                    throw new Exception('El archivo no contiene cabecera o filas de datos.');
                }

                $headerMap = [];
                foreach ($headers as $i => $h) {
                    $headerMap[tgNormalizeHeader((string)$h)] = $i;
                }

                $assocRows = [];
                foreach ($rows as $r) {
                    $assoc = [];
                    foreach ($headerMap as $hk => $idx) {
                        $assoc[$hk] = trim((string)($r[$idx] ?? ''));
                    }
                    $isEmpty = true;
                    foreach ($assoc as $v) {
                        if ($v !== '') {
                            $isEmpty = false;
                            break;
                        }
                    }
                    if (!$isEmpty) {
                        $assocRows[] = $assoc;
                    }
                }
                if ($assocRows === []) {
                    throw new Exception('No hay filas útiles para procesar.');
                }

                $pdo = DB::pdo();
                $pdo->beginTransaction();
                $ok = 0;
                $errores = [];

                if ($tabla === 'inscritos') {
                    $upCols = array_values(array_intersect($columnasSeleccionadas, ['id_club', 'codigo_equipo', 'estatus', 'cedula', 'numfvd']));
                    if ($upCols === []) {
                        throw new Exception('Para inscritos seleccione al menos un campo editable (id_club, codigo_equipo, estatus, cedula, numfvd).');
                    }
                    foreach ($assocRows as $i => $row) {
                        $idUsuario = (int)($row['id_usuario'] ?? 0);
                        if ($idUsuario <= 0) {
                            $errores[] = 'Fila ' . ($i + 2) . ': id_usuario es obligatorio.';
                            continue;
                        }
                        $setParts = [];
                        $params = [];
                        foreach ($upCols as $c) {
                            if (!array_key_exists($c, $row)) {
                                continue;
                            }
                            $setParts[] = "$c = ?";
                            $params[] = $row[$c];
                        }
                        if ($setParts === []) {
                            continue;
                        }
                        $params[] = $tid;
                        $params[] = $idUsuario;
                        $sql = "UPDATE inscritos SET " . implode(', ', $setParts) . " WHERE torneo_id = ? AND id_usuario = ?";
                        $st = $pdo->prepare($sql);
                        $st->execute($params);
                        if ($st->rowCount() > 0) {
                            $ok++;
                            continue;
                        }
                        $insertCols = ['torneo_id', 'id_usuario'];
                        $insertVals = [$tid, $idUsuario];
                        foreach ($upCols as $c) {
                            if (array_key_exists($c, $row)) {
                                $insertCols[] = $c;
                                $insertVals[] = $row[$c];
                            }
                        }
                        $ph = implode(',', array_fill(0, count($insertCols), '?'));
                        $sqlIns = "INSERT INTO inscritos (" . implode(',', $insertCols) . ") VALUES ($ph)";
                        $stIns = $pdo->prepare($sqlIns);
                        $stIns->execute($insertVals);
                        $ok += 1;
                    }
                } elseif ($tabla === 'equipos') {
                    $upCols = array_values(array_intersect($columnasSeleccionadas, ['nombre_equipo', 'id_club', 'estatus', 'ganados', 'perdidos', 'efectividad', 'puntos']));
                    if ($upCols === []) {
                        throw new Exception('Para equipos seleccione al menos un campo editable.');
                    }
                    foreach ($assocRows as $i => $row) {
                        $codigo = trim((string)($row['codigo_equipo'] ?? ''));
                        if ($codigo === '') {
                            $errores[] = 'Fila ' . ($i + 2) . ': codigo_equipo es obligatorio.';
                            continue;
                        }
                        $setParts = [];
                        $params = [];
                        foreach ($upCols as $c) {
                            if (!array_key_exists($c, $row)) {
                                continue;
                            }
                            $setParts[] = "$c = ?";
                            $params[] = $row[$c];
                        }
                        if ($setParts === []) {
                            continue;
                        }
                        $params[] = $tid;
                        $params[] = $codigo;
                        $sql = "UPDATE equipos SET " . implode(', ', $setParts) . " WHERE torneo_id = ? AND codigo_equipo = ?";
                        $st = $pdo->prepare($sql);
                        $st->execute($params);
                        if ($st->rowCount() > 0) {
                            $ok++;
                            continue;
                        }
                        $insertCols = ['torneo_id', 'codigo_equipo'];
                        $insertVals = [$tid, $codigo];
                        foreach ($upCols as $c) {
                            if (array_key_exists($c, $row)) {
                                $insertCols[] = $c;
                                $insertVals[] = $row[$c];
                            }
                        }
                        $ph = implode(',', array_fill(0, count($insertCols), '?'));
                        $sqlIns = "INSERT INTO equipos (" . implode(',', $insertCols) . ") VALUES ($ph)";
                        $stIns = $pdo->prepare($sqlIns);
                        $stIns->execute($insertVals);
                        $ok += 1;
                    }
                } else {
                    require_once __DIR__ . '/../lib/Tournament/Handlers/TournamentActionHandler.php';
                    $grupos = [];
                    foreach ($assocRows as $i => $row) {
                        $partida = (int)($row['partida'] ?? 0);
                        $mesa = (int)($row['mesa'] ?? 0);
                        $secuencia = (int)($row['secuencia'] ?? 0);
                        if ($partida <= 0 || $mesa <= 0 || $secuencia <= 0) {
                            $errores[] = 'Fila ' . ($i + 2) . ': partida, mesa y secuencia son obligatorias.';
                            continue;
                        }
                        $k = $partida . ':' . $mesa;
                        if (!isset($grupos[$k])) {
                            $grupos[$k] = ['partida' => $partida, 'mesa' => $mesa, 'rows' => []];
                        }
                        $grupos[$k]['rows'][$secuencia] = $row;
                    }

                    foreach ($grupos as $grupo) {
                        $partida = (int)$grupo['partida'];
                        $mesa = (int)$grupo['mesa'];
                        $stMesa = $pdo->prepare("SELECT id, id_usuario, secuencia, resultado1, resultado2, ff, sancion, tarjeta, chancleta, zapato FROM partiresul WHERE torneo_id = ? AND partida = ? AND mesa = ? ORDER BY secuencia ASC");
                        $stMesa->execute([$tid, $partida, $mesa]);
                        $dbRows = $stMesa->fetchAll(PDO::FETCH_ASSOC);
                        if (count($dbRows) !== 4) {
                            $errores[] = "Ronda $partida mesa $mesa: se esperaban 4 jugadores en partiresul.";
                            continue;
                        }

                        $jugadores = [];
                        foreach ($dbRows as $db) {
                            $sec = (int)$db['secuencia'];
                            $src = $grupo['rows'][$sec] ?? [];
                            $jugadores[] = [
                                'id' => (int)$db['id'],
                                'id_usuario' => (int)$db['id_usuario'],
                                'secuencia' => $sec,
                                'resultado1' => in_array('resultado1', $columnasSeleccionadas, true) ? (int)($src['resultado1'] ?? $db['resultado1']) : (int)$db['resultado1'],
                                'resultado2' => in_array('resultado2', $columnasSeleccionadas, true) ? (int)($src['resultado2'] ?? $db['resultado2']) : (int)$db['resultado2'],
                                'ff' => in_array('ff', $columnasSeleccionadas, true) ? (int)($src['ff'] ?? $db['ff']) : (int)$db['ff'],
                                'sancion' => in_array('sancion', $columnasSeleccionadas, true) ? (int)($src['sancion'] ?? $db['sancion']) : (int)$db['sancion'],
                                'tarjeta' => in_array('tarjeta', $columnasSeleccionadas, true) ? (int)($src['tarjeta'] ?? $db['tarjeta']) : (int)$db['tarjeta'],
                                'chancleta' => (int)$db['chancleta'],
                                'zapato' => (int)$db['zapato'],
                            ];
                        }

                        \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                            $pdo,
                            $tid,
                            $partida,
                            $mesa,
                            $jugadores,
                            (int)(Auth::id() ?: 0),
                            'Carga Excel'
                        );
                        $ok++;
                    }
                }

                actualizarEstadisticasInscritos($tid, true);
                $pdo->commit();

                $msg = "Carga aplicada en tabla {$tabla}. Registros procesados: {$ok}.";
                if ($errores !== []) {
                    $msg .= ' Observaciones: ' . implode(' | ', array_slice($errores, 0, 8));
                }
                $_SESSION['success'] = $msg;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = 'Error en carga Excel: ' . $e->getMessage();
            }

            header('Location: ' . buildRedirectUrl('carga_excel_tablas', ['torneo_id' => $tid]));
            exit;

        case 'generar_ronda':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            generarRonda($torneo_id, $user_id, $is_admin_general);
            break;
        
        case 'vincular_torneos_evento':
            $parent_torneo_id = (int)($_POST['parent_torneo_id'] ?? 0);
            if ($parent_torneo_id <= 0) {
                $_SESSION['error'] = 'Debe seleccionar un torneo principal válido.';
                header('Location: ' . buildRedirectUrl('index'));
                exit;
            }
            verificarPermisosTorneo($parent_torneo_id, $user_id, $is_admin_general);
            $idsSeleccionados = $_POST['torneos_ids'] ?? [];
            if (!is_array($idsSeleccionados)) {
                $idsSeleccionados = [];
            }
            $ids = [];
            foreach ($idsSeleccionados as $idRaw) {
                $id = (int)$idRaw;
                if ($id > 0 && $id !== $parent_torneo_id && Auth::canAccessTournament($id)) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
            try {
                $pdo = DB::pdo();
                if (!tournamentsColumnExists('parent_event_id')) {
                    throw new Exception('La columna parent_event_id no está disponible.');
                }
                $stParent = $pdo->prepare("UPDATE tournaments SET parent_event_id = ? WHERE id = ?");
                $stParent->execute([$parent_torneo_id, $parent_torneo_id]);
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $params = array_merge([$parent_torneo_id], $ids);
                    $sql = "UPDATE tournaments SET parent_event_id = ? WHERE id IN ({$placeholders})";
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                }
                $_SESSION['success'] = 'Vinculación aplicada correctamente.';
            } catch (Throwable $e) {
                $_SESSION['error'] = 'No se pudo vincular torneos: ' . $e->getMessage();
            }
            header('Location: ' . buildRedirectUrl('vincular_torneos', ['torneo_id' => $parent_torneo_id]));
            exit;

        case 'desvincular_torneo_evento':
            $parent_torneo_id = (int)($_POST['parent_torneo_id'] ?? 0);
            $target_torneo_id = (int)($_POST['target_torneo_id'] ?? 0);
            if ($parent_torneo_id <= 0 || $target_torneo_id <= 0) {
                $_SESSION['error'] = 'Parámetros inválidos para desvincular.';
                header('Location: ' . buildRedirectUrl('index'));
                exit;
            }
            verificarPermisosTorneo($parent_torneo_id, $user_id, $is_admin_general);
            if (!Auth::canAccessTournament($target_torneo_id)) {
                $_SESSION['error'] = 'No tiene permisos para desvincular el torneo seleccionado.';
                header('Location: ' . buildRedirectUrl('vincular_torneos', ['torneo_id' => $parent_torneo_id]));
                exit;
            }
            try {
                $pdo = DB::pdo();
                $st = $pdo->prepare("UPDATE tournaments SET parent_event_id = NULL WHERE id = ?");
                $st->execute([$target_torneo_id]);
                $_SESSION['success'] = 'Torneo desvinculado correctamente.';
            } catch (Throwable $e) {
                $_SESSION['error'] = 'No se pudo desvincular el torneo: ' . $e->getMessage();
            }
            header('Location: ' . buildRedirectUrl('vincular_torneos', ['torneo_id' => $parent_torneo_id]));
            exit;

        case 'eliminar_ultima_ronda':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            eliminarUltimaRonda($torneo_id, $user_id, $is_admin_general);
            break;
            
        case 'guardar_resultados':
            guardarResultados($user_id, $is_admin_general);
            break;
            
        case 'guardar_mesa_adicional':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            $ronda = (int)($_POST['ronda'] ?? 0);
            guardarMesaAdicional($torneo_id, $ronda, $user_id, $is_admin_general);
            break;
            
        case 'cargar_inscritos_movimiento':
            $torneo_id = (int) ($_POST['torneo_id'] ?? 0);
            cargarInscritosDesdeMovimiento($torneo_id, $user_id, $is_admin_general);
            break;

        case 'actualizar_estadisticas':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            actualizarEstadisticasManual($torneo_id, $user_id, $is_admin_general);
            break;

        case 'recalcular_bye':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            recalcularBye($torneo_id, $user_id, $is_admin_general);
            break;
            
        case 'ejecutar_reasignacion':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            $ronda = (int)($_POST['ronda'] ?? 0);
            $mesa = (int)($_POST['mesa'] ?? 0);
            ejecutarReasignacion($torneo_id, $ronda, $mesa, $user_id, $is_admin_general);
            break;
        
        case 'cerrar_torneo':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            ensureTournamentsLockedColumn();
            try {
                if ($torneo_id > 0 && tournamentsLockedColumnExists()) {
                    actualizarEstadisticasInscritos($torneo_id, true);
                    $stmt = DB::pdo()->prepare("UPDATE tournaments SET locked = 1 WHERE id = ?");
                    $stmt->execute([$torneo_id]);
                    $_SESSION['success'] = 'Torneo cerrado definitivamente. No se podrán realizar más cambios.';
                } else {
                    $_SESSION['error'] = 'No fue posible cerrar el torneo (estructura no disponible).';
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error al cerrar el torneo: ' . $e->getMessage();
            }
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
            exit;

        case 'enviar_notificacion_torneo':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            enviarNotificacionTorneo($torneo_id, $user_id, $is_admin_general);
            break;

        case 'guardar_asignacion_mesas_operador':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            $ronda = (int)($_POST['ronda'] ?? 0);
            guardarAsignacionMesasOperador($torneo_id, $ronda, $user_id, $is_admin_general);
            break;

        case 'verificar_acta_aprobar':
            verificarActaAprobar($user_id, $is_admin_general);
            break;

        case 'verificar_acta_rechazar':
            verificarActaRechazar($user_id, $is_admin_general);
            break;

        case 'cambiar_estatus_inscrito':
            $inscripcion_id = (int)($_POST['inscripcion_id'] ?? 0);
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            $nuevo_estatus = (int)($_POST['estatus'] ?? 0);
            if ($inscripcion_id <= 0 || $torneo_id <= 0 || !InscritosHelper::isValidEstatus($nuevo_estatus)) {
                $_SESSION['error'] = 'Parámetros inválidos para cambiar estatus.';
                header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
                exit;
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE id = ? AND torneo_id = ?");
            $stmt->execute([$inscripcion_id, $torneo_id]);
            if (!$stmt->fetch()) {
                $_SESSION['error'] = 'Inscripción no encontrada.';
                header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
                exit;
            }
            // Guardar como entero (columna INT); si fuera ENUM usar InscritosHelper::ESTATUS_MAP[$nuevo_estatus]
            $stmt = $pdo->prepare("UPDATE inscritos SET estatus = ? WHERE id = ? AND torneo_id = ?");
            $stmt->execute([$nuevo_estatus, $inscripcion_id, $torneo_id]);
            $_SESSION['success'] = 'Estatus del inscrito actualizado.';
            header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
            exit;

        case 'validar_pago_inscrito':
        case 'toggle_pago_inscrito':
            $inscripcion_id = (int) ($_POST['inscripcion_id'] ?? 0);
            $torneo_id = (int) ($_POST['torneo_id'] ?? 0);
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            require_once __DIR__ . '/../lib/InscripcionPagoService.php';
            $pagado = isset($_POST['pagado']) ? (int) $_POST['pagado'] : 1;
            $resVal = $pagado === 1
                ? InscripcionPagoService::validarPagoInscripcion(DB::pdo(), $inscripcion_id, $torneo_id)
                : InscripcionPagoService::marcarPendienteInscripcion(DB::pdo(), $inscripcion_id, $torneo_id);
            if ($resVal['ok']) {
                $_SESSION['success'] = $resVal['message'];
            } else {
                $_SESSION['error'] = $resVal['message'];
            }
            header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
            exit;

        case 'enviar_recordatorio_pago_inscrito':
            $inscripcion_id = (int) ($_POST['inscripcion_id'] ?? 0);
            $torneo_id = (int) ($_POST['torneo_id'] ?? 0);
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            require_once __DIR__ . '/../lib/InscripcionPagoService.php';
            $resRec = InscripcionPagoService::enviarRecordatorioPago(DB::pdo(), $inscripcion_id, $torneo_id);
            if ($resRec['ok']) {
                $_SESSION['success'] = $resRec['message'];
                if (!empty($resRec['whatsapp_url'])) {
                    $_SESSION['whatsapp_redirect_inscripcion'] = $resRec['whatsapp_url'];
                }
            } else {
                $_SESSION['error'] = $resRec['message'];
            }
            header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
            exit;

        default:
            $_SESSION['error'] = 'Acción POST no válida';
            // Si hay torneo_id en POST, redirigir al panel; de lo contrario, al índice
            $redirect_torneo_id = (int)($_POST['torneo_id'] ?? 0);
            if ($redirect_torneo_id > 0) {
                header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $redirect_torneo_id]));
            } else {
                header('Location: ' . buildRedirectUrl('index'));
            }
            exit;
    }
}

// Determinar qué vista mostrar
$view_file = null;
$view_data = [];
$error_message = null;

try {
    switch ($action) {
        case 'index':
            $filtro_torneos = isset($_GET['filtro']) && in_array($_GET['filtro'], ['realizados', 'en_proceso', 'por_realizar'], true) ? $_GET['filtro'] : null;
            $torneos = obtenerTorneosGestion($user_id, $is_admin_general, $filtro_torneos);
            $use_standalone = (basename($_SERVER['PHP_SELF'] ?? '') === 'admin_torneo.php');
            $view_file = $use_standalone ? __DIR__ . '/gestion_torneos/index-moderno.php' : __DIR__ . '/gestion_torneos/index.php';
            $view_data = ['torneos' => $torneos, 'filtro_torneos' => $filtro_torneos, 'is_admin_general' => $is_admin_general];
            break;
            
        case 'dashboard':
            // Redirigir 'dashboard' a 'panel' si hay torneo_id, de lo contrario a 'index'
            if ($torneo_id > 0) {
                header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
                exit;
            } else {
                header('Location: ' . buildRedirectUrl('index'));
                exit;
            }
            break;
            
        case 'panel':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            // Usar siempre panel-moderno.php (común para todos los tipos de torneo)
            // La vista se adapta dinámicamente según la modalidad del torneo
            $view_file = __DIR__ . '/gestion_torneos/panel-moderno.php';
            // Obtener datos según modalidad (obtenerDatosPanel ahora incluye datos de equipos si corresponde)
            $view_data = obtenerDatosPanel($torneo_id);
            // Asegurar que $torneo esté en $view_data (obtenerDatosPanel ya lo incluye, pero por si acaso)
            if (!isset($view_data['torneo']) || !$view_data['torneo']) {
                $view_data['torneo'] = $torneo;
            }
            // También asegurar que torneo_id esté disponible
            $view_data['torneo_id'] = $torneo_id;
            $view_data['context_switcher'] = obtenerContextoTorneoUnificado((int)$torneo_id);
            $view_data['paired_tournaments_status'] = obtenerEstadoParTorneosUnificado((int)$torneo_id);
            break;

        case 'reportes_inscritos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/gestion_torneos/reportes_inscritos.php';
            $view_data = obtenerDatosPanel($torneo_id);
            if (!isset($view_data['torneo']) || !$view_data['torneo']) {
                $view_data['torneo'] = $torneo;
            }
            $view_data['torneo_id'] = $torneo_id;
            break;

        case 'carga_excel_tablas':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/gestion_torneos/carga_excel_tablas.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id];
            break;
        
        case 'vincular_torneos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo principal');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/vincular_torneos.php';
            $view_data = obtenerDatosVincularTorneos($torneo_id, $user_id, $is_admin_general);
            break;
            
        case 'panel_equipos':
            // Redirigir panel_equipos a panel (ahora es común para todos los tipos)
            // Este caso se mantiene solo para compatibilidad con enlaces antiguos
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            // Redirigir al panel común
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
            exit;
            break;
            
        case 'cronometro':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/gestion_torneos/cronometro.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id];
            $use_cronometro_standalone = true;
            break;
            
        case 'gestionar_inscripciones_equipos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/gestionar_inscripciones_equipos.php';
            $view_data = obtenerDatosGestionarInscripcionesEquipos($torneo_id);
            break;
            
        case 'inscribir_equipo_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscribir_equipo_sitio.php';
            $view_data = obtenerDatosInscribirEquipoSitio($torneo_id);
            break;

        case 'carga_masiva_equipos_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            require_once __DIR__ . '/../lib/CargaMasivaEquiposSitioService.php';
            $cache_cleanup = CargaMasivaEquiposSitioService::limpiarCacheCargaMasiva();
            $stmt = DB::pdo()->prepare('SELECT id, nombre, modalidad, locked FROM tournaments WHERE id = ?');
            $stmt->execute([$torneo_id]);
            $torneo_cm = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$torneo_cm || (int)($torneo_cm['modalidad'] ?? 0) !== 3) {
                throw new Exception('Solo torneos modalidad equipos (4 integrantes).');
            }
            $view_file = __DIR__ . '/gestion_torneos/carga_masiva_equipos_sitio.php';
            $view_data = ['torneo' => $torneo_cm, 'torneo_id' => $torneo_id, 'cache_cleanup' => $cache_cleanup];
            break;

        case 'carga_masiva_parejas_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            require_once __DIR__ . '/../lib/CargaMasivaEquiposSitioService.php';
            require_once __DIR__ . '/../lib/CargaMasivaParejasSitioService.php';
            $cache_cleanup = CargaMasivaEquiposSitioService::limpiarCacheCargaMasiva();
            $stmt = DB::pdo()->prepare('SELECT id, nombre, modalidad, locked FROM tournaments WHERE id = ?');
            $stmt->execute([$torneo_id]);
            $torneo_cm = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$torneo_cm || (int)($torneo_cm['modalidad'] ?? 0) !== 2) {
                throw new Exception('Solo torneos modalidad parejas (2).');
            }
            $view_data_insc = obtenerDatosInscribirEquipoSitio($torneo_id);
            $view_file = __DIR__ . '/gestion_torneos/carga_masiva_parejas_sitio.php';
            $view_data = [
                'torneo' => $view_data_insc['torneo'] ?? $torneo_cm,
                'torneo_id' => $torneo_id,
                'cache_cleanup' => $cache_cleanup,
                'clubes_disponibles' => $view_data_insc['clubes_disponibles'] ?? [],
            ];
            break;

        case 'export_access_portal':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $pdoAccess = DB::pdo();
            $stInsc = $pdoAccess->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND CAST(estatus AS CHAR) NOT IN (\'4\',\'retirado\')');
            $stInsc->execute([$torneo_id]);
            $stPart = $pdoAccess->prepare('SELECT COUNT(*) FROM partiresul WHERE id_torneo = ?');
            $stPart->execute([$torneo_id]);
            $view_file = __DIR__ . '/gestion_torneos/export_access_portal.php';
            $view_data = [
                'torneo' => $torneo,
                'torneo_id' => $torneo_id,
                'n_inscritos' => (int) $stInsc->fetchColumn(),
                'n_partidas' => (int) $stPart->fetchColumn(),
            ];
            break;
            
        case 'mesas':
            if (!$torneo_id || !$ronda) {
                throw new Exception('Debe especificar torneo y ronda');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/mesas.php';
            $view_data = obtenerDatosMesas($torneo_id, $ronda, $user_id, $user_role);
            break;

        case 'asignar_mesas_operador':
            if (!$torneo_id || !$ronda) {
                throw new Exception('Debe especificar torneo y ronda');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/asignar_mesas_operador.php';
            $view_data = obtenerDatosAsignarMesasOperador($torneo_id, $ronda);
            break;
            
        case 'rondas':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/rondas.php';
            $view_data = obtenerDatosRondas($torneo_id);
            break;
            
        case 'posiciones':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/posiciones.php';
            $view_data = obtenerDatosPosiciones($torneo_id, $_GET['genero'] ?? null);
            break;

        case 'galeria_fotos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            // Reutilizamos la vista de administración de torneo para mantener funcionalidades y estilos
            $view_file = __DIR__ . '/tournament_admin/galeria_fotos.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id];
            break;
            
        case 'inscripciones':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscripciones.php';
            $view_data = obtenerDatosInscripciones($torneo_id);
            break;

        case 'notificaciones':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/notificaciones_torneo.php';
            $view_data = obtenerDatosNotificacionesTorneo($torneo_id);
            break;

        case 'equipos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/equipos.php';
            $view_data = obtenerDatosEquiposAdmin($torneo_id);
            break;
            
        case 'inscribir_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscribir-sitio.php';
            $view_data = obtenerDatosInscribirSitio($torneo_id, $user_id, $is_admin_general);
            break;
            
        case 'registrar_resultados':
        case 'registrar_resultados_v2':
            if (!$torneo_id) {
                throw new Exception('Debe especificar torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            // Si no se especifica ronda, ir a la última ronda para comenzar a ingresar
            if (!$ronda || $ronda <= 0) {
                $pdo = DB::pdo();
                $stmt = $pdo->prepare("SELECT MAX(partida) FROM partiresul WHERE id_torneo = ?");
                $stmt->execute([$torneo_id]);
                $ultima_ronda = (int)$stmt->fetchColumn();
                if ($ultima_ronda <= 0) {
                    throw new Exception('No hay rondas generadas para este torneo. Genere rondas primero.');
                }
                $ronda = $ultima_ronda;
                $mesa = isset($_GET['mesa']) ? (int)$_GET['mesa'] : 0;
                header('Location: ' . buildRedirectUrl($action, [
                    'torneo_id' => $torneo_id,
                    'ronda' => $ronda,
                    'mesa' => $mesa
                ]));
                exit;
            }
            $view_file = __DIR__ . '/gestion_torneos/registrar-resultados-v2.php';
            $view_data = obtenerDatosRegistroResultados($torneo_id, $ronda, $mesa ?? 0, $user_id, $user_role);
            $ctxReg = obtenerContextoTorneoUnificado((int) $torneo_id);
            $view_data['context_switcher'] = $ctxReg;
            if (!empty($ctxReg['items'])) {
                $idsCtx = array_column($ctxReg['items'], 'id');
                $view_data['map_max_partida_switch'] = torneoGestionMapaMaxPartidasPorTorneo($idsCtx);
            } else {
                $view_data['map_max_partida_switch'] = [];
            }
            break;
            
        case 'cuadricula':
            if (!$torneo_id || !$ronda) {
                throw new Exception('Debe especificar torneo y ronda');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $pdoCu = DB::pdo();
            $stCu = $pdoCu->prepare('SELECT modalidad FROM tournaments WHERE id = ? LIMIT 1');
            $stCu->execute([(int) $torneo_id]);
            $modCu = (int) $stCu->fetchColumn();
            if ($modCu === 3) {
                $activeCu = (int) ($_SESSION['active_tournament_id'] ?? 0);
                if ($activeCu > 0 && (int) $torneo_id !== $activeCu && Auth::canAccessTournament($activeCu)) {
                    header('Location: ' . buildRedirectUrl('cuadricula', ['torneo_id' => $activeCu, 'ronda' => $ronda]));
                    exit;
                }
            }
            $view_file = __DIR__ . '/gestion_torneos/cuadricula.php';
            $view_data = obtenerDatosCuadricula($torneo_id, $ronda);
            $ctxCuad = obtenerContextoTorneoUnificado((int) $torneo_id);
            $view_data['context_switcher'] = $ctxCuad;
            if (!empty($ctxCuad['items'])) {
                $idsCtx = array_column($ctxCuad['items'], 'id');
                $view_data['map_max_partida_switch'] = torneoGestionMapaMaxPartidasPorTorneo($idsCtx);
            } else {
                $view_data['map_max_partida_switch'] = [];
            }
            break;
            
        case 'hojas_anotacion':
            if (!$torneo_id || !$ronda) {
                throw new Exception('Debe especificar torneo y ronda');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/hojas-anotacion.php';
            $view_data = obtenerDatosHojasAnotacion($torneo_id, $ronda);
            $ctxHoj = obtenerContextoTorneoUnificado((int) $torneo_id);
            $view_data['context_switcher'] = $ctxHoj;
            if (!empty($ctxHoj['items'])) {
                $idsCtx = array_column($ctxHoj['items'], 'id');
                $view_data['map_max_partida_switch'] = torneoGestionMapaMaxPartidasPorTorneo($idsCtx);
            } else {
                $view_data['map_max_partida_switch'] = [];
            }
            break;
            
        case 'resumen_individual':
            if (!$torneo_id || !$inscrito_id) {
                throw new Exception('Debe especificar torneo e inscrito');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/resumen-individual.php';
            $view_data = obtenerDatosResumenIndividual($torneo_id, $inscrito_id);
            break;
            
        case 'reasignar_mesa':
            if (!$torneo_id || !$ronda || !$mesa) {
                throw new Exception('Debe especificar torneo, ronda y mesa');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $datos = obtenerDatosReasignarMesa($torneo_id, $ronda, $mesa);
            if (empty($datos['jugadores']) || count($datos['jugadores']) != 4) {
                throw new Exception('La mesa debe tener exactamente 4 jugadores para reasignar');
            }
            $view_file = __DIR__ . '/gestion_torneos/reasignar-mesa.php';
            $view_data = array_merge(['torneo' => $torneo], $datos);
            break;
            
        case 'agregar_mesa':
            if (!$torneo_id || !$ronda) {
                throw new Exception('Debe especificar torneo y ronda');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/agregar-mesa.php';
            $view_data = obtenerDatosAgregarMesa($torneo_id, $ronda);
            break;
            
        case 'agregar_mesa':
            if (!$torneo_id || !$ronda) {
                throw new Exception('Debe especificar torneo y ronda');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/agregar-mesa.php';
            $view_data = obtenerDatosAgregarMesa($torneo_id, $ronda);
            break;
            
        case 'verificar_mesa':
            // Endpoint AJAX
            if (!$torneo_id || !$ronda || !$mesa) {
                header('Content-Type: application/json');
                echo json_encode(['existe' => false]);
                exit;
            }
            header('Content-Type: application/json');
            echo json_encode(['existe' => verificarMesaExiste($torneo_id, $ronda, $mesa)]);
            exit;
            
        case 'podio':
        case 'podios':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            // Detectar modalidad: si es equipos (modalidad = 3), mostrar podios de equipos
            $es_modalidad_equipos = isset($torneo['modalidad']) && (int)$torneo['modalidad'] === 3;
            if ($es_modalidad_equipos) {
                $view_file = __DIR__ . '/tournament_admin/podios_equipos.php';
            } else {
                $view_file = __DIR__ . '/tournament_admin/podios.php';
            }
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'podios_equipos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/podios_equipos.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'equipos_detalle':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/equipos_detalle.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'resultados_por_club':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/resultados_por_club.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'resultados_equipos_resumido':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            if ((int)($torneo['modalidad'] ?? 0) !== 3) {
                throw new Exception('Este reporte solo está disponible para torneos por equipos');
            }
            $view_file = __DIR__ . '/tournament_admin/resultados_equipos_resumido.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'resultados_equipos_detallado':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            if ((int)($torneo['modalidad'] ?? 0) !== 3) {
                throw new Exception('Este reporte solo está disponible para torneos por equipos');
            }
            $view_file = __DIR__ . '/tournament_admin/resultados_equipos_detallado.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'resultados_general':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            if ((int)($torneo['modalidad'] ?? 0) !== 3) {
                throw new Exception('Este reporte solo está disponible para torneos por equipos');
            }
            $view_file = __DIR__ . '/tournament_admin/resultados_general.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;

        case 'resultados_reportes':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/resultados_reportes.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;

        case 'resultados_reportes_print':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/resultados_reportes_print.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;

        case 'verificar_actas':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/verificar_actas_lista.php';
            $view_data = obtenerDatosVerificarActasLista($torneo_id);
            $view_data['torneo'] = $torneo;
            $view_data['torneo_id'] = $torneo_id;
            break;

        case 'verificar_acta':
            if (!$torneo_id || !$ronda || !$mesa) {
                throw new Exception('Debe especificar torneo, ronda y mesa');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/verificar_acta.php';
            $view_data = obtenerDatosVerificarActa($torneo_id, $ronda, $mesa);
            if (!$view_data) {
                throw new Exception('Acta no encontrada o ya verificada');
            }
            $view_data['torneo'] = $torneo;
            $view_data['torneo_id'] = $torneo_id;
            $view_data['ronda'] = $ronda;
            $view_data['mesa'] = $mesa;
            break;

        case 'verificar_resultados':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/views/verificar_resultados.php';
            $view_data = obtenerDatosVerificarActasLista($torneo_id);
            $view_data['torneo'] = $torneo;
            $view_data['torneo_id'] = $torneo_id;
            $view_data['jugadores'] = [];
            $view_data['torneo_finalizado'] = isTorneoLocked($torneo_id);
            $view_data['is_admin_general'] = $is_admin_general;
            $view_data['can_edit'] = !$view_data['torneo_finalizado'] || $is_admin_general;
            $ronda_vr = (int)($_GET['ronda'] ?? $_REQUEST['ronda'] ?? 0);
            $mesa_vr = (int)($_GET['mesa'] ?? $_REQUEST['mesa'] ?? 0);
            if ($ronda_vr > 0 && $mesa_vr > 0) {
                $acta_data = obtenerDatosVerificarActa($torneo_id, $ronda_vr, $mesa_vr);
                if ($acta_data) {
                    $view_data['jugadores'] = $acta_data['jugadores'];
                    $view_data['ronda'] = $ronda_vr;
                    $view_data['mesa'] = $mesa_vr;
                }
            }
            break;

        case 'verificar_actas_index':
            $view_file = __DIR__ . '/tournament_admin/verificar_actas_index.php';
            $view_data = obtenerTorneosConActasPendientes($user_id, $is_admin_general);
            break;

        case 'reporte_sanciones_ronda':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            require_once __DIR__ . '/../lib/ReporteSancionesPorRondaService.php';
            $rondaFiltroSanc = (int) ($_GET['ronda'] ?? 0);
            $view_file = __DIR__ . '/gestion_torneos/reporte_sanciones_ronda.php';
            $view_data = [
                'torneo' => $torneo,
                'torneo_id' => $torneo_id,
                'reporte' => (new ReporteSancionesPorRondaService())->construirReporte($torneo_id, DB::pdo(), $rondaFiltroSanc),
            ];
            break;

        case 'reporte_estructura_mesas':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            require_once __DIR__ . '/../lib/MesaEstructuraReporteService.php';
            require_once __DIR__ . '/../lib/ResultadosReportePaginacion.php';
            $rondaFiltroMesas = (int) ($_GET['ronda'] ?? 0);
            $paginaMesas = (int) ($_GET['pagina'] ?? 1);
            $view_file = __DIR__ . '/gestion_torneos/reporte_estructura_mesas.php';
            $view_data = [
                'torneo' => $torneo,
                'torneo_id' => $torneo_id,
                'reporte' => (new MesaEstructuraReporteService())->construirReporte(
                    $torneo_id,
                    DB::pdo(),
                    $rondaFiltroMesas,
                    $paginaMesas,
                    ResultadosReportePaginacion::PER_PAGE
                ),
            ];
            break;

        case 'reporte_parejas_repetidas':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            require_once __DIR__ . '/../lib/ReporteParejasRepetidasService.php';
            $minVecesParejas = max(2, min(20, (int) ($_GET['min_veces'] ?? 2)));
            $view_file = __DIR__ . '/gestion_torneos/reporte_parejas_repetidas.php';
            $view_data = [
                'torneo' => $torneo,
                'torneo_id' => $torneo_id,
                'reporte' => (new ReporteParejasRepetidasService())->construirReporte($torneo_id, DB::pdo(), $minVecesParejas),
            ];
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
    // Enriquecer torneo con datos de organización si faltan (para panel_torneo header)
    if (isset($view_data['torneo']) && !empty($view_data['torneo']['club_responsable']) && empty($view_data['torneo']['organizacion_logo'])) {
        try {
            $stmt = DB::pdo()->prepare("SELECT nombre, logo FROM organizaciones WHERE id = ?");
            $stmt->execute([$view_data['torneo']['club_responsable']]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($org) {
                $view_data['torneo']['organizacion_nombre'] = $org['nombre'] ?? 'N/A';
                $view_data['torneo']['organizacion_logo'] = !empty($org['logo']) ? $org['logo'] : null;
            }
        } catch (Exception $e) { /* ignorar */ }
    }
    
    // Cronómetro: página aparte sin layout (pantalla dedicada)
    if (!empty($use_cronometro_standalone) && $view_file && file_exists($view_file)) {
        extract($view_data);
        include $view_file;
        exit;
    }
    
    // Si se invoca desde panel_torneo.php, no renderizar aquí; panel_torneo lo hará con un solo contenedor
    $is_panel_standalone_page = (basename($_SERVER['PHP_SELF'] ?? '') === 'panel_torneo.php');
    if ($is_panel_standalone_page) {
        return; // panel_torneo.php hace el render
    }
    
    // Determinar si usar layout independiente o layout normal
    $use_standalone_layout = (basename($_SERVER['PHP_SELF']) === 'admin_torneo.php');
    
    if ($use_standalone_layout) {
        // Usar layout independiente para admin_torneo.php
        ob_start();
        if ($view_file && file_exists($view_file)) {
            extract($view_data);
            include $view_file;
        } else {
            throw new Exception('Vista no encontrada: ' . basename($view_file));
        }
        $content = ob_get_clean();
        
        // Asegurar que $torneo y $torneo_id estén disponibles para el layout
        if (!isset($torneo) && isset($view_data['torneo'])) {
            $torneo = $view_data['torneo'];
        }
        if (!isset($torneo_id) && isset($torneo['id'])) {
            $torneo_id = $torneo['id'];
        } elseif (!isset($torneo_id)) {
            $torneo_id = (int)($_GET['torneo_id'] ?? $_REQUEST['torneo_id'] ?? 0);
        }
        
        // Obtener acción actual
        $action = $_GET['action'] ?? $_REQUEST['action'] ?? '';
        
        $page_title = $page_title ?? 'Administrador de Torneos';
        include __DIR__ . '/../public/includes/admin_torneo_layout.php';
    } else {
        // Usar layout normal (incluido desde index.php)
        if ($view_file && file_exists($view_file)) {
            extract($view_data);
            include $view_file;
        } else {
            throw new Exception('Vista no encontrada: ' . basename($view_file));
        }
    }
    
} catch (Exception $e) {
    $use_standalone_layout = (basename($_SERVER['PHP_SELF']) === 'admin_torneo.php');
    
    if ($use_standalone_layout) {
        // Mostrar error en layout independiente
        ob_start();
        $error_message = $e->getMessage();
        $view_file = __DIR__ . '/gestion_torneos/index.php';
        $view_data = ['torneos' => [], 'error_message' => $error_message];
        extract($view_data);
        include $view_file;
        $content = ob_get_clean();
        
        $page_title = 'Error - Administrador de Torneos';
        include __DIR__ . '/../public/includes/admin_torneo_layout.php';
    } else {
        // Mostrar error en layout normal
        $error_message = $e->getMessage();
        $view_file = __DIR__ . '/gestion_torneos/index.php';
        $view_data = ['torneos' => [], 'error_message' => $error_message];
        extract($view_data);
        include $view_file;
    }
}

} // TORNEO_GESTION_SKIP_ROUTER

// =================================================================
// FUNCIONES AUXILIARES
// =================================================================

/**
 * Obtiene torneos disponibles para gestión, opcionalmente filtrados por categoría.
 * Categorías: realizados (cerrados), en_proceso (en curso), por_realizar (futuros).
 *
 * @param int $user_id
 * @param bool $is_admin_general
 * @param string|null $filtro 'realizados' | 'en_proceso' | 'por_realizar' | null (todos)
 * @return array
 */
/**
 * Etiqueta de entidad/ámbito territorial para filas del listado de gestión de torneos.
 *
 * @param array<string, mixed> $torneo
 */
function torneo_entidad_nombre_listado(array $torneo, PDO $pdo): string
{
    $entidadId = FvdConfig::entidadTerritorioEfectivaTorneo($torneo, $pdo);

    return FvdConfig::etiquetaAmbitoTerritorial($entidadId, $pdo);
}

function obtenerTorneosGestion($user_id, $is_admin_general, $filtro = null) {
    $pdo = DB::pdo();
    
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_clause = !empty($tournament_filter['where']) ? "WHERE " . $tournament_filter['where'] : "";
    $params = $tournament_filter['params'];
    
    $orgJoin = torneoOrgJoinExpr('t', 'o');
    $sql = "SELECT t.*, o.nombre as organizacion_nombre, o.entidad as organizacion_entidad,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id) as total_inscritos,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND " . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO . ") as inscritos_confirmados
            FROM tournaments t
            {$orgJoin}
            $where_clause
            ORDER BY t.fechator DESC, t.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hoy = date('Y-m-d');
    
    foreach ($torneos as &$torneo) {
        $rondas_generadas = obtenerRondasGeneradas($torneo['id']);
        $torneo['rondas_generadas'] = count($rondas_generadas);
        $ultima_ronda = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
        $torneo['ultima_ronda'] = $ultima_ronda;
        $torneo['ronda_actual'] = $ultima_ronda;
        $torneo['proxima_ronda'] = $ultima_ronda + 1;
        $torneo['rondas_totales'] = $torneo['rondas'] ?? 0;
        $torneo['rondas_faltantes'] = max(0, ($torneo['rondas_totales'] ?? 0) - $ultima_ronda);
        $torneo['porcentaje_progreso'] = ($torneo['rondas_totales'] > 0) ? round(($ultima_ronda / $torneo['rondas_totales']) * 100) : 0;

        $locked = (int)($torneo['locked'] ?? 0) === 1;
        $fecha = $torneo['fechator'] ?? null;
        $fecha_ok = $fecha ? (strtotime($fecha) <= strtotime($hoy)) : false;

        if ($locked) {
            $torneo['categoria'] = 'realizados';
        } elseif ($fecha_ok || $ultima_ronda > 0) {
            $torneo['categoria'] = 'en_proceso';
        } else {
            $torneo['categoria'] = 'por_realizar';
        }

        if (empty($torneo['organizacion_nombre'])) {
            $torneo['organizacion_nombre'] = FvdConfig::getOrganizacionNombre();
        }
        $torneo['club_nombre'] = $torneo['organizacion_nombre'];
        $torneo['entidad_nombre'] = torneo_entidad_nombre_listado($torneo, $pdo);
    }
    unset($torneo);

    if ($filtro !== null && in_array($filtro, ['realizados', 'en_proceso', 'por_realizar'], true)) {
        $torneos = array_values(array_filter($torneos, function ($t) use ($filtro) {
            return ($t['categoria'] ?? '') === $filtro;
        }));
        if ($filtro === 'por_realizar') {
            usort($torneos, function ($a, $b) {
                $fa = $a['fechator'] ?? '';
                $fb = $b['fechator'] ?? '';
                return strcmp($fa, $fb);
            });
        }
    }

    return $torneos;
}

/**
 * Obtiene datos de un torneo
 */
function obtenerTorneo($torneo_id, $user_id, $is_admin_general) {
    $pdo = DB::pdo();
    
    // Obtener torneo (la tabla clubes NO tiene admin_id, se relaciona vía usuarios.club_id)
    $sql = "SELECT t.*, c.nombre as club_nombre
            FROM tournaments t
            LEFT JOIN clubes c ON t.club_responsable = c.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar permisos usando Auth::canAccessTournament
    if ($torneo && !Auth::canAccessTournament($torneo_id)) {
        return null; // Sin permisos
    }
    
    return $torneo;
}

/**
 * Verifica permisos sobre un torneo
 */
function verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general) {
    // Usar Auth::canAccessTournament que ya maneja todos los roles correctamente
    if (!Auth::canAccessTournament($torneo_id)) {
        throw new Exception('No tiene permisos para acceder a este torneo');
    }
    return obtenerTorneo($torneo_id, $user_id, $is_admin_general);
}

/**
 * Obtiene rondas generadas de un torneo
 */
function obtenerRondasGeneradas($torneo_id) {
    return \Tournament\Handlers\TournamentStatusHandler::getRondasGeneradas((int) $torneo_id);
}

/**
 * Obtiene datos para el panel de control
 */
function obtenerDatosPanel($torneo_id) {
    return \Tournament\Handlers\TournamentStatusHandler::getTournamentSummary((int) $torneo_id);
}

/**
 * Carga inscritos confirmados desde movimiento_torneo (pagos de inscripción) hacia inscritos.
 */
function cargarInscritosDesdeMovimiento(int $torneo_id, int $user_id, bool $is_admin_general): void
{
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
        if (!$torneo) {
            throw new Exception('Torneo no encontrado.');
        }
        if (!empty($torneo['locked']) && (int) $torneo['locked'] === 1) {
            throw new Exception('El torneo está cerrado; no se pueden cargar inscripciones.');
        }

        require_once __DIR__ . '/../lib/MovimientoTorneoInscripcionImportService.php';
        $pdo = DB::pdo();
        $res = MovimientoTorneoInscripcionImportService::importarInscritos($pdo, $torneo_id, $user_id > 0 ? $user_id : null);

        if ($res['insertados'] > 0) {
            foreach (array_keys($res['por_torneo']) as $tidAfectado) {
                actualizarEstadisticasInscritos((int) $tidAfectado, true);
            }
            $msg = 'Se cargaron ' . (int) $res['insertados'] . ' inscrito(s) desde movimiento_torneo.';
            if ($res['omitidos'] > 0) {
                $msg .= ' Omitidos: ' . (int) $res['omitidos'] . '.';
            }
            if (!empty($res['por_torneo'])) {
                $det = [];
                foreach ($res['por_torneo'] as $tid => $cnt) {
                    $det[] = 'torneo #' . (int) $tid . ': ' . (int) $cnt;
                }
                $msg .= ' (' . implode(', ', $det) . ')';
            }
            $_SESSION['success'] = $msg;
        } elseif ($res['errores'] !== []) {
            $_SESSION['error'] = 'No se cargaron inscripciones. ' . implode(' ', array_slice($res['errores'], 0, 5));
        } else {
            $_SESSION['error'] = 'No hay inscripciones pendientes por cargar desde movimiento_torneo.';
        }

        if ($res['insertados'] > 0 && count($res['errores']) > 0) {
            $_SESSION['warning'] = 'Algunas filas no se importaron: ' . implode(' | ', array_slice($res['errores'], 0, 6));
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Error al cargar inscripciones: ' . $e->getMessage();
    }

    header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Datos para configurar vinculación de torneos por parent_event_id.
 */
function obtenerDatosVincularTorneos($torneo_id, $user_id, $is_admin_general) {
    $pdo = DB::pdo();
    $torneo = obtenerTorneo((int)$torneo_id, (int)$user_id, (bool)$is_admin_general);
    if (!$torneo) {
        throw new Exception('Torneo principal no encontrado o sin permisos');
    }
    if (!tournamentsColumnExists('parent_event_id')) {
        throw new Exception('La columna parent_event_id no existe en la tabla tournaments');
    }

    $orgId = (int)($torneo['club_responsable'] ?? 0);
    $parentEventId = (int)($torneo['parent_event_id'] ?? 0);
    $eventRef = $parentEventId > 0 ? $parentEventId : (int)$torneo['id'];

    // Torneos principales elegibles (misma organización, sin padre o padre propio)
    $stmtPadres = $pdo->prepare("
        SELECT id, nombre, fechator, club_responsable, parent_event_id
        FROM tournaments
        WHERE (club_responsable = ?
          " . (organizacionesHasCodOrg() ? "OR club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)" : "") . ")
          AND (parent_event_id IS NULL OR parent_event_id = 0 OR parent_event_id = id)
        ORDER BY id ASC
    ");
    $stmtPadres->execute(organizacionesHasCodOrg() ? [$orgId, $orgId] : [$orgId]);
    $padresRaw = $stmtPadres->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $torneosPadre = array_values(array_filter($padresRaw, static function (array $t): bool {
        return Auth::canAccessTournament((int)($t['id'] ?? 0));
    }));

    // Disponibles para vincular: huérfanos de la misma organización (sin padre).
    $stmtDisp = $pdo->prepare("
        SELECT id, nombre, fechator
        FROM tournaments
        WHERE (club_responsable = ?
          " . (organizacionesHasCodOrg() ? "OR club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)" : "") . ")
          AND (parent_event_id IS NULL OR parent_event_id = 0)
          AND id <> ?
        ORDER BY id ASC
    ");
    $stmtDisp->execute(organizacionesHasCodOrg() ? [$orgId, $orgId, $eventRef] : [$orgId, $eventRef]);
    $disponiblesRaw = $stmtDisp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $torneosDisponibles = array_values(array_filter($disponiblesRaw, static function (array $t): bool {
        return Auth::canAccessTournament((int)($t['id'] ?? 0));
    }));

    // Vinculados al evento padre de referencia.
    $stmtVinc = $pdo->prepare("
        SELECT id, nombre, fechator, parent_event_id
        FROM tournaments
        WHERE parent_event_id = ?
        ORDER BY id ASC
    ");
    $stmtVinc->execute([$eventRef]);
    $vinculadosRaw = $stmtVinc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $torneosVinculados = array_values(array_filter($vinculadosRaw, static function (array $t): bool {
        return Auth::canAccessTournament((int)($t['id'] ?? 0));
    }));

    return [
        'torneo' => $torneo,
        'torneo_id' => (int)$torneo_id,
        'torneos_padre' => $torneosPadre,
        'parent_event_ref' => $eventRef,
        'torneos_disponibles' => $torneosDisponibles,
        'torneos_vinculados' => $torneosVinculados,
    ];
}

/**
 * Obtiene datos de mesas de una ronda.
 * Si el usuario es operador, solo se devuelven las mesas de su ámbito (asignadas a él).
 */
function obtenerDatosMesas($torneo_id, $ronda, $user_id = 0, $user_role = '') {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener todas las mesas de la ronda
    $sql = "SELECT DISTINCT pr.mesa as numero,
                MAX(pr.registrado) as registrado,
                COUNT(DISTINCT pr.id_usuario) as total_jugadores
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            GROUP BY pr.mesa
            ORDER BY pr.mesa ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda]);
    $todasLasMesas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener jugadores agrupados por mesa
    $sql = "SELECT 
                pr.*,
                u.nombre as nombre_completo,
                u.nombre,
                u.sexo,
                i.codigo_equipo AS codigo_equipo_inscrito,
                c.nombre as club_nombre
            FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            ORDER BY pr.mesa ASC, pr.secuencia ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $generoTorneoNombre = detectarGeneroTorneoPorNombre((string) ($torneo['nombre'] ?? ''));
    foreach ($resultados as &$resultado) {
        $resultado['alerta_genero'] = torneoGestionAlertaGeneroVsTorneo($generoTorneoNombre, $resultado['sexo'] ?? null);
    }
    unset($resultado);
    
    // Agrupar por mesa con estructura completa
    $mesas = [];
    foreach ($resultados as $resultado) {
        $numMesa = (int)$resultado['mesa'];
        if (!isset($mesas[$numMesa])) {
            $mesas[$numMesa] = [
                'mesa' => $numMesa,
                'numero' => $numMesa,
                'registrado' => $resultado['registrado'] ?? 0,
                'tiene_resultados' => ($resultado['registrado'] ?? 0) > 0,
                'jugadores' => []
            ];
        }
        $mesas[$numMesa]['jugadores'][] = $resultado;
    }
    
    // Operador: limitar a sus mesas asignadas (ámbito)
    $mesas_operador = obtenerMesasAsignadasOperador($torneo_id, $ronda, $user_id, $user_role);
    if ($mesas_operador !== null && !empty($mesas_operador)) {
        $set_operador = array_flip($mesas_operador);
        $mesas = array_intersect_key($mesas, $set_operador);
        $mesas = array_values($mesas);
    } elseif ($mesas_operador !== null && empty($mesas_operador)) {
        $mesas = [];
    } else {
        $mesas = array_values($mesas);
    }
    
    // Obtener total de rondas
    $stmt = $pdo->prepare("SELECT rondas FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $totalRondas = $stmt->fetchColumn() ?? 0;
    
    return [
        'torneo' => $torneo,
        'ronda' => $ronda,
        'mesas' => $mesas,
        'totalRondas' => $totalRondas,
        'es_operador_ambito' => $mesas_operador !== null,
    ];
}

/**
 * Obtiene datos de rondas
 */
function obtenerDatosRondas($torneo_id) {
    $pdo = DB::pdo();
    
    $torneo = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $torneo->execute([$torneo_id]);
    $torneo = $torneo->fetch(PDO::FETCH_ASSOC);
    
    $rondas_generadas = obtenerRondasGeneradas($torneo_id);
    $ultima_ronda = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
    $proxima_ronda = $ultima_ronda + 1;
    
    return [
        'torneo' => $torneo,
        'rondas_generadas' => $rondas_generadas,
        'proxima_ronda' => $proxima_ronda
    ];
}

/**
 * Obtiene datos de posiciones.
 * Procedencia: estadísticas y posición persistidas en `inscritos` (sincronizadas al cerrar ronda o finalizar torneo).
 * No recalcula clasificación en cada consulta.
 */
function obtenerDatosPosiciones($torneo_id, $genero_get = null) {
    require_once __DIR__ . '/../lib/ResultadosReporteData.php';
    require_once __DIR__ . '/../lib/InscritosReporteStatsHelper.php';
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo) {
        return ['torneo' => null, 'posiciones' => [], 'es_modalidad_equipos' => false];
    }
    
    $es_modalidad_equipos = (int)($torneo['modalidad'] ?? 0) === 3;

    InscritosReporteStatsHelper::ensureColumnas($pdo);
    $cols = InscritosReporteStatsHelper::expresionesSelectClasificacion('i');
    
    $sql = "SELECT 
                i.*,
                u.nombre as nombre_completo,
                u.username,
                u.sexo,
                c.nombre as club_nombre,
                c.nombre as nombre_club,
                e.nombre_equipo,
                e.codigo_equipo as codigo_equipo_from_equipos,
                {$cols['ganadas_por_forfait']},
                {$cols['partidas_bye']},
                {$cols['tarjeta']}
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            LEFT JOIN equipos e ON i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo AND e.estatus = 0
            WHERE i.torneo_id = ?
            ORDER BY (CASE WHEN (i.estatus = 1 OR i.estatus = 'confirmado') THEN 0 ELSE 1 END) ASC,
                     i.posicion ASC, i.ganados DESC, i.efectividad DESC, i.puntos DESC, i.id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $posiciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/../lib/NumfvdHelper.php';
    $posiciones = NumfvdHelper::enriquecerFilas($posiciones);

    $genero_ranking = \ResultadosReporteData::generoFiltroDesdeParametro(is_string($genero_get) ? $genero_get : null);
    $modalidadTorneo = (int) ($torneo['modalidad'] ?? 0);
    $posiciones = \ResultadosReporteData::aplicarRankingPorGenero($posiciones, $genero_ranking, $modalidadTorneo);
    
    // Asegurar que todos los jugadores tengan el nombre del equipo si tienen codigo_equipo
    foreach ($posiciones as &$pos) {
        if (empty($pos['nombre_equipo']) && !empty($pos['codigo_equipo'])) {
            // Si no tiene nombre_equipo pero tiene codigo_equipo, construir uno
            $pos['nombre_equipo'] = 'Equipo ' . $pos['codigo_equipo'];
        }
    }
    unset($pos);

    if (in_array($modalidadTorneo, [2, 4], true)) {
        $posiciones = \ResultadosReporteData::colapsarFilasPorPareja($posiciones, $pdo, $torneo_id);
    }
    
    return [
        'torneo' => $torneo,
        'posiciones' => $posiciones,
        'es_modalidad_equipos' => $es_modalidad_equipos,
        'genero_ranking' => $genero_ranking,
    ];
}

/**
 * Obtiene datos de inscripciones de un torneo
 */
function obtenerDatosInscripciones($torneo_id) {
    $pdo = DB::pdo();
    
    $orgJoin = torneoOrgJoinExpr('t', 'o');
    $stmt = $pdo->prepare("SELECT t.*, COALESCE(o.nombre, c.nombre) AS club_nombre
            FROM tournaments t
            {$orgJoin}
            LEFT JOIN clubes c ON t.club_responsable = c.id
            WHERE t.id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar si el torneo ha iniciado (tiene rondas generadas)
    $rondas_generadas = obtenerRondasGeneradas($torneo_id);
    $torneo_iniciado = !empty($rondas_generadas);
    // Confirmar/Retirar: permitido mientras el torneo no esté cerrado (locked)
    $torneo_cerrado = (int)($torneo['locked'] ?? 0) === 1;
    $puede_confirmar_retirar = !$torneo_cerrado;
    
    // Obtener TODOS los inscritos del torneo (cualquier estatus) para confirmar o retirar
    $sql = "SELECT 
                i.*,
                u.nombre as nombre_completo,
                u.username,
                u.sexo,
                c.nombre as nombre_club,
                c.id as club_id
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ?
            ORDER BY c.nombre ASC, u.nombre ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $total_inscritos = count($inscritos);
    $confirmados = 0;
    $hombres = 0;
    $mujeres = 0;
    
    foreach ($inscritos as $inscrito) {
        if (InscritosHelper::esConfirmado($inscrito['estatus'])) {
            $confirmados++;
        }
        if ($inscrito['sexo'] == 1 || strtoupper($inscrito['sexo']) === 'M') {
            $hombres++;
        } elseif ($inscrito['sexo'] == 2 || strtoupper($inscrito['sexo']) === 'F') {
            $mujeres++;
        }
    }
    
    // Resumen por club
    $resumen_clubes = [];
    foreach ($inscritos as $inscrito) {
        $club_id = $inscrito['club_id'] ?? 0;
        $club_nombre = $inscrito['nombre_club'] ?? 'Sin Club';
        
        if (!isset($resumen_clubes[$club_id])) {
            $resumen_clubes[$club_id] = [
                'id' => $club_id,
                'nombre' => $club_nombre,
                'total' => 0,
                'hombres' => 0,
                'mujeres' => 0
            ];
        }
        
        $resumen_clubes[$club_id]['total']++;
        if ($inscrito['sexo'] == 1 || strtoupper($inscrito['sexo']) === 'M') {
            $resumen_clubes[$club_id]['hombres']++;
        } elseif ($inscrito['sexo'] == 2 || strtoupper($inscrito['sexo']) === 'F') {
            $resumen_clubes[$club_id]['mujeres']++;
        }
    }

    $contadores_inscripcion = InscritosHelper::contadoresResumenInscripcionTorneo(
        $pdo,
        (int) $torneo_id,
        (int) ($torneo['modalidad'] ?? 0)
    );

    return [
        'torneo' => $torneo,
        'inscritos' => $inscritos,
        'total_inscritos' => $total_inscritos,
        'confirmados' => $confirmados,
        'hombres' => $hombres,
        'mujeres' => $mujeres,
        'resumen_clubes' => array_values($resumen_clubes),
        'torneo_iniciado' => $torneo_iniciado,
        'puede_confirmar_retirar' => $puede_confirmar_retirar,
        'contadores_inscripcion' => $contadores_inscripcion,
    ];
}

/**
 * Obtiene datos para la pantalla de notificaciones del torneo (plantillas + inscritos)
 */
function obtenerDatosNotificacionesTorneo($torneo_id) {
    $pdo = DB::pdo();
    $orgJoin = torneoOrgJoinExpr('t', 'o');
    $stmt = $pdo->prepare("SELECT t.*, o.nombre as organizacion_nombre FROM tournaments t {$orgJoin} WHERE t.id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        return ['torneo' => null, 'plantillas' => [], 'ultima_ronda' => 0, 'total_inscritos' => 0];
    }
    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);
    $plantillas = $nm->listarPlantillas('torneo');
    $ultima_ronda = 0;
    try {
        require_once __DIR__ . '/../lib/Core/TorneoMesaAsignacionResolver.php';
        $modalidad = (int)($torneo['modalidad'] ?? 0);
        $mesaService = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);
        $ultima_ronda = $mesaService->obtenerUltimaRonda($torneo_id);
    } catch (Exception $e) {}
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND " . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO);
    $stmt->execute([$torneo_id]);
    $total_inscritos = (int) $stmt->fetchColumn();

    $inscritos_prueba = [];
    if ($total_inscritos > 0) {
        $ronda_ref = $ultima_ronda > 0 ? $ultima_ronda : 1;
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.telegram_chat_id,
                   COALESCE(i.posicion, 0) AS posicion, COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
                   COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . "
            ORDER BY i.id
            LIMIT 50
        ");
        $stmt->execute([$torneo_id]);
        $inscritos_prueba = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mesaPareja = [];
        $stmtMesa = $pdo->prepare("
            SELECT pr.id_usuario, pr.mesa, pr_p.id_usuario AS pareja_id, u_pareja.nombre AS pareja_nombre
            FROM partiresul pr
            LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
            LEFT JOIN usuarios u_pareja ON u_pareja.id = pr_p.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
        ");
        $stmtMesa->execute([$torneo_id, $ronda_ref]);
        while ($row = $stmtMesa->fetch(PDO::FETCH_ASSOC)) {
            $mesaPareja[(int)$row['id_usuario']] = [
                'mesa' => (string)$row['mesa'],
                'pareja_id' => (int)($row['pareja_id'] ?? 0),
                'pareja' => trim((string)($row['pareja_nombre'] ?? '')) ?: '””',
            ];
        }
        require_once __DIR__ . '/../lib/app_helpers.php';
        foreach ($inscritos_prueba as &$ins) {
            $uid = (int)$ins['id'];
            $ins['mesa'] = $mesaPareja[$uid]['mesa'] ?? '””';
            $ins['pareja_id'] = $mesaPareja[$uid]['pareja_id'] ?? 0;
            $ins['pareja'] = $mesaPareja[$uid]['pareja'] ?? '””';
            $ins['url_resumen'] = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneo_id, 'inscrito_id' => $uid, 'from' => 'notificaciones']);
        }
        unset($ins);
    }

    return [
        'torneo' => $torneo,
        'torneo_id' => (int) $torneo_id,
        'plantillas' => $plantillas,
        'ultima_ronda' => $ultima_ronda,
        'total_inscritos' => $total_inscritos,
        'inscritos_prueba' => $inscritos_prueba,
    ];
}

/**
 * Envía notificación masiva según plantilla: a inscritos del torneo o a todos los usuarios del administrador.
 * Si POST prueba=1 e inscrito_id=X, envía solo una notificación de prueba a ese inscrito (con prefijo [Prueba]).
 */
function enviarNotificacionTorneo($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }
    $pdo = DB::pdo();
    $clave_plantilla = trim((string)($_POST['plantilla_clave'] ?? ''));
    $ronda = (int)($_POST['ronda'] ?? 0);
    $es_prueba = !empty($_POST['prueba']);
    $inscrito_id_prueba = $es_prueba ? (int)($_POST['inscrito_id'] ?? 0) : 0;

    if ($clave_plantilla === '') {
        $_SESSION['error'] = 'Debe seleccionar una plantilla.';
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }

    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);
    $plantilla = $nm->obtenerPlantilla($clave_plantilla);
    if (!$plantilla) {
        $_SESSION['error'] = 'Plantilla no encontrada.';
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }

    if ($es_prueba && $inscrito_id_prueba > 0) {
        enviarNotificacionPrueba($pdo, $nm, $torneo_id, $inscrito_id_prueba, $plantilla, $ronda);
        $_SESSION['success'] = 'Notificación de prueba encolada para 1 inscrito. Revisa la campanita con ese usuario.';
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }

    $stmt = $pdo->prepare("SELECT nombre, club_responsable FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $torneo_nombre = $torneo_row['nombre'] ?? 'Torneo';
    $club_responsable = (int)($torneo_row['club_responsable'] ?? 0);

    $destinatarios = isset($plantilla['destinatarios']) ? trim((string)$plantilla['destinatarios']) : 'inscritos';
    if ($destinatarios !== 'todos_usuarios_admin') {
        $destinatarios = 'inscritos';
    }

    if ($destinatarios === 'todos_usuarios_admin') {
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $club_ids = ClubHelper::getClubesSupervised($club_responsable);
        if (empty($club_ids)) {
            $club_ids = [$club_responsable];
        }
        $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT id, nombre, telegram_chat_id
            FROM usuarios
            WHERE club_id IN ($placeholders) AND role = 'usuario' AND status = 0
        ");
        $stmt->execute(array_values($club_ids));
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($jugadores)) {
            $_SESSION['error'] = 'No hay usuarios en los clubes del administrador.';
            header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
            exit;
        }
        $items = [];
        foreach ($jugadores as $j) {
            $mensaje = $nm->procesarMensaje($plantilla['cuerpo_mensaje'], [
                'nombre' => (string)($j['nombre'] ?? ''),
                'ronda' => (string)$ronda,
                'torneo' => $torneo_nombre,
                'ganados' => '””',
                'perdidos' => '””',
                'efectividad' => '””',
                'puntos' => '””',
                'mesa' => '””',
                'pareja' => '””',
            ]);
            $items[] = [
                'id' => (int)$j['id'],
                'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
                'mensaje' => $mensaje,
                'url_destino' => '',
            ];
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.telegram_chat_id,
                   COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
                   COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . "
        ");
        $stmt->execute([$torneo_id]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($jugadores)) {
            $_SESSION['error'] = 'No hay inscritos activos en este torneo.';
            header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
            exit;
        }

        $mesaPareja = [];
        if ($ronda > 0) {
            $stmtMesa = $pdo->prepare("
                SELECT pr.id_usuario, pr.mesa, u_pareja.nombre AS pareja_nombre
                FROM partiresul pr
                LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                    AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
                LEFT JOIN usuarios u_pareja ON u_pareja.id = pr_p.id_usuario
                WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            ");
            $stmtMesa->execute([$torneo_id, $ronda]);
            while ($row = $stmtMesa->fetch(PDO::FETCH_ASSOC)) {
                $mesaPareja[(int)$row['id_usuario']] = [
                    'mesa' => (string)$row['mesa'],
                    'pareja' => trim((string)($row['pareja_nombre'] ?? '')) ?: '””',
                ];
            }
        }

        require_once __DIR__ . '/../lib/app_helpers.php';
        $items = [];
        foreach ($jugadores as $j) {
            $uid = (int)$j['id'];
            $mp = $mesaPareja[$uid] ?? null;
            $url_resumen = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneo_id, 'inscrito_id' => $uid, 'from' => 'notificaciones']);
            $mensaje = $nm->procesarMensaje($plantilla['cuerpo_mensaje'], [
                'nombre' => (string)($j['nombre'] ?? ''),
                'ronda' => (string)$ronda,
                'torneo' => $torneo_nombre,
                'ganados' => (string)($j['ganados'] ?? '0'),
                'perdidos' => (string)($j['perdidos'] ?? '0'),
                'efectividad' => (string)($j['efectividad'] ?? '0'),
                'puntos' => (string)($j['puntos'] ?? '0'),
                'mesa' => $mp ? (string)$mp['mesa'] : '””',
                'pareja' => $mp ? (string)$mp['pareja'] : '””',
                'url_resumen' => $url_resumen,
            ]);
            $items[] = [
                'id' => $uid,
                'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
                'mensaje' => $mensaje,
                'url_destino' => $url_resumen,
            ];
        }
    }

    $nm->programarMasivoPersonalizado($items);
    $_SESSION['success'] = 'Notificaciones encoladas: ' . count($items) . ' mensaje(s). Se enviarán por Telegram y aparecerán en la campanita web.';
    header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Envía una sola notificación de prueba a un inscrito (datos reales de inscritos).
 */
function enviarNotificacionPrueba(PDO $pdo, NotificationManager $nm, int $torneo_id, int $inscrito_id, array $plantilla, int $ronda): void {
    $stmt = $pdo->prepare("SELECT nombre FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo_nombre = $stmt->fetchColumn() ?: 'Torneo';
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.telegram_chat_id,
               COALESCE(i.posicion, 0) AS posicion, COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
               COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
        FROM inscritos i
        INNER JOIN usuarios u ON i.id_usuario = u.id
        WHERE i.torneo_id = ? AND i.id_usuario = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . "
    ");
    $stmt->execute([$torneo_id, $inscrito_id]);
    $j = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$j) {
        $_SESSION['error'] = 'Inscrito no encontrado.';
        return;
    }
    $mesaPareja = [];
    if ($ronda > 0) {
        $stmtMesa = $pdo->prepare("
            SELECT pr.id_usuario, pr.mesa, pr_p.id_usuario AS pareja_id, u_pareja.nombre AS pareja_nombre
            FROM partiresul pr
            LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
            LEFT JOIN usuarios u_pareja ON u_pareja.id = pr_p.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0 AND pr.id_usuario = ?
        ");
        $stmtMesa->execute([$torneo_id, $ronda, $inscrito_id]);
        $row = $stmtMesa->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $mesaPareja = [
                'mesa' => (string)$row['mesa'],
                'pareja_id' => (int)($row['pareja_id'] ?? 0),
                'pareja' => trim((string)($row['pareja_nombre'] ?? '')) ?: '””',
            ];
        }
    }
    require_once __DIR__ . '/../lib/app_helpers.php';
    $url_resumen = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneo_id, 'inscrito_id' => $inscrito_id, 'from' => 'notificaciones']);
    $url_clasificacion = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'posiciones', 'torneo_id' => $torneo_id, 'from' => 'notificaciones']);
    $mensaje = $nm->procesarMensaje($plantilla['cuerpo_mensaje'], [
        'nombre' => (string)($j['nombre'] ?? ''),
        'ronda' => (string)$ronda,
        'torneo' => $torneo_nombre,
        'ganados' => (string)($j['ganados'] ?? '0'),
        'perdidos' => (string)($j['perdidos'] ?? '0'),
        'efectividad' => (string)($j['efectividad'] ?? '0'),
        'puntos' => (string)($j['puntos'] ?? '0'),
        'mesa' => $mesaPareja['mesa'] ?? '””',
        'pareja' => $mesaPareja['pareja'] ?? '””',
        'url_resumen' => $url_resumen,
    ]);
    $nm->programarMasivoPersonalizado([[
        'id' => (int)$j['id'],
        'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
        'mensaje' => '[Prueba] ' . $mensaje,
        'url_destino' => $url_resumen,
        'datos_json' => [
            'tipo' => 'nueva_ronda',
            'ronda' => (string) $ronda,
            'mesa' => $mesaPareja['mesa'] ?? '””',
            'usuario_id' => (int)$j['id'],
            'nombre' => (string)($j['nombre'] ?? ''),
            'pareja_id' => (int)($mesaPareja['pareja_id'] ?? 0),
            'pareja_nombre' => $mesaPareja['pareja'] ?? '””',
            'posicion' => (string)($j['posicion'] ?? '0'),
            'ganados' => (string)($j['ganados'] ?? '0'),
            'perdidos' => (string)($j['perdidos'] ?? '0'),
            'efectividad' => (string)($j['efectividad'] ?? '0'),
            'puntos' => (string)($j['puntos'] ?? '0'),
            'url_resumen' => $url_resumen,
            'url_clasificacion' => $url_clasificacion,
        ],
    ]]);
}

/**
 * Envía notificaciones (web + Telegram) a los 4 jugadores de una mesa tras registrar resultados.
 * Mensaje: atleta id/nombre, ganó/perdió ronda X mesa Y, resultados R1 a R2; si aplica sanción y/o tarjeta; "Si no está conforme notifique a mesa técnica."
 *
 * @param PDO $pdo
 * @param int $torneo_id
 * @param int $ronda
 * @param int $mesa
 */
function enviarNotificacionesResultadosMesa(PDO $pdo, int $torneo_id, int $ronda, int $mesa): void {
    $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
    $sql = "SELECT pr.id_usuario, pr.resultado1, pr.resultado2, pr.sancion, pr.tarjeta,
            u.nombre" . ($hasTg ? ", u.telegram_chat_id" : "") . "
            FROM partiresul pr
            INNER JOIN usuarios u ON u.id = pr.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? AND pr.registrado = 1
            ORDER BY pr.secuencia";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($filas) === 0) return;

    require_once __DIR__ . '/../lib/app_helpers.php';
    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);

    $tarjetaTexto = [1 => 'Amarilla', 3 => 'Roja', 4 => 'Negra'];
    $items = [];
    foreach ($filas as $row) {
        $id_usuario = (int)$row['id_usuario'];
        $nombre = trim((string)($row['nombre'] ?? ''));
        $r1 = (int)($row['resultado1'] ?? 0);
        $r2 = (int)($row['resultado2'] ?? 0);
        $sancion = (int)($row['sancion'] ?? 0);
        $tarjeta = (int)($row['tarjeta'] ?? 0);
        $ganado = $r1 > $r2;
        $textoResultado = $ganado ? 'ganado' : 'perdido';
        $mensaje = "Atleta {$id_usuario}, {$nombre}, usted ha {$textoResultado} la ronda número {$ronda} en la mesa {$mesa}, con los siguientes resultados: {$r1} a {$r2}.";
        if ($sancion > 0 || $tarjeta > 0) {
            $partes = [];
            if ($sancion > 0) $partes[] = "sancionado con {$sancion} pts";
            if ($tarjeta > 0) $partes[] = "tarjeta " . ($tarjetaTexto[$tarjeta] ?? $tarjeta);
            $mensaje .= " " . ucfirst(implode(" y ", $partes)) . ".";
        }
        $mensaje .= " Si no está conforme notifique a mesa técnica.";

        $url_resumen = AppHelpers::url('index.php', [
            'page' => 'torneo_gestion',
            'action' => 'resumen_individual',
            'torneo_id' => $torneo_id,
            'inscrito_id' => $id_usuario,
            'from' => 'notificaciones',
        ]);
        $url_clasificacion = AppHelpers::url('index.php', [
            'page' => 'torneo_gestion',
            'action' => 'posiciones',
            'torneo_id' => $torneo_id,
            'from' => 'notificaciones',
        ]);
        $tarjetaStr = $tarjeta > 0 ? ($tarjetaTexto[$tarjeta] ?? (string)$tarjeta) : '';
        $items[] = [
            'id' => $id_usuario,
            'telegram_chat_id' => $hasTg && !empty(trim((string)($row['telegram_chat_id'] ?? ''))) ? trim((string)$row['telegram_chat_id']) : null,
            'mensaje' => $mensaje,
            'url_destino' => $url_resumen,
            'datos_json' => [
                'tipo' => 'resultados_mesa',
                'ronda' => (string)$ronda,
                'mesa' => (string)$mesa,
                'usuario_id' => $id_usuario,
                'nombre' => $nombre,
                'resultado_texto' => $textoResultado,
                'resultado1' => (string)$r1,
                'resultado2' => (string)$r2,
                'sancion' => (string)$sancion,
                'tarjeta_texto' => $tarjetaStr,
                'url_resumen' => $url_resumen,
                'url_clasificacion' => $url_clasificacion,
            ],
        ];
    }
    if (!empty($items)) {
        $nm->programarMasivoPersonalizado($items);
    }
}

/**
 * Envía notificaciones a los 4 jugadores tras APROBAR un acta QR.
 * Mensaje con cláusula de veracidad: resultado definitivo, revisión ante juez en 2 rondas.
 *
 * @param PDO $pdo
 * @param int $torneo_id
 * @param int $ronda
 * @param int $mesa
 */
function enviarNotificacionesResultadosAprobados(PDO $pdo, int $torneo_id, int $ronda, int $mesa): void {
    $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
    $sql = "SELECT pr.id_usuario, pr.resultado1, pr.resultado2, pr.sancion, pr.tarjeta,
            u.nombre" . ($hasTg ? ", u.telegram_chat_id" : "") . "
            FROM partiresul pr
            INNER JOIN usuarios u ON u.id = pr.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? AND pr.registrado = 1
            ORDER BY pr.secuencia";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($filas) === 0) return;

    require_once __DIR__ . '/../lib/app_helpers.php';
    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);

    $tarjetaTexto = [1 => 'Amarilla', 3 => 'Roja', 4 => 'Negra'];
    $items = [];
    foreach ($filas as $row) {
        $id_usuario = (int)$row['id_usuario'];
        $nombre = trim((string)($row['nombre'] ?? ''));
        $r1 = (int)($row['resultado1'] ?? 0);
        $r2 = (int)($row['resultado2'] ?? 0);
        $sancion = (int)($row['sancion'] ?? 0);
        $tarjeta = (int)($row['tarjeta'] ?? 0);
        $puntos = "{$r1} a {$r2}";
        if ($sancion > 0 || $tarjeta > 0) {
            $partes = [];
            if ($sancion > 0) $partes[] = "sancion {$sancion} pts";
            if ($tarjeta > 0) $partes[] = "tarjeta " . ($tarjetaTexto[$tarjeta] ?? $tarjeta);
            $puntos .= " (" . implode(", ", $partes) . ")";
        }
        $mensaje = "Resultados registrados: {$puntos}. Nota: Pasadas dos rondas, se tomará como verídico este resultado. Cualquier discrepancia debe ser reportada físicamente ante la mesa de control antes de ese plazo.";

        $url_resumen = AppHelpers::url('index.php', [
            'page' => 'torneo_gestion',
            'action' => 'resumen_individual',
            'torneo_id' => $torneo_id,
            'inscrito_id' => $id_usuario,
            'from' => 'notificaciones',
        ]);
        $url_clasificacion = AppHelpers::url('index.php', [
            'page' => 'torneo_gestion',
            'action' => 'posiciones',
            'torneo_id' => $torneo_id,
            'from' => 'notificaciones',
        ]);
        $tarjetaStr = $tarjeta > 0 ? ($tarjetaTexto[$tarjeta] ?? (string)$tarjeta) : '';
        $items[] = [
            'id' => $id_usuario,
            'telegram_chat_id' => $hasTg && !empty(trim((string)($row['telegram_chat_id'] ?? ''))) ? trim((string)$row['telegram_chat_id']) : null,
            'mensaje' => $mensaje,
            'url_destino' => $url_resumen,
            'datos_json' => [
                'tipo' => 'resultados_aprobados',
                'ronda' => (string)$ronda,
                'mesa' => (string)$mesa,
                'usuario_id' => $id_usuario,
                'nombre' => $nombre,
                'resultado1' => (string)$r1,
                'resultado2' => (string)$r2,
                'sancion' => (string)$sancion,
                'tarjeta_texto' => $tarjetaStr,
                'url_resumen' => $url_resumen,
                'url_clasificacion' => $url_clasificacion,
            ],
        ];
    }
    if (!empty($items)) {
        $nm->programarMasivoPersonalizado($items);
    }
}

/**
 * FVD (org. 1): en listados, «club» = entidad federativa (tabla entidad); el encabezado debe reflejar el código/nombre de entidad.
 */
function torneo_listado_equipos_por_entidad(int $organizacion_id): bool
{
    return $organizacion_id === FvdConfig::ORGANIZACION_ID;
}

/**
 * Obtiene datos de equipos para el administrador
 */
function obtenerDatosEquiposAdmin($torneo_id) {
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    $orgId = (int)($torneo['cod_org'] ?? 0);
    $porEntidad = torneo_listado_equipos_por_entidad($orgId);
    
    // Obtener todos los equipos del torneo (de todos los clubes)
    $stmt = $pdo->prepare("
        SELECT e.*, c.nombre as nombre_club,
               c.entidad AS club_entidad,
               ent.nombre AS nombre_entidad,
               (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . ") AS total_jugadores
        FROM equipos e
        LEFT JOIN clubes c ON e.id_club = c.id
        LEFT JOIN entidad ent ON ent.id = c.entidad
        WHERE e.id_torneo = ?
        ORDER BY " . ($porEntidad
            ? "COALESCE(ent.nombre, c.nombre, 'ZZZ') ASC, e.codigo_equipo ASC, e.nombre_equipo ASC"
            : "c.nombre ASC, e.nombre_equipo ASC") . "
    ");
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por entidad (org. 32) o por club
    $equipos_por_club = [];
    foreach ($equipos as $equipo) {
        if ($porEntidad) {
            $codEnt = (int)($equipo['club_entidad'] ?? 0);
            $grupo_id = $codEnt > 0 ? $codEnt : (int)($equipo['id_club'] ?? 0);
            $nombreGrupo = trim((string)($equipo['nombre_entidad'] ?? ''));
            if ($nombreGrupo === '') {
                $nombreGrupo = $equipo['nombre_club'] ?? 'Sin entidad';
            }
            if ($codEnt > 0) {
                $nombreGrupo = $codEnt . ' ”” ' . $nombreGrupo;
            }
        } else {
            $grupo_id = (int)($equipo['id_club'] ?? 0);
            $nombreGrupo = $equipo['nombre_club'] ?? 'Sin Club';
        }
        if (!isset($equipos_por_club[$grupo_id])) {
            $equipos_por_club[$grupo_id] = [
                'nombre' => $nombreGrupo,
                'equipos' => [],
            ];
        }
        $equipos_por_club[$grupo_id]['equipos'][] = $equipo;
    }
    
    return [
        'torneo' => $torneo,
        'equipos' => $equipos,
        'equipos_por_club' => $equipos_por_club,
        'total_equipos' => count($equipos)
    ];
}


/**
 * Obtiene datos para el panel de control de torneos por equipos
 */
function obtenerDatosPanelEquipos($torneo_id) {
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    
    // Obtener total de equipos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM equipos WHERE id_torneo = ?");
    $stmt->execute([$torneo_id]);
    $total_equipos = (int)$stmt->fetchColumn();
    
    // Obtener total de jugadores inscritos (con codigo_equipo)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND " . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO);
    $stmt->execute([$torneo_id]);
    $total_jugadores_inscritos = (int)$stmt->fetchColumn();
    
    // Obtener total de clubes con equipos
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT id_club) FROM equipos WHERE id_torneo = ?");
    $stmt->execute([$torneo_id]);
    $total_clubes_con_equipos = (int)$stmt->fetchColumn();
    
    // Obtener jugadores disponibles (NO inscritos - sin codigo_equipo y no retirados)
    $current_user = Auth::user();
    $user_club_id_raw = Auth::getUserClubId();
    $user_club_id = ($user_club_id_raw !== null && (int)$user_club_id_raw > 0) ? (int)$user_club_id_raw : null;
    $is_admin_general = Auth::isAdminGeneral();
    $is_admin_club = Auth::isAdminClub();
    
    $jugadores_disponibles = [];
    
    if ($is_admin_general) {
        // Admin general: todos los usuarios que no están inscritos
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM usuarios u
            LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('ins') . "
            WHERE u.role = 'usuario' 
              AND u.status = 0
              AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
        ");
        $stmt->execute([$torneo_id]);
        $total_jugadores_disponibles = (int)$stmt->fetchColumn();
    } else if ($user_club_id) {
        // Admin club o usuario: jugadores del territorio que no están inscritos
        if ($is_admin_club) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
            $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
        } else {
            $clubes_ids = [$user_club_id];
        }
        
        if (!empty($clubes_ids)) {
            $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM usuarios u
                LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('ins') . "
                WHERE u.role = 'usuario' 
                  AND u.status = 0
                  AND u.club_id IN ({$placeholders})
                  AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
            ");
            $stmt->execute(array_merge([$torneo_id], $clubes_ids));
            $total_jugadores_disponibles = (int)$stmt->fetchColumn();
        } else {
            $total_jugadores_disponibles = 0;
        }
    } else {
        $total_jugadores_disponibles = 0;
    }
    
    // Obtener información de rondas (igual que panel individual)
    $rondas_generadas = obtenerRondasGeneradas($torneo_id);
    $ultima_ronda = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
    $proxima_ronda = $ultima_ronda + 1;
    
    // Calcular si se puede generar la próxima ronda
    $puede_generar = true;
    $mesas_incompletas = 0;
    $total_mesas_ronda = 0;
    if ($ultima_ronda > 0) {
        $vm_eq = \Tournament\Handlers\RoundManagerHandler::verificarMesasPendientes($torneo_id);
        $mesas_incompletas = $vm_eq['mesas_incompletas'];
        $puede_generar = $vm_eq['puede_generar_ronda'];
        
        // Contar total de mesas de la última ronda
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT mesa) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0");
        $stmt->execute([$torneo_id, $ultima_ronda]);
        $total_mesas_ronda = (int)$stmt->fetchColumn();
    }
    
    // Estadísticas adicionales
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND registrado = 1");
    $stmt->execute([$torneo_id]);
    $total_partidas = (int)$stmt->fetchColumn();
    
    // Obtener información del club responsable
    $club_nombre = 'N/A';
    if (!empty($torneo['club_responsable'])) {
        $stmt = $pdo->prepare("SELECT nombre FROM clubes WHERE id = ?");
        $stmt->execute([$torneo['club_responsable']]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        $club_nombre = $club['nombre'] ?? 'N/A';
    }
    $torneo['club_nombre'] = $club_nombre;
    
    return [
        'torneo' => $torneo,
        'total_equipos' => $total_equipos,
        'total_jugadores_inscritos' => $total_jugadores_inscritos,
        'total_clubes_con_equipos' => $total_clubes_con_equipos,
        'total_jugadores_disponibles' => $total_jugadores_disponibles,
        'jugadores_por_equipo' => max(2, (int)($torneo['pareclub'] ?? 4)),
        // Información de rondas
        'rondas_generadas' => $rondas_generadas,
        'ultima_ronda' => $ultima_ronda,
        'proxima_ronda' => $proxima_ronda,
        'puede_generar_ronda' => $puede_generar,
        'mesas_incompletas' => $mesas_incompletas,
        'estadisticas' => [
            'total_equipos' => $total_equipos,
            'total_jugadores' => $total_jugadores_inscritos,
            'total_partidas' => $total_partidas,
            'mesas_ronda' => $total_mesas_ronda
        ]
    ];
}

/**
 * Obtiene datos para gestionar inscripciones de equipos (listado completo y por club)
 */
function obtenerDatosGestionarInscripcionesEquipos($torneo_id) {
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }

    $orgId = (int)($torneo['cod_org'] ?? 0);
    $porEntidad = torneo_listado_equipos_por_entidad($orgId);
    
    // Obtener todos los equipos del torneo ordenados por club (o entidad si org. 32) y código de equipo (secuencial)
    $stmt = $pdo->prepare("
        SELECT 
            e.*, 
            c.nombre as nombre_club,
            c.id as club_id,
            c.entidad AS club_entidad,
            ent.nombre AS nombre_entidad
        FROM equipos e
        LEFT JOIN clubes c ON e.id_club = c.id
        LEFT JOIN entidad ent ON ent.id = c.entidad
        WHERE e.id_torneo = ?
        ORDER BY 
            " . ($porEntidad
                ? "COALESCE(ent.nombre, c.nombre, 'ZZZ') ASC,\n            "
                : "COALESCE(c.nombre, 'ZZZ') ASC,\n            ") . "
            e.codigo_equipo ASC,
            e.nombre_equipo ASC
    ");
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agregar jugadores a cada equipo usando codigo_equipo desde inscritos
    foreach ($equipos as &$equipo) {
        $jugadores = [];
        if (!empty($equipo['codigo_equipo'])) {
            $stmt_jugadores = $pdo->prepare("
                SELECT 
                    i.id as id_inscrito,
                    i.id_usuario,
                    i.codigo_equipo,
                    " . (InscritosHelper::tieneColumnaActivoMesa($pdo)
                        ? 'COALESCE(i.activo_mesa, 1) AS activo_mesa'
                        : '1 AS activo_mesa') . ",
                    u.cedula,
                    u.nombre,
                    u.id as usuario_id
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                WHERE i.torneo_id = ? 
                    AND i.codigo_equipo = ?
                    AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . "
                ORDER BY activo_mesa DESC, i.id ASC
            ");
            $stmt_jugadores->execute([$torneo_id, $equipo['codigo_equipo']]);
            $jugadores = $stmt_jugadores->fetchAll(PDO::FETCH_ASSOC);
        }
        $equipo['jugadores'] = $jugadores;
        $equipo['total_jugadores'] = count($jugadores);
        $equipo['titulares'] = count(array_filter($jugadores, static function ($j) {
            return (int) ($j['activo_mesa'] ?? 1) === 1;
        }));
        $equipo['suplentes'] = $equipo['total_jugadores'] - $equipo['titulares'];
    }
    unset($equipo);
    
    // Agrupar equipos por entidad (org. 32) o por club
    $equipos_por_club = [];
    $grupo_ids_orden = [];
    foreach ($equipos as $equipo) {
        if ($porEntidad) {
            $codEnt = (int)($equipo['club_entidad'] ?? 0);
            $grupo_id = $codEnt > 0 ? $codEnt : (int)($equipo['club_id'] ?? 0);
            $club_nombre = trim((string)($equipo['nombre_entidad'] ?? ''));
            if ($club_nombre === '') {
                $club_nombre = $equipo['nombre_club'] ?? 'Sin entidad';
            }
            if ($codEnt > 0) {
                $club_nombre = $codEnt . ' ”” ' . $club_nombre;
            }
        } else {
            $grupo_id = (int)($equipo['club_id'] ?? 0);
            $club_nombre = $equipo['nombre_club'] ?? 'Sin Club';
        }

        if (!isset($equipos_por_club[$grupo_id])) {
            $equipos_por_club[$grupo_id] = [
                'id' => $grupo_id,
                'nombre' => $club_nombre,
                'equipos' => [],
            ];
            $grupo_ids_orden[] = $grupo_id;
        }
        $equipos_por_club[$grupo_id]['equipos'][] = $equipo;
    }

    $equipos_por_club_ordenado = [];
    foreach ($grupo_ids_orden as $gid) {
        if (isset($equipos_por_club[$gid])) {
            $equipos_por_club_ordenado[] = $equipos_por_club[$gid];
        }
    }
    $equipos_por_club = $equipos_por_club_ordenado;

    $contadores_inscripcion = InscritosHelper::contadoresResumenInscripcionTorneo(
        $pdo,
        (int) $torneo_id,
        (int) ($torneo['modalidad'] ?? 0)
    );

    return [
        'torneo' => $torneo,
        'equipos' => $equipos,
        'equipos_por_club' => $equipos_por_club,
        'jugadores_por_equipo' => max(2, (int)($torneo['pareclub'] ?? 4)),
        'contadores_inscripcion' => $contadores_inscripcion,
        'es_parejas' => ((int)($torneo['modalidad'] ?? 0) === 2),
    ];
}

/**
 * Obtiene datos para inscribir equipos en sitio (solo jugadores NO inscritos + equipos registrados)
 */
function obtenerDatosInscribirEquipoSitio($torneo_id) {
    require_once __DIR__ . '/../lib/ClubHelper.php';
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    require_once __DIR__ . '/../config/auth.php';
    
    $pdo = DB::pdo();
    
    $current_user = Auth::user();
    $user_club_id_raw = Auth::getUserClubId();
    $user_club_id = ($user_club_id_raw !== null && (int)$user_club_id_raw > 0) ? (int)$user_club_id_raw : null;
    $is_admin_general = Auth::isAdminGeneral();
    $is_admin_club = Auth::isAdminClub();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    
    // Obtener jugadores NO inscritos (sin codigo_equipo) del territorio del administrador
    $jugadores_disponibles = [];
    
    if ($is_admin_general) {
        // Admin general: todos los usuarios que no están inscritos o no tienen codigo_equipo
        $stmt = $pdo->prepare("
            SELECT u.id as id_usuario, u.nombre, u.cedula, u.sexo,
                   u.club_id as club_id, c.nombre as club_nombre,
                   ins.id as id_inscrito
            FROM usuarios u
            LEFT JOIN clubes c ON u.club_id = c.id
            LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('ins') . "
            WHERE u.role = 'usuario' 
              AND u.status = 0
              AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
            ORDER BY COALESCE(u.nombre, u.username) ASC
        ");
        $stmt->execute([$torneo_id]);
        $jugadores_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($user_club_id) {
        // Admin club o usuario: jugadores del territorio que no están inscritos
        if ($is_admin_club) {
            $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
            $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
        } else {
            $clubes_ids = [$user_club_id];
        }
        
        if (!empty($clubes_ids)) {
            $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT u.id as id_usuario, u.nombre, u.cedula, u.sexo,
                       u.club_id as club_id, c.nombre as club_nombre,
                       ins.id as id_inscrito
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('ins') . "
                WHERE u.role = 'usuario' 
                  AND u.status = 0
                  AND u.club_id IN ({$placeholders})
                  AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
                ORDER BY COALESCE(u.nombre, u.username) ASC
            ");
            $stmt->execute(array_merge([$torneo_id], $clubes_ids));
            $jugadores_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Agregar campo id para compatibilidad
    foreach ($jugadores_disponibles as &$jugador) {
        $jugador['id'] = $jugador['id_inscrito'] ?? null;
        $jugador['club_nombre'] = $jugador['club_nombre'] ?? 'Sin Club';
    }
    unset($jugador);
    
    $org_torneo_id = Auth::getTournamentOrganizacionId($torneo_id);
    $clubes_disponibles = [];
    $where_club_activo = "(c.estatus = 1 OR c.estatus = '1' OR c.estatus = 'activo')";
    if ($org_torneo_id) {
        $stmt = $pdo->prepare("SELECT c.id, c.nombre, c.entidad FROM clubes c WHERE (c.cod_org = ?" . (organizacionesHasCodOrg() ? " OR c.cod_org = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)" : "") . ") AND {$where_club_activo} ORDER BY c.nombre ASC");
        $stmt->execute(organizacionesHasCodOrg() ? [$org_torneo_id, $org_torneo_id] : [$org_torneo_id]);
        $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($is_admin_general) {
        $stmt = $pdo->query("SELECT id, nombre, entidad FROM clubes WHERE (estatus = 1 OR estatus = '1' OR estatus = 'activo') ORDER BY nombre ASC");
        $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user_club_id) {
        $stmt = $pdo->prepare('SELECT cod_org FROM clubes WHERE id = ? LIMIT 1');
        $stmt->execute([$user_club_id]);
        $org_usuario = $stmt->fetchColumn();
        if ($org_usuario) {
            $stmt = $pdo->prepare("SELECT c.id, c.nombre, c.entidad FROM clubes c WHERE (c.cod_org = ?" . (organizacionesHasCodOrg() ? " OR c.cod_org = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)" : "") . ") AND {$where_club_activo} ORDER BY c.nombre ASC");
            $stmt->execute(organizacionesHasCodOrg() ? [(int)$org_usuario, (int)$org_usuario] : [(int)$org_usuario]);
            $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($clubes_disponibles) && $is_admin_club) {
            $clubes_disponibles = ClubHelper::getClubesSupervisedWithData($user_club_id);
            $clubes_disponibles = array_map(static function ($r) {
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'nombre' => (string)($r['nombre'] ?? ''),
                    'entidad' => (int)($r['entidad'] ?? 0),
                ];
            }, $clubes_disponibles);
        }
        if (empty($clubes_disponibles) && $user_club_id) {
            $stmt = $pdo->prepare("SELECT id, nombre, entidad FROM clubes WHERE id = ? AND (estatus = 1 OR estatus = '1' OR estatus = 'activo')");
            $stmt->execute([$user_club_id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($club) {
                $clubes_disponibles = [$club];
            }
        }
    }
    $stmt = $pdo->prepare("
        SELECT e.id, e.codigo_equipo, e.nombre_equipo, e.id_club AS id_club,
               COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Club #', e.id_club)) AS nombre_club
        FROM equipos e
        LEFT JOIN clubes c ON c.id = e.id_club
        WHERE e.id_torneo = ?
        ORDER BY e.codigo_equipo ASC, e.nombre_equipo ASC
    ");
    $stmt->execute([$torneo_id]);
    $equipos_registrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($equipos_registrados as &$eq0) {
        $eq0['id_club'] = (int)($eq0['id_club'] ?? 0);
    }
    unset($eq0);
    $ids_select = array_map('intval', array_column($clubes_disponibles, 'id'));
    foreach ($equipos_registrados as $eqm) {
        $cid = (int)($eqm['id_club'] ?? 0);
        if ($cid <= 0 || in_array($cid, $ids_select, true)) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT id, nombre, entidad FROM clubes WHERE id = ? LIMIT 1');
        $stmt->execute([$cid]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fila) {
            $clubes_disponibles[] = ['id' => (int)$fila['id'], 'nombre' => $fila['nombre'] . ' (equipo)', 'entidad' => (int)($fila['entidad'] ?? 0)];
            $ids_select[] = $cid;
        }
    }
    foreach ($clubes_disponibles as &$cClub) {
        $ent = (int)($cClub['entidad'] ?? 0);
        $cClub['codigo_prefijo'] = $ent > 0 ? (string)$ent : (string)(int)($cClub['id'] ?? 0);
    }
    unset($cClub);
    usort($clubes_disponibles, static function ($a, $b) {
        return strcasecmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
    });
    $por_codigo = [];
    $codigos_eq = [];
    foreach ($equipos_registrados as $eq) {
        $c = trim((string)($eq['codigo_equipo'] ?? ''));
        if ($c !== '') {
            $codigos_eq[$c] = true;
        }
    }
    $codigos_list = array_keys($codigos_eq);
    if ($codigos_list !== []) {
        $ph = implode(',', array_fill(0, count($codigos_list), '?'));
        $stj = $pdo->prepare("
            SELECT i.codigo_equipo, i.id AS id_inscrito, i.id_usuario, u.cedula,
                   COALESCE(NULLIF(TRIM(u.nombre), ''), u.username) AS nombre
            FROM inscritos i INNER JOIN usuarios u ON u.id = i.id_usuario
            WHERE i.torneo_id = ? AND i.codigo_equipo IN ($ph)
              AND (i.estatus IS NULL OR i.estatus = '' OR i.estatus NOT IN ('retirado', 4))
            ORDER BY i.codigo_equipo ASC, nombre ASC
        ");
        $stj->execute(array_merge([$torneo_id], $codigos_list));
        foreach ($stj->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ck = (string)($row['codigo_equipo'] ?? '');
            if ($ck === '') {
                continue;
            }
            unset($row['codigo_equipo']);
            $por_codigo[$ck][] = $row;
        }
    }
    foreach ($equipos_registrados as &$eq) {
        $c = trim((string)($eq['codigo_equipo'] ?? ''));
        $eq['jugadores'] = ($c !== '' && isset($por_codigo[$c])) ? $por_codigo[$c] : [];
    }
    unset($eq);

    $contadores_inscripcion = InscritosHelper::contadoresResumenInscripcionTorneo(
        $pdo,
        (int) $torneo_id,
        (int) ($torneo['modalidad'] ?? 0)
    );

    return [
        'torneo' => $torneo,
        'jugadores_disponibles' => $jugadores_disponibles,
        'clubes_disponibles' => $clubes_disponibles,
        'equipos_registrados' => $equipos_registrados,
        'total_jugadores_disponibles' => count($jugadores_disponibles),
        'total_equipos' => count($equipos_registrados),
        'jugadores_por_equipo' => max(2, (int)($torneo['pareclub'] ?? 4)),
        'contadores_inscripcion' => $contadores_inscripcion,
    ];
}

/**
 * Obtiene datos para inscribir jugador en sitio.
 * Disponibles = todos los usuarios registrados bajo la entidad del torneo (no inscritos aún).
 * Inscritos = ya inscritos con estatus confirmado.
 */
function obtenerDatosInscribirSitio($torneo_id, $user_id, $is_admin_general) {
    $pdo = DB::pdo();
    
    $orgJoin = torneoOrgJoinExpr('t', 'o');
    $stmt = $pdo->prepare("SELECT t.*, COALESCE(t.entidad, o.entidad) AS entidad_torneo
                           FROM tournaments t
                           {$orgJoin} AND o.estatus = 1
                           WHERE t.id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $entidad_torneo = isset($torneo['entidad_torneo']) ? (int)$torneo['entidad_torneo'] : (int)($torneo['entidad'] ?? 0);
    unset($torneo['entidad_torneo']);
    
    // Usuarios disponibles = todos los de la entidad del torneo (role usuario, activos)
    // Pertenen a la entidad si: u.entidad = entidad_torneo O su club está en una org de esa entidad
    $usuarios_territorio = [];
    if ($entidad_torneo > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.username, u.nombre, u.cedula, u.entidad, u.club_id,
                   c.nombre as club_nombre,
                   COALESCE(NULLIF(c.id, 0), NULLIF(u.club_id, 0), NULLIF(u.entidad, 0)) as club_id_resuelto
            FROM usuarios u
            LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(u.club_id, 0), NULLIF(u.entidad, 0))
            LEFT JOIN organizaciones o ON (c.cod_org = o.id" . (organizacionesHasCodOrg() ? " OR c.cod_org = o.cod_org" : "") . ") AND o.estatus = 1
            WHERE u.role = 'usuario'
              AND u.status = 0
              AND (u.entidad = ? OR o.entidad = ? OR c.id = ? OR c.entidad = ?)
            ORDER BY COALESCE(u.nombre, u.username) ASC
        ");
        $stmt->execute([$entidad_torneo, $entidad_torneo, $entidad_torneo, $entidad_torneo]);
        $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Sin entidad en torneo: usuarios de la organización que organiza el torneo (club_responsable = org id)
        $org_id = (int)($torneo['club_responsable'] ?? 0);
        if ($org_id > 0) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.username, u.nombre, u.cedula, u.entidad, c.nombre as club_nombre, c.id as club_id
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.role = 'usuario'
                  AND u.status = 0
                  AND (c.cod_org = ?" . (organizacionesHasCodOrg() ? " OR c.cod_org = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)" : "") . ")
                ORDER BY COALESCE(u.nombre, u.username) ASC
            ");
            $stmt->execute(organizacionesHasCodOrg() ? [$org_id, $org_id] : [$org_id]);
            $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Normalizar club_id del listado (clubes.id = código de asociación)
    foreach ($usuarios_territorio as &$uRow) {
        $resolved = (int) ($uRow['club_id_resuelto'] ?? 0);
        if ($resolved > 0) {
            $uRow['club_id'] = $resolved;
        }
        unset($uRow['club_id_resuelto']);
    }
    unset($uRow);

    // Inscritos activos en el torneo (pendiente, confirmado; no retirados)
    require_once __DIR__ . '/../lib/InscritosHelper.php';
    $sqlInscActivo = InscritosHelper::sqlWhereActivoConAlias('i');
    $stmt = $pdo->prepare("
        SELECT i.id_usuario, i.estatus, i.id_club,
               u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre
        FROM inscritos i
        LEFT JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ?
          AND {$sqlInscActivo}
        ORDER BY COALESCE(u.nombre, u.username) ASC
    ");
    $stmt->execute([$torneo_id]);
    $usuarios_inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $usuarios_inscritos_ids = array_column($usuarios_inscritos, 'id_usuario');
    
    // Disponibles: se cargan por AJAX (asociación + estatus); vacío en carga inicial salvo operativo legacy
    $usuarios_disponibles = [];
    
    // Clubes disponibles: clubes.id es el código de asociación
    require_once __DIR__ . '/../lib/ClubHelper.php';
    $clubes_disponibles = ClubHelper::listarClubesActivos($pdo, '', 'id ASC');
    if ($clubes_disponibles === []) {
        $clubes_disponibles = $pdo->query('SELECT id, nombre FROM clubes ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    $inscripcion_operativo_asoc = false;
    $club_forzado_id = 0;
    $club_forzado_nombre = '';
    require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
    $ctxOp = AsociacionAdminHelper::contextoInscripcionOperativa($pdo);
    if ($ctxOp !== null && ($ctxOp['club_id'] ?? 0) > 0) {
        $inscripcion_operativo_asoc = true;
        $club_forzado_id = (int) $ctxOp['club_id'];
        $club_forzado_nombre = (string) ($ctxOp['club_nombre'] ?? '');
        $eid = (int) ($ctxOp['entidad_id'] ?? 0);
        $cid = $club_forzado_id;
        $usuarios_territorio = array_values(array_filter($usuarios_territorio, static function ($u) use ($eid, $cid) {
            $uEnt = (int) ($u['entidad'] ?? 0);
            $uClub = (int) ($u['club_id'] ?? 0);

            return $uEnt === $eid || $uClub === $cid;
        }));
        $clubes_disponibles = [['id' => $club_forzado_id, 'nombre' => $club_forzado_nombre]];
    }

    return [
        'torneo' => $torneo,
        'usuarios_disponibles' => array_values($usuarios_disponibles),
        'usuarios_inscritos' => $usuarios_inscritos,
        'clubes_disponibles' => $clubes_disponibles,
        'inscripcion_operativo_asoc' => $inscripcion_operativo_asoc,
        'club_forzado_id' => $club_forzado_id,
        'club_forzado_nombre' => $club_forzado_nombre,
    ];
}

/**
 * Guarda inscripción de jugador en sitio
 */
function guardarInscripcionSitio($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);

        require_once __DIR__ . '/../lib/Tournament/Handlers/RegistrationHandler.php';
        $current_user = Auth::user();
        $user_club_id = $current_user['club_id'] ?? null;

        $r = \Tournament\Handlers\RegistrationHandler::registrarJugador([
            'torneo_id' => (int) $torneo_id,
            'actor_user_id' => (int) $user_id,
            'actor_club_id' => $user_club_id,
            'post' => $_POST,
        ]);

        if (!empty($r['ok'])) {
            $_SESSION['success'] = $r['success_message'] ?? 'Jugador inscrito exitosamente';
            header('Location: ' . buildRedirectUrl('inscribir_sitio', ['torneo_id' => $torneo_id]));
            exit;
        }

        $_SESSION['error'] = $r['error'] ?? 'Error al inscribir';
        header('Location: ' . buildRedirectUrl('inscribir_sitio', ['torneo_id' => $torneo_id]));
        exit;
    } catch (Exception $e) {
        error_log('Error al inscribir jugador: ' . $e->getMessage());
        $_SESSION['error'] = 'Error al inscribir: ' . $e->getMessage();
        header('Location: ' . buildRedirectUrl('inscribir_sitio', ['torneo_id' => $torneo_id]));
        exit;
    }
}

/**
 * Cuenta mesas incompletas de una ronda
 */
function contarMesasIncompletas($torneo_id, $ronda) {
    return \Tournament\Handlers\RoundManagerHandler::contarMesasIncompletas((int) $torneo_id, (int) $ronda);
}

/**
 * Devuelve los números de mesa asignados a un operador para un torneo y ronda (ámbito del operador).
 * Si el usuario no es operador o no tiene asignación, devuelve null (sin restricción).
 */
function obtenerMesasAsignadasOperador($torneo_id, $ronda, $user_id, $user_role) {
    if ($user_role !== 'operador' || $user_id <= 0) {
        return null;
    }
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'operador_mesa_asignacion'");
        if ($stmt->rowCount() === 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT mesa_numero FROM operador_mesa_asignacion WHERE torneo_id = ? AND ronda = ? AND user_id_operador = ? ORDER BY mesa_numero ASC");
        $stmt->execute([$torneo_id, $ronda, $user_id]);
        $nums = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'mesa_numero');
        return array_map('intval', $nums);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Obtiene datos para registrar resultados (versión v2).
 * Si el usuario es operador, solo ve y puede operar las mesas asignadas (ámbito limitado).
 */
function obtenerDatosRegistroResultados($torneo_id, $ronda, $mesa, $user_id = 0, $user_role = '') {
    $pdo = DB::pdo();
    ensureTournamentsCorreccionesCierreColumn();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener todas las rondas del torneo
    $sql = "SELECT DISTINCT partida FROM partiresul WHERE id_torneo = ? ORDER BY partida ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $todasLasRondas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener todas las mesas de la ronda
    $sql = "SELECT DISTINCT 
                pr.mesa as numero,
                MAX(pr.registrado) as registrado
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            GROUP BY pr.mesa
            ORDER BY CAST(pr.mesa AS UNSIGNED) ASC, pr.mesa ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda]);
    $todasLasMesas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Asegurar que el número de mesa sea un entero y filtrar valores inválidos
    $mesas_filtradas = [];
    foreach ($todasLasMesas as $m) {
        $numeroMesa = (int)$m['numero'];
        if ($numeroMesa > 0) {
            $mesas_filtradas[] = [
                'numero' => $numeroMesa,
                'registrado' => (int)($m['registrado'] ?? 0),
                'tiene_resultados' => ($m['registrado'] ?? 0) > 0
            ];
        }
    }
    usort($mesas_filtradas, function($a, $b) {
        return $a['numero'] - $b['numero'];
    });
    
    // Operador: limitar ámbito a sus mesas asignadas (ej. mesas 1 a 10)
    $mesas_operador = obtenerMesasAsignadasOperador($torneo_id, $ronda, $user_id, $user_role);
    if ($mesas_operador !== null) {
        if (empty($mesas_operador)) {
            $mesas_filtradas = [];
        } else {
            $set_operador = array_flip($mesas_operador);
            $mesas_filtradas = array_filter($mesas_filtradas, function($m) use ($set_operador) {
                return isset($set_operador[$m['numero']]);
            });
            $mesas_filtradas = array_values($mesas_filtradas);
        }
    }
    
    $todasLasMesas = $mesas_filtradas;
    
    // Validar que la mesa solicitada existe en las mesas permitidas
    $mesa = (int)$mesa;
    $mesasExistentes = array_column($todasLasMesas, 'numero');
    $maxMesa = !empty($mesasExistentes) ? max($mesasExistentes) : 0;
    
    if ($mesa > 0 && !in_array($mesa, $mesasExistentes)) {
        if (!empty($mesasExistentes)) {
            $mesa = min($mesasExistentes);
            $_SESSION['warning'] = "La mesa solicitada no está en su ámbito. Se ha redirigido a la mesa #{$mesa}.";
        } else {
            $_SESSION['error'] = $mesas_operador !== null
                ? "No tiene mesas asignadas para esta ronda. Contacte al administrador."
                : "No hay mesas asignadas para la ronda {$ronda}.";
            $mesa = 0;
        }
    }
    
    if ($mesa === 0 && !empty($mesasExistentes)) {
        $mesa = min($mesasExistentes);
    }
    
    if ($mesa > $maxMesa && $maxMesa > 0) {
        $mesa = $maxMesa;
        $_SESSION['warning'] = "Se ha redirigido a la última mesa de su ámbito (mesa #{$maxMesa}).";
    }
    
    // Debug: Log de mesas encontradas
    error_log("Mesas encontradas para torneo $torneo_id, ronda $ronda: " . implode(', ', array_column($todasLasMesas, 'numero')));
    
    // Obtener jugadores de la mesa actual (incluyendo id de partiresul y estado de tarjeta en inscritos en una sola consulta)
    $sql = "SELECT 
                pr.id,
                pr.*,
                u.nombre as nombre_completo,
                i.posicion,
                i.ganados,
                i.perdidos,
                i.efectividad,
                i.puntos as puntos_acumulados,
                i.sancion as sancion_acumulada,
                COALESCE(i.tarjeta, 0) AS tarjeta_inscritos
            FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
            ORDER BY pr.secuencia ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tarjeta previa desde partidas anteriores (para indicador y resaltado; evita doble escalación al re-editar)
    require_once __DIR__ . '/../lib/SancionesHelper.php';
    $idsJugadores = array_map(function ($j) { return (int)$j['id_usuario']; }, $jugadores);
    $tarjetaPreviaPorUsuario = SancionesHelper::getTarjetaPreviaDesdePartidasAnteriores($pdo, $torneo_id, $ronda, $idsJugadores);
    foreach ($jugadores as &$jugador) {
        $tarjetaPrevia = (int)($tarjetaPreviaPorUsuario[$jugador['id_usuario']] ?? 0);
        $jugador['inscrito'] = [
            'posicion' => (int)($jugador['posicion'] ?? 0),
            'ganados' => (int)($jugador['ganados'] ?? 0),
            'perdidos' => (int)($jugador['perdidos'] ?? 0),
            'efectividad' => (int)($jugador['efectividad'] ?? 0),
            'puntos' => (int)($jugador['puntos'] ?? 0),
            'tarjeta' => (int)($jugador['tarjeta_inscritos'] ?? 0),
            'tarjeta_previa' => $tarjetaPrevia
        ];
    }
    
    // Obtener observaciones
    $observacionesMesa = '';
    if (!empty($jugadores) && isset($jugadores[0]['observaciones'])) {
        $observacionesMesa = $jugadores[0]['observaciones'] ?? '';
    }
    
    // Estadísticas de mesas
    $mesasCompletadas = 0;
    foreach ($todasLasMesas as $m) {
        if ($m['tiene_resultados']) {
            $mesasCompletadas++;
        }
    }
    $totalMesas = count($todasLasMesas);
    $mesasPendientes = $totalMesas - $mesasCompletadas;
    
    // Mesa anterior y siguiente
    $mesaAnterior = null;
    $mesaSiguiente = null;
    foreach ($todasLasMesas as $index => $m) {
        if ($m['numero'] == $mesa) {
            if ($index > 0) {
                $mesaAnterior = $todasLasMesas[$index - 1]['numero'];
            }
            if ($index < count($todasLasMesas) - 1) {
                $mesaSiguiente = $todasLasMesas[$index + 1]['numero'];
            }
            break;
        }
    }
    
    $vieneDeResumen = isset($_GET['from']) && $_GET['from'] === 'resumen';
    $inscritoId = isset($_GET['inscrito_id']) ? (int)$_GET['inscrito_id'] : null;
    
    // Countdown "Correcciones se cierran en": usa correcciones_cierre_at (fijado al guardar última mesa; no se resetea)
    $rondas_generadas = obtenerRondasGeneradas($torneo_id);
    $ultima_ronda_global = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
    $totalRondas = (int)($torneo['rondas'] ?? 0);
    $mesas_incompletas_global = $ultima_ronda_global > 0 ? contarMesasIncompletas($torneo_id, $ultima_ronda_global) : 0;
    $torneo_completado = $totalRondas > 0 && $ultima_ronda_global >= $totalRondas && $mesas_incompletas_global == 0;
    $countdown_fin_timestamp = null;
    $mostrar_countdown_correcciones = false;
    $correcciones_cierre_at = isset($torneo['correcciones_cierre_at']) ? $torneo['correcciones_cierre_at'] : null;
    if (!empty($correcciones_cierre_at) && $correcciones_cierre_at !== '0000-00-00 00:00:00') {
        $countdown_fin_timestamp = strtotime($correcciones_cierre_at);
        $mostrar_countdown_correcciones = $torneo_completado;
    }
    $puede_cerrar_torneo = $torneo_completado && !((int)($torneo['locked'] ?? 0) === 1);
    
    return [
        'torneo' => $torneo,
        'ronda' => $ronda,
        'mesaActual' => $mesa,
        'jugadores' => $jugadores,
        'todasLasMesas' => $todasLasMesas,
        'todasLasRondas' => $todasLasRondas,
        'mesasCompletadas' => $mesasCompletadas,
        'mesasPendientes' => $mesasPendientes,
        'totalMesas' => $totalMesas,
        'mesaAnterior' => $mesaAnterior,
        'mesaSiguiente' => $mesaSiguiente,
        'observacionesMesa' => $observacionesMesa,
        'vieneDeResumen' => $vieneDeResumen,
        'inscritoId' => $inscritoId,
        'es_operador_ambito' => $mesas_operador !== null,
        'mesas_ambito' => $mesas_operador !== null ? $mesas_operador : [],
        'mostrar_countdown_correcciones' => $mostrar_countdown_correcciones,
        'countdown_fin_timestamp' => $countdown_fin_timestamp,
        'puede_cerrar_torneo' => $puede_cerrar_torneo,
    ];
}

/**
 * Obtiene datos para la cuadrícula
 */
function obtenerDatosCuadricula($torneo_id, $ronda) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);

    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND estatus != 4');
    $stCnt->execute([(int) $torneo_id]);
    $totalInscritos = (int) $stCnt->fetchColumn();
    
    // Obtener asignaciones (incluye mesa > 0 y mesa 0 = BYE), ordenadas por id_usuario ASC
    // La letra (A,C,B,D) se asigna según secuencia: 1=A, 2=C, 3=B, 4=D
    $sql = "SELECT 
                pr.id_usuario,
                pr.mesa,
                pr.secuencia,
                u.id AS usuario_id_real,
                COALESCE(u.numfvd, 0) AS numfvd,
                u.nombre as nombre_completo,
                u.username
            FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            WHERE pr.id_torneo = ? AND pr.partida = ?
            ORDER BY pr.id_usuario ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda]);
    $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'titulo' => 'Cuadrícula de Asignaciones - Ronda ' . $ronda,
        'torneo' => $torneo,
        'numRonda' => $ronda,
        'asignaciones' => $asignaciones,
        'totalAsignaciones' => count($asignaciones),
        'totalInscritos' => $totalInscritos,
    ];
}

/**
 * Obtiene datos para hojas de anotación
 */
function obtenerDatosHojasAnotacion($torneo_id, $ronda) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $es_torneo_equipos = (int)($torneo['modalidad'] ?? 0) === 3;
    $es_torneo_parejas = (int)($torneo['modalidad'] ?? 0) === 2;
    
    // Obtener inscritos con estadísticas (incluyendo tarjeta y codigo_equipo)
    $sql = "SELECT 
                id_usuario,
                codigo_equipo,
                posicion,
                ganados,
                perdidos,
                efectividad,
                puntos,
                sancion,
                tarjeta
            FROM inscritos
            WHERE torneo_id = ?
            ORDER BY posicion ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $inscritosMap = [];
    foreach ($inscritos as $inscrito) {
        $inscritosMap[$inscrito['id_usuario']] = $inscrito;
    }
    
    // Equipos / parejas (nombre y stats de tabla equipos): modalidad equipos (3) o parejas (2)
    $equiposMap = [];
    $estadisticasEquipos = [];
    if ($es_torneo_equipos || $es_torneo_parejas) {
        $sql = "SELECT 
                    e.codigo_equipo,
                    e.nombre_equipo,
                    e.id_club,
                    c.nombre as nombre_club
                FROM equipos e
                LEFT JOIN clubes c ON e.id_club = c.id
                WHERE e.id_torneo = ? AND e.estatus = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneo_id]);
        $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($equipos as $equipo) {
            $equiposMap[$equipo['codigo_equipo']] = $equipo;
        }
        
        // Estadísticas y posición de equipos desde tabla equipos (posicion/clasiequi ya calculada)
        $sql = "SELECT codigo_equipo, posicion, puntos, ganados, perdidos, efectividad
                FROM equipos
                WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != ''
                ORDER BY posicion ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneo_id]);
        $statsEquipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $njEq = $es_torneo_equipos ? 4 : 2;
        foreach ($statsEquipos as $stat) {
            $posicion = (int)($stat['posicion'] ?? 0);
            $estadisticasEquipos[$stat['codigo_equipo']] = [
                'posicion' => $posicion,
                'clasiequi' => $posicion,
                'puntos' => (int)($stat['puntos'] ?? 0),
                'ganados' => (int)($stat['ganados'] ?? 0),
                'perdidos' => (int)($stat['perdidos'] ?? 0),
                'efectividad' => (int)($stat['efectividad'] ?? 0),
                'total_jugadores' => $njEq
            ];
        }
    }
    
    // Obtener mesas
    $sql = "SELECT 
                pr.*,
                u.nombre as nombre_completo,
                i.codigo_equipo,
                c.nombre as nombre_club
            FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE pr.id_torneo = ? AND pr.partida = ?
            ORDER BY pr.mesa ASC, pr.secuencia ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por mesa
    $mesas = [];
    foreach ($resultados as $resultado) {
        $numMesa = (int)$resultado['mesa'];
        if ($numMesa > 0) {
            if (!isset($mesas[$numMesa])) {
                $mesas[$numMesa] = [
                    'numero' => $numMesa,
                    'jugadores' => []
                ];
            }
            
            $idUsuario = $resultado['id_usuario'];
            $inscritoData = $inscritosMap[$idUsuario] ?? [
                'posicion' => 0, 'ganados' => 0, 'perdidos' => 0,
                'efectividad' => 0, 'puntos' => 0, 'sancion' => 0, 'tarjeta' => 0,
                'codigo_equipo' => null
            ];
            
            // Agregar información del equipo (modalidad equipos o parejas)
            $codigoEquipo = $resultado['codigo_equipo'] ?? $inscritoData['codigo_equipo'] ?? null;
            if (($es_torneo_equipos || $es_torneo_parejas) && $codigoEquipo) {
                $equipoData = $equiposMap[$codigoEquipo] ?? null;
                if ($equipoData) {
                    $resultado['nombre_equipo'] = $equipoData['nombre_equipo'];
                    $resultado['codigo_equipo_display'] = $equipoData['codigo_equipo'];
                }
                
                // Agregar estadísticas del equipo
                if (isset($estadisticasEquipos[$codigoEquipo])) {
                    $resultado['estadisticas_equipo'] = $estadisticasEquipos[$codigoEquipo];
                }
            }
            
            // Usar la tarjeta de inscritos (última tarjeta del jugador en el torneo)
            $resultado['tarjeta'] = (int)($inscritoData['tarjeta'] ?? 0);
            $resultado['inscrito'] = [
                'posicion' => (int)($inscritoData['posicion'] ?? 0),
                'ganados' => (int)($inscritoData['ganados'] ?? 0),
                'perdidos' => (int)($inscritoData['perdidos'] ?? 0),
                'efectividad' => (int)($inscritoData['efectividad'] ?? 0),
                'puntos' => (int)($inscritoData['puntos'] ?? 0),
                'sancion' => (int)($inscritoData['sancion'] ?? 0),
                'tarjeta' => (int)($inscritoData['tarjeta'] ?? 0)
            ];
            
            $mesas[$numMesa]['jugadores'][] = $resultado;
        }
    }
    
    return [
        'torneo' => $torneo,
        'ronda' => $ronda,
        'mesas' => array_values($mesas),
        'es_torneo_equipos' => $es_torneo_equipos,
        'es_torneo_parejas' => $es_torneo_parejas,
    ];
}

/**
 * Obtiene datos para asignar mesas a operadores (operadores del club del torneo, mesas de la ronda, asignaciones actuales).
 */
function obtenerDatosAsignarMesasOperador($torneo_id, $ronda) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    $club_responsable = (int)($torneo['club_responsable'] ?? 0);
    require_once __DIR__ . '/../lib/ClubHelper.php';
    $club_ids = ClubHelper::getClubesSupervised($club_responsable);
    if (empty($club_ids)) {
        $club_ids = [$club_responsable];
    }
    $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.username
        FROM usuarios u
        WHERE u.role = 'operador' AND u.club_id IN ($placeholders) AND u.status = 0
        ORDER BY u.nombre ASC
    ");
    $stmt->execute(array_values($club_ids));
    $operadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("
        SELECT DISTINCT CAST(pr.mesa AS UNSIGNED) as numero
        FROM partiresul pr
        WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
        ORDER BY numero ASC
    ");
    $stmt->execute([$torneo_id, $ronda]);
    $mesas_numeros = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'numero');
    $asignaciones = [];
    $table_exists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'operador_mesa_asignacion'");
        $table_exists = $stmt->rowCount() > 0;
    } catch (Exception $e) {}
    if ($table_exists) {
        $stmt = $pdo->prepare("SELECT mesa_numero, user_id_operador FROM operador_mesa_asignacion WHERE torneo_id = ? AND ronda = ?");
        $stmt->execute([$torneo_id, $ronda]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $asignaciones[(int)$row['mesa_numero']] = (int)$row['user_id_operador'];
        }
    }
    return [
        'torneo' => $torneo,
        'torneo_id' => $torneo_id,
        'ronda' => $ronda,
        'operadores' => $operadores,
        'mesas_numeros' => $mesas_numeros,
        'asignaciones' => $asignaciones,
        'tabla_existe' => $table_exists,
    ];
}

/**
 * Guarda la asignación de mesas a operadores (crea tabla si no existe).
 */
function guardarAsignacionMesasOperador($torneo_id, $ronda, $user_id, $is_admin_general) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $sql_file = __DIR__ . '/../sql/operador_mesa_asignacion.sql';
    if (file_exists($sql_file)) {
        $sql = file_get_contents($sql_file);
        if ($sql) {
            try {
                $pdo->exec($sql);
            } catch (Exception $e) {
                // Tabla ya existe o error; continuar
            }
        }
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS operador_mesa_asignacion (
          torneo_id INT NOT NULL,
          ronda INT NOT NULL,
          mesa_numero INT NOT NULL,
          user_id_operador INT NOT NULL,
          created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (torneo_id, ronda, mesa_numero),
          KEY idx_oma_operador (user_id_operador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    $asignaciones = $_POST['asignacion'] ?? [];
    if (!is_array($asignaciones)) {
        $asignaciones = [];
    }
    $pdo->beginTransaction();
    try {
        $stmtDel = $pdo->prepare("DELETE FROM operador_mesa_asignacion WHERE torneo_id = ? AND ronda = ?");
        $stmtDel->execute([$torneo_id, $ronda]);
        $stmtIns = $pdo->prepare("INSERT INTO operador_mesa_asignacion (torneo_id, ronda, mesa_numero, user_id_operador) VALUES (?, ?, ?, ?)");
        foreach ($asignaciones as $mesa_numero => $user_id_op) {
            $mesa_numero = (int)$mesa_numero;
            $user_id_op = (int)$user_id_op;
            if ($mesa_numero > 0 && $user_id_op > 0) {
                $stmtIns->execute([$torneo_id, $ronda, $mesa_numero, $user_id_op]);
            }
        }
        $pdo->commit();
        $_SESSION['success'] = 'Asignación de mesas a operadores guardada correctamente.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error al guardar: ' . $e->getMessage();
    }
    $url = buildRedirectUrl('asignar_mesas_operador', ['torneo_id' => $torneo_id, 'ronda' => $ronda]);
    header('Location: ' . $url);
    exit;
}

/**
 * Obtiene datos para resumen individual
 */
function obtenerDatosResumenIndividual($torneo_id, $inscrito_id) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener inscrito
    if (! class_exists('NumfvdHelper', false)) {
        require_once __DIR__ . '/../lib/NumfvdHelper.php';
    }
    $sql = "SELECT i.*, u.nombre as nombre_completo, c.nombre as nombre_club
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? AND i.id_usuario = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $inscrito_id]);
    $inscrito = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inscrito) {
        throw new Exception('Jugador no encontrado en este torneo');
    }
    $nfInscrito = NumfvdHelper::numfvdInscrito($pdo, $torneo_id, (int) $inscrito_id);
    if ($nfInscrito > 0) {
        $inscrito['numfvd'] = $nfInscrito;
    }
    
    $esModalidadEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
    $equipo = null;
    if ($esModalidadEquipos && !empty($inscrito['codigo_equipo'])) {
        $stmtEq = $pdo->prepare("
            SELECT codigo_equipo, nombre_equipo, puntos, ganados, perdidos, efectividad, sancion
            FROM equipos
            WHERE id_torneo = ? AND codigo_equipo = ? AND estatus = 0
            LIMIT 1
        ");
        $stmtEq->execute([$torneo_id, $inscrito['codigo_equipo']]);
        $equipo = $stmtEq->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    // Obtener la última ronda asignada
    $stmtUltimaRonda = $pdo->prepare("SELECT MAX(partida) as ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
    $stmtUltimaRonda->execute([$torneo_id]);
    $ultimaRonda = (int)$stmtUltimaRonda->fetchColumn() ?? 0;
    
    // Obtener resumen de participación con información de pareja y contrarios
    // Incluir solo registrados, pero también incluir la última ronda aunque no esté registrada
    $sql = "SELECT 
                pr.partida,
                pr.mesa,
                pr.secuencia,
                pr.resultado1,
                pr.resultado2,
                pr.efectividad,
                pr.sancion,
                pr.ff,
                pr.tarjeta,
                pr.registrado,
                CASE WHEN pr.resultado1 > pr.resultado2 THEN 1 ELSE 0 END as gano
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.id_usuario = ? AND pr.mesa > 0 
            AND (pr.registrado = 1 OR (pr.registrado = 0 AND pr.partida = ?))
            ORDER BY pr.partida ASC, pr.mesa ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $inscrito_id, $ultimaRonda]);
    $partidasJugador = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (! function_exists('detectarModoCalculoMesa')) {
        require_once __DIR__ . '/../lib/partiresul_efectividad_funcs.php';
    }
    $puntosTorneo = (int) ($torneo['puntos'] ?? 200);
    if ($puntosTorneo <= 0) {
        $puntosTorneo = 200;
    }
    
    // Para cada partida, obtener información de pareja y contrarios
    $resumenParticipacion = [];
    
    foreach ($partidasJugador as $partidaJugador) {
        $partida = (int)$partidaJugador['partida'];
        $mesa = (int)$partidaJugador['mesa'];
        $secuencia = (int)$partidaJugador['secuencia'];
        
        // Obtener todos los jugadores de esta mesa
        $exprNumfvdMesa = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo, 'u');
        $sqlMesa = "SELECT 
                        pr.secuencia,
                        pr.id_usuario,
                        pr.resultado1,
                        pr.resultado2,
                        pr.efectividad,
                        pr.ff,
                        pr.tarjeta,
                        pr.sancion,
                        {$exprNumfvdMesa} AS numfvd,
                        u.nombre as nombre_completo,
                        COALESCE(c.nombre, 'Sin Club') as club_nombre
                    FROM partiresul pr
                    INNER JOIN usuarios u ON (
                        u.id = pr.id_usuario
                        OR (
                            u.numfvd = pr.id_usuario
                            AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                            AND EXISTS (
                                SELECT 1 FROM tournaments tx
                                WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                            )
                        )
                    )
                    LEFT JOIN inscritos i ON i.id_usuario = pr.id_usuario AND i.torneo_id = pr.id_torneo
                    LEFT JOIN clubes c ON i.id_club = c.id
                    WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
                    ORDER BY pr.secuencia ASC";
        
        $stmtMesa = $pdo->prepare($sqlMesa);
        $stmtMesa->execute([$torneo_id, $partida, $mesa]);
        $jugadoresMesa = $stmtMesa->fetchAll(PDO::FETCH_ASSOC);
        
        // Identificar compañero y contrarios
        // Pareja A: secuencias 1-2, Pareja B: secuencias 3-4
        $compañero = null;
        $contrarios = [];
        
        $esParejaA = ($secuencia == 1 || $secuencia == 2);
        
        foreach ($jugadoresMesa as $jugador) {
            $seq = (int)$jugador['secuencia'];
            
            if ($seq == $secuencia) {
                // Es el jugador actual, saltar
                continue;
            }
            
            if ($esParejaA) {
                // Jugador está en Pareja A (secuencias 1-2)
                if ($seq == 1 || $seq == 2) {
                    // Es compañero
                    $compañero = [
                        'nombre' => $jugador['nombre_completo'],
                        'club' => $jugador['club_nombre'] ?? 'Sin Club',
                        'id_usuario' => (int)$jugador['id_usuario'],
                        'numfvd' => (int) ($jugador['numfvd'] ?? 0),
                    ];
                } elseif ($seq == 3 || $seq == 4) {
                    // Es contrario (Pareja B)
                    $contrarios[] = [
                        'nombre' => $jugador['nombre_completo'],
                        'club' => $jugador['club_nombre'] ?? 'Sin Club',
                        'id_usuario' => (int)$jugador['id_usuario'],
                        'numfvd' => (int) ($jugador['numfvd'] ?? 0),
                    ];
                }
            } else {
                // Jugador está en Pareja B (secuencias 3-4)
                if ($seq == 3 || $seq == 4) {
                    // Es compañero
                    $compañero = [
                        'nombre' => $jugador['nombre_completo'],
                        'club' => $jugador['club_nombre'] ?? 'Sin Club',
                        'id_usuario' => (int)$jugador['id_usuario'],
                        'numfvd' => (int) ($jugador['numfvd'] ?? 0),
                    ];
                } elseif ($seq == 1 || $seq == 2) {
                    // Es contrario (Pareja A)
                    $contrarios[] = [
                        'nombre' => $jugador['nombre_completo'],
                        'club' => $jugador['club_nombre'] ?? 'Sin Club',
                        'id_usuario' => (int)$jugador['id_usuario'],
                        'numfvd' => (int) ($jugador['numfvd'] ?? 0),
                    ];
                }
            }
        }
        
        $estaRegistrado = ((int)($partidaJugador['registrado'] ?? 0) == 1);
        $efectividadFila = $estaRegistrado ? (int)($partidaJugador['efectividad'] ?? 0) : null;
        
        if ($estaRegistrado && count($jugadoresMesa) === 4) {
            $modoMesa = detectarModoCalculoMesa($jugadoresMesa);
            $efectividadFila = calcularEfectividadJugadorMesa($partidaJugador, $modoMesa, $puntosTorneo);
        }
        
        // Solo calcular resultados si está registrado
        $hayForfait = false;
        $hayTarjetaGrave = false;
        $sancion = 0;
        $resultado1 = 0;
        $resultado2 = 0;
        $gano = false;
        
        if ($estaRegistrado) {
            // Determinar si ganó - evaluar individualmente considerando sanciones
            $hayForfait = ((int)$partidaJugador['ff'] == 1);
            $hayTarjetaGrave = ((int)$partidaJugador['tarjeta'] >= 3);
            $sancion = (int)($partidaJugador['sancion'] ?? 0);
            $resultado1 = (int)($partidaJugador['resultado1'] ?? 0);
            $resultado2 = (int)($partidaJugador['resultado2'] ?? 0);
            
            if ($hayForfait) {
                $gano = false; // El jugador con forfait pierde
            } elseif ($hayTarjetaGrave) {
                $gano = false; // El jugador con tarjeta grave pierde
            } elseif ($sancion > 0) {
                // Si hay sanción, evaluar individualmente
                $resultadoOponente = 0;
                $sqlOponente = "SELECT resultado1 FROM partiresul 
                               WHERE id_torneo = ? AND partida = ? AND mesa = ?
                               AND secuencia IN (" . ($esParejaA ? "3,4" : "1,2") . ")
                               LIMIT 1";
                $stmtOponente = $pdo->prepare($sqlOponente);
                $stmtOponente->execute([$torneo_id, $partida, $mesa]);
                $oponenteData = $stmtOponente->fetch(PDO::FETCH_ASSOC);
                if ($oponenteData) {
                    $resultadoOponente = (int)($oponenteData['resultado1'] ?? 0);
                }
                
                // Evaluar sanción individualmente
                $evaluacionSancion = evaluarSancionIndividual($resultado1, $resultadoOponente, $sancion, $puntosTorneo);
                $gano = $evaluacionSancion['gano'];
            } else {
                $gano = ($resultado1 > $resultado2);
            }
        }
        
        $resumenParticipacion[] = [
            'partida' => $partida,
            'mesa' => $mesa,
            'compañero' => $compañero,
            'contrarios' => $contrarios,
            'resultado1' => $estaRegistrado ? (int)($partidaJugador['resultado1'] ?? 0) : null,
            'resultado2' => $estaRegistrado ? (int)($partidaJugador['resultado2'] ?? 0) : null,
            'efectividad' => $efectividadFila,
            'sancion' => $estaRegistrado ? (int)($partidaJugador['sancion'] ?? 0) : null,
            'gano' => $estaRegistrado ? $gano : null,
            'ff' => $estaRegistrado ? $hayForfait : false,
            'tarjeta' => $estaRegistrado ? (int)($partidaJugador['tarjeta'] ?? 0) : null,
            'registrado' => $estaRegistrado
        ];
    }
    
    // Calcular totales
    $totales = [
        'resultado1' => 0,
        'resultado2' => 0,
        'efectividad' => 0,
        'sancion' => 0,
        'ganados' => 0,
        'perdidos' => 0
    ];
    
    foreach ($resumenParticipacion as $partida) {
        // Solo sumar a totales si está registrado
        if (!empty($partida['registrado']) && $partida['registrado']) {
            $totales['resultado1'] += (int)($partida['resultado1'] ?? 0);
            $totales['resultado2'] += (int)($partida['resultado2'] ?? 0);
            $totales['efectividad'] += (int)($partida['efectividad'] ?? 0);
            $totales['sancion'] += (int)($partida['sancion'] ?? 0);
            
            if ($partida['gano']) {
                $totales['ganados']++;
            } else {
                $totales['perdidos']++;
            }
        }
    }
    
    // Detectar desde dónde viene para construir el enlace de retorno
    $from = $_GET['from'] ?? 'panel';
    $vieneDePosiciones = ($from === 'posiciones');
    
    // Construir URL de retorno según el origen
    $use_standalone = (basename($_SERVER['PHP_SELF'] ?? '') === 'admin_torneo.php');
    $base_url_retorno = $use_standalone ? 'admin_torneo.php' : 'index.php?page=torneo_gestion';
    $action_param = $use_standalone ? '?' : '&';
    
    $urlRetorno = $base_url_retorno . $action_param . 'action=panel&torneo_id=' . $torneo_id;
    
    if ($from === 'posiciones') {
        $urlRetorno = $base_url_retorno . $action_param . 'action=posiciones&torneo_id=' . $torneo_id;
    } elseif ($from === 'resultados_equipos_detallado') {
        $urlRetorno = $base_url_retorno . $action_param . 'action=resultados_equipos_detallado&torneo_id=' . $torneo_id;
    } elseif ($from === 'resultados_equipos_resumido') {
        $urlRetorno = $base_url_retorno . $action_param . 'action=resultados_equipos_resumido&torneo_id=' . $torneo_id;
    } elseif ($from === 'resultados_general') {
        $urlRetorno = $base_url_retorno . $action_param . 'action=resultados_general&torneo_id=' . $torneo_id;
    } elseif ($from === 'resultados_por_club') {
        $urlRetorno = $base_url_retorno . $action_param . 'action=resultados_por_club&torneo_id=' . $torneo_id;
    }
    
    return [
        'torneo' => $torneo,
        'inscrito' => $inscrito,
        'resumenParticipacion' => $resumenParticipacion,
        'totales' => $totales,
        'vieneDePosiciones' => $vieneDePosiciones,
        'from' => $from,
        'urlRetorno' => $urlRetorno,
        'es_modalidad_equipos' => $esModalidadEquipos,
        'equipo' => $equipo,
    ];
}

/**
 * Obtiene datos para agregar mesa adicional.
 * Solo muestra jugadores NO asignados en esta ronda (excluye los que ya están en partiresul).
 */
function obtenerDatosAgregarMesa($torneo_id, $ronda) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener solo jugadores NO asignados en esta ronda (no están en partiresul para esta ronda)
    $sql = "SELECT i.id_usuario, u.nombre, u.nombre as nombre_completo
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN partiresul pr ON pr.id_torneo = i.torneo_id AND pr.id_usuario = i.id_usuario AND pr.partida = ?
            WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . "
              AND pr.id_usuario IS NULL
            ORDER BY u.nombre ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ronda, $torneo_id]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'torneo' => $torneo,
        'ronda' => $ronda,
        'jugadores' => $jugadores
    ];
}

/**
 * Verifica si una mesa existe
 */
function verificarMesaExiste($torneo_id, $ronda, $mesa) {
    $pdo = DB::pdo();
    
    $sql = "SELECT COUNT(*) as total
            FROM partiresul
            WHERE id_torneo = ? AND partida = ? AND mesa = ? AND mesa > 0";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return isset($resultado['total']) && (int)$resultado['total'] > 0;
}

/**
 * Obtiene lista de actas pendientes de verificación (origen QR, estatus pendiente_verificacion)
 */
function obtenerDatosVerificarActasLista($torneo_id) {
    $pdo = DB::pdo();
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('estatus', $cols)) {
        return ['actas_pendientes' => []];
    }
    $has_origen = in_array('origen_dato', $cols);
    $wherePv = PartiresulEstatusSql::wherePendienteVerificacionSinAlias($pdo);
    $sql = "
        SELECT DISTINCT partida, mesa
        FROM partiresul
        WHERE id_torneo = ? AND mesa > 0 AND {$wherePv}"
        . ($has_origen ? " AND origen_dato = 'qr'" : "") . "
        ORDER BY partida ASC, mesa ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    return ['actas_pendientes' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/**
 * Obtiene torneos con actas pendientes de verificación (QR) según el rol del usuario.
 * Usado por verificar_actas_index para listar torneos con mesas pendientes.
 *
 * @param int $user_id
 * @param bool $is_admin_general
 * @return array ['torneos' => array, 'total_actas_pendientes' => int]
 */
function obtenerTorneosConActasPendientes($user_id, $is_admin_general) {
    $pdo = DB::pdo();
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('estatus', $cols)) {
        return ['torneos' => [], 'total_actas_pendientes' => 0];
    }
    $has_origen = in_array('origen_dato', $cols);
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_t = !empty($tournament_filter['where']) ? "AND " . $tournament_filter['where'] : "";
    $params = $tournament_filter['params'];

    $extra_where = $has_origen ? " AND pr.origen_dato = 'qr'" : "";
    $wherePrPv = PartiresulEstatusSql::qualifiedWherePendienteVerificacion($pdo, 'pr');
    $orgJoin = torneoOrgJoinExpr('t', 'o');
    $sql = "
        SELECT t.id, t.nombre, t.fechator, t.club_responsable,
               o.nombre as organizacion_nombre,
               COUNT(DISTINCT CONCAT(pr.partida, '-', pr.mesa)) as actas_pendientes
        FROM partiresul pr
        INNER JOIN tournaments t ON pr.id_torneo = t.id
        {$orgJoin}
        WHERE pr.mesa > 0 AND {$wherePrPv} $extra_where
        AND t.estatus = 1
        $where_t
        GROUP BY t.id, t.nombre, t.fechator, t.club_responsable, o.nombre
        HAVING actas_pendientes > 0
        ORDER BY t.fechator DESC, t.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($torneos, 'actas_pendientes'));
    return ['torneos' => $torneos, 'total_actas_pendientes' => (int)$total];
}

/**
 * Obtiene datos de una acta específica para verificación
 */
function obtenerDatosVerificarActa($torneo_id, $ronda, $mesa) {
    $pdo = DB::pdo();
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_estatus = in_array('estatus', $cols);
    $sql = "
        SELECT pr.id, pr.id_usuario, pr.secuencia, pr.resultado1, pr.resultado2, pr.efectividad,
               pr.ff, pr.tarjeta, pr.sancion, pr.foto_acta, pr.estatus, pr.origen_dato,
               u.nombre as nombre_completo
        FROM partiresul pr
        INNER JOIN usuarios u ON (
            u.id = pr.id_usuario
            OR (
                u.numfvd = pr.id_usuario
                AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                AND EXISTS (
                    SELECT 1 FROM tournaments tx
                    WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                )
            )
        )
        WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
        ORDER BY pr.secuencia ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($jugadores) !== 4) {
        return null;
    }
    if ($has_estatus) {
        $estatus_primero = $jugadores[0]['estatus'] ?? '';
        if (!PartiresulEstatusSql::valueIsPendienteVerificacion($estatus_primero, $pdo)) {
            return null; // Ya verificada
        }
    }
    return ['jugadores' => $jugadores];
}

/**
 * Aprueba una acta QR: marca estatus=confirmado, actualiza puntos si se corrigieron, recalcula rankings
 */
function verificarActaAprobar($user_id, $is_admin_general) {
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $ronda = (int)($_POST['ronda'] ?? 0);
    $mesa = (int)($_POST['mesa'] ?? 0);
    $jugadores_raw = $_POST['jugadores'] ?? [];
    if ($torneo_id <= 0 || $ronda <= 0 || $mesa <= 0) {
        $_SESSION['error'] = 'Parámetros inválidos.';
        header('Location: ' . buildRedirectUrl('verificar_actas', ['torneo_id' => $torneo_id]));
        exit;
    }
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $torneo_finalizado = isTorneoLocked($torneo_id);
    if ($torneo_finalizado && !$is_admin_general) {
        $_SESSION['error'] = 'No puede aprobar actas en un torneo finalizado. Solo el administrador general puede realizar correcciones.';
        header('Location: ' . buildRedirectUrl('verificar_resultados', ['torneo_id' => $torneo_id]));
        exit;
    }
    require_once __DIR__ . '/../lib/SancionesHelper.php';
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_estatus = in_array('estatus', $cols);
    if (!$has_estatus) {
        $_SESSION['error'] = 'La tabla partiresul no tiene la columna estatus.';
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
        exit;
    }
    $stmt = $pdo->prepare("SELECT puntos FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $puntosTorneo = (int)($stmt->fetchColumn() ?: 200);
    $stmt = $pdo->prepare("SELECT pr.id, pr.id_usuario, pr.secuencia, pr.resultado1, pr.resultado2, pr.sancion FROM partiresul pr WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? ORDER BY pr.secuencia");
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids_usuarios = array_column($rows, 'id_usuario');
    $tarjeta_previa = SancionesHelper::getTarjetaPreviaDesdePartidasAnteriores($pdo, $torneo_id, $ronda, $ids_usuarios);
    $validarPuntos = fn($p, $pt) => min($p, (int)round($pt * 1.6));
    $efAlcanzo = fn($r1, $r2, $pt) => $r1 == $r2 ? 0 : ($r1 > $r2 ? $pt - $r2 : -($pt - $r1));
    $efNoAlcanzo = fn($r1, $r2) => $r1 == $r2 ? 0 : ($r1 > $r2 ? $r1 - $r2 : -($r2 - $r1));
    $calcularEf = function ($r1, $r2, $pt, $ff, $tarjeta) use ($validarPuntos, $efAlcanzo, $efNoAlcanzo) {
        $r1 = $validarPuntos($r1, $pt);
        $r2 = $validarPuntos($r2, $pt);
        if ($ff == 1) return -$pt;
        if (in_array($tarjeta, [3, 4])) return -$pt;
        $mayor = max($r1, $r2);
        return $mayor >= $pt ? $efAlcanzo($r1, $r2, $pt) : $efNoAlcanzo($r1, $r2);
    };
    try {
        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $partiresul_id = (int)$row['id'];
            $j = $jugadores_raw[$partiresul_id] ?? [];
            $resultado1 = TorneoCampoNumerico::intEstadistica($j['resultado1'] ?? $row['resultado1'] ?? 0);
            $resultado2 = TorneoCampoNumerico::intEstadistica($j['resultado2'] ?? $row['resultado2'] ?? 0);
            $sancion_input = (int)($j['sancion'] ?? 0);
            $tarjeta_inscritos = (int)($tarjeta_previa[(int)$row['id_usuario']] ?? 0);
            $procesado = SancionesHelper::procesar($sancion_input, 0, $tarjeta_inscritos);
            $tarjeta = $procesado['tarjeta'];
            $sancion_guardar = $procesado['sancion_guardar'];
            $sancion_calc = $procesado['sancion_para_calculo'];
            $resultado1_ajust = max(0, $resultado1 - $sancion_calc);
            $efectividad = $calcularEf($resultado1_ajust, $resultado2, $puntosTorneo, 0, $tarjeta);
            $setEstatus = PartiresulEstatusSql::setEstatusConfirmadoFragment($pdo);
            $pdo->prepare("
                UPDATE partiresul SET resultado1 = ?, resultado2 = ?, efectividad = ?, tarjeta = ?, sancion = ?, {$setEstatus}
                WHERE id = ?
            ")->execute([$resultado1, $resultado2, $efectividad, $tarjeta, $sancion_guardar, $partiresul_id]);
        }
        $pdo->commit();
        actualizarEstadisticasInscritos($torneo_id);
        if (! class_exists(\RankingTorneoRecalc::class, false)) {
            require_once __DIR__ . '/../lib/RankingTorneoRecalc.php';
        }
        \RankingTorneoRecalc::reclasificarSiUltimaRondaTorneoCompleta($torneo_id);
        try {
            enviarNotificacionesResultadosAprobados($pdo, $torneo_id, $ronda, $mesa);
        } catch (Exception $e) {
            error_log("Error al enviar notificaciones de acta aprobada: " . $e->getMessage());
        }
        $_SESSION['success'] = 'Acta aprobada y rankings actualizados. Notificaciones enviadas a los jugadores.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error al aprobar: ' . $e->getMessage();
    }
    $redirect_action = (!empty($_POST['redirect_action']) && $_POST['redirect_action'] === 'verificar_resultados') ? 'verificar_resultados' : 'verificar_actas';
    header('Location: ' . buildRedirectUrl($redirect_action, ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Rechaza una acta QR: limpia resultados y foto, pone estatus para re-escaneo
 */
function verificarActaRechazar($user_id, $is_admin_general) {
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $ronda = (int)($_POST['ronda'] ?? 0);
    $mesa = (int)($_POST['mesa'] ?? 0);
    if ($torneo_id <= 0 || $ronda <= 0 || $mesa <= 0) {
        $_SESSION['error'] = 'Parámetros inválidos.';
        header('Location: ' . buildRedirectUrl('verificar_actas', ['torneo_id' => $torneo_id]));
        exit;
    }
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $torneo_finalizado = isTorneoLocked($torneo_id);
    if ($torneo_finalizado && !$is_admin_general) {
        $_SESSION['error'] = 'No puede rechazar actas en un torneo finalizado. Solo el administrador general puede realizar correcciones.';
        $redirect_action = (!empty($_POST['redirect_action']) && $_POST['redirect_action'] === 'verificar_resultados') ? 'verificar_resultados' : 'verificar_actas';
        header('Location: ' . buildRedirectUrl($redirect_action, ['torneo_id' => $torneo_id]));
        exit;
    }
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_estatus = in_array('estatus', $cols);
    $has_foto = in_array('foto_acta', $cols);
    try {
        $updates = ["registrado = 0", "resultado1 = 0", "resultado2 = 0", "efectividad = 0", "ff = 0", "tarjeta = 0", "sancion = 0"];
        if ($has_estatus) {
            $updates[] = PartiresulEstatusSql::setEstatusPendienteVerificacionFragment($pdo);
        }
        if ($has_foto) $updates[] = "foto_acta = NULL";
        $pdo->prepare("UPDATE partiresul SET " . implode(', ', $updates) . " WHERE id_torneo = ? AND partida = ? AND mesa = ?")
            ->execute([$torneo_id, $ronda, $mesa]);
        actualizarEstadisticasInscritos($torneo_id);
        $_SESSION['success'] = 'Acta rechazada. El jugador puede volver a escanear y enviar el acta.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al rechazar: ' . $e->getMessage();
    }
    $redirect_action = (!empty($_POST['redirect_action']) && $_POST['redirect_action'] === 'verificar_resultados') ? 'verificar_resultados' : 'verificar_actas';
    header('Location: ' . buildRedirectUrl($redirect_action, ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Genera una nueva ronda
 */
function generarRonda($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        \Tournament\Handlers\RoundManagerHandler::ejecutarGeneracionRonda((int) $torneo_id);
    } catch (Exception $e) {
        error_log('Error al generar ronda: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $_SESSION['error'] = 'Error al generar ronda: ' . $e->getMessage();
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
        exit;
    }
}

/**
 * Elimina la última ronda generada.
 * - Sin resultados en mesas: elimina con la confirmación normal del panel.
 * - Con resultados en mesas: exige confirmación estricta (escribir ELIMINAR) por seguridad.
 */
function eliminarUltimaRonda($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        require_once __DIR__ . '/../lib/Core/TorneoMesaAsignacionResolver.php';

        $pdo = DB::pdo();
        $stmtM = $pdo->prepare('SELECT modalidad FROM tournaments WHERE id = ?');
        $stmtM->execute([$torneo_id]);
        $modalidad = (int)($stmtM->fetchColumn() ?? 0);

        $mesaService = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);
        $ultima_ronda = $mesaService->obtenerUltimaRonda($torneo_id);
        
        if ($ultima_ronda === 0) {
            $_SESSION['error'] = 'No hay rondas generadas para eliminar';
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
            exit;
        }
        
        $tiene_resultados_mesas = TorneoMesaAsignacionResolver::rondaTieneResultadosEnMesas($torneo_id, $ultima_ronda);
        if ($tiene_resultados_mesas) {
            $confirmacion = trim((string)($_POST['confirmar_eliminar_con_resultados'] ?? ''));
            if ($confirmacion !== 'ELIMINAR') {
                $_SESSION['error'] = 'La ronda ' . $ultima_ronda . ' tiene resultados de mesas registrados. Para eliminarla debe confirmar de forma segura (escribir ELIMINAR en el cuadro de confirmación).';
                header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
                exit;
            }
        }
        
        $eliminada = TorneoMesaAsignacionResolver::eliminarRonda($torneo_id, $ultima_ronda, $modalidad);
        
        if ($eliminada) {
            $_SESSION['success'] = "Ronda {$ultima_ronda} eliminada exitosamente";
        } else {
            $_SESSION['error'] = "Error al eliminar la ronda {$ultima_ronda}";
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al eliminar ronda: ' . $e->getMessage();
    }
    
    header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Guarda resultados de una mesa (delegado en TournamentActionHandler).
 */
function guardarResultados($user_id, $is_admin_general) {
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $ronda = (int)($_POST['ronda'] ?? 0);
    $mesa = (int)($_POST['mesa'] ?? 0);
    $resultado = \Tournament\Handlers\TournamentActionHandler::guardarResultados(
        $torneo_id,
        $_POST,
        $user_id,
        $is_admin_general
    );
    if (!empty($resultado['session_error'])) {
        $_SESSION['error'] = $resultado['session_error'];
    }
    if (!empty($resultado['session_info'])) {
        $_SESSION['info'] = $resultado['session_info'];
    }
    if (!empty($resultado['limpiar_formulario'])) {
        $_SESSION['limpiar_formulario'] = true;
    }
    if (!empty($resultado['resultados_guardados'])) {
        $_SESSION['resultados_guardados'] = true;
    }
    if (!empty($resultado['success'])) {
        $_SESSION['success'] = $resultado['success'];
    }
    $redirect = $resultado['redirect_url'] ?? '';
    if ($redirect !== '') {
        header('Location: ' . $redirect);
        exit;
    }
    $fallback = buildRedirectUrl('registrar_resultados', ['torneo_id' => $torneo_id, 'ronda' => $ronda, 'mesa' => $mesa]) . '#formResultados';
    header('Location: ' . $fallback);
    exit;
}


require_once __DIR__ . '/../lib/partiresul_efectividad_funcs.php';

/**
 * Guarda una mesa adicional
 */
function guardarMesaAdicional($torneo_id, $ronda, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        
        // Verificar que haya al menos 4 jugadores disponibles (no asignados en esta ronda)
        $pdo = DB::pdo();
        $sql = "SELECT COUNT(*) as total FROM inscritos i
                LEFT JOIN partiresul pr ON pr.id_torneo = i.torneo_id AND pr.id_usuario = i.id_usuario AND pr.partida = ?
                WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . " AND pr.id_usuario IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ronda, $torneo_id]);
        $disponibles = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($disponibles < 4) {
            $_SESSION['error'] = 'No hay jugadores disponibles.';
            header('Location: ' . buildRedirectUrl('agregar_mesa', ['torneo_id' => $torneo_id, 'ronda' => $ronda]));
            exit;
        }
        
        $jugadores_ids = $_POST['jugadores'] ?? [];
        
        if (empty($jugadores_ids) || !is_array($jugadores_ids) || count($jugadores_ids) != 4) {
            throw new Exception('Debe seleccionar exactamente 4 jugadores');
        }
        
        // Verificar que los jugadores sean diferentes
        if (count($jugadores_ids) !== count(array_unique($jugadores_ids))) {
            throw new Exception('Los jugadores deben ser diferentes');
        }
        
        $pdo->beginTransaction();
        
        // Obtener el siguiente número de mesa
        $stmt = $pdo->prepare("SELECT MAX(mesa) as max_mesa 
                               FROM partiresul 
                               WHERE id_torneo = ? AND partida = ? AND mesa > 0");
        $stmt->execute([$torneo_id, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nuevaMesa = ((int)($result['max_mesa'] ?? 0)) + 1;
        
        // Verificar que los jugadores estén inscritos y no estén ya asignados en esta ronda
        $stmt = $pdo->prepare("SELECT id_usuario FROM inscritos 
                               WHERE torneo_id = ? AND id_usuario = ? AND " . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO);
        $stmt2 = $pdo->prepare("SELECT COUNT(*) as existe 
                                FROM partiresul 
                                WHERE id_torneo = ? AND partida = ? AND id_usuario = ? AND mesa > 0");
        
        foreach ($jugadores_ids as $jugador_id) {
            $stmt->execute([$torneo_id, $jugador_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Uno de los jugadores seleccionados no está inscrito o no está disponible');
            }
            
            $stmt2->execute([$torneo_id, $ronda, $jugador_id]);
            $existe = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($existe && $existe['existe'] > 0) {
                throw new Exception('Uno de los jugadores seleccionados ya está asignado en esta ronda');
            }
        }
        
        // Insertar los jugadores en la nueva mesa
        $stmt = $pdo->prepare("INSERT INTO partiresul 
                               (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado)
                               VALUES (?, ?, ?, ?, ?, NOW(), 0)");
        
        foreach ($jugadores_ids as $index => $jugador_id) {
            $stmt->execute([
                $torneo_id,
                $jugador_id,
                $ronda,
                $nuevaMesa,
                $index + 1
            ]);
        }
        
        // Registrar parejas en historial_parejas (id_menor-id_mayor) para evitar que vuelvan a jugar juntos
        try {
            $stmtH = $pdo->prepare(
                "INSERT IGNORE INTO historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id, llave) VALUES (?, ?, ?, ?, ?)"
            );
            $a = (int)$jugadores_ids[0];
            $c = (int)$jugadores_ids[1];
            $b = (int)$jugadores_ids[2];
            $d = (int)$jugadores_ids[3];
            if ($a > 0 && $c > 0) {
                $idMenor = min($a, $c);
                $idMayor = max($a, $c);
                $stmtH->execute([$torneo_id, $ronda, $idMenor, $idMayor, $idMenor . '-' . $idMayor]);
            }
            if ($b > 0 && $d > 0) {
                $idMenor = min($b, $d);
                $idMayor = max($b, $d);
                $stmtH->execute([$torneo_id, $ronda, $idMenor, $idMayor, $idMenor . '-' . $idMayor]);
            }
        } catch (Exception $e) {
            try {
                $stmtH = $pdo->prepare(
                    "INSERT IGNORE INTO historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id) VALUES (?, ?, ?, ?)"
                );
                $a = (int)$jugadores_ids[0];
                $c = (int)$jugadores_ids[1];
                $b = (int)$jugadores_ids[2];
                $d = (int)$jugadores_ids[3];
                if ($a > 0 && $c > 0) $stmtH->execute([$torneo_id, $ronda, min($a, $c), max($a, $c)]);
                if ($b > 0 && $d > 0) $stmtH->execute([$torneo_id, $ronda, min($b, $d), max($b, $d)]);
            } catch (Exception $e2) {
                // Tabla puede no existir
            }
        }
        
        $pdo->commit();
        
        $_SESSION['success'] = "Mesa adicional {$nuevaMesa} creada exitosamente. Los jugadores han sido asignados y aparecen en la cuadrícula.";
        
        // Redirigir a cuadrícula para que el usuario vea la reconstrucción con los nuevos jugadores
        header('Location: ' . buildRedirectUrl('cuadricula', ['torneo_id' => $torneo_id, 'ronda' => $ronda]));
        exit;
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error al crear mesa adicional: ' . $e->getMessage();
    }
    
    header('Location: ' . buildRedirectUrl('agregar_mesa', ['torneo_id' => $torneo_id, 'ronda' => $ronda]));
    exit;
}

/**
 * Actualiza estadísticas manualmente
 */
function actualizarEstadisticasManual($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        
        actualizarEstadisticasInscritos($torneo_id, true);
        $_SESSION['success'] = 'Estadísticas y clasificación actualizadas exitosamente';
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al actualizar estadísticas: ' . $e->getMessage();
    }
    
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
        exit;
}

/**
 * Recalcular puntos y efectividad de todas las partidas BYE (mesa = 0) del torneo.
 * Asigna 100% de los puntos del torneo a resultado1 y 50% a efectividad. El torneo no puede tener 0 puntos.
 */
function recalcularBye($torneo_id, $user_id, $is_admin_general) {
    if ($torneo_id <= 0) {
        $_SESSION['error'] = 'Torneo no especificado.';
        header('Location: ' . buildRedirectUrl('index'));
        exit;
    }
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        $pdo = DB::pdo();

        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(puntos, 0), 200) AS puntos FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $puntosTorneo = ($row && isset($row['puntos'])) ? (int)$row['puntos'] : 200;
        if ($puntosTorneo <= 0) {
            $puntosTorneo = 200;
        }
        $efectividadBye = (int)round($puntosTorneo * 0.5); // 50% exacto de los puntos del torneo

        $stmt = $pdo->prepare("
            UPDATE partiresul
            SET resultado1 = ?, resultado2 = 0, efectividad = ?, registrado = 1
            WHERE id_torneo = ? AND mesa = 0
        ");
        $stmt->execute([$puntosTorneo, $efectividadBye, $torneo_id]);
        $actualizados = $stmt->rowCount();

        $stmt = $pdo->prepare("SELECT DISTINCT id_usuario FROM partiresul WHERE id_torneo = ? AND mesa = 0");
        $stmt->execute([$torneo_id]);
        while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)($fila['id_usuario'] ?? 0);
            if ($uid > 0) {
                InscritosPartiresulHelper::actualizarEstadisticas($uid, $torneo_id);
            }
        }

        $_SESSION['success'] = $actualizados > 0
            ? "BYE recalculados: $actualizados partida(s) actualizadas con $puntosTorneo puntos (100%) y efectividad $efectividadBye (50%)."
            : 'No hay partidas BYE en este torneo.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al recalcular BYE: ' . $e->getMessage();
    }
    header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Marca como retirados a los inscritos no presentes antes de generar la 3.ª ronda.
 * No presentes = estatus pendiente y que no tienen ninguna partida en partiresul (nunca participaron).
 * Se ejecuta solo cuando se va a generar la ronda 3; los listados y la siguiente generación ya no los incluyen.
 *
 * @param int $torneo_id
 * @return int Número de inscritos marcados como retirados
 */
function marcarNoPresentesRetiradosAntesRonda3($torneo_id) {
    $pdo = DB::pdo();
    // Compatible con columna estatus ENUM o INT: usar 'retirado' (enum) o 4 (int)
    $estatus_retirado = InscritosHelper::ESTATUS_RETIRADO; // 'retirado'
    $stmt = $pdo->prepare("
        UPDATE inscritos i
        SET i.estatus = ?
        WHERE i.torneo_id = ?
          AND CAST(i.estatus AS CHAR) IN ('0', 'pendiente')
          AND NOT EXISTS (
              SELECT 1 FROM partiresul pr
              WHERE pr.id_torneo = i.torneo_id AND pr.id_usuario = i.id_usuario
          )
    ");
    $stmt->execute([$estatus_retirado, $torneo_id]);
    return (int) $stmt->rowCount();
}

/**
 * Actualizar estadísticas de inscritos desde partiresul.
 *
 * BASE: tabla partiresul (única fuente de verdad para resultados de partidas).
 * LLAVE DE ACTUALIZACIÓN: (id_usuario, id_torneo). Se agregan los campos computables
 * por esa llave y se actualiza la tabla inscritos con esos totales.
 *
 * Tarjetas en inscritos: se guarda el valor de la ÚLTIMA tarjeta (partida más reciente).
 * Se consulta inscritos cuando hay sanción 80 pts o tarjeta directa: si inscritos.tarjeta = 0
 * →’ amarilla en formulario y partiresul; si inscritos.tarjeta > 0 →’ roja en formulario y partiresul.
 *
 * Norma: una fila por jugador por partida en partiresul; se eliminan duplicados
 * (mismo id_usuario, id_torneo, partida) y se agrega por (id_usuario, id_torneo).
 * Solo se consideran filas con registrado = 1 (mesas y BYE).
 */
function actualizarEstadisticasInscritos($torneo_id, bool $recalcularClasificacion = false) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT id FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    if (!$stmt->fetch()) {
        throw new Exception("Torneo no encontrado");
    }
    
    // Eliminar duplicados: una sola fila por (id_torneo, id_usuario, partida), conservar la de menor id
    $stmtDup = $pdo->prepare("
        SELECT pr.id FROM partiresul pr
        INNER JOIN (
            SELECT id_torneo, id_usuario, partida, MIN(id) AS keep_id
            FROM partiresul WHERE id_torneo = ?
            GROUP BY id_torneo, id_usuario, partida
            HAVING COUNT(*) > 1
        ) dup ON pr.id_torneo = dup.id_torneo AND pr.id_usuario = dup.id_usuario AND pr.partida = dup.partida AND pr.id != dup.keep_id
    ");
    $stmtDup->execute([$torneo_id]);
    $idsEliminar = $stmtDup->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($idsEliminar)) {
        $placeholders = implode(',', array_fill(0, count($idsEliminar), '?'));
        $pdo->prepare("DELETE FROM partiresul WHERE id IN ($placeholders)")->execute($idsEliminar);
    }
    
    // No se inicializa a 0 toda la tabla: solo se actualiza con totales desde partiresul y
    // se pone a 0 únicamente a los inscritos que no tienen partidas registradas.
    
    // 1) Actualizar inscritos que tienen partidas: sumas desde partiresul; tarjeta = valor de la ÚLTIMA tarjeta (por partida más reciente)
    // partiresul.resultado* u otras columnas pueden traer texto no numérico (p. ej. 'pendiente') en datos legacy/UI: forzar solo dígitos
    // para evitar SQLSTATE[22007]/1292 en modo estricto al comparar o agregar.
    $prR1 = "IF(CAST(resultado1 AS CHAR) REGEXP '^-?[0-9]+(\\.[0-9]+)?$', CAST(resultado1 AS DECIMAL(18,4)), 0)";
    $prR2 = "IF(CAST(resultado2 AS CHAR) REGEXP '^-?[0-9]+(\\.[0-9]+)?$', CAST(resultado2 AS DECIMAL(18,4)), 0)";
    $prInt = static function (string $col): string {
        return "IF(CAST({$col} AS CHAR) REGEXP '^-?[0-9]+$', CAST({$col} AS SIGNED), 0)";
    };
    $prDec = static function (string $col): string {
        return "IF(CAST({$col} AS CHAR) REGEXP '^-?[0-9]+(\\.[0-9]+)?$', CAST({$col} AS DECIMAL(18,4)), 0)";
    };
    $sqlUpdate = "
        UPDATE inscritos i
        INNER JOIN (
            SELECT
                id_usuario,
                id_torneo,
                SUM(ganado) AS ganados,
                SUM(perdido) AS perdidos,
                SUM(efectividad) AS efectividad,
                SUM(puntos) AS puntos,
                SUM(sancion) AS sancion,
                SUM(chancletas) AS chancletas,
                SUM(zapatos) AS zapatos,
                MAX(por_ronda.tarjeta_norm) AS tarjeta
            FROM (
                SELECT
                    id_usuario,
                    id_torneo,
                    partida,
                    MAX(CASE WHEN {$prR1} > {$prR2} THEN 1 ELSE 0 END) AS ganado,
                    MAX(CASE WHEN {$prR1} < {$prR2} THEN 1 ELSE 0 END) AS perdido,
                    MAX({$prDec('efectividad')}) AS efectividad,
                    MAX({$prR1}) AS puntos,
                    MAX({$prInt('sancion')}) AS sancion,
                    MAX({$prInt('chancleta')}) AS chancletas,
                    MAX({$prInt('zapato')}) AS zapatos,
                    MAX(CASE
                        WHEN {$prInt('tarjeta')} = 5 THEN 1
                        WHEN {$prInt('tarjeta')} = 6 THEN 3
                        WHEN {$prInt('tarjeta')} = 8 THEN 4
                        ELSE {$prInt('tarjeta')}
                    END) AS tarjeta_norm
                FROM partiresul
                WHERE id_torneo = ? AND " . PartiresulEstatusSql::whereRegistradoUno() . "
                GROUP BY id_usuario, id_torneo, partida
            ) por_ronda
            GROUP BY id_usuario, id_torneo
        ) agg ON i.id_usuario = agg.id_usuario AND i.torneo_id = agg.id_torneo
        SET
            i.ganados = agg.ganados,
            i.perdidos = agg.perdidos,
            i.efectividad = agg.efectividad,
            i.puntos = agg.puntos,
            i.sancion = agg.sancion,
            i.chancletas = agg.chancletas,
            i.zapatos = agg.zapatos,
            i.tarjeta = agg.tarjeta
        WHERE i.torneo_id = ?
    ";
    $pdo->prepare($sqlUpdate)->execute([$torneo_id, $torneo_id]);
    
    // 2) Poner a 0 solo los inscritos del torneo que no tienen ninguna partida registrada en partiresul
    $pdo->prepare("
        UPDATE inscritos i
        LEFT JOIN (
            SELECT DISTINCT id_usuario, id_torneo
            FROM partiresul
            WHERE id_torneo = ? AND " . PartiresulEstatusSql::whereRegistradoUno() . "
        ) has_data ON i.id_usuario = has_data.id_usuario AND i.torneo_id = has_data.id_torneo
        SET i.ganados = 0, i.perdidos = 0, i.efectividad = 0, i.puntos = 0,
            i.sancion = 0, i.chancletas = 0, i.zapatos = 0, i.tarjeta = 0
        WHERE i.torneo_id = ? AND has_data.id_usuario IS NULL
    ")->execute([$torneo_id, $torneo_id]);
    
    if ($recalcularClasificacion) {
        recalcularClasificacionEquiposYJugadores($torneo_id);
    }

    require_once __DIR__ . '/../lib/InscritosReporteStatsHelper.php';
    InscritosReporteStatsHelper::sincronizarGffYBye($pdo, (int) $torneo_id);
}

/**
 * Punto de entrada para recalcular estadísticas desde partiresul y puntos de ranking (ptosrnk) según modalidad.
 *
 * Regla general (tabla `clasiranking` + rendimiento; ver doc en lib/RankingTorneoRecalc.php):
 * - Hasta el límite de la tabla por tipo (30 individual, 20 parejas, 10 equipos): puntos por posición de tabla
 *   más partidas ganadas × tarifa de la fila más punto(s) de participación/asistencia.
 * - Por encima del límite: solo tarifa de partidas ganadas (fuera de tabla) + participación.
 * - En parejas/equipos, la componente de "posición en tabla" es la de la unidad (todos los integrantes);
 *   el rendimiento por partidas se aplica según modalidad (ver recalcularPosiciones).
 */
function recalcularRankingSegunModalidad($torneo_id) {
    $torneo_id = (int)$torneo_id;
    if ($torneo_id <= 0) {
        return;
    }
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT COALESCE(modalidad, 1) AS modalidad FROM tournaments WHERE id = ?');
    $stmt->execute([$torneo_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $modalidad = (int)($row['modalidad'] ?? 1);
    // Parejas (2), equipos (3) y parejas fijas (4): misma cadena (tabla equipos, clasiequi, ptosrnk por clasificación de unidad).
    if (in_array($modalidad, [2, 3, 4], true)) {
        recalcularClasificacionEquiposYJugadores($torneo_id);
    } else {
        recalcularPosiciones($torneo_id);
    }
}

/**
 * Recalcula toda la clasificación para torneos por unidad (parejas 2, equipos 3, parejas fijas 4):
 * 1) Actualiza estadísticas en tabla equipos, orden de equipos y sincroniza clasiequi en inscritos
 * 2) Recalcula posiciones y ptosrnk (puntos por posición según clasiequi de la unidad y ganados agregados del equipo)
 * 3) Numera 1..N dentro de cada código de equipo según clasificación individual
 */
function recalcularClasificacionEquiposYJugadores($torneo_id) {
    // Primero clasiequi: recalcularPosiciones usa clasiequi para ptosrnk en modalidades por unidad.
    actualizarEstadisticasEquipos($torneo_id);
    recalcularPosiciones($torneo_id);
    asignarNumeroSecuencialPorEquipo($torneo_id);
}

/**
 * Actualiza las estadísticas de equipos desde la tabla inscritos
 * Suma los valores de puntos, ganados, perdidos y calcula efectividad promedio
 * por codigo_equipo
 */
function actualizarEstadisticasEquipos($torneo_id) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT modalidad FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $torneo) {
        return;
    }
    $mod = (int) ($torneo['modalidad'] ?? 0);
    if (! in_array($mod, [2, 3, 4], true)) {
        return;
    }
    
    // Mismo universo que mesas (MesaAsignacionEquiposService: estatus != retirado): no solo confirmados,
    // para no perder clasificación/clasiequi si hay solventes/no_solventes en plantilla.
    $exP = InscritosHelper::sqlExprColumnaNumerica('puntos');
    $exG = InscritosHelper::sqlExprColumnaNumerica('ganados');
    $exPe = InscritosHelper::sqlExprColumnaNumerica('perdidos');
    $exE = InscritosHelper::sqlExprColumnaNumerica('efectividad');
    $exS = InscritosHelper::sqlExprColumnaNumerica('sancion');
    $sql = "SELECT 
                codigo_equipo,
                SUM($exP) as puntos_equipo,
                SUM($exG) as ganados_equipo,
                SUM($exPe) as perdidos_equipo,
                SUM($exE) as efectividad_equipo,
                SUM($exS) as sancion_equipo,
                COUNT(*) as total_jugadores
            FROM inscritos
            WHERE torneo_id = ? 
                AND codigo_equipo IS NOT NULL 
                AND codigo_equipo != ''
                AND " . InscritosHelper::SQL_WHERE_ACTIVO . "
            GROUP BY codigo_equipo";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $estadisticasEquipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($estadisticasEquipos)) {
        // No hay equipos con inscritos, no hay nada que actualizar
        return;
    }
    
    // Actualizar cada equipo con sus estadísticas agregadas
    $stmtUpdate = $pdo->prepare("
        UPDATE equipos 
        SET puntos = ?,
            ganados = ?,
            perdidos = ?,
            efectividad = ?,
            sancion = ?,
            fecha_actualizacion = CURRENT_TIMESTAMP
        WHERE id_torneo = ? AND codigo_equipo = ?
    ");
    
    foreach ($estadisticasEquipos as $stats) {
        $codigoEquipo = $stats['codigo_equipo'];
        $puntosEquipo = (int)($stats['puntos_equipo'] ?? 0);
        $ganadosEquipo = (int)($stats['ganados_equipo'] ?? 0);
        $perdidosEquipo = (int)($stats['perdidos_equipo'] ?? 0);
        $efectividadEquipo = (int)($stats['efectividad_equipo'] ?? 0); // Suma de efectividades de todos los jugadores
        $sancionEquipo = (int)($stats['sancion_equipo'] ?? 0);
        
        $stmtUpdate->execute([
            $puntosEquipo,
            $ganadosEquipo,
            $perdidosEquipo,
            $efectividadEquipo,
            $sancionEquipo,
            $torneo_id,
            $codigoEquipo
        ]);
    }
    
    // Recalcular posiciones de equipos después de actualizar estadísticas
    recalcularPosicionesEquipos($torneo_id);
}

/**
 * Recalcular posiciones de equipos según sus estadísticas.
 * Debe coincidir con {@see MesaAsignacionEquiposService::obtenerEquiposConJugadoresYClasificacion}:
 * ganados DESC, efectividad DESC, puntos DESC, perdidos ASC, código.
 */
function recalcularPosicionesEquipos($torneo_id) {
    $pdo = DB::pdo();
    // COALESCE(ganados,0) no basta si la columna tiene texto ('pendiente'): COALESCE devuelve el texto y ORDER BY en modo estricto dispara 1292.
    $og = InscritosHelper::sqlExprColumnaNumerica('ganados');
    $oe = InscritosHelper::sqlExprColumnaNumerica('efectividad');
    $op = InscritosHelper::sqlExprColumnaNumerica('puntos');
    $ope = InscritosHelper::sqlExprColumnaNumerica('perdidos');
    $sql = "SELECT codigo_equipo, puntos, ganados, perdidos, efectividad
            FROM equipos
            WHERE id_torneo = ? AND estatus = 0
            ORDER BY $og DESC,
                     $oe DESC,
                     $op DESC,
                     $ope ASC,
                     codigo_equipo ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($equipos)) {
        return;
    }
    
    // Actualizar posiciones secuencialmente
    $stmtUpdate = $pdo->prepare("
        UPDATE equipos 
        SET posicion = ?
        WHERE id_torneo = ? AND codigo_equipo = ?
    ");
    $stmtUpdateInscritos = $pdo->prepare("
        UPDATE inscritos i
        SET i.clasiequi = ?
        WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND " . InscritosHelper::sqlWhereActivoConAlias('i') . "
    ");
    
    $posicion = 1;
    foreach ($equipos as $equipo) {
        $stmtUpdate->execute([
            $posicion,
            $torneo_id,
            $equipo['codigo_equipo']
        ]);
        
        // Sincronizar campo clasiequi en inscritos con la clasificación del equipo
        $stmtUpdateInscritos->execute([
            $posicion,
            $torneo_id,
            $equipo['codigo_equipo']
        ]);
        $posicion++;
    }
}

/**
 * Asigna numero 1..4 dentro de cada equipo según clasificación individual.
 * Debe coincidir con {@see MesaAsignacionEquiposService::obtenerJugadoresEquipoConClasificacion}:
 * ganados DESC, efectividad DESC, puntos DESC, perdidos ASC, id_usuario.
 */
function asignarNumeroSecuencialPorEquipo($torneo_id) {
    $pdo = DB::pdo();
    $stmtEquipos = $pdo->prepare("
        SELECT DISTINCT i.codigo_equipo
        FROM inscritos i
        WHERE i.torneo_id = ? AND i.codigo_equipo IS NOT NULL AND i.codigo_equipo != '' AND " . InscritosHelper::sqlWhereActivoConAlias('i') . "
    ");
    $stmtEquipos->execute([$torneo_id]);
    $codigos = $stmtEquipos->fetchAll(PDO::FETCH_COLUMN);

    $ordG = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
    $ordE = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
    $ordP = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
    $ordPe = InscritosHelper::sqlExprColumnaNumerica('i.perdidos');
    $stmtJugadores = $pdo->prepare("
        SELECT i.id
        FROM inscritos i
        WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND " . InscritosHelper::sqlWhereActivoConAlias('i') . "
        ORDER BY 
            $ordG DESC,
            $ordE DESC,
            $ordP DESC,
            $ordPe ASC,
            i.id_usuario ASC
    ");
    $stmtUpdateNumero = $pdo->prepare("UPDATE inscritos SET numero = ? WHERE id = ?");

    foreach ($codigos as $codigo) {
        $stmtJugadores->execute([$torneo_id, $codigo]);
        $jugadoresEquipo = $stmtJugadores->fetchAll(PDO::FETCH_ASSOC);

        $numeroSecuencial = 1;
        foreach ($jugadoresEquipo as $jug) {
            $stmtUpdateNumero->execute([$numeroSecuencial, $jug['id']]);
            $numeroSecuencial++;
        }
    }
}

/**
 * Asigna posición consecutiva en el torneo (orden: ganados, efectividad, puntos) y calcula `ptosrnk`:
 * puntos según fila de `clasiranking` para la clasificación aplicable + rendimiento + participación.
 *
 * Clasificación para la tabla: en modalidades por unidad (2,3,4) con `clasiequi` > 0 se usa la posición
 * del equipo/pareja; si no, la posición individual en el orden anterior.
 *
 * @see RankingTorneoRecalc Documentación de política de negocio
 */
function recalcularPosiciones($torneo_id) {
    try {
        require_once __DIR__ . '/../lib/ClasirankingRankingHelper.php';
        $pdo = DB::pdo();
        
        error_log("recalcularPosiciones: Iniciando para torneo_id = $torneo_id");
        
        // Obtener información del torneo para saber el tipo
        $stmt = $pdo->prepare("SELECT modalidad, nombre FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$torneo) {
            error_log("recalcularPosiciones: Torneo no encontrado");
            return;
        }
        
        // Modalidad del torneo (1 indiv., 2 parejas, 3 equipos, 4 parejas fijas, ...)
        $modalidadRaw = $torneo['modalidad'] ?? 1;
        $modalidadNum = 1;
        if (is_numeric($modalidadRaw)) {
            $modalidadNum = (int)$modalidadRaw;
        } else {
            $modalidad_str = strtolower(trim((string)$modalidadRaw));
            if (stripos($modalidad_str, 'pareja') !== false) {
                $modalidadNum = 2;
            } elseif (stripos($modalidad_str, 'equipo') !== false) {
                $modalidadNum = 3;
            }
        }
        // clasiranking solo define tipos 1=individual, 2=parejas, 3=equipos (parejas fijas = misma tabla que parejas)
        if (in_array($modalidadNum, [2, 4], true)) {
            $tipoTorneoClasi = 2;
        } elseif ($modalidadNum === 3) {
            $tipoTorneoClasi = 3;
        } else {
            $tipoTorneoClasi = 1;
        }
        $limitePosiciones = 30;
        if ($tipoTorneoClasi === 2) {
            $limitePosiciones = 20;
        } elseif ($tipoTorneoClasi === 3) {
            $limitePosiciones = 10;
        }
        
        error_log("recalcularPosiciones: modalidad=$modalidadNum tipoClasi=$tipoTorneoClasi limite=$limitePosiciones");
        
        // Primero, resetear todas las posiciones a 0 para evitar conflictos
        $stmt = $pdo->prepare("UPDATE inscritos SET posicion = 0 WHERE torneo_id = ?");
        $stmt->execute([$torneo_id]);
        $reseteados = $stmt->rowCount();
        error_log("recalcularPosiciones: Reseteados $reseteados registros");
        
        // Obtener inscritos ordenados por: 1. ganados DESC, 2. efectividad DESC, 3. puntos DESC
        // Filtro: excluir retirados
        $rg = InscritosHelper::sqlExprColumnaNumerica('ganados');
        $re = InscritosHelper::sqlExprColumnaNumerica('efectividad');
        $rp = InscritosHelper::sqlExprColumnaNumerica('puntos');
        $stmt = $pdo->prepare("SELECT id, id_usuario, codigo_equipo, clasiequi,
                               $rg as ganados, 
                               $re as efectividad, 
                               $rp as puntos
                               FROM inscritos 
                               WHERE torneo_id = ? AND " . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO . "
                               ORDER BY $rg DESC, 
                                        $re DESC, 
                                        $rp DESC");
        $stmt->execute([$torneo_id]);
        $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("recalcularPosiciones: Encontrados " . count($inscritos) . " inscritos");
        
        if (empty($inscritos)) {
            error_log("recalcularPosiciones: No hay inscritos para actualizar");
            return;
        }

        // Solo parejas (2) y parejas fijas (4): ptosrnk usa partidas ganadas agregadas de la unidad.
        // Modalidad equipos (3): puntos por posición según clasificación del equipo (clasiequi + tabla clasiranking);
        // partidas ganadas y participación por integrante.
        $ganadosEquipoPorCodigo = [];
        if (in_array($modalidadNum, [2, 4], true)) {
            $stmtEqG = $pdo->prepare(
                'SELECT codigo_equipo, ganados FROM equipos WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != \'\''
            );
            $stmtEqG->execute([$torneo_id]);
            while ($rowEq = $stmtEqG->fetch(PDO::FETCH_ASSOC)) {
                $cod = trim((string) ($rowEq['codigo_equipo'] ?? ''));
                if ($cod !== '') {
                    $ganadosEquipoPorCodigo[$cod] = (int) ($rowEq['ganados'] ?? 0);
                }
            }
        }

        $existeClasiRanking = false;
        try {
            $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $stmtCk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clasiranking' LIMIT 1");
                $existeClasiRanking = ($stmtCk && $stmtCk->fetch() !== false);
            } else {
                $stmtCk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'clasiranking' LIMIT 1");
                $existeClasiRanking = ($stmtCk && $stmtCk->fetch() !== false);
            }
        } catch (Exception $e) {
            $existeClasiRanking = false;
        }

        // Fuera del top N de la tabla: mismos criterios que la última fila definida dentro del límite; si no hay datos, 0 + asistencia mínima.
        $puntosPorPartidaGanadaFuera = null;
        $puntosAsistenciaFuera = 1;
        if ($existeClasiRanking && $limitePosiciones >= 1) {
            try {
                $stmtF = $pdo->prepare("SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia
                    FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1");
                $stmtF->execute([$tipoTorneoClasi, $limitePosiciones]);
                $rf = $stmtF->fetch(PDO::FETCH_ASSOC);
                if ($rf) {
                    $puntosPorPartidaGanadaFuera = (int)($rf['puntos_por_partida_ganada'] ?? 0);
                    $puntosAsistenciaFuera = (int)($rf['puntos_asistencia'] ?? 1);
                }
            } catch (Exception $e) {
                try {
                    $stmtF = $pdo->prepare("SELECT puntos_por_partida_ganada FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1");
                    $stmtF->execute([$tipoTorneoClasi, $limitePosiciones]);
                    $rf = $stmtF->fetch(PDO::FETCH_ASSOC);
                    if ($rf) {
                        $puntosPorPartidaGanadaFuera = (int)($rf['puntos_por_partida_ganada'] ?? 0);
                    }
                } catch (Exception $e2) {
                }
            }
        }
        if ($existeClasiRanking && $puntosPorPartidaGanadaFuera === null) {
            try {
                $stmtF2 = $pdo->prepare("SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia
                    FROM clasiranking WHERE tipo_torneo = ? ORDER BY clasificacion DESC LIMIT 1");
                $stmtF2->execute([$tipoTorneoClasi]);
                $rf2 = $stmtF2->fetch(PDO::FETCH_ASSOC);
                if ($rf2) {
                    $puntosPorPartidaGanadaFuera = (int) ($rf2['puntos_por_partida_ganada'] ?? 0);
                    $puntosAsistenciaFuera = (int) ($rf2['puntos_asistencia'] ?? 1);
                }
            } catch (Exception $e) {
            }
        }
        $pppFuera = (int) ($puntosPorPartidaGanadaFuera ?? 0);
        $pasFuera = max(1, (int) ($puntosAsistenciaFuera ?? 1));
        
        // Actualizar posiciones consecutivamente (1, 2, 3, 4...) y calcular puntos de ranking
        // Cada jugador recibe una posición única, incluso si hay empates en los valores
        $posicion = 1;
        $actualizados = 0;
        $puntosRankingActualizados = 0;
        $rankingPorClasificacion = [];
        
        foreach ($inscritos as $inscrito) {
            $id = (int)$inscrito['id'];
            $ganados = (int)($inscrito['ganados'] ?? 0);
            $codEq = trim((string)($inscrito['codigo_equipo'] ?? ''));
            if (in_array($modalidadNum, [2, 4], true) && $codEq !== '' && array_key_exists($codEq, $ganadosEquipoPorCodigo)) {
                $ganados = $ganadosEquipoPorCodigo[$codEq];
            }
            $clasiequi = (int)($inscrito['clasiequi'] ?? 0);
            
            // Puntos por posición (tabla clasiranking): parejas/parejas fijas y equipos usan clasificación de la unidad (clasiequi).
            $clasificacionRanking = $posicion;
            if (in_array($modalidadNum, [2, 3, 4], true) && $clasiequi > 0) {
                $clasificacionRanking = $clasiequi;
            }

            $ptosrnk = $pasFuera;
            if ($existeClasiRanking && $clasificacionRanking >= 1 && $clasificacionRanking <= $limitePosiciones) {
                if (!array_key_exists($clasificacionRanking, $rankingPorClasificacion)) {
                    $rankingPorClasificacion[$clasificacionRanking] = ClasirankingRankingHelper::obtenerFilaParaClasificacion(
                        $pdo,
                        $tipoTorneoClasi,
                        $clasificacionRanking,
                        $limitePosiciones
                    );
                }
                $ranking = $rankingPorClasificacion[$clasificacionRanking];
                if ($ranking) {
                    $ptosrnk = (int) $ranking['puntos_posicion'] + ($ganados * (int) $ranking['puntos_por_partida_ganada']) + (int) $ranking['puntos_asistencia'];
                } else {
                    $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
                }
            } elseif ($existeClasiRanking && $clasificacionRanking > $limitePosiciones) {
                $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
            } elseif ($existeClasiRanking) {
                $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
            }
            
            // Actualizar posición y puntos de ranking
            $stmt = $pdo->prepare("UPDATE inscritos SET posicion = ?, ptosrnk = ? WHERE id = ?");
            $result = $stmt->execute([$posicion, $ptosrnk, $id]);
            if ($result) {
                $actualizados++;
                $puntosRankingActualizados++;
            } else {
                error_log("recalcularPosiciones: Error al actualizar posición para inscrito id=$id");
            }
            $posicion++;
        }

        try {
            $pdo->prepare("UPDATE inscritos SET ptosrnk = 0 WHERE torneo_id = ? AND (estatus = 4 OR estatus = 'retirado')")->execute([$torneo_id]);
        } catch (Exception $e) {
        }
        
        error_log("recalcularPosiciones: Actualizadas $actualizados posiciones y $puntosRankingActualizados puntos de ranking");
        
        // Verificar que no hay duplicados
        $stmt = $pdo->prepare("SELECT posicion, COUNT(*) as cantidad 
                               FROM inscritos 
                               WHERE torneo_id = ? AND posicion > 0
                               GROUP BY posicion 
                               HAVING cantidad > 1");
        $stmt->execute([$torneo_id]);
        $duplicados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($duplicados)) {
            error_log("ADVERTENCIA: Se encontraron posiciones duplicadas en el torneo $torneo_id: " . json_encode($duplicados));
        } else {
            error_log("recalcularPosiciones: No se encontraron posiciones duplicadas");
        }
        
    } catch (Exception $e) {
        error_log("ERROR en recalcularPosiciones: " . $e->getMessage());
        error_log("ERROR stack trace: " . $e->getTraceAsString());
        throw $e;
    }
}

/**
 * Obtiene datos para mostrar formulario de reasignación de mesa
 */
function obtenerDatosReasignarMesa($torneo_id, $ronda, $mesa) {
    $pdo = DB::pdo();
    
    // Obtener jugadores de la mesa actual
    $stmt = $pdo->prepare("SELECT 
                pr.*,
                u.nombre as nombre_completo,
                i.posicion,
                i.ganados,
                i.perdidos,
                i.efectividad
            FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
            ORDER BY pr.secuencia ASC");
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener todas las mesas de la ronda
    $stmt = $pdo->prepare("SELECT DISTINCT 
                CAST(pr.mesa AS UNSIGNED) as numero,
                MAX(pr.registrado) as registrado,
                COUNT(DISTINCT pr.id_usuario) as total_jugadores
            FROM partiresul pr
            WHERE pr.id_torneo = ? 
              AND pr.partida = ? 
              AND pr.mesa IS NOT NULL 
              AND pr.mesa > 0 
              AND CAST(pr.mesa AS UNSIGNED) > 0
            GROUP BY CAST(pr.mesa AS UNSIGNED)
            ORDER BY CAST(pr.mesa AS UNSIGNED) ASC");
    $stmt->execute([$torneo_id, $ronda]);
    $todasLasMesas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convertir numero a entero y ordenar
    foreach ($todasLasMesas as &$m) {
        $m['numero'] = (int)$m['numero'];
        $m['tiene_resultados'] = $m['registrado'];
    }
    usort($todasLasMesas, function($a, $b) {
        return $a['numero'] <=> $b['numero'];
    });
    
    // Obtener todas las rondas del torneo
    $stmt = $pdo->prepare("SELECT DISTINCT partida as ronda 
                          FROM partiresul 
                          WHERE id_torneo = ? AND partida > 0 
                          ORDER BY partida ASC");
    $stmt->execute([$torneo_id]);
    $todasLasRondas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Determinar mesa anterior y siguiente
    $mesaAnterior = null;
    $mesaSiguiente = null;
    foreach ($todasLasMesas as $index => $m) {
        if ($m['numero'] == $mesa) {
            if ($index > 0) {
                $mesaAnterior = $todasLasMesas[$index - 1]['numero'];
            }
            if ($index < count($todasLasMesas) - 1) {
                $mesaSiguiente = $todasLasMesas[$index + 1]['numero'];
            }
            break;
        }
    }
    
    return [
        'jugadores' => $jugadores,
        'todasLasMesas' => $todasLasMesas,
        'todasLasRondas' => $todasLasRondas,
        'mesaAnterior' => $mesaAnterior,
        'mesaSiguiente' => $mesaSiguiente,
        'mesaActual' => (int) $mesa,
        'ronda' => (int) $ronda,
    ];
}

/**
 * Ejecuta la reasignación de una mesa
 */
function ejecutarReasignacion($torneo_id, $ronda, $mesa, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        
        // Verificar CSRF
        $csrf_token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';
        if (!$csrf_token || !$session_token || !hash_equals($session_token, $csrf_token)) {
            throw new Exception('Token de seguridad inválido. Por favor, recarga la página e intenta nuevamente.');
        }
        
        $opcion = (int)($_POST['opcion_reasignacion'] ?? 0);
        
        if (!in_array($opcion, [1, 2, 3, 4], true)) {
            throw new Exception('Opción de reasignación no válida');
        }
        
        $pdo = DB::pdo();
        $stmtTorneo = $pdo->prepare("SELECT modalidad FROM tournaments WHERE id = ? LIMIT 1");
        $stmtTorneo->execute([$torneo_id]);
        $modalidad = (int)($stmtTorneo->fetchColumn() ?: 0);
        $esParejasFijas = ($modalidad === 4);
        if ($esParejasFijas) {
            throw new Exception('La reasignación de mesa no está disponible para modalidad Parejas Fijas.');
        }
        
        // Obtener jugadores actuales de la mesa
        $stmt = $pdo->prepare("SELECT * FROM partiresul 
                              WHERE id_torneo = ? AND partida = ? AND mesa = ?
                              ORDER BY secuencia ASC");
        $stmt->execute([$torneo_id, $ronda, $mesa]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($jugadores) != 4) {
            throw new Exception('La mesa debe tener exactamente 4 jugadores');
        }
        
        // Crear mapa de secuencias actuales (claves 1-4 como enteros)
        $mapaActual = [];
        foreach ($jugadores as $jugador) {
            $mapaActual[(int) $jugador['secuencia']] = $jugador;
        }
        
        // Definir cambios según la opción
        $cambios = [];
        // Un solo par [a,b] aplica un intercambio; no duplicar [b,a] (anula el efecto).
        switch ($opcion) {
            case 1: // 1 con 3
                $cambios = [[1, 3]];
                break;
            case 2: // 1 con 4
                $cambios = [[1, 4]];
                break;
            case 3: // 2 con 3
                $cambios = [[2, 3]];
                break;
            case 4: // 2 con 4
                $cambios = [[2, 4]];
                break;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Crear mapa de cambios finales
            $mapaFinal = [];
            foreach ($mapaActual as $seq => $jugador) {
                $mapaFinal[$seq] = $jugador['id_usuario'];
            }
            
            // Aplicar cambios
            foreach ($cambios as $cambio) {
                $secuenciaOrigen = $cambio[0];
                $secuenciaDestino = $cambio[1];
                $temp = $mapaFinal[$secuenciaOrigen];
                $mapaFinal[$secuenciaOrigen] = $mapaFinal[$secuenciaDestino];
                $mapaFinal[$secuenciaDestino] = $temp;
            }

            // id_fila => nueva secuencia (1–4). No actualizar secuencia “en directo”: el UNIQUE
            // (id_torneo, partida, mesa, secuencia) chocaría al pisar 1→4 mientras 4 sigue ocupado.
            $porIdNuevaSeq = [];
            foreach ($jugadores as $jugador) {
                $rowId = (int) $jugador['id'];
                $uid = (int) $jugador['id_usuario'];
                $nuevaSeq = null;
                foreach ($mapaFinal as $seq => $mapUid) {
                    if ((int) $mapUid === $uid) {
                        $nuevaSeq = (int) $seq;
                        break;
                    }
                }
                if ($nuevaSeq === null || $nuevaSeq < 1 || $nuevaSeq > 4) {
                    throw new Exception('No se pudo calcular la nueva secuencia para un jugador.');
                }
                $porIdNuevaSeq[$rowId] = $nuevaSeq;
            }
            if (count(array_unique($porIdNuevaSeq)) !== 4) {
                throw new Exception('Asignación de secuencias inconsistente.');
            }

            $stById = $pdo->prepare('UPDATE partiresul SET secuencia = ? WHERE id = ?');
            // Fase 1: sacar de 1–4 para liberar el UNIQUE (11–14 no se usan en mesas reales).
            $tempVals = [11, 12, 13, 14];
            $i = 0;
            foreach ($jugadores as $jugador) {
                $stById->execute([$tempVals[$i++], (int) $jugador['id']]);
            }
            // Fase 2: secuencias definitivas
            foreach ($porIdNuevaSeq as $rowId => $nuevaSeq) {
                $stById->execute([$nuevaSeq, $rowId]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = 'Mesa reasignada exitosamente. Los cambios se han aplicado correctamente.';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
        header('Location: ' . buildRedirectUrl('registrar_resultados', [
            'torneo_id' => $torneo_id, 
            'ronda' => $ronda, 
            'mesa' => $mesa
        ]));
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al reasignar la mesa: ' . $e->getMessage();
        error_log("Error en reasignación de mesa: " . $e->getMessage());
        header('Location: ' . buildRedirectUrl('reasignar_mesa', [
            'torneo_id' => $torneo_id, 
            'ronda' => $ronda, 
            'mesa' => $mesa
        ]));
        exit;
    }
}

