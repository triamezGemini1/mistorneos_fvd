<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use Auth;
use Exception;
use InscritosHelper;
use PDO;
use PDOException;
use Security;
use UserActivationHelper;

/**
 * Inserción y validación de inscripciones (sitio web y API admin).
 */
final class RegistrationHandler
{
    private function __construct()
    {
    }

    /**
     * Inscripción desde flujo sitio (POST). El llamador debe haber verificado permisos (p. ej. verificarPermisosTorneo).
     *
     * @param array{
     *   torneo_id: int,
     *   actor_user_id: int,
     *   actor_club_id?: int|null,
     *   post: array
     * } $datos
     * @return array{ok: true, success_message: string, id_inscrito: int}|array{ok: false, error: string}
     */
    public static function registrarJugador(array $datos): array
    {
        require_once __DIR__ . '/../../InscritosHelper.php';

        $torneo_id = (int) ($datos['torneo_id'] ?? 0);
        $user_id = (int) ($datos['actor_user_id'] ?? 0);
        $post = $datos['post'] ?? [];
        $user_club_id = isset($datos['actor_club_id']) ? $datos['actor_club_id'] : null;
        if ($user_club_id !== null) {
            $user_club_id = (int) $user_club_id ?: null;
        }

        $pdo = \DB::pdo();
        $id_usuario = (int) ($post['id_usuario'] ?? 0);
        $cedula = trim((string) ($post['cedula'] ?? ''));
        $id_club = !empty($post['id_club']) ? (int) $post['id_club'] : null;

        $estatus = InscritosHelper::ESTATUS_PENDIENTE_NUM;
        $inscrito_por = $user_id;

        if (empty($id_usuario) && $cedula !== '') {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? AND status = 0');
            $stmt->execute([$cedula]);
            $usuario_encontrado = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario_encontrado) {
                $id_usuario = (int) $usuario_encontrado['id'];
            } else {
                return [
                    'ok' => false,
                    'error' => 'No se encontró un usuario registrado con la cédula ' . htmlspecialchars($cedula) . '. Debe registrar al usuario primero.',
                ];
            }
        }

        if ($id_usuario <= 0) {
            return ['ok' => false, 'error' => 'Debe seleccionar un usuario o proporcionar una cédula válida'];
        }

        $errAlcance = self::aplicarAlcanceOperativo($pdo, $id_club, $id_usuario);
        if ($errAlcance !== null) {
            return $errAlcance;
        }

