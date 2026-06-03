<?php

declare(strict_types=1);

require_once __DIR__ . '/NumfvdHelper.php';
require_once __DIR__ . '/PartiresulEstatusSql.php';

/**
 * Clave de jugador en partiresul: columna numfvd (preferida) con compatibilidad id_usuario legado.
 * En producción puede existir solo numfvd (id_usuario eliminado de partiresul).
 */
final class PartiresulJugadorHelper
{
    private static ?bool $tieneColumnaNumfvd = null;
    private static ?bool $tieneColumnaIdUsuario = null;

    public static function resetCacheForTests(): void
    {
        self::$tieneColumnaNumfvd = null;
        self::$tieneColumnaIdUsuario = null;
    }

    /**
     * Relee columnas de partiresul con SHOW COLUMNS (fiable en cPanel; evita caché erróneo).
     */
    public static function refrescarEsquemaPartiresul(?\PDO $pdo = null): void
    {
        self::$tieneColumnaNumfvd = null;
        self::$tieneColumnaIdUsuario = null;
        self::cargarEsquemaPartiresul($pdo);
    }

    public static function tieneColumnaNumfvd(?\PDO $pdo = null): bool
    {
        if (self::$tieneColumnaNumfvd === null) {
            self::cargarEsquemaPartiresul($pdo);
        }

        return self::$tieneColumnaNumfvd;
    }

    public static function tieneColumnaIdUsuario(?\PDO $pdo = null): bool
    {
        if (self::$tieneColumnaIdUsuario === null) {
            self::cargarEsquemaPartiresul($pdo);
        }

        return self::$tieneColumnaIdUsuario;
    }

