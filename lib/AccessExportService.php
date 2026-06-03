<?php

declare(strict_types=1);

require_once __DIR__ . '/PartiresulJugadorHelper.php';

/**
 * Exportación de datos para Microsoft Access (inscritos + partiresul).
 */
final class AccessExportService
{
    /**
     * Torneo activo: no finalizado; si hay varios, el de mayor id con inscritos.
     */
    public static function resolverTorneoActivo(PDO $pdo, int $preferido = 0): ?array
    {
        if ($preferido > 0) {
            $st = $pdo->prepare('SELECT id, nombre, COALESCE(finalizado, 0) AS finalizado FROM tournaments WHERE id = ? LIMIT 1');
            $st->execute([$preferido]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        $hasFin = (bool) $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'finalizado'")->fetch(PDO::FETCH_ASSOC);
        $whereFin = $hasFin ? 'AND COALESCE(t.finalizado, 0) = 0' : '';

        $st = $pdo->query("
            SELECT t.id, t.nombre, COALESCE(t.finalizado, 0) AS finalizado,
                   (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = t.id) AS n_inscritos
            FROM tournaments t
            WHERE 1=1 {$whereFin}
            ORDER BY n_inscritos DESC, t.id DESC
            LIMIT 1
        ");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;

        return $row ?: null;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public static function filasInscritosAccess(PDO $pdo, int $torneoId): array
    {
        $hasEmail = self::columnaExiste($pdo, 'usuarios', 'email');
        $emailSql = $hasEmail ? 'u.email' : "''";

        $st = $pdo->prepare("
            SELECT
                COALESCE(
                    NULLIF(i.id_club, 0),
                    NULLIF(c.id, 0),
                    CASE
                        WHEN i.codigo_equipo IS NOT NULL AND i.codigo_equipo <> '' AND i.codigo_equipo LIKE '%-%'
                            THEN CAST(SUBSTRING_INDEX(i.codigo_equipo, '-', 1) AS UNSIGNED)
                        ELSE 0
                    END
                ) AS asociacion,
                i.torneo_id AS torneo,
                CASE
                    WHEN i.numero > 0 THEN i.numero
                    WHEN i.codigo_equipo IS NOT NULL AND i.codigo_equipo <> '' AND i.codigo_equipo <> '000-000'
                        THEN CAST(SUBSTRING_INDEX(i.codigo_equipo, '-', -1) AS UNSIGNED)
                    ELSE 1
                END AS equipo,
                COALESCE(NULLIF(TRIM(i.cedula), ''), NULLIF(TRIM(u.cedula), ''), '') AS cedula,
                COALESCE(NULLIF(TRIM(u.nombre), ''), CONCAT('Usuario #', i.id_usuario)) AS nombre,
                CASE
                    WHEN COALESCE(i.numfvd, 0) > 0 THEN i.numfvd
                    WHEN COALESCE(u.numfvd, 0) > 0 THEN u.numfvd
                    ELSE i.id_usuario
                END AS numfvd,
                CASE
                    WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) IN ('M', '1', 'MASCULINO', 'H') THEN 1
                    WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) IN ('F', '2', 'FEMENINO') THEN 2
                    ELSE 0
                END AS sexo,
                COALESCE(NULLIF(TRIM(u.celular), ''), '') AS telefono,
                COALESCE(NULLIF(TRIM({$emailSql}), ''), '') AS email
            FROM inscritos i
            INNER JOIN usuarios u ON u.id = i.id_usuario
            LEFT JOIN clubes c ON c.id = i.id_club
            WHERE i.torneo_id = ?
              AND " . self::sqlInscritoActivo('i') . "
            ORDER BY asociacion ASC, equipo ASC, nombre ASC, i.id ASC
        ");
        $st->execute([$torneoId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public static function filasPartidasAccess(PDO $pdo, int $torneoId): array
    {
        PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);
        $joinInsc = self::joinInscritosPartiresulSql('pr', 'i');

        $st = $pdo->prepare("
            SELECT
                CASE WHEN pr.secuencia = 1 THEN 1 ELSE 0 END AS indi,
                pr.id_torneo AS torneo,
                pr.partida,
                pr.mesa,
                pr.secuencia,
                COALESCE(NULLIF(i.numero, 0), 0) AS pareja,
                COALESCE(pr.ff, 0) AS ff,
                COALESCE(pr.tarjeta, 0) AS sancion,
                COALESCE(pr.resultado1, 0) AS result1,
                COALESCE(pr.resultado2, 0) AS result2,
                COALESCE(pr.sancion, 0) AS `sancion p`,
                COALESCE(pr.efectividad, 0) AS efectividad,
                COALESCE(i.efectividad, 0) AS act,
                COALESCE(i.ganados, 0) AS ganado,
                COALESCE(i.perdidos, 0) AS perdido
            FROM partiresul pr
            LEFT JOIN inscritos i ON {$joinInsc}
            WHERE pr.id_torneo = ?
            ORDER BY pr.partida ASC, pr.mesa ASC, pr.secuencia ASC, pr.id ASC
        ");
        $st->execute([$torneoId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<array<string, scalar|null>> $filas
     * @param list<string>|null $headers
     */
    public static function escribirExcelHtml(string $path, string $titulo, array $filas, ?array $headers = null): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio: ' . $dir);
        }

        if ($headers === null && $filas !== []) {
            $headers = array_keys($filas[0]);
        }
        $headers = $headers ?? [];

        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . $esc($titulo) . '</title></head><body>';
        $html .= '<table border="1" cellpadding="4" cellspacing="0"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>' . $esc($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($filas as $row) {
            $html .= '<tr>';
            foreach ($headers as $h) {
                $html .= '<td>' . $esc($row[$h] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        if (file_put_contents($path, "\xEF\xBB\xBF" . $html) === false) {
            throw new RuntimeException('No se pudo escribir: ' . $path);
        }
    }

    /**
     * @return array{torneo: array, inscritos: string, partidas: string, n_inscritos: int, n_partidas: int}
     */
    public static function generarArchivosAccess(PDO $pdo, int $torneoId, string $outputDir): array
    {
        $torneo = self::resolverTorneoActivo($pdo, $torneoId);
        if (!$torneo) {
            throw new RuntimeException('Torneo no encontrado.');
        }
        $tid = (int) $torneo['id'];

        $filasInsc = self::filasInscritosAccess($pdo, $tid);
        $filasPart = self::filasPartidasAccess($pdo, $tid);

        $pathInsc = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'inscritos para access.xls';
        $pathPart = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'partidas para access.xls';

        self::escribirExcelHtml(
            $pathInsc,
            'Inscritos para Access',
            $filasInsc,
            ['asociacion', 'torneo', 'equipo', 'cedula', 'nombre', 'numfvd', 'sexo', 'telefono', 'email']
        );
        self::escribirExcelHtml(
            $pathPart,
            'Partidas para Access',
            $filasPart,
            ['indi', 'torneo', 'partida', 'mesa', 'secuencia', 'pareja', 'ff', 'sancion', 'result1', 'result2', 'sancion p', 'efectividad', 'act', 'ganado', 'perdido']
        );

        return [
            'torneo' => $torneo,
            'inscritos' => $pathInsc,
            'partidas' => $pathPart,
            'n_inscritos' => count($filasInsc),
            'n_partidas' => count($filasPart),
        ];
    }

    private static function sqlInscritoActivo(string $alias): string
    {
        $a = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) ? $alias : 'i';

        return "CAST({$a}.estatus AS CHAR) NOT IN ('4','retirado')";
    }

    private static function joinInscritosPartiresulSql(string $prAlias, string $iAlias): string
    {
        $pr = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prAlias) ? $prAlias : 'pr';
        $i = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $iAlias) ? $iAlias : 'i';

        return "{$i}.torneo_id = {$pr}.id_torneo AND (
            {$i}.id_usuario = {$pr}.id_usuario
            OR (COALESCE({$pr}.numfvd, 0) > 0 AND {$i}.numfvd = {$pr}.numfvd)
            OR (COALESCE({$i}.numfvd, 0) > 0 AND {$i}.numfvd = {$pr}.id_usuario)
        )";
    }

    private static function columnaExiste(PDO $pdo, string $tabla, string $columna): bool
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tabla) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columna)) {
            return false;
        }
        $st = $pdo->query("SHOW COLUMNS FROM `{$tabla}` LIKE " . $pdo->quote($columna));

        return (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));
    }
}
