<?php
/**
 * Datos agregados para reportes PDF/Excel del módulo de resultados.
 *
 * GFF (ganadas por forfait / tarjeta roja rival): FF y TR comparten la misma contabilidad GFF.
 */
declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/InscritosHelper.php';

final class ResultadosReporteData
{
    public const GENERO_TODOS = 'T';

    /** Normaliza M/F/T desde GET u otros valores. */
    public static function normalizarGeneroQuery(?string $g): ?string
    {
        if ($g === null || $g === '') {
            return null;
        }
        $x = strtoupper(trim($g));
        if (in_array($x, ['T', 'TODOS', 'ALL', 'A', '*'], true) || str_starts_with($x, 'TOD')) {
            return self::GENERO_TODOS;
        }
        if ($x === 'F' || str_starts_with($x, 'F')) {
            return 'F';
        }
        if ($x === 'M' || str_starts_with($x, 'M') || $x === 'H') {
            return 'M';
        }

        return null;
    }

    public static function esFiltroGeneroTodos(string $genero): bool
    {
        return strtoupper(trim($genero)) === self::GENERO_TODOS;
    }

    public static function etiquetaGeneroFiltro(string $genero): string
    {
        if (self::esFiltroGeneroTodos($genero)) {
            return 'Todos';
        }

        return strtoupper(trim($genero)) === 'F' ? 'Femenino' : 'Masculino';
    }

    /** Columna Equipo solo en torneos por equipos (modalidad 3). */
    public static function mostrarColumnaEquipo(int $modalidad): bool
    {
        return $modalidad === 3;
    }

    public static function etiquetaAsociacion(): string
    {
        return 'Asociación';
    }

    public static function etiquetaSinAsociacion(): string
    {
        return 'Sin asociación';
    }

    /** Encabezado columna identificador: parejas → código; resto → ID FVD. */
    public static function etiquetaColumnaIdentificador(bool $esParejas): string
    {
        return $esParejas ? 'Cód. pareja' : 'ID FVD';
    }

    /** Texto público del identificador en filas de clasificación. */
    public static function textoIdentificadorFila(array $fila, bool $esParejas): string
    {
        if ($esParejas) {
            $cod = trim((string) ($fila['codigo_equipo'] ?? ''));

            return $cod !== '' ? $cod : '—';
        }
        if (! class_exists('NumfvdHelper', false)) {
            require_once __DIR__ . '/NumfvdHelper.php';
        }
        $txt = NumfvdHelper::textoMostrar($fila, false);

        return $txt !== '—' ? $txt : '—';
    }

    /**
     * M, F o T (todos) elegido para el reporte.
     */
    public static function generoFiltroDesdeParametro(?string $generoGet): string
    {
        $n = self::normalizarGeneroQuery($generoGet);

        return $n ?? 'T';
    }

    /** @deprecated Usar {@see generoFiltroDesdeParametro}; el parámetro torneo ya no se usa */
    public static function generoFiltroEfectivo(array $torneo, ?string $generoGet): string
    {
        return self::generoFiltroDesdeParametro($generoGet);
    }

    /**
     * M o F del participante en esa inscripción: si existen columnas en la fila procedentes de `inscritos`
     * (p. ej. snapshot), tienen prioridad; si no, `usuarios.sexo` en el JOIN habitual inscritos → usuarios.
     */
    public static function sexoUsuarioFila(array $row): string
    {
        foreach (['sexo_inscripcion', 'sexo_inscrito', 'genero_inscripcion'] as $k) {
            if (! array_key_exists($k, $row)) {
                continue;
            }
            $raw = trim((string) ($row[$k] ?? ''));
            if ($raw === '') {
                continue;
            }
            $m = self::sexoParticipanteDesdeTexto($raw);
            if ($m !== '') {
                return $m;
            }
        }

        return self::sexoParticipanteDesdeTexto((string) ($row['sexo'] ?? ''));
    }

    private static function sexoParticipanteDesdeTexto(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '' || $s === 'O') {
            return '';
        }
        if (isset($s[0]) && $s[0] === 'F') {
            return 'F';
        }
        if ($s === 'H' || (isset($s[0]) && $s[0] === 'M')) {
            return 'M';
        }
        if ($s === '2') {
            return 'F';
        }
        if ($s === '1') {
            return 'M';
        }

