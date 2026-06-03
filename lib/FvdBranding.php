<?php

declare(strict_types=1);

/**
 * Identidad visual y textual de la organización ancla (FVD).
 */
final class FvdBranding
{
    public const COLOR_PRIMARY = '#1a365d';
    public const COLOR_PRIMARY_LIGHT = '#2d4a6b';
    public const COLOR_ACCENT = '#2563eb';

    public static function nombre(): string
    {
        return FvdConfig::getOrganizacionNombre();
    }

    public static function siglas(): string
    {
        $row = FvdConfig::getOrganizacionMaestra();
        $siglas = trim((string)($row['siglas'] ?? ''));
        return $siglas !== '' ? $siglas : FvdConfig::ORGANIZACION_SIGLAS;
    }

    public static function nombreCorto(): string
    {
        return self::siglas();
    }

    public static function logoUrl(): string
    {
        if (!class_exists('AppHelpers', false)) {
            return '';
        }

        return AppHelpers::getAppLogo();
    }

    /**
     * Href del logo para layouts con base href en public/.
     */
    public static function logoHref(?string $basePrefix = null): string
    {
        if (!class_exists('AppHelpers', false)) {
            return '';
        }

        return AppHelpers::getAppLogoHref($basePrefix);
    }

    public static function appTitle(string $suffix = ''): string
    {
        $base = self::nombre();
        return $suffix !== '' ? $base . ' — ' . $suffix : $base;
    }

    public static function soporteTecnico(): string
    {
        return 'Sistema ' . self::siglas();
    }

    /**
     * Variables CSS institucionales (alineadas con dashboard.css).
     *
     * @return array<string, string>
     */
    public static function cssVariables(): array
    {
        return [
            '--fvd-primary' => self::COLOR_PRIMARY,
            '--fvd-primary-light' => self::COLOR_PRIMARY_LIGHT,
            '--fvd-accent' => self::COLOR_ACCENT,
            '--fvd-gradient' => 'linear-gradient(135deg, ' . self::COLOR_PRIMARY . ' 0%, ' . self::COLOR_PRIMARY_LIGHT . ' 100%)',
            '--fvd-gradient-header' => 'linear-gradient(135deg, #1e40af 0%, ' . self::COLOR_PRIMARY . ' 100%)',
            '--fvd-swal-confirm' => self::COLOR_PRIMARY,
        ];
    }

    public static function inlineCssBlock(): string
    {
        $parts = [];
        foreach (self::cssVariables() as $key => $value) {
            $parts[] = $key . ':' . $value;
        }
        return ':root{' . implode(';', $parts) . '}';
    }
}
