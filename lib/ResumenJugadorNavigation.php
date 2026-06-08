<?php

declare(strict_types=1);

require_once __DIR__ . '/app_helpers.php';

/**
 * Enlaces al resumen individual del jugador (admin) y navegación de retorno.
 */
final class ResumenJugadorNavigation
{
    /** @var array<string, string> from → action torneo_gestion */
    private const FROM_ACTION = [
        'posiciones' => 'posiciones',
        'resultados_general' => 'resultados_general',
        'resultados_por_club' => 'resultados_por_club',
        'resultados_equipos_detallado' => 'resultados_equipos_detallado',
        'resultados_equipos_resumido' => 'resultados_equipos_resumido',
        'resultados_reportes' => 'resultados_reportes',
        'resultados_reportes_print' => 'resultados_reportes',
        'reporte_estructura_mesas' => 'reporte_estructura_mesas',
        'reporte_parejas_repetidas' => 'reporte_parejas_repetidas',
        'reporte_sanciones_ronda' => 'reporte_sanciones_ronda',
        'registrar_resultados' => 'registrar_resultados',
        'registrar_resultados_v2' => 'registrar_resultados_v2',
        'mesas' => 'mesas',
        'rondas' => 'rondas',
        'cuadricula' => 'cuadricula',
        'hojas_anotacion' => 'hojas_anotacion',
        'reportes_inscritos' => 'reportes_inscritos',
        'reporte_resultados_general' => 'resultados_general',
    ];

    /** Parámetros GET que se conservan al volver (filtros / paginación). */
    private const PRESERVE_ON_RETURN = [
        'genero', 'pagina', 'vista', 'club_id', 'ronda', 'mesa', 'por_pagina', 'min_veces',
    ];

    public static function normalizeFrom(string $from): string
    {
        $from = trim($from);
        if ($from === '' || ! isset(self::FROM_ACTION[$from])) {
            return 'posiciones';
        }

        return $from;
    }

    /**
     * Contexto URL según script actual (index vs panel_torneo / admin_torneo).
     *
     * @return array{standalone: bool, base: string, sep: string}
     */
    public static function torneoGestionContext(): array
    {
        $script = basename($_SERVER['PHP_SELF'] ?? '');
        $standalone = in_array($script, ['admin_torneo.php', 'panel_torneo.php'], true);

        return [
            'standalone' => $standalone,
            'base' => $standalone ? $script : 'index.php?page=torneo_gestion',
            'sep' => $standalone ? '?' : '&',
        ];
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    public static function urlResumenIndividual(
        int $torneoId,
        int $idUsuario,
        string $from = 'posiciones',
        array $extra = [],
        ?array $context = null
    ): string {
        if ($torneoId <= 0 || $idUsuario <= 0) {
            return '';
        }

        $from = self::normalizeFrom($from);
        $preserve = self::capturePreserveParams();
        $params = array_merge($preserve, $extra, [
            'action' => 'resumen_individual',
            'torneo_id' => $torneoId,
            'inscrito_id' => $idUsuario,
            'from' => $from,
        ]);

        $ctx = $context ?? self::torneoGestionContext();
        if ($ctx['standalone']) {
            return $ctx['base'] . $ctx['sep'] . http_build_query($params);
        }

        return AppHelpers::url('index.php', array_merge(['page' => 'torneo_gestion'], $params));
    }

    public static function urlResumenPublico(int $torneoId, int $idUsuario): string
    {
        if ($torneoId <= 0 || $idUsuario <= 0) {
            return '';
        }

        return AppHelpers::url('resumen_jugador.php', [
            'torneo_id' => $torneoId,
            'id_usuario' => $idUsuario,
        ]);
    }

    /**
     * URL para volver a la pantalla de origen.
     *
     * @param array<string, scalar|null> $extra
     */
    public static function urlVolver(
        int $torneoId,
        string $from,
        array $extra = [],
        ?array $context = null
    ): string {
        if ($torneoId <= 0) {
            return AppHelpers::urlPanelTorneoReturn(0);
        }

        $from = trim($from);
        if ($from === 'notificaciones') {
            if (class_exists('Auth', false)) {
                $rol = Auth::user()['role'] ?? '';
                if ($rol === 'usuario') {
                    return AppHelpers::url('user_portal.php', ['section' => 'notificaciones']);
                }
            }

            return AppHelpers::dashboard('user_notificaciones');
        }

        $action = self::FROM_ACTION[$from] ?? 'panel';
        $params = array_merge(self::capturePreserveParams(), $extra, [
            'action' => $action,
            'torneo_id' => $torneoId,
        ]);

        if ($from === 'resultados_reportes_print' && isset($_GET['tipo']) && $_GET['tipo'] !== '') {
            // Volver a impresión no; ya mapeado a resultados_reportes
        }

        $ctx = $context ?? self::torneoGestionContext();
        if ($ctx['standalone']) {
            return $ctx['base'] . $ctx['sep'] . http_build_query($params);
        }

        return AppHelpers::url('index.php', array_merge(['page' => 'torneo_gestion'], $params));
    }

    /**
     * @return array<string, string>
     */
    public static function capturePreserveParams(): array
    {
        $out = [];
        foreach (self::PRESERVE_ON_RETURN as $key) {
            if (! isset($_GET[$key]) || $_GET[$key] === '') {
                continue;
            }
            $val = $_GET[$key];
            if (is_scalar($val)) {
                $out[$key] = (string) $val;
            }
        }

        return $out;
    }

    /**
     * Enlace HTML al resumen (admin). Si no hay id, devuelve solo el nombre escapado.
     */
    public static function enlaceNombre(
        string $nombre,
        int $torneoId,
        int $idUsuario,
        string $from = 'posiciones',
        string $class = 'text-purple-600 hover:text-purple-800 hover:underline font-semibold',
        array $extra = [],
        ?array $context = null
    ): string {
        $nombreEsc = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $url = self::urlResumenIndividual($torneoId, $idUsuario, $from, $extra, $context);
        if ($url === '') {
            return $nombreEsc;
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="'
            . htmlspecialchars($class, ENT_QUOTES, 'UTF-8')
            . '" title="Ver resumen individual del jugador">'
            . $nombreEsc
            . ' <i class="fas fa-user-chart text-xs opacity-80"></i></a>';
    }

    public static function etiquetaVolver(string $from): string
    {
        if ($from === 'notificaciones') {
            return 'Volver a Notificaciones';
        }
        if (in_array($from, ['resultados_reportes', 'resultados_reportes_print'], true)) {
            return 'Volver a Reportes de resultados';
        }
        if ($from === 'resultados_general') {
            return 'Volver a Resultados general';
        }
        if ($from === 'resultados_por_club') {
            return 'Volver a Resultados por club';
        }
        if ($from === 'resultados_equipos_detallado') {
            return 'Volver a Equipos (detallado)';
        }
        if ($from === 'resultados_equipos_resumido') {
            return 'Volver a Equipos (resumido)';
        }
        if ($from === 'reporte_estructura_mesas') {
            return 'Volver a Estructura de mesas';
        }
        if ($from === 'reporte_parejas_repetidas') {
            return 'Volver a Parejas repetidas';
        }
        if (in_array($from, ['registrar_resultados', 'registrar_resultados_v2'], true)) {
            return 'Volver a Registrar resultados';
        }
        if ($from === 'posiciones') {
            return 'Volver a Posiciones';
        }

        return 'Volver';
    }
}
