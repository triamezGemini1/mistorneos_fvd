<?php

declare(strict_types=1);

require_once __DIR__ . '/FvdConfig.php';

/**
 * TournamentScopeHelper - Centraliza las reglas de ámbito, visibilidad e inscripción online.
 * Usado por landing, inscripción y formularios de torneos.
 */
class TournamentScopeHelper
{
    /** Tipos de evento: 0=Ninguno, 1=Nacional, 2=Regional, 3=Local, 4=Privado */
    public const TIPO_NINGUNO = 0;
    public const TIPO_NACIONAL = 1;
    public const TIPO_REGIONAL = 2;
    public const TIPO_LOCAL = 3;
    public const TIPO_PRIVADO = 4;

    /**
     * Reglas de visibilidad por tipo de evento.
     *
     * @param int $es_evento_masivo 0-4
     * @return array{publico: bool, requiere_login: bool, etiquetas: array<string>, permite_inscripcion_online: bool, descripcion: string}
     */
    public static function getVisibilityRules(int $es_evento_masivo): array
    {
        $reglas = [
            self::TIPO_NINGUNO => [
                'publico' => true,
                'requiere_login' => false,
                'etiquetas' => ['Torneo'],
                'permite_inscripcion_online' => true,
                'descripcion' => 'Torneo normal. Inscripción en línea si está habilitada.',
            ],
            self::TIPO_NACIONAL => [
                'publico' => true,
                'requiere_login' => false,
                'etiquetas' => ['Evento Nacional'],
                'permite_inscripcion_online' => true,
                'descripcion' => 'Evento Nacional. No genera ranking (tipo polla). Inscripción abierta.',
            ],
            self::TIPO_REGIONAL => [
                'publico' => true,
                'requiere_login' => false,
                'etiquetas' => ['Evento Regional'],
                'permite_inscripcion_online' => true,
                'descripcion' => 'Evento Regional. Requiere historial de participación previa para inscripción en línea.',
            ],
            self::TIPO_LOCAL => [
                'publico' => true,
                'requiere_login' => false,
                'etiquetas' => ['Evento Local'],
                'permite_inscripcion_online' => true,
                'descripcion' => 'Evento Local. Requiere historial de participación previa para inscripción en línea.',
            ],
            self::TIPO_PRIVADO => [
                'publico' => true,
                'requiere_login' => false,
                'etiquetas' => ['Evento Privado'],
                'permite_inscripcion_online' => false,
                'descripcion' => 'Evento Privado. Visible en el landing pero NO permite inscripción en línea.',
            ],
        ];

        return $reglas[$es_evento_masivo] ?? $reglas[self::TIPO_NINGUNO];
    }

    /**
     * Indica si el tipo de evento requiere historial de participación previa para inscripción en línea.
     * Tipos 2 (Regional) y 3 (Local) exigen historial; tipos 0, 1 y 4 no.
     *
     * @param int $es_evento_masivo 0-4
     * @return bool
     */
    public static function requiresHistorialParticipacion(int $es_evento_masivo): bool
    {
        return in_array($es_evento_masivo, [self::TIPO_REGIONAL, self::TIPO_LOCAL], true);
    }

    /**
     * Inscripción sin límite por entidad territorial (FVD = alcance nacional).
     *
     * @param array<string, mixed> $torneo
     * @param array<string, mixed>|null $usuario Usuario en sesión/BD (entidad)
     * @param int|null $entidadFormulario Entidad elegida en formulario público (usuario nuevo)
     */
    public static function pasaAmbitoTerritorialInscripcion(array $torneo, ?array $usuario = null, ?int $entidadFormulario = null): bool
    {
        if (class_exists('FvdConfig', false)) {
            return true;
        }

        $entidad_torneo = (int) ($torneo['entidad_torneo'] ?? $torneo['entidad'] ?? 0);
        if ($entidadFormulario !== null) {
            return ($entidad_torneo <= 0) || ($entidadFormulario > 0 && $entidadFormulario === $entidad_torneo);
        }
        if ($usuario === null) {
            return true;
        }
        $entidad_usuario = (int) ($usuario['entidad'] ?? 0);

        return ($entidad_torneo <= 0) || ($entidad_usuario > 0 && $entidad_usuario === $entidad_torneo);
    }

