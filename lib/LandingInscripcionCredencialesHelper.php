<?php
/**
 * Credenciales y NUMFVD para inscripción pública desde landing.
 * Usuario por defecto: inicial(nombre) + apellido + numfvd; clave = usuario si no se indica.
 * NUMFVD secuencial 9100+ cuando no hay valor válido (0 o más de 4 dígitos).
 */
declare(strict_types=1);

final class LandingInscripcionCredencialesHelper
{
    public const NUMFVD_SECUENCIA_MIN = 9100;
    public const NUMFVD_SECUENCIA_MAX = 9999;

    public static function numfvdEsValido(int $numfvd): bool
    {
        if ($numfvd <= 0) {
            return false;
        }

        return strlen((string) $numfvd) <= 4 && $numfvd <= self::NUMFVD_SECUENCIA_MAX;
    }

    public static function siguienteNumfvdSecuencia(PDO $pdo): int
    {
        $max = self::NUMFVD_SECUENCIA_MIN - 1;
        foreach (['usuarios', 'inscritos'] as $tabla) {
            try {
                $st = $pdo->query(
                    'SELECT COALESCE(MAX(numfvd), 0) FROM `' . $tabla . '`
                     WHERE numfvd >= ' . self::NUMFVD_SECUENCIA_MIN . '
                       AND numfvd <= ' . self::NUMFVD_SECUENCIA_MAX
                );
                $v = (int) ($st->fetchColumn() ?: 0);
                if ($v > $max) {
                    $max = $v;
                }
            } catch (Throwable $e) {
                error_log('LandingInscripcionCredencialesHelper::siguienteNumfvdSecuencia ' . $tabla . ': ' . $e->getMessage());
            }
        }
        $next = $max + 1;
        if ($next < self::NUMFVD_SECUENCIA_MIN) {
            return self::NUMFVD_SECUENCIA_MIN;
        }
        if ($next > self::NUMFVD_SECUENCIA_MAX) {
            throw new RuntimeException(
                'No hay NUMFVD disponibles en el rango ' . self::NUMFVD_SECUENCIA_MIN . '–' . self::NUMFVD_SECUENCIA_MAX . '. Contacte a la FVD.'
            );
        }

        return $next;
    }

    public static function resolverNumfvd(PDO $pdo, int $numfvdExistente = 0): int
    {
        if (self::numfvdEsValido($numfvdExistente)) {
            return $numfvdExistente;
        }

        return self::siguienteNumfvdSecuencia($pdo);
    }

    /**
     * @return array{nombre:string,apellido:string}
     */
    public static function dividirNombreApellido(string $nombreCompleto, string $apellido = ''): array
    {
        $nombreCompleto = trim(preg_replace('/\s+/', ' ', $nombreCompleto));
        $apellido = trim($apellido);
        if ($apellido !== '') {
            return ['nombre' => $nombreCompleto, 'apellido' => $apellido];
        }
        if ($nombreCompleto === '') {
            return ['nombre' => '', 'apellido' => ''];
        }
        $pos = strpos($nombreCompleto, ' ');
        if ($pos === false) {
            return ['nombre' => $nombreCompleto, 'apellido' => ''];
        }

        return [
            'nombre' => trim(substr($nombreCompleto, 0, $pos)),
            'apellido' => trim(substr($nombreCompleto, $pos + 1)),
        ];
    }

    public static function slugAscii(string $texto): string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
            if ($t !== false) {
                $texto = $t;
            }
        }
        $texto = strtolower($texto);

        return preg_replace('/[^a-z0-9]+/', '', $texto) ?? '';
    }

    public static function generarUsernameBase(string $nombre, string $apellido, int $numfvd): string
    {
        $partes = self::dividirNombreApellido($nombre, $apellido);
        $iniNombre = $partes['nombre'] !== '' ? $partes['nombre'] : 'u';
        $ini = self::slugAscii(
            function_exists('mb_substr') ? mb_substr($iniNombre, 0, 1, 'UTF-8') : substr($iniNombre, 0, 1)
        );
        $ape = self::slugAscii($partes['apellido']);
        if ($ini === '') {
            $ini = 'u';
        }
        if ($ape === '') {
            $ape = self::slugAscii($partes['nombre']);
        }
        $base = $ini . $ape . (string) $numfvd;
        if (strlen($base) < 3) {
            $base = 'usr' . (string) $numfvd;
        }

        return substr($base, 0, 48);
    }

    public static function usernameUnico(PDO $pdo, string $base): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_\.]/', '', $base) ?? '';
        if ($base === '') {
            $base = 'usr' . time();
        }
        $candidato = substr($base, 0, 50);
        $n = 0;
        while ($n < 200) {
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
            $st->execute([$candidato]);
            if (!$st->fetch()) {
                return $candidato;
            }
            $n++;
            $candidato = substr($base, 0, 44) . (string) $n;
        }

        return substr($base, 0, 40) . '_' . random_int(100, 999);
    }

    /**
     * @return array{
     *   username: string,
     *   password: string,
     *   numfvd: int,
     *   credenciales_automaticas: bool,
     *   password_igual_usuario: bool
     * }
     */
    public static function resolverParaRegistroNuevo(
        PDO $pdo,
        string $nombre,
        string $apellido = '',
        string $usernamePost = '',
        string $passwordPost = ''
    ): array {
        $numfvd = self::siguienteNumfvdSecuencia($pdo);
        $partes = self::dividirNombreApellido($nombre, $apellido);

        $username = trim($usernamePost);
        $password = trim($passwordPost);
        $auto = false;
        $passwordIgualUsuario = false;

        if ($username === '') {
            $username = self::usernameUnico(
                $pdo,
                self::generarUsernameBase($partes['nombre'], $partes['apellido'], $numfvd)
            );
            $auto = true;
        }

        if ($password === '') {
            $password = $username;
            $auto = true;
            $passwordIgualUsuario = true;
        }

        if (strlen($password) < 6) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.');
        }

        return [
            'username' => $username,
            'password' => $password,
            'numfvd' => $numfvd,
            'credenciales_automaticas' => $auto,
            'password_igual_usuario' => $passwordIgualUsuario,
        ];
    }

    public static function persistirNumfvdUsuario(PDO $pdo, int $userId, int $numfvd): void
    {
        if ($userId <= 0 || $numfvd <= 0) {
            return;
        }
        try {
            $pdo->prepare('UPDATE usuarios SET numfvd = ? WHERE id = ?')->execute([$numfvd, $userId]);
        } catch (Throwable $e) {
            error_log('LandingInscripcionCredencialesHelper::persistirNumfvdUsuario: ' . $e->getMessage());
        }
    }
}
