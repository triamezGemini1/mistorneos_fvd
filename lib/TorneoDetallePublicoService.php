<?php

declare(strict_types=1);

require_once __DIR__ . '/TournamentPhotoService.php';
require_once __DIR__ . '/app_helpers.php';

/**
 * Datos públicos de un torneo para landing / torneo_detalle.php.
 */
final class TorneoDetallePublicoService
{
    /**
     * @return array<string, mixed>|null
     */
    public static function cargar(PDO $pdo, int $torneoId, ?string $publicBaseUrl = null): ?array
    {
        if ($torneoId <= 0) {
            return null;
        }

        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasCodOrg = false;
        }

        $orgJoin = $hasCodOrg
            ? 'LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org) AND o.estatus = 1'
            : 'LEFT JOIN organizaciones o ON t.club_responsable = o.id AND o.estatus = 1';

        $st = $pdo->prepare("
            SELECT
                t.*,
                COALESCE(o.nombre, c.nombre) AS organizacion_nombre,
                COALESCE(o.responsable, c.delegado) AS organizacion_responsable,
                COALESCE(o.telefono, c.telefono) AS organizacion_telefono,
                COALESCE(o.direccion, c.direccion) AS organizacion_direccion,
                COALESCE(o.email, c.email) AS organizacion_email,
                COALESCE(o.logo, c.logo) AS organizacion_logo,
                (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = t.id
                    AND (i.estatus = 'confirmado' OR i.estatus IS NULL OR i.estatus = 1 OR i.estatus = '1')) AS total_inscritos
            FROM tournaments t
            {$orgJoin}
            LEFT JOIN clubes c ON t.club_responsable = c.id AND c.estatus = 1
            WHERE t.id = ? AND t.estatus = 1
            LIMIT 1
        ");
        $st->execute([$torneoId]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            return null;
        }

        $publicBase = $publicBaseUrl ?? rtrim(AppHelpers::getPublicUrl(), '/') . '/';
        $fileBase = rtrim($publicBase, '/') . '/view_tournament_file.php';

        $archivoUrl = static function (?string $path) use ($fileBase): ?string {
            if ($path === null || trim($path) === '') {
                return null;
            }
            $file = str_replace('upload/tournaments/', '', $path);

            return $fileBase . '?file=' . rawurlencode($file);
        };

        $logoOrg = trim((string) ($torneo['organizacion_logo'] ?? ''));
        $logoUrl = $logoOrg !== '' ? AppHelpers::publicImageUrl($logoOrg, $publicBase) : AppHelpers::getAppLogo();
        if ($logoUrl === '') {
            $logoUrl = AppHelpers::getAppLogo();
        }

        $aficheUrl = $archivoUrl($torneo['afiche'] ?? null);
        $invitacionUrl = $archivoUrl($torneo['invitacion'] ?? null);
        $normasUrl = $archivoUrl($torneo['normas'] ?? null);

        $fotos = TournamentPhotoService::listarPublicas($pdo, $torneoId, $publicBase);
        $fechator = substr((string) ($torneo['fechator'] ?? ''), 0, 10);
        $esPasado = $fechator !== '' && $fechator < date('Y-m-d');

        $portada = '';
        if ($aficheUrl) {
            $portada = $aficheUrl;
        } elseif ($fotos !== []) {
            $portada = (string) ($fotos[0]['url'] ?? '');
        }

        return [
            'torneo' => $torneo,
            'torneo_id' => $torneoId,
            'organizacion' => [
                'nombre' => (string) ($torneo['organizacion_nombre'] ?? ''),
                'responsable' => (string) ($torneo['organizacion_responsable'] ?? ''),
                'telefono' => (string) ($torneo['organizacion_telefono'] ?? ''),
                'email' => (string) ($torneo['organizacion_email'] ?? ''),
                'direccion' => (string) ($torneo['organizacion_direccion'] ?? ''),
                'logo_url' => $logoUrl,
            ],
            'archivos' => array_values(array_filter([
                $aficheUrl ? ['tipo' => 'afiche', 'titulo' => 'Afiche', 'url' => $aficheUrl, 'icon' => 'fa-image'] : null,
                $invitacionUrl ? ['tipo' => 'invitacion', 'titulo' => 'Invitación oficial', 'url' => $invitacionUrl, 'icon' => 'fa-envelope'] : null,
                $normasUrl ? ['tipo' => 'normas', 'titulo' => 'Normas / Condiciones', 'url' => $normasUrl, 'icon' => 'fa-file-alt'] : null,
            ])),
            'fotos' => $fotos,
            'total_fotos' => count($fotos),
            'portada_url' => $portada,
            'es_pasado' => $esPasado,
            'landing_url' => rtrim($publicBase, '/') . '/landing-spa.php',
            'galeria_url' => rtrim($publicBase, '/') . '/galeria_fotos.php?torneo_id=' . $torneoId,
            'public_base' => $publicBase,
        ];
    }
}
