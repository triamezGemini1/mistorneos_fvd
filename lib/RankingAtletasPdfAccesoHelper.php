<?php



declare(strict_types=1);



require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';

require_once __DIR__ . '/FinanzasAsociacionData.php';

require_once __DIR__ . '/OrganizacionDashboardStats.php';



/**

 * Reglas de acceso al PDF personal del ranking público.

 * El administrador habilita usuarios con usuarios.permite_reportes_personales.

 */

final class RankingAtletasPdfAccesoHelper

{

    /** Interruptor global de la funcionalidad (no sustituye el permiso por usuario). */

    public const DESCARGA_HABILITADA = true;



    /**

     * Cuando el sistema de afiliación esté plenamente activo, poner en true para exigir

     * entidad, NUMFVD y anualidad además del flag por usuario.

     */

    public const REQUIERE_AFILIACION_VIGENTE = false;



    private static ?bool $columnaPermiteReportes = null;



    public static function descargaGlobalHabilitada(): bool

    {

        return self::DESCARGA_HABILITADA;

    }



    public static function columnaPermiteReportesDisponible(PDO $pdo): bool

    {

        if (self::$columnaPermiteReportes !== null) {

            return self::$columnaPermiteReportes;

        }

        try {

            self::$columnaPermiteReportes = (bool) $pdo->query(

                "SHOW COLUMNS FROM usuarios LIKE 'permite_reportes_personales'"

            )->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            self::$columnaPermiteReportes = false;

        }



        return self::$columnaPermiteReportes;

    }



    public static function usuarioTienePermisoReportes(PDO $pdo, int $userId): bool

    {

        if ($userId < 1 || ! self::columnaPermiteReportesDisponible($pdo)) {

            return false;

        }

        $st = $pdo->prepare(

            'SELECT permite_reportes_personales FROM usuarios WHERE id = ? LIMIT 1'

        );

        $st->execute([$userId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);



        return $row !== false && (int) ($row['permite_reportes_personales'] ?? 0) === 1;

    }



    /**

     * @param array<string, mixed>|null $sessionUser

     * @return array{permitido: bool, motivo: string|null, mensaje: string}

     */

    public static function evaluar(PDO $pdo, ?array $sessionUser): array

    {

        if (! self::DESCARGA_HABILITADA) {

            return [

                'permitido' => false,

                'motivo' => 'deshabilitado',

                'mensaje' => 'La descarga de PDF estará disponible próximamente.',

            ];

        }



        if (! is_array($sessionUser) || (int) ($sessionUser['id'] ?? 0) < 1) {

            return [

                'permitido' => false,

                'motivo' => 'login',

                'mensaje' => 'Debe iniciar sesión para acceder a sus reportes personales.',

            ];

        }



        $id = (int) $sessionUser['id'];



        if (! self::usuarioTienePermisoReportes($pdo, $id)) {

            return [

                'permitido' => false,

                'motivo' => 'sin_permiso',

                'mensaje' => 'Los reportes personales en PDF no están habilitados para su usuario.',

            ];

        }



        if (self::REQUIERE_AFILIACION_VIGENTE) {

            $afiliacion = self::evaluarAfiliacionVigente($pdo, $id);

            if (! $afiliacion['permitido']) {

                return $afiliacion;

            }

        }



        return ['permitido' => true, 'motivo' => null, 'mensaje' => ''];

    }



    /**

     * Solo puede descargar PDF de su propio id_usuario.

     *

     * @param array<string, mixed>|null $sessionUser

     * @return array{permitido: bool, motivo: string|null, mensaje: string}

     */

    public static function evaluarDescargaPropio(

        PDO $pdo,

        ?array $sessionUser,

        int $idUsuarioSolicitado

    ): array {

        $base = self::evaluar($pdo, $sessionUser);

        if (! $base['permitido']) {

            return $base;

        }



        $idSesion = (int) ($sessionUser['id'] ?? 0);

        if ($idUsuarioSolicitado < 1 || $idUsuarioSolicitado !== $idSesion) {

            return [

                'permitido' => false,

                'motivo' => 'ajeno',

                'mensaje' => 'Solo puede descargar el PDF de su propia información en el ranking.',

            ];

        }



        return $base;

    }



    /**

     * @param array<string, mixed>|null $sessionUser

     */

    public static function puedeMostrarBotonPdfPersonal(

        PDO $pdo,

        ?array $sessionUser,

        int $idUsuarioPagina

    ): bool {

        return self::evaluarDescargaPropio($pdo, $sessionUser, $idUsuarioPagina)['permitido'];

    }



    /**

     * @return array{permitido: bool, motivo: string|null, mensaje: string}

     */

    private static function evaluarAfiliacionVigente(PDO $pdo, int $id): array

    {

        $st = $pdo->prepare(

            'SELECT id, entidad, numfvd, status, role FROM usuarios WHERE id = ? LIMIT 1'

        );

        $st->execute([$id]);

        $u = $st->fetch(PDO::FETCH_ASSOC);

        if ($u === false) {

            return [

                'permitido' => false,

                'motivo' => 'login',

                'mensaje' => 'No se encontró su usuario. Inicie sesión nuevamente.',

            ];

        }



        if ((int) ($u['entidad'] ?? 0) <= 0) {

            return [

                'permitido' => false,

                'motivo' => 'no_afiliado',

                'mensaje' => 'El PDF personal está disponible solo para atletas afiliados a la FVD.',

            ];

        }



        if ((int) ($u['numfvd'] ?? 0) <= 0) {

            return [

                'permitido' => false,

                'motivo' => 'sin_numfvd',

                'mensaje' => 'Debe contar con carnet FVD (NUMFVD) para descargar el PDF personal.',

            ];

        }



        $status = (int) ($u['status'] ?? -1);

        if ($status === FvdMovimientoTorneoHelper::STATUS_USUARIO_PENDIENTE_ANUALIDAD) {

            return [

                'permitido' => false,

                'motivo' => 'anualidad_pendiente',

                'mensaje' => 'Su anualidad FVD está pendiente. Regularice su suscripción para descargar el PDF.',

            ];

        }



        $aprob = OrganizacionDashboardStats::sqlUsuarioAfiliadoAprobado('u');

        $stOk = $pdo->prepare("SELECT 1 FROM usuarios u WHERE u.id = ? AND {$aprob} LIMIT 1");

        $stOk->execute([$id]);

        if (! $stOk->fetchColumn()) {

            return [

                'permitido' => false,

                'motivo' => 'inactivo',

                'mensaje' => 'Su credencial no está vigente. Debe estar al día con su suscripción FVD.',

            ];

        }



        if (FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {

            $anio = (int) date('Y');

            $stMov = $pdo->prepare(

                'SELECT COALESCE(estatus, 0) AS estatus

                 FROM movimiento_torneo

                 WHERE id_usuario = ? AND anualidad > 0 AND YEAR(created_at) = ?

                 ORDER BY id DESC

                 LIMIT 1'

            );

            $stMov->execute([$id, $anio]);

            $mov = $stMov->fetch(PDO::FETCH_ASSOC);

            if ($mov !== false

                && (int) ($mov['estatus'] ?? 0) !== FinanzasAsociacionData::MOV_ESTATUS_PAGADO

            ) {

                return [

                    'permitido' => false,

                    'motivo' => 'anualidad_pendiente',

                    'mensaje' => 'Tiene anualidad FVD pendiente de liquidación. Regularice su suscripción para descargar el PDF.',

                ];

            }

        }



        return ['permitido' => true, 'motivo' => null, 'mensaje' => ''];

    }

}

