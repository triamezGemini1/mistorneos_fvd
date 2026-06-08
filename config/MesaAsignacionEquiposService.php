<?php

/**
 * Servicio de Asignación de Mesas para Torneos de Equipos de Dominó — V4
 *
 * Tabla equipos (referencia): id, id_torneo, id_club, nombre_equipo, codigo_equipo, consecutivo_club,
 * estatus (0=activo, 1=inactivo), ganados, perdidos, efectividad, puntos, gff, posicion, sancion,
 * creado_por, fechas. Los jugadores se resuelven vía inscritos: mismo torneo y codigo_equipo que equipos.
 *
 * PRIORIDADES DE ASIGNACIÓN:
 * 1. Número dentro del equipo (Rango 1-4 según rendimiento individual desde partiresul: JG, efectividad, PF).
 * 2. Lista maestra del bloque: todos los rango 1 (por ranking de equipo), luego rango 2, etc.; mesas 1..N
 *    se llenan en orden: cada atleta va a la primera mesa con hueco sin otro jugador de su equipo.
 * 3. Integridad: Prohibido dos jugadores del mismo equipo en la misma mesa.
 * 4. Diversidad: Rotación de parejas (ejes) según el número de ronda.
 *
 * Clasificación equipos/jugadores (rondas 2+): una sola consulta SQL con CTE + ROW_NUMBER (MySQL 8+ / MariaDB 10.2+).
 */

require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';

class MesaAsignacionEquiposService
{
    private $pdo;
    private $proxima_ronda_cache;
    /** @var int Torneo en curso (generación de ronda) para consultar historial partiresul. */
    private $torneo_id_cache = 0;

    const JUGADORES_POR_MESA = 4;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Punto de entrada principal
     */
    public function generarAsignacionRonda($torneo_id, $proxima_ronda, $total_rondas, $estrategia)
    {
        $this->proxima_ronda_cache = $proxima_ronda;
        $this->torneo_id_cache = (int) $torneo_id;

        if ($proxima_ronda == 1) {
            return $this->generarRonda1($torneo_id, $estrategia);
        }

        // Obtener equipos ordenados por clasificación (G/E/P)
        $equipos = $this->obtenerEquiposConJugadoresYClasificacion($torneo_id, $proxima_ronda - 1);
        
        if (empty($equipos)) {
            return ['success' => false, 'message' => 'No hay equipos con datos para procesar.'];
        }

        // Partir en lotes (4 a 7 equipos para manejar remanentes correctamente)
        try {
            $lotes = $this->partirEquiposEnLotes($equipos);
            // Lista maestra por rango + ranking de equipo; llenado secuencial de mesas 1..N
            // (puede lanzar RuntimeException: mesa incompleta, duplicado de equipo, asignación imposible, etc.)
            $mesasArray = $this->construirMesasDesdeLotesHomologos($lotes);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (empty($mesasArray)) {
            return ['success' => false, 'message' => 'Error al generar la distribución de mesas.'];
        }

        try {
            $this->guardarAsignacionRonda($torneo_id, $proxima_ronda, $mesasArray);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true, 
            'message' => "Ronda {$proxima_ronda} generada exitosamente respetando rangos y rotando parejas.",
            'total_mesas' => count($mesasArray)
        ];
    }

    /**
     * Generación de Ronda 1 (homologada con individual en el reparto por mesas):
     * 1) ordenar por asociación, equipo, id_usuario
     * 2) asignar mesas por 4 ciclos consecutivos mesa 1..N
     * En equipos no existe BYE: el total de jugadores debe ser múltiplo de 4.
     */
    private function generarRonda1($torneo_id, $estrategia)
    {
        // Miembros del equipo: tabla equipos + inscritos (codigo_equipo), con asociación (id_club).
        $sql = "SELECT
                    u.id AS id_usuario,
                    e.id AS id_equipo,
                    e.id_club AS id_asociacion,
                    e.nombre_equipo AS nombre_equipo
                FROM equipos e
                INNER JOIN inscritos i ON i.torneo_id = e.id_torneo
                    AND i.codigo_equipo = e.codigo_equipo
                    AND i.estatus != 4
                    AND " . InscritosHelper::sqlWhereActivoMesaConAlias($this->pdo, 'i') . "
                INNER JOIN usuarios u ON u.id = i.id_usuario
                WHERE e.id_torneo = ?
                  AND e.estatus = 0
                  AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
                ORDER BY
                    COALESCE(e.id_club, 0) ASC,
                    e.id ASC,
                    u.id ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneo_id]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($jugadores)) {
            return ['success' => false, 'message' => 'No hay jugadores'];
        }

