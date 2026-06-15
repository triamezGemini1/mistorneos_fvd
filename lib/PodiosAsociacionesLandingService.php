<?php
declare(strict_types=1);

require_once __DIR__ . '/ClubHelper.php';
require_once __DIR__ . '/InscritosHelper.php';

/**
 * Podios por asociación FVD (usuarios.entidad) en torneos finalizados.
 * Puntuación: oro = 5, plata = 3, bronce = 1.
 */
final class PodiosAsociacionesLandingService
{
    /** @var array<int, int> */
    private const PUNTOS_PODIO = [1 => 5, 2 => 3, 3 => 1];

    /** @var array<int, string> */
    private const ETIQUETA_PODIO = [1 => 'Oro', 2 => 'Plata', 3 => 'Bronce'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function hasColumnPublicarLanding(): bool
    {
        try {
            return (bool) $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'publicar_landing'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{
     *   criterio: string,
     *   resumen: list<array{
     *     entidad_id: int,
     *     asociacion: string,
     *     total_puntos: int,
     *     oro: int,
     *     plata: int,
     *     bronce: int,
     *     podios_count: int,
     *     por_torneo: list<array{
     *       torneo_id: int,
     *       torneo_nombre: string,
     *       fechator: string,
     *       oro: int,
     *       plata: int,
     *       bronce: int,
     *       total_puntos: int
     *     }>
     *   }>,
     *   detalle: list<array{
     *     entidad_id: int,
     *     asociacion: string,
     *     torneo_id: int,
     *     torneo_nombre: string,
     *     fechator: string,
     *     posicion: int,
     *     podio: string,
     *     puntos: int
     *   }>
     * }
     */
    public function construirResumen(): array
    {
        $pub = $this->hasColumnPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';
        $wEst = InscritosHelper::sqlWhereActivoConAlias('i');
        $unidadPos = '(t.modalidad IN (2, 3, 4) AND NULLIF(TRIM(i.codigo_equipo), \'\') IS NOT NULL)';
        $posExpr = "CASE WHEN {$unidadPos}
            THEN COALESCE(NULLIF(i.clasiequi, 0), NULLIF(i.posicion, 0), 0)
            ELSE COALESCE(NULLIF(i.posicion, 0), 0)
        END";

        $sql = "
            SELECT
                t.id AS torneo_id,
                t.nombre AS torneo_nombre,
                t.fechator,
                t.modalidad,
                i.codigo_equipo,
                i.id_usuario,
                {$posExpr} AS posicion_efectiva,
                u.entidad AS entidad_id,
                COALESCE(NULLIF(TRIM(c.nombre), ''), '') AS entidad_nombre
            FROM inscritos i
            INNER JOIN tournaments t ON i.torneo_id = t.id
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON c.id = u.entidad
            WHERE t.estatus = 1
              AND COALESCE(t.ranking, 0) = 1
              AND DATE(t.fechator) < CURDATE()
              AND COALESCE(u.entidad, 0) > 0
              AND {$wEst}
              AND {$posExpr} BETWEEN 1 AND 3
              {$pub}
            ORDER BY t.fechator DESC, t.id DESC, posicion_efectiva ASC
        ";

        try {
            $filas = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('PodiosAsociacionesLandingService: ' . $e->getMessage());

            return [
                'criterio' => 'Oro 5 pts · Plata 3 pts · Bronce 1 pt. Solo torneos con ranking activado y asociaciones FVD con entidad.',
                'resumen' => [],
                'detalle' => [],
            ];
        }

        /** @var array<string, true> $vistos */
        $vistos = [];
        $detalle = [];

        foreach ($filas as $row) {
            $pos = (int) ($row['posicion_efectiva'] ?? 0);
            if ($pos < 1 || $pos > 3) {
                continue;
            }
            $tid = (int) ($row['torneo_id'] ?? 0);
            $ent = (int) ($row['entidad_id'] ?? 0);
            if ($tid <= 0 || $ent <= 0) {
                continue;
            }
            $ce = trim((string) ($row['codigo_equipo'] ?? ''));
            $uid = (int) ($row['id_usuario'] ?? 0);
            $unidad = $ce !== '' ? ('eq:' . $ce) : ('u:' . $uid);
            $clave = $tid . '|' . $pos . '|' . $unidad;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;

            $puntos = self::PUNTOS_PODIO[$pos] ?? 0;
            $nomEnt = (string) ($row['entidad_nombre'] ?? '');
            $detalle[] = [
                'entidad_id' => $ent,
                'asociacion' => ClubHelper::etiquetaAsociacion($ent, $nomEnt !== '' ? $nomEnt : null),
                'torneo_id' => $tid,
                'torneo_nombre' => (string) ($row['torneo_nombre'] ?? ''),
                'fechator' => (string) ($row['fechator'] ?? ''),
                'posicion' => $pos,
                'podio' => self::ETIQUETA_PODIO[$pos] ?? (string) $pos,
                'puntos' => $puntos,
            ];
        }

        /** @var array<int, array{entidad_id: int, asociacion: string, total_puntos: int, oro: int, plata: int, bronce: int, podios_count: int}> $porEnt */
        $porEnt = [];
        /** @var array<int, array<int, array{torneo_id: int, torneo_nombre: string, fechator: string, oro: int, plata: int, bronce: int, total_puntos: int}>> $porTorneoEnt */
        $porTorneoEnt = [];
        foreach ($detalle as $item) {
            $eid = (int) $item['entidad_id'];
            $tid = (int) $item['torneo_id'];
            if (! isset($porEnt[$eid])) {
                $porEnt[$eid] = [
                    'entidad_id' => $eid,
                    'asociacion' => (string) $item['asociacion'],
                    'total_puntos' => 0,
                    'oro' => 0,
                    'plata' => 0,
                    'bronce' => 0,
                    'podios_count' => 0,
                ];
            }
            if (! isset($porTorneoEnt[$eid][$tid])) {
                $porTorneoEnt[$eid][$tid] = [
                    'torneo_id' => $tid,
                    'torneo_nombre' => (string) $item['torneo_nombre'],
                    'fechator' => (string) $item['fechator'],
                    'oro' => 0,
                    'plata' => 0,
                    'bronce' => 0,
                    'total_puntos' => 0,
                ];
            }
            $pts = (int) $item['puntos'];
            $porEnt[$eid]['total_puntos'] += $pts;
            $porEnt[$eid]['podios_count']++;
            $porTorneoEnt[$eid][$tid]['total_puntos'] += $pts;
            if ($item['posicion'] === 1) {
                $porEnt[$eid]['oro']++;
                $porTorneoEnt[$eid][$tid]['oro']++;
            } elseif ($item['posicion'] === 2) {
                $porEnt[$eid]['plata']++;
                $porTorneoEnt[$eid][$tid]['plata']++;
            } else {
                $porEnt[$eid]['bronce']++;
                $porTorneoEnt[$eid][$tid]['bronce']++;
            }
        }

        $resumen = [];
        foreach ($porEnt as $eid => $row) {
            $torneos = array_values($porTorneoEnt[$eid] ?? []);
            usort($torneos, static function (array $a, array $b): int {
                return strcmp((string) ($b['fechator'] ?? ''), (string) ($a['fechator'] ?? ''));
            });
            $row['por_torneo'] = $torneos;
            $resumen[] = $row;
        }
        usort($resumen, static function (array $a, array $b): int {
            if ($a['total_puntos'] !== $b['total_puntos']) {
                return $b['total_puntos'] <=> $a['total_puntos'];
            }
            if ($a['oro'] !== $b['oro']) {
                return $b['oro'] <=> $a['oro'];
            }
            if ($a['plata'] !== $b['plata']) {
                return $b['plata'] <=> $a['plata'];
            }
            if ($a['bronce'] !== $b['bronce']) {
                return $b['bronce'] <=> $a['bronce'];
            }

            return strcasecmp((string) $a['asociacion'], (string) $b['asociacion']);
        });

        return [
            'criterio' => 'Oro 5 pts · Plata 3 pts · Bronce 1 pt. Solo torneos finalizados con ranking activado; asociación según entidad FVD del atleta.',
            'resumen' => $resumen,
            'detalle' => $detalle,
        ];
    }
}
