<?php

declare(strict_types=1);

/**
 * LandingDataService - Servicio único para datos de torneos en el landing público.
 * Centraliza queries: club_responsable = ID de tabla organizaciones (no clubes).
 * Datos de contacto (nombre, responsable, telefono, email) provienen de organizaciones.
 */
class LandingDataService
{
    private PDO $pdo;
    private bool $hasCodOrgColumn;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->hasCodOrgColumn = $this->detectCodOrgColumn();
    }

    /**
     * Subconsulta: total de inscritos activos (incluye pendiente 0 de inscripción en línea).
     */
    private function sqlSubqueryTotalInscritos(string $torneoIdExpr = 't.id'): string
    {
        require_once __DIR__ . '/InscritosHelper.php';
        $whereActivo = InscritosHelper::sqlWhereActivoConAlias('');

        return "(SELECT COUNT(*) FROM inscritos WHERE torneo_id = {$torneoIdExpr} AND {$whereActivo}) AS total_inscritos";
    }

    private function detectCodOrgColumn(): bool
    {
        try {
            return (bool)$this->pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Enlace club ↔ organización: solo cod_org homologado (no PK de organizaciones).
     */
    private function clubMatchesOrganizacionExpr(string $cAlias = 'c', string $oAlias = 'o'): string
    {
        if ($this->hasCodOrgColumn) {
            return "{$cAlias}.cod_org = COALESCE(NULLIF({$oAlias}.cod_org, 0), NULLIF({$oAlias}.entidad, 0))";
        }

        return "{$cAlias}.cod_org = {$oAlias}.entidad";
    }

    private function orgJoinExpr(string $tAlias = 't', string $oAlias = 'o'): string
    {
        if ($this->hasCodOrgColumn) {
            return "LEFT JOIN organizaciones {$oAlias} ON (({$tAlias}.club_responsable = {$oAlias}.id OR {$tAlias}.club_responsable = {$oAlias}.cod_org) AND {$oAlias}.estatus = 1)";
        }
        return "LEFT JOIN organizaciones {$oAlias} ON {$tAlias}.club_responsable = {$oAlias}.id AND {$oAlias}.estatus = 1";
    }

    /**
     * Verifica si existe la tabla club_photos.
     */
    private function hasClubPhotos(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'club_photos'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /** Subconsulta primera foto y total (solo activas). */
    private function sqlSubqueryFotos(): string
    {
        if (!$this->hasClubPhotos()) {
            return ', NULL AS primera_foto, 0 AS total_fotos';
        }

        return ", (SELECT cp.ruta_imagen FROM club_photos cp WHERE cp.torneo_id = t.id AND (cp.activa = 1 OR cp.activa IS NULL) ORDER BY cp.orden ASC, cp.fecha_subida ASC LIMIT 1) AS primera_foto, (SELECT COUNT(*) FROM club_photos cp2 WHERE cp2.torneo_id = t.id AND (cp2.activa = 1 OR cp2.activa IS NULL)) AS total_fotos";
    }

    /**
     * Verifica si existe la columna publicar_landing en tournaments.
     */
    private function hasPublicarLanding(): bool
    {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'publicar_landing'")->fetchAll();
            return !empty($cols);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Obtiene los próximos eventos (fechator >= CURDATE(), estatus = 1).
     * Usa JOIN con organizaciones (club_responsable = org.id).
     *
     * @param int $limit
     * @return list<array>
     */
    public function getProximosEventos(int $limit = 500): array
    {
        $where_publicar = $this->hasPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';

        $sql = "
            SELECT
                t.*,
                o.nombre as organizacion_nombre,
                o.logo as organizacion_logo,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                o.email as organizacion_email,
                u.nombre as admin_nombre,
                u.username as admin_username,
                u.celular as admin_celular,
                COALESCE(o.entidad, t.entidad, 0) as entidad_torneo,
                " . $this->sqlSubqueryTotalInscritos() . "
                " . $this->sqlSubqueryFotos() . "
            FROM tournaments t
            " . $this->orgJoinExpr('t', 'o') . "
            LEFT JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
            WHERE t.estatus = 1 AND t.fechator >= CURDATE()
            {$where_publicar}
            ORDER BY t.fechator ASC
            LIMIT " . max(1, min($limit, 500));
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getProximosEventos: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene eventos realizados (fechator < CURDATE(), estatus = 1, publicar_landing = 1).
     * JOIN exclusivo con organizaciones (club_responsable = o.id) para nombre, logo y contacto.
     * No usa clubes: los datos del responsable provienen de la tabla organizaciones.
     *
     * @param int $limit
     * @param int|null $anio Filtro por año (opcional)
     * @param int|null $tipo_evento Filtro por es_evento_masivo: 0,1,2,3,4 (opcional)
     * @return list<array>
     */
    public function getEventosRealizados(int $limit = 50, ?int $anio = null, ?int $tipo_evento = null): array
    {
        $where_publicar = $this->hasPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';
        $where_anio = $anio !== null ? ' AND YEAR(t.fechator) = ' . (int)$anio : '';
        $where_tipo = $tipo_evento !== null ? ' AND COALESCE(t.es_evento_masivo, 0) = ' . (int)$tipo_evento : '';

        $subquery_fotos = $this->sqlSubqueryFotos();

        $sql = "
            SELECT
                t.*,
                o.nombre as organizacion_nombre,
                o.logo as organizacion_logo,
                o.responsable as organizacion_responsable,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                o.email as organizacion_email,
                u.nombre as admin_nombre,
                u.username as admin_username,
                u.celular as admin_celular,
                " . $this->sqlSubqueryTotalInscritos() . "
                {$subquery_fotos}
            FROM tournaments t
            " . $this->orgJoinExpr('t', 'o') . "
            LEFT JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
            WHERE t.estatus = 1 AND t.fechator < CURDATE()
            {$where_publicar}{$where_anio}{$where_tipo}
            ORDER BY t.fechator DESC
            LIMIT " . max(1, min($limit, 200));
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$ev) {
                $ev['total_fotos'] = (int)($ev['total_fotos'] ?? 0);
                $ev['primera_foto'] = isset($ev['primera_foto']) ? trim((string)$ev['primera_foto']) : null;
                if ($ev['primera_foto'] === '') {
                    $ev['primera_foto'] = null;
                }
            }
            unset($ev);
            return $rows;
        } catch (Exception $e) {
            error_log('LandingDataService getEventosRealizados: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene próximos eventos filtrados por entidad (organizaciones con esa entidad).
     *
     * @param int $entidad_id
     * @param int $limit
     * @return list<array>
     */
    public function getProximosEventosPorEntidad(int $entidad_id, int $limit = 12): array
    {
        if ($entidad_id <= 0) {
            return [];
        }

        $where_publicar = $this->hasPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';

        $sql = "
            SELECT
                t.*,
                o.nombre as organizacion_nombre,
                o.logo as organizacion_logo,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                o.email as organizacion_email,
                u.nombre as admin_nombre,
                u.username as admin_username,
                u.celular as admin_celular,
                COALESCE(o.entidad, t.entidad, 0) as entidad_torneo,
                " . $this->sqlSubqueryTotalInscritos() . ",
                e.nombre as entidad_nombre
            FROM tournaments t
            " . $this->orgJoinExpr('t', 'o') . "
            LEFT JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
            LEFT JOIN entidad e ON COALESCE(o.entidad, t.entidad) = e.id
            WHERE t.estatus = 1 AND t.fechator >= CURDATE()
              AND (o.entidad = ? OR t.entidad = ?)
              {$where_publicar}
            ORDER BY t.fechator ASC
            LIMIT " . max(1, min($limit, 100));
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$entidad_id, $entidad_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getProximosEventosPorEntidad: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene próximos eventos filtrados por IDs de organización (club_responsable IN org_ids).
     *
     * @param array<int> $org_ids
     * @param int $limit
     * @return list<array>
     */
    public function getProximosEventosPorOrganizaciones(array $org_ids, int $limit = 12): array
    {
        if (empty($org_ids)) {
            return [];
        }

        $org_ids = array_values(array_map('intval', array_filter($org_ids)));
        if (empty($org_ids)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($org_ids), '?'));
        $where_publicar = $this->hasPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';

        $sql = "
            SELECT
                t.*,
                o.nombre as organizacion_nombre,
                o.logo as organizacion_logo,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                o.email as organizacion_email,
                u.nombre as admin_nombre,
                u.username as admin_username,
                u.celular as admin_celular,
                COALESCE(o.entidad, t.entidad, 0) as entidad_torneo,
                " . $this->sqlSubqueryTotalInscritos() . "
            FROM tournaments t
            " . $this->orgJoinExpr('t', 'o') . "
            LEFT JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
            WHERE t.estatus = 1 AND t.fechator >= CURDATE()
              AND t.club_responsable IN ({$ph})
              {$where_publicar}
            ORDER BY t.fechator ASC
            LIMIT " . max(1, min($limit, 100));
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($org_ids);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getProximosEventosPorOrganizaciones: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene IDs de organización para una entidad (orgs cuya entidad = X).
     */
    public function getOrgIdsPorEntidad(int $entidad_id): array
    {
        if ($entidad_id <= 0) {
            return [];
        }
        try {
            if ($this->hasCodOrgColumn) {
                $stmt = $this->pdo->prepare("SELECT COALESCE(NULLIF(cod_org,0), id) FROM organizaciones WHERE entidad = ? AND estatus = 1");
            } else {
                $stmt = $this->pdo->prepare("SELECT id FROM organizaciones WHERE entidad = ? AND estatus = 1");
            }
            $stmt->execute([$entidad_id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtiene cod_org del club del usuario (club.cod_org).
     */
    public function getOrgIdPorClub(int $club_id): ?int
    {
        if ($club_id <= 0) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT cod_org FROM clubes WHERE id = ? AND estatus = 1");
            $stmt->execute([$club_id]);
            $val = $stmt->fetchColumn();
            return $val !== false && $val !== null ? (int)$val : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Obtiene eventos para el calendario (todos los futuros y recientes).
     */
    public function getEventosCalendario(): array
    {
        $where_publicar = $this->hasPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';

        $sql = "
            SELECT
                t.*,
                o.nombre as organizacion_nombre,
                o.logo as organizacion_logo,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                o.email as organizacion_email,
                u.nombre as admin_nombre,
                u.celular as admin_celular,
                " . $this->sqlSubqueryTotalInscritos() . "
            FROM tournaments t
            LEFT JOIN organizaciones o ON t.club_responsable = o.id AND o.estatus = 1
            LEFT JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
            WHERE t.estatus = 1
            {$where_publicar}
            ORDER BY t.fechator ASC";
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getEventosCalendario: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene la columna identificadora de la tabla entidad (id o codigo).
     */
    private function getEntidadIdColumn(): ?string
    {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $f = strtolower($col['Field'] ?? '');
                if ($f === 'id') return 'id';
                if ($f === 'codigo') return 'codigo';
            }
            return isset($cols[0]['Field']) ? $cols[0]['Field'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Obtiene TODAS las entidades para el flujo de registro, con indicador de si tienen organizaciones.
     * Las entidades sin organizaciones se mostrarán inactivas (no seleccionables).
     *
     * @return list<array{id: int, nombre: string, total_organizaciones: int, total_clubes: int, tiene_organizaciones: bool}>
     */
    public function getTodasEntidadesParaRegistro(): array
    {
        try {
            $idCol = $this->getEntidadIdColumn();
            $idColSafe = ($idCol === 'codigo') ? 'codigo' : 'id';

            $stmt = $this->pdo->query("
                SELECT
                    e.{$idColSafe} AS id,
                    e.nombre,
                    COUNT(DISTINCT o.id) AS total_organizaciones,
                    COUNT(DISTINCT c.id) AS total_clubes
                FROM entidad e
                LEFT JOIN organizaciones o ON o.entidad = e.{$idColSafe} AND o.estatus = 1
                LEFT JOIN clubes c ON (" . $this->clubMatchesOrganizacionExpr() . ") AND c.estatus = 1
                GROUP BY e.{$idColSafe}, e.nombre
                ORDER BY e.nombre ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['total_organizaciones'] = (int)($r['total_organizaciones'] ?? 0);
                $r['total_clubes'] = (int)($r['total_clubes'] ?? 0);
                $r['tiene_organizaciones'] = $r['total_organizaciones'] > 0;
            }
            unset($r);
            return $rows;
        } catch (Exception $e) {
            error_log('LandingDataService getTodasEntidadesParaRegistro: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene entidades que tienen organizaciones registradas (para flujo de registro de jugadores).
     * Compatible con tablas entidad que usan 'id' o 'codigo' como identificador.
     *
     * @return list<array{id: int, nombre: string, total_organizaciones: int, total_clubes: int}>
     */
    public function getEntidadesConOrganizacionesRegistro(): array
    {
        try {
            $idCol = $this->getEntidadIdColumn();
            if (!$idCol) {
                $idCol = 'id';
            }
            $idColSafe = $idCol === 'codigo' ? 'codigo' : 'id';

            $stmt = $this->pdo->query("
                SELECT
                    e.{$idColSafe} AS id,
                    e.nombre,
                    COUNT(DISTINCT o.id) as total_organizaciones,
                    COUNT(DISTINCT c.id) as total_clubes
                FROM entidad e
                INNER JOIN organizaciones o ON o.entidad = e.{$idColSafe} AND o.estatus = 1
                LEFT JOIN clubes c ON (" . $this->clubMatchesOrganizacionExpr() . ") AND c.estatus = 1
                GROUP BY e.{$idColSafe}, e.nombre
                HAVING total_organizaciones > 0
                ORDER BY e.nombre ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                return $rows;
            }

            // Fallback: obtener entidades directamente desde organizaciones (por si el JOIN falla)
            $col = $idColSafe === 'codigo' ? 'codigo' : 'id';
            $fallback = $this->pdo->query("
                SELECT
                    o.entidad AS id,
                    COALESCE(
                        (SELECT e2.nombre FROM entidad e2 WHERE e2.{$col} = o.entidad LIMIT 1),
                        CONCAT('Entidad ', o.entidad)
                    ) AS nombre,
                    COUNT(DISTINCT o.id) AS total_organizaciones,
                    COUNT(DISTINCT c.id) AS total_clubes
                FROM organizaciones o
                LEFT JOIN clubes c ON (" . $this->clubMatchesOrganizacionExpr() . ") AND c.estatus = 1
                WHERE o.estatus = 1 AND o.entidad > 0
                GROUP BY o.entidad
                ORDER BY nombre ASC
            ");
            return $fallback->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getEntidadesConOrganizacionesRegistro: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene organizaciones de una entidad (para flujo de registro).
     *
     * @param int $entidad_id
     * @return list<array>
     */
    public function getOrganizacionesPorEntidad(int $entidad_id): array
    {
        if ($entidad_id <= 0) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    o.id,
                    o.nombre,
                    o.logo,
                    o.responsable,
                    o.telefono,
                    o.email,
                    (SELECT COUNT(*) FROM clubes c WHERE (" . $this->clubMatchesOrganizacionExpr() . ") AND c.estatus = 1) as total_clubes,
                    (SELECT COUNT(*) FROM tournaments t
                     WHERE (t.club_responsable = o.id" . ($this->hasCodOrgColumn ? " OR t.club_responsable = o.cod_org" : "") . ") AND t.estatus = 1 AND t.fechator >= CURDATE()) as torneos_activos
                FROM organizaciones o
                WHERE o.entidad = ? AND o.estatus = 1
                ORDER BY o.nombre ASC
            ");
            $stmt->execute([$entidad_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getOrganizacionesPorEntidad: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene clubes de una organización (para flujo de registro).
     *
     * @param int $org_id
     * @return list<array>
     */
    public function getClubesPorOrganizacion(int $org_id): array
    {
        if ($org_id <= 0) {
            return [];
        }
        try {
            $orgRealId = $org_id;
            if ($this->hasCodOrgColumn) {
                $stOrg = $this->pdo->prepare("SELECT id FROM organizaciones WHERE id = ? OR cod_org = ? LIMIT 1");
                $stOrg->execute([$org_id, $org_id]);
                $orgRealId = (int)$stOrg->fetchColumn();
                if ($orgRealId <= 0) {
                    return [];
                }
            }
            $stmt = $this->pdo->prepare("
                SELECT
                    c.id,
                    c.nombre,
                    c.logo,
                    c.delegado,
                    c.telefono,
                    (SELECT COUNT(*) FROM tournaments t
                     WHERE t.club_responsable = ? AND t.estatus = 1 AND t.fechator >= CURDATE()) as torneos_activos
                FROM clubes c
                WHERE c.cod_org = ? AND c.estatus = 1
                ORDER BY c.nombre ASC
            ");
            $stmt->execute([$org_id, $orgRealId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getClubesPorOrganizacion: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene entidades con eventos (para filtros).
     */
    public function getEntidadesConEventos(): array
    {
        try {
            return $this->pdo->query("
                SELECT
                    e.id,
                    e.nombre,
                    COUNT(DISTINCT u.club_id) as total_clubes,
                    COUNT(DISTINCT CASE WHEN t.estatus = 1 AND t.fechator >= CURDATE() THEN t.id END) as total_eventos_futuros,
                    COUNT(DISTINCT CASE WHEN t.estatus = 1 THEN t.id END) as total_eventos_todos
                FROM entidad e
                INNER JOIN usuarios u ON u.entidad = e.id
                LEFT JOIN organizaciones o ON o.admin_user_id = u.id
                LEFT JOIN tournaments t ON t.club_responsable = o.id
                WHERE u.role IN ('admin_club', 'admin_torneo')
                  AND u.club_id IS NOT NULL
                  AND u.club_id > 0
                  AND u.status = 0
                GROUP BY e.id, e.nombre
                HAVING total_clubes > 0
                ORDER BY e.nombre ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('LandingDataService getEntidadesConEventos: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene el podio (1°, 2°, 3°) de un torneo a partir de inscritos ordenados por ptosrnk.
     *
     * @param int $torneo_id
     * @return list<array> [{posicion, nombre, club_nombre}, ...]
     */
    public function getPodioPorTorneo(int $torneo_id): array
    {
        if ($torneo_id <= 0) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    i.posicion,
                    COALESCE(u.nombre, u.username, 'N/A') as nombre,
                    c.nombre as club_nombre
                FROM inscritos i
                LEFT JOIN usuarios u ON i.id_usuario = u.id
                LEFT JOIN clubes c ON i.id_club = c.id
                WHERE i.torneo_id = ? AND (i.estatus IS NULL OR i.estatus = 'confirmado')
                ORDER BY i.ptosrnk DESC, i.efectividad DESC, i.ganados DESC, i.puntos DESC, i.posicion ASC
                LIMIT 3
            ");
            $stmt->execute([$torneo_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $i => $row) {
                $rows[$i]['posicion_display'] = $i + 1;
            }
            return $rows;
        } catch (Exception $e) {
            error_log('LandingDataService getPodioPorTorneo: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fotos recientes de torneos para la sección galería del landing.
     *
     * @return list<array<string, mixed>>
     */
    public function getGaleriaDestacada(int $limit = 12): array
    {
        if (!$this->hasClubPhotos()) {
            return [];
        }
        $limit = max(1, min($limit, 48));
        $sql = "
            SELECT
                cp.id,
                cp.ruta_imagen,
                cp.titulo,
                cp.torneo_id,
                t.nombre AS torneo_nombre,
                t.fechator,
                o.nombre AS organizacion_nombre
            FROM club_photos cp
            INNER JOIN tournaments t ON t.id = cp.torneo_id AND t.estatus = 1
            " . $this->orgJoinExpr('t', 'o') . "
            WHERE (cp.activa = 1 OR cp.activa IS NULL)
            ORDER BY cp.fecha_subida DESC, cp.id DESC
            LIMIT {$limit}
        ";
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('LandingDataService getGaleriaDestacada: ' . $e->getMessage());

            return [];
        }
    }
}
