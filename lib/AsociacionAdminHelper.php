<?php

declare(strict_types=1);

require_once __DIR__ . '/FinanzasAsociacionData.php';
require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/FvdConfig.php';

/**
 * Administrador operativo de una asociación (club estatal) — delegado_user_id o admin_club acotado al club.
 */
final class AsociacionAdminHelper
{
    /** Acciones permitidas en torneo_gestion para operativo de asociación (sin gestión del torneo). */
    public const TORNEO_GESTION_ACCIONES = [
        'inscripciones',
        'inscribir_sitio',
        'inscribir_equipo_sitio',
        'inscribir_pareja_sitio',
        'carga_masiva_parejas_sitio',
        'carga_masiva_equipos_sitio',
        'carga_masiva_parejas_plantilla',
        'carga_masiva_equipos_plantilla',
    ];

    /** Acciones POST en torneo_gestion permitidas al operativo de asociación. */
    public const TORNEO_GESTION_POST_ACCIONES = [
        'cambiar_estatus_inscrito',
        'validar_pago_inscrito',
        'toggle_pago_inscrito',
        'enviar_recordatorio_pago_inscrito',
        'guardar_equipo_sitio',
        'carga_masiva_equipos_validar',
        'carga_masiva_equipos_sitio',
        'carga_masiva_parejas_validar',
        'carga_masiva_parejas_sitio',
    ];

    /** Páginas del dashboard accesibles (el resto redirige al panel). */
    public const PAGINAS_OPERATIVO = [
        'asociacion_panel',
        'asociacion/torneo_ver',
        'asociacion/solicitud',
        'asociacion/afiliar_atleta',
        'asociacion/informes',
        'torneo_gestion',
        'tournament_admin',
        'finanzas/resumen_asociacion',
        'reportes_pago_usuarios',
        'organizaciones',
        'users/profile',
        'api_search_user_persona',
        'user_notificaciones',
    ];

    /** Acciones permitidas en tournament_admin (solo credenciales / carnets). */
    public const TOURNAMENT_ADMIN_ACCIONES = [
        'generar_qr',
        'imprimir_qr_lote',
        'reporte_identificacion_jugadores',
    ];