        $stmt = $pdo->prepare('SELECT nombre, cedula, sexo, email, username, entidad FROM usuarios WHERE id = ?');
        $stmt->execute([$id_usuario]);
        $usuario_datos = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario_datos) {
            return ['ok' => false, 'error' => 'No se encontró el usuario seleccionado'];
        }

        $campos_faltantes = [];
        if (empty(trim((string) ($usuario_datos['nombre'] ?? '')))) {
            $campos_faltantes[] = 'Nombre';
        }
        if (empty(trim((string) ($usuario_datos['cedula'] ?? '')))) {
            $campos_faltantes[] = 'Cédula';
        }
        if (empty($usuario_datos['sexo'] ?? '')) {
            $campos_faltantes[] = 'Sexo';
        }
        if (empty(trim((string) ($usuario_datos['email'] ?? '')))) {
            $campos_faltantes[] = 'Email';
        }
        if (empty(trim((string) ($usuario_datos['username'] ?? '')))) {
            $campos_faltantes[] = 'Username';
        }

        if ($campos_faltantes !== []) {
            $campos_lista = implode(', ', $campos_faltantes);

            return [
                'ok' => false,
                'error' => 'El usuario no puede ser inscrito porque faltan los siguientes campos obligatorios: ' . $campos_lista . '. Por favor complete la información del usuario antes de inscribirlo.',
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND ' . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO
        );
        $stmt->execute([$id_usuario, $torneo_id]);

        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'Este usuario ya está inscrito en el torneo'];
        }

        require_once __DIR__ . '/../../AsociacionAdminHelper.php';
        $id_club = \AsociacionAdminHelper::resolverIdClubInscripcion(
            $pdo,
            $id_usuario,
            $inscrito_por,
            null,
            $id_club,
            $user_club_id
        );

        if ($id_usuario <= 0) {
            return ['ok' => false, 'error' => 'ID de usuario inválido'];
        }
        if ($torneo_id <= 0) {
            return ['ok' => false, 'error' => 'ID de torneo inválido'];
        }

        try {
            $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $id_usuario,
                'torneo_id' => $torneo_id,
                'id_club' => $id_club,
                'estatus' => $estatus,
                'inscrito_por' => $inscrito_por,
                'numero' => 0,
            ]);
            self::notificarInscripcion($pdo, $id_usuario, $torneo_id, $id_club, (int) $id_inscrito);

            return ['ok' => true, 'success_message' => 'Jugador inscrito exitosamente', 'id_inscrito' => (int) $id_inscrito];
        } catch (PDOException $e) {
            error_log('Error PDO al inscribir jugador: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Error al guardar la inscripción: ' . $e->getMessage()];
        } catch (Exception $e) {
            error_log('Error al inscribir jugador: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Error al inscribir: ' . $e->getMessage()];
        }
    }

    /**
     * API: registrar usuario nuevo e inscribir (transacción).
     *
     * @return array{success: true, message: string, id: int, id_usuario: int}|array{success: false, error: string, sql_error?: string}
     */
    public static function apiRegistrarEInscribir(PDO $pdo, int $torneoId, array $post, int $inscritoPorUserId): array
    {
        require_once __DIR__ . '/../../InscritosHelper.php';
        require_once __DIR__ . '/../../UserActivationHelper.php';
        require_once __DIR__ . '/../../security.php';

        $estatus = InscritosHelper::ESTATUS_PENDIENTE_NUM;
        $nacionalidad = strtoupper(trim((string) ($post['nacionalidad'] ?? 'V')));
        if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
            $nacionalidad = 'V';
        }
        $cedula = preg_replace('/\D/', '', trim((string) ($post['cedula'] ?? '')));
        $nombre = trim((string) ($post['nombre'] ?? ''));
        $fechnac = trim((string) ($post['fechnac'] ?? ''));
        $sexo = strtoupper(trim((string) ($post['sexo'] ?? 'M')));
        if (!in_array($sexo, ['M', 'F', 'O'], true)) {
            $sexo = 'M';
        }
        $telefono = trim((string) ($post['telefono'] ?? $post['celular'] ?? ''));
        $email = trim((string) ($post['email'] ?? ''));
        $id_club = !empty($post['id_club']) ? (int) $post['id_club'] : null;
        $current_user = Auth::user();
        $user_club_id = $current_user['club_id'] ?? null;
        if ($id_club === null || $id_club <= 0) {
            $id_club = $user_club_id;
        }
        $errAlcance = self::aplicarAlcanceOperativo($pdo, $id_club, 0);
        if ($errAlcance !== null) {
            return ['success' => false, 'error' => $errAlcance['error'] ?? 'Sin permiso'];
        }

        if (strlen($cedula) < 4) {
            return ['success' => false, 'error' => 'Cédula inválida'];
        }
        if (strlen($nombre) < 2) {
            return ['success' => false, 'error' => 'Nombre requerido'];
        }

        $email_placeholder = 'user' . $cedula . '@inscrito.local';
        if ($email === '') {
            $email = $email_placeholder;
        }

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
        $stmt->execute([$cedula]);
        if ($stmt->fetch()) {
            return [
                'success' => false,
                'error' => 'Ya existe un usuario con esta cédula. Use la pestaña "Buscar por cédula" para inscribirlo.',
            ];
        }

        if ($email !== $email_placeholder && $email !== '') {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return [
                    'success' => false,
                    'error' => 'El correo electrónico ya está registrado por otro usuario. Use otro correo o déjelo en blanco.',
                ];
            }
        }

        $username = $nacionalidad . $cedula;
        $sufijo = '';
        $idx = 0;
        while (true) {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = ?');
            $stmt->execute([$username . $sufijo]);
            if (!$stmt->fetch()) {
                break;
            }
            ++$idx;
            $sufijo = '_' . $idx;
        }
        $username = $username . $sufijo;
        $password = strlen($cedula) >= 6 ? $cedula : str_pad($cedula, 6, '0', STR_PAD_LEFT);

        $createData = [
            'username' => $username,
            'password' => $password,
            'role' => 'usuario',
            'nombre' => $nombre,
            'cedula' => $cedula,
            'nacionalidad' => $nacionalidad,
            'sexo' => $sexo,
            'fechnac' => $fechnac !== '' ? $fechnac : null,
            'email' => $email,
            'celular' => $telefono,
            'club_id' => $id_club,
            '_allow_club_for_usuario' => true,
        ];
        require_once __DIR__ . '/../../AsociacionAdminHelper.php';
        $ctxOp = \AsociacionAdminHelper::contextoInscripcionOperativa($pdo);
        if ($ctxOp !== null && ($ctxOp['entidad_id'] ?? 0) > 0) {
            $createData['entidad'] = (int) $ctxOp['entidad_id'];
        }

        $pdo->beginTransaction();
        try {
            $create = Security::createUser($createData);
            if (!empty($create['errors'])) {
                $pdo->rollBack();

                return ['success' => false, 'error' => implode(', ', $create['errors'])];
            }
            $id_usuario = (int) ($create['user_id'] ?? 0);
            if ($id_usuario <= 0) {
                $pdo->rollBack();

                return ['success' => false, 'error' => 'No se pudo crear el usuario'];
            }
            $stmt = $pdo->prepare('SELECT modalidad FROM tournaments WHERE id = ?');
            $stmt->execute([$torneoId]);
            $modalidad = (int) ($stmt->fetchColumn() ?? 0);
            $codigo_equipo = InscritosHelper::codigoEquipoParaInscripcionSitioIndividual($pdo, $torneoId, $id_club, $modalidad);
            $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $id_usuario,
                'torneo_id' => $torneoId,
                'id_club' => $id_club,
                'estatus' => $estatus,
                'inscrito_por' => $inscritoPorUserId,
                'numero' => 0,
                'nacionalidad' => $nacionalidad,
                'cedula' => $cedula,
                'codigo_equipo' => $codigo_equipo,
            ]);
            UserActivationHelper::activateUser($pdo, $id_usuario);
            $pdo->commit();
            self::notificarInscripcion($pdo, $id_usuario, $torneoId, $id_club, (int) $id_inscrito);

            return [
                'success' => true,
                'message' => 'Usuario registrado e inscrito correctamente',
                'id' => (int) $id_inscrito,
                'id_usuario' => $id_usuario,
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('registrar_inscribir: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'sql_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * API: inscribir usuario ya existente (insert o re-activar fila).
     *
     * @return array{success: true, message: string, id: int}|array{success: false, error: string, sql_error?: string}
     */
    public static function apiInscribirUsuarioExistente(
        PDO $pdo,
        int $torneoId,
        int $idUsuario,
        ?int $idClubPost,
        int $estatus,
        int $inscritoPorUserId,
        ?int $userClubIdActor
    ): array {
        require_once __DIR__ . '/../../InscritosHelper.php';
        require_once __DIR__ . '/../../UserActivationHelper.php';

        $errAlcance = self::aplicarAlcanceOperativo($pdo, $idClubPost, $idUsuario);
        if ($errAlcance !== null) {
            return ['success' => false, 'error' => $errAlcance['error'] ?? 'Sin permiso'];
        }

        $stmt = $pdo->prepare('SELECT id, estatus FROM inscritos WHERE id_usuario = ? AND torneo_id = ?');
        $stmt->execute([$idUsuario, $torneoId]);
        $existe = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existe && !InscritosHelper::esRetirado($existe['estatus'])) {
            return ['success' => false, 'error' => 'Este usuario ya está inscrito en el torneo'];
        }
        if ($existe) {
            $idClubRe = $idClubPost;
            $errRe = self::aplicarAlcanceOperativo($pdo, $idClubRe, $idUsuario);
            if ($errRe !== null) {
                return ['success' => false, 'error' => $errRe['error'] ?? 'Sin permiso'];
            }
            $estatusRe = $estatus > 0 ? $estatus : InscritosHelper::ESTATUS_PENDIENTE_NUM;
            $sqlUp = 'UPDATE inscritos SET estatus = ?' . ($idClubRe ? ', id_club = ?' : '') . ' WHERE id = ?';
            $stmt = $pdo->prepare($sqlUp);
            $idClubRe
                ? $stmt->execute([$estatusRe, $idClubRe, $existe['id']])
                : $stmt->execute([$estatusRe, $existe['id']]);
            UserActivationHelper::activateUser($pdo, $idUsuario);
            self::notificarInscripcion($pdo, $idUsuario, $torneoId, $idClubRe, (int) $existe['id']);

            return ['success' => true, 'message' => 'Jugador inscrito exitosamente', 'id' => (int) $existe['id']];
        }

        $stmt = $pdo->prepare('SELECT nombre, cedula, sexo, email, username, entidad, nacionalidad FROM usuarios WHERE id = ?');
        $stmt->execute([$idUsuario]);
        $usuario_datos = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario_datos) {
            return ['success' => false, 'error' => 'No se encontró el usuario seleccionado'];
        }

        $campos_faltantes = [];
        if (empty(trim((string) ($usuario_datos['nombre'] ?? '')))) {
            $campos_faltantes[] = 'Nombre';
        }
        if (empty(trim((string) ($usuario_datos['cedula'] ?? '')))) {
            $campos_faltantes[] = 'Cédula';
        }
        if (empty($usuario_datos['sexo'] ?? '')) {
            $campos_faltantes[] = 'Sexo';
        }
        if (empty(trim((string) ($usuario_datos['email'] ?? '')))) {
            $campos_faltantes[] = 'Email';
        }
        if (empty(trim((string) ($usuario_datos['username'] ?? '')))) {
            $campos_faltantes[] = 'Username';
        }

        if ($campos_faltantes !== []) {
            $campos_lista = implode(', ', $campos_faltantes);

            return [
                'success' => false,
                'error' => 'El usuario no puede ser inscrito porque faltan los siguientes campos obligatorios: ' . $campos_lista . '. Por favor complete la información del usuario antes de inscribirlo.',
            ];
        }

        require_once __DIR__ . '/../../AsociacionAdminHelper.php';
        $id_club = \AsociacionAdminHelper::resolverIdClubInscripcion(
            $pdo,
            $idUsuario,
            $inscritoPorUserId,
            null,
            $idClubPost,
            $userClubIdActor
        );

        $nac_inscrito = isset($usuario_datos['nacionalidad']) && in_array(strtoupper(trim((string) $usuario_datos['nacionalidad'])), ['V', 'E', 'J', 'P'], true)
            ? strtoupper(trim((string) $usuario_datos['nacionalidad'])) : 'V';
        $ced_inscrito = isset($usuario_datos['cedula']) ? preg_replace('/\D/', '', (string) $usuario_datos['cedula']) : '';
        $stmt = $pdo->prepare('SELECT modalidad FROM tournaments WHERE id = ?');
        $stmt->execute([$torneoId]);
        $modalidad = (int) ($stmt->fetchColumn() ?? 0);
        $codigo_equipo = InscritosHelper::codigoEquipoParaInscripcionSitioIndividual($pdo, $torneoId, $id_club, $modalidad);

        try {
            $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $idUsuario,
                'torneo_id' => $torneoId,
                'id_club' => $id_club,
                'estatus' => $estatus,
                'inscrito_por' => $inscritoPorUserId,
                'numero' => 0,
                'nacionalidad' => $nac_inscrito,
                'cedula' => $ced_inscrito,
                'codigo_equipo' => $codigo_equipo,
            ]);
            UserActivationHelper::activateUser($pdo, $idUsuario);
            self::notificarInscripcion($pdo, $idUsuario, $torneoId, $id_club, (int) $id_inscrito);

            return ['success' => true, 'message' => 'Jugador inscrito exitosamente', 'id' => (int) $id_inscrito];
        } catch (Exception $e) {
            error_log('Error al inscribir jugador (API): ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage(), 'sql_error' => $e->getMessage()];
        }
    }

    /**
     * Fuerza club de la asociación y valida ámbito del atleta (admin operativo).
     *
     * @return array{ok: false, error: string}|null
     */
    private static function aplicarAlcanceOperativo(PDO $pdo, ?int &$idClub, int $idUsuario = 0): ?array
    {
        require_once __DIR__ . '/../../AsociacionAdminHelper.php';
        $forzado = \AsociacionAdminHelper::idClubForzadoInscripcion($pdo);
        if ($forzado === null) {
            return null;
        }
        if ($idUsuario > 0 && !\AsociacionAdminHelper::usuarioEnAmbitoAsociacion($pdo, $idUsuario)) {
            return ['ok' => false, 'error' => 'El atleta no pertenece a su asociación.'];
        }
        $idClub = $forzado;

        return null;
    }

    private static function notificarInscripcion(PDO $pdo, int $idUsuario, int $torneoId, ?int $idClub, int $idInscrito): void
    {
        $notifierPath = __DIR__ . '/../../InscripcionTorneoNotifier.php';
        if (!is_readable($notifierPath)) {
            return;
        }

        try {
            require_once $notifierPath;
        } catch (\Throwable $e) {
            error_log('notificarInscripcion require: ' . $e->getMessage());

            return;
        }

        if (!class_exists(\InscripcionTorneoNotifier::class, false)) {
            return;
        }

        try {
            \InscripcionTorneoNotifier::notificarTrasInscripcion(
                $pdo,
                $idUsuario,
                $torneoId,
                (int) ($idClub ?? 0),
                $idInscrito
            );
        } catch (\Throwable $e) {
            error_log('notificarInscripcion: ' . $e->getMessage());
        }
    }
}
