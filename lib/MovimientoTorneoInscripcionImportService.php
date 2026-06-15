<?php
/**
 * Importa inscritos confirmados desde movimiento_torneo hacia inscritos,
 * respetando campeonatos por género y categorías SUB (parent_event_id).
 */
declare(strict_types=1);

require_once __DIR__ . '/CampeonatoTorneoHelper.php';
require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/FinanzasAsociacionData.php';
require_once __DIR__ . '/InscritosHelper.php';

final class MovimientoTorneoInscripcionImportService
{
    /**
     * Resumen para el panel: movimientos con inscripción pendientes de cargar.
     *
     * @return array{
     *   disponible: bool,
     *   pendientes: int,
     *   total_movimientos: int,
     *   torneos_destino: list<int>,
     *   es_campeonato: bool
     * }
     */
    public static function resumenParaPanel(PDO $pdo, int $torneoPanelId): array
    {
        $base = [
            'disponible' => false,
            'pendientes' => 0,
            'total_movimientos' => 0,
            'torneos_destino' => [],
            'es_campeonato' => false,
        ];
        if ($torneoPanelId <= 0 || !FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {
            return $base;
        }

        $ctx = self::contextoEvento($pdo, $torneoPanelId);
        if ($ctx === null) {
            return $base;
        }

        $movimientos = self::listarMovimientosInscripcion($pdo, $ctx['torneo_ids_consulta']);
        $base['total_movimientos'] = count($movimientos);
        $base['torneos_destino'] = $ctx['torneos_destino_ids'];
        $base['es_campeonato'] = count($ctx['torneos_destino']) > 1;

        foreach ($movimientos as $mov) {
            $idUsuario = self::resolverIdUsuario($pdo, $mov);
            if ($idUsuario === null) {
                continue;
            }
            $torneoDestino = self::resolverTorneoDestino($pdo, $mov, $ctx, $idUsuario);
            if ($torneoDestino === null) {
                continue;
            }
            if (self::usuarioYaInscrito($pdo, $idUsuario, $torneoDestino)) {
                continue;
            }
            $base['pendientes']++;
        }

        $base['disponible'] = $base['pendientes'] > 0;

        return $base;
    }

    /**
     * @return array{
     *   ok: bool,
     *   insertados: int,
     *   omitidos: int,
     *   errores: list<string>,
     *   por_torneo: array<int, int>
     * }
     */
    public static function importarInscritos(PDO $pdo, int $torneoPanelId, ?int $inscritoPor = null): array
    {
        $resultado = [
            'ok' => false,
            'insertados' => 0,
            'omitidos' => 0,
            'errores' => [],
            'por_torneo' => [],
        ];

        if ($torneoPanelId <= 0) {
            $resultado['errores'][] = 'Torneo no válido.';
            return $resultado;
        }
        if (!FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {
            $resultado['errores'][] = 'La tabla movimiento_torneo no está disponible.';
            return $resultado;
        }

        $ctx = self::contextoEvento($pdo, $torneoPanelId);
        if ($ctx === null) {
            $resultado['errores'][] = 'No se pudo resolver el evento del torneo.';
            return $resultado;
        }

        $movimientos = self::listarMovimientosInscripcion($pdo, $ctx['torneo_ids_consulta']);
        if ($movimientos === []) {
            $resultado['errores'][] = 'No hay movimientos de inscripción para este evento.';
            return $resultado;
        }

        $clubCol = FvdMovimientoTorneoHelper::clubColumn($pdo);

        foreach ($movimientos as $mov) {
            $idUsuario = self::resolverIdUsuario($pdo, $mov);
            if ($idUsuario === null) {
                $resultado['omitidos']++;
                $resultado['errores'][] = 'Movimiento #' . (int) ($mov['id'] ?? 0) . ': usuario no identificado (cédula/NUMFVD).';
                continue;
            }

            $torneoDestino = self::resolverTorneoDestino($pdo, $mov, $ctx, $idUsuario);
            if ($torneoDestino === null) {
                $resultado['omitidos']++;
                $nombre = trim((string) ($mov['nombre_usuario'] ?? ''));
                $resultado['errores'][] = ($nombre !== '' ? $nombre : 'Usuario #' . $idUsuario)
                    . ': no coincide con ningún torneo del campeonato (género/edad).';
                continue;
            }

            if (self::usuarioYaInscrito($pdo, $idUsuario, $torneoDestino)) {
                $resultado['omitidos']++;
                continue;
            }

            $idClub = (int) ($mov[$clubCol] ?? $mov['id_club'] ?? 0);
            $datos = [
                'id_usuario' => $idUsuario,
                'torneo_id' => $torneoDestino,
                'estatus' => InscritosHelper::ESTATUS_CONFIRMADO_NUM,
                'id_club' => $idClub > 0 ? $idClub : null,
            ];
            if ($inscritoPor !== null && $inscritoPor > 0) {
                $datos['inscrito_por'] = $inscritoPor;
            }

            try {
                InscritosHelper::insertarInscrito($pdo, $datos);
                $resultado['insertados']++;
                $resultado['por_torneo'][$torneoDestino] = ($resultado['por_torneo'][$torneoDestino] ?? 0) + 1;
            } catch (Throwable $e) {
                $resultado['omitidos']++;
                $msg = $e->getMessage();
                $nombre = trim((string) ($mov['nombre_usuario'] ?? ''));
                $resultado['errores'][] = ($nombre !== '' ? $nombre : 'Usuario #' . $idUsuario) . ': ' . $msg;
            }
        }

        $resultado['ok'] = $resultado['insertados'] > 0;

        return $resultado;
    }

    /**
     * @return array{
     *   root_id: int,
     *   torneo_ids_consulta: list<int>,
     *   torneos_destino: list<array<string, mixed>>,
     *   torneos_destino_ids: list<int>,
     *   torneos_por_id: array<int, array<string, mixed>>
     * }|null
     */
    private static function contextoEvento(PDO $pdo, int $torneoPanelId): ?array
    {
        $grupo = CampeonatoTorneoHelper::torneosGrupoEvento($pdo, $torneoPanelId);
        if ($grupo === []) {
            return null;
        }

        $torneoIdsConsulta = [];
        foreach ($grupo as $t) {
            $tid = (int) ($t['id'] ?? 0);
            if ($tid > 0) {
                $torneoIdsConsulta[] = $tid;
            }
        }
        $torneoIdsConsulta = array_values(array_unique($torneoIdsConsulta));

        $parentCol = 0;
        foreach ($grupo as $t) {
            $p = (int) ($t['parent_event_id'] ?? 0);
            if ($p > 0) {
                $parentCol = $p;
                break;
            }
        }
        $rootId = $parentCol > 0 ? $parentCol : $torneoPanelId;

        $destino = [];
        foreach ($grupo as $t) {
            $tid = (int) ($t['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $genero = strtoupper(trim((string) ($t['genero_requerido'] ?? '')));
            $edadMax = (int) ($t['edad_maxima'] ?? 0);
            $grupoNombre = trim((string) ($t['campeonato_grupo'] ?? ''));
            $esHijo = ((int) ($t['parent_event_id'] ?? 0)) === $rootId && $tid !== $rootId;
            if ($genero === 'M' || $genero === 'F' || $edadMax > 0 || $grupoNombre !== '' || $esHijo) {
                $full = CampeonatoTorneoHelper::cargarTorneo($pdo, $tid);
                if ($full) {
                    $destino[] = $full;
                }
            }
        }

        if ($destino === []) {
            $full = CampeonatoTorneoHelper::cargarTorneo($pdo, $torneoPanelId);
            if ($full) {
                $destino[] = $full;
            }
        }

        $porId = [];
        $destinoIds = [];
        foreach ($destino as $t) {
            $tid = (int) ($t['id'] ?? 0);
            if ($tid > 0) {
                $porId[$tid] = $t;
                $destinoIds[] = $tid;
            }
        }

        return [
            'root_id' => $rootId,
            'torneo_ids_consulta' => $torneoIdsConsulta,
            'torneos_destino' => $destino,
            'torneos_destino_ids' => $destinoIds,
            'torneos_por_id' => $porId,
        ];
    }

    /**
     * @param list<int> $torneoIds
     * @return list<array<string, mixed>>
     */
    private static function listarMovimientosInscripcion(PDO $pdo, array $torneoIds): array
    {
        if ($torneoIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($torneoIds), '?'));
        $sql = "
            SELECT m.*, u.nombre AS nombre_usuario
            FROM movimiento_torneo m
            LEFT JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.inscripcion > 0
              AND m.torneo_id IN ({$placeholders})
            ORDER BY m.id_usuario ASC, m.id DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute($torneoIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $vistos = [];
        $unicos = [];
        foreach ($rows as $row) {
            $clave = self::claveMovimiento($row);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $unicos[] = $row;
        }

        return $unicos;
    }

    /** @param array<string, mixed> $mov */
    private static function claveMovimiento(array $mov): string
    {
        $idUsuario = (int) ($mov['id_usuario'] ?? 0);
        if ($idUsuario > 0) {
            return 'u:' . $idUsuario;
        }
        $cedula = trim((string) ($mov['cedula'] ?? ''));
        if ($cedula !== '') {
            return 'c:' . $cedula;
        }
        $numfvd = (int) ($mov['numfvd'] ?? 0);
        if ($numfvd > 0) {
            return 'n:' . $numfvd;
        }

        return 'id:' . (int) ($mov['id'] ?? 0);
    }

    /** @param array<string, mixed> $mov */
    private static function resolverIdUsuario(PDO $pdo, array $mov): ?int
    {
        $id = (int) ($mov['id_usuario'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $cedula = trim((string) ($mov['cedula'] ?? ''));
        if ($cedula !== '') {
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
            $st->execute([$cedula]);
            $uid = (int) $st->fetchColumn();
            if ($uid > 0) {
                return $uid;
            }
        }
        $numfvd = (int) ($mov['numfvd'] ?? 0);
        if ($numfvd > 0) {
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE numfvd = ? LIMIT 1');
            $st->execute([$numfvd]);
            $uid = (int) $st->fetchColumn();
            if ($uid > 0) {
                return $uid;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $mov
     * @param array{
     *   torneos_destino: list<array<string, mixed>>,
     *   torneos_por_id: array<int, array<string, mixed>>
     * } $ctx
     */
    private static function resolverTorneoDestino(PDO $pdo, array $mov, array $ctx, int $idUsuario): ?int
    {
        $usuario = CampeonatoTorneoHelper::cargarUsuario($pdo, $idUsuario);
        if (!$usuario) {
            return null;
        }
        $usuario = self::enriquecerUsuarioDesdeMovimiento($usuario, $mov);

        $movTid = (int) ($mov['torneo_id'] ?? 0);
        if ($movTid > 0 && isset($ctx['torneos_por_id'][$movTid])) {
            $torneo = $ctx['torneos_por_id'][$movTid];
            if (CampeonatoTorneoHelper::validarElegibilidadInscripcion($torneo, $usuario) === null) {
                return $movTid;
            }
        }

        $candidatos = [];
        foreach ($ctx['torneos_destino'] as $torneo) {
            if (CampeonatoTorneoHelper::validarElegibilidadInscripcion($torneo, $usuario) === null) {
                $candidatos[] = $torneo;
            }
        }

        if ($candidatos === []) {
            return null;
        }
        if (count($candidatos) === 1) {
            return (int) $candidatos[0]['id'];
        }

        $conEdad = array_values(array_filter(
            $candidatos,
            static fn (array $t): bool => (int) ($t['edad_maxima'] ?? 0) > 0
        ));
        if ($conEdad !== []) {
            usort($conEdad, static fn (array $a, array $b): int => (int) ($a['edad_maxima'] ?? 0) <=> (int) ($b['edad_maxima'] ?? 0));

            return (int) $conEdad[0]['id'];
        }

        return (int) $candidatos[0]['id'];
    }

    /** @param array<string, mixed> $usuario @param array<string, mixed> $mov */
    private static function enriquecerUsuarioDesdeMovimiento(array $usuario, array $mov): array
    {
        $sexo = trim((string) ($usuario['sexo'] ?? ''));
        if ($sexo === '' || $sexo === '0') {
            $movSexo = (int) ($mov['sexo'] ?? 0);
            if ($movSexo === 1) {
                $usuario['sexo'] = 'M';
            } elseif ($movSexo === 2) {
                $usuario['sexo'] = 'F';
            }
        } else {
            $norm = CampeonatoTorneoHelper::sexoNormalizado($sexo);
            if ($norm !== '') {
                $usuario['sexo'] = $norm;
            }
        }

        return $usuario;
    }

    private static function usuarioYaInscrito(PDO $pdo, int $idUsuario, int $torneoId): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND '
            . InscritosHelper::SQL_WHERE_NO_RETIRADO
            . ' LIMIT 1'
        );
        $st->execute([$idUsuario, $torneoId]);

        return (bool) $st->fetchColumn();
    }
}