        return '';
    }

    /**
     * Deja solo jugadores/parejas homogéneas del género indicado (en parejas mixtas no entra ningún integrante).
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function filtrarFilasClasificacionPorGenero(array $filas, string $generoObjetivo, int $modalidad): array
    {
        if (self::esFiltroGeneroTodos($generoObjetivo)) {
            return $filas;
        }

        $g = strtoupper($generoObjetivo) === 'F' ? 'F' : 'M';
        if (! in_array($modalidad, [2, 4], true)) {
            $out = [];
            foreach ($filas as $r) {
                if (self::sexoUsuarioFila($r) === $g) {
                    $out[] = $r;
                }
            }

            return $out;
        }
        $grupos = [];
        foreach ($filas as $r) {
            $cod = trim((string) ($r['codigo_equipo'] ?? ''));
            if ($cod === '' || $cod === '000-000') {
                if (self::sexoUsuarioFila($r) === $g) {
                    $grupos['__singles'][] = $r;
                }
                continue;
            }
            if (! isset($grupos[$cod])) {
                $grupos[$cod] = [];
            }
            $grupos[$cod][] = $r;
        }
        $out = $grupos['__singles'] ?? [];
        unset($grupos['__singles']);
        foreach ($grupos as $cod => $rows) {
            $sexos = [];
            foreach ($rows as $r) {
                $sx = self::sexoUsuarioFila($r);
                if ($sx !== '') {
                    $sexos[$sx] = true;
                }
            }
            if (count($sexos) !== 1) {
                continue;
            }
            $only = array_key_first($sexos);
            if ($only === $g) {
                foreach ($rows as $r) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /**
     * Mismo criterio que la vista de posiciones en torneo_gestion (activos primero, luego posición global).
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function ordenarFilasComoPosicionesTorneo(array $filas): array
    {
        usort($filas, static function (array $a, array $b): int {
            $ea = (isset($a['estatus']) && ((int) $a['estatus'] === 1 || $a['estatus'] === 'confirmado')) ? 0 : 1;
            $eb = (isset($b['estatus']) && ((int) $b['estatus'] === 1 || $b['estatus'] === 'confirmado')) ? 0 : 1;
            if ($ea !== $eb) {
                return $ea <=> $eb;
            }
            $pa = (int) ($a['posicion'] ?? 0);
            $pb = (int) ($b['posicion'] ?? 0);
            if ($pa === 0) {
                $pa = 999999;
            }
            if ($pb === 0) {
                $pb = 999999;
            }
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $ga = (int) ($a['ganados'] ?? 0);
            $gb = (int) ($b['ganados'] ?? 0);
            if ($ga !== $gb) {
                return $gb <=> $ga;
            }
            $efa = (int) ($a['efectividad'] ?? 0);
            $efb = (int) ($b['efectividad'] ?? 0);
            if ($efa !== $efb) {
                return $efb <=> $efa;
            }
            $pta = (int) ($a['puntos'] ?? 0);
            $ptb = (int) ($b['puntos'] ?? 0);
            if ($pta !== $ptb) {
                return $ptb <=> $pta;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $filas;
    }

    /**
     * Posiciones 1…N dentro del subconjunto filtrado por género.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function reenumerarPosicionMostrada(array $filas): array
    {
        $n = 0;
        foreach ($filas as &$f) {
            $n++;
            $f['posicion'] = $n;
        }
        unset($f);

        return $filas;
    }

    /**
     * Filtra por género (si aplica) y ordena por posición persistida en inscritos.
     * No recalcula ni renumera: la clasificación se cierra al generar la siguiente ronda o finalizar el torneo.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function aplicarRankingPorGenero(array $filas, string $genero, int $modalidad): array
    {
        if (! self::esFiltroGeneroTodos($genero)) {
            $filas = self::filtrarFilasClasificacionPorGenero($filas, $genero, $modalidad);
        }

        return self::ordenarFilasComoPosicionesTorneo($filas);
    }

    /**
     * Fragmentos SQL compartidos para GFF = victorias por FF rival/compañero + TR (tarjeta roja/negra rival/compañero).
     *
     * @return array{joins: string, where_victoria: string, where_incidencia: string}
     */
    public static function sqlPartesConteoGff(): array
    {
        if (! class_exists('InscritosReporteStatsHelper', false)) {
            require_once __DIR__ . '/InscritosReporteStatsHelper.php';
        }
        $wRegPr1 = PartiresulEstatusSql::whereRegistradoUno('pr1');
        $wFf0Pr1 = PartiresulEstatusSql::whereFfCero('pr1');
        $wFfOpp = PartiresulEstatusSql::whereFfUno('pr_oponente');
        $wFfComp = PartiresulEstatusSql::whereFfUno('pr_companero');
        $r1 = InscritosHelper::sqlExprColumnaNumerica('pr1.resultado1');
        $r2 = InscritosHelper::sqlExprColumnaNumerica('pr1.resultado2');
        $ef = InscritosHelper::sqlExprColumnaNumerica('pr1.efectividad');
        $t1 = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr1.tarjeta');
        $tOpp = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr_oponente.tarjeta');
        $tComp = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr_companero.tarjeta');

        $joins = '
            LEFT JOIN partiresul pr_oponente ON pr1.id_torneo = pr_oponente.id_torneo
                AND pr1.partida = pr_oponente.partida
                AND pr1.mesa = pr_oponente.mesa
                AND pr_oponente.id_usuario != pr1.id_usuario
                AND (
                    (pr1.secuencia IN (1, 2) AND pr_oponente.secuencia IN (3, 4)) OR
                    (pr1.secuencia IN (3, 4) AND pr_oponente.secuencia IN (1, 2))
                )
            LEFT JOIN partiresul pr_companero ON pr1.id_torneo = pr_companero.id_torneo
                AND pr1.partida = pr_companero.partida
                AND pr1.mesa = pr_companero.mesa
                AND pr_companero.id_usuario != pr1.id_usuario
                AND (
                    (pr1.secuencia IN (1, 2) AND pr_companero.secuencia IN (1, 2) AND pr_companero.secuencia != pr1.secuencia) OR
                    (pr1.secuencia IN (3, 4) AND pr_companero.secuencia IN (3, 4) AND pr_companero.secuencia != pr1.secuencia)
                )';

        $whereVictoria = "
                AND {$wRegPr1}
                AND {$wFf0Pr1}
                AND {$t1} NOT IN (3, 4)
                AND {$r1} > {$r2}
                AND {$ef} > 0";

        $whereIncidencia = "
                AND (({$wFfOpp}) OR ({$wFfComp}) OR ({$tOpp} IN (3, 4)) OR ({$tComp} IN (3, 4)))";

        return [
            'joins' => $joins,
            'where_victoria' => $whereVictoria,
            'where_incidencia' => $whereIncidencia,
        ];
    }

    /**
     * GFF: partidas ganadas por forfait (FF) o tarjeta roja/negra (TR) del rival o compañero.
     */
    public static function sqlGanadasPorForfaitSubquery(string $iAlias = 'i'): string
    {
        self::validarAliasTabla($iAlias);
        $parts = self::sqlPartesConteoGff();

        return "(
            SELECT COUNT(DISTINCT pr1.partida, pr1.mesa)
            FROM partiresul pr1
            {$parts['joins']}
            WHERE pr1.id_usuario = {$iAlias}.id_usuario
                AND pr1.id_torneo = {$iAlias}.torneo_id
                {$parts['where_victoria']}
                {$parts['where_incidencia']}
        )";
    }

    /** Tarjeta vigente: máximo en partiresul registradas (normalizado FVD), fallback inscritos.tarjeta. */
    public static function sqlTarjetaEfectivaSubquery(string $iAlias = 'i'): string
    {
        self::validarAliasTabla($iAlias);
        $wReg = PartiresulEstatusSql::whereRegistradoUno('pr_tar');
        if (! class_exists('InscritosReporteStatsHelper', false)) {
            require_once __DIR__ . '/InscritosReporteStatsHelper.php';
        }
        $tn = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr_tar.tarjeta');
        $ti = InscritosHelper::sqlExprColumnaNumerica($iAlias . '.tarjeta');

        return "COALESCE(
            (SELECT MAX({$tn}) FROM partiresul pr_tar
             WHERE pr_tar.id_torneo = {$iAlias}.torneo_id
               AND pr_tar.id_usuario = {$iAlias}.id_usuario
               AND {$wReg}),
            {$ti},
            0
        )";
    }

    /** @deprecated Alias: usar {@see sqlGanadasPorForfaitSubquery} */
    public static function sqlGffSubquery(): string
    {
        return self::sqlGanadasPorForfaitSubquery('i');
    }

    private static function validarAliasTabla(string $alias): void
    {
        if ($alias === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Alias de tabla inválido: ' . $alias);
        }
    }

    /**
     * Modalidad parejas (2) o parejas fijas (4): una fila por codigo_equipo con ambos nombres en nombre_completo.
     *
     * @param list<array<string, mixed>> $filas Filas ya ordenadas (p. ej. por posición).
     * @return list<array<string, mixed>>
     */
    public static function colapsarFilasPorPareja(array $filas, PDO $pdo, int $torneoId): array
    {
        $stmtParejas = $pdo->prepare("
            SELECT i.codigo_equipo, u.nombre AS nombre_completo
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ?
              AND i.codigo_equipo IS NOT NULL
              AND TRIM(i.codigo_equipo) != ''
              AND i.codigo_equipo != '000-000'
            ORDER BY i.codigo_equipo ASC, u.nombre ASC
        ");
        $stmtParejas->execute([$torneoId]);
        $nombresPorCodigo = [];
        foreach ($stmtParejas->fetchAll(PDO::FETCH_ASSOC) as $filaPareja) {
            $codigo = trim((string)($filaPareja['codigo_equipo'] ?? ''));
            $nombre = trim((string)($filaPareja['nombre_completo'] ?? ''));
            if ($codigo === '' || $nombre === '') {
                continue;
            }
            if (!isset($nombresPorCodigo[$codigo])) {
                $nombresPorCodigo[$codigo] = [];
            }
            $nombresPorCodigo[$codigo][] = $nombre;
        }

        $vistos = [];
        $salida = [];
        foreach ($filas as $p) {
            $codigo = trim((string)($p['codigo_equipo'] ?? ''));
            if ($codigo === '' || $codigo === '000-000') {
                $salida[] = $p;
                continue;
            }
            if (isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;
            $nombres = array_values(array_unique($nombresPorCodigo[$codigo] ?? []));
            $parejaDisplay = implode(' / ', array_slice($nombres, 0, 2));
            if ($parejaDisplay !== '') {
                $p['nombre_completo'] = $parejaDisplay;
            }
            $p['id_usuario'] = $codigo;
            $salida[] = $p;
        }

        return $salida;
    }

    /**
     * @return array{torneo: array, participantes: array, resumen_clubes: array, equipos: array, rondas: array}
     */
    public static function cargar(PDO $pdo, int $torneoId, array $torneo, ?string $generoPreferencia = null): array
    {
        require_once __DIR__ . '/InscritosReporteStatsHelper.php';
        InscritosReporteStatsHelper::ensureColumnas($pdo);
        $cols = InscritosReporteStatsHelper::expresionesSelectClasificacion('i');

        $sqlParticipantes = "
            SELECT
                i.id,
                i.id_usuario,
                i.torneo_id,
                i.codigo_equipo,
                i.posicion,
                i.ganados,
                i.perdidos,
                i.efectividad,
                i.puntos,
                i.ptosrnk,
                {$cols['gff']},
                i.sancion,
                {$cols['tarjeta']},
                {$cols['partidas_bye']},
                u.nombre AS nombre_completo,
                u.username,
                u.cedula,
                u.sexo,
                c.nombre AS club_nombre,
                c.id AS club_id,
                e.nombre_equipo
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            LEFT JOIN equipos e ON i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo AND e.estatus = 0
            WHERE i.torneo_id = ?
              AND i.estatus != 'retirado'
            ORDER BY
                CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                i.ganados DESC,
                i.efectividad DESC,
                i.puntos DESC
        ";
        $stmt = $pdo->prepare($sqlParticipantes);
        $stmt->execute([$torneoId]);
        $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (class_exists('NumfvdHelper', false) || is_file(__DIR__ . '/NumfvdHelper.php')) {
            require_once __DIR__ . '/NumfvdHelper.php';
            $participantes = NumfvdHelper::enriquecerFilas($participantes);
        }
        foreach ($participantes as &$p) {
            if (empty($p['nombre_equipo']) && !empty($p['codigo_equipo'])) {
                $p['nombre_equipo'] = 'Equipo ' . $p['codigo_equipo'];
            }
        }
        unset($p);

        $modalidad = (int) ($torneo['modalidad'] ?? 0);
        $gen = self::generoFiltroDesdeParametro($generoPreferencia);
        $participantes = self::aplicarRankingPorGenero($participantes, $gen, $modalidad);
        if (in_array($modalidad, [2, 4], true)) {
            $participantes = self::colapsarFilasPorPareja($participantes, $pdo, $torneoId);
        }

        $sqlClubes = "
            SELECT
                COALESCE(c.id, 0) AS club_id,
                COALESCE(c.nombre, 'Sin club') AS club_nombre,
                COUNT(*) AS jugadores,
                SUM(i.ganados) AS sum_ganados,
                SUM(i.perdidos) AS sum_perdidos,
                AVG(i.efectividad) AS avg_efectividad,
                SUM(i.puntos) AS sum_puntos
            FROM inscritos i
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? AND i.estatus != 'retirado'
            GROUP BY COALESCE(c.id, 0), COALESCE(c.nombre, 'Sin club')
            ORDER BY club_nombre
        ";
        $stmtClub = $pdo->prepare($sqlClubes);
        $stmtClub->execute([$torneoId]);
        $resumenClubes = $stmtClub->fetchAll(PDO::FETCH_ASSOC);

        $equipos = [];
        if ((int)($torneo['modalidad'] ?? 0) === 3) {
            $sqlEq = "
                SELECT
                    e.codigo_equipo,
                    e.nombre_equipo,
                    e.posicion AS pos_equipo,
                    e.ganados AS g_eq,
                    e.perdidos AS p_eq,
                    e.efectividad AS ef_eq,
                    e.puntos AS pts_eq
                FROM equipos e
                WHERE e.id_torneo = ? AND e.estatus = 0
                  AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
                ORDER BY
                    CASE WHEN e.posicion IS NULL OR e.posicion = 0 THEN 9999 ELSE e.posicion END,
                    e.ganados DESC
            ";
            $stmtEq = $pdo->prepare($sqlEq);
            $stmtEq->execute([$torneoId]);
            $equipos = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
        }

        $sqlRondas = "
            SELECT partida AS num_ronda,
                   COUNT(DISTINCT mesa) AS mesas,
                   SUM(registrado) AS registros
            FROM partiresul
            WHERE id_torneo = ?
            GROUP BY partida
            ORDER BY partida
        ";
        $stmtR = $pdo->prepare($sqlRondas);
        $stmtR->execute([$torneoId]);
        $rondas = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        return [
            'torneo' => $torneo,
            'participantes' => $participantes,
            'resumen_clubes' => $resumenClubes,
            'equipos' => $equipos,
            'rondas' => $rondas,
        ];
    }

    public static function tarjetaTexto($tarjeta): string
    {
        if (! class_exists('TorneoCampoNumerico', false)) {
            require_once __DIR__ . '/TorneoCampoNumerico.php';
        }
        switch (TorneoCampoNumerico::codigoTarjeta($tarjeta)) {
            case 1:
                return 'Amarilla';
            case 3:
                return 'Roja';
            case 4:
                return 'Negra';
            default:
                return '—';
        }
    }

    /** Letra corta para reportes: A=amarilla, R=roja, N=negra. */
    public static function tarjetaLetraReporte($tarjeta): string
    {
        if (! class_exists('TorneoCampoNumerico', false)) {
            require_once __DIR__ . '/TorneoCampoNumerico.php';
        }
        switch (TorneoCampoNumerico::codigoTarjeta($tarjeta)) {
            case 1:
                return 'A';
            case 3:
                return 'R';
            case 4:
                return 'N';
            default:
                return '';
        }
    }
}
