<?php
/**
 * Títulos públicos para documentos del landing (independientes del nombre de archivo en disco).
 */
declare(strict_types=1);

final class LandingDocumentosMeta
{
    private const META_FILENAME = '_titulos.json';

    public static function metaPath(string $directory): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::META_FILENAME;
    }

    /** @return array<string, string> archivo => título */
    public static function load(string $directory): array
    {
        $path = self::metaPath($directory);
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $archivo => $titulo) {
            if (!is_string($archivo) || !is_string($titulo)) {
                continue;
            }
            $archivo = basename($archivo);
            $titulo = trim($titulo);
            if ($archivo !== '' && $titulo !== '') {
                $out[$archivo] = $titulo;
            }
        }

        return $out;
    }

    public static function save(string $directory, array $meta): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $clean = [];
        foreach ($meta as $archivo => $titulo) {
            $archivo = basename((string) $archivo);
            $titulo = trim((string) $titulo);
            if ($archivo !== '' && $titulo !== '') {
                $clean[$archivo] = $titulo;
            }
        }
        $path = self::metaPath($directory);
        if ($clean === []) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        @file_put_contents(
            $path,
            json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public static function setTitulo(string $directory, string $archivo, string $titulo): void
    {
        $meta = self::load($directory);
        $archivo = basename($archivo);
        $titulo = trim($titulo);
        if ($archivo === '') {
            return;
        }
        if ($titulo === '') {
            unset($meta[$archivo]);
        } else {
            $meta[$archivo] = $titulo;
        }
        self::save($directory, $meta);
    }

    public static function remove(string $directory, string $archivo): void
    {
        $meta = self::load($directory);
        unset($meta[basename($archivo)]);
        self::save($directory, $meta);
    }

    public static function renameKey(string $directory, string $archivoAnterior, string $archivoNuevo): void
    {
        $meta = self::load($directory);
        $archivoAnterior = basename($archivoAnterior);
        $archivoNuevo = basename($archivoNuevo);
        if ($archivoAnterior === $archivoNuevo) {
            return;
        }
        if (isset($meta[$archivoAnterior])) {
            $meta[$archivoNuevo] = $meta[$archivoAnterior];
            unset($meta[$archivoAnterior]);
            self::save($directory, $meta);
        }
    }

    public static function titulo(string $directory, string $archivo): ?string
    {
        $meta = self::load($directory);
        $archivo = basename($archivo);

        return $meta[$archivo] ?? null;
    }

    /** Título corto derivado del nombre de archivo si no hay meta. */
    public static function tituloDesdeNombreArchivo(string $filename, int $maxLen = 48): string
    {
        $base = pathinfo(basename($filename), PATHINFO_FILENAME);
        $base = str_replace(['_', '-'], ' ', $base);
        $base = preg_replace('/\s+/', ' ', trim($base)) ?? '';
        if ($base === '') {
            return 'Documento';
        }
        if (function_exists('mb_convert_case')) {
            $base = mb_convert_case($base, MB_CASE_TITLE, 'UTF-8');
        } else {
            $base = ucwords(strtolower($base));
        }
        if (strlen($base) > $maxLen) {
            if (function_exists('mb_substr')) {
                $base = mb_substr($base, 0, $maxLen - 1, 'UTF-8') . '…';
            } else {
                $base = substr($base, 0, $maxLen - 3) . '…';
            }
        }

        return $base;
    }

    public static function tituloEfectivo(string $directory, string $archivo): string
    {
        $custom = self::titulo($directory, $archivo);

        return $custom !== null && $custom !== ''
            ? $custom
            : self::tituloDesdeNombreArchivo($archivo);
    }
}