    /**
     * Verifica si un usuario puede inscribirse en línea en un torneo.
     *
     * Valida según es_evento_masivo (0-4):
     * - Tipo 0 (Ninguno): inscripción si permite_torneo, mismo ámbito.
     * - Tipo 1 (Nacional): inscripción abierta, sin historial.
     * - Tipo 2 (Regional) / 3 (Local): requiere historial (InscritosHelper::puedeInscribirseEnLinea).
     * - Tipo 4 (Privado): nunca permite inscripción online.
     *
     * Además: torneo y club permiten inscripción, mismo ámbito territorial.
     *
     * @param array $torneo Datos del torneo (debe incluir permite_inscripcion_linea, es_evento_masivo, entidad_torneo o entidad)
     * @param array|null $usuario Datos del usuario (club_id, entidad) o null si no autenticado
     * @param PDO|null $pdo Conexión para InscritosHelper (requerido si es_evento_masivo in [2,3])
     * @return array{can: bool, message: string}
     */
    public static function canRegisterOnline(array $torneo, ?array $usuario, ?PDO $pdo = null): array
    {
        $permite_torneo = (int)($torneo['permite_inscripcion_linea'] ?? 1) === 1;
        $es_evento_masivo = (int)($torneo['es_evento_masivo'] ?? 0);
        $reglas = self::getVisibilityRules($es_evento_masivo);

        // Evento Privado: nunca permite inscripción online
        if ($es_evento_masivo === self::TIPO_PRIVADO) {
            return [
                'can' => false,
                'message' => 'Este evento es privado. Solo puedes inscribirte en el sitio del evento.',
            ];
        }

        if (!$permite_torneo) {
            return [
                'can' => false,
                'message' => 'Este torneo no acepta inscripciones en línea. Contacta al administrador del club para inscribirte en el sitio del evento.',
            ];
        }

        if (!$reglas['permite_inscripcion_online']) {
            return [
                'can' => false,
                'message' => 'Este tipo de evento no permite inscripción en línea.',
            ];
        }

        // Usuario no autenticado: para eventos masivos (1,2,3) se puede crear usuario; para 0 y 4 requiere login
        if (!$usuario) {
            if (in_array($es_evento_masivo, [self::TIPO_NACIONAL, self::TIPO_REGIONAL, self::TIPO_LOCAL], true)) {
                return ['can' => true, 'message' => ''];
            }
            return [
                'can' => false,
                'message' => 'Debes iniciar sesión para inscribirte en este torneo.',
            ];
        }

        // Verificar que el club del usuario permita inscripción en línea
        $club_id = (int)($usuario['club_id'] ?? 0);
        if ($club_id > 0 && $pdo) {
            try {
                $stmt = $pdo->prepare('SELECT permite_inscripcion_linea FROM clubes WHERE id = ?');
                $stmt->execute([$club_id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($club && (int)($club['permite_inscripcion_linea'] ?? 1) !== 1) {
                    return [
                        'can' => false,
                        'message' => 'Tu club no permite inscripciones en línea. Puedes inscribirte en el sitio del evento.',
                    ];
                }
            } catch (Exception $e) {
                error_log('TournamentScopeHelper canRegisterOnline club: ' . $e->getMessage());
            }
        }

        if (!self::pasaAmbitoTerritorialInscripcion($torneo, $usuario)) {
            return [
                'can' => false,
                'message' => 'Este torneo está fuera de tu ámbito. Puedes inscribirte en el sitio del evento el día del torneo.',
            ];
        }

        // Para Regional (2) y Local (3): verificar historial de participación (no presentaciones consecutivas)
        if (self::requiresHistorialParticipacion($es_evento_masivo) && $pdo) {
            $user_id = (int)($usuario['id'] ?? $usuario['user_id'] ?? 0);
            if ($user_id > 0) {
                require_once __DIR__ . '/InscritosHelper.php';
                $validacion = InscritosHelper::puedeInscribirseEnLinea($pdo, $user_id);
                if (!$validacion['puede_inscribirse']) {
                    return [
                        'can' => false,
                        'message' => $validacion['razon'] ?? 'No cumples con el historial de participación requerido.',
                    ];
                }
            }
        }

        return ['can' => true, 'message' => ''];
    }

    /**
     * Determina si un torneo debe mostrarse en el landing según su tipo y (futuro) publicar_landing.
     *
     * @param array $torneo
     * @return bool
     */
    public static function getLandingFilter(array $torneo): bool
    {
        $publicar = (int)($torneo['publicar_landing'] ?? 1) === 1;
        if (!$publicar && array_key_exists('publicar_landing', $torneo)) {
            return false;
        }
        return true;
    }

    /**
     * Verifica si el acceso público a los resultados del torneo está permitido.
     * Usa publicar_landing: si está en 0, los resultados no son accesibles públicamente.
     *
     * @param array $torneo Datos del torneo (debe incluir publicar_landing si existe la columna)
     * @return bool
     */
    public static function canAccessResultsPublicly(array $torneo): bool
    {
        if (!array_key_exists('publicar_landing', $torneo)) {
            return true;
        }
        return (int)($torneo['publicar_landing'] ?? 1) === 1;
    }
}
