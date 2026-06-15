<?php
declare(strict_types=1);

require_once __DIR__ . '/ClubHelper.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/NumfvdHelper.php';

/**
 * Ranking acumulado por NUMFVD: procesa torneos finalizados con ranking activado,
 * agrega TJ/PJ/PG/PP y suma de ptosrnk; permite sincronizar usuarios.posi_rnk.
 */
final class RankingNumfvdAdminService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
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
     * Participación por torneo para un sexo (todos los torneos con ranking, sin filtro de organización).
     *
     * @return list<array<string, mixed>>
     */
    private static ?bool $tieneGeneroRequerido = null;

    private function tieneColumnaGeneroRequerido(): bool
    {
        if (self::$tieneGeneroRequerido !== null) {
            return self::$tieneGeneroRequerido;
        }
        try {
            self::$tieneGeneroRequerido = (bool) $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'genero_requerido'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$tieneGeneroRequerido = false;
        }

        return self::$tieneGeneroRequerido;
    }

    private function normalizarSexo(string $sexo): string
    {
        return strtoupper($sexo) === 'F' ? 'F' : 'M';
    }

    /**
     * Campeonatos por género (genero_requerido M/F) o por nombre (MASCULINO/FEMENINO).
     * Torneos abiertos (sin restricción) aplican a ambos géneros.
     */
    private function sqlWhereTorneoGenero(string $sexo, string $alias = 't'): string
    {
        $sexo = $this->normalizarSexo($sexo);
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 't';

        if ($this->tieneColumnaGeneroRequerido()) {
            return " AND (
                NULLIF(TRIM({$a}.genero_requerido), '') IS NULL
                OR UPPER(TRIM({$a}.genero_requerido)) = '{$sexo}'
            )";
        }

        if ($sexo === 'F') {
            return " AND (
                (UPPER({$a}.nombre) NOT LIKE '%MASCULINO%' AND UPPER({$a}.nombre) NOT LIKE '%FEMENINO%')
                OR UPPER({$a}.nombre) LIKE '%FEMENINO%'
            )";
        }

        return " AND (
            (UPPER({$a}.nombre) NOT LIKE '%MASCULINO%' AND UPPER({$a}.nombre) NOT LIKE '%FEMENINO%')
            OR UPPER({$a}.nombre) LIKE '%MASCULINO%'
        )";
    }

    /**
     * @return array{nombre: string, siglas: string, id: int}
     */
    public function datosEncabezadoOrganizacion(): array
    {
        $id = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_ID : 1;
        $nombre = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_NOMBRE : 'Federación Venezolana de Dominó';
        $siglas = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_SIGLAS : 'FVD';
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

    /**
     * Año lectivo según fechas de torneos con ranking del género (ej. 2026 o 2025-2026).
     */
    public function resolverAnoLectivo(string $sexo): string
    {
        $catalogo = $this->catalogoTorneosRanking($sexo);
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

    public function subtituloRankingNacional(string $sexo): string
    {
        $sexo = $this->normalizarSexo($sexo);
        $generoTxt = $sexo === 'F' ? 'Femenino' : 'Masculino';

        return 'Ranking Nacional — ' . $generoTxt . ' ' . $this->resolverAnoLectivo($sexo);
    }

    /**
     * Catálogo de torneos con ranking activado, filtrado por género del ranking (campeonatos M/F).
     *
     * @return list<array{torneo_id: int, nombre: string, fechator: string, modalidad: int, estatus: int, genero_requerido: string, campeonato_grupo: string}>
     */
    public function catalogoTorneosRanking(string $sexo): array
    {
        $sexo = $this->normalizarSexo($sexo);
        $selGr = $this->tieneColumnaGeneroRequerido() ? ', genero_requerido' : ", '' AS genero_requerido";
        $tieneGrupo = false;
        try {
            $tieneGrupo = (bool) $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'campeonato_grupo'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }
        $selCg = $tieneGrupo ? ', campeonato_grupo' : ", '' AS campeonato_grupo";
        $whereGen = $this->sqlWhereTorneoGenero($sexo, 't');

        $st = $this->pdo->query(
            "SELECT id AS torneo_id, nombre, fechator, modalidad, estatus{$selGr}{$selCg}
             FROM tournaments t
             WHERE COALESCE(ranking, 0) = 1
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

    public function filasParticipacionPorSexo(string $sexo): array
    {
        $sexo = $this->normalizarSexo($sexo);
        $whereGen = $this->sqlWhereTorneoGenero($sexo, 't');

        $exprNf = NumfvdHelper::sqlExprNumfvdInscrito('i', $this->pdo, 'u');
        $wEst = InscritosHelper::sqlWhereActivoConAlias('i');
        $ig = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $ie = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $ip = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $ipt = InscritosHelper::sqlExprColumnaNumerica('i.ptosrnk');
        $eg = 'COALESCE(CAST(e.ganados AS SIGNED), 0)';
        $epe = 'COALESCE(CAST(e.perdidos AS SIGNED), 0)';
        $ee = 'COALESCE(CAST(e.efectividad AS SIGNED), 0)';
        $ep = 'COALESCE(CAST(e.puntos AS SIGNED), 0)';
        // Posición: unidad en parejas (2), equipos (3) y parejas fijas (4).
        $unidadPos = '(t.modalidad IN (2, 3, 4) AND NULLIF(TRIM(i.codigo_equipo), \'\') IS NOT NULL AND e.codigo_equipo IS NOT NULL)';
        // PG/PP/EFEC/puntos de tabla equipos solo en parejas y parejas fijas; en equipos (3) stats individuales.
        $statsEquipo = '(t.modalidad IN (2, 4) AND NULLIF(TRIM(i.codigo_equipo), \'\') IS NOT NULL AND e.codigo_equipo IS NOT NULL)';

        $sql = "
            SELECT
                u.id AS id_usuario,
                {$exprNf} AS numfvd,
                COALESCE(NULLIF(TRIM(u.nombre), ''), u.username) AS nombre_atleta,
                u.cedula,
                u.sexo,
                u.entidad AS entidad_id,
                COALESCE(NULLIF(TRIM(c.nombre), ''), '') AS entidad_nombre,
                i.torneo_id,
                i.codigo_equipo,
                t.nombre AS torneo_nombre,
                t.fechator,
                t.modalidad,
                t.estatus AS torneo_estatus,
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
            AND COALESCE(t.ranking, 0) = 1
            AND COALESCE(u.entidad, 0) > 0
            {$whereGen}
            AND {$exprNf} > 0
            ORDER BY {$exprNf} ASC, t.fechator DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([$sexo]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->normalizarPtosrnkPorUnidad($filas);

        return $filas;
    }

    /**
     * @return array{
     *   criterio_orden: string,
     *   torneos_procesados: int,
     *   atletas: list<array{
     *     rank: int,
     *     numfvd: int,
     *     id_usuario: int,
     *     nombre: string,
     *     cedula: string,
     *     sexo: string,
     *     tj: int,
     *     pj: int,
     *     pg: int,
     *     pp: int,
     *     total_ptosrnk: int,
     *     total_efectividad: int,
     *     total_puntos: int,
     *     detalle_torneos: list<array<string, mixed>>
     *   }>
     * }
     */
    /**
     * @param array<int, array<string, mixed>> $jugadosPorId
     * @param list<array{torneo_id: int, nombre: string, fechator: string, modalidad: int, estatus: int}> $catalogo
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

    public function construirRanking(string $sexo): array
    {
        $filas = $this->filasParticipacionPorSexo($sexo);
        $catalogo = $this->catalogoTorneosRanking($sexo);
        $torneosIds = [];
        /** @var array<int, array{numfvd: int, id_usuario: int, nombre: string, cedula: string, sexo: string, entidad_id: int, entidad_nombre: string, torneos: array<int, array<string, mixed>>, sum_pt: int, sum_ef: int, sum_g: int, sum_p: int, sum_pu: int}> $porNumfvd */
        $porNumfvd = [];

        foreach ($filas as $row) {
            $nf = (int) ($row['numfvd'] ?? 0);
            if ($nf <= 0) {
                continue;
            }
            $tid = (int) ($row['torneo_id'] ?? 0);
            if ($tid > 0) {
                $torneosIds[$tid] = true;
            }
            if (! isset($porNumfvd[$nf])) {
                $porNumfvd[$nf] = [
                    'numfvd' => $nf,
                    'id_usuario' => (int) ($row['id_usuario'] ?? 0),
                    'nombre' => (string) ($row['nombre_atleta'] ?? ''),
                    'cedula' => (string) ($row['cedula'] ?? ''),
                    'sexo' => (string) ($row['sexo'] ?? ''),
                    'entidad_id' => (int) ($row['entidad_id'] ?? 0),
                    'entidad_nombre' => (string) ($row['entidad_nombre'] ?? ''),
                    'torneos' => [],
                    'sum_pt' => 0,
                    'sum_ef' => 0,
                    'sum_g' => 0,
                    'sum_p' => 0,
                    'sum_pu' => 0,
                ];
            }
            if ($tid > 0 && ! isset($porNumfvd[$nf]['torneos'][$tid])) {
                $g = (int) ($row['ganados'] ?? 0);
                $p = (int) ($row['perdidos'] ?? 0);
                $pt = (int) ($row['ptosrnk'] ?? 0);
                $ef = (int) ($row['efectividad'] ?? 0);
                $pu = (int) ($row['puntos'] ?? 0);
                $porNumfvd[$nf]['torneos'][$tid] = [
                    'torneo_id' => $tid,
                    'nombre' => (string) ($row['torneo_nombre'] ?? ''),
                    'fechator' => (string) ($row['fechator'] ?? ''),
                    'modalidad' => (int) ($row['modalidad'] ?? 0),
                    'torneo_estatus' => (int) ($row['torneo_estatus'] ?? 0),
                    'codigo_equipo' => trim((string) ($row['codigo_equipo'] ?? '')),
                    'clasif' => (int) ($row['posicion'] ?? 0),
                    'pg' => $g,
                    'pp' => $p,
                    'pj' => $g + $p,
                    'efec' => $ef,
                    'tot_pts' => $pu,
                    'ptosrnk' => $pt,
                    'participo' => true,
                ];
                $porNumfvd[$nf]['sum_pt'] += $pt;
                $porNumfvd[$nf]['sum_ef'] += $ef;
                $porNumfvd[$nf]['sum_g'] += $g;
                $porNumfvd[$nf]['sum_p'] += $p;
                $porNumfvd[$nf]['sum_pu'] += $pu;
            }
        }

        $lista = [];
        foreach ($porNumfvd as $item) {
            $detalle = $this->fusionarDetalleConCatalogo($item['torneos'], $catalogo);
            $lista[] = [
                'numfvd' => $item['numfvd'],
                'id_usuario' => $item['id_usuario'],
                'nombre' => $item['nombre'],
                'cedula' => $item['cedula'],
                'sexo' => $item['sexo'],
                'entidad_id' => $item['entidad_id'],
                'asociacion' => ClubHelper::etiquetaAsociacion($item['entidad_id'], $item['entidad_nombre'] !== '' ? $item['entidad_nombre'] : null),
                'tj' => count($item['torneos']),
                'pj' => $item['sum_g'] + $item['sum_p'],
                'pg' => $item['sum_g'],
                'pp' => $item['sum_p'],
                'total_ptosrnk' => $item['sum_pt'],
                'total_efectividad' => $item['sum_ef'],
                'total_puntos' => $item['sum_pu'],
                'detalle_torneos' => $detalle,
            ];
        }

        usort($lista, static function (array $a, array $b): int {
            if ($a['total_ptosrnk'] !== $b['total_ptosrnk']) {
                return $b['total_ptosrnk'] <=> $a['total_ptosrnk'];
            }
            if ($a['total_efectividad'] !== $b['total_efectividad']) {
                return $b['total_efectividad'] <=> $a['total_efectividad'];
            }
            if ($a['pg'] !== $b['pg']) {
                return $b['pg'] <=> $a['pg'];
            }

            return strcasecmp($a['nombre'], $b['nombre']);
        });

        $out = [];
        $rank = 0;
        foreach ($lista as $item) {
            $rank++;
            $out[] = array_merge($item, ['rank' => $rank]);
        }

        return [
            'criterio_orden' => 'Solo atletas afiliados a la organización (usuarios con entidad/asociación FVD). Clasificación por suma de ptosrnk en torneos con ranking = Sí del género. Desempate: efectividad total, partidas ganadas.',
            'torneos_procesados' => count($catalogo),
            'torneos_catalogo' => $catalogo,
            'atletas' => $out,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerAtletaPorNumfvd(string $sexo, int $numfvd): ?array
    {
        $numfvd = (int) $numfvd;
        if ($numfvd <= 0) {
            return null;
        }
        $data = $this->construirRanking($sexo);
        foreach ($data['atletas'] as $a) {
            if ((int) ($a['numfvd'] ?? 0) === $numfvd) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Torneos con flag ranking activado (los que alimentan el acumulado nacional).
     *
     * @return list<array{id: int, nombre: string, fechator: string, estatus: int}>
     */
    public function listarTorneosConRanking(bool $soloFinalizados = false): array
    {
        $where = 'COALESCE(ranking, 0) = 1';
        if ($soloFinalizados) {
            $where .= ' AND estatus = 1 AND DATE(fechator) < CURDATE()';
        }
        $st = $this->pdo->query(
            "SELECT id, nombre, fechator, modalidad, estatus
             FROM tournaments
             WHERE {$where}
             ORDER BY fechator DESC, id DESC"
        );

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Equivalente general a «Actualizar estadísticas» del panel de cada torneo,
     * para todos los torneos con ranking = 1 (finalizados o en curso).
     * Sincroniza partiresul → inscritos y reclasifica ptosrnk.
     *
     * @return array{
     *   ok: bool,
     *   procesados: int,
     *   fallidos: int,
     *   torneos: list<array{id: int, nombre: string, ok: bool, error: string}>,
     *   errores: list<string>
     * }
     */
    public function recalcularEstadisticasTodosTorneosRanking(): array
    {
        $out = [
            'ok' => false,
            'procesados' => 0,
            'fallidos' => 0,
            'torneos' => [],
            'errores' => [],
        ];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $torneos = $this->listarTorneosConRanking(false);
        if ($torneos === []) {
            $out['errores'][] = 'No hay torneos con ranking activado (ranking = 1).';

            return $out;
        }

        $this->cargarFuncionesTorneoGestion();
        if (! function_exists('actualizarEstadisticasInscritos')) {
            $out['errores'][] = 'No se pudo cargar actualizarEstadisticasInscritos desde torneo_gestion.';

            return $out;
        }

        foreach ($torneos as $t) {
            $tid = (int) ($t['id'] ?? 0);
            $nombre = (string) ($t['nombre'] ?? '');
            if ($tid <= 0) {
                continue;
            }
            $fila = ['id' => $tid, 'nombre' => $nombre, 'ok' => false, 'error' => ''];
            try {
                actualizarEstadisticasInscritos($tid, true);
                $fila['ok'] = true;
                $out['procesados']++;
            } catch (Throwable $e) {
                $fila['error'] = $e->getMessage();
                $out['fallidos']++;
                $out['errores'][] = "Torneo #{$tid} ({$nombre}): " . $e->getMessage();
            }
            $out['torneos'][] = $fila;
        }

        $out['ok'] = $out['procesados'] > 0 && $out['fallidos'] === 0;

        return $out;
    }

    private function cargarFuncionesTorneoGestion(): void
    {
        if (function_exists('actualizarEstadisticasInscritos')) {
            return;
        }
        $path = dirname(__DIR__) . '/modules/torneo_gestion.php';
        if (! is_readable($path)) {
            return;
        }
        if (! defined('TORNEO_GESTION_SKIP_AUTH')) {
            define('TORNEO_GESTION_SKIP_AUTH', true);
        }
        if (! defined('TORNEO_GESTION_SKIP_ROUTER')) {
            define('TORNEO_GESTION_SKIP_ROUTER', true);
        }
        require_once $path;
    }

    public function tieneColumnaPosiRnk(): bool
    {
        try {
            return (bool) $this->pdo->query("SHOW COLUMNS FROM usuarios LIKE 'posi_rnk'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Actualiza usuarios.posi_rnk con la posición del ranking acumulado por NUMFVD.
     *
     * @return array{ok: bool, actualizados: int, sin_cambio: int, sin_usuario: int, errores: list<string>}
     */
    public function aplicarPosiRnkDesdeRanking(string $sexo): array
    {
        $out = [
            'ok' => false,
            'actualizados' => 0,
            'sin_cambio' => 0,
            'sin_usuario' => 0,
            'errores' => [],
        ];
        if (! $this->tieneColumnaPosiRnk()) {
            $out['errores'][] = 'La columna usuarios.posi_rnk no existe en esta base de datos.';

            return $out;
        }

        $data = $this->construirRanking($sexo);
        $atletas = $data['atletas'];
        if ($atletas === []) {
            $out['errores'][] = 'No hay atletas en el ranking para actualizar.';

            return $out;
        }

        $sexo = strtoupper($sexo) === 'F' ? 'F' : 'M';
        $stSel = $this->pdo->prepare('SELECT id, posi_rnk FROM usuarios WHERE numfvd = ? AND sexo = ?');
        $stUpd = $this->pdo->prepare('UPDATE usuarios SET posi_rnk = ? WHERE id = ?');

        $this->pdo->beginTransaction();
        try {
            foreach ($atletas as $a) {
                $nf = (int) ($a['numfvd'] ?? 0);
                $nuevoRank = (int) ($a['rank'] ?? 0);
                if ($nf <= 0 || $nuevoRank <= 0) {
                    continue;
                }
                $stSel->execute([$nf, $sexo]);
                $usuarios = $stSel->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($usuarios === []) {
                    $out['sin_usuario']++;
                    continue;
                }
                foreach ($usuarios as $u) {
                    $uid = (int) ($u['id'] ?? 0);
                    $viejo = (int) ($u['posi_rnk'] ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }
                    if ($viejo === $nuevoRank) {
                        $out['sin_cambio']++;
                        continue;
                    }
                    $stUpd->execute([$nuevoRank, $uid]);
                    $out['actualizados']++;
                }
            }
            $this->pdo->commit();
            $out['ok'] = true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $out['errores'][] = $e->getMessage();
        }

        return $out;
    }
}
