<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/AsociacionAdminHelper.php';
require_once __DIR__ . '/BusquedaJugadorInscripcionService.php';

/**
 * Búsqueda unificada inscripción en sitio → atletas disponibles (no inscritos activos).
 */
final class InscribirSitioBusquedaService
{
    /**
     * @return array{
     *   success: bool,
     *   ya_inscrito?: bool,
     *   mensaje?: string,
     *   items?: list<array<string, mixed>>,
     *   error?: string
     * }
     */
    public static function buscar(
        PDO $pdo,
        int $torneoId,
        ?int $clubId,
        string $nacionalidad,
        string $raw,
        string $cedulaDigits,
        int $userIdParam,
        string $qNombre,
        int $inscritoPorUserId
    ): array {
        if ($torneoId <= 0) {
            return ['success' => false, 'error' => 'Torneo requerido'];
        }
        $clubId = ($clubId !== null && $clubId > 0) ? $clubId : 0;

        $items = [];
        $vistos = [];

        $agregarUsuario = static function (array $persona) use (
            $pdo,
            $torneoId,
            &$items,
            &$vistos
        ): bool {
            $uid = (int) ($persona['id'] ?? 0);
            if ($uid <= 0 || isset($vistos[$uid])) {
                return false;
            }
            if (self::usuarioInscritoActivoEnTorneo($pdo, $torneoId, $uid)) {
                return false;
            }
            $vistos[$uid] = true;
            $items[] = self::filaUsuario($pdo, $persona, false);

            return true;
        };

        // Por ID de usuario o NUMFVD (carnet)
        if ($userIdParam > 0) {
            $persona = self::cargarUsuario($pdo, $userIdParam);
            if ($persona) {
                if (self::usuarioInscritoActivoEnTorneo($pdo, $torneoId, $userIdParam)) {
                    return [
                        'success' => true,
                        'ya_inscrito' => true,
                        'mensaje' => 'El jugador ya está inscrito en este torneo.',
                        'items' => [],
                    ];
                }
                $agregarUsuario($persona);
            }
        }

        if ($items === [] && $cedulaDigits !== '' && strlen($cedulaDigits) >= 3) {
            $personaNumfvd = self::buscarPorNumfvd($pdo, $cedulaDigits);
            if ($personaNumfvd !== null) {
                $uid = (int) ($personaNumfvd['id'] ?? 0);
                if (self::usuarioInscritoActivoEnTorneo($pdo, $torneoId, $uid)) {
                    return [
                        'success' => true,
                        'ya_inscrito' => true,
                        'mensaje' => 'El jugador ya está inscrito en este torneo.',
                        'items' => [],
                    ];
                }
                $agregarUsuario($personaNumfvd);
            }
        }

        // Por cédula
        if ($cedulaDigits !== '' && $items === []) {
            $variantes = array_unique([$cedulaDigits, $nacionalidad . $cedulaDigits]);
            foreach ($variantes as $c) {
                if ($c === '') {
                    continue;
                }
                $usuario = BusquedaJugadorInscripcionService::buscarUsuarioPorCedula($pdo, $nacionalidad, $c);
                if ($usuario) {
                    $uid = (int) ($usuario['id'] ?? 0);
                    if (self::usuarioInscritoActivoEnTorneo($pdo, $torneoId, $uid)) {
                        return [
                            'success' => true,
                            'ya_inscrito' => true,
                            'mensaje' => 'El jugador ya está inscrito en este torneo.',
                            'items' => [],
                        ];
                    }
                    $agregarUsuario($usuario);
                    break;
                }
            }
        }

        // Por nombre (mín. 3 caracteres)
        $lenQ = function_exists('mb_strlen') ? mb_strlen($qNombre, 'UTF-8') : strlen($qNombre);
        if ($qNombre !== '' && $lenQ >= 3 && $items === [] && $userIdParam <= 0) {
            $sqlClub = '';
            $paramsClub = [];
            if ($clubId > 0) {
                [$sqlClub, $paramsClub] = AsociacionAdminHelper::filtroSqlUsuariosPorClub($clubId, 'u');
            }
            $like = '%' . addcslashes($qNombre, '%_\\') . '%';
            $stmt = $pdo->prepare(
                "SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad
                 FROM usuarios u
                 WHERE u.role = 'usuario' AND u.status = 0
                   AND (u.nombre LIKE ? OR u.username LIKE ?)
                   {$sqlClub}
                 ORDER BY COALESCE(u.nombre, u.username) ASC
                 LIMIT 25"
            );
            $stmt->execute(array_merge([$like, $like], $paramsClub));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $persona) {
                $agregarUsuario($persona);
            }
        }

        if ($items !== []) {
            return [
                'success' => true,
                'ya_inscrito' => false,
                'mensaje' => count($items) === 1
                    ? 'Seleccione el atleta en disponibles para inscribir.'
                    : 'Seleccione un atleta de la lista.',
                'items' => $items,
            ];
        }

