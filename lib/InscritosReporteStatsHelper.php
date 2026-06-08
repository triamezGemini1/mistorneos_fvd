<?php

declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/PartiresulTriunfoGffHelper.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/ResultadosReporteData.php';

/**
 * GFF y partidas BYE persistidos en inscritos (se sincronizan con actualizarEstadisticasInscritos).
 * triunfo_gff en partiresul evita subconsultas pesadas en cada reporte.
 */
final class InscritosReporteStatsHelper
{
    /** @var bool|null */
    private static ?bool $columnasOk = null;

    public static function ensureColumnas(PDO $pdo): void
    {
        if (self::$columnasOk === true) {
            return;
        }
        $defs = [
            'gff' => "INT NOT NULL DEFAULT 0 COMMENT 'GFF: victorias por FF o TR (tarjeta roja/negra) del rival/compañero'",
            'partidas_bye' => "INT NOT NULL DEFAULT 0 COMMENT 'Partidas BYE ganadas (mesa 0)'",
        ];
        foreach ($defs as $col => $def) {
            $st = $pdo->query("SHOW COLUMNS FROM inscritos LIKE " . $pdo->quote($col));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }
            $pdo->exec("ALTER TABLE inscritos ADD COLUMN `{$col}` {$def}");
        }
        PartiresulTriunfoGffHelper::ensureColumna($pdo);
        self::$columnasOk = true;
    }

    public static function columnasDisponibles(PDO $pdo): bool
    {
        if (self::$columnasOk === true) {
            return true;
        }
        $st = $pdo->query("SHOW COLUMNS FROM inscritos LIKE 'gff'");
        self::$columnasOk = (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));

        return self::$columnasOk;
    }

    /**
     * Recalcula gff y partidas_bye para un torneo (agregación masiva, sin subconsultas por fila).
     */
    public static function sincronizarGffYBye(PDO $pdo, int $torneoId): void
    {
        if ($torneoId <= 0) {
            return;
        }
        self::ensureColumnas($pdo);

        $pdo->prepare('UPDATE inscritos SET gff = 0, partidas_bye = 0 WHERE torneo_id = ?')
            ->execute([$torneoId]);

        if (PartiresulTriunfoGffHelper::tieneColumna($pdo)) {
            $wReg = PartiresulEstatusSql::whereRegistradoUno('pr_gff');
            $sqlGff = "
                UPDATE inscritos i
                INNER JOIN (
                    SELECT pr_gff.id_usuario, SUM(pr_gff.triunfo_gff) AS gff
                    FROM partiresul pr_gff
                    WHERE pr_gff.id_torneo = ? AND {$wReg}
                    GROUP BY pr_gff.id_usuario
                ) g ON i.id_usuario = g.id_usuario
                SET i.gff = g.gff
                WHERE i.torneo_id = ?
            ";
            $pdo->prepare($sqlGff)->execute([$torneoId, $torneoId]);
        } else {
            $sqlGff = '
                UPDATE inscritos i
                INNER JOIN (' . self::sqlAggregadoGffPorTorneo() . ') g
                    ON i.id_usuario = g.id_usuario AND i.torneo_id = g.id_torneo
                SET i.gff = g.gff
                WHERE i.torneo_id = ?
            ';
            $pdo->prepare($sqlGff)->execute([$torneoId, $torneoId]);
        }

        $wReg = PartiresulEstatusSql::whereRegistradoUno('pr_bye');
        $r1 = InscritosHelper::sqlExprColumnaNumerica('pr_bye.resultado1');
        $r2 = InscritosHelper::sqlExprColumnaNumerica('pr_bye.resultado2');
        $sqlBye = "
            UPDATE inscritos i
            INNER JOIN (
                SELECT pr_bye.id_usuario, COUNT(*) AS cnt
                FROM partiresul pr_bye
                WHERE pr_bye.id_torneo = ?
                  AND {$wReg}
                  AND pr_bye.mesa = 0
                  AND {$r1} > {$r2}
                GROUP BY pr_bye.id_usuario
            ) b ON i.id_usuario = b.id_usuario
            SET i.partidas_bye = b.cnt
            WHERE i.torneo_id = ?
        ";
        $pdo->prepare($sqlBye)->execute([$torneoId, $torneoId]);

        self::sincronizarTarjetaVigente($pdo, $torneoId);
    }

    /**
     * Tarjeta disciplinaria vigente en reportes: MAX en partiresul (1/3/4), no la de la última ronda sola.
     */
    public static function sincronizarTarjetaVigente(PDO $pdo, int $torneoId): void
    {
        if ($torneoId <= 0) {
            return;
        }
        $wReg = PartiresulEstatusSql::whereRegistradoUno('pr');
        $tn = self::sqlExprTarjetaCodigoFvd('pr.tarjeta');
        $sql = "
            UPDATE inscritos i
            LEFT JOIN (
                SELECT pr.id_usuario, MAX({$tn}) AS tarjeta_max
                FROM partiresul pr
                WHERE pr.id_torneo = ? AND {$wReg}
                GROUP BY pr.id_usuario
            ) t ON i.id_usuario = t.id_usuario
            SET i.tarjeta = COALESCE(t.tarjeta_max, 0)
            WHERE i.torneo_id = ?
        ";
        $pdo->prepare($sql)->execute([$torneoId, $torneoId]);
    }

    /**
     * Normaliza códigos legacy Access (5/6/8) antes de MAX/agregados.
     */
    public static function sqlExprTarjetaCodigoFvd(string $column): string
    {
        $t = InscritosHelper::sqlExprColumnaNumerica($column);

        return "CASE
            WHEN {$t} = 5 THEN 1
            WHEN {$t} = 6 THEN 3
            WHEN {$t} = 8 THEN 4
            ELSE {$t}
        END";
    }

    /**
     * Fragmento SELECT para listados de clasificación (lectura rápida desde columnas persistidas).
     *
     * @return array{gff: string, tarjeta: string, partidas_bye: string, ganadas_por_forfait: string}
     */
    public static function expresionesSelectClasificacion(string $iAlias = 'i'): array
    {
        self::validarAlias($iAlias);
        $gffExpr = 'COALESCE(' . $iAlias . '.gff, 0)';
        $tarjetaExpr = 'COALESCE(' . $iAlias . '.tarjeta, 0)';

        return [
            'gff' => $gffExpr . ' AS gff',
            'tarjeta' => $tarjetaExpr . ' AS tarjeta',
            'partidas_bye' => 'COALESCE(' . $iAlias . '.partidas_bye, 0) AS partidas_bye',
            'ganadas_por_forfait' => $gffExpr . ' AS ganadas_por_forfait',
        ];
    }

    private static function sqlAggregadoGffPorTorneo(): string
    {
        $parts = ResultadosReporteData::sqlPartesConteoGff();

        return "
            SELECT pr1.id_usuario, pr1.id_torneo, COUNT(DISTINCT pr1.partida, pr1.mesa) AS gff
            FROM partiresul pr1
            {$parts['joins']}
            WHERE pr1.id_torneo = ?
                {$parts['where_victoria']}
                {$parts['where_incidencia']}
            GROUP BY pr1.id_usuario, pr1.id_torneo
        ";
    }

    private static function validarAlias(string $alias): void
    {
        if ($alias === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Alias de tabla inválido: ' . $alias);
        }
    }
}
