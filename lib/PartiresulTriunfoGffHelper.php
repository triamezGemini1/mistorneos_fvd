<?php

declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/partiresul_efectividad_funcs.php';

/**
 * triunfo_gff en partiresul: 1 si esa fila es victoria por FF o TR (rival/compañero) — base de inscritos.gff.
 */
final class PartiresulTriunfoGffHelper
{
    /** @var bool|null */
    private static ?bool $columnaOk = null;

    public static function ensureColumna(PDO $pdo): void
    {
        if (self::$columnaOk === true) {
            return;
        }
        $st = $pdo->query("SHOW COLUMNS FROM partiresul LIKE 'triunfo_gff'");
        if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
            self::$columnaOk = true;

            return;
        }
        $pdo->exec(
            "ALTER TABLE partiresul ADD COLUMN triunfo_gff TINYINT(1) NOT NULL DEFAULT 0
             COMMENT '1=victoria por forfait o tarjeta roja/negra del rival/compañero (GFF)'"
        );
        self::$columnaOk = true;
    }

    public static function tieneColumna(PDO $pdo): bool
    {
        if (self::$columnaOk === true) {
            return true;
        }
        $st = $pdo->query("SHOW COLUMNS FROM partiresul LIKE 'triunfo_gff'");
        self::$columnaOk = (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));

        return self::$columnaOk;
    }

    /**
     * Recalcula triunfo_gff en todas las filas registradas del torneo (backfill / corrección).
     */
    public static function backfillTorneo(PDO $pdo, int $torneoId): void
    {
        if ($torneoId <= 0) {
            return;
        }
        self::ensureColumna($pdo);
        require_once __DIR__ . '/ResultadosReporteData.php';

        $pdo->prepare('UPDATE partiresul SET triunfo_gff = 0 WHERE id_torneo = ?')
            ->execute([$torneoId]);

        $parts = ResultadosReporteData::sqlPartesConteoGff();
        $sql = '
            UPDATE partiresul pr1
            ' . $parts['joins'] . '
            SET pr1.triunfo_gff = 1
            WHERE pr1.id_torneo = ?
                ' . $parts['where_victoria'] . '
                ' . $parts['where_incidencia'];
        $pdo->prepare($sql)->execute([$torneoId]);
    }
}
