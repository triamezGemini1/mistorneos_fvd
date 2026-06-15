<?php

declare(strict_types=1);

require_once __DIR__ . '/ImageOptimizer.php';
require_once __DIR__ . '/app_helpers.php';

/**
 * Galería de fotos por torneo (club_photos + upload/tournaments/{id}/photos/).
 */
final class TournamentPhotoService
{
    public const MAX_PHOTOS_PER_TORNEO = 20;
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public static function tablaDisponible(PDO $pdo): bool
    {
        try {
            return (bool) $pdo->query("SHOW TABLES LIKE 'club_photos'")->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function projectRoot(): string
    {
        return defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    }

    /** Directorio absoluto de fotos del torneo. */
    public static function photosAbsoluteDir(int $torneoId): string
    {
        $root = self::projectRoot();

        return $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'tournaments'
            . DIRECTORY_SEPARATOR . $torneoId . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR;
    }

    /** Ruta relativa al proyecto, ej. upload/tournaments/12/photos/photo_xxx.jpg */
    public static function relativePath(int $torneoId, string $filename): string
    {
        return 'upload/tournaments/' . $torneoId . '/photos/' . ltrim($filename, '/');
    }

    public static function publicUrl(?string $rutaImagen, ?string $publicBaseUrl = null): string
    {
        if ($rutaImagen === null || trim($rutaImagen) === '') {
            return '';
        }
        $path = trim($rutaImagen);
        if (strpos($path, 'http') === 0) {
            return $path;
        }

        return AppHelpers::publicImageUrl($path, $publicBaseUrl);
    }

    public static function countActive(PDO $pdo, int $torneoId): int
    {
        if ($torneoId <= 0 || !self::tablaDisponible($pdo)) {
            return 0;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM club_photos WHERE torneo_id = ? AND (activa = 1 OR activa IS NULL)'
        );
        $st->execute([$torneoId]);

        return (int) $st->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarPublicas(PDO $pdo, int $torneoId, ?string $publicBaseUrl = null): array
    {
        if ($torneoId <= 0 || !self::tablaDisponible($pdo)) {
            return [];
        }
        $st = $pdo->prepare("
            SELECT id, ruta_imagen, titulo, descripcion, orden, fecha_subida
            FROM club_photos
            WHERE torneo_id = ? AND (activa = 1 OR activa IS NULL)
            ORDER BY orden ASC, fecha_subida ASC
        ");
        $st->execute([$torneoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['url'] = self::publicUrl((string) ($row['ruta_imagen'] ?? ''), $publicBaseUrl);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{success: bool, foto_id?: int, ruta_imagen?: string, url?: string, optimized?: array<string, mixed>, error?: string}
     */
    public static function subir(PDO $pdo, int $torneoId, array $file, ?int $subidoPor = null): array
    {
        if ($torneoId <= 0) {
            return ['success' => false, 'error' => 'ID de torneo inválido'];
        }
        if (!self::tablaDisponible($pdo)) {
            return ['success' => false, 'error' => 'La galería no está disponible en este servidor'];
        }

        $uploadCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadCode !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => self::uploadErrorMessage($uploadCode)];
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'No se recibió el archivo'];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            return ['success' => false, 'error' => 'El archivo supera el máximo de 10 MB'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return ['success' => false, 'error' => 'Tipo de imagen no permitido (JPG, PNG, GIF, WebP)'];
        }

        if (self::countActive($pdo, $torneoId) >= self::MAX_PHOTOS_PER_TORNEO) {
            return ['success' => false, 'error' => 'Límite de ' . self::MAX_PHOTOS_PER_TORNEO . ' fotos por torneo'];
        }

        $dir = self::photosAbsoluteDir($torneoId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'No se pudo crear la carpeta de fotos del torneo'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            if ($mime === 'image/png') {
                $ext = 'png';
            } elseif ($mime === 'image/gif') {
                $ext = 'gif';
            } elseif ($mime === 'image/webp') {
                $ext = 'webp';
            } else {
                $ext = 'jpg';
            }
        }

        $filename = 'photo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $absolute = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            return ['success' => false, 'error' => 'Error al guardar la imagen'];
        }

        $optimized = ImageOptimizer::optimize($absolute, null, [
            'quality' => 82,
            'max_width' => 1920,
            'max_height' => 1920,
            'create_webp' => false,
        ]);
        if (!($optimized['success'] ?? false)) {
            @unlink($absolute);

            return ['success' => false, 'error' => (string) ($optimized['error'] ?? 'No se pudo optimizar la imagen')];
        }

        $rutaImagen = self::relativePath($torneoId, $filename);
        $orden = self::countActive($pdo, $torneoId) + 1;
        $titulo = pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME);

        $st = $pdo->prepare("
            INSERT INTO club_photos (torneo_id, club_id, ruta_imagen, titulo, descripcion, nombre_archivo, orden, subido_por, activa)
            VALUES (?, NULL, ?, ?, NULL, ?, ?, ?, 1)
        ");
        $st->execute([
            $torneoId,
            $rutaImagen,
            $titulo,
            (string) ($file['name'] ?? $filename),
            $orden,
            $subidoPor,
        ]);

        return [
            'success' => true,
            'foto_id' => (int) $pdo->lastInsertId(),
            'ruta_imagen' => $rutaImagen,
            'url' => self::publicUrl($rutaImagen),
            'optimized' => $optimized,
        ];
    }

    public static function eliminar(PDO $pdo, int $fotoId, int $torneoId): bool
    {
        if ($fotoId <= 0 || $torneoId <= 0 || !self::tablaDisponible($pdo)) {
            return false;
        }
        $st = $pdo->prepare('SELECT ruta_imagen FROM club_photos WHERE id = ? AND torneo_id = ? LIMIT 1');
        $st->execute([$fotoId, $torneoId]);
        $foto = $st->fetch(PDO::FETCH_ASSOC);
        if (!$foto) {
            return false;
        }

        self::eliminarArchivoFisico((string) ($foto['ruta_imagen'] ?? ''));

        $del = $pdo->prepare('DELETE FROM club_photos WHERE id = ? AND torneo_id = ?');
        $del->execute([$fotoId, $torneoId]);

        return $del->rowCount() > 0;
    }

    public static function eliminarArchivoFisico(string $rutaImagen): void
    {
        if ($rutaImagen === '') {
            return;
        }
        $root = self::projectRoot();
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($rutaImagen, '/'));
        if (is_file($full)) {
            @unlink($full);
        }
        $webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $full);
        if ($webp && is_file($webp)) {
            @unlink($webp);
        }
    }

    private static function uploadErrorMessage(int $code): string
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return 'La imagen supera el tamaño máximo permitido';
        }
        if ($code === UPLOAD_ERR_PARTIAL) {
            return 'La imagen se subió solo parcialmente';
        }
        if ($code === UPLOAD_ERR_NO_FILE) {
            return 'No se seleccionó ningún archivo';
        }

        return 'Error al subir la imagen (código ' . $code . ')';
    }
}
