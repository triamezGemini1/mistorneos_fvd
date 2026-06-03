<?php

declare(strict_types=1);

require_once __DIR__ . '/NumfvdHelper.php';
require_once __DIR__ . '/PartiresulJugadorHelper.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/Core/MesaRepository.php';
require_once __DIR__ . '/Core/TorneoMesaAsignacionResolver.php';

/**
 * Reporte consultable: estructura de mesas por ronda, procedimiento aplicado
 * y marcas de pareja / enfrentamiento repetidos.
 */
final class MesaEstructuraReporteService
{
    /** @var array<int, array<int, int>> */
    private array $conteoPareja = [];

    /** @var array<int, array<int, int>> */
    private array $conteoEnfrenta = [];

    /**
     * @return array{
     *   torneo: array<string, mixed>,
     *   modalidad_etiqueta: string,
     *   rondas_disponibles: list<int>,
     *   ronda_actual: int,
     *   ronda: array<string, mixed>|null,
     *   paginacion: array<string, int>,
     *   orden_clasificacion_criterio: string,
     *   leyenda: array<string, string>
     * }
     */
    public function construirReporte(int $torneoId, PDO $pdo, int $rondaFiltro = 0, int $pagina = 1, int $porPagina = 10): array
    {
        $torneo = $this->cargarTorneo($torneoId, $pdo);
        if ($torneo === null) {
            return [
                'torneo' => [],
                'modalidad_etiqueta' => '',
                'rondas_disponibles' => [],
                'ronda_actual' => 0,
                'ronda' => null,
                'paginacion' => ['pagina' => 1, 'por_pagina' => $porPagina, 'total_mesas' => 0, 'total_paginas' => 0],
                'orden_clasificacion_criterio' => '',
                'leyenda' => $this->leyenda(),
            ];
        }

        $porPagina = max(4, min(40, $porPagina));
        $pagina = max(1, $pagina);
        $totalRondas = max(1, (int) ($torneo['rondas'] ?? 0));
        $modalidad = (int) ($torneo['modalidad'] ?? 0);
        $opciones = (new MesaRepository($pdo))->obtenerOpcionesAsignacionTorneo($torneoId);
        $numerosRonda = $this->numerosRondaGenerados($torneoId, $pdo);

        if ($rondaFiltro <= 0 && $numerosRonda !== []) {
            $rondaFiltro = (int) end($numerosRonda);
        }
        if ($rondaFiltro > 0 && !in_array($rondaFiltro, $numerosRonda, true)) {
            $rondaFiltro = $numerosRonda !== [] ? (int) end($numerosRonda) : 0;
        }

        $this->conteoPareja = [];
        $this->conteoEnfrenta = [];
        foreach ($numerosRonda as $numRonda) {
            if ($numRonda >= $rondaFiltro) {
                break;
            }
            $this->acumularHistorialRonda($torneoId, $numRonda, $pdo);
        }

        $rondaData = null;
        if ($rondaFiltro > 0) {
            $ordenMap = $this->mapaOrdenClasificacion($torneoId, $rondaFiltro, $pdo);
            $mesasRaw = $this->cargarMesasRonda($torneoId, $rondaFiltro, $pdo);
            $mesasReporte = [];
            foreach ($mesasRaw as $numMesa => $jugadoresPorSec) {
                $mesasReporte[] = $this->formatearMesa(
                    $numMesa,
                    $jugadoresPorSec,
                    $rondaFiltro,
                    $this->clasificarZonaMesaR2($jugadoresPorSec, $rondaFiltro),
                    $ordenMap
                );
            }
            $totalMesas = count($mesasReporte);
            $totalPaginas = max(1, (int) ceil($totalMesas / $porPagina));
            if ($pagina > $totalPaginas) {
                $pagina = $totalPaginas;
            }
            $offset = ($pagina - 1) * $porPagina;
            $mesasPagina = array_slice($mesasReporte, $offset, $porPagina);

            $rondaData = [
                'numero' => $rondaFiltro,
                'procedimiento' => self::etiquetaProcedimiento($rondaFiltro, $totalRondas, $opciones, $modalidad),
                'procedimiento_codigo' => self::codigoProcedimiento($rondaFiltro, $totalRondas, $opciones, $modalidad),
                'total_mesas' => $totalMesas,
                'mesas' => $mesasPagina,
                'bye' => $this->cargarByeRonda($torneoId, $rondaFiltro, $pdo),
                'clasificacion_preview' => $this->previewClasificacion($torneoId, $rondaFiltro, $pdo, 12),
            ];
        }

        return [
            'torneo' => $torneo,
            'modalidad_etiqueta' => self::etiquetaModalidad($modalidad),
            'rondas_disponibles' => $numerosRonda,
            'ronda_actual' => $rondaFiltro,
            'ronda' => $rondaData,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_mesas' => (int) ($rondaData['total_mesas'] ?? 0),
                'total_paginas' => isset($rondaData['total_mesas'])
                    ? max(1, (int) ceil($rondaData['total_mesas'] / $porPagina))
                    : 0,
            ],
            'orden_clasificacion_criterio' => $this->textoCriterioClasificacion($rondaFiltro),
            'leyenda' => $this->leyenda(),
        ];
    }

    private function textoCriterioClasificacion(int $numRonda): string
    {
        if ($numRonda === 2) {
            return 'R2: ganadores R1 → BYE ganadores → ganados ↓ → efectividad ↓ → puntos ↓';
        }

        return 'Ganados ↓ → efectividad ↓ → puntos ↓ → posición ↑';
    }

    /**
     * @return array<int, int> id_usuario => orden (1 = mejor)
     */
    private function mapaOrdenClasificacion(int $torneoId, int $numRonda, PDO $pdo): array
    {
        $repo = new MesaRepository($pdo);
        $lista = $numRonda === 2
            ? $repo->obtenerClasificacionInscritosParaRonda2($torneoId)
            : $repo->obtenerClasificacionInscritos($torneoId);
        $map = [];
        $orden = 1;
        foreach ($lista as $row) {
            $id = (int) ($row['id_usuario'] ?? 0);
            if ($id > 0) {
                $map[$id] = $orden++;
            }
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewClasificacion(int $torneoId, int $numRonda, PDO $pdo, int $limite = 12): array
    {
        $repo = new MesaRepository($pdo);
        $lista = $numRonda === 2
            ? $repo->obtenerClasificacionInscritosParaRonda2($torneoId)
            : $repo->obtenerClasificacionInscritos($torneoId);
        $out = [];
        $n = 0;
        foreach ($lista as $row) {
            if ($n >= $limite) {
                break;
            }
            $out[] = [
                'orden' => $n + 1,
                'numfvd_txt' => NumfvdHelper::textoMostrar($row, true),
                'nombre_corto' => $this->nombreCorto((string) ($row['nombre'] ?? '')),
                'stats_txt' => $this->textoStats($row),
            ];
            $n++;
        }

        return $out;
    }

    private function acumularHistorialRonda(int $torneoId, int $numRonda, PDO $pdo): void
    {
        $mesasRaw = $this->cargarMesasRonda($torneoId, $numRonda, $pdo);
        foreach ($mesasRaw as $jugadoresPorSec) {
            $this->registrarMesaEnHistorial($jugadoresPorSec);
        }
    }

    /**
     * @return array<string, string>
     */
    private function leyenda(): array
    {
        return [
            'pareja' => 'Rojo: ya jugó a favor (misma pareja) con su compañero actual en rondas anteriores. El número indica cuántas veces antes.',
            'enfrenta' => 'Naranja: ya jugó en contra con ese rival más de una vez (2.ª vez o más). El número es el total de enfrentamientos incluyendo esta mesa.',
            'layout' => 'Formato [A · C] vs [B · D] (secuencias 1–2 a favor, 3–4 a favor, parejas enfrentadas).',
            'clasif' => 'Orden # = clasificación G/E/P al generar la ronda. Greedy: mejor disponible → A de la mesa; mesa 1 lleva #1 en A si no hubo intercambio por pareja repetida.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresPorSec secuencia 1..4
     * @return array<string, mixed>
     */
    /**
     * @param array<int, array<string, mixed>> $jugadoresPorSec
     * @return array{zona: string, etiqueta: string, descripcion: string}
     */
    private function clasificarZonaMesaR2(array $jugadoresPorSec, int $numRonda): array
    {
        if ($numRonda !== 2) {
            return ['zona' => '', 'etiqueta' => '', 'descripcion' => ''];
        }

        return [
            'zona' => 'greedy',
            'etiqueta' => 'Greedy R2',
            'descripcion' => 'Mejor clasificado en A (sec.1); reglas pareja y no enfrentar compañero R1',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresPorSec
     * @param array{zona: string, etiqueta: string, descripcion: string} $zonaInfo
     */
    /**
     * @param array<int, int> $ordenClasificacion
     */
    private function formatearMesa(int $numMesa, array $jugadoresPorSec, int $numRonda, array $zonaInfo = [], array $ordenClasificacion = []): array
    {
        $slots = [];
        for ($s = 1; $s <= 4; $s++) {
            $slots[] = isset($jugadoresPorSec[$s])
                ? $this->formatearJugador($jugadoresPorSec[$s], $jugadoresPorSec, $s, $numRonda, $ordenClasificacion)
                : null;
        }

        $parejaAc = array_filter([$slots[0] ?? null, $slots[1] ?? null]);
        $parejaBd = array_filter([$slots[2] ?? null, $slots[3] ?? null]);

        $ordenA = (int) ($slots[0]['orden_clasificacion'] ?? 0);
        $alertaLider = ($numMesa === 1 && $ordenA > 1);

        return [
            'numero' => $numMesa,
            'etiqueta' => 'M' . $numMesa,
            'zona' => $zonaInfo['zona'] ?? '',
            'zona_etiqueta' => $zonaInfo['etiqueta'] ?? '',
            'zona_descripcion' => $zonaInfo['descripcion'] ?? '',
            'alerta_lider_mesa1' => $alertaLider,
            'orden_cabeza_a' => $ordenA,
            'slots' => $slots,
            'pareja_ac' => array_values($parejaAc),
            'pareja_bd' => array_values($parejaBd),
            'linea_resumen' => $this->lineaResumenMesa($slots),
        ];
    }

    /**
     * @param list<array<string, mixed>|null> $slots
     */
    private function lineaResumenMesa(array $slots): string
    {
        $fmt = static function (?array $j): string {
            if ($j === null) {
                return '—';
            }

            return trim((string) ($j['numfvd_txt'] ?? '—') . ' ' . ($j['nombre_corto'] ?? ''));
        };

        $ac = ($fmt($slots[0] ?? null) . ' · ' . $fmt($slots[1] ?? null));
        $bd = ($fmt($slots[2] ?? null) . ' · ' . $fmt($slots[3] ?? null));

        return $ac . '  vs  ' . $bd;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresPorSec
     */
    /**
     * @param array<int, int> $ordenClasificacion
     */
    private function formatearJugador(array $row, array $jugadoresPorSec, int $secuencia, int $numRonda, array $ordenClasificacion = []): array
    {
        $id = (int) ($row['id_usuario_resuelto'] ?? $row['id_usuario'] ?? 0);
        $nombre = trim((string) ($row['nombre'] ?? $row['nombre_completo'] ?? 'Sin nombre'));
        $nf = NumfvdHelper::textoMostrar($row, true);
        $ordenClasificacionNum = (int) ($ordenClasificacion[$id] ?? 0);
        $statsTxt = $this->textoStats($row);

        $secCompañero = ($secuencia <= 2) ? ($secuencia === 1 ? 2 : 1) : ($secuencia === 3 ? 4 : 3);
        $idCompañero = (int) ($jugadoresPorSec[$secCompañero]['id_usuario_resuelto'] ?? $jugadoresPorSec[$secCompañero]['id_usuario'] ?? 0);

        $vecesParejaAntes = $id > 0 && $idCompañero > 0
            ? $this->conteoPar($this->conteoPareja, $id, $idCompañero)
            : 0;

        $enfrentamientos = [];
        for ($s = 1; $s <= 4; $s++) {
            if ($s === $secuencia || !isset($jugadoresPorSec[$s])) {
                continue;
            }
            $esMismaPareja = ($secuencia <= 2 && $s <= 2) || ($secuencia >= 3 && $s >= 3);
            if ($esMismaPareja) {
                continue;
            }
            $idRival = (int) ($jugadoresPorSec[$s]['id_usuario_resuelto'] ?? $jugadoresPorSec[$s]['id_usuario'] ?? 0);
            if ($idRival <= 0) {
                continue;
            }
            $antes = $this->conteoPar($this->conteoEnfrenta, $id, $idRival);
            $total = $antes + 1;
            if ($antes >= 1) {
                $enfrentamientos[] = [
                    'id_usuario' => $idRival,
                    'numfvd_txt' => NumfvdHelper::textoMostrar($jugadoresPorSec[$s], true),
                    'nombre_corto' => $this->nombreCorto((string) ($jugadoresPorSec[$s]['nombre'] ?? '')),
                    'veces_antes' => $antes,
                    'veces_total' => $total,
                ];
            }
        }

        return [
            'id_usuario' => $id,
            'secuencia' => $secuencia,
            'rol' => $secuencia <= 2 ? 'AC' : 'BD',
            'numfvd_txt' => $nf,
            'nombre' => $nombre,
            'nombre_corto' => $this->nombreCorto($nombre),
            'orden_clasificacion' => $ordenClasificacionNum,
            'orden_clasificacion_txt' => $ordenClasificacionNum > 0 ? '#' . $ordenClasificacionNum : '—',
            'ganados' => (int) ($row['ganados'] ?? 0),
            'efectividad' => (int) ($row['efectividad'] ?? 0),
            'puntos' => (int) ($row['puntos'] ?? 0),
            'stats_txt' => $statsTxt,
            'posicion' => (int) ($row['posicion'] ?? 0),
            'marca_pareja' => $vecesParejaAntes > 0,
            'veces_pareja_antes' => $vecesParejaAntes,
            'enfrentamientos_repetidos' => $enfrentamientos,
            'tiene_enfrenta_naranja' => $enfrentamientos !== [],
        ];
    }

    private function nombreCorto(string $nombre): string
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return '—';
        }
        if (function_exists('mb_strlen') && mb_strlen($nombre) > 28) {
            return mb_substr($nombre, 0, 26) . '…';
        }
        if (strlen($nombre) > 28) {
            return substr($nombre, 0, 26) . '…';
        }

        return $nombre;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function textoStats(array $row): string
    {
        $g = (int) ($row['ganados'] ?? 0);
        $e = (int) ($row['efectividad'] ?? 0);
        $p = (int) ($row['puntos'] ?? 0);

        return 'G:' . $g . ' E:' . $e . ' P:' . $p;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresPorSec
     */
    private function registrarMesaEnHistorial(array $jugadoresPorSec): void
    {
        $ids = [];
        for ($s = 1; $s <= 4; $s++) {
            if (!isset($jugadoresPorSec[$s])) {
                continue;
            }
            $ids[$s] = (int) ($jugadoresPorSec[$s]['id_usuario_resuelto'] ?? $jugadoresPorSec[$s]['id_usuario'] ?? 0);
        }
        if (isset($ids[1], $ids[2])) {
            $this->incrementarPar($this->conteoPareja, $ids[1], $ids[2]);
        }
        if (isset($ids[3], $ids[4])) {
            $this->incrementarPar($this->conteoPareja, $ids[3], $ids[4]);
        }
        $cruces = [[1, 3], [1, 4], [2, 3], [2, 4]];
        foreach ($cruces as [$a, $b]) {
            if (isset($ids[$a], $ids[$b])) {
                $this->incrementarPar($this->conteoEnfrenta, $ids[$a], $ids[$b]);
            }
        }
    }

    /**
     * @param array<int, array<int, int>> $matriz
     */
    private function conteoPar(array $matriz, int $id1, int $id2): int
    {
        if ($id1 <= 0 || $id2 <= 0) {
            return 0;
        }
        $a = min($id1, $id2);
        $b = max($id1, $id2);

        return (int) ($matriz[$a][$b] ?? 0);
    }

    /**
     * @param array<int, array<int, int>> $matriz
     */
    private function incrementarPar(array &$matriz, int $id1, int $id2): void
    {
        if ($id1 <= 0 || $id2 <= 0) {
            return;
        }
        $a = min($id1, $id2);
        $b = max($id1, $id2);
        if (!isset($matriz[$a])) {
            $matriz[$a] = [];
        }
        $matriz[$a][$b] = ($matriz[$a][$b] ?? 0) + 1;
    }

    /**
     * @return list<int>
     */
    private function numerosRondaGenerados(int $torneoId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT partida FROM partiresul WHERE id_torneo = ? AND partida > 0 ORDER BY partida ASC'
        );
        $stmt->execute([$torneoId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
            $n = (int) $p;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function cargarMesasRonda(int $torneoId, int $ronda, PDO $pdo): array
    {
        $exprNf = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo);
        $rankIni = InscritosHelper::sqlExprPosiRnkJugador($pdo, 'u', 'i');
        $regPr1 = PartiresulEstatusSql::whereRegistradoUno('pr1');
        $ganoR1 = InscritosHelper::sqlExprPartiresulResultado1MayorQueResultado2('pr1');
        $ganadorR1Expr = "(CASE WHEN pr1.id IS NOT NULL AND ({$regPr1}) AND {$ganoR1} THEN 1 ELSE 0 END)";
        $sql = 'SELECT pr.mesa, pr.secuencia, pr.id_usuario,
                u.id AS uid, u.nombre, u.posi_rnk,
                ' . $exprNf . ' AS numfvd,
                i.id_usuario AS insc_id_usuario,
                i.posicion, i.ptosrnk, i.ganados, i.efectividad, i.puntos,
                (' . $rankIni . ') AS ranking_inicial,
                ' . $ganadorR1Expr . ' AS ganador_r1
            FROM partiresul pr
            ' . NumfvdHelper::sqlJoinUsuariosPartiresul('pr', 'u') . '
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            LEFT JOIN partiresul pr1 ON pr1.partida = 1 AND ' . PartiresulJugadorHelper::sqlOnInscritosPartiresul('i', 'pr1') . '
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            ORDER BY pr.mesa ASC, pr.secuencia ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $filas = NumfvdHelper::enriquecerFilas($stmt->fetchAll(PDO::FETCH_ASSOC));

        $mesas = [];
        foreach ($filas as $row) {
            $numMesa = (int) ($row['mesa'] ?? 0);
            $sec = (int) ($row['secuencia'] ?? 0);
            if ($numMesa <= 0 || $sec < 1 || $sec > 4) {
                continue;
            }
            $row['id_usuario_resuelto'] = (int) ($row['insc_id_usuario'] ?? $row['uid'] ?? $row['id_usuario'] ?? 0);
            if (!isset($mesas[$numMesa])) {
                $mesas[$numMesa] = [];
            }
            $mesas[$numMesa][$sec] = $row;
        }

        return $mesas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cargarByeRonda(int $torneoId, int $ronda, PDO $pdo): array
    {
        $exprNf = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo);
        $sql = 'SELECT pr.id_usuario, u.nombre, ' . $exprNf . ' AS numfvd, i.posicion, i.ptosrnk, i.ganados, i.efectividad, i.puntos
            FROM partiresul pr
            ' . NumfvdHelper::sqlJoinUsuariosPartiresul('pr', 'u') . '
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = 0
            ORDER BY u.nombre ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $out = [];
        foreach (NumfvdHelper::enriquecerFilas($stmt->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $out[] = [
                'numfvd_txt' => NumfvdHelper::textoMostrar($row, true),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'stats_txt' => $this->textoStats($row),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cargarTorneo(int $torneoId, PDO $pdo): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? LIMIT 1');
        $stmt->execute([$torneoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function etiquetaModalidad(int $modalidad): string
    {
        if ($modalidad === 3) {
            return 'Equipos';
        }
        if ($modalidad === 2) {
            return 'Parejas';
        }
        if ($modalidad === 4) {
            return 'Parejas fijas / interclub';
        }

        return 'Individual';
    }

    /**
     * @param array{modalidad?: int, ranking?: int, asignacion_por_posicion?: int} $opciones
     */
    public static function etiquetaProcedimiento(int $numRonda, int $totalRondas, array $opciones, int $modalidad): string
    {
        if ($modalidad === TorneoMesaAsignacionResolver::MODALIDAD_EQUIPOS) {
            return 'Asignación por equipos (códigos de equipo, rotación secuencial de mesas).';
        }
        if (in_array($modalidad, TorneoMesaAsignacionResolver::MODALIDAD_PAREJAS_FIJAS, true)) {
            return 'Parejas fijas: rotación de compañeros dentro del club y mesas interclub.';
        }
        if ($numRonda === 1) {
            if (!empty($opciones['asignacion_por_posicion'])) {
                return 'Ronda 1: ranking inicial (posi_rnk). Parejas por mitades altas vs bajas; layout A·C vs B·D por mesa.';
            }

            return 'Ronda 1: dispersión por clubes (vectores V1–V4). Sin tope de jugadores del mismo club por mesa.';
        }
        if ($numRonda === 2) {
            return 'Ronda 2: clasificación específica post-R1. Greedy: mejor → cabeza A; luego C, B, D sin repetir pareja; '
                . 'no enfrentar en contra al compañero de R1. Desde R3 sin restricción de enfrentamiento R1.';
        }
        if ($numRonda === $totalRondas - 1 && $totalRondas > 3) {
            return 'Penúltima ronda: clasificación actual; patrón 1+3 vs 2+4 (posiciones 1,3 contra 2,4 por bloques de 4).';
        }
        if ($numRonda >= $totalRondas && $totalRondas > 3) {
            return 'Última ronda: clasificación actual; greedy (mejor → A; reglas de pareja sin restricción R1).';
        }
        if ($numRonda >= $totalRondas && $totalRondas > 0) {
            return 'Última ronda (≤3 rondas totales): patrón 1+3 vs 2+4 por clasificación.';
        }

        return 'Ronda intermedia: clasificación actual; greedy (mejor → cabeza A; no repetir pareja).';
    }

    /**
     * @param array{modalidad?: int, ranking?: int, asignacion_por_posicion?: int} $opciones
     */
    public static function codigoProcedimiento(int $numRonda, int $totalRondas, array $opciones, int $modalidad): string
    {
        if ($modalidad === 3) {
            return 'equipos_secuencial';
        }
        if (in_array($modalidad, [2, 4], true)) {
            return 'parejas_fijas';
        }
        if ($numRonda === 1) {
            return !empty($opciones['asignacion_por_posicion']) ? 'r1_ranking_inicial' : 'r1_dispersion_club';
        }
        if ($numRonda === 2) {
            return 'r2_greedy_clasificacion';
        }
        if ($numRonda === $totalRondas - 1 && $totalRondas > 3) {
            return 'r_penultima_1324';
        }
        if ($numRonda >= $totalRondas && $totalRondas > 3) {
            return 'r_final_greedy';
        }
        if ($numRonda >= $totalRondas && $totalRondas > 0) {
            return 'r_final_1324';
        }

        return 'r_intermedia_greedy';
    }
}