        // Sin usuario en plataforma: BD externa o alta mínima
        if ($cedulaDigits === '') {
            return [
                'success' => true,
                'ya_inscrito' => false,
                'mensaje' => 'No encontrado. Use cédula (mín. 4 dígitos) o nombre (mín. 3 letras).',
                'items' => [],
            ];
        }

        $creado = self::crearUsuarioDesdeBusqueda($pdo, $torneoId, $nacionalidad, $cedulaDigits, $clubId, $inscritoPorUserId);
        if (!empty($creado['error'])) {
            return ['success' => false, 'error' => $creado['error']];
        }
        $persona = self::cargarUsuario($pdo, (int) ($creado['user_id'] ?? 0));
        if (!$persona) {
            return ['success' => false, 'error' => 'No se pudo registrar el usuario'];
        }
        if (self::usuarioInscritoActivoEnTorneo($pdo, $torneoId, (int) $persona['id'])) {
            return [
                'success' => true,
                'ya_inscrito' => true,
                'mensaje' => 'El jugador ya está inscrito en este torneo.',
                'items' => [],
            ];
        }

        return [
            'success' => true,
            'ya_inscrito' => false,
            'mensaje' => ($creado['nuevo'] ?? false)
                ? 'Usuario registrado. Selecciónelo en disponibles para inscribir.'
                : 'Seleccione el atleta en disponibles para inscribir.',
            'items' => [self::filaUsuario($pdo, $persona, false)],
        ];
    }

    public static function usuarioInscritoActivoEnTorneo(PDO $pdo, int $torneoId, int $idUsuario): bool
    {
        if ($torneoId <= 0 || $idUsuario <= 0) {
            return false;
        }
        $sqlActivo = InscritosHelper::sqlWhereActivoConAlias('i');
        $stmt = $pdo->prepare(
            "SELECT i.id FROM inscritos i
             WHERE i.torneo_id = ? AND i.id_usuario = ? AND {$sqlActivo}
             LIMIT 1"
        );
        $stmt->execute([$torneoId, $idUsuario]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cargarUsuario(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad
             FROM usuarios WHERE id = ? AND role = ? LIMIT 1'
        );
        $stmt->execute([$id, 'usuario']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $persona
     * @return array<string, mixed>
     */
    private static function filaUsuario(PDO $pdo, array $persona, bool $inscritoActivo): array
    {
        $clubRes = AsociacionAdminHelper::resolverClubAtletaDesdeUsuario($pdo, $persona);
        $nom = trim((string) ($persona['nombre'] ?? ''));
        if ($nom === '') {
            $nom = (string) ($persona['username'] ?? '');
        }

        return [
            'id' => (int) ($persona['id'] ?? 0),
            'nombre' => $nom,
            'cedula' => (string) ($persona['cedula'] ?? ''),
            'club_id' => (int) ($clubRes['club_id'] ?? 0),
            'club_nombre' => (string) ($clubRes['club_nombre'] ?? ''),
            'inscrito_activo' => $inscritoActivo,
        ];
    }

    /**
     * @return array{user_id?: int, nuevo?: bool, error?: string}
     */
    /**
     * Club para alta de usuario nuevo: panel, operativo forzado o entidad del torneo.
     */
    public static function resolverClubParaAlta(PDO $pdo, int $torneoId, int $clubIdPanel): int
    {
        if ($clubIdPanel > 0) {
            return $clubIdPanel;
        }
        $forzado = AsociacionAdminHelper::idClubForzadoInscripcion($pdo);
        if ($forzado !== null && $forzado > 0) {
            return $forzado;
        }
        if ($torneoId > 0) {
            $st = $pdo->prepare('SELECT COALESCE(entidad, 0) AS entidad FROM tournaments WHERE id = ? LIMIT 1');
            $st->execute([$torneoId]);
            $ent = (int) ($st->fetchColumn() ?: 0);
            if ($ent > 0) {
                $resolved = AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $ent);

                return $resolved ?? $ent;
            }
        }

        return 0;
    }

    private static function crearUsuarioDesdeBusqueda(
        PDO $pdo,
        int $torneoId,
        string $nacionalidad,
        string $cedula,
        int $clubIdPanel,
        int $inscritoPorUserId
    ): array {
        require_once __DIR__ . '/security.php';

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
        $stmt->execute([$cedula]);
        $existente = (int) ($stmt->fetchColumn() ?: 0);
        if ($existente > 0) {
            return ['user_id' => $existente, 'nuevo' => false];
        }

        $nombre = '';
        $sexo = 'M';
        $fechnac = null;
        $telefono = '';
        $email = '';

        $ext = BusquedaJugadorInscripcionService::buscarPersonaExternaPorCedula($nacionalidad, $cedula);
        if ($ext !== null) {
            $p = $ext['persona'];
            $nombre = trim((string) ($p['nombre'] ?? ''));
            $sexo = strtoupper(trim((string) ($p['sexo'] ?? 'M')));
            if (!in_array($sexo, ['M', 'F', 'O'], true)) {
                $sexo = 'M';
            }
            $fechnac = trim((string) ($p['fechnac'] ?? ''));
            $telefono = trim((string) ($p['celular'] ?? $p['telefono'] ?? ''));
            $email = trim((string) ($p['email'] ?? ''));
        }
        if ($nombre === '') {
            $nombre = 'Atleta ' . $cedula;
        }

        $username = $nacionalidad . $cedula;
        $sufijo = '';
        $idx = 0;
        while (true) {
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE username = ?');
            $st->execute([$username . $sufijo]);
            if (!$st->fetch()) {
                break;
            }
            ++$idx;
            $sufijo = '_' . $idx;
        }
        $username .= $sufijo;

        if ($email === '') {
            $email = 'user' . $cedula . '@inscrito.local';
        }

        $clubId = self::resolverClubParaAlta($pdo, $torneoId, $clubIdPanel);
        if ($clubId <= 0) {
            return ['error' => 'No se pudo determinar la asociación para el alta. Selecciónela en el panel e intente de nuevo.'];
        }

        $createData = [
            'username' => $username,
            'password' => strlen($cedula) >= 6 ? $cedula : str_pad($cedula, 6, '0', STR_PAD_LEFT),
            'role' => 'usuario',
            'nombre' => $nombre,
            'cedula' => $cedula,
            'nacionalidad' => $nacionalidad,
            'sexo' => $sexo,
            'fechnac' => $fechnac !== '' ? $fechnac : null,
            'email' => $email,
            'celular' => $telefono,
            'club_id' => $clubId,
            'entidad' => $clubId,
            '_allow_club_for_usuario' => true,
        ];

        require_once __DIR__ . '/InscritosHelper.php';
        $nf = InscritosHelper::resolverNumfvdDesdeCedula($pdo, $cedula);
        if ($nf > 0) {
            $createData['numfvd'] = $nf;
        }

        $create = Security::createUser($createData);
        if (!empty($create['errors'])) {
            return ['error' => implode(', ', $create['errors'])];
        }
        $uid = (int) ($create['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['error' => 'No se pudo crear el usuario'];
        }

        return ['user_id' => $uid, 'nuevo' => true];
    }

    /**
     * Atletas disponibles de una asociación (no inscritos activos), activos primero.
     *
     * @return array{success: bool, items?: list<array<string, mixed>>, mensaje?: string, error?: string}
     */
    public static function listarDisponiblesPorClub(PDO $pdo, int $torneoId, ?int $clubId, int $limite = 200): array
    {
        if ($torneoId <= 0) {
            return ['success' => false, 'error' => 'Torneo requerido'];
        }

        $sqlActivo = InscritosHelper::sqlWhereActivoConAlias('i');
        $sqlClub = '';
        $params = [$torneoId];
        if ($clubId !== null && $clubId > 0) {
            [$sqlClub, $paramsClub] = AsociacionAdminHelper::filtroSqlUsuariosPorClub($clubId, 'u');
            $params = array_merge($params, $paramsClub);
        }

        $hasNumfvd = false;
        try {
            $hasNumfvd = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetchColumn();
        } catch (Throwable $e) {
        }

        $cols = 'u.id, u.username, u.nacionalidad, u.nombre, u.cedula, u.sexo, u.celular, u.club_id, u.entidad, u.status';
        if ($hasNumfvd) {
            $cols .= ', u.numfvd';
        }

        $stmt = $pdo->prepare(
            "SELECT {$cols}
             FROM usuarios u
             WHERE u.role = 'usuario'
               AND NOT EXISTS (
                   SELECT 1 FROM inscritos i
                   WHERE i.torneo_id = ? AND i.id_usuario = u.id AND {$sqlActivo}
               )
               {$sqlClub}
             ORDER BY u.status ASC, COALESCE(u.nombre, u.username) ASC
             LIMIT " . (int) $limite
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $persona) {
            $items[] = self::filaUsuario($pdo, $persona, false);
        }

        return [
            'success' => true,
            'items' => $items,
            'mensaje' => $items === []
                ? 'No hay atletas disponibles para esta asociación.'
                : count($items) . ' atleta(s) disponible(s). Seleccione uno para inscribir.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buscarPorNumfvd(PDO $pdo, string $digits): ?array
    {
        if ($digits === '') {
            return null;
        }
        try {
            if (!(bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetchColumn()) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }
        $num = (int) $digits;
        if ($num <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, entidad, numfvd
             FROM usuarios WHERE role = 'usuario' AND numfvd = ? LIMIT 1"
        );
        $stmt->execute([$num]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
