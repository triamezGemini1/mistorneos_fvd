<?php

declare(strict_types=1);

/**
 * Shell HTML unificado FVD — usar como modelo en nuevas pantallas y migraciones graduales.
 */
final class FvdAppPage
{
    public const SHELL_CLASS = 'fvd-app-page fvd-app-page--compact';
    public const SHELL_CLASS_GLASS = 'fvd-app-page fvd-app-page--compact fvd-app-page--glass';

    /** @param list<array{label: string, href?: string, active?: bool}> $items */
    public static function renderBreadcrumb(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $html = '<nav aria-label="breadcrumb" class="mb-1"><ol class="breadcrumb mb-0">';
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $active = !empty($item['active']) || $i === $last;
            if ($active || empty($item['href'])) {
                $html .= '<li class="breadcrumb-item' . ($active ? ' active' : '') . '"'
                    . ($active ? ' aria-current="page"' : '') . '>' . $label . '</li>';
            } else {
                $href = htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8');
                $html .= '<li class="breadcrumb-item"><a href="' . $href . '">' . $label . '</a></li>';
            }
        }
        $html .= '</ol></nav>';

        return $html;
    }

    public static function openShell(string $extraClass = '', bool $glass = false): string
    {
        $base = $glass ? self::SHELL_CLASS_GLASS : self::SHELL_CLASS;
        $class = trim($base . ' ' . $extraClass);

        return '<div class="container-fluid py-2 ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function closeShell(): string
    {
        return '</div>';
    }

    public static function cardOpen(string $title, string $icon = 'fas fa-layer-group', string $extraHeaderHtml = ''): string
    {
        $iconClass = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<div class="card shadow-sm fvd-app-card mb-2">'
            . '<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-1 py-2">'
            . '<h6 class="mb-0"><i class="' . $iconClass . ' me-1"></i>' . $titleEsc . '</h6>'
            . $extraHeaderHtml
            . '</div><div class="card-body py-2">';
    }

    public static function cardClose(): string
    {
        return '</div></div>';
    }

    public static function kpi(string $num, string $label, string $tone = 'blue', string $sub = ''): string
    {
        $toneClass = preg_match('/^[a-z-]+$/', $tone) ? $tone : 'blue';
        $html = '<div class="fvd-app-kpi fvd-app-kpi--' . $toneClass . '">'
            . '<strong class="fvd-app-kpi-num">' . htmlspecialchars($num, ENT_QUOTES, 'UTF-8') . '</strong>'
            . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($sub !== '') {
            $html .= '<small class="d-block mt-0">' . htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') . '</small>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @return list<string>
     */
    public static function excludedPagesForGlobalShell(): array
    {
        return [
            'home',
            'asociacion_panel',
            'estadisticas_torneos',
        ];
    }

    /**
     * @return list<string>
     */
    public static function excludedTorneoGestionActions(): array
    {
        return [
            'panel',
            'panel_equipos',
            'registrar_resultados',
            'registrar_resultados_v2',
            'cuadricula',
            'hojas_anotacion',
            'inscribir_sitio',
            'inscribir_equipo_sitio',
            'carga_masiva_parejas_sitio',
            'carga_masiva_equipos_sitio',
            'carga_masiva_parejas_plantilla',
            'carga_masiva_equipos_plantilla',
        ];
    }

    public static function shouldApplyGlobalShell(string $page, string $action = ''): bool
    {
        if (in_array($page, self::excludedPagesForGlobalShell(), true)) {
            return false;
        }
        if ($page === 'torneo_gestion' && in_array($action, self::excludedTorneoGestionActions(), true)) {
            return false;
        }
        if ($page === 'registrants' && $action === 'inscribir_sitio') {
            return false;
        }

        return true;
    }
}
