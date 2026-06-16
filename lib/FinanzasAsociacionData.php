<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/FvdConfig.php';

/**
 * Agregados e historial financiero por código de entidad (asociación).
 * Usa movimiento_torneo (si existe), inscritos, tournaments y reportes_pago_usuarios.
 */
final class FinanzasAsociacionData
{
    private const MOV_TABLA = 'movimiento_torneo';

    /** Moneda de referencia en reportes financieros de asociación. */
    public const MONEDA_REFERENCIA = 'EUR';

    /** Símbolo para importes en pantalla. */
    public const MONEDA_SIMBOLO = '€';

    /** @var array<string, bool|null> */
    private static array $movColumnCache = [];

    /** Estatus en movimiento_torneo: 1 = liquidado administrativamente. */
    public const MOV_ESTATUS_PAGADO = 1;

    public static function tablaExiste(PDO $pdo, string $tabla): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$tabla]);

        return (bool) $st->fetchColumn();
    }

    public static function inscritosTieneEntidadId(PDO $pdo): bool
    {
        $st = $pdo->query("SHOW COLUMNS FROM inscritos LIKE 'entidad_id'");

        return $st && $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function inscritosTieneCreatedAt(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $st = $pdo->query("SHOW COLUMNS FROM inscritos LIKE 'created_at'");
        $cache = $st && (bool) $st->fetch(PDO::FETCH_ASSOC);

        return $cache;
    }

    /**
     * Jugador inscrito pertenece a la entidad (asociación).
     *
     * @return array{sql:string,params:array<int,int>}
     */
    public static function whereInscritoEnEntidad(PDO $pdo, string $aliasInscrito, int $entidadId): array
    {
        if ($entidadId <= 0) {
            return ['1=0', []];
        }
        if (self::inscritosTieneEntidadId($pdo)) {
            return [
                '(' . $aliasInscrito . '.entidad_id = ? OR EXISTS (SELECT 1 FROM usuarios u_e LEFT JOIN clubes c_e ON c_e.id = u_e.club_id AND c_e.estatus = 1 WHERE u_e.id = ' . $aliasInscrito . '.id_usuario AND (u_e.entidad = ? OR c_e.entidad = ?)))',
                [$entidadId, $entidadId, $entidadId],
            ];
        }

        return [
            'EXISTS (SELECT 1 FROM usuarios u_e LEFT JOIN clubes c_e ON c_e.id = u_e.club_id AND c_e.estatus = 1 WHERE u_e.id = ' . $aliasInscrito . '.id_usuario AND (u_e.entidad = ? OR c_e.entidad = ?))',
            [$entidadId, $entidadId],
        ];
    }

    /**
     * Columna de club en movimiento_torneo (id_club o legacy asociacion_id).
     */
    public static function movimientoClubColumn(PDO $pdo): ?string
    {
        $key = 'movimiento_torneo';
        if (array_key_exists($key, self::$movColumnCache)) {
            $v = self::$movColumnCache[$key];

            return $v === false ? null : $v;
        }
        if (!self::tablaExiste($pdo, self::MOV_TABLA)) {
            self::$movColumnCache[$key] = false;

            return null;
        }
        foreach (['id_club', 'asociacion_id'] as $col) {
            try {
                $st = $pdo->query('SHOW COLUMNS FROM `' . self::MOV_TABLA . '` LIKE ' . $pdo->quote($col));
                if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                    self::$movColumnCache[$key] = $col;

                    return $col;
                }
            } catch (Throwable $e) {
                // siguiente candidato
            }
        }
        self::$movColumnCache[$key] = false;

        return null;
    }

    /**
     * Movimiento imputado a la entidad vía club del movimiento o usuario (sin club) con entidad.
     *
     * @return array{sql:string,params:array<int,int>}
     */
    public static function whereMovimientoEnEntidad(PDO $pdo, string $aliasMov = 'm', int $entidadId = 0): array
    {
        if ($entidadId <= 0) {
            return ['1=0', []];
        }
        $m = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMov) ?: 'm';
        $clubCol = self::movimientoClubColumn($pdo);
        if ($clubCol === null) {
            return [
                'EXISTS (SELECT 1 FROM usuarios u_m WHERE u_m.id = ' . $m . '.id_usuario AND u_m.entidad = ?)',
                [$entidadId],
            ];
        }
        $sql = '('
            . 'EXISTS (SELECT 1 FROM clubes c_m WHERE c_m.id = ' . $m . '.' . $clubCol . ' AND c_m.entidad = ?)'
            . ' OR ('
            . $m . '.' . $clubCol . ' IS NULL AND EXISTS (SELECT 1 FROM usuarios u_m WHERE u_m.id = ' . $m . '.id_usuario AND u_m.entidad = ?)'
            . ')'
            . ')';

        return [$sql, [$entidadId, $entidadId]];
    }

    /**
     * Condición ON/JOIN para torneos FVD (sin tournaments.organizacion_id).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function torneoFvdScopeSql(PDO $pdo, int $orgFvdId, string $alias = 't'): array
    {
        if ($orgFvdId <= 0) {
            return ['1=0', []];
        }
        $st = $pdo->prepare('SELECT * FROM organizaciones WHERE id = ? LIMIT 1');
        $st->execute([$orgFvdId]);
        $org = $st->fetch(PDO::FETCH_ASSOC);
        if (!$org) {
            return ['1=0', []];
        }
        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasCodOrg = false;
        }

        return OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
            $pdo,
            $org,
            $hasCodOrg,
            $alias,
            false
        );
    }

    /**
     * @return array{recaudado: float, pendiente: float, registros: int}
     */
    public static function totalesMovimientoConcepto(PDO $pdo, int $entidadId, string $montoSql, int $torneoId = 0): array
    {
        if (!self::tablaExiste($pdo, self::MOV_TABLA) || $entidadId <= 0) {
            return ['recaudado' => 0.0, 'pendiente' => 0.0, 'registros' => 0];
        }
        [$w, $wp] = self::whereMovimientoEnEntidad($pdo, 'm', $entidadId);
        if ($torneoId > 0) {
            $w .= ' AND m.torneo_id = ?';
            $wp[] = $torneoId;
        }
        $sql = "SELECT 
            COALESCE(SUM(CASE WHEN m.estatus = " . self::MOV_ESTATUS_PAGADO . " THEN ($montoSql) ELSE 0 END), 0) AS recaudado,
            COALESCE(SUM(CASE WHEN m.estatus <> " . self::MOV_ESTATUS_PAGADO . " OR m.estatus IS NULL THEN ($montoSql) ELSE 0 END), 0) AS pendiente,
            COUNT(*) AS registros
            FROM " . self::MOV_TABLA . " m
            WHERE ($montoSql) > 0 AND $w";
        $st = $pdo->prepare($sql);
        $st->execute($wp);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'recaudado' => (float) ($row['recaudado'] ?? 0),
            'pendiente' => (float) ($row['pendiente'] ?? 0),
            'registros' => (int) ($row['registros'] ?? 0),
        ];
    }

    /**
     * Inscripciones de la asociación en un torneo (tabla inscritos).
     *
     * @return array{
     *   total: int,
     *   confirmados: int,
     *   pendientes: int,
     *   recaudado: float,
     *   pendiente_monto: float,
     *   costo_unitario: float
     * }
     */
    public static function resumenInscripcionesTorneoAsociacion(
        PDO $pdo,
        int $torneoId,
        int $entidadId,
        int $clubId = 0
    ): array {
        $vacío = [
            'total' => 0,
            'confirmados' => 0,
            'pendientes' => 0,
            'recaudado' => 0.0,
            'pendiente_monto' => 0.0,
            'costo_unitario' => 0.0,
        ];
        if ($torneoId <= 0 || $entidadId <= 0) {
            return $vacío;
        }
        $stT = $pdo->prepare('SELECT costo FROM tournaments WHERE id = ? LIMIT 1');
        $stT->execute([$torneoId]);
        $costo = (float) ($stT->fetchColumn() ?: 0);
        [$wInsc, $pInsc] = self::whereInscritoEnEntidad($pdo, 'i', $entidadId);
        if ($clubId > 0) {
            $wInsc = '(' . $wInsc . ' OR i.id_club = ?)';
            $pInsc[] = $clubId;
        }
        $paid = '(' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i')
            . " OR EXISTS (SELECT 1 FROM reportes_pago_usuarios r WHERE r.inscrito_id = i.id AND r.estatus = 'confirmado'))";
        $sql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ($paid) THEN 1 ELSE 0 END) AS confirmados,
            SUM(CASE WHEN NOT ($paid) AND " . InscritosHelper::sqlWhereActivoConAlias('i') . ' THEN 1 ELSE 0 END) AS pendientes
            FROM inscritos i
            WHERE i.torneo_id = ? AND ' . $wInsc;
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$torneoId], $pInsc));
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $confirmados = (int) ($row['confirmados'] ?? 0);
        $pendientes = (int) ($row['pendientes'] ?? 0);

        return [
            'total' => $total,
            'confirmados' => $confirmados,
            'pendientes' => $pendientes,
            'recaudado' => $confirmados * $costo,
            'pendiente_monto' => $pendientes * $costo,
            'costo_unitario' => $costo,
        ];
    }

    /**
     * Detalle de inscripciones de la asociación en un torneo (estado de cuenta).
     *
     * @return list<array{fecha:string,atleta_club:string,concepto:string,monto:float,estatus:string}>
     */
    public static function historialInscripcionesTorneoAsociacion(
        PDO $pdo,
        int $torneoId,
        int $entidadId,
        int $clubId = 0,
        int $limite = 200
    ): array {
        if ($torneoId <= 0 || $entidadId <= 0) {
            return [];
        }
        $limite = max(20, min(500, $limite));
        $stT = $pdo->prepare('SELECT nombre, costo FROM tournaments WHERE id = ? LIMIT 1');
        $stT->execute([$torneoId]);
        $tor = $stT->fetch(PDO::FETCH_ASSOC) ?: [];
        $costo = (float) ($tor['costo'] ?? 0);
        $torNombre = trim((string) ($tor['nombre'] ?? 'Torneo'));
        [$wInsc, $pInsc] = self::whereInscritoEnEntidad($pdo, 'i', $entidadId);
        if ($clubId > 0) {
            $wInsc = '(' . $wInsc . ' OR i.id_club = ?)';
            $pInsc[] = $clubId;
        }
        $paid = '(' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i')
            . " OR EXISTS (SELECT 1 FROM reportes_pago_usuarios r WHERE r.inscrito_id = i.id AND r.estatus = 'confirmado'))";
        $sql = "SELECT i.id, i.fecha_inscripcion, i.estatus,
                       COALESCE(u.nombre, u.cedula, '') AS atleta,
                       COALESCE(c.nombre, '') AS club_nombre,
                       ($paid) AS pagado
                FROM inscritos i
                LEFT JOIN usuarios u ON u.id = i.id_usuario
                LEFT JOIN clubes c ON c.id = i.id_club
                WHERE i.torneo_id = ? AND $wInsc
                ORDER BY COALESCE(i.fecha_inscripcion, CAST(i.id AS CHAR)) DESC, i.id DESC
                LIMIT " . (int) $limite;
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$torneoId], $pInsc));
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $pagado = !empty($r['pagado']);
            $fecha = $r['fecha_inscripcion'] ?? '';
            $rows[] = [
                'fecha' => self::fmtFecha((string) $fecha),
                'atleta_club' => self::fmtAtletaClub((string) ($r['atleta'] ?? ''), (string) ($r['club_nombre'] ?? '')),
                'concepto' => 'Inscripción · ' . $torNombre,
                'monto' => $costo,
                'estatus' => $pagado ? 'Pagado' : 'Pendiente',
            ];
        }

        return $rows;
    }

    /**
     * Inscripciones a torneos de la organización FVD: costo por fila inscritos.
     *
     * @return array{recaudado: float, pendiente: float, inscripciones: int}
     */
    public static function totalesInscripcionesTorneoFvd(PDO $pdo, int $entidadId, int $organizacionFvdId): array
    {
        if ($entidadId <= 0 || $organizacionFvdId <= 0) {
            return ['recaudado' => 0.0, 'pendiente' => 0.0, 'inscripciones' => 0];
        }
        [$wInsc, $pInsc] = self::whereInscritoEnEntidad($pdo, 'i', $entidadId);
        [$wTorneo, $pTorneo] = self::torneoFvdScopeSql($pdo, $organizacionFvdId, 't');
        $paid = '(' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . " OR EXISTS (SELECT 1 FROM reportes_pago_usuarios r WHERE r.inscrito_id = i.id AND r.estatus = 'confirmado'))";
        $sql = "SELECT 
            COALESCE(SUM(CASE WHEN ($paid) THEN CAST(t.costo AS DECIMAL(12,2)) ELSE 0 END), 0) AS recaudado,
            COALESCE(SUM(CASE WHEN NOT ($paid) AND " . InscritosHelper::sqlWhereActivoConAlias('i') . " THEN CAST(t.costo AS DECIMAL(12,2)) ELSE 0 END), 0) AS pendiente,
            COUNT(*) AS inscripciones
            FROM inscritos i
            INNER JOIN tournaments t ON t.id = i.torneo_id AND ($wTorneo)
            WHERE $wInsc";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($pTorneo, $pInsc));
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'recaudado' => (float) ($row['recaudado'] ?? 0),
            'pendiente' => (float) ($row['pendiente'] ?? 0),
            'inscripciones' => (int) ($row['inscripciones'] ?? 0),
        ];
    }

    /**
     * @return list<array{fecha:string,atleta_club:string,concepto:string,monto:float,estatus:string}>
     */
    public static function historialTransacciones(PDO $pdo, int $entidadId, int $organizacionFvdId, int $limite = 400): array
    {
        if ($entidadId <= 0) {
            return [];
        }
        $limite = max(50, min(800, $limite));
        $rows = [];

        if (self::tablaExiste($pdo, self::MOV_TABLA)) {
            $clubColMov = self::movimientoClubColumn($pdo);
            [$wM, $pM] = self::whereMovimientoEnEntidad($pdo, 'm', $entidadId);
            $joinClub = $clubColMov !== null
                ? 'LEFT JOIN clubes c ON c.id = m.' . $clubColMov
                : 'LEFT JOIN clubes c ON 1=0';
            $sqlM = "
                SELECT m.created_at AS fecha,
                       COALESCE(u.nombre, m.cedula) AS atleta,
                       COALESCE(c.nombre, '') AS club_nombre,
                       (m.afiliacion + m.anualidad + m.traspaso + m.carnet + m.inscripcion) AS monto,
                       m.afiliacion, m.anualidad, m.traspaso, m.carnet, m.inscripcion,
                       m.estatus AS mov_estatus
                FROM " . self::MOV_TABLA . " m
                LEFT JOIN usuarios u ON u.id = m.id_usuario
                {$joinClub}
                WHERE $wM
                  AND (m.afiliacion > 0 OR m.anualidad > 0 OR m.traspaso > 0 OR m.carnet > 0 OR m.inscripcion > 0)
            ";
            $st = $pdo->prepare($sqlM);
            $st->execute($pM);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $concepto = self::etiquetarConceptoMovimiento($r);
                $monto = (float) ($r['monto'] ?? 0);
                if ($monto <= 0) {
                    continue;
                }
                $est = ((int) ($r['mov_estatus'] ?? 0)) === self::MOV_ESTATUS_PAGADO ? 'Pagado' : 'Pendiente';
                $rows[] = [
                    'fecha' => self::fmtFecha($r['fecha'] ?? ''),
                    'atleta_club' => self::fmtAtletaClub($r['atleta'] ?? '', $r['club_nombre'] ?? ''),
                    'concepto' => $concepto,
                    'monto' => $monto,
                    'estatus' => $est,
                    '_ts' => strtotime((string) ($r['fecha'] ?? '')) ?: 0,
                ];
            }
        }

        if (self::tablaExiste($pdo, 'reportes_pago_usuarios') && $organizacionFvdId > 0) {
            [$wInsc, $pInsc] = self::whereInscritoEnEntidad($pdo, 'i', $entidadId);
            [$wTorneo, $pTorneo] = self::torneoFvdScopeSql($pdo, $organizacionFvdId, 't');
            $sqlR = "
                SELECT COALESCE(r.created_at, TIMESTAMP(r.fecha, r.hora), TIMESTAMP(r.fecha, '00:00:00')) AS fecha,
                       COALESCE(u.nombre, u.cedula) AS atleta,
                       COALESCE(c.nombre, '') AS club_nombre,
                       CAST(r.monto AS DECIMAL(12,2)) AS monto,
                       r.estatus AS rep_estatus
                FROM reportes_pago_usuarios r
                INNER JOIN inscritos i ON i.id = r.inscrito_id
                INNER JOIN usuarios u ON u.id = i.id_usuario
                LEFT JOIN clubes c ON c.id = i.id_club
                INNER JOIN tournaments t ON t.id = i.torneo_id AND ($wTorneo)
                WHERE $wInsc
            ";
            $st = $pdo->prepare($sqlR);
            $st->execute(array_merge($pTorneo, $pInsc));
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $estRaw = (string) ($r['rep_estatus'] ?? 'pendiente');
                $est = $estRaw === 'confirmado' ? 'Pagado' : ($estRaw === 'rechazado' ? 'Rechazado' : 'Pendiente');
                $rows[] = [
                    'fecha' => self::fmtFecha($r['fecha'] ?? ''),
                    'atleta_club' => self::fmtAtletaClub($r['atleta'] ?? '', $r['club_nombre'] ?? ''),
                    'concepto' => 'Inscripción (reporte de pago)',
                    'monto' => (float) ($r['monto'] ?? 0),
                    'estatus' => $est,
                    '_ts' => strtotime((string) ($r['fecha'] ?? '')) ?: 0,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['_ts'] <=> $a['_ts']) ?: strcmp($b['fecha'], $a['fecha']);
        });
        $rows = array_slice($rows, 0, $limite);
        foreach ($rows as &$r) {
            unset($r['_ts']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Detalle de movimientos por concepto (afiliacion, traspaso, carnet, inscripcion_mov).
     *
     * @return list<array{fecha:string,atleta_club:string,concepto:string,monto:float,estatus:string}>
     */
    public static function historialMovimientoPorConcepto(
        PDO $pdo,
        int $entidadId,
        string $concepto,
        int $torneoId = 0,
        int $limite = 400
    ): array {
        if ($entidadId <= 0 || !self::tablaExiste($pdo, self::MOV_TABLA)) {
            return [];
        }
        $concepto = strtolower(trim($concepto));
        if ($concepto === 'afiliacion') {
            $montoSql = '(m.afiliacion + m.anualidad)';
        } elseif ($concepto === 'traspaso') {
            $montoSql = 'm.traspaso';
        } elseif ($concepto === 'carnet') {
            $montoSql = 'm.carnet';
        } elseif ($concepto === 'inscripcion_mov') {
            $montoSql = 'm.inscripcion';
        } else {
            $montoSql = '';
        }
        if ($montoSql === '') {
            return [];
        }
        $limite = max(20, min(800, $limite));
        $clubColMov = self::movimientoClubColumn($pdo);
        [$wM, $pM] = self::whereMovimientoEnEntidad($pdo, 'm', $entidadId);
        if ($torneoId > 0) {
            $wM .= ' AND m.torneo_id = ?';
            $pM[] = $torneoId;
        }
        $joinClub = $clubColMov !== null
            ? 'LEFT JOIN clubes c ON c.id = m.' . $clubColMov
            : 'LEFT JOIN clubes c ON 1=0';
        $sqlM = "
            SELECT m.created_at AS fecha,
                   COALESCE(u.nombre, m.cedula) AS atleta,
                   COALESCE(c.nombre, '') AS club_nombre,
                   ($montoSql) AS monto,
                   m.afiliacion, m.anualidad, m.traspaso, m.carnet, m.inscripcion,
                   m.estatus AS mov_estatus
            FROM " . self::MOV_TABLA . " m
            LEFT JOIN usuarios u ON u.id = m.id_usuario
            {$joinClub}
            WHERE $wM AND ($montoSql) > 0
            ORDER BY COALESCE(m.created_at, FROM_UNIXTIME(0)) DESC, m.id DESC
            LIMIT " . (int) $limite;
        $st = $pdo->prepare($sqlM);
        $st->execute($pM);
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $monto = (float) ($r['monto'] ?? 0);
            if ($monto <= 0) {
                continue;
            }
            $est = ((int) ($r['mov_estatus'] ?? 0)) === self::MOV_ESTATUS_PAGADO ? 'Pagado' : 'Pendiente';
            $rows[] = [
                'fecha' => self::fmtFecha($r['fecha'] ?? ''),
                'atleta_club' => self::fmtAtletaClub($r['atleta'] ?? '', $r['club_nombre'] ?? ''),
                'concepto' => self::etiquetarConceptoMovimiento($r),
                'monto' => $monto,
                'estatus' => $est,
            ];
        }

        return $rows;
    }

    /**
     * Reportes de pago de inscripciones (tabla reportes_pago_usuarios) del torneo para la asociación.
     *
     * @return list<array{fecha:string,atleta_club:string,concepto:string,monto:float,estatus:string}>
     */
    public static function historialReportesPagoTorneoAsociacion(
        PDO $pdo,
        int $torneoId,
        int $entidadId,
        int $clubId = 0,
        int $limite = 400
    ): array {
        if ($torneoId <= 0 || $entidadId <= 0 || !self::tablaExiste($pdo, 'reportes_pago_usuarios')) {
            return [];
        }
        $limite = max(20, min(500, $limite));
        [$wInsc, $pInsc] = self::whereInscritoEnEntidad($pdo, 'i', $entidadId);
        if ($clubId > 0) {
            $wInsc = '(' . $wInsc . ' OR i.id_club = ?)';
            $pInsc[] = $clubId;
        }
        $sql = "SELECT COALESCE(r.created_at, TIMESTAMP(r.fecha, r.hora), TIMESTAMP(r.fecha, '00:00:00')) AS fecha,
                       COALESCE(u.nombre, u.cedula, '') AS atleta,
                       COALESCE(c.nombre, '') AS club_nombre,
                       CAST(r.monto AS DECIMAL(12,2)) AS monto,
                       r.estatus AS rep_estatus,
                       t.nombre AS torneo_nombre
                FROM reportes_pago_usuarios r
                INNER JOIN inscritos i ON i.id = r.inscrito_id
                INNER JOIN tournaments t ON t.id = i.torneo_id
                LEFT JOIN usuarios u ON u.id = i.id_usuario
                LEFT JOIN clubes c ON c.id = i.id_club
                WHERE i.torneo_id = ? AND $wInsc
                ORDER BY fecha DESC, r.id DESC
                LIMIT " . (int) $limite;
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$torneoId], $pInsc));
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $estRaw = (string) ($r['rep_estatus'] ?? 'pendiente');
            $est = $estRaw === 'confirmado' ? 'Pagado' : ($estRaw === 'rechazado' ? 'Rechazado' : 'Pendiente');
            $torNombre = trim((string) ($r['torneo_nombre'] ?? 'Torneo'));
            $rows[] = [
                'fecha' => self::fmtFecha((string) ($r['fecha'] ?? '')),
                'atleta_club' => self::fmtAtletaClub((string) ($r['atleta'] ?? ''), (string) ($r['club_nombre'] ?? '')),
                'concepto' => 'Reporte de pago · ' . $torNombre,
                'monto' => (float) ($r['monto'] ?? 0),
                'estatus' => $est,
            ];
        }

        return $rows;
    }

    public static function formatearImporte(float $monto): string
    {
        return self::MONEDA_SIMBOLO . number_format($monto, 2, ',', '.');
    }

    /**
     * @return list<array{id:int,nombre:string}>
     */
    public static function listarEntidadesConClubes(PDO $pdo): array
    {
        $st = $pdo->query("
            SELECT DISTINCT e.id, e.nombre
            FROM entidad e
            INNER JOIN clubes c ON c.entidad = e.id AND c.estatus = 1
            ORDER BY e.nombre ASC
        ");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if ($rows !== []) {
            return $rows;
        }
        $st2 = $pdo->query('SELECT id, nombre FROM entidad ORDER BY nombre ASC');

        return $st2 ? ($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function entidadExiste(PDO $pdo, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $st = $pdo->prepare('SELECT 1 FROM entidad WHERE id = ? LIMIT 1');
        $st->execute([$id]);

        return (bool) $st->fetchColumn();
    }

    private static function fmtFecha(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts ? date('d/m/Y H:i', $ts) : $raw;
    }

    private static function fmtAtletaClub(string $atleta, string $club): string
    {
        $atleta = trim($atleta);
        $club = trim($club);
        if ($club !== '') {
            return $atleta !== '' ? ($atleta . ' · ' . $club) : $club;
        }

        return $atleta !== '' ? $atleta : '—';
    }

    /**
     * @param array<string,mixed> $r fila movimiento_torneo
     */
    private static function etiquetarConceptoMovimiento(array $r): string
    {
        $parts = [];
        if ((int) ($r['afiliacion'] ?? 0) > 0) {
            $parts[] = 'Afiliación';
        }
        if ((int) ($r['anualidad'] ?? 0) > 0) {
            $parts[] = 'Anualidad';
        }
        if ((int) ($r['traspaso'] ?? 0) > 0) {
            $parts[] = 'Traspaso';
        }
        if ((int) ($r['carnet'] ?? 0) > 0) {
            $parts[] = 'Carnet';
        }
        if ((int) ($r['inscripcion'] ?? 0) > 0) {
            $parts[] = 'Inscripción (mov.)';
        }

        return $parts !== [] ? implode(' + ', $parts) : 'Movimiento';
    }
}