        $totalJugadores = count($jugadores);
        if ($totalJugadores % self::JUGADORES_POR_MESA !== 0) {
            return [
                'success' => false,
                'message' => 'En torneos por equipos el número de jugadores debe ser múltiplo de 4 (no hay BYE). Actualmente hay ' . $totalJugadores . ' jugadores.',
            ];
        }

        $numMesas = (int) ($totalJugadores / self::JUGADORES_POR_MESA);
        if ($numMesas < 1) {
            return ['success' => false, 'message' => 'No hay suficientes jugadores para formar al menos 1 mesa (mínimo 4).'];
        }

        // 4 ciclos consecutivos: en cada ciclo se recorre mesa 1..N.
        $mesas = array_fill(0, $numMesas, []);
        $idx = 0;
        for ($ciclo = 0; $ciclo < self::JUGADORES_POR_MESA; $ciclo++) {
            for ($m = 0; $m < $numMesas; $m++) {
                $mesas[$m][] = $jugadores[$idx++];
            }
        }

        try {
            $this->guardarAsignacionRonda($torneo_id, 1, $mesas);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Primera ronda generada exitosamente (equipos)',
            'total_mesas' => count($mesas),
        ];
    }

    /**
     * MOTOR PRINCIPAL: por cada bloque, lista maestra (rango 1→4 agrupado; dentro de cada rango por ranking de equipo)
     * y asignación secuencial a mesas 1..N (N = k equipos), colocando cada atleta en la primera mesa con hueco sin conflicto de equipo.
     * Bloques de 4 equipos: 4 mesas. Bloque 5–7 equipos: N = k mesas.
     *
     * Tras asignar los 4 jugadores a cada mesa, solo se reubican dentro de la misma mesa (permutaciones)
     * para intentar reducir repeticiones de pareja de compañero respecto a rondas anteriores. No se mueven
     * jugadores entre mesas. Si ninguna permutación mejora, se deja el orden por rango + rotación habitual.
     */
    private function construirMesasDesdeLotesHomologos(array $lotes): array
    {
        $mesas = [];
        $ronda = $this->proxima_ronda_cache;
        $torneoId = (int) $this->torneo_id_cache;
        $histCompanero = ($ronda > 1 && $torneoId > 0)
            ? $this->obtenerHistorialParesCompanero($torneoId, $ronda)
            : [];

        foreach ($lotes as $lote) {
            $lote = array_values($lote);
            $k = count($lote);
            $listaMaestra = $this->construirListaMaestraBloque($lote);
            $mesasBloque = $this->asignarAtletasMesasSecuencial($listaMaestra, $k);

            foreach ($mesasBloque as $jugadoresMesa) {
                usort($jugadoresMesa, function ($a, $b) {
                    return ((int)($a['posicion_equipo'] ?? 0)) <=> ((int)($b['posicion_equipo'] ?? 0));
                });
                if (count($jugadoresMesa) !== self::JUGADORES_POR_MESA) {
                    throw new RuntimeException('Mesa incompleta: se esperaban 4 jugadores por mesa.');
                }
                if ($this->mesaTieneEquipoDuplicado($jugadoresMesa)) {
                    throw new RuntimeException(
                        'Asignación inválida: dos jugadores del mismo equipo en una mesa (revisar rangos o plantillas).'
                    );
                }
                $rotados = $this->elegirOrdenIntraMesaMenosCompaneroRepetido($jugadoresMesa, $ronda, $histCompanero);
                if ($this->mesaTieneEquipoDuplicado($rotados)) {
                    throw new RuntimeException('Rotación de parejas generó duplicado de equipo en mesa.');
                }
                $mesas[] = $rotados;
            }
        }

        return $mesas;
    }

    /**
     * Pares de compañeros (misma pareja de dominó) ya usados en partidas anteriores (mesa &gt; 0).
     * Coherente con {@see rotarParejas}: según paridad de la ronda guardada en partiresul.
     *
     * @return array<string, true> clave "a-b" con a &lt; b
     */
    private function obtenerHistorialParesCompanero(int $torneoId, int $partidaNueva): array
    {
        if ($torneoId <= 0 || $partidaNueva <= 1) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT partida, mesa, id_usuario FROM partiresul
             WHERE id_torneo = ? AND partida < ? AND mesa > 0
             ORDER BY partida ASC, mesa ASC, secuencia ASC'
        );
        $stmt->execute([$torneoId, $partidaNueva]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $porGrupo = [];
        foreach ($rows as $row) {
            $k = (int) $row['partida'] . ':' . (int) $row['mesa'];
            if (!isset($porGrupo[$k])) {
                $porGrupo[$k] = ['partida' => (int) $row['partida'], 'uids' => []];
            }
            $porGrupo[$k]['uids'][] = (int) $row['id_usuario'];
        }
        $pares = [];
        foreach ($porGrupo as $grupo) {
            $uids = $grupo['uids'];
            $p = (int) $grupo['partida'];
            if (count($uids) !== 4) {
                continue;
            }
            if ($p % 2 === 0) {
                $par1 = [$uids[0], $uids[1]];
                $par2 = [$uids[2], $uids[3]];
            } else {
                $par1 = [$uids[0], $uids[2]];
                $par2 = [$uids[1], $uids[3]];
            }
            foreach ([$par1, $par2] as $par) {
                $a = (int) $par[0];
                $b = (int) $par[1];
                if ($a <= 0 || $b <= 0 || $a === $b) {
                    continue;
                }
                if ($a > $b) {
                    $t = $a;
                    $a = $b;
                    $b = $t;
                }
                $pares["{$a}-{$b}"] = true;
            }
        }

        return $pares;
    }

    /**
     * @return array<int, array{0:int,1:int,2:int,3:int}>
     */
    private function permutacionesIndices4(): array
    {
        $out = [];
        for ($a = 0; $a < 4; $a++) {
            for ($b = 0; $b < 4; $b++) {
                if ($b === $a) {
                    continue;
                }
                for ($c = 0; $c < 4; $c++) {
                    if ($c === $a || $c === $b) {
                        continue;
                    }
                    for ($d = 0; $d < 4; $d++) {
                        if ($d === $a || $d === $b || $d === $c) {
                            continue;
                        }
                        $out[] = [$a, $b, $c, $d];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Cuenta cuántas de las dos parejas de compañero (tras rotar) ya jugaron juntas antes.
     */
    private function contarCompanerosRepetidosVsHistorial(array $mesaRotada, int $ronda, array $histCompanero): int
    {
        if ($histCompanero === []) {
            return 0;
        }
        $uids = [];
        foreach ($mesaRotada as $j) {
            $uids[] = (int) ($j['id_usuario'] ?? 0);
        }
        if ($ronda % 2 === 0) {
            $pares = [[$uids[0], $uids[1]], [$uids[2], $uids[3]]];
        } else {
            $pares = [[$uids[0], $uids[2]], [$uids[1], $uids[3]]];
        }
        $cnt = 0;
        foreach ($pares as $par) {
            $a = $par[0];
            $b = $par[1];
            if ($a <= 0 || $b <= 0) {
                continue;
            }
            if ($a > $b) {
                $t = $a;
                $a = $b;
                $b = $t;
            }
            if (isset($histCompanero["{$a}-{$b}"])) {
                ++$cnt;
            }
        }

        return $cnt;
    }

    /**
     * Prueba permutaciones del orden previo a rotarParejas; elige la que minimiza compañeros repetidos.
     * Si ninguna mejora el orden por rango (identidad), se mantiene ese.
     *
     * @param array<int, array<string, mixed>> $jugadoresPorRango
     * @return array<int, array<string, mixed>>
     */
    private function elegirOrdenIntraMesaMenosCompaneroRepetido(array $jugadoresPorRango, int $ronda, array $histCompanero): array
    {
        $defecto = $this->rotarParejas($jugadoresPorRango, $ronda);
        if ($histCompanero === []) {
            return $defecto;
        }
        $mejor = $defecto;
        $mejorCnt = $this->contarCompanerosRepetidosVsHistorial($mejor, $ronda, $histCompanero);
        if ($mejorCnt === 0) {
            return $mejor;
        }
        foreach ($this->permutacionesIndices4() as $perm) {
            $reorden = [
                $jugadoresPorRango[$perm[0]],
                $jugadoresPorRango[$perm[1]],
                $jugadoresPorRango[$perm[2]],
                $jugadoresPorRango[$perm[3]],
            ];
            if ($this->mesaTieneEquipoDuplicado($reorden)) {
                continue;
            }
            $rot = $this->rotarParejas($reorden, $ronda);
            if ($this->mesaTieneEquipoDuplicado($rot)) {
                continue;
            }
            $c = $this->contarCompanerosRepetidosVsHistorial($rot, $ronda, $histCompanero);
            if ($c < $mejorCnt) {
                $mejorCnt = $c;
                $mejor = $rot;
            }
        }

        return $mejor;
    }

    /**
     * Aplana atletas del bloque: orden rango interno ascendente (1,1,…, 2,2,…, 3,…, 4,…);
     * dentro de cada rango, por ranking del equipo (mejor equipo primero).
     *
     * @param array<int, array<string, mixed>> $lote
     * @return array<int, array<string, mixed>>
     */
    private function construirListaMaestraBloque(array $lote): array
    {
        $lista = [];
        foreach ($lote as $equipo) {
            foreach ($equipo['jugadores'] as $jugador) {
                $lista[] = $jugador;
            }
        }
        usort($lista, function ($a, $b) {
            $ra = (int)($a['posicion_equipo'] ?? 0);
            $rb = (int)($b['posicion_equipo'] ?? 0);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((int)($a['ranking_equipo'] ?? 0)) <=> ((int)($b['ranking_equipo'] ?? 0));
        });

        return $lista;
    }

    /**
     * N mesas (índice 0..N-1); para cada atleta en orden de lista maestra, primera mesa con &lt; 4 plazas
     * que no contenga ya a su equipo.
     *
     * @param array<int, array<string, mixed>> $listaMaestra
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function asignarAtletasMesasSecuencial(array $listaMaestra, int $numMesas): array
    {
        $mesas = [];
        for ($i = 0; $i < $numMesas; $i++) {
            $mesas[$i] = [];
        }

        foreach ($listaMaestra as $atleta) {
            $idEquipo = (int)($atleta['id_equipo'] ?? 0);
            $colocado = false;
            for ($m = 0; $m < $numMesas; $m++) {
                if (count($mesas[$m]) >= self::JUGADORES_POR_MESA) {
                    continue;
                }
                if ($this->mesaContieneEquipoId($mesas[$m], $idEquipo)) {
                    continue;
                }
                $mesas[$m][] = $atleta;
                $colocado = true;
                break;
            }
            if (!$colocado) {
                throw new RuntimeException(
                    'No se pudo asignar un atleta: ninguna mesa admite su equipo sin duplicar (bloque demasiado restringido).'
                );
            }
        }

        return $mesas;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresMesa
     */
    private function mesaContieneEquipoId(array $jugadoresMesa, int $idEquipo): bool
    {
        if ($idEquipo <= 0) {
            return false;
        }
        foreach ($jugadoresMesa as $j) {
            if ((int)($j['id_equipo'] ?? 0) === $idEquipo) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresMesa
     */
    private function mesaTieneEquipoDuplicado(array $jugadoresMesa): bool
    {
        $vistos = [];
        foreach ($jugadoresMesa as $j) {
            $e = (int)($j['id_equipo'] ?? 0);
            if ($e <= 0) {
                continue;
            }
            if (isset($vistos[$e])) {
                return true;
            }
            $vistos[$e] = true;
        }
        return false;
    }

    /**
     * Alterna quién es pareja de quién para evitar repeticiones de compañeros
     */
    private function rotarParejas(array $j, $ronda)
    {
        // j[0]=Rango1, j[1]=Rango2, j[2]=Rango3, j[3]=Rango4
        // En dominó usualmente Pareja 1: (Eje 1-3) y Pareja 2: (Eje 2-4) 
        // o según tu guardado (1-2 vs 3-4).
        
        if ($ronda % 2 == 0) {
            // Compañeros: (0 con 1) y (2 con 3)
            return [$j[0], $j[1], $j[2], $j[3]]; 
        } else {
            // Compañeros: (0 con 2) y (1 con 3) -> Cambió la pareja
            return [$j[0], $j[2], $j[1], $j[3]];
        }
    }

    /**
     * Partición en bloques para ronda 2+:
     * - Avanza de 4 en 4 equipos mientras el remanente sea &gt; 7.
     * - Si el remanente es entre 4 y 7 (inclusive), forma un solo bloque final con esos equipos
     *   (caso típico: total de equipos no múltiplo de 4; el último grupo tiene 5, 6 o 7 equipos).
     * - Si al inicio hay menos de 4 equipos, un único bloque con los que haya (caso degenerado).
     */
    private function partirEquiposEnLotes(array $equipos): array
    {
        $lotes = [];
        $total = count($equipos);
        $i = 0;

        while ($i < $total) {
            $restante = $total - $i;
            if ($restante < 4) {
                $lotes[] = array_slice($equipos, $i);
                break;
            }
            if ($restante >= 4 && $restante <= 7) {
                $lotes[] = array_slice($equipos, $i);
                break;
            }
            $lotes[] = array_slice($equipos, $i, 4);
            $i += 4;
        }
        return $lotes;
    }

    /**
     * Clasificación desde partiresul hasta la ronda $rondaActual (inclusive):
     * equipos por SUM(JG), SUM(efectividad), SUM(PF); jugadores por JG, efectividad, PF con ROW_NUMBER 1–4.
     * Misma lógica de partida ganada / registrado que {@see InscritosPartiresulHelper::obtenerEstadisticas}.
     *
     * @return array<int, array{id:int, nombre:string, jugadores:array<int, array<string, mixed>>}>
     */
    private function obtenerEquiposConJugadoresYClasificacion($torneo_id, $rondaActual)
    {
        $sql = $this->sqlClasificacionEquiposJugadoresPartiresul();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneo_id, $torneo_id, (int)$rondaActual]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        $equipos = [];
        foreach ($rows as $row) {
            $eid = (int)$row['id_equipo'];
            if (!isset($equipos[$eid])) {
                $equipos[$eid] = [
                    'id' => $eid,
                    'nombre' => $row['nombre'],
                    'jugadores' => [],
                    '_rn' => (int)$row['rn_equipo'],
                ];
            }
            $equipos[$eid]['jugadores'][] = [
                'id_usuario' => (int)$row['id_usuario'],
                'nombre_usuario' => $row['nombre_usuario'],
                'posicion_equipo' => (int)$row['posicion_equipo'],
                'ranking_equipo' => (int)$row['rn_equipo'],
                'id_equipo' => $eid,
                'nombre_equipo' => $row['nombre'],
            ];
        }

        uasort($equipos, function ($a, $b) {
            return $a['_rn'] <=> $b['_rn'];
        });
        foreach ($equipos as &$eq) {
            unset($eq['_rn']);
        }
        unset($eq);

        return array_values($equipos);
    }

    /**
     * Una única consulta: CTE + ROW_NUMBER() sobre agregados de partiresul (JG, efectividad, PF).
     */
    private function sqlClasificacionEquiposJugadoresPartiresul(): string
    {
        $r1 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $r2 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado2');
        $sn = InscritosHelper::sqlExprColumnaNumerica('pr.sancion');
        $reg = PartiresulEstatusSql::whereRegistradoUno('pr');
        $ff = PartiresulEstatusSql::whereFfCero('pr');
        $sumEf = InscritosHelper::sqlExprColumnaNumerica('pr.efectividad');
        $sumR1 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $br1 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $br2 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado2');

        $winNormal = "pr.mesa > 0 AND {$reg} AND {$ff} AND ((({$sn}) = 0 AND ({$r1}) > ({$r2})) OR (({$sn}) > 0 AND (({$r1}) - ({$sn})) > ({$r2})))";
        $winBye = "pr.mesa = 0 AND {$reg} AND (({$br1}) > ({$br2}))";
        $ganoFila = "pr.id IS NOT NULL AND (({$winNormal}) OR ({$winBye}))";

        return "
WITH eu_base AS (
  SELECT e.id AS id_equipo, e.nombre_equipo AS nombre, i.id_usuario
  FROM equipos e
  INNER JOIN inscritos i ON i.torneo_id = e.id_torneo
    AND i.codigo_equipo = e.codigo_equipo
    AND i.estatus != 4
    AND (i.activo_mesa IS NULL OR i.activo_mesa = 1)
  WHERE e.id_torneo = ? AND e.estatus = 0
    AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
),
jugador_agg AS (
  SELECT
    eu.id_equipo,
    eu.id_usuario,
    COALESCE(SUM(CASE WHEN {$ganoFila} THEN 1 ELSE 0 END), 0) AS jg,
    COALESCE(SUM(CASE WHEN pr.id IS NOT NULL AND {$reg} THEN {$sumEf} ELSE 0 END), 0) AS ef,
    COALESCE(SUM(CASE WHEN pr.id IS NOT NULL AND {$reg} THEN {$sumR1} ELSE 0 END), 0) AS pf
  FROM eu_base eu
  LEFT JOIN partiresul pr ON pr.id_usuario = eu.id_usuario
    AND pr.id_torneo = ?
    AND pr.partida <= ?
  GROUP BY eu.id_equipo, eu.id_usuario
),
jugador_ranked AS (
  SELECT
    ja.id_equipo,
    ja.id_usuario,
    u.nombre AS nombre_usuario,
    ROW_NUMBER() OVER (
      PARTITION BY ja.id_equipo
      ORDER BY ja.jg DESC, ja.ef DESC, ja.pf DESC, ja.id_usuario ASC
    ) AS posicion_equipo
  FROM jugador_agg ja
  INNER JOIN usuarios u ON u.id = ja.id_usuario
),
equipo_agg AS (
  SELECT
    id_equipo,
    SUM(jg) AS jg_eq,
    SUM(ef) AS ef_eq,
    SUM(pf) AS pf_eq
  FROM jugador_agg
  GROUP BY id_equipo
),
equipo_ranked AS (
  SELECT
    id_equipo,
    ROW_NUMBER() OVER (
      ORDER BY jg_eq DESC, ef_eq DESC, pf_eq DESC, id_equipo ASC
    ) AS rn_equipo
  FROM equipo_agg
)
SELECT
  jr.id_equipo,
  e.nombre_equipo AS nombre,
  jr.id_usuario,
  jr.nombre_usuario,
  jr.posicion_equipo,
  er.rn_equipo
FROM jugador_ranked jr
INNER JOIN equipo_ranked er ON er.id_equipo = jr.id_equipo
INNER JOIN equipos e ON e.id = jr.id_equipo
WHERE jr.posicion_equipo <= 4
ORDER BY er.rn_equipo ASC, jr.posicion_equipo ASC
";
    }

    /**
     * Usuario que registra la asignación (misma lógica que MesaRepository / legacy).
     */
    private function resolveRegistradoPorUsuarioId(): int
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $admin = $_SESSION['admin_user'] ?? null;
            if (is_array($admin) && !empty($admin['id'])) {
                return max(1, (int) $admin['id']);
            }
            $user = $_SESSION['user'] ?? null;
            if (is_array($user) && !empty($user['id'])) {
                return max(1, (int) $user['id']);
            }
        }
        if (class_exists('Auth', false) && method_exists('Auth', 'id')) {
            $id = (int) Auth::id();
            return $id > 0 ? $id : 1;
        }
        return 1;
    }

    /**
     * Esquema estándar partiresul (sin id_equipo / fecha_registro; igual que MesaRepositoryPersistTrait).
     */
    private function guardarAsignacionRonda($torneo_id, $ronda, $mesas)
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?");
            $stmt->execute([$torneo_id, $ronda]);

            $registradoPor = $this->resolveRegistradoPorUsuarioId();
            $sql = "INSERT INTO partiresul
                    (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                     resultado1, resultado2, efectividad, ff)
                    VALUES (?, ?, ?, ?, ?, NOW(), 0, ?, 0, 0, 0, 0)";
            $stmt = $this->pdo->prepare($sql);

            foreach ($mesas as $indexMesa => $jugadores) {
                $numMesa = $indexMesa + 1;
                foreach ($jugadores as $indexJugador => $j) {
                    $secuencia = $indexJugador + 1;
                    $stmt->execute([
                        $torneo_id,
                        (int) ($j['id_usuario'] ?? 0),
                        $ronda,
                        $numMesa,
                        $secuencia,
                        $registradoPor,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function obtenerUltimaRonda($torneoId)
    {
        $sql = "SELECT MAX(partida) as ultima_ronda FROM partiresul WHERE id_torneo = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['ultima_ronda'] ?? 0);
    }

    /**
     * Próxima ronda a generar (misma firma que otros servicios de mesa).
     */
    public function obtenerProximaRonda($torneoId)
    {
        return $this->obtenerUltimaRonda($torneoId) + 1;
    }

    /**
     * Todas las mesas de juego (mesa &gt; 0) tienen resultados registrados.
     * Misma lógica que {@see MesaAsignacionService::todasLasMesasCompletas} (registrado VARCHAR-safe).
     */
    public function todasLasMesasCompletas($torneoId, $ronda)
    {
        $noReg = PartiresulEstatusSql::whereRegistradoNoCompleto();
        $sql = "SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND {$noReg}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['mesas_incompletas'] ?? 0) === 0;
    }

    /**
     * Mesas sin registrar (misma consulta que {@see RoundManagerHandler::contarMesasIncompletas}).
     */
    public function contarMesasIncompletas($torneoId, $ronda)
    {
        $noReg = PartiresulEstatusSql::whereRegistradoNoCompleto();
        $sql = "SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND {$noReg}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['mesas_incompletas'] ?? 0);
    }
}