<?php

declare(strict_types=1);

/**
 * Invitaciones FVD en portal: vigencia hasta la fecha del evento.
 */
final class InvitacionesFvdWebService
{
    public const CARPETA_REL = 'upload/invitaciones_fvd';
    public const EXTENSIONES = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];

    public static function ensureTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS archivos_web_invitaciones (
              id INT NOT NULL AUTO_INCREMENT,
              archivo VARCHAR(255) NOT NULL,
              ruta_relativa VARCHAR(500) NOT NULL,
              titulo VARCHAR(255) DEFAULT NULL,
              fecha_limite DATE NOT NULL,
              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uk_archivo (archivo),
              KEY idx_fecha_limite (fecha_limite)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done = true;
    }

    public static function esActivoPorFecha(?string $fechaLimiteYmd): bool
    {
        if ($fechaLimiteYmd === null || $fechaLimiteYmd === '' || $fechaLimiteYmd === '0000-00-00') {
            return false;
        }
        $limite = substr($fechaLimiteYmd, 0, 10);

        return $limite >= date('Y-m-d');
    }

    public static function etiquetaEstado(?string $fechaLimiteYmd): string
    {
        return self::esActivoPorFecha($fechaLimiteYmd) ? 'Activo' : 'Inactivo';
    }

    public static function registrar(PDO $pdo, string $archivo, ?string $titulo, string $fechaLimite): void
    {
        self::ensureTable($pdo);
        $archivo = basename($archivo);
        $fecha = self::normalizarFecha($fechaLimite);
        if ($fecha === null) {
            throw new InvalidArgumentException('Fecha límite del evento no válida');
        }
        $titulo = $titulo !== null && trim($titulo) !== '' ? trim($titulo) : pathinfo($archivo, PATHINFO_FILENAME);
        $ruta = self::CARPETA_REL . '/' . $archivo;

        $st = $pdo->prepare('
            INSERT INTO archivos_web_invitaciones (archivo, ruta_relativa, titulo, fecha_limite)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ruta_relativa = VALUES(ruta_relativa),
                titulo = VALUES(titulo),
                fecha_limite = VALUES(fecha_limite),
                updated_at = CURRENT_TIMESTAMP
        ');
        $st->execute([$archivo, $ruta, $titulo, $fecha]);
    }

    public static function actualizarFechaLimite(PDO $pdo, string $archivo, string $fechaLimite): void
    {
        self::ensureTable($pdo);
        $archivo = basename($archivo);
        $fecha = self::normalizarFecha($fechaLimite);
        if ($fecha === null) {
            throw new InvalidArgumentException('Fecha límite no válida');
        }
        $st = $pdo->prepare('UPDATE archivos_web_invitaciones SET fecha_limite = ?, updated_at = CURRENT_TIMESTAMP WHERE archivo = ?');
        $st->execute([$fecha, $archivo]);
        if ($st->rowCount() === 0) {
            self::registrar($pdo, $archivo, pathinfo($archivo, PATHINFO_FILENAME), $fecha);
        }
    }

    public static function eliminarPorArchivo(PDO $pdo, string $archivo): void
    {
        self::ensureTable($pdo);
        $st = $pdo->prepare('DELETE FROM archivos_web_invitaciones WHERE archivo = ?');
        $st->execute([basename($archivo)]);
    }

    public static function renombrarArchivo(PDO $pdo, string $viejo, string $nuevo): void
    {
        self::ensureTable($pdo);
        $viejo = basename($viejo);
        $nuevo = basename($nuevo);
        $st = $pdo->prepare('
            UPDATE archivos_web_invitaciones
            SET archivo = ?, ruta_relativa = ?, updated_at = CURRENT_TIMESTAMP
            WHERE archivo = ?
        ');
        $st->execute([$nuevo, self::CARPETA_REL . '/' . $nuevo, $viejo]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function mapaMeta(PDO $pdo): array
    {
        self::ensureTable($pdo);
        $rows = $pdo->query('SELECT archivo, ruta_relativa, titulo, fecha_limite FROM archivos_web_invitaciones')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['archivo']] = $row;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarAdmin(PDO $pdo, string $dirFull): array
    {
        $meta = self::mapaMeta($pdo);
        $out = [];
        if (!is_dir($dirFull)) {
            return $out;
        }
        foreach (new DirectoryIterator($dirFull) as $f) {
            if ($f->isDot() || !$f->isFile()) {
                continue;
            }
            $nombre = $f->getFilename();
            if ($nombre === '_meta.json') {
                continue;
            }
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, self::EXTENSIONES, true)) {
                continue;
            }
            $m = $meta[$nombre] ?? null;
            $fechaLimite = $m['fecha_limite'] ?? null;
            $activo = self::esActivoPorFecha(is_string($fechaLimite) ? $fechaLimite : null);
            $out[] = [
                'nombre' => $nombre,
                'path' => self::CARPETA_REL . '/' . $nombre,
                'titulo' => (string) ($m['titulo'] ?? pathinfo($nombre, PATHINFO_FILENAME)),
                'fecha_limite' => $fechaLimite,
                'activo' => $activo,
                'estado' => self::etiquetaEstado(is_string($fechaLimite) ? $fechaLimite : null),
                'sin_vigencia' => $m === null,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($b['fecha_limite'] ?? ''), (string) ($a['fecha_limite'] ?? ''));
        });

        return $out;
    }

    /**
     * @return list<array{titulo: string, path: string, archivo: string, fecha_limite: string}>
     */
    public static function listarActivos(PDO $pdo): array
    {
        self::ensureTable($pdo);
        $hoy = date('Y-m-d');
        $st = $pdo->prepare('
            SELECT archivo, ruta_relativa, titulo, fecha_limite
            FROM archivos_web_invitaciones
            WHERE fecha_limite >= ?
            ORDER BY fecha_limite DESC, id DESC
        ');
        $st->execute([$hoy]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::CARPETA_REL);
        foreach ($rows as $row) {
            $archivo = (string) ($row['archivo'] ?? '');
            if ($archivo === '' || !is_file($base . DIRECTORY_SEPARATOR . $archivo)) {
                continue;
            }
            $out[] = [
                'titulo' => (string) ($row['titulo'] ?? pathinfo($archivo, PATHINFO_FILENAME)),
                'path' => (string) ($row['ruta_relativa'] ?? (self::CARPETA_REL . '/' . $archivo)),
                'archivo' => $archivo,
                'fecha_limite' => substr((string) $row['fecha_limite'], 0, 10),
            ];
        }

        return $out;
    }

    public static function puedeServirPublico(PDO $pdo, string $pathRel): bool
    {
        if (strpos($pathRel, self::CARPETA_REL . '/') !== 0) {
            return true;
        }
        $archivo = basename($pathRel);
        self::ensureTable($pdo);
        $st = $pdo->prepare('SELECT fecha_limite FROM archivos_web_invitaciones WHERE archivo = ? LIMIT 1');
        $st->execute([$archivo]);
        $fecha = $st->fetchColumn();

        return self::esActivoPorFecha(is_string($fecha) ? $fecha : null);
    }

    private static function normalizarFecha(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $raw) ?: DateTime::createFromFormat('d/m/Y', $raw);
        if (!$dt) {
            return null;
        }

        return $dt->format('Y-m-d');
    }
}
