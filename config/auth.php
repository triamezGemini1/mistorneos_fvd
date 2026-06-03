<?php
if (!defined('APP_BOOTSTRAPPED')) { require __DIR__ . '/bootstrap.php'; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/FvdConfig.php';

class Auth {
  private static $has_cod_org_column = null;
  private static $role_aliases = [
    'admin gral' => 'admin_general',
    'admin_gral' => 'admin_general',
    'admingral' => 'admin_general',
    'admin general' => 'admin_general',
  ];

  private static function normalizeRole(?string $role): string {
    $value = trim((string)$role);
    if ($value === '') {
      return '';
    }
    $lower = strtolower($value);
    if (isset(self::$role_aliases[$lower])) {
      return self::$role_aliases[$lower];
    }
    return $lower;
  }

  private static function hasCodOrg(): bool {
    if (self::$has_cod_org_column !== null) {
      return self::$has_cod_org_column;
    }
    try {
      self::$has_cod_org_column = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      self::$has_cod_org_column = false;
    }
    return self::$has_cod_org_column;
  }

  public static function login(string $username, string $password): bool {
    require_once __DIR__ . '/../lib/security.php';
    
    $user = Security::authenticateUser($username, $password);
    if ($user) {
      $roleNormalized = self::normalizeRole($user['role'] ?? '');
      $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $roleNormalized,
        'role_original' => $roleNormalized,
        'email' => $user['email'],
        'uuid' => $user['uuid'],
        'photo_path' => $user['photo_path'],
        'club_id' => $user['club_id'],
        'entidad' => isset($user['entidad']) ? (int)$user['entidad'] : 0,
        'organizacion_id' => FvdConfig::ORGANIZACION_ID,
      ];
      FvdConfig::anchorSession();
      // No regenerar ID aquí: el navegador ya envió una cookie (session_id); si regeneramos,
      // la nueva cookie no llega a la siguiente petición en entornos con subcarpeta y se pierde la sesión.
      return true;
    }
    return false;
  }

  public static function logout(): void {
    // Limpiar toda la sesión primero
    $_SESSION = [];
    
    // Destruir la sesión actual
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_unset();
      session_destroy();
    }
    
    // Limpiar cookies de sesión si est�n habilitadas
    if (!headers_sent()) {
      $params = session_get_cookie_params();
      
      $name = session_name();
      $expire = time() - 42000;
      setcookie($name, '', $expire, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
      // Eliminar también cookie con path=/ por si la sesión se inició con session_start_early (index.php)
      if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
        setcookie($name, '', $expire, '/', $params["domain"], $params["secure"], $params["httponly"]);
      }
    }

    if (isset($_COOKIE)) {
      foreach (array_keys($_COOKIE) as $cname) {
        if ($cname === session_name() || strpos($cname, session_name()) === 0) {
          unset($_COOKIE[$cname]);
        }
      }
    }
  }

  /**
   * ID del usuario actual (único punto de acceso, evita inconsistencia user_id/id).
   * @return int 0 si no hay sesión.
   */
  public static function id(): int {
    $u = $_SESSION['user'] ?? null;
    if (!$u) {
      return 0;
    }
    return (int)($u['id'] ?? $u['user_id'] ?? 0);
  }

  public static function user(): ?array {
    $u = $_SESSION['user'] ?? null;
    if (!$u || !is_array($u)) {
      return $u;
    }
    FvdConfig::ensureSessionAnchorIfAuthenticated();
    $u['role'] = self::normalizeRole((string)($u['role'] ?? ''));
    if (!isset($u['role_original']) || $u['role_original'] === '') {
      $u['role_original'] = $u['role'] ?? '';
    } else {
      $u['role_original'] = self::normalizeRole((string)$u['role_original']);
    }
    // Switch de rol: solo permitido cuando el rol original es admin_general
    if (($u['role_original'] ?? '') === 'admin_general' && isset($_SESSION['role_switch_mode'])) {
      $mode = (int)$_SESSION['role_switch_mode'];
      $map = [0 => 'admin_general', 1 => 'admin_club', 2 => 'admin_torneo', 3 => 'operador', 4 => 'usuario'];
      if (isset($map[$mode])) {
        $u['role'] = $map[$mode];
        $u['role_switch_mode'] = $mode;
      }
    }
    if ($u !== null && !isset($u['id']) && isset($u['user_id'])) {
      $u['id'] = $u['user_id'];
      $_SESSION['user'] = $u;
    }
    $_SESSION['user'] = $u;
    return $u;
  }

  public static function requireRole(array $roles): void {
    $u = self::user();
    $ok = $u && in_array($u['role'], $roles, true);
    if (!$ok && $u && in_array('admin_general', $roles, true) && self::isAdminGeneral()) {
      $ok = true;
    }
    if (!$ok) {
      // Redirigir a una página de error en lugar de establecer código de respuesta
      if (!headers_sent()) {
        $base = class_exists('AppHelpers') && method_exists('AppHelpers', 'getRequestEntryUrl') ? AppHelpers::getRequestEntryUrl() : rtrim(app_base_url(), '/') . '/public';
        header('Location: ' . $base . '/access_denied.php');
        exit;
      } else {
        // Si los headers ya se enviaron, mostrar mensaje de error
        echo '<div class="alert alert-danger text-center mt-4">';
        echo '<h4>Acceso Denegado</h4>';
        echo '<p>No tienes permisos para acceder a esta sección.</p>';
        $base = class_exists('AppHelpers') && method_exists('AppHelpers', 'getRequestEntryUrl') ? AppHelpers::getRequestEntryUrl() : rtrim(app_base_url(), '/') . '/public';
        echo '<a href="' . $base . '/index.php?page=registrants" class="btn btn-primary">Ir a Inscripciones</a>';
        echo '</div>';
        exit;
      }
    }
  }

  /**
   * Igual que requireRole pero devuelve JSON (para fetch/XHR) en lugar de redirigir a HTML.
   * Evita el mensaje genérico "Error de conexión" cuando la sesión expiró o el rol no aplica.
   */
  public static function requireRoleJson(array $roles): void {
    $u = self::user();
    if (!$u) {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
      }
      echo json_encode([
        'success' => false,
        'error' => 'Sesión expirada o no válida. Actualice la página e inicie sesión de nuevo.',
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $ok = in_array($u['role'], $roles, true);
    if (!$ok && in_array('admin_general', $roles, true) && self::isAdminGeneral()) {
      $ok = true;
    }
    if (!$ok) {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
      }
      echo json_encode([
        'success' => false,
        'error' => 'No tiene permisos para esta acción. Se requiere administrador (general, torneo u organización).',
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  public static function requireRoleOrTournamentResponsible(array $roles, ?int $tournament_id = null): void {
    $u = self::user();
    
    // Si tiene alguno de los roles especificados, permitir acceso
    if ($u && in_array($u['role'], $roles, true)) {
      return;
    }
    if ($u && in_array('admin_general', $roles, true) && self::isAdminGeneral()) {
      return;
    }
    
    // Si el usuario es admin_club (admin organización) y hay torneo_id, verificar por organización
    if ($u && $u['role'] === 'admin_club' && $tournament_id !== null) {
      try {
        $stmt = DB::pdo()->prepare("SELECT club_responsable FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);
        $tournament = $stmt->fetch();
        if (!$tournament) {
          // Nada que permitir
        } else {
          $org_id = self::getUserOrganizacionId();
          if ($org_id && (int)$tournament['club_responsable'] === (int)$org_id) {
            return;
          }
          if (!empty($u['club_id']) && $tournament['club_responsable'] == $u['club_id']) {
            return;
          }
        }
      } catch (Exception $e) {
        // Error al verificar torneo, denegar acceso por seguridad
      }
    }
    
    // Si llega aquí, no tiene permisos
    if (!headers_sent()) {
      header('Location: ' . AppHelpers::url('access_denied.php'));
      exit;
    } else {
      echo '<div class="alert alert-danger text-center mt-4">';
      echo '<h4>Acceso Denegado</h4>';
      echo '<p>No tienes permisos para acceder a esta sección.</p>';
      echo '<a href="' . htmlspecialchars(AppHelpers::url('index.php', ['page' => 'registrants'])) . '" class="btn btn-primary">Ir a Inscripciones</a>';
      echo '</div>';
      exit;
    }
  }

  /**
   * Cuenta con privilegio de administrador general (según rol original de sesión).
   * Incluye modo de prueba de rol: con role_switch_mode el rol activo puede ser otro,
   * pero la cuenta sigue siendo admin_general.
   */
  public static function isAdminGeneralUser(?array $u): bool {
    if (!$u || !is_array($u)) {
      return false;
    }
    $orig = (string)($u['role_original'] ?? '');
    if ($orig === '') {
      $orig = (string)($u['role'] ?? '');
    }
    return $orig === 'admin_general';
  }

  /**
   * Verifica si el usuario actual es admin_general (cuenta real, no solo el rol simulado).
   * @return bool
   */
  public static function isAdminGeneral(): bool {
    return self::isAdminGeneralUser(self::user());
  }

  /**
   * Verifica si el usuario actual es admin_torneo
   * @return bool
   */
  public static function isAdminTorneo(): bool {
    $u = self::user();
    return $u && $u['role'] === 'admin_torneo';
  }

  /**
   * Verifica si el usuario actual es admin_club
   * @return bool
   */
  public static function isAdminClub(): bool {
    $u = self::user();
    return $u && $u['role'] === 'admin_club';
  }

  /**
   * Delegado / admin operativo de una asociación (alcance provincial, sin gestión de torneos).
   */
  public static function isOperativoSoloAsociacion(): bool
  {
    if (!FvdConfig::adminModuleEnabled()) {
      return false;
    }
    if (self::isAdminGeneral()) {
      return false;
    }
    $u = self::user();
    if (!$u) {
      return false;
    }
    require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';

    return AsociacionAdminHelper::esOperativoSoloAsociacion(
      DB::pdo(),
      self::id(),
      (string) ($u['role'] ?? '')
    );
  }

  /**
   * Club de la asociación del usuario operativo.
   *
   * @return array<string, mixed>|null
   */
  public static function clubOperativoAsociacion(): ?array
  {
    $u = self::user();
    if (!$u) {
      return null;
    }
    require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';

    return AsociacionAdminHelper::clubOperativo(DB::pdo(), self::id(), (string) ($u['role'] ?? ''));
  }

  /**
   * Obtiene el club_id del usuario actual
   * @return int|null
   */
  public static function getUserClubId(): ?int {
    $u = self::user();
    return $u['club_id'] ?? null;
  }

  /** Cache por petición para evitar consultas repetidas */
  private static $cached_organizacion_id = null;
  private static $cached_user_clubes = null;
  private static $cached_dashboard_organizacion = null;

  /**
   * Datos de la organización/club para mostrar en el dashboard (logo + nombre).
   * Solo cuando el usuario NO es admin_general.
   * @return array|null ['nombre' => string, 'logo' => string|null] o null para admin_general/sin org
   */
  public static function getDashboardOrganizacion(): ?array {
    if (self::$cached_dashboard_organizacion !== null) {
      return self::$cached_dashboard_organizacion;
    }
    $u = self::user();
    if (!$u) {
      self::$cached_dashboard_organizacion = null;
      return null;
    }
    $maestra = FvdConfig::getOrganizacionMaestra();
    if ($maestra !== null) {
      self::$cached_dashboard_organizacion = [
        'nombre' => (string)($maestra['nombre'] ?? FvdConfig::ORGANIZACION_NOMBRE),
        'logo' => !empty($maestra['logo']) ? (string)$maestra['logo'] : null,
      ];
      return self::$cached_dashboard_organizacion;
    }
    if ($u['role'] === 'admin_general') {
      self::$cached_dashboard_organizacion = [
        'nombre' => FvdConfig::ORGANIZACION_NOMBRE,
        'logo' => null,
      ];
      return self::$cached_dashboard_organizacion;
    }
    try {
      if ($u['role'] === 'admin_club') {
        $stmt = DB::pdo()->prepare("SELECT nombre, logo FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([self::id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          self::$cached_dashboard_organizacion = ['nombre' => $row['nombre'], 'logo' => $row['logo'] ?: null];
          return self::$cached_dashboard_organizacion;
        }
      }
      if ($u['role'] === 'admin_torneo' && !empty($u['club_id'])) {
        $orgJoin = self::hasCodOrg()
          ? "LEFT JOIN organizaciones o ON (c.cod_org = o.id OR c.cod_org = o.cod_org) AND o.estatus = 1"
          : "LEFT JOIN organizaciones o ON c.cod_org = o.id AND o.estatus = 1";
        $stmt = DB::pdo()->prepare("SELECT c.nombre, c.logo AS club_logo, o.nombre AS org_nombre, o.logo AS org_logo FROM clubes c {$orgJoin} WHERE c.id = ? LIMIT 1");
        $stmt->execute([$u['club_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $nombre = !empty($row['org_nombre']) ? $row['org_nombre'] : $row['nombre'];
          $logo = !empty($row['org_logo']) ? $row['org_logo'] : ($row['club_logo'] ?? null);
          self::$cached_dashboard_organizacion = ['nombre' => $nombre, 'logo' => $logo];
          return self::$cached_dashboard_organizacion;
        }
      }
      if (($u['role'] === 'operador' || $u['role'] === 'usuario') && !empty($u['club_id'])) {
        $orgJoin = self::hasCodOrg()
          ? "LEFT JOIN organizaciones o ON (c.cod_org = o.id OR c.cod_org = o.cod_org) AND o.estatus = 1"
          : "LEFT JOIN organizaciones o ON c.cod_org = o.id AND o.estatus = 1";
        $stmt = DB::pdo()->prepare("SELECT c.nombre, c.logo AS club_logo, o.nombre AS org_nombre, o.logo AS org_logo FROM clubes c {$orgJoin} WHERE c.id = ? LIMIT 1");
        $stmt->execute([$u['club_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $nombre = !empty($row['org_nombre']) ? $row['org_nombre'] : $row['nombre'];
          $logo = !empty($row['org_logo']) ? $row['org_logo'] : ($row['club_logo'] ?? null);
          self::$cached_dashboard_organizacion = ['nombre' => $nombre, 'logo' => $logo];
          return self::$cached_dashboard_organizacion;
        }
      }
    } catch (Exception $e) {
      self::$cached_dashboard_organizacion = null;
      return null;
    }
    self::$cached_dashboard_organizacion = null;
    return null;
  }

  /**
   * Obtiene el ID de la organización del admin_club actual
   * @return int|null
   */
  public static function getUserOrganizacionId(): ?int {
    $u = self::user();
    if (!$u) {
      return null;
    }
    self::$cached_organizacion_id = FvdConfig::ORGANIZACION_ID;
    return self::$cached_organizacion_id;
  }

  /**
   * Referencia canónica de organización para nuevos flujos (cod_org si existe, fallback id).
   */
  public static function getUserOrganizacionRef(): ?int {
    $u = self::user();
    if (!$u) {
      return null;
    }
    return FvdConfig::ORGANIZACION_ID;
  }

  /**
   * Código canónico de federación (clubes.cod_org / torneos) para el admin_club actual.
   * Siempre desde la fila de organizaciones: COALESCE(cod_org, entidad), nunca la PK como sustituto en vínculos homologados.
   */
  public static function getUserOrganizacionCodOrg(): ?int {
    $u = self::user();
    if (!$u) {
      return null;
    }
    return FvdConfig::ORGANIZACION_ID;
  }

  /**
   * Validación institucional: la FVD (id 1) siempre está activa y sin bloqueo por suscripción.
   */
  public static function isOrganizacionActivaYAlDia(?int $organizacionId = null): bool {
    $id = FvdConfig::resolveOrganizacionId($organizacionId);
    return FvdConfig::isOrganizacionOperativa($id);
  }

  /**
   * Obtiene el ID de la organización que gestiona el torneo (todos los procesos son por organización).
   * club_responsable puede ser ID de organización o ID de club; si es club se resuelve organizacion_id.
   * @param int $tournament_id
   * @return int|null
   */
  public static function getTournamentOrganizacionId(int $tournament_id): ?int {
    if ($tournament_id <= 0) return null;
    try {
      $stmt = DB::pdo()->prepare("SELECT club_responsable FROM tournaments WHERE id = ? LIMIT 1");
      $stmt->execute([$tournament_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || empty($row['club_responsable'])) return null;
      $resp = (int)$row['club_responsable'];
      if ($resp <= 0) return null;
      // ¿Es una organización? (existe en organizaciones)
      if (self::hasCodOrg()) {
        $stmt2 = DB::pdo()->prepare("SELECT COALESCE(NULLIF(cod_org, 0), id) AS org_ref FROM organizaciones WHERE (id = ? OR cod_org = ?) AND estatus = 1 LIMIT 1");
        $stmt2->execute([$resp, $resp]);
        $orgRef = $stmt2->fetchColumn();
        if ($orgRef) return (int)$orgRef;
      } else {
        $stmt2 = DB::pdo()->prepare("SELECT id FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1");
        $stmt2->execute([$resp]);
        if ($stmt2->fetch()) return $resp;
      }
      // Es un club: obtener organizacion_id del club
      $stmt3 = DB::pdo()->prepare("SELECT cod_org FROM clubes WHERE id = ? LIMIT 1");
      $stmt3->execute([$resp]);
      $org = $stmt3->fetchColumn();
      return $org !== false && $org !== null ? (int)$org : null;
    } catch (Exception $e) {
      return null;
    }
  }

  /**
   * Indica si el usuario actual pertenece a la organización (los procesos se administran por organización).
   * True si: es admin de esa org, o su club pertenece a esa org, o es admin_club de esa org.
   * @param int $org_id
   * @return bool
   */
  public static function userIsInOrganizacion(int $org_id): bool {
    $u = self::user();
    if (!$u || $org_id <= 0) return false;
    if ($org_id === FvdConfig::ORGANIZACION_ID) {
      return true;
    }
    if (self::isAdminGeneral()) return true;
    if (self::getUserOrganizacionId() === $org_id) return true;
    // Usuario es responsable de la organización (admin_user_id) aunque no tenga rol admin_club
    try {
      $stmt = DB::pdo()->prepare(self::hasCodOrg()
        ? "SELECT 1 FROM organizaciones WHERE (id = ? OR cod_org = ?) AND admin_user_id = ? AND estatus = 1 LIMIT 1"
        : "SELECT 1 FROM organizaciones WHERE id = ? AND admin_user_id = ? AND estatus = 1 LIMIT 1");
      $stmt->execute(self::hasCodOrg() ? [$org_id, $org_id, self::id()] : [$org_id, self::id()]);
      if ($stmt->fetch()) return true;
    } catch (Exception $e) { /* seguir con club */ }
    $club_id = isset($u['club_id']) ? (int)$u['club_id'] : 0;
    if ($club_id <= 0) return false;
    try {
      $pdo = DB::pdo();
      require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
      $match = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'c', 'o');
      $stmt = $pdo->prepare(
        'SELECT 1 FROM clubes c WHERE c.id = ? AND c.estatus = 1 '
        . 'AND EXISTS (SELECT 1 FROM organizaciones o WHERE (o.id = ? OR o.cod_org = ?) AND o.estatus = 1 AND (' . $match . ')) LIMIT 1'
      );
      $stmt->execute([$club_id, $org_id, $org_id]);
      return $stmt->fetch() !== false;
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Verifica si un torneo pertenece al club del admin_torneo o admin_club
   * Para admin_club, verifica por organización (no requiere club_id)
   * @param int $tournament_id
   * @return bool
   */
  public static function canAccessTournament(int $tournament_id): bool {
    $u = self::user();
    
    // Admin general puede acceder a todo
    if (self::isAdminGeneral()) {
      return true;
    }

    if (self::isOperativoSoloAsociacion()) {
      $club = self::clubOperativoAsociacion();
      if (!$club) {
        return false;
      }
      require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
      $orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : 1;

      return AsociacionAdminHelper::torneoVisibleParaClub(DB::pdo(), $tournament_id, $club, $orgFvd);
    }
    
    // Admin club: alineado con getTournamentFilterForRole / OrganizacionDashboardStats (torneo por org)
    if (self::isAdminClub()) {
      $orgRow = self::loadOrganizacionRowForAdminClub();
      if (! $orgRow) {
        return false;
      }
      try {
        require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
        $pdo = DB::pdo();
        [$whereTorneo, $paramsTorneo] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
          $pdo,
          $orgRow,
          self::hasCodOrg(),
          't',
          false
        );
        $stmt = $pdo->prepare("SELECT 1 FROM tournaments t WHERE t.id = ? AND ({$whereTorneo}) LIMIT 1");
        $stmt->execute(array_merge([$tournament_id], $paramsTorneo));

        return $stmt->fetch() !== false;
      } catch (Exception $e) {
        return false;
      }
    }
    
    // Admin torneo: solo su club directo
    if (self::isAdminTorneo()) {
      $user_club_id = self::getUserClubId();
      
      if (!$user_club_id) {
        return false;
      }
      
      try {
        $stmt = DB::pdo()->prepare("SELECT club_responsable FROM tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);
        $tournament = $stmt->fetch();
        
        if (!$tournament) {
          return false;
        }
        
        return $tournament['club_responsable'] == $user_club_id;
      } catch (Exception $e) {
        return false;
      }
    }
    
    // Usuario (jugador): puede acceder solo a torneos en los que está inscrito (para ver posiciones o su resumen)
    if (($u['role'] ?? '') === 'usuario') {
      try {
        $uid = self::id();
        if ($uid <= 0) return false;
        $stmt = DB::pdo()->prepare("SELECT 1 FROM inscritos WHERE torneo_id = ? AND id_usuario = ? AND (estatus IS NULL OR estatus != 'retirado') LIMIT 1");
        $stmt->execute([$tournament_id, $uid]);
        return $stmt->fetch() !== false;
      } catch (Exception $e) {
        return false;
      }
    }
    
    return false;
  }

  /**
   * Verifica si un torneo ya pasó (fechator < hoy)
   * @param int $tournament_id
   * @return bool
   */
  public static function isTournamentPast(int $tournament_id): bool {
    try {
      $stmt = DB::pdo()->prepare("SELECT fechator FROM tournaments WHERE id = ?");
      $stmt->execute([$tournament_id]);
      $tournament = $stmt->fetch();
      
      if (!$tournament || !$tournament['fechator']) {
        return false;
      }
      
      $fecha_torneo = strtotime($tournament['fechator']);
      $hoy = strtotime(date('Y-m-d'));
      
      return $fecha_torneo < $hoy;
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Verifica si el admin_torneo o admin_club puede modificar un torneo
   * (debe ser de su club Y no debe haber pasado)
   * @param int $tournament_id
   * @return bool
   */
  public static function canModifyTournament(int $tournament_id): bool {
    // Admin general puede modificar todo
    if (self::isAdminGeneral()) {
      return true;
    }

    if (self::isOperativoSoloAsociacion()) {
      return false;
    }
    
    // Admin torneo y admin organización tienen restricciones
    if (self::isAdminTorneo() || self::isAdminClub()) {
      // Debe ser de su club
      if (!self::canAccessTournament($tournament_id)) {
        return false;
      }
      
      // No debe haber pasado
      if (self::isTournamentPast($tournament_id)) {
        return false;
      }
      
      return true;
    }
    
    return false;
  }

  /**
   * Fila de organizaciones para el admin_club actual (alcance único de reportes/vistas).
   * @return array<string, mixed>|null
   */
  private static function loadOrganizacionRowForAdminClub(): ?array {
    if (! self::isAdminClub()) {
      return null;
    }
    $org_id = self::getUserOrganizacionId();
    if (! $org_id) {
      return null;
    }
    try {
      $pdo = DB::pdo();
      // getUserOrganizacionId() es siempre la PK: no usar (id OR cod_org) con el mismo parámetro
      // (colisión: otra fila puede tener cod_org = id de Caracas y devolver la org equivocada → 0 torneos).
      $stmt = $pdo->prepare('SELECT * FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
      $stmt->execute([(int) $org_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      return $row ?: null;
    } catch (Exception $e) {
      return null;
    }
  }

  /**
   * Agrega filtro WHERE para limitar torneos según el rol del usuario
   * Retorna array con ['where' => string, 'params' => array]
   * @param string $table_alias Alias de la tabla tournaments (ej: 't')
   * @return array
   */
  public static function getTournamentFilterForRole(string $table_alias = 't'): array {
    $u = self::user();
    
    // Admin general ve todo
    if (self::isAdminGeneral()) {
      return ['where' => '', 'params' => []];
    }
    
    // Admin torneo solo ve torneos de su club directo
    if (self::isAdminTorneo()) {
      $user_club_id = self::getUserClubId();
      
      if (!$user_club_id) {
        return ['where' => "{$table_alias}.club_responsable = ?", 'params' => [0]];
      }
      
      return [
        'where' => "{$table_alias}.club_responsable = ?",
        'params' => [$user_club_id]
      ];
    }
    
    // Admin club: mismo criterio que el dashboard (organizacion_id en torneo, cod_org, entidad, club_responsable)
    if (self::isAdminClub()) {
      $orgRow = self::loadOrganizacionRowForAdminClub();
      if (! $orgRow) {
        return ['where' => '1=0', 'params' => []];
      }
      require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
      [$whereSql, $params] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
        DB::pdo(),
        $orgRow,
        self::hasCodOrg(),
        $table_alias,
        false
      );

      return ['where' => $whereSql, 'params' => $params];
    }
    
    return ['where' => '1=0', 'params' => []]; // Denegar acceso por defecto
  }

  /**
   * Agrega filtro WHERE para limitar clubes según el rol del usuario
   * Retorna array con ['where' => string, 'params' => array]
   * @param string $table_alias Alias de la tabla clubs (ej: 'c'), vacío para sin alias
   * @return array
   */
  public static function getClubFilterForRole(string $table_alias = ''): array {
    $u = self::user();
    $col = $table_alias ? "{$table_alias}.id" : "id";
    
    // Admin general ve todo
    if (self::isAdminGeneral()) {
      return ['where' => '', 'params' => []];
    }
    
    // Admin torneo ve solo su club
    if (self::isAdminTorneo()) {
      $user_club_id = self::getUserClubId();
      
      if (!$user_club_id) {
        return ['where' => "{$col} = ?", 'params' => [0]]; // No verá nada
      }
      
      return [
        'where' => "{$col} = ?",
        'params' => [$user_club_id]
      ];
    }
    
    // Admin club: clubes vinculados a la organización activa (misma regla que OrganizacionDashboardStats)
    if (self::isAdminClub()) {
      $orgRow = self::loadOrganizacionRowForAdminClub();
      if (! $orgRow) {
        return ['where' => "{$col} = ?", 'params' => [0]];
      }
      require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
      $clubes = OrganizacionDashboardStats::clubIdsForOrganizacion(DB::pdo(), $orgRow, self::hasCodOrg());
      if (empty($clubes)) {
        return ['where' => "{$col} = ?", 'params' => [0]];
      }
      $placeholders = implode(',', array_fill(0, count($clubes), '?'));

      return [
        'where' => "{$col} IN ($placeholders)",
        'params' => $clubes,
      ];
    }
    
    return ['where' => '1=0', 'params' => []]; // Denegar acceso por defecto
  }

  /**
   * Genera un UUID v4 simple
   * @return string
   */
  public static function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      mt_rand(0, 0xffff),
      mt_rand(0, 0x0fff) | 0x4000,
      mt_rand(0, 0x3fff) | 0x8000,
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
  }

  /**
   * Obtiene la credencial UUID del usuario actual
   * @return string|null
   */
  public static function getUserCredential(): ?string {
    $u = self::user();
    return $u['uuid'] ?? null;
  }

  /**
   * Verifica si se están usando las credenciales por defecto
   * @param string $username
   * @param string $password
   * @return bool
   */
  public static function isUsingDefaultCredentials(string $username, string $password): bool {
    // Lista de credenciales por defecto conocidas
    $defaultCredentials = [
      ['username' => 'admin', 'password' => 'admin123'],
      ['username' => 'admin', 'password' => 'password'],
      ['username' => 'admin', 'password' => '123456'],
    ];
    
    foreach ($defaultCredentials as $cred) {
      if (strtolower($username) === strtolower($cred['username']) && $password === $cred['password']) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Verifica si el usuario actual debe cambiar su contraseña
   * @return bool
   */
  public static function mustChangePassword(): bool {
    return isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true;
  }

  /**
   * Limpia el flag de cambio de contraseña obligatorio
   */
  public static function clearPasswordChangeFlag(): void {
    unset($_SESSION['force_password_change']);
    unset($_SESSION['password_change_reason']);
  }

  /**
   * Obtiene todos los clubes que supervisa el usuario actual
   * Incluye club principal + asociados. Cache por petición.
   * @return array Lista de IDs de clubes
   */
  public static function getUserClubes(): array {
    if (self::$cached_user_clubes !== null) {
      return self::$cached_user_clubes;
    }
    $u = self::user();
    if (!$u) {
      self::$cached_user_clubes = [];
      return [];
    }
    if (self::isAdminGeneral()) {
      try {
        $stmt = DB::pdo()->query("SELECT id FROM clubes WHERE estatus = 1");
        self::$cached_user_clubes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return self::$cached_user_clubes;
      } catch (Exception $e) {
        self::$cached_user_clubes = [];
        return [];
      }
    }
    if ($u['role'] === 'admin_club') {
      $orgRow = self::loadOrganizacionRowForAdminClub();
      if ($orgRow) {
        require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';
        try {
          $ids = OrganizacionDashboardStats::clubIdsForOrganizacion(DB::pdo(), $orgRow, self::hasCodOrg());
          if (! empty($ids)) {
            self::$cached_user_clubes = array_values(array_map('intval', $ids));
            return self::$cached_user_clubes;
          }
        } catch (Exception $e) {
          // fallback ClubHelper
        }
      }
    }
    require_once __DIR__ . '/../lib/ClubHelper.php';
    // Admin organización: respaldo legacy si no hubo fila org o lista vacía
    $clubes = ClubHelper::getClubesByAdminClubId(self::id());
    if (empty($clubes) && !empty($u['club_id'])) {
      $clubes = ClubHelper::getClubesSupervised($u['club_id']);
    }
    self::$cached_user_clubes = $clubes;
    return $clubes;
  }

  /**
   * Verifica si el usuario puede gestionar un club específico
   * @param int $club_id
   * @return bool
   */
  public static function canManageClub(int $club_id): bool {
    $u = self::user();
    if (!$u) return false;
    
    if (self::isAdminGeneral()) return true;
    
    $clubes = self::getUserClubes();
    return in_array($club_id, $clubes);
  }
}

