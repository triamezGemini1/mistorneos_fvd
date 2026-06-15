<?php

declare(strict_types=1);

/**
 * Subida y persistencia de foto de perfil (upload/ + usuarios.photo_path).
 */
final class ProfilePhotoService
{
    public static function uploadErrorMessage(int $code): string
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return 'La imagen supera el tamaño máximo permitido (2 MB)';
        }
        if ($code === UPLOAD_ERR_PARTIAL) {
            return 'La imagen se subió solo parcialmente; intente de nuevo';
        }
        if ($code === UPLOAD_ERR_NO_FILE) {
            return 'Seleccione una imagen';
        }

        return 'Error al subir la imagen (código ' . $code . ')';
    }

    public static function storeUploadedFile(array $file, ?string $oldPhotoPath): string
    {
        $uploadCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($uploadCode));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('No se recibió el archivo de imagen');
        }

        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $uploadDir = $root . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('No se pudo crear la carpeta upload/ en el servidor');
        }
        if (!is_writable($uploadDir)) {
            throw new RuntimeException('La carpeta upload/ no tiene permisos de escritura (use 755 o 775)');
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Tipo de archivo no permitido. Use JPG, PNG, GIF o WebP');
        }
        if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new RuntimeException('La imagen no debe superar 2 MB');
        }

        $newName = uniqid('profile_', true) . '.' . $ext;
        $target = $uploadDir . $newName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Error al guardar la imagen en el servidor');
        }

        $photoPath = 'upload/' . $newName;
        self::deleteOldPhoto($oldPhotoPath, $photoPath, $root);

        return $photoPath;
    }

    public static function saveForUser(int $userId, array $file, ?string $oldPhotoPath): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario no válido');
        }
        if (empty($file['name'])) {
            throw new RuntimeException('Seleccione una imagen antes de guardar');
        }

        $photoPath = self::storeUploadedFile($file, $oldPhotoPath);

        require_once __DIR__ . '/../config/db.php';
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('UPDATE usuarios SET photo_path = ? WHERE id = ?');
        $stmt->execute([$photoPath, $userId]);

        if (!empty($_SESSION['user']) && (int)($_SESSION['user']['id'] ?? 0) === $userId) {
            $_SESSION['user']['photo_path'] = $photoPath;
        }

        return $photoPath;
    }

    private static function deleteOldPhoto(?string $oldPhotoPath, string $newPhotoPath, string $root): void
    {
        if (!$oldPhotoPath) {
            return;
        }
        $oldResolved = $oldPhotoPath;
        if (class_exists('AppHelpers', false) && method_exists('AppHelpers', 'resolveUserPhotoStoragePath')) {
            $oldResolved = AppHelpers::resolveUserPhotoStoragePath($oldPhotoPath);
        }
        if ($oldResolved === '' || $oldResolved === $newPhotoPath) {
            return;
        }
        $oldFull = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldResolved);
        if (is_file($oldFull)) {
            @unlink($oldFull);
        }
    }
}
