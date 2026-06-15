<?php
declare(strict_types=1);

require_once __DIR__ . '/CampeonatoTorneoHelper.php';

/**
 * Categorías del ranking oficial FVD (absoluto y SUB) para filtros SQL y enlaces públicos.
 */
final class RankingCategoriaFvdHelper
{
    public const ABSOLUTO = 'absoluto';
    public const SUB = 'sub';
    public const SUB12 = 'sub12';
    public const SUB15 = 'sub15';
    public const SUB18 = 'sub18';

    /** @var array<string, bool>|null */
    private static ?array $columnasTorneo = null;

    /**
     * @return array{campeonato_grupo: bool, edad_maxima: bool, genero_requerido: bool}
     */
    public static function columnasTorneo(PDO $pdo): array
    {
        if (self::$columnasTorneo !== null) {
            return self::$columnasTorneo;
        }
        $out = ['campeonato_grupo' => false, 'edad_maxima' => false, 'genero_requerido' => false];
        foreach (array_keys($out) as $col) {
            try {
                $out[$col] = (bool) $pdo->query("SHOW COLUMNS FROM tournaments LIKE " . $pdo->quote($col))->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $out[$col] = false;
            }
        }
        self::$columnasTorneo = $out;

        return $out;
    }

    public static function normalizar(string $categoria): string
    {
        $c = strtolower(trim($categoria));
        $map = [
            'absoluto' => self::ABSOLUTO,
            'abs' => self::ABSOLUTO,
            'sub' => self::SUB,
            'sub12' => self::SUB12,
            'sub-12' => self::SUB12,
            'sub 12' => self::SUB12,
            'sub15' => self::SUB15,
            'sub-15' => self::SUB15,
            'sub 15' => self::SUB15,
            'sub18' => self::SUB18,
            'sub-18' => self::SUB18,
            'sub 18' => self::SUB18,
        ];

        return $map[$c] ?? self::ABSOLUTO;
    }

    public static function edadMaximaParticipacion(string $categoria): ?int
    {
        switch (self::normalizar($categoria)) {
            case self::SUB12:
                return 12;
            case self::SUB15:
                return 15;
            case self::SUB18:
            case self::SUB:
                return 18;
            default:
                return null;
        }
    }

    public static function etiqueta(string $categoria, string $genero): string
    {
        $g = strtoupper($genero) === 'F' ? 'Femenino' : 'Masculino';
        switch (self::normalizar($categoria)) {
            case self::SUB:
                $cat = 'Sub';
                break;
            case self::SUB12:
                $cat = 'Sub 12';
                break;
            case self::SUB15:
                $cat = 'Sub 15';
                break;
            case self::SUB18:
                $cat = 'Sub 18';
                break;
            default:
                $cat = 'Categoría libre';
        }

        return $cat . ' ' . $g;
    }

    public static function tituloRanking(string $categoria, string $genero): string
    {
        return 'Ranking oficial — ' . self::etiqueta($categoria, $genero);
    }

    /**
     * Restringe torneos según categoría del ranking.
     */
    public static function sqlFiltroTorneoCategoria(PDO $pdo, string $categoria, string $alias = 't'): string
    {
        $cols = self::columnasTorneo($pdo);
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 't';
        $cg = $cols['campeonato_grupo'] ? "UPPER(TRIM(COALESCE({$a}.campeonato_grupo, '')))" : "''";
        $em = $cols['edad_maxima'] ? "COALESCE({$a}.edad_maxima, 0)" : '0';

        switch (self::normalizar($categoria)) {
            case self::SUB12:
                return " AND ({$cg} = 'SUB 12' OR {$em} = 12)";
            case self::SUB15:
                return " AND ({$cg} = 'SUB 15' OR {$em} = 15)";
            case self::SUB18:
                return " AND ({$cg} = 'SUB 18' OR {$em} = 18)";
            case self::SUB:
                return " AND ({$cg} LIKE 'SUB%' OR ({$em} > 0 AND {$em} <= 18))";
            default:
                return " AND NOT ({$cg} LIKE 'SUB%' OR {$em} > 0)";
        }
    }

    /**
     * Campeonatos por género (genero_requerido o nombre MASCULINO/FEMENINO).
     */
    public static function sqlFiltroTorneoGenero(PDO $pdo, string $sexo, string $alias = 't'): string
    {
        $cols = self::columnasTorneo($pdo);
        $sexo = strtoupper($sexo) === 'F' ? 'F' : 'M';
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 't';

        if ($cols['genero_requerido']) {
            return " AND (
                NULLIF(TRIM({$a}.genero_requerido), '') IS NULL
                OR UPPER(TRIM({$a}.genero_requerido)) = '{$sexo}'
            )";
        }

        if ($sexo === 'F') {
            return " AND (
                (UPPER({$a}.nombre) NOT LIKE '%MASCULINO%' AND UPPER({$a}.nombre) NOT LIKE '%FEMENINO%')
                OR UPPER({$a}.nombre) LIKE '%FEMENINO%'
            )";
        }

        return " AND (
            (UPPER({$a}.nombre) NOT LIKE '%MASCULINO%' AND UPPER({$a}.nombre) NOT LIKE '%FEMENINO%')
            OR UPPER({$a}.nombre) LIKE '%MASCULINO%'
        )";
    }

    public static function atletaElegibleEnTorneo(string $categoria, ?string $fechnac, ?string $fechator): bool
    {
        $max = self::edadMaximaParticipacion($categoria);
        if ($max === null) {
            return true;
        }
        $edad = CampeonatoTorneoHelper::calcularEdad($fechnac, $fechator);
        if ($edad === null) {
            return false;
        }

        return $edad <= $max;
    }

    /**
     * Enlaces para la landing y menús públicos.
     *
     * @return list<array{slug: string, genero: string, label: string, grupo: string}>
     */
    public static function enlacesLanding(): array
    {
        $out = [];
        $grupos = [
            ['slug' => self::ABSOLUTO, 'grupo' => 'Absoluto'],
            ['slug' => self::SUB, 'grupo' => 'Sub'],
            ['slug' => self::SUB12, 'grupo' => 'Sub 12'],
            ['slug' => self::SUB15, 'grupo' => 'Sub 15'],
            ['slug' => self::SUB18, 'grupo' => 'Sub 18'],
        ];
        foreach ($grupos as $g) {
            foreach (['M' => 'Masculino', 'F' => 'Femenino'] as $gen => $genLabel) {
                $out[] = [
                    'slug' => $g['slug'],
                    'genero' => $gen,
                    'grupo' => $g['grupo'],
                    'label' => $g['grupo'] . ' ' . $genLabel,
                ];
            }
        }

        return $out;
    }

    public static function urlRanking(string $basePublic, string $categoria, string $genero): string
    {
        $base = rtrim($basePublic, '/') . '/';
        $params = [
            'genero' => strtoupper($genero) === 'F' ? 'F' : 'M',
            'categoria' => self::normalizar($categoria),
        ];
        if ($params['categoria'] === self::ABSOLUTO) {
            unset($params['categoria']);
        }

        return $base . 'ranking_atletas.php?' . http_build_query($params);
    }
}