    public static function usuarioEsDelegadoAsociacion(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $st = $pdo->prepare('SELECT 1 FROM clubes WHERE delegado_user_id = ? AND estatus = 1 LIMIT 1');
        $st->execute([$userId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Usuario con alcance solo operativo de asociación (no administra torneos ni crea entidades).
     */
    public static function esOperativoSoloAsociacion(PDO $pdo, int $userId, string $role): bool
    {
        if ($userId <= 0 || $role === 'admin_general') {
            return false;
        }
        if (self::usuarioEsDelegadoAsociacion($pdo, $userId)) {
            return true;
        }
        if ($role === 'admin_club') {
            return self::clubOperativo($pdo, $userId, $role) !== null;
        }

        return false;
    }

    /**
     * Club operativo del usuario (delegado o admin_club con club asignado).
     *
     * @return array<string, mixed>|null
     */
    public static function clubOperativo(PDO $pdo, int $userId, string $role): ?array
    {
        $club = self::clubDelegadoPrincipal($pdo, $userId);
        if ($club !== null) {
            return $club;
        }
        if ($role !== 'admin_club' || $userId <= 0) {
            return null;
        }
        $cid = class_exists('Auth') ? (int) (Auth::getUserClubId() ?? 0) : 0;
        if ($cid <= 0) {
            return null;
        }
        $st = $pdo->prepare('
            SELECT c.*, e.nombre AS entidad_nombre
            FROM clubes c
            LEFT JOIN entidad e ON e.id = c.entidad
            WHERE c.id = ? AND c.estatus = 1
            LIMIT 1
        ');
        $st->execute([$cid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Primer club donde el usuario es delegado (una fila).
     *
     * @return array<string, mixed>|null
     */
    public static function clubDelegadoPrincipal(PDO $pdo, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $st = $pdo->prepare("
            SELECT c.*, e.nombre AS entidad_nombre
            FROM clubes c
            LEFT JOIN entidad e ON e.id = c.entidad
            WHERE c.delegado_user_id = ? AND c.estatus = 1
            ORDER BY c.id ASC
            LIMIT 1
        ");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && !empty($row) ? $row : null;
    }

    /**
     * URL al detalle de la asociación (club) del usuario autenticado dentro de la org FVD.
     */
    public static function urlMiOrganizacion(?PDO $pdo = null, ?int $userId = null, ?string $role = null): ?string
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        $pdo = $pdo ?? (class_exists('DB', false) ? DB::pdo() : null);
        if (!$pdo instanceof PDO) {
            return null;
        }
        $userId = $userId ?? (class_exists('Auth', false) ? Auth::id() : 0);
        $role = $role ?? (class_exists('Auth', false) ? (string) (Auth::user()['role'] ?? '') : '');
        $club = self::clubOperativo($pdo, (int) $userId, $role);
        if ($club === null) {
            return null;
        }
        $clubId = (int) ($club['id'] ?? 0);
        if ($clubId <= 0) {
            return null;
        }
        $orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : 1;

        return AppHelpers::dashboard('organizaciones', [
            'id' => $orgFvd,
            'club_id' => $clubId,
        ]);
    }

    /** Operativo de asociación solo puede ver su propio club (no el listado FVD completo). */
    public static function usuarioPuedeVerClubOperativo(PDO $pdo, int $clubId): bool
    {
        if ($clubId <= 0) {
            return false;
        }
        if (!class_exists('Auth', false) || !Auth::isOperativoSoloAsociacion()) {
            return true;
        }
        $club = self::clubOperativo($pdo, Auth::id(), (string) (Auth::user()['role'] ?? ''));

        return $club !== null && (int) ($club['id'] ?? 0) === $clubId;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function esPaginaMiOrganizacionActiva(string $currentPage, array $query, ?PDO $pdo = null): bool
    {
        if ($currentPage !== 'organizaciones') {
            return false;
        }
        $clubIdGet = (int) ($query['club_id'] ?? 0);
        if ($clubIdGet <= 0) {
            return false;
        }
        if (!class_exists('Auth', false) || !Auth::isOperativoSoloAsociacion()) {
            $orgId = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : (int) ($query['id'] ?? 0);

            return (int) ($query['id'] ?? 0) === $orgId;
        }
        $pdo = $pdo ?? (class_exists('DB', false) ? DB::pdo() : null);
        if (!$pdo instanceof PDO) {
            return false;
        }
        $club = self::clubOperativo($pdo, Auth::id(), (string) (Auth::user()['role'] ?? ''));

        return $club !== null && (int) ($club['id'] ?? 0) === $clubIdGet;
    }

    public static function esEventoMasivo(?array $torneo): bool
    {
        return $torneo !== null && (int) ($torneo['es_evento_masivo'] ?? 0) > 0;
    }

    /**
     * Eventos masivos / nacionales FVD (sin club_responsable; cod_org de la federación).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function sqlWhereTorneosMasivosFvd(PDO $pdo, int $orgFvdId): array
    {
        if ($orgFvdId <= 0) {
            return ['1=0', []];
        }
        $stOrg = $pdo->prepare('SELECT id, cod_org, entidad FROM organizaciones WHERE id = ? LIMIT 1');
        $stOrg->execute([$orgFvdId]);
        $org = $stOrg->fetch(PDO::FETCH_ASSOC);
        if (!$org) {
            return ['1=0', []];
        }
        $orgPk = (int) ($org['id'] ?? 0);
        $orgRef = (int) ($org['cod_org'] ?? 0);
        if ($orgRef <= 0) {
            $orgRef = $orgPk;
        }
        $ids = array_values(array_unique(array_filter([$orgPk, $orgRef], static fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return ['1=0', []];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $flags = OrganizacionDashboardStats::columnFlags($pdo);
        if ($flags['has_tournament_cod_org']) {
            $sql = "COALESCE(t.es_evento_masivo, 0) > 0 AND t.estatus = 1 AND (
                (t.cod_org IS NOT NULL AND t.cod_org > 0 AND t.cod_org IN ({$ph}))
                OR ((t.cod_org IS NULL OR t.cod_org = 0) AND COALESCE(t.entidad, 0) IN (0, 999))
            )";

            return [$sql, $ids];
        }

        return [
            'COALESCE(t.es_evento_masivo, 0) > 0 AND t.estatus = 1 AND COALESCE(t.entidad, 0) IN (0, 999)',
            [],
        ];
    }

    /**
     * WHERE torneos sede/provinciales del club (no masivos; cod_org / club_responsable).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function sqlWhereTorneosVisibleClub(PDO $pdo, int $orgFvdId, int $entidadClub): array
    {
        if ($orgFvdId <= 0) {
            return ['1=0', []];
        }
        $stOrg = $pdo->prepare('SELECT * FROM organizaciones WHERE id = ? LIMIT 1');
        $stOrg->execute([$orgFvdId]);
        $org = $stOrg->fetch(PDO::FETCH_ASSOC);
        if (!$org) {
            return ['1=0', []];
        }
        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasCodOrg = false;
        }
        [$whereOrg, $params] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
            $pdo,
            $org,
            $hasCodOrg,
            't',
            false
        );
        $entSql = '';
        $entParams = [];
        if ($entidadClub > 0) {
            $entSql = ' AND (t.entidad = ? OR t.entidad IN (0, 999))';
            $entParams[] = $entidadClub;
        }

        return ['(COALESCE(t.es_evento_masivo, 0) = 0) AND (' . $whereOrg . ')' . $entSql, array_merge($params, $entParams)];
    }

    /**
     * Torneos sede/provinciales de la entidad del club (excluye eventos masivos).
     *
     * @return list<array<string, mixed>>
     */
    public static function listarTorneosFvdParaClub(PDO $pdo, array $club, int $orgFvdId, int $limite = 20): array
    {
        $entidadId = (int) ($club['entidad'] ?? 0);
        if ($orgFvdId <= 0) {
            return [];
        }
        [$whereSql, $params] = self::sqlWhereTorneosVisibleClub($pdo, $orgFvdId, $entidadId);
        $sql = '
            SELECT t.id, t.nombre, t.es_evento_masivo, t.fechator, t.modalidad, t.clase, t.costo, t.lugar,
                   t.permite_inscripcion_linea, t.entidad
            FROM tournaments t
            WHERE ' . $whereSql . '
            ORDER BY t.fechator DESC, t.id DESC
            LIMIT ' . (int) max(1, min(50, $limite));
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Eventos masivos / nacionales organizados por la FVD (sin depender de club_responsable).
     *
     * @return list<array<string, mixed>>
     */
    public static function listarTorneosFvdMasivos(PDO $pdo, int $orgFvdId, int $limite = 20): array
    {
        if ($orgFvdId <= 0) {
            return [];
        }
        [$whereSql, $params] = self::sqlWhereTorneosMasivosFvd($pdo, $orgFvdId);
        $sql = '
            SELECT t.id, t.nombre, t.es_evento_masivo, t.fechator, t.modalidad, t.clase, t.costo, t.lugar,
                   t.permite_inscripcion_linea, t.entidad
            FROM tournaments t
            WHERE ' . $whereSql . '
            ORDER BY t.fechator DESC, t.id DESC
            LIMIT ' . (int) max(1, min(50, $limite));
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function torneoVisibleParaClub(PDO $pdo, int $torneoId, array $club, int $orgFvdId): bool
    {
        if ($torneoId <= 0 || $orgFvdId <= 0) {
            return false;
        }
        $stM = $pdo->prepare('SELECT COALESCE(es_evento_masivo, 0) AS em FROM tournaments WHERE id = ? LIMIT 1');
        $stM->execute([$torneoId]);
        $esMasivo = (int) ($stM->fetchColumn() ?: 0) > 0;
        if ($esMasivo) {
            [$whereSql, $params] = self::sqlWhereTorneosMasivosFvd($pdo, $orgFvdId);
        } else {
            $entidadId = (int) ($club['entidad'] ?? 0);
            [$whereSql, $params] = self::sqlWhereTorneosVisibleClub($pdo, $orgFvdId, $entidadId);
        }
        $st = $pdo->prepare('SELECT 1 FROM tournaments t WHERE t.id = ? AND ' . $whereSql . ' LIMIT 1');
        $st->execute(array_merge([$torneoId], $params));

        return (bool) $st->fetchColumn();
    }

    /** Admin general o delegado / admin_club con club operativo asignado. */
    public static function usuarioTieneAccesoModulosAsociacion(): bool
    {
        if (!class_exists('Auth', false) || !Auth::user()) {
            return false;
        }
        if (Auth::isAdminGeneral()) {
            return true;
        }

        return Auth::clubOperativoAsociacion() !== null;
    }

    /** Visibilidad de torneo para el panel de asociación (incluye eventos masivos FVD). */
    public static function usuarioPuedeVerTorneo(PDO $pdo, int $torneoId, ?array $club = null): bool
    {
        if ($torneoId <= 0) {
            return false;
        }
        if (class_exists('Auth', false) && Auth::isAdminGeneral()) {
            return true;
        }
        $club = $club ?? (class_exists('Auth', false) ? Auth::clubOperativoAsociacion() : null);
        if ($club === null || !is_array($club)) {
            return class_exists('Auth', false) && Auth::canAccessTournament($torneoId);
        }
        $orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : 1;

        return self::torneoVisibleParaClub($pdo, $torneoId, $club, $orgFvd);
    }

    public static function accionTorneoGestionPermitida(string $action): bool
    {
        $action = trim($action);
        if ($action === '') {
            return false;
        }
        if (in_array($action, self::TORNEO_GESTION_ACCIONES, true)
            || in_array($action, self::TORNEO_GESTION_POST_ACCIONES, true)) {
            return true;
        }
        if (str_starts_with($action, 'inscripciones_')
            && (str_contains($action, 'export') || str_contains($action, 'excel') || str_contains($action, 'reporte'))) {
            return true;
        }
        if (str_starts_with($action, 'retirados_') && str_contains($action, 'export')) {
            return true;
        }
        if (str_contains($action, 'reporte_pdf') || str_contains($action, '_plantilla')) {
            return true;
        }

        return false;
    }

    public static function paginaPermitidaOperativo(string $page): bool
    {
        $page = trim($page);
        if ($page === '' || $page === 'home') {
            return true;
        }
        if (str_starts_with($page, 'asociacion/')) {
            return true;
        }

        return in_array($page, self::PAGINAS_OPERATIVO, true);
    }

    public static function accionTournamentAdminPermitida(string $action): bool
    {
        return in_array(trim($action), self::TOURNAMENT_ADMIN_ACCIONES, true);
    }

    /**
     * Registra solicitud administrativa en movimiento_torneo (pendiente de validación FVD).
     *
     * @param array{tipo:string,id_usuario?:int,cedula?:string,monto?:float,torneo_id?:int,nota?:string} $datos
     */
    public static function registrarSolicitudMovimiento(PDO $pdo, array $club, array $datos): int
    {
        if (!FinanzasAsociacionData::tablaExiste($pdo, 'movimiento_torneo')) {
            throw new RuntimeException('La tabla movimiento_torneo no existe. Ejecute sql/create_movimiento_torneo.sql');
        }
        $tipo = (string) ($datos['tipo'] ?? '');
        $map = [
            'afiliacion' => ['afiliacion' => 1],
            'anualidad' => ['anualidad' => 1],
            'traspaso' => ['traspaso' => 1],
            'carnet' => ['carnet' => 1],
        ];
        if (!isset($map[$tipo])) {
            throw new InvalidArgumentException('Tipo de solicitud no válido');
        }
        $monto = max(0, (float) ($datos['monto'] ?? 0));
        $idUsuario = (int) ($datos['id_usuario'] ?? 0);
        $cedula = trim((string) ($datos['cedula'] ?? ''));
        if ($idUsuario <= 0 && $cedula === '') {
            throw new InvalidArgumentException('Indique el atleta (usuario o cédula)');
        }
        if ($idUsuario > 0) {
            $stU = $pdo->prepare('SELECT id, cedula, numfvd, sexo FROM usuarios WHERE id = ? LIMIT 1');
            $stU->execute([$idUsuario]);
            $u = $stU->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                throw new InvalidArgumentException('Usuario no encontrado');
            }
            $cedula = (string) $u['cedula'];
            $numfvd = (int) ($u['numfvd'] ?? 0);
            $sexo = is_numeric($u['sexo'] ?? '') ? (int) $u['sexo'] : 0;
        } else {
            $stU = $pdo->prepare('SELECT id, numfvd, sexo FROM usuarios WHERE cedula = ? LIMIT 1');
            $stU->execute([$cedula]);
            $u = $stU->fetch(PDO::FETCH_ASSOC);
            $idUsuario = $u ? (int) $u['id'] : 0;
            $numfvd = $u ? (int) ($u['numfvd'] ?? 0) : 0;
            $sexo = $u && is_numeric($u['sexo'] ?? '') ? (int) $u['sexo'] : 0;
        }
        $torneoId = (int) ($datos['torneo_id'] ?? 0);
        $cols = $map[$tipo];
        $af = (int) ($cols['afiliacion'] ?? 0) * ($monto > 0 ? (int) round($monto) : 1);
        $an = (int) ($cols['anualidad'] ?? 0) * ($monto > 0 ? (int) round($monto) : 1);
        $tr = (int) ($cols['traspaso'] ?? 0) * ($monto > 0 ? (int) round($monto) : 1);
        $ca = (int) ($cols['carnet'] ?? 0) * ($monto > 0 ? (int) round($monto) : 1);
        $ins = (int) ($cols['inscripcion'] ?? 0);
        $st = $pdo->prepare('
            INSERT INTO movimiento_torneo
                (id_usuario, cedula, numfvd, sexo, id_club, estatus, afiliacion, anualidad, carnet, traspaso, inscripcion, torneo_id, grupo_nombre)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)
        ');
        $nota = trim((string) ($datos['nota'] ?? ''));
        $st->execute([
            max(0, $idUsuario),
            $cedula,
            $numfvd ?? 0,
            $sexo ?? 0,
            (int) ($club['id'] ?? 0),
            $af,
            $an,
            $ca,
            $tr,
            $ins,
            $torneoId,
            $nota !== '' ? $nota : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Contexto de inscripción para admin operativo de asociación (club fijo).
     *
     * @return array{club_id:int, entidad_id:int, club_nombre:string, entidad_nombre:string}|null
     */
    public static function contextoInscripcionOperativa(PDO $pdo, ?int $userId = null, ?string $role = null): ?array
    {
        if (!class_exists('Auth') || !Auth::isOperativoSoloAsociacion()) {
            return null;
        }
        $uid = $userId ?? (int) (Auth::id() ?? 0);
        $rol = $role ?? (string) (Auth::user()['role'] ?? '');
        $club = self::clubOperativo($pdo, $uid, $rol);
        if ($club === null) {
            return null;
        }

        return [
            'club_id' => (int) ($club['id'] ?? 0),
            'entidad_id' => (int) ($club['entidad'] ?? 0),
            'club_nombre' => trim((string) ($club['nombre'] ?? '')),
            'entidad_nombre' => trim((string) ($club['entidad_nombre'] ?? '')),
        ];
    }

    public static function idClubForzadoInscripcion(PDO $pdo): ?int
    {
        $ctx = self::contextoInscripcionOperativa($pdo);

        return $ctx !== null && ($ctx['club_id'] ?? 0) > 0 ? (int) $ctx['club_id'] : null;
    }

    /**
     * Club del delegado que realiza la inscripción (admin de asociación / delegado_user_id).
     */
    public static function idClubDelegadoInscripcion(PDO $pdo, int $userIdInscriptor): ?int
    {
        if ($userIdInscriptor <= 0) {
            return null;
        }
        $forzado = self::idClubForzadoInscripcion($pdo);
        if ($forzado !== null) {
            return $forzado;
        }
        $st = $pdo->prepare('SELECT id FROM clubes WHERE delegado_user_id = ? AND estatus = 1 LIMIT 1');
        $st->execute([$userIdInscriptor]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id > 0 ? $id : null;
    }

    /**
     * id_club a persistir en inscritos según canal de inscripción.
     *
     * @param int|null $clubSelectorLanding Club elegido en landing (prioridad si > 0)
     * @param int|null $clubPost            Club enviado en formulario admin/sitio
     */
    public static function resolverIdClubInscripcion(
        PDO $pdo,
        int $idUsuarioInscrito,
        int $idUsuarioInscriptor,
        ?int $clubSelectorLanding = null,
        ?int $clubPost = null,
        ?int $clubActor = null
    ): ?int {
        $forzado = self::idClubForzadoInscripcion($pdo);
        if ($forzado !== null) {
            return $forzado;
        }
        if ($clubPost !== null && $clubPost > 0) {
            return $clubPost;
        }
        if ($clubSelectorLanding !== null && $clubSelectorLanding > 0) {
            return $clubSelectorLanding;
        }
        if ($idUsuarioInscrito > 0) {
            $st = $pdo->prepare('SELECT club_id, entidad FROM usuarios WHERE id = ? LIMIT 1');
            $st->execute([$idUsuarioInscrito]);
            $uRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $resolved = self::resolverClubIdDesdeAtleta(
                    $pdo,
                    (int) ($uRow['club_id'] ?? 0),
                    (int) ($uRow['entidad'] ?? 0)
                );
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
        if ($idUsuarioInscriptor > 0) {
            $delegado = self::idClubDelegadoInscripcion($pdo, $idUsuarioInscriptor);
            if ($delegado !== null) {
                return $delegado;
            }
        }
        if ($clubActor !== null && $clubActor > 0) {
            return $clubActor;
        }

        return null;
    }

    /**
     * FVD: en clubes el id es el código de asociación/club. El atleta puede tener club_id o entidad apuntando a ese id.
     */
    public static function resolverClubIdDesdeAtleta(PDO $pdo, ?int $clubId, ?int $entidadId): ?int
    {
        foreach ([(int) ($clubId ?? 0), (int) ($entidadId ?? 0)] as $candidate) {
            if ($candidate <= 0) {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM clubes WHERE id = ? LIMIT 1');
            $st->execute([$candidate]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return array{id: int, nombre: string}|null
     */
    public static function clubPorId(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT id, nombre FROM clubes WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? ['id' => (int) $row['id'], 'nombre' => (string) ($row['nombre'] ?? '')] : null;
    }

    /**
     * Resuelve club del atleta (usuarios) contra clubes.id.
     *
     * @return array{club_id: int, club_nombre: string}
     */
    public static function resolverClubAtletaDesdeUsuario(PDO $pdo, array $usuarioRow): array
    {
        $clubId = self::resolverClubIdDesdeAtleta(
            $pdo,
            (int) ($usuarioRow['club_id'] ?? 0),
            (int) ($usuarioRow['entidad'] ?? 0)
        ) ?? 0;
        $nombre = '';
        if ($clubId > 0) {
            $c = self::clubPorId($pdo, $clubId);
            $nombre = $c['nombre'] ?? '';
        }

        return ['club_id' => $clubId, 'club_nombre' => $nombre];
    }

    /**
     * FVD: el selector de "asociación" envía entidad; en clubes id suele coincidir con entidad.
     */
    public static function resolverClubIdDesdeEntidad(PDO $pdo, int $entidadId): ?int
    {
        if ($entidadId <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT id FROM clubes WHERE id = ? LIMIT 1');
        $st->execute([$entidadId]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $st = $pdo->prepare('SELECT id FROM clubes WHERE entidad = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$entidadId]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id > 0 ? $id : null;
    }

    public static function validarClubPermitido(PDO $pdo, ?int $idClub): bool
    {
        $forzado = self::idClubForzadoInscripcion($pdo);
        if ($forzado === null) {
            return true;
        }

        return $idClub !== null && (int) $idClub === $forzado;
    }

    /**
     * Club de referencia para búsqueda/inscripción en sitio (operativo forzado o selector del panel).
     */
    public static function clubFiltroInscripcionSitio(PDO $pdo, ?int $clubIdPost = null): ?int
    {
        $forzado = self::idClubForzadoInscripcion($pdo);
        if ($forzado !== null) {
            return $forzado;
        }
        $clubIdPost = (int) ($clubIdPost ?? 0);

        return $clubIdPost > 0 ? $clubIdPost : null;
    }

    /**
     * Condición SQL: usuarios de la misma asociación (clubes.id = código).
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function filtroSqlUsuariosPorClub(int $clubId, string $alias = 'u'): array
    {
        if ($clubId <= 0) {
            return ['', []];
        }
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'u';

        return [
            " AND ({$a}.club_id = ? OR {$a}.entidad = ?) ",
            [$clubId, $clubId],
        ];
    }

    /**
     * Condición SQL para limitar usuarios al ámbito de la asociación (misma entidad).
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function filtroSqlUsuariosAsociacion(PDO $pdo, string $alias = 'u'): array
    {
        $ctx = self::contextoInscripcionOperativa($pdo);
        if ($ctx === null || ($ctx['club_id'] ?? 0) <= 0) {
            return ['', []];
        }

        return self::filtroSqlUsuariosPorClub((int) $ctx['club_id'], $alias);
    }

    /**
     * El usuario pertenece a la asociación indicada (clubes.id).
     */
    public static function usuarioPerteneceAClub(PDO $pdo, int $idUsuario, int $clubId): bool
    {
        if ($idUsuario <= 0 || $clubId <= 0) {
            return false;
        }
        $st = $pdo->prepare('SELECT entidad, club_id FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$idUsuario]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return false;
        }
        $resolved = self::resolverClubIdDesdeAtleta(
            $pdo,
            (int) ($u['club_id'] ?? 0),
            (int) ($u['entidad'] ?? 0)
        );

        return $resolved !== null && $resolved === $clubId;
    }

    /**
     * El usuario pertenece al ámbito de la asociación del operativo.
     */
    public static function usuarioEnAmbitoAsociacion(PDO $pdo, int $idUsuario): bool
    {
        $ctx = self::contextoInscripcionOperativa($pdo);
        if ($ctx === null) {
            return true;
        }

        return self::usuarioPerteneceAClub($pdo, $idUsuario, (int) ($ctx['club_id'] ?? 0));
    }
}
