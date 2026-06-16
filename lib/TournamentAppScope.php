<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdAdminGate.php';

if (!class_exists('FvdInstitutionalScope', false)) {
    require_once __DIR__ . '/FvdInstitutionalScope.php';
}

/**
 * Alcance de la app según modo instalación.
 * - Institucional (FVD_INSTITUTIONAL_ONLY=true): panel FVD + CRUD campeonatos; operación de mesas en otra app.
 * - Torneos solo (FVD_ADMIN_ENABLED=false): operación de torneos sin módulos FVD institucionales.
 */
final class TournamentAppScope
{
    /** Modo restringido FVD (sin finanzas/afiliación en menú). */
    public static function isTorneosOnly(): bool
    {
        if (class_exists('FvdInstitutionalScope', false) && FvdInstitutionalScope::isEnabled()) {
            return false;
        }

        return FvdAdminGate::isRestricted();
    }

    /**
     * Tipos en notifications_queue.datos_json que pertenecen al panel FVD (no torneos).
     *
     * @return list<string>
     */
    public static function tiposNotificacionExcluidos(): array
    {
        return [
            'donacion_reportes_activado',
            'solicitud_afiliacion',
            'solicitud_asociacion_nueva',
        ];
    }

    /**
     * @param array<string, mixed>|null $datos
     */
    public static function esNotificacionDeTorneo(?array $datos): bool
    {
        if ($datos === null || $datos === []) {
            return true;
        }
        $tipo = isset($datos['tipo']) ? trim((string) $datos['tipo']) : '';
        if ($tipo === '') {
            return true;
        }

        return ! in_array($tipo, self::tiposNotificacionExcluidos(), true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function filtrarFilasNotificaciones(array $rows): array
    {
        if (! self::isTorneosOnly()) {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row): bool {
            $datos = null;
            if (! empty($row['datos_json'])) {
                $decoded = json_decode((string) $row['datos_json'], true);
                $datos = is_array($decoded) ? $decoded : null;
            }

            return self::esNotificacionDeTorneo($datos);
        }));
    }

    /**
     * Fragmento SQL AND para excluir notificaciones FVD (MySQL 5.7+ JSON).
     */
    public static function sqlExcluirNotificacionesFvd(string $alias = 'nq'): string
    {
        if (! self::isTorneosOnly()) {
            return '';
        }

        $tipos = self::tiposNotificacionExcluidos();
        if ($tipos === []) {
            return '';
        }

        $quoted = implode(',', array_map(
            static fn (string $t): string => "'" . str_replace("'", "''", $t) . "'",
            $tipos
        ));

        return " AND (
            {$alias}.datos_json IS NULL
            OR {$alias}.datos_json = ''
            OR JSON_UNQUOTE(JSON_EXTRACT({$alias}.datos_json, '$.tipo')) IS NULL
            OR JSON_UNQUOTE(JSON_EXTRACT({$alias}.datos_json, '$.tipo')) NOT IN ({$quoted})
        )";
    }
}
