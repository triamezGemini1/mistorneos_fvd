<?php

declare(strict_types=1);

/**
 * NUMFVD público del atleta en torneo: fuente canónica inscritos.numfvd (copiado al inscribir desde usuarios).
 * partiresul usa numfvd (columna dedicada) como clave pública; id_usuario en partiresul conserva FK a usuarios.
 */
final class NumfvdHelper
{
    private static ?bool $inscritosTieneNumfvd = null;

    /**
     * JOIN partiresul → usuarios (vía inscritos.numfvd o id_usuario legado en partiresul).
     */
    public static function sqlJoinUsuariosPartiresul(
        string $prAlias = 'pr',
        string $uAlias = 'u',
        string $torneoIdColumn = 'pr.id_torneo'
    ): string {
        require_once __DIR__ . '/PartiresulJugadorHelper.php';
        $pdo = self::resolverPdo();
        if (PartiresulJugadorHelper::soloNumfvdEnPartiresul($pdo)) {
            return 'INNER JOIN inscritos i_u_pr ON (' . PartiresulJugadorHelper::sqlOnInscritosPartiresul('i_u_pr', $prAlias) . ')
                INNER JOIN usuarios ' . $uAlias . ' ON ' . $uAlias . '.id = i_u_pr.id_usuario';
        }
        if (PartiresulJugadorHelper::tieneColumnaNumfvd($pdo)) {
            return 'INNER JOIN usuarios ' . $uAlias . ' ON (
                ' . $uAlias . '.id = ' . $prAlias . '.id_usuario
                OR (
                    ' . $uAlias . '.numfvd > 0
                    AND ' . $uAlias . '.numfvd = ' . $prAlias . '.numfvd
                )
                OR (
                    ' . $uAlias . '.numfvd = ' . $prAlias . '.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = ' . $prAlias . '.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = ' . $torneoIdColumn . ' AND tx.club_responsable = 7
                    )
                )
            )';
        }

        return 'INNER JOIN usuarios ' . $uAlias . ' ON ' . $uAlias . '.id = ' . $prAlias . '.id_usuario';
    }

    /**
     * Expresión SQL: NUMFVD del inscrito en el torneo (usar con JOIN inscritos i).
     */
    public static function sqlExprNumfvdInscrito(string $iAlias = 'i', ?\PDO $pdo = null, string $uAlias = ''): string
    {
        if (self::inscritosTieneColumnaNumfvd($pdo)) {
            return 'COALESCE(NULLIF(' . $iAlias . '.numfvd, 0), 0)';
        }
        if ($uAlias !== '') {
            return 'COALESCE(NULLIF(' . $uAlias . '.numfvd, 0), 0)';
        }

        return '(SELECT COALESCE(NULLIF(u_nf.numfvd, 0), 0) FROM usuarios u_nf WHERE u_nf.id = ' . $iAlias . '.id_usuario LIMIT 1)';
    }

    /**
     * @deprecated Usar sqlExprNumfvdInscrito cuando hay JOIN inscritos.
     */
    public static function sqlExprNumfvdMostrar(string $uAlias = 'u', string $iAlias = '', ?\PDO $pdo = null): string
    {
        if ($iAlias !== '') {
            return self::sqlExprNumfvdInscrito($iAlias);
        }
        if ($uAlias !== '') {
            return 'COALESCE(NULLIF(' . $uAlias . '.numfvd, 0), 0)';
        }

        return '0';
    }

    public static function desdeFila(array $row): int
    {
        foreach (['numfvd', 'inscrito_numfvd', 'usuario_numfvd'] as $clave) {
            $v = (int) ($row[$clave] ?? 0);
            if ($v > 0) {
                return $v;
            }
        }

        return 0;
    }

    public static function textoMostrar(array $row, bool $respaldoIdUsuario = false): string
    {
        $nf = self::desdeFila($row);
        if ($nf > 0) {
            return (string) $nf;
        }
        if ($respaldoIdUsuario) {
            $id = (int) ($row['id_usuario'] ?? $row['usuario_id_real'] ?? 0);
            if ($id > 0) {
                return (string) $id;
            }
        }

        return '—';
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function enriquecerFilas(array $filas): array
    {
        foreach ($filas as &$fila) {
            $nf = self::desdeFila($fila);
            if ($nf > 0) {
                $fila['numfvd'] = $nf;
            }
        }
        unset($fila);

        return $filas;
    }

    /**
     * Resuelve NUMFVD (o id interno) → id_usuario inscrito en el torneo.
     */
    public static function resolverIdUsuarioInscrito(\PDO $pdo, int $torneoId, int $identificador): ?int
    {
        if ($identificador <= 0) {
            return null;
        }
        $whereActivo = '(estatus IS NULL OR estatus = 1 OR estatus = 2 OR estatus = \'1\' OR estatus = \'confirmado\')';

        $st = $pdo->prepare(
            "SELECT id_usuario FROM inscritos
             WHERE torneo_id = ? AND id_usuario = ? AND {$whereActivo}
             LIMIT 1"
        );
        $st->execute([$torneoId, $identificador]);
        $uid = (int) $st->fetchColumn();
        if ($uid > 0) {
            return $uid;
        }

        if (self::inscritosTieneColumnaNumfvd($pdo)) {
            $st = $pdo->prepare(
                "SELECT id_usuario FROM inscritos
                 WHERE torneo_id = ? AND numfvd = ? AND {$whereActivo}
                 LIMIT 1"
            );
            $st->execute([$torneoId, $identificador]);
            $uid = (int) $st->fetchColumn();
            if ($uid > 0) {
                return $uid;
            }
        }

        $st = $pdo->prepare(
            "SELECT i.id_usuario FROM inscritos i
             INNER JOIN usuarios u ON u.id = i.id_usuario
             WHERE i.torneo_id = ? AND u.numfvd = ? AND {$whereActivo}
             LIMIT 1"
        );
        $st->execute([$torneoId, $identificador]);
        $uid = (int) $st->fetchColumn();

        return $uid > 0 ? $uid : null;
    }

    /**
     * NUMFVD del atleta en este torneo (inscritos.numfvd).
     */
    public static function numfvdInscrito(\PDO $pdo, int $torneoId, int $idUsuario): int
    {
        if ($torneoId <= 0 || $idUsuario <= 0) {
            return 0;
        }
        if (self::inscritosTieneColumnaNumfvd($pdo)) {
            $st = $pdo->prepare(
                'SELECT COALESCE(NULLIF(numfvd, 0), 0) FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1'
            );
            $st->execute([$torneoId, $idUsuario]);
            $nf = (int) $st->fetchColumn();
            if ($nf > 0) {
                return $nf;
            }
        }

        $st = $pdo->prepare(
            'SELECT COALESCE(NULLIF(u.numfvd, 0), 0) FROM inscritos i
             INNER JOIN usuarios u ON u.id = i.id_usuario
             WHERE i.torneo_id = ? AND i.id_usuario = ? LIMIT 1'
        );
        $st->execute([$torneoId, $idUsuario]);

        return (int) $st->fetchColumn();
    }

    /**
     * Valores posibles de partiresul.id_usuario (id interno y numfvd en fila legada).
     *
     * @return list<int>
     */
    public static function clavesPartiresulIdUsuario(\PDO $pdo, int $torneoId, int $idUsuarioInterno): array
    {
        require_once __DIR__ . '/PartiresulJugadorHelper.php';

        return PartiresulJugadorHelper::clavesBusqueda($pdo, $torneoId, $idUsuarioInterno);
    }

    public static function inscritosTieneColumnaNumfvd(?\PDO $pdo = null): bool
    {
        if (self::$inscritosTieneNumfvd !== null) {
            return self::$inscritosTieneNumfvd;
        }
        $pdo = $pdo ?? self::resolverPdo();
        if (!$pdo) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'inscritos\' AND COLUMN_NAME = \'numfvd\''
            );
            $st->execute();
            self::$inscritosTieneNumfvd = ((int) $st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            self::$inscritosTieneNumfvd = false;
        }

        return self::$inscritosTieneNumfvd;
    }

    /**
     * NUMFVD desde tabla atletas (fvdadmin / converma copiada en mistorneos) por cédula.
     */
    public static function resolverDesdeCedula(\PDO $pdo, string $cedula): int
    {
        $ced = preg_replace('/\D/', '', trim($cedula));
        if ($ced === '') {
            return 0;
        }
        try {
            $st = $pdo->query("SHOW TABLES LIKE 'atletas'");
            if (!$st || !$st->fetchColumn()) {
                return 0;
            }
            $st = $pdo->prepare(
                'SELECT COALESCE(NULLIF(numfvd, 0), 0) AS nf FROM atletas
                 WHERE cedula = ? OR REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), \'-\', \'\'), \'.\', \'\'), \' \', \'\') = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $st->execute([$ced, $ced]);
            $nf = (int) ($st->fetchColumn() ?: 0);

            return $nf > 0 ? $nf : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * NUMFVD para inscripción: usuarios.numfvd → atletas.numfvd (misma cédula).
     */
    public static function resolverParaUsuario(\PDO $pdo, int $usuarioId, string $cedula = ''): int
    {
        if ($usuarioId > 0) {
            try {
                $st = $pdo->prepare('SELECT COALESCE(NULLIF(numfvd, 0), 0), cedula FROM usuarios WHERE id = ? LIMIT 1');
                $st->execute([$usuarioId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $nf = (int) ($row['numfvd'] ?? 0);
                    if ($nf > 0) {
                        return $nf;
                    }
                    if ($cedula === '') {
                        $cedula = (string) ($row['cedula'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return self::resolverDesdeCedula($pdo, $cedula);
    }

    private static function resolverPdo(): ?\PDO
    {
        return class_exists('DB', false) ? DB::pdo() : null;
    }
}