    private static function cargarEsquemaPartiresul(?\PDO $pdo = null): void
    {
        if (self::envForzarSoloNumfvd()) {
            self::$tieneColumnaNumfvd = true;
            self::$tieneColumnaIdUsuario = false;

            return;
        }

        $pdo = $pdo ?? self::resolverPdo();
        if (!$pdo) {
            self::$tieneColumnaNumfvd = false;
            self::$tieneColumnaIdUsuario = true;

            return;
        }

        $columnas = [];
        try {
            $st = $pdo->query('SHOW COLUMNS FROM partiresul');
            if ($st !== false) {
                while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                    $field = strtolower((string) ($row['Field'] ?? ''));
                    if ($field !== '') {
                        $columnas[$field] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $columnas = [];
        }

        if ($columnas === []) {
            try {
                $st = $pdo->prepare(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'partiresul\''
                );
                $st->execute();
                while ($name = $st->fetchColumn()) {
                    $columnas[strtolower((string) $name)] = true;
                }
            } catch (\Throwable $e) {
                // Sin introspección: asumir esquema clásico (id_usuario) para no romper INSERT.
                self::$tieneColumnaNumfvd = false;
                self::$tieneColumnaIdUsuario = true;

                return;
            }
        }

        self::$tieneColumnaNumfvd = isset($columnas['numfvd']);
        self::$tieneColumnaIdUsuario = isset($columnas['id_usuario']);
    }

    private static function envForzarSoloNumfvd(): bool
    {
        $v = getenv('FVD_PARTIRESUL_SOLO_NUMFVD');
        if ($v === false || $v === '') {
            $v = $_ENV['FVD_PARTIRESUL_SOLO_NUMFVD'] ?? $_SERVER['FVD_PARTIRESUL_SOLO_NUMFVD'] ?? '';
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    /** partiresul solo expone numfvd (sin columna id_usuario). */
    public static function soloNumfvdEnPartiresul(?\PDO $pdo = null): bool
    {
        return self::tieneColumnaNumfvd($pdo) && !self::tieneColumnaIdUsuario($pdo);
    }

    /**
     * Expresión SQL de la clave pública del jugador en partiresul (para SELECT/GROUP BY).
     */
    public static function sqlExprClaveJugador(string $alias = 'pr', ?\PDO $pdo = null): string
    {
        if ($alias !== '') {
            self::validarAlias($alias);
        }
        $p = $alias !== '' ? $alias . '.' : '';
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return 'COALESCE(NULLIF(' . $p . 'numfvd, 0), 0)';
        }
        if (self::tieneColumnaNumfvd($pdo)) {
            return 'COALESCE(NULLIF(' . $p . 'numfvd, 0), ' . $p . 'id_usuario)';
        }
        if (self::tieneColumnaIdUsuario($pdo)) {
            return $p . 'id_usuario';
        }

        return 'COALESCE(NULLIF(' . $p . 'numfvd, 0), 0)';
    }

    /**
     * JOIN inscritos ↔ partiresul por torneo y clave de jugador (numfvd o id_usuario).
     */
    /** ON inscritos ↔ partiresul (p. ej. LEFT JOIN partiresul p ON …). */
    public static function sqlOnInscritosPartiresul(string $iAlias = 'i', string $prAlias = 'p'): string
    {
        self::validarAlias($prAlias);
        self::validarAlias($iAlias);
        if (!self::tieneColumnaNumfvd()) {
            return $iAlias . '.id_usuario = ' . $prAlias . '.id_usuario
                AND ' . $iAlias . '.torneo_id = ' . $prAlias . '.id_torneo';
        }
        if (self::soloNumfvdEnPartiresul()) {
            return self::sqlOnInscritosPartiresulSoloNumfvd($iAlias, $prAlias);
        }

        require_once __DIR__ . '/NumfvdHelper.php';
        $onNf = NumfvdHelper::inscritosTieneColumnaNumfvd(self::resolverPdo())
            ? '(' . $iAlias . '.numfvd > 0 AND ' . $iAlias . '.numfvd = ' . $prAlias . '.numfvd)'
            : 'EXISTS (
                SELECT 1 FROM usuarios u_on_ip
                WHERE u_on_ip.id = ' . $iAlias . '.id_usuario
                  AND u_on_ip.numfvd > 0
                  AND u_on_ip.numfvd = ' . $prAlias . '.numfvd
            )';

        return $iAlias . '.torneo_id = ' . $prAlias . '.id_torneo
            AND (
                ' . $onNf . '
                OR (
                    COALESCE(NULLIF(' . $prAlias . '.numfvd, 0), 0) = 0
                    AND ' . $iAlias . '.id_usuario = ' . $prAlias . '.id_usuario
                )
            )';
    }

    public static function sqlJoinInscritos(string $prAlias = 'pr', string $iAlias = 'i'): string
    {
        self::validarAlias($prAlias);
        self::validarAlias($iAlias);
        if (self::tieneColumnaNumfvd()) {
            if (self::soloNumfvdEnPartiresul()) {
                return 'INNER JOIN inscritos ' . $iAlias . ' ON (' . self::sqlOnInscritosPartiresulSoloNumfvd($iAlias, $prAlias) . ')';
            }

            require_once __DIR__ . '/NumfvdHelper.php';
            $onNf = NumfvdHelper::inscritosTieneColumnaNumfvd(self::resolverPdo())
                ? '(' . $iAlias . '.numfvd > 0 AND ' . $iAlias . '.numfvd = ' . $prAlias . '.numfvd)'
                : 'EXISTS (
                    SELECT 1 FROM usuarios u_ij
                    WHERE u_ij.id = ' . $iAlias . '.id_usuario
                      AND u_ij.numfvd > 0
                      AND u_ij.numfvd = ' . $prAlias . '.numfvd
                )';

            return 'INNER JOIN inscritos ' . $iAlias . ' ON (
                ' . $iAlias . '.torneo_id = ' . $prAlias . '.id_torneo
                AND (
                    ' . $onNf . '
                    OR (
                        COALESCE(NULLIF(' . $prAlias . '.numfvd, 0), 0) = 0
                        AND ' . $iAlias . '.id_usuario = ' . $prAlias . '.id_usuario
                    )
                )
            )';
        }

        return 'INNER JOIN inscritos ' . $iAlias . ' ON (
            ' . $iAlias . '.torneo_id = ' . $prAlias . '.id_torneo
            AND ' . $iAlias . '.id_usuario = ' . $prAlias . '.id_usuario
        )';
    }

    /**
     * JOIN agg (subconsulta con clave_jugador) → inscritos.
     *
     * @param string $aggAlias alias de la subconsulta agregada (debe exponer clave_jugador e id_torneo)
     */
    public static function sqlOnInscritosDesdeAgg(string $aggAlias = 'agg', string $iAlias = 'i'): string
    {
        self::validarAlias($aggAlias);
        self::validarAlias($iAlias);
        if (self::tieneColumnaNumfvd()) {
            require_once __DIR__ . '/NumfvdHelper.php';
            $pdo = self::resolverPdo();
            if (NumfvdHelper::inscritosTieneColumnaNumfvd($pdo)) {
                return $iAlias . '.torneo_id = ' . $aggAlias . '.id_torneo
                    AND (
                        (' . $iAlias . '.numfvd > 0 AND ' . $iAlias . '.numfvd = ' . $aggAlias . '.clave_jugador)
                        OR (
                            COALESCE(NULLIF(' . $iAlias . '.numfvd, 0), 0) = 0
                            AND ' . $iAlias . '.id_usuario = ' . $aggAlias . '.clave_jugador
                        )
                    )';
            }

            return $iAlias . '.torneo_id = ' . $aggAlias . '.id_torneo
                AND (
                    EXISTS (
                        SELECT 1 FROM usuarios u_agg
                        WHERE u_agg.id = ' . $iAlias . '.id_usuario
                          AND u_agg.numfvd > 0
                          AND u_agg.numfvd = ' . $aggAlias . '.clave_jugador
                    )
                    OR ' . $iAlias . '.id_usuario = ' . $aggAlias . '.clave_jugador
                )';
        }

        return $iAlias . '.id_usuario = ' . $aggAlias . '.clave_jugador
            AND ' . $iAlias . '.torneo_id = ' . $aggAlias . '.id_torneo';
    }

    /**
     * SELECT de la clave en partiresul con alias id_usuario (compat. código legado).
     */
    public static function sqlSelectClaveJugadorComoIdUsuario(string $alias = 'pr', ?\PDO $pdo = null): string
    {
        if ($alias !== '') {
            self::validarAlias($alias);
        }

        return self::sqlExprClaveJugador($alias, $pdo) . ' AS id_usuario';
    }

    public static function sqlJoinInscritosDesdeAgg(string $aggAlias = 'agg', string $iAlias = 'i'): string
    {
        self::validarAlias($iAlias);

        return 'INNER JOIN inscritos ' . $iAlias . ' ON (' . self::sqlOnInscritosDesdeAgg($aggAlias, $iAlias) . ')';
    }

    /**
     * Valores posibles al buscar filas en partiresul (numfvd + legado en id_usuario).
     *
     * @return list<int>
     */
    public static function clavesBusqueda(\PDO $pdo, int $torneoId, int $idUsuarioInterno): array
    {
        if ($idUsuarioInterno <= 0) {
            return [];
        }
        if (!self::tieneColumnaNumfvd($pdo)) {
            return NumfvdHelper::clavesPartiresulIdUsuario($pdo, $torneoId, $idUsuarioInterno);
        }
        $nf = NumfvdHelper::numfvdInscrito($pdo, $torneoId, $idUsuarioInterno);
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return $nf > 0 ? [$nf] : [];
        }
        $claves = [];
        if ($nf > 0) {
            $claves[] = $nf;
        }
        $claves[] = $idUsuarioInterno;

        return array_values(array_unique($claves));
    }

    /**
     * Claves en partiresul a partir de NUMFVD o id interno recibido en formulario.
     *
     * @return list<int>
     */
    public static function clavesBusquedaDesdeIdentificador(\PDO $pdo, int $torneoId, int $identificador): array
    {
        if ($identificador <= 0) {
            return [];
        }
        $uid = NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneoId, $identificador);
        if ($uid !== null) {
            return self::clavesBusqueda($pdo, $torneoId, $uid);
        }
        if (self::tieneColumnaNumfvd($pdo)) {
            return [$identificador];
        }

        return NumfvdHelper::clavesPartiresulIdUsuario($pdo, $torneoId, $identificador);
    }

    /**
     * WHERE partiresul.{numfvd|id_usuario} IN (...).
     *
     * @param list<int> $claves
     */
    public static function sqlWhereClaveIn(string $alias, array $claves): string
    {
        self::validarAlias($alias);
        if ($claves === []) {
            return '1=0';
        }
        $col = self::soloNumfvdEnPartiresul() || self::tieneColumnaNumfvd()
            ? $alias . '.numfvd'
            : $alias . '.id_usuario';

        return $col . ' IN (' . implode(',', array_fill(0, count($claves), '?')) . ')';
    }

    /** Secuencia del compañero de pareja (1↔2, 3↔4). */
    public static function secuenciaCompanero(int $secuencia): int
    {
        if ($secuencia === 1) {
            return 2;
        }
        if ($secuencia === 2) {
            return 1;
        }
        if ($secuencia === 3) {
            return 4;
        }
        if ($secuencia === 4) {
            return 3;
        }

        return 0;
    }

    /** Secuencias de la pareja contraria. */
    public static function secuenciasContrarios(int $secuencia): array
    {
        if ($secuencia === 1 || $secuencia === 2) {
            return [3, 4];
        }
        if ($secuencia === 3 || $secuencia === 4) {
            return [1, 2];
        }

        return [];
    }

    /**
     * Todos los jugadores de una mesa (siempre 4 filas en partiresul), con nombre y club.
     *
     * @return array<int, array{secuencia: int, clave_jugador: int, id_usuario: int, nombre_completo: string, club_nombre: string}>
     */
    public static function fetchJugadoresMesa(\PDO $pdo, int $torneoId, int $partida, int $mesa): array
    {
        if ($torneoId <= 0 || $partida <= 0 || $mesa <= 0) {
            return [];
        }
        $exprClave = self::sqlExprClaveJugador('pr', $pdo);
        $st = $pdo->prepare(
            "SELECT pr.secuencia, {$exprClave} AS clave_jugador
             FROM partiresul pr
             WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
             ORDER BY pr.secuencia ASC"
        );
        $st->execute([$torneoId, $partida, $mesa]);
        $porSecuencia = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sec = (int) ($row['secuencia'] ?? 0);
            if ($sec < 1 || $sec > 4) {
                continue;
            }
            $clave = (int) ($row['clave_jugador'] ?? 0);
            $datos = self::resolverDatosJugadorEnTorneo($pdo, $torneoId, $clave);
            $porSecuencia[$sec] = [
                'secuencia' => $sec,
                'clave_jugador' => $clave,
                'id_usuario' => (int) ($datos['id_usuario'] ?? 0),
                'nombre_completo' => (string) ($datos['nombre_completo'] ?? '—'),
                'club_nombre' => (string) ($datos['club_nombre'] ?? 'Sin Club'),
            ];
        }

        return $porSecuencia;
    }

    /**
     * @return array{id_usuario: int, nombre_completo: string, club_nombre: string}
     */
    public static function resolverDatosJugadorEnTorneo(\PDO $pdo, int $torneoId, int $claveJugador): array
    {
        $vacio = ['id_usuario' => 0, 'nombre_completo' => '—', 'club_nombre' => 'Sin Club'];
        if ($torneoId <= 0 || $claveJugador <= 0) {
            return $vacio;
        }
        require_once __DIR__ . '/NumfvdHelper.php';
        $uid = NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneoId, $claveJugador);
        if ($uid === null || $uid <= 0) {
            $stU = $pdo->prepare(
                'SELECT u.id, COALESCE(u.nombre, u.username) AS nombre
                 FROM usuarios u
                 WHERE u.id = ? OR u.numfvd = ?
                 LIMIT 1'
            );
            $stU->execute([$claveJugador, $claveJugador]);
            $u = $stU->fetch(\PDO::FETCH_ASSOC);
            if (!$u) {
                return $vacio;
            }
            $uid = (int) ($u['id'] ?? 0);
            $nombre = (string) ($u['nombre'] ?? '—');
            $club = 'Sin Club';
            if ($uid > 0) {
                $stC = $pdo->prepare(
                    'SELECT COALESCE(c.nombre, \'Sin Club\') AS club_nombre
                     FROM inscritos i LEFT JOIN clubes c ON c.id = i.id_club
                     WHERE i.torneo_id = ? AND i.id_usuario = ? LIMIT 1'
                );
                $stC->execute([$torneoId, $uid]);
                $club = (string) ($stC->fetchColumn() ?: 'Sin Club');
            }

            return ['id_usuario' => $uid, 'nombre_completo' => $nombre, 'club_nombre' => $club];
        }

        $exprNf = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo, 'u');
        $st = $pdo->prepare(
            "SELECT i.id_usuario, COALESCE(u.nombre, u.username) AS nombre_completo,
                    COALESCE(c.nombre, 'Sin Club') AS club_nombre, {$exprNf} AS numfvd_ins
             FROM inscritos i
             INNER JOIN usuarios u ON u.id = i.id_usuario
             LEFT JOIN clubes c ON c.id = i.id_club
             WHERE i.torneo_id = ? AND (i.id_usuario = ? OR {$exprNf} = ? OR u.numfvd = ?)
             LIMIT 1"
        );
        $st->execute([$torneoId, $uid, $claveJugador, $claveJugador]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return $vacio;
        }

        return [
            'id_usuario' => (int) ($row['id_usuario'] ?? $uid),
            'nombre_completo' => (string) ($row['nombre_completo'] ?? '—'),
            'club_nombre' => (string) ($row['club_nombre'] ?? 'Sin Club'),
        ];
    }

    /**
     * Pareja y contrarios para un jugador en mesa (dominó 4 jugadores).
     *
     * @return array{companero: ?array{nombre: string, club: string, id_usuario: int}, contrarios: list<array{nombre: string, club: string, id_usuario: int}>}
     */
    public static function parejaYContrariosEnMesa(\PDO $pdo, int $torneoId, int $partida, int $mesa, int $secuenciaJugador): array
    {
        $mesaMap = self::fetchJugadoresMesa($pdo, $torneoId, $partida, $mesa);
        $companero = null;
        $contrarios = [];
        $secComp = self::secuenciaCompanero($secuenciaJugador);
        if ($secComp > 0 && isset($mesaMap[$secComp])) {
            $j = $mesaMap[$secComp];
            $companero = [
                'nombre' => $j['nombre_completo'],
                'club' => $j['club_nombre'],
                'id_usuario' => $j['id_usuario'],
            ];
        }
        foreach (self::secuenciasContrarios($secuenciaJugador) as $secContr) {
            if (!isset($mesaMap[$secContr])) {
                continue;
            }
            $j = $mesaMap[$secContr];
            $contrarios[] = [
                'nombre' => $j['nombre_completo'],
                'club' => $j['club_nombre'],
                'id_usuario' => $j['id_usuario'],
            ];
        }

        return ['companero' => $companero, 'contrarios' => $contrarios];
    }

    /**
     * Datos para INSERT: mantiene id_usuario (FK) y rellena numfvd cuando existe la columna.
     *
     * @return array{id_usuario?: int, numfvd: int}
     */
    public static function datosInsertJugador(\PDO $pdo, int $torneoId, int $idUsuarioInterno): array
    {
        $idUsuario = max(0, $idUsuarioInterno);
        $nf = NumfvdHelper::numfvdInscrito($pdo, $torneoId, $idUsuario);
        if ($nf <= 0) {
            $nf = $idUsuario;
        }
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return ['numfvd' => $nf];
        }
        if (!self::tieneColumnaNumfvd($pdo)) {
            return ['id_usuario' => $idUsuario, 'numfvd' => $nf];
        }

        return ['id_usuario' => $idUsuario, 'numfvd' => $nf];
    }

    /** Columna(s) de clave de jugador en INSERT INTO partiresul (tras id_torneo). */
    public static function fragmentoColumnasInsertClave(?\PDO $pdo = null): string
    {
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return 'numfvd';
        }

        return 'id_usuario' . self::sufijoColumnasInsert($pdo);
    }

    /** Marcadores ? para fragmentoColumnasInsertClave. */
    public static function fragmentoMarcadoresInsertClave(?\PDO $pdo = null): string
    {
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return '?';
        }

        return '?' . self::sufijoMarcadorInsert($pdo);
    }

    /**
     * Valores de clave de jugador para una fila INSERT (en orden de fragmentoColumnasInsertClave).
     *
     * @param array{id_usuario?: int, numfvd: int} $datos
     * @return list<int>
     */
    public static function valoresInsertClave(array $datos, ?\PDO $pdo = null): array
    {
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return [self::valorSufijoInsert($datos)];
        }
        $vals = [(int) ($datos['id_usuario'] ?? $datos['numfvd'])];
        if (self::tieneColumnaNumfvd($pdo)) {
            $vals[] = self::valorSufijoInsert($datos);
        }

        return $vals;
    }

    /**
     * Columnas extra en INSERT batch de asignación (después de id_usuario).
     */
    public static function sufijoColumnasInsert(?\PDO $pdo = null): string
    {
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return '';
        }

        return self::tieneColumnaNumfvd($pdo) ? ', numfvd' : '';
    }

    /**
     * Marcador SQL extra por fila en INSERT batch.
     */
    public static function sufijoMarcadorInsert(?\PDO $pdo = null): string
    {
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return '';
        }

        return self::tieneColumnaNumfvd($pdo) ? ',?' : '';
    }

    /**
     * @param array{id_usuario: int, numfvd?: int} $datos
     */
    public static function valorSufijoInsert(array $datos): int
    {
        return (int) ($datos['numfvd'] ?? $datos['id_usuario']);
    }

    /**
     * SET al intercambiar/reemplazar jugador en partiresul.
     *
     * @return array{set: string, params: list<int>}
     */
    public static function sqlSetClaveJugador(\PDO $pdo, int $torneoId, int $idUsuarioInternoNuevo): array
    {
        $datos = self::datosInsertJugador($pdo, $torneoId, $idUsuarioInternoNuevo);
        if (self::soloNumfvdEnPartiresul($pdo)) {
            return ['set' => 'numfvd = ?', 'params' => [self::valorSufijoInsert($datos)]];
        }
        if (!self::tieneColumnaNumfvd($pdo)) {
            return ['set' => 'id_usuario = ?', 'params' => [(int) ($datos['id_usuario'] ?? $datos['numfvd'])]];
        }

        return [
            'set' => 'id_usuario = ?, numfvd = ?',
            'params' => [(int) $datos['id_usuario'], self::valorSufijoInsert($datos)],
        ];
    }

    /**
     * Subconsulta COUNT ff por inscrito (usa clave numfvd).
     */
    public static function sqlSubqueryCountGffPorInscrito(string $iAlias = 'i'): string
    {
        self::validarAlias($iAlias);
        $pr = 'pr_gff';
        if (self::tieneColumnaNumfvd()) {
            if (self::soloNumfvdEnPartiresul()) {
                return '(SELECT COUNT(*) FROM partiresul ' . $pr . '
                    WHERE ' . $pr . '.id_torneo = ' . $iAlias . '.torneo_id
                      AND ' . $iAlias . '.numfvd > 0
                      AND ' . $pr . '.numfvd = ' . $iAlias . '.numfvd
                      AND ' . PartiresulEstatusSql::whereFfUno($pr) . ')';
            }

            return '(SELECT COUNT(*) FROM partiresul ' . $pr . '
                WHERE ' . $pr . '.id_torneo = ' . $iAlias . '.torneo_id
                  AND (
                    (' . $iAlias . '.numfvd > 0 AND ' . $pr . '.numfvd = ' . $iAlias . '.numfvd)
                    OR (
                        COALESCE(NULLIF(' . $pr . '.numfvd, 0), 0) = 0
                        AND ' . $pr . '.id_usuario = ' . $iAlias . '.id_usuario
                    )
                  )
                  AND ' . PartiresulEstatusSql::whereFfUno($pr) . ')';
        }

        return '(SELECT COUNT(*) FROM partiresul ' . $pr . '
            WHERE ' . $pr . '.id_usuario = ' . $iAlias . '.id_usuario
              AND ' . $pr . '.id_torneo = ' . $iAlias . '.torneo_id
              AND ' . PartiresulEstatusSql::whereFfUno($pr) . ')';
    }

    /**
     * partiresul.numfvd ↔ inscritos (con o sin columna inscritos.numfvd).
     */
    private static function sqlOnInscritosPartiresulSoloNumfvd(string $iAlias, string $prAlias): string
    {
        require_once __DIR__ . '/NumfvdHelper.php';
        $pdo = self::resolverPdo();
        if (NumfvdHelper::inscritosTieneColumnaNumfvd($pdo)) {
            return $iAlias . '.torneo_id = ' . $prAlias . '.id_torneo
                AND ' . $iAlias . '.numfvd > 0
                AND ' . $iAlias . '.numfvd = ' . $prAlias . '.numfvd';
        }

        return $iAlias . '.torneo_id = ' . $prAlias . '.id_torneo
            AND EXISTS (
                SELECT 1 FROM usuarios u_on_pr
                WHERE u_on_pr.id = ' . $iAlias . '.id_usuario
                  AND u_on_pr.numfvd > 0
                  AND u_on_pr.numfvd = ' . $prAlias . '.numfvd
            )';
    }

    private static function validarAlias(string $alias): void
    {
        if ($alias === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException('Alias de tabla inválido: ' . $alias);
        }
    }

    private static function resolverPdo(): ?\PDO
    {
        return class_exists('DB', false) ? DB::pdo() : null;
    }
}
