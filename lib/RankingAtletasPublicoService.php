<?php
declare(strict_types=1);

require_once __DIR__ . '/ClubHelper.php';
require_once __DIR__ . '/FvdConfig.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/RankingCategoriaFvdHelper.php';

/**
 * Ranking público de atletas por sexo: acumula rendimiento en torneos finalizados
 * visibles (publicar_landing), alineado con datos persistidos en inscritos (ptosrnk, efectividad, posición).
 */
final class RankingAtletasPublicoService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function hasColumnPublicarLanding(): bool
    {
        try {
            $c = $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'publicar_landing'")->fetchAll();

            return ! empty($c);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasColumnCodOrg(): bool
    {
        try {
            $c = $this->pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetchAll();
            return !empty($c);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Algunas BD no tienen tournaments.cod_org; no usar COALESCE(t.cod_org, …) en ese caso. */
    private function hasColumnTournamentsCodOrg(): bool
    {
        try {
            $this->pdo->query('SELECT `cod_org` FROM `tournaments` LIMIT 0');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * En parejas / parejas fijas, unifica ptosrnk entre integrantes de la misma unidad (por si hubiera datos históricos dispares).
     * En equipos (3) cada jugador puede tener ptosrnk distinto (mismos puntos por posición de equipo, ganados individuales).
     *
     * @param list<array<string, mixed>> $filas
     */
    private function normalizarPtosrnkPorUnidad(array &$filas): void
    {
        $grupos = [];
        foreach ($filas as $idx => $row) {
            $mod = (int) ($row['modalidad'] ?? 0);
            if (! in_array($mod, [2, 4], true)) {
                continue;
            }
            $ce = trim((string) ($row['codigo_equipo'] ?? ''));
            if ($ce === '') {
                continue;
            }
            $tid = (int) ($row['torneo_id'] ?? 0);
            $grupos[$tid . '|' . $ce][] = $idx;
        }
        foreach ($grupos as $indices) {
            $maxPt = 0;
            foreach ($indices as $idx) {
                $maxPt = max($maxPt, (int) ($filas[$idx]['ptosrnk'] ?? 0));
            }
            foreach ($indices as $idx) {
                $filas[$idx]['ptosrnk'] = $maxPt;
            }
        }
    }

    /**
     * Filas de participación + torneo para un sexo (M|F).
     *
     * @return list<array<string, mixed>>
     */
    public function filasParticipacionPorSexo(string $sexo, int $organizacionId = 0, string $categoria = RankingCategoriaFvdHelper::ABSOLUTO): array
    {
        $sexo = strtoupper($sexo) === 'F' ? 'F' : 'M';
        $categoria = RankingCategoriaFvdHelper::normalizar($categoria);
        $organizacionId = max(0, $organizacionId);
        $whereCat = RankingCategoriaFvdHelper::sqlFiltroTorneoCategoria($this->pdo, $categoria, 't');
        $whereGenTorneo = RankingCategoriaFvdHelper::sqlFiltroTorneoGenero($this->pdo, $sexo, 't');
        $pub = $this->hasColumnPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';

        $tOrgRef = $this->hasColumnTournamentsCodOrg()
            ? 'COALESCE(t.cod_org, t.club_responsable, 0)'
            : 'COALESCE(t.club_responsable, 0)';

        $whereOrg = '';
        /** @var list<int|float|string> $orgParams */
        $orgParams = [];
        if ($organizacionId > 0) {
            // Solo placeholders posicionales (?): evita HY093 con PDO nativo y nombres duplicados.
            if ($this->hasColumnCodOrg()) {
                $whereOrg = " AND EXISTS (
                        SELECT 1
                        FROM organizaciones ox
                        WHERE (ox.id = ? OR ox.cod_org = ?)
                          AND (ox.id = {$tOrgRef} OR ox.cod_org = {$tOrgRef})
                    )";
                $orgParams = [$organizacionId, $organizacionId];
            } else {
                $whereOrg = " AND {$tOrgRef} = ?";
                $orgParams = [$organizacionId];
            }
        }
        $wEst = InscritosHelper::sqlWhereActivoConAlias('i');
        $ig = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $ie = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $ip = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $ipt = InscritosHelper::sqlExprColumnaNumerica('i.ptosrnk');
        $eg = 'COALESCE(CAST(e.ganados AS SIGNED), 0)';
        $epe = 'COALESCE(CAST(e.perdidos AS SIGNED), 0)';
        $ee = 'COALESCE(CAST(e.efectividad AS SIGNED), 0)';
        $ep = 'COALESCE(CAST(e.puntos AS SIGNED), 0)';
        // Posición mostrada: unidad en parejas (2), equipos (3) y parejas fijas (4).
        $unidadPos = '(t.modalidad IN (2, 3, 4) AND NULLIF(TRIM(i.codigo_equipo), \'\') IS NOT NULL AND e.codigo_equipo IS NOT NULL)';
        // Estadísticas agregadas de tabla equipos solo en parejas / parejas fijas; en equipos (3) se muestran datos individuales.
        $statsEquipo = '(t.modalidad IN (2, 4) AND NULLIF(TRIM(i.codigo_equipo), \'\') IS NOT NULL AND e.codigo_equipo IS NOT NULL)';
        $sql = "
            SELECT
                u.id AS id_usuario,
                COALESCE(NULLIF(TRIM(u.nombre), ''), u.username) AS nombre_atleta,
                u.cedula,
                u.sexo,
                u.fechnac,
                u.entidad AS entidad_id,
                COALESCE(NULLIF(TRIM(c.nombre), ''), '') AS entidad_nombre,
                i.torneo_id,
                i.codigo_equipo,
                t.nombre AS torneo_nombre,
                t.fechator,
                t.modalidad,
                CASE WHEN {$unidadPos}
                    THEN COALESCE(NULLIF(i.clasiequi, 0), NULLIF(CAST(e.posicion AS SIGNED), 0), COALESCE(i.posicion, 0))
                    ELSE COALESCE(i.posicion, 0)
                END AS posicion,
                CASE WHEN {$statsEquipo}
                    THEN {$eg}
                    ELSE {$ig}
                END AS ganados,
                CASE WHEN {$statsEquipo}
                    THEN {$epe}
                    ELSE COALESCE(CAST(i.perdidos AS SIGNED), 0)
                END AS perdidos,
                CASE WHEN {$statsEquipo}
                    THEN {$ee}
                    ELSE {$ie}
                END AS efectividad,
                CASE WHEN {$statsEquipo}
                    THEN {$ep}
                    ELSE {$ip}
                END AS puntos,
                {$ipt} AS ptosrnk
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            INNER JOIN tournaments t ON i.torneo_id = t.id
            LEFT JOIN clubes c ON c.id = u.entidad
            LEFT JOIN equipos e ON e.id_torneo = i.torneo_id AND e.codigo_equipo = i.codigo_equipo AND e.estatus = 0
            WHERE u.sexo = ?
            AND $wEst
            AND t.estatus = 1
            AND COALESCE(t.ranking, 0) = 1
            AND COALESCE(u.entidad, 0) > 0
            AND DATE(t.fechator) < CURDATE()
            {$pub}
            {$whereOrg}
            {$whereCat}
            {$whereGenTorneo}
            ORDER BY u.id ASC, t.fechator DESC
        ";
        $st = $this->pdo->prepare($sql);
        $params = array_merge([$sexo], $orgParams);
        $st->execute($params);

        $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($categoria !== RankingCategoriaFvdHelper::ABSOLUTO) {
            $filas = array_values(array_filter(
                $filas,
                static function (array $row) use ($categoria): bool {
                    return RankingCategoriaFvdHelper::atletaElegibleEnTorneo(
                        $categoria,
                        isset($row['fechnac']) ? (string) $row['fechnac'] : null,
                        isset($row['fechator']) ? (string) $row['fechator'] : null
                    );
                }
            ));
        }
        $this->normalizarPtosrnkPorUnidad($filas);

        return $filas;
    }

    /**
     * @return array{
     *   criterio_orden: string,
     *   atletas: list<array{
     *     rank: int,
     *     id_usuario: int,
     *     nombre: string,
     *     cedula: string,
     *     sexo: string,
     *     torneos_count: int,
     *     total_ptosrnk: int,
     *     total_efectividad: int,
     *     total_ganados: int,
     *     total_puntos: int,
     *     detalle_torneos: list<array<string, mixed>>
     *   }>
     * }
     */
    public function construirRanking(string $sexo, int $organizacionId = 0, string $categoria = RankingCategoriaFvdHelper::ABSOLUTO): array
    {
        $categoria = RankingCategoriaFvdHelper::normalizar($categoria);
        $filas = $this->filasParticipacionPorSexo($sexo, $organizacionId, $categoria);
        /** @var array<int, array{id_usuario: int, nombre: string, cedula: string, sexo: string, entidad_id: int, entidad_nombre: string, torneos: list<array<string, mixed>>, sum_pt: int, sum_ef: int, sum_g: int, sum_pu: int}> $porUsuario */
        $porUsuario = [];
        foreach ($filas as $row) {
            $uid = (int) ($row['id_usuario'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if (! isset($porUsuario[$uid])) {
                $porUsuario[$uid] = [
                    'id_usuario' => $uid,
                    'nombre' => (string) ($row['nombre_atleta'] ?? ''),
                    'cedula' => (string) ($row['cedula'] ?? ''),
                    'sexo' => (string) ($row['sexo'] ?? ''),
                    'entidad_id' => (int) ($row['entidad_id'] ?? 0),
                    'entidad_nombre' => (string) ($row['entidad_nombre'] ?? ''),
                    'torneos' => [],
                    'sum_pt' => 0,
                    'sum_ef' => 0,
                    'sum_g' => 0,
                    'sum_pu' => 0,
                ];
            }
            $pt = (int) ($row['ptosrnk'] ?? 0);
            $ef = (int) ($row['efectividad'] ?? 0);
            $g = (int) ($row['ganados'] ?? 0);
            $pu = (int) ($row['puntos'] ?? 0);
            $porUsuario[$uid]['sum_pt'] += $pt;
            $porUsuario[$uid]['sum_ef'] += $ef;
            $porUsuario[$uid]['sum_g'] += $g;
            $porUsuario[$uid]['sum_pu'] += $pu;
            $porUsuario[$uid]['torneos'][] = [
                'torneo_id' => (int) ($row['torneo_id'] ?? 0),
                'nombre' => (string) ($row['torneo_nombre'] ?? ''),
                'fechator' => (string) ($row['fechator'] ?? ''),
                'modalidad' => (int) ($row['modalidad'] ?? 0),
                'codigo_equipo' => trim((string) ($row['codigo_equipo'] ?? '')),
                'posicion' => (int) ($row['posicion'] ?? 0),
                'ganados' => $g,
                'perdidos' => (int) ($row['perdidos'] ?? 0),
                'efectividad' => $ef,
                'puntos' => $pu,
                'ptosrnk' => $pt,
            ];
        }
        $lista = array_values($porUsuario);
        usort($lista, static function (array $a, array $b): int {
            if ($a['sum_pt'] !== $b['sum_pt']) {
                return $b['sum_pt'] <=> $a['sum_pt'];
            }
            if ($a['sum_ef'] !== $b['sum_ef']) {
                return $b['sum_ef'] <=> $a['sum_ef'];
            }
            if ($a['sum_g'] !== $b['sum_g']) {
                return $b['sum_g'] <=> $a['sum_g'];
            }

            return strcasecmp($a['nombre'], $b['nombre']);
        });
        $out = [];
        $rank = 0;
        foreach ($lista as $item) {
            $rank++;
            $out[] = [
                'rank' => $rank,
                'id_usuario' => $item['id_usuario'],
                'nombre' => $item['nombre'],
                'cedula' => $item['cedula'],
                'sexo' => $item['sexo'],
                'entidad_id' => $item['entidad_id'],
                'asociacion' => ClubHelper::etiquetaAsociacion($item['entidad_id'], $item['entidad_nombre'] !== '' ? $item['entidad_nombre'] : null),
                'torneos_count' => count($item['torneos']),
                'total_ptosrnk' => $item['sum_pt'],
                'total_efectividad' => $item['sum_ef'],
                'total_ganados' => $item['sum_g'],
                'total_puntos' => $item['sum_pu'],
                'detalle_torneos' => $item['torneos'],
            ];
        }

        $catTxt = RankingCategoriaFvdHelper::etiqueta($categoria, $sexo);

        return [
            'criterio_orden' => 'Ranking oficial ' . $catTxt . '. Solo atletas afiliados FVD (con entidad). Suma de ptosrnk por torneo; desempate: efectividad total, partidas ganadas.',
            'atletas' => $out,
        ];
    }

    /**
     * @return array{nombre: string, siglas: string, id: int}
     */
    public function datosEncabezadoOrganizacion(): array
    {
        $id = FvdConfig::ORGANIZACION_ID;
        $nombre = FvdConfig::getOrganizacionNombre();
        $siglas = FvdConfig::ORGANIZACION_SIGLAS;
        try {
            $st = $this->pdo->prepare('SELECT nombre FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
            $st->execute([$id]);
            $nomDb = trim((string) ($st->fetchColumn() ?: ''));
            if ($nomDb !== '') {
                $nombre = $nomDb;
            }
        } catch (Throwable $e) {
        }

        return ['id' => $id, 'nombre' => $nombre, 'siglas' => $siglas];
    }

    public function resolverAnoLectivo(string $sexo, string $categoria = RankingCategoriaFvdHelper::ABSOLUTO): string
    {
        $catalogo = $this->catalogoTorneosRankingPublico($sexo, $categoria);
        $years = [];
        foreach ($catalogo as $t) {
            $f = (string) ($t['fechator'] ?? '');
            if ($f !== '' && preg_match('/^(\d{4})/', $f, $m)) {
                $years[] = (int) $m[1];
            }
        }
        if ($years === []) {
            return (string) (int) date('Y');
        }
        $min = min($years);
        $max = max($years);

        return $min === $max ? (string) $min : $min . '-' . $max;
    }

    public function subtituloRankingNacional(string $sexo, string $categoria = RankingCategoriaFvdHelper::ABSOLUTO): string
    {
        $generoTxt = strtoupper($sexo) === 'F' ? 'Femenino' : 'Masculino';
        $catTxt = RankingCategoriaFvdHelper::etiqueta($categoria, $sexo);
        $ano = $this->resolverAnoLectivo($sexo, $categoria);

        return 'Ranking Nacional — ' . $catTxt . ' — ' . $ano;
    }

    /**
     * @return list<array{torneo_id: int, nombre: string, fechator: string, modalidad: int, estatus: int, genero_requerido: string, campeonato_grupo: string}>
     */
    public function catalogoTorneosRankingPublico(string $sexo, string $categoria = RankingCategoriaFvdHelper::ABSOLUTO): array
    {
        $sexo = strtoupper($sexo) === 'F' ? 'F' : 'M';
        $categoria = RankingCategoriaFvdHelper::normalizar($categoria);
        $cols = RankingCategoriaFvdHelper::columnasTorneo($this->pdo);
        $selGr = $cols['genero_requerido'] ? ', genero_requerido' : ", '' AS genero_requerido";
        $selCg = $cols['campeonato_grupo'] ? ', campeonato_grupo' : ", '' AS campeonato_grupo";
        $pub = $this->hasColumnPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';
        $whereCat = RankingCategoriaFvdHelper::sqlFiltroTorneoCategoria($this->pdo, $categoria, 't');
        $whereGen = RankingCategoriaFvdHelper::sqlFiltroTorneoGenero($this->pdo, $sexo, 't');

        $st = $this->pdo->query(
            "SELECT id AS torneo_id, nombre, fechator, modalidad, estatus{$selGr}{$selCg}
             FROM tournaments t
             WHERE COALESCE(ranking, 0) = 1
               AND t.estatus = 1
               AND DATE(t.fechator) < CURDATE()
               {$pub}
               {$whereCat}
               {$whereGen}
             ORDER BY fechator ASC, id ASC"
        );
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'torneo_id' => (int) ($row['torneo_id'] ?? 0),
                'nombre' => (string) ($row['nombre'] ?? ''),
                'fechator' => (string) ($row['fechator'] ?? ''),
                'modalidad' => (int) ($row['modalidad'] ?? 0),
                'estatus' => (int) ($row['estatus'] ?? 0),
                'genero_requerido' => strtoupper(trim((string) ($row['genero_requerido'] ?? ''))),
                'campeonato_grupo' => trim((string) ($row['campeonato_grupo'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadosPorId
     * @param list<array<string, mixed>> $catalogo
     * @return list<array<string, mixed>>
     */
    private function fusionarDetalleConCatalogo(array $jugadosPorId, array $catalogo): array
    {
        $detalle = [];
        foreach ($catalogo as $meta) {
            $tid = (int) ($meta['torneo_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (isset($jugadosPorId[$tid])) {
                $fila = $jugadosPorId[$tid];
                $fila['campeonato_grupo'] = (string) ($meta['campeonato_grupo'] ?? '');
                $fila['genero_requerido'] = (string) ($meta['genero_requerido'] ?? '');
                $detalle[] = $fila;
                continue;
            }
            $detalle[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($meta['nombre'] ?? ''),
                'fechator' => (string) ($meta['fechator'] ?? ''),
                'modalidad' => (int) ($meta['modalidad'] ?? 0),
                'torneo_estatus' => (int) ($meta['estatus'] ?? 0),
                'campeonato_grupo' => (string) ($meta['campeonato_grupo'] ?? ''),
                'genero_requerido' => (string) ($meta['genero_requerido'] ?? ''),
                'codigo_equipo' => '',
                'clasif' => 0,
                'pg' => 0,
                'pp' => 0,
                'pj' => 0,
                'efec' => 0,
                'tot_pts' => 0,
                'ptosrnk' => 0,
                'participo' => false,
            ];
        }

        return $detalle;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerAtletaPorIdUsuario(
        string $sexo,
        int $idUsuario,
        int $organizacionId = 0,
        string $categoria = RankingCategoriaFvdHelper::ABSOLUTO
    ): ?array {
        $idUsuario = (int) $idUsuario;
        if ($idUsuario <= 0) {
            return null;
        }

        $data = $this->construirRanking($sexo, $organizacionId, $categoria);
        $base = null;
        foreach ($data['atletas'] as $a) {
            if ((int) ($a['id_usuario'] ?? 0) === $idUsuario) {
                $base = $a;
                break;
            }
        }
        if ($base === null) {
            return null;
        }

        $numfvd = 0;
        try {
            $st = $this->pdo->prepare('SELECT numfvd FROM usuarios WHERE id = ? LIMIT 1');
            $st->execute([$idUsuario]);
            $numfvd = (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
        }

        $jugadosPorId = [];
        $sumP = 0;
        foreach ($base['detalle_torneos'] as $t) {
            $tid = (int) ($t['torneo_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $g = (int) ($t['ganados'] ?? 0);
            $p = (int) ($t['perdidos'] ?? 0);
            $sumP += $p;
            $jugadosPorId[$tid] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($t['nombre'] ?? ''),
                'fechator' => (string) ($t['fechator'] ?? ''),
                'modalidad' => (int) ($t['modalidad'] ?? 0),
                'codigo_equipo' => trim((string) ($t['codigo_equipo'] ?? '')),
                'clasif' => (int) ($t['posicion'] ?? 0),
                'pg' => $g,
                'pp' => $p,
                'pj' => $g + $p,
                'efec' => (int) ($t['efectividad'] ?? 0),
                'tot_pts' => (int) ($t['puntos'] ?? 0),
                'ptosrnk' => (int) ($t['ptosrnk'] ?? 0),
                'participo' => true,
            ];
        }

        $catalogo = $this->catalogoTorneosRankingPublico($sexo, $categoria);
        $detalle = $this->fusionarDetalleConCatalogo($jugadosPorId, $catalogo);
        $tj = 0;
        foreach ($detalle as $fila) {
            if (! empty($fila['participo'])) {
                $tj++;
            }
        }

        return [
            'rank' => (int) ($base['rank'] ?? 0),
            'id_usuario' => $idUsuario,
            'numfvd' => $numfvd,
            'nombre' => (string) ($base['nombre'] ?? ''),
            'cedula' => (string) ($base['cedula'] ?? ''),
            'sexo' => (string) ($base['sexo'] ?? ''),
            'asociacion' => (string) ($base['asociacion'] ?? ''),
            'tj' => $tj,
            'pj' => (int) ($base['total_ganados'] ?? 0) + $sumP,
            'pg' => (int) ($base['total_ganados'] ?? 0),
            'pp' => $sumP,
            'total_ptosrnk' => (int) ($base['total_ptosrnk'] ?? 0),
            'total_efectividad' => (int) ($base['total_efectividad'] ?? 0),
            'total_puntos' => (int) ($base['total_puntos'] ?? 0),
            'detalle_torneos' => $detalle,
        ];
    }
}
