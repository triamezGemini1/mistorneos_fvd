<?php



declare(strict_types=1);



require_once __DIR__ . '/NumfvdHelper.php';

require_once __DIR__ . '/PartiresulJugadorHelper.php';



/**

 * Parejas repetidas: fuente historial_parejas (torneo + llave menor-mayor).

 * Usa columna mesa del historial cuando existe; si no, resuelve desde partiresul.

 */

final class ReporteParejasRepetidasService

{

    private ?bool $historialTieneMesa = null;



    /**

     * @return array{

     *   torneo: array<string, mixed>,

     *   min_veces: int,

     *   total_grupos: int,

     *   grupos: list<array<string, mixed>>,

     *   sin_repeticiones: bool,

     *   sin_historial: bool,

     *   mensaje: string

     * }

     */

    public function construirReporte(int $torneoId, PDO $pdo, int $minVeces = 2): array

    {

        $minVeces = max(2, $minVeces);

        PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);



        $torneo = $this->cargarTorneo($torneoId, $pdo);

        if ($torneo === []) {

            return $this->respuestaVacia($minVeces, true, 'Torneo no encontrado.');

        }



        if (!$this->tablaHistorialExiste($pdo)) {

            return $this->respuestaVacia($minVeces, true, 'La tabla historial_parejas no existe en esta base de datos.');

        }



        $this->historialTieneMesa = $this->columnaHistorialExiste($pdo, 'mesa');

        $registros = $this->cargarRegistrosHistorial($torneoId, $pdo);

        if ($registros === []) {

            return [

                'torneo' => $torneo,

                'min_veces' => $minVeces,

                'total_grupos' => 0,

                'grupos' => [],

                'sin_repeticiones' => true,

                'sin_historial' => true,

                'mensaje' => 'No hay registros en historial_parejas para este torneo.',

            ];

        }



        $necesitaIndicePartiresul = !$this->historialTieneMesa;

        if (!$necesitaIndicePartiresul) {

            foreach ($registros as $reg) {

                if ((int) ($reg['mesa'] ?? 0) <= 0) {

                    $necesitaIndicePartiresul = true;

                    break;

                }

            }

        }



        $indiceMesas = $necesitaIndicePartiresul

            ? $this->construirIndiceMesasPorUsuario($torneoId, $pdo)

            : $this->construirIndiceMesasPorNumero($torneoId, $pdo);



        $cacheJugador = [];

        /** @var array<string, array<string, mixed>> $porLlave */

        $porLlave = [];



        foreach ($registros as $reg) {

            $llave = $reg['llave'];

            $j1 = $reg['jugador_1_id'];

            $j2 = $reg['jugador_2_id'];

            $ronda = $reg['ronda_id'];

            $mesa = (int) ($reg['mesa'] ?? 0);



            if ($mesa <= 0) {

                $mesa = $this->buscarMesaJugadores($indiceMesas, $ronda, $j1, $j2);

            }



            if (!isset($porLlave[$llave])) {

                $ja = $this->jugadorDesdeIdUsuario($pdo, $torneoId, $j1, $cacheJugador);

                $jb = $this->jugadorDesdeIdUsuario($pdo, $torneoId, $j2, $cacheJugador);

                $porLlave[$llave] = [

                    'llave' => $llave,

                    'jugador_1_id' => $j1,

                    'jugador_2_id' => $j2,

                    'jugador_a' => $ja,

                    'jugador_b' => $jb,

                    'etiqueta_pareja' => $ja['numfvd_txt'] . ' ' . $ja['nombre_corto']

                        . ' · ' . $jb['numfvd_txt'] . ' ' . $jb['nombre_corto'],

                    'veces' => 0,

                    'ocurrencias' => [],

                ];

            }



            $contrarios = $this->contrariosEnMesa($pdo, $torneoId, $indiceMesas, $ronda, $mesa, $j1, $j2, $cacheJugador);

            $ja = $porLlave[$llave]['jugador_a'];

            $jb = $porLlave[$llave]['jugador_b'];



            $porLlave[$llave]['veces'] = (int) $porLlave[$llave]['veces'] + 1;

            $porLlave[$llave]['ocurrencias'][] = [

                'ronda' => $ronda,

                'mesa' => $mesa,

                'llave' => $llave,

                'pareja_txt' => $porLlave[$llave]['etiqueta_pareja'],

                'contrarios' => $contrarios,

                'linea_mesa' => $this->lineaMesa($ja, $jb, $contrarios),

            ];

        }



