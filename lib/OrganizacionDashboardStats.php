<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/FvdConfig.php';

/**
 * Estadísticas de organización alineadas con el esquema real:
 * - usuarios: entidad (geográfica) y club_id; columna opcional cod_org (afiliación).
 * - clubes: solo cod_org homologado al código de federación (organizaciones.cod_org / entidad).
 * - tournaments: cod_org, club_responsable y/o entidad territorial.
 */
final class OrganizacionDashboardStats
{
    /** @var array{has_usuario_cod_org: bool, has_tournament_cod_org: bool}|null */
    private static ?array $columnFlags = null;

    private static ?bool $clubesHasCodOrg = null;

    private static ?bool $clubesHasEntidad = null;

    public static function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $ignored) {
            return false;
        }
    }

    private static function clubesHasCodOrgColumn(PDO $pdo): bool
    {
        if (self::$clubesHasCodOrg !== null) {
            return self::$clubesHasCodOrg;
        }
        self::$clubesHasCodOrg = self::tableHasColumn($pdo, 'clubes', 'cod_org');

        return self::$clubesHasCodOrg;
    }

    private static function clubesHasEntidadColumn(PDO $pdo): bool
    {
        if (self::$clubesHasEntidad !== null) {
            return self::$clubesHasEntidad;
        }
        self::$clubesHasEntidad = self::tableHasColumn($pdo, 'clubes', 'entidad');

        return self::$clubesHasEntidad;
    }

    /**
     * Código de federación efectivo del club: cod_org homologado, o entidad si cod_org quedó NULL (legacy).
     */
    public static function clubFederacionCodigoSqlExpr(PDO $pdo, string $alias = 'c'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 'c';
        }
        if (self::clubesHasCodOrgColumn($pdo) && self::clubesHasEntidadColumn($pdo)) {
            return "COALESCE(NULLIF({$alias}.cod_org, 0), NULLIF({$alias}.entidad, 0))";
        }
        if (self::clubesHasEntidadColumn($pdo)) {
            return "NULLIF({$alias}.entidad, 0)";
        }
        if (self::clubesHasCodOrgColumn($pdo)) {
            return "{$alias}.cod_org";
        }

        return '0';
    }

    /** @return array{has_usuario_cod_org: bool, has_tournament_cod_org: bool} */
    public static function columnFlags(PDO $pdo): array
    {
        if (self::$columnFlags !== null) {
            return self::$columnFlags;
        }
        self::$columnFlags = [
            'has_usuario_cod_org' => self::tableHasColumn($pdo, 'usuarios', 'cod_org'),
            'has_tournament_cod_org' => self::tableHasColumn($pdo, 'tournaments', 'cod_org'),
        ];

        return self::$columnFlags;
    }

    /** @return int[] */
    private static function idsRef(int $org_pk, int $org_ref): array
    {
        return array_values(array_unique(array_filter([$org_pk, $org_ref], static fn (int $v): bool => $v > 0)));
    }

    /**
     * Código de federación canónico para enlazar clubes (misma semántica que sql/normalizar_modelo_organizacion_canonico.sql).
     */
    private static function canonicalOrgCodigo(array $organizacion): int
    {
        $c = (int) ($organizacion['cod_org'] ?? 0);
        if ($c > 0) {
            return $c;
        }
        $pk = (int) ($organizacion['id'] ?? 0);
        if ($pk === FvdConfig::ORGANIZACION_ID) {
            return FvdConfig::ORGANIZACION_ID;
        }

        return (int) ($organizacion['entidad'] ?? 0);
    }

    /**
     * WHERE sobre clubes (alias c): código de federación = cod_org o, si viene NULL, entidad (misma regla que normalizar_modelo).
     */
    private static function clubScopeSqlAndParams(PDO $pdo, int $canonical_cod_org): array
    {
        if ($canonical_cod_org <= 0) {
            return ['1=0', []];
        }
        if (!self::clubesHasCodOrgColumn($pdo)) {
            return ['1=0', []];
        }
        $expr = self::clubFederacionCodigoSqlExpr($pdo, 'c');

        return ["c.estatus = 1 AND ({$expr}) = ?", [$canonical_cod_org]];
    }

    /**
     * Código de federación canónico de una fila organizaciones (para comparar con clubes sin confundir PK con cod_org).
     */
    public static function federacionCodigoDesdeOrganizacion(array $organizacion): int
    {
        return self::canonicalOrgCodigo($organizacion);
    }

    /**
     * WHERE sobre clubes (alias c) + parámetros: misma federación que la fila organización dada.
     * No usa c.cod_org = o.id (colisión: PK org 11 vs cod_org club Cojedes 11).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function clubScopeWhereForOrganizacion(PDO $pdo, array $organizacion): array
    {
        $canonical = self::canonicalOrgCodigo($organizacion);

        return self::clubScopeSqlAndParams($pdo, $canonical);
    }

    /**
     * Fragmento SQL: club (alias) y organización (alias) son la misma federación.
     * Compara COALESCE(cod_org, entidad) en ambos lados.
     */
    public static function sqlClubMismaFederacionQueOrg(PDO $pdo, string $clubAlias, string $orgAlias): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $clubAlias)) {
            $clubAlias = 'c';
        }
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $orgAlias)) {
            $orgAlias = 'o';
        }
        $cFed = self::clubFederacionCodigoSqlExpr($pdo, $clubAlias);
        $oCan = "COALESCE(NULLIF({$orgAlias}.cod_org, 0), NULLIF({$orgAlias}.entidad, 0))";

        return "({$cFed}) = ({$oCan})";
    }

    /**
     * IDs de clubes activos vinculados a la organización (misma lógica que el dashboard).
     *
     * @param array<string, mixed> $organizacion Fila de organizaciones (id, cod_org, entidad)
     * @return int[]
     */
    public static function clubIdsForOrganizacion(PDO $pdo, array $organizacion, bool $has_cod_org): array
    {
        // $has_cod_org: conservado por compatibilidad de firma; alcance = COALESCE(cod_org, entidad) vs código canónico.
        $canonical = self::canonicalOrgCodigo($organizacion);
        if ($canonical <= 0) {
            return [];
        }
        [$clubWhere, $clubParams] = self::clubScopeSqlAndParams($pdo, $canonical);
        $stmt = $pdo->prepare("SELECT DISTINCT c.id FROM clubes c WHERE {$clubWhere}");
        $stmt->execute($clubParams);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /** WHERE sobre tournaments + parámetros; $activosProximos añade fechator >= CURDATE() */
    private static function torneoScopeSqlAndParams(
        PDO $pdo,
        bool $has_cod_org,
        int $org_pk,
        int $org_ref,
        int $org_entidad,
        bool $activosProximos,
        string $alias = 't'
    ): array {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 't';
        }
        $a = $alias;
        $flags = self::columnFlags($pdo);
        $ids = self::idsRef($org_pk, $org_ref);
        $idParams = count($ids) > 0 ? $ids : [$org_pk];
        $ph = implode(',', array_fill(0, count($idParams), '?'));

        // Incluir torneos sin entidad (0): datos legacy; el acople real es cod_org / club_responsable.
        $entPart = "(? = 0 OR COALESCE({$a}.entidad, 0) = ? OR COALESCE({$a}.entidad, 0) = 0)";
        $entParams = [$org_entidad, $org_entidad];

        $fechaPart = $activosProximos ? " AND {$a}.fechator >= CURDATE()" : '';

        if ($flags['has_tournament_cod_org']) {
            // club_responsable y cod_org en torneos pueden guardar cod_org (p. ej. 13) y no la PK: hay que matchear id y cod_org.
            $legacyClub = "{$a}.club_responsable IN ({$ph})";
            $legacyParams = $idParams;
            if ($has_cod_org) {
                $legacyClub = "({$legacyClub} OR {$a}.club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1))";
                $legacyParams = array_merge($idParams, [$org_ref]);
            }
            $sql = "{$entPart}{$fechaPart} AND {$a}.estatus = 1 AND (
                ({$a}.cod_org IS NOT NULL AND {$a}.cod_org > 0 AND {$a}.cod_org IN ({$ph}))
                OR (
                    ({$a}.cod_org IS NULL OR {$a}.cod_org = 0)
                    AND ({$legacyClub})
                )
            )";
            $params = array_merge($entParams, $idParams, $legacyParams);

            return [$sql, $params];
        }

        if ($has_cod_org) {
            $sql = "{$entPart}{$fechaPart} AND {$a}.estatus = 1 AND (
                {$a}.club_responsable IN ({$ph})
                OR {$a}.club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)
            )";
            $params = array_merge($entParams, $idParams, [$org_ref]);

            return [$sql, $params];
        }

        $sql = "{$entPart}{$fechaPart} AND {$a}.estatus = 1 AND {$a}.club_responsable IN ({$ph})";
        $params = array_merge($entParams, $idParams);

        return [$sql, $params];
    }

    /**
     * Mismo criterio de torneos que el dashboard de organización, para usar en Auth::getTournamentFilterForRole.
     *
     * @param array<string, mixed> $organizacion Fila de organizaciones
     * @return array{0: string, 1: list<mixed>}
     */
    public static function tournamentWhereSqlAndParamsForOrganizacion(
        PDO $pdo,
        array $organizacion,
        bool $has_cod_org,
        string $tableAlias,
        bool $activosProximos = false
    ): array {
        self::columnFlags($pdo);
        $org_pk = (int) ($organizacion['id'] ?? 0);
        if ($org_pk <= 0) {
            return ['1=0', []];
        }
        $org_ref = (int) ($organizacion['cod_org'] ?? 0);
        if ($org_ref <= 0) {
            $org_ref = $org_pk;
        }
        $org_entidad = FvdConfig::entidadTerritorioEfectivaOrganizacion($organizacion);

        return self::torneoScopeSqlAndParams($pdo, $has_cod_org, $org_pk, $org_ref, $org_entidad, $activosProximos, $tableAlias);
    }

    private static function usuarioTerritorioSql(PDO $pdo): string
    {
        $flags = self::columnFlags($pdo);

        return $flags['has_usuario_cod_org']
            ? '(? > 0 AND COALESCE(NULLIF(u.cod_org, 0), COALESCE(u.entidad, 0)) = ?)'
            : '(? > 0 AND COALESCE(u.entidad, 0) = ?)';
    }

    /**
     * Expresión SQL (sin WHERE) para “territorio” del usuario: entidad, u opcionalmente cod_org.
     */
    public static function usuarioTerritorioCoalesceExpr(PDO $pdo, string $alias = 'u'): string
    {
        $flags = self::columnFlags($pdo);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 'u';
        }

        return $flags['has_usuario_cod_org']
            ? "COALESCE(NULLIF({$alias}.cod_org, 0), COALESCE({$alias}.entidad, 0))"
            : "COALESCE({$alias}.entidad, 0)";
    }

    /** Usuario afiliado activo/aprobado (misma regla en listados y KPI). */
    public static function sqlUsuarioAfiliadoAprobado(string $alias = 'u'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 'u';
        }

        return "({$alias}.status = 0 OR {$alias}.status = 'approved' OR {$alias}.status = 1 OR TRIM(CAST({$alias}.status AS CHAR)) = '1')";
    }

    /** Operador / admin_torneo activo. */
    public static function sqlUsuarioStaffAprobado(string $alias = 'u'): string
    {
        return self::sqlUsuarioAfiliadoAprobado($alias);
    }

    /**
     * @param array<string, mixed> $organizacion
     * @return array{
     *   stats: array{clubes:int,torneos:int,torneos_activos:int,afiliados:int,usuarios:int,inscripciones:int},
     *   torneos_recientes: list<array<string,mixed>>
     * }
     */
    public static function snapshot(PDO $pdo, array $organizacion, bool $has_cod_org): array
    {
        self::columnFlags($pdo);

        $org_pk = (int) ($organizacion['id'] ?? 0);
        if ($org_pk <= 0) {
            return [
                'stats' => [
                    'clubes' => 0,
                    'torneos' => 0,
                    'torneos_activos' => 0,
                    'afiliados' => 0,
                    'usuarios' => 0,
                    'inscripciones' => 0,
                ],
                'torneos_recientes' => [],
            ];
        }
        $org_ref = (int) ($organizacion['cod_org'] ?? 0);
        if ($org_ref <= 0) {
            $org_ref = $org_pk;
        }
        $org_entidad = FvdConfig::entidadTerritorioEfectivaOrganizacion($organizacion);

        $uTerr = self::usuarioTerritorioSql($pdo);
        $terrParams = [$org_entidad, $org_entidad];

        $aprob = self::sqlUsuarioAfiliadoAprobado('u');
        $sqlAsociaciones = "
            SELECT COUNT(DISTINCT u.entidad) FROM usuarios u
            WHERE u.role = 'usuario' AND {$aprob}
              AND COALESCE(u.entidad, 0) > 0
              AND ({$uTerr})";
        $stmt = $pdo->prepare($sqlAsociaciones);
        $stmt->execute($terrParams);
        $stats_clubes = (int) $stmt->fetchColumn();

        [$torneoActWhere, $torneoActParams] = self::torneoScopeSqlAndParams($pdo, $has_cod_org, $org_pk, $org_ref, $org_entidad, true);
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE {$torneoActWhere}");
        $stmt->execute($torneoActParams);
        $stats_torneos_activos = (int) $stmt->fetchColumn();

        [$torneoTotWhere, $torneoTotParams] = self::torneoScopeSqlAndParams($pdo, $has_cod_org, $org_pk, $org_ref, $org_entidad, false);
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE {$torneoTotWhere}");
        $stmt->execute($torneoTotParams);
        $stats_torneos_total = (int) $stmt->fetchColumn();

        $uTerr = self::usuarioTerritorioSql($pdo);
        $terrParams = [$org_entidad, $org_entidad];

        $sqlAfiliados = "
            SELECT COUNT(DISTINCT u.id) FROM usuarios u
            WHERE u.role = 'usuario' AND {$aprob}
            AND ({$uTerr})";
        $stmt = $pdo->prepare($sqlAfiliados);
        $stmt->execute($terrParams);
        $stats_afiliados = (int) $stmt->fetchColumn();

        $sqlUsuarios = "
            SELECT COUNT(DISTINCT u.id) FROM usuarios u
            WHERE u.status = 0
            AND ({$uTerr})";
        $stmt = $pdo->prepare($sqlUsuarios);
        $stmt->execute($terrParams);
        $stats_usuarios = (int) $stmt->fetchColumn();

        $whereInsc = InscritosHelper::sqlWhereSoloConfirmadoConAlias('i');
        $sqlInsc = "
            SELECT COUNT(DISTINCT i.id) FROM inscritos i
            INNER JOIN tournaments t ON i.torneo_id = t.id
            WHERE {$torneoTotWhere}
              AND {$whereInsc}
        ";
        $stmt = $pdo->prepare($sqlInsc);
        $stmt->execute($torneoTotParams);
        $stats_inscripciones = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT DISTINCT t.id, t.nombre, t.fechator, t.estatus
             FROM tournaments t
             WHERE {$torneoTotWhere}
             ORDER BY t.fechator DESC, t.id DESC
             LIMIT 12"
        );
        $stmt->execute($torneoTotParams);
        $torneos_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'stats' => [
                'clubes' => $stats_clubes,
                'torneos' => $stats_torneos_total,
                'torneos_activos' => $stats_torneos_activos,
                'afiliados' => $stats_afiliados,
                'usuarios' => $stats_usuarios,
                'inscripciones' => $stats_inscripciones,
            ],
            'torneos_recientes' => $torneos_recientes,
        ];
    }

    /**
     * Desglose por sexo de afiliados (misma regla que snapshot: territorio + clubes de la organización).
     *
     * @param array<string, mixed> $organizacion
     * @return array{hombres: int, mujeres: int, sin_genero: int}
     */
    public static function affiliateGenderCounts(PDO $pdo, array $organizacion, bool $has_cod_org): array
    {
        self::columnFlags($pdo);

        $org_pk = (int) ($organizacion['id'] ?? 0);
        if ($org_pk <= 0) {
            return ['hombres' => 0, 'mujeres' => 0, 'sin_genero' => 0];
        }
        $org_ref = (int) ($organizacion['cod_org'] ?? 0);
        if ($org_ref <= 0) {
            $org_ref = $org_pk;
        }
        $org_entidad = FvdConfig::entidadTerritorioEfectivaOrganizacion($organizacion);

        $uTerr = self::usuarioTerritorioSql($pdo);
        $terrParams = [$org_entidad, $org_entidad];

        $aprob = self::sqlUsuarioAfiliadoAprobado('u');
        $sql = "
            SELECT
                SUM(CASE WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) IN ('M', '1') OR u.sexo = 1 THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) IN ('F', '2') OR u.sexo = 2 THEN 1 ELSE 0 END) AS mujeres,
                SUM(CASE
                    WHEN u.sexo IS NULL OR TRIM(COALESCE(CAST(u.sexo AS CHAR), '')) = '' THEN 1
                    WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) NOT IN ('M', 'F', '1', '2') AND u.sexo NOT IN (1, 2) THEN 1
                    ELSE 0
                END) AS sin_genero
            FROM usuarios u
            WHERE u.role = 'usuario' AND {$aprob}
            AND ({$uTerr})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($terrParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'hombres' => (int) ($row['hombres'] ?? 0),
            'mujeres' => (int) ($row['mujeres'] ?? 0),
            'sin_genero' => (int) ($row['sin_genero'] ?? 0),
        ];
    }
}
