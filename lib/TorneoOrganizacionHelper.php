<?php

declare(strict_types=1);

/**
 * Resolución de organización del torneo (club_responsable, cod_org, entidad) y URLs de afiche.
 */
final class TorneoOrganizacionHelper
{
    private static ?bool $hasCodOrg = null;

    public static function hasCodOrgColumn(PDO $pdo): bool
    {
        if (self::$hasCodOrg === null) {
            try {
                self::$hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                self::$hasCodOrg = false;
            }
        }

        return self::$hasCodOrg;
    }

    public static function orgJoinSql(PDO $pdo, string $tAlias = 't', string $oAlias = 'o'): string
    {
        if (self::hasCodOrgColumn($pdo)) {
            return "LEFT JOIN organizaciones {$oAlias} ON (({$tAlias}.club_responsable = {$oAlias}.id OR {$tAlias}.club_responsable = {$oAlias}.cod_org) AND {$oAlias}.estatus = 1)";
        }

        return "LEFT JOIN organizaciones {$oAlias} ON {$tAlias}.club_responsable = {$oAlias}.id AND {$oAlias}.estatus = 1";
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveOrganizacion(PDO $pdo, int $clubRef, int $entidadTorneo = 0): ?array
    {
        if ($clubRef <= 0) {
            return null;
        }

        if (self::hasCodOrgColumn($pdo)) {
            $st = $pdo->prepare(
                'SELECT id, nombre, logo, entidad, cod_org, responsable, telefono, email, direccion
                 FROM organizaciones
                 WHERE estatus = 1 AND (id = ? OR cod_org = ?)'
            );
            $st->execute([$clubRef, $clubRef]);
        } else {
            $st = $pdo->prepare(
                'SELECT id, nombre, logo, entidad, responsable, telefono, email, direccion
                 FROM organizaciones
                 WHERE estatus = 1 AND id = ?'
            );
            $st->execute([$clubRef]);
        }

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if (count($rows) === 1) {
            return $rows[0];
        }
        if ($entidadTorneo > 0) {
            foreach ($rows as $row) {
                if ((int) ($row['entidad'] ?? 0) === $entidadTorneo) {
                    return $row;
                }
            }
        }

        return $rows[0];
    }

    /**
     * @param array<string, mixed> $torneo
     * @return array<string, mixed>
     */
    public static function enriquecerTorneo(PDO $pdo, array $torneo): array
    {
        $entidadTorneo = (int) ($torneo['entidad_torneo'] ?? $torneo['entidad'] ?? 0);
        $clubRef = (int) ($torneo['club_responsable'] ?? 0);

        if ($clubRef > 0 && (empty($torneo['organizacion_nombre']) || !array_key_exists('organizacion_logo', $torneo))) {
            $org = self::resolveOrganizacion($pdo, $clubRef, $entidadTorneo);
            if ($org !== null) {
                $torneo['organizacion_nombre'] = $org['nombre'] ?? ($torneo['organizacion_nombre'] ?? 'N/A');
                $torneo['organizacion_logo'] = $org['logo'] ?? null;
                if ($entidadTorneo <= 0 && (int) ($org['entidad'] ?? 0) > 0) {
                    $entidadTorneo = (int) $org['entidad'];
                }
                foreach (['responsable' => 'organizacion_responsable', 'telefono' => 'organizacion_telefono', 'email' => 'organizacion_email', 'direccion' => 'organizacion_direccion'] as $src => $dst) {
                    if (empty($torneo[$dst]) && !empty($org[$src])) {
                        $torneo[$dst] = $org[$src];
                    }
                }
            }
        }

        $torneo['entidad_torneo'] = $entidadTorneo > 0 ? $entidadTorneo : (int) ($torneo['entidad'] ?? 0);

        return $torneo;
    }

    public static function tournamentFileRelative(?string $storedPath): string
    {
        if ($storedPath === null || $storedPath === '') {
            return '';
        }
        if (strpos($storedPath, 'http') === 0) {
            return $storedPath;
        }

        $rel = str_replace('\\', '/', ltrim($storedPath, '/'));
        if (str_starts_with($rel, 'upload/tournaments/')) {
            $rel = substr($rel, strlen('upload/tournaments/'));
        }

        return ltrim($rel, '/');
    }

    public static function tournamentFilePublicUrl(?string $storedPath): string
    {
        if ($storedPath === null || $storedPath === '') {
            return '';
        }
        if (strpos($storedPath, 'http') === 0) {
            return $storedPath;
        }

        $rel = self::tournamentFileRelative($storedPath);
        if ($rel === '') {
            return '';
        }

        return rtrim(AppHelpers::getPublicUrl(), '/') . '/view_tournament_file.php?file=' . rawurlencode($rel);
    }

    public static function aficheEsImagen(?string $storedPath): bool
    {
        if ($storedPath === null || $storedPath === '') {
            return false;
        }

        return in_array(strtolower(pathinfo($storedPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /**
     * Imagen superior de tarjeta de evento: afiche (si es imagen), logo org por entidad o logo FVD.
     *
     * @param array<string, mixed> $evento
     */
    public static function eventBannerUrl(array $evento): string
    {
        $afiche = (string) ($evento['afiche'] ?? '');
        if ($afiche !== '' && self::aficheEsImagen($afiche)) {
            $url = self::tournamentFilePublicUrl($afiche);
            if ($url !== '') {
                return $url;
            }
        }

        $entidad = (int) ($evento['entidad_torneo'] ?? $evento['entidad'] ?? 0);
        $masivo = in_array((int) ($evento['es_evento_masivo'] ?? 0), [1, 2, 3], true);
        if ($masivo || $entidad <= 0 || $entidad === 999) {
            return class_exists('FvdBranding', false) ? FvdBranding::logoUrl() : AppHelpers::getAppLogo();
        }

        if (!empty($evento['organizacion_logo'])) {
            $orgUrl = AppHelpers::imageUrl((string) $evento['organizacion_logo']);
            if ($orgUrl !== '') {
                return $orgUrl;
            }
        }

        return class_exists('FvdBranding', false) ? FvdBranding::logoUrl() : AppHelpers::getAppLogo();
    }

    /**
     * @param array<string, mixed> $evento
     */
    public static function eventBannerIsAfiche(array $evento): bool
    {
        $afiche = (string) ($evento['afiche'] ?? '');

        return $afiche !== '' && self::aficheEsImagen($afiche);
    }
}