        $grupos = [];

        foreach ($porLlave as $item) {

            if ((int) ($item['veces'] ?? 0) < $minVeces) {

                continue;

            }

            usort($item['ocurrencias'], static function (array $a, array $b): int {

                $cmp = ((int) ($a['ronda'] ?? 0)) <=> ((int) ($b['ronda'] ?? 0));

                if ($cmp !== 0) {

                    return $cmp;

                }



                return ((int) ($a['mesa'] ?? 0)) <=> ((int) ($b['mesa'] ?? 0));

            });

            $grupos[] = $item;

        }



        usort($grupos, static function (array $a, array $b): int {

            $cmp = ((int) ($b['veces'] ?? 0)) <=> ((int) ($a['veces'] ?? 0));

            if ($cmp !== 0) {

                return $cmp;

            }



            return strcmp((string) ($a['llave'] ?? ''), (string) ($b['llave'] ?? ''));

        });



        return [

            'torneo' => $torneo,

            'min_veces' => $minVeces,

            'total_grupos' => count($grupos),

            'grupos' => $grupos,

            'sin_repeticiones' => $grupos === [],

            'sin_historial' => false,

            'mensaje' => $grupos === []

                ? 'No hay llaves repetidas en historial_parejas con el mínimo indicado.'

                : '',

        ];

    }



    /**

     * @return array{

     *   torneo: array<string, mixed>,

     *   min_veces: int,

     *   total_grupos: int,

     *   grupos: list<array<string, mixed>>,

     *   sin_repeticiones: bool,

     *   sin_historial: bool,

     *   mensaje: string

     * }

     */

    private function respuestaVacia(int $minVeces, bool $sinHistorial, string $mensaje): array

    {

        return [

            'torneo' => [],

            'min_veces' => $minVeces,

            'total_grupos' => 0,

            'grupos' => [],

            'sin_repeticiones' => true,

            'sin_historial' => $sinHistorial,

            'mensaje' => $mensaje,

        ];

    }



    private function tablaHistorialExiste(PDO $pdo): bool

    {

        try {

            $st = $pdo->query("SHOW TABLES LIKE 'historial_parejas'");

            if ($st !== false && $st->fetch(PDO::FETCH_NUM)) {

                return true;

            }

        } catch (\Throwable $e) {

        }



        try {

            $st = $pdo->prepare(

                'SELECT 1 FROM information_schema.tables

                 WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'

            );

            $st->execute(['historial_parejas']);



            return (bool) $st->fetchColumn();

        } catch (\Throwable $e) {

            return false;

        }

    }



    /**

     * @return list<array{llave: string, jugador_1_id: int, jugador_2_id: int, ronda_id: int, mesa: int}>

     */

    private function cargarRegistrosHistorial(int $torneoId, PDO $pdo): array

    {

        $cols = 'ronda_id, jugador_1_id, jugador_2_id';

        if ($this->historialTieneMesa) {

            $cols .= ', mesa';

        }

        if ($this->columnaHistorialExiste($pdo, 'llave')) {

            $cols .= ', llave';

        }



        try {

            $st = $pdo->prepare(

                'SELECT ' . $cols . ' FROM historial_parejas WHERE torneo_id = ? ORDER BY '
                . ($this->historialTieneMesa ? 'llave ASC, ronda_id ASC, mesa ASC' : 'ronda_id ASC, jugador_1_id ASC')

            );

            $st->execute([$torneoId]);

        } catch (\Throwable $e) {

            return [];

        }



        $out = [];

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {

            $j1 = (int) ($row['jugador_1_id'] ?? 0);

            $j2 = (int) ($row['jugador_2_id'] ?? 0);

            $ronda = (int) ($row['ronda_id'] ?? 0);

            if ($j1 <= 0 || $j2 <= 0 || $ronda <= 0) {

                continue;

            }

            $menor = min($j1, $j2);

            $mayor = max($j1, $j2);

            $llave = trim((string) ($row['llave'] ?? ''));

            if ($llave === '' || !$this->llaveCoherenteConIds($llave, $menor, $mayor)) {

                $llave = $menor . '-' . $mayor;

            }



            $out[] = [

                'llave' => $llave,

                'jugador_1_id' => $menor,

                'jugador_2_id' => $mayor,

                'ronda_id' => $ronda,

                'mesa' => (int) ($row['mesa'] ?? 0),

            ];

        }



        return $out;

    }



    private function llaveCoherenteConIds(string $llave, int $menor, int $mayor): bool

    {

        return $llave === ($menor . '-' . $mayor);

    }



    private function columnaHistorialExiste(PDO $pdo, string $columna): bool

    {

        try {

            $st = $pdo->prepare(

                'SELECT 1 FROM information_schema.columns

                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'

            );

            $st->execute(['historial_parejas', $columna]);



            return (bool) $st->fetchColumn();

        } catch (\Throwable $e) {

            return false;

        }

    }



    /**

     * Índice por ronda y número de mesa (cuando historial_parejas.mesa está poblado).

     *

     * @return array<int, array<int, list<int>>>

     */

    private function construirIndiceMesasPorNumero(int $torneoId, PDO $pdo): array

    {

        if (!$this->historialTieneMesa) {

            return $this->construirIndiceMesasPorUsuario($torneoId, $pdo);

        }



        try {

            $st = $pdo->prepare(

                'SELECT ronda_id, mesa, jugador_1_id, jugador_2_id

                 FROM historial_parejas

                 WHERE torneo_id = ? AND mesa > 0'

            );

            $st->execute([$torneoId]);

        } catch (\Throwable $e) {

            return $this->construirIndiceMesasPorUsuario($torneoId, $pdo);

        }



        $indice = [];

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {

            $ronda = (int) ($row['ronda_id'] ?? 0);

            $mesa = (int) ($row['mesa'] ?? 0);

            foreach ([(int) ($row['jugador_1_id'] ?? 0), (int) ($row['jugador_2_id'] ?? 0)] as $uid) {

                if ($ronda <= 0 || $mesa <= 0 || $uid <= 0) {

                    continue;

                }

                if (!isset($indice[$ronda][$mesa])) {

                    $indice[$ronda][$mesa] = [];

                }

                if (!in_array($uid, $indice[$ronda][$mesa], true)) {

                    $indice[$ronda][$mesa][] = $uid;

                }

            }

        }



        if ($indice !== []) {

            return $indice;

        }



        return $this->construirIndiceMesasPorUsuario($torneoId, $pdo);

    }



    /**

     * @return array<int, array<int, list<int>>>

     */

    private function construirIndiceMesasPorUsuario(int $torneoId, PDO $pdo): array

    {

        $exprClave = PartiresulJugadorHelper::sqlExprClaveJugador('pr', $pdo);

        $extraId = PartiresulJugadorHelper::tieneColumnaIdUsuario($pdo) ? ', pr.id_usuario AS pr_id_usuario' : '';

        $sql = 'SELECT pr.partida, pr.mesa, ' . $exprClave . ' AS clave_jugador' . $extraId . '

            FROM partiresul pr

            WHERE pr.id_torneo = ? AND pr.mesa > 0 AND pr.partida > 0

            ORDER BY pr.partida ASC, pr.mesa ASC, pr.secuencia ASC';



        $st = $pdo->prepare($sql);

        $st->execute([$torneoId]);



        $indice = [];

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {

            $partida = (int) ($row['partida'] ?? 0);

            $mesa = (int) ($row['mesa'] ?? 0);

            $clave = (int) ($row['clave_jugador'] ?? 0);

            if ($partida <= 0 || $mesa <= 0 || $clave <= 0) {

                continue;

            }



            $uid = (int) ($row['pr_id_usuario'] ?? 0);

            if ($uid <= 0) {

                $uid = (int) (NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneoId, $clave) ?? 0);

            }

            if ($uid <= 0) {

                continue;

            }



            if (!isset($indice[$partida][$mesa])) {

                $indice[$partida][$mesa] = [];

            }

            if (!in_array($uid, $indice[$partida][$mesa], true)) {

                $indice[$partida][$mesa][] = $uid;

            }

        }



        return $indice;

    }



    /**

     * @param array<int, array<int, list<int>>> $indiceMesas

     */

    private function buscarMesaJugadores(array $indiceMesas, int $ronda, int $id1, int $id2): int

    {

        if (!isset($indiceMesas[$ronda])) {

            return 0;

        }



        foreach ($indiceMesas[$ronda] as $numMesa => $uids) {

            if (in_array($id1, $uids, true) && in_array($id2, $uids, true)) {

                return (int) $numMesa;

            }

        }



        return 0;

    }



    /**

     * @param array<int, array<int, list<int>>> $indiceMesas

     * @param array<int, array<string, mixed>> $cacheJugador

     * @return list<array<string, mixed>>

     */

    private function contrariosEnMesa(

        PDO $pdo,

        int $torneoId,

        array $indiceMesas,

        int $ronda,

        int $mesa,

        int $id1,

        int $id2,

        array &$cacheJugador

    ): array {

        if ($mesa <= 0 || !isset($indiceMesas[$ronda][$mesa])) {

            return [];

        }



        $contrarios = [];

        foreach ($indiceMesas[$ronda][$mesa] as $uid) {

            if ($uid === $id1 || $uid === $id2) {

                continue;

            }

            $contrarios[] = $this->jugadorDesdeIdUsuario($pdo, $torneoId, $uid, $cacheJugador);

        }



        return $contrarios;

    }



    /**

     * @param array<int, array<string, mixed>> $cache

     * @return array{id: int, numfvd_txt: string, nombre: string, nombre_corto: string}

     */

    private function jugadorDesdeIdUsuario(PDO $pdo, int $torneoId, int $idUsuario, array &$cache): array

    {

        if (isset($cache[$idUsuario])) {

            return $cache[$idUsuario];

        }



        $nf = NumfvdHelper::numfvdInscrito($pdo, $torneoId, $idUsuario);

        $nombre = '—';

        if ($idUsuario > 0) {

            $st = $pdo->prepare(

                'SELECT COALESCE(u.nombre, u.username) AS nombre FROM usuarios u WHERE u.id = ? LIMIT 1'

            );

            $st->execute([$idUsuario]);

            $nombre = trim((string) ($st->fetchColumn() ?: '—'));

        }



        $cache[$idUsuario] = [

            'id' => $idUsuario,

            'numfvd_txt' => NumfvdHelper::textoMostrar(['numfvd' => $nf, 'id_usuario' => $idUsuario], true),

            'nombre' => $nombre,

            'nombre_corto' => $this->nombreCorto($nombre),

        ];



        return $cache[$idUsuario];

    }



    /**

     * @param list<array<string, mixed>> $contrarios

     */

    private function lineaMesa(array $ja, array $jb, array $contrarios): string

    {

        $pareja = $ja['numfvd_txt'] . ' ' . $ja['nombre_corto'] . ' · ' . $jb['numfvd_txt'] . ' ' . $jb['nombre_corto'];

        $vs = [];

        foreach ($contrarios as $c) {

            $vs[] = $c['numfvd_txt'] . ' ' . $c['nombre_corto'];

        }



        return $pareja . '  vs  ' . implode(' · ', $vs);

    }



    private function nombreCorto(string $nombre): string

    {

        $nombre = trim($nombre);

        if ($nombre === '') {

            return '—';

        }

        if (function_exists('mb_strlen') && mb_strlen($nombre) > 32) {

            return mb_substr($nombre, 0, 30) . '…';

        }

        if (strlen($nombre) > 32) {

            return substr($nombre, 0, 30) . '…';

        }



        return $nombre;

    }



    /**

     * @return array<string, mixed>

     */

    private function cargarTorneo(int $torneoId, PDO $pdo): array

    {

        $st = $pdo->prepare('SELECT id, nombre, fechator, modalidad, rondas FROM tournaments WHERE id = ? LIMIT 1');

        $st->execute([$torneoId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);



        return is_array($row) ? $row : [];

    }

}


