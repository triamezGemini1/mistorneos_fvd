<?php

declare(strict_types=1);

require_once __DIR__ . '/FinanzasAsociacionData.php';
require_once __DIR__ . '/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/FvdDelegadoMovimientoService.php';

/**
 * Alta y actualización de atletas (equivalente a admin_fvd AfiliacionAtleta).
 */
final class FvdAfiliacionAtletaService
{
    /**
     * @return array<string, mixed>|null
     */
    public static function buscarPorCedula(PDO $pdo, string $cedula): ?array
    {
        $cedula = FvdMovimientoTorneoHelper::normalizarCedula($cedula);
        if ($cedula === '') {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT id, numfvd, cedula, sexo, nombre, fechnac, email, celular, username, role, status, club_id, entidad, photo_path
             FROM usuarios WHERE cedula = ? LIMIT 1'
        );
        $st->execute([$cedula]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array{allowed: bool, user?: array<string, mixed>|null, message?: string}
     */
    public static function verificarAccesoConsultaCedula(PDO $pdo, string $cedula, ?array $clubOperativo, bool $esAdminGeneral): array
    {
        $row = self::buscarPorCedula($pdo, $cedula);
        if ($row === null) {
            return ['allowed' => true, 'user' => null];
        }
        if ($esAdminGeneral) {
            return ['allowed' => true, 'user' => $row];
        }
        $clubId = (int) ($clubOperativo['id'] ?? 0);
        if ($clubId < 1) {
            return ['allowed' => false, 'user' => null, 'message' => 'Sin asociación asignada.'];
        }
        $uClub = (int) ($row['club_id'] ?? 0);
        if ($uClub > 0 && $uClub !== $clubId) {
            return ['allowed' => false, 'user' => null, 'message' => 'La cédula pertenece a un afiliado de otra asociación.'];
        }

        return ['allowed' => true, 'user' => $row];
    }

    /**
     * @param array<string, mixed> $post
     * @param array{foto: ?string, cedula_img: ?string} $rutas
     * @return array{user_id: int, movimiento_tridente: bool, torneo_id: int, movimiento_id: int}
     */
    public static function guardar(
        PDO $pdo,
        array $post,
        array $rutas,
        ?array $clubOperativo,
        bool $esAdminGeneral
    ): array {
        $cedula = FvdMovimientoTorneoHelper::normalizarCedula((string) ($post['cedula'] ?? ''));
        if ($cedula === '') {
            throw new InvalidArgumentException('La cédula es obligatoria.');
        }
        $userId = (int) ($post['user_id'] ?? 0);
        $isUpdate = $userId > 0;
        $nombre = trim((string) ($post['nombre'] ?? ''));
        $email = trim((string) ($post['email'] ?? ''));
        $fechnac = trim((string) ($post['fechnac'] ?? ''));
        $fechnac = $fechnac === '' ? null : $fechnac;
        $sexo = (int) ($post['sexo'] ?? 0);
        $celular = trim((string) ($post['celular'] ?? ''));
        $celular = $celular === '' ? null : $celular;
        $numfvdPost = $esAdminGeneral ? (int) ($post['numfvd'] ?? 0) : 0;
        if ($nombre === '' || $email === '') {
            throw new InvalidArgumentException('Nombre y email son obligatorios.');
        }

        $imgCols = FvdMovimientoTorneoHelper::columnasUsuarioImagen($pdo);
        $torneoId = self::resolverTorneoId($pdo, $post);
        $pdo->beginTransaction();
        try {
            $movimientoCreado = false;
            $clubMov = 0;
            if ($isUpdate) {
                $row = self::obtenerParaEdicion($pdo, $userId, $clubOperativo, $esAdminGeneral);
                if ($row === null) {
                    throw new InvalidArgumentException('Usuario no encontrado o sin permiso.');
                }
                if (FvdMovimientoTorneoHelper::normalizarCedula((string) $row['cedula']) !== $cedula) {
                    throw new InvalidArgumentException('No puede cambiar la cédula del registro.');
                }
                $numfvdFinal = $esAdminGeneral ? $numfvdPost : (int) ($row['numfvd'] ?? 0);
                $numfvdAntes = (int) ($row['numfvd'] ?? 0);
                self::actualizarUsuario($pdo, $userId, $nombre, $fechnac, $sexo, $email, $celular, $numfvdFinal, $rutas, $imgCols);
                $uid = $userId;
                $clubMov = (int) ($row['club_id'] ?? 0);
            } else {
                if (self::buscarPorCedula($pdo, $cedula) !== null) {
                    throw new InvalidArgumentException('Ya existe un usuario con esta cédula. Consulte la cédula y use actualizar.');
                }
                $clubId = $esAdminGeneral
                    ? max(0, (int) ($post['club_id'] ?? 0))
                    : (int) ($clubOperativo['id'] ?? 0);
                if (!$esAdminGeneral && $clubId < 1) {
                    throw new InvalidArgumentException('Asociación no asignada.');
                }
                $uid = self::insertarUsuario($pdo, $cedula, $nombre, $fechnac, $sexo, $email, $celular, $esAdminGeneral ? $numfvdPost : 0, $clubId, $rutas, $imgCols);
                $numfvdAntes = 0;
                $numfvdFinal = $esAdminGeneral ? $numfvdPost : 0;
                $clubMov = $clubId;
            }

            $clubMov = self::resolverClubIdMovimiento($clubMov, $clubOperativo, $post, $esAdminGeneral);
            self::asegurarClubEnUsuario($pdo, $uid, $clubMov);

            $movimientoId = 0;
            if (!FvdMovimientoTorneoHelper::tablaDisponible($pdo)) {
                throw new RuntimeException('La tabla movimiento_torneo no está disponible. Ejecute la migración SQL del módulo FVD.');
            }
            if ($torneoId < 1) {
                throw new InvalidArgumentException(
                    'No hay torneo activo. Abra el panel de asociación con un torneo seleccionado e intente de nuevo.'
                );
            }
            if ($clubMov < 1) {
                throw new InvalidArgumentException(
                    'No se pudo determinar la asociación del atleta. Verifique que su usuario tenga club asignado.'
                );
            }

            $aprobadoInmediato = $esAdminGeneral && $numfvdFinal > 0;
            $movimientoId = FvdDelegadoMovimientoService::registrarSolicitudAfiliacion(
                $pdo,
                $uid,
                $torneoId,
                $clubMov,
                $aprobadoInmediato
            );
            if ($movimientoId < 1) {
                throw new RuntimeException('No se pudo registrar el movimiento de afiliación en movimiento_torneo.');
            }
            $movimientoCreado = true;

            $pdo->commit();

            return [
                'user_id' => $uid,
                'movimiento_tridente' => $movimientoCreado,
                'torneo_id' => $torneoId,
                'movimiento_id' => $movimientoId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function resolverTorneoId(PDO $pdo, array $post): int
    {
        $tid = (int) ($post['torneo_id'] ?? 0);
        if ($tid < 1) {
            $tid = (int) (FvdMovimientoTorneoHelper::torneoActivoId($pdo) ?? 0);
        }
        if ($tid < 1 && FinanzasAsociacionData::tablaExiste($pdo, 'tournaments')) {
            $hasFechator = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'fechator'")->fetch(PDO::FETCH_ASSOC);
            $order = $hasFechator ? 'fechator DESC, id DESC' : 'id DESC';
            $tid = (int) ($pdo->query("SELECT id FROM tournaments ORDER BY {$order} LIMIT 1")->fetchColumn() ?: 0);
        }

        return $tid;
    }

    private static function resolverClubIdMovimiento(
        int $clubDesdeUsuario,
        ?array $clubOperativo,
        array $post,
        bool $esAdminGeneral
    ): int {
        if ($clubOperativo !== null) {
            $op = (int) ($clubOperativo['id'] ?? 0);
            if ($op > 0) {
                return $op;
            }
        }
        if ($clubDesdeUsuario > 0) {
            return $clubDesdeUsuario;
        }
        if ($esAdminGeneral) {
            return max(0, (int) ($post['club_id'] ?? 0));
        }

        return 0;
    }

    private static function asegurarClubEnUsuario(PDO $pdo, int $userId, int $clubId): void
    {
        if ($userId < 1 || $clubId < 1) {
            return;
        }
        $pdo->prepare(
            'UPDATE usuarios SET club_id = ? WHERE id = ? AND (club_id IS NULL OR club_id = 0)'
        )->execute([$clubId, $userId]);
    }

    /**
     * @param array{foto: ?string, cedula_img: ?string} $rutas
     * @param array<string, bool> $imgCols
     */
    private static function actualizarUsuario(
        PDO $pdo,
        int $userId,
        string $nombre,
        ?string $fechnac,
        int $sexo,
        string $email,
        ?string $celular,
        int $numfvd,
        array $rutas,
        array $imgCols
    ): void {
        $sets = ['nombre = ?', 'fechnac = ?', 'sexo = ?', 'email = ?', 'celular = ?', 'numfvd = ?'];
        $params = [$nombre, $fechnac, $sexo, $email, $celular, $numfvd];
        if ($rutas['foto'] !== null) {
            if ($imgCols['urlimgfoto']) {
                $sets[] = 'urlimgfoto = ?';
                $params[] = $rutas['foto'];
            } elseif ($imgCols['photo_path']) {
                $sets[] = 'photo_path = ?';
                $params[] = $rutas['foto'];
            }
        }
        if ($rutas['cedula_img'] !== null) {
            if ($imgCols['urlimgcedula']) {
                $sets[] = 'urlimgcedula = ?';
                $params[] = $rutas['cedula_img'];
            } elseif ($imgCols['foto_cedula']) {
                $sets[] = 'foto_cedula = ?';
                $params[] = $rutas['cedula_img'];
            }
        }
        $params[] = $userId;
        $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1')->execute($params);
    }

    /**
     * @param array{foto: ?string, cedula_img: ?string} $rutas
     * @param array<string, bool> $imgCols
     */
    private static function insertarUsuario(
        PDO $pdo,
        string $cedula,
        string $nombre,
        ?string $fechnac,
        int $sexo,
        string $email,
        ?string $celular,
        int $numfvd,
        int $clubId,
        array $rutas,
        array $imgCols
    ): int {
        $plain = bin2hex(random_bytes(12));
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('No se pudo generar contraseña.');
        }
        $username = self::usernameUnico($pdo, $cedula);
        $cols = ['cedula', 'nombre', 'fechnac', 'sexo', 'email', 'celular', 'username', 'password_hash', 'role', 'status', 'club_id', 'numfvd'];
        $vals = [$cedula, $nombre, $fechnac, $sexo, $email, $celular, $username, $hash, 'usuario', FvdMovimientoTorneoHelper::STATUS_USUARIO_PENDIENTE_ANUALIDAD, $clubId > 0 ? $clubId : null, $numfvd];
        if ($rutas['foto'] !== null) {
            if ($imgCols['urlimgfoto']) {
                $cols[] = 'urlimgfoto';
                $vals[] = $rutas['foto'];
            } elseif ($imgCols['photo_path']) {
                $cols[] = 'photo_path';
                $vals[] = $rutas['foto'];
            }
        }
        if ($rutas['cedula_img'] !== null) {
            if ($imgCols['urlimgcedula']) {
                $cols[] = 'urlimgcedula';
                $vals[] = $rutas['cedula_img'];
            } elseif ($imgCols['foto_cedula']) {
                $cols[] = 'foto_cedula';
                $vals[] = $rutas['cedula_img'];
            }
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO usuarios (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);

        return (int) $pdo->lastInsertId();
    }

    private static function usernameUnico(PDO $pdo, string $cedula): string
    {
        $base = 'afiliado_' . preg_replace('/\D/', '', $cedula);
        if ($base === 'afiliado_') {
            $base = 'afiliado_' . substr(sha1($cedula), 0, 12);
        }
        $u = $base;
        for ($n = 0; $n < 50; $n++) {
            $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE username = ? LIMIT 1');
            $st->execute([$u]);
            if (!$st->fetchColumn()) {
                return $u;
            }
            $u = $base . '_' . ($n + 1);
        }

        return $base . '_' . bin2hex(random_bytes(4));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerParaEdicion(PDO $pdo, int $id, ?array $clubOperativo, bool $esAdminGeneral): ?array
    {
        $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!$esAdminGeneral) {
            $clubId = (int) ($clubOperativo['id'] ?? 0);
            $uClub = (int) ($row['club_id'] ?? 0);
            if ($clubId > 0 && $uClub > 0 && $uClub !== $clubId) {
                return null;
            }
        }

        return $row;
    }
}
