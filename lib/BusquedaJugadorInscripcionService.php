<?php

declare(strict_types=1);

/**
 * Criterios unificados de búsqueda para inscripción (individual y equipos/parejas):
 * 1) usuarios (variantes de cédula + columna normalizada)
 * 2) base externa de personas (PersonaDatabase), misma que search_persona.php
 * 3) no encontrado → registro manual en cliente
 *
 * No incluye aquí búsqueda por ID de usuario ni por nombre (solo inscripción en sitio / search_persona).
 */
final class BusquedaJugadorInscripcionService
{
    public const RESULTADO_USUARIO = 'usuario';

    public const RESULTADO_PERSONA_EXTERNA = 'persona_externa';

    public const RESULTADO_NO_ENCONTRADO = 'no_encontrado';

    public const RESULTADO_YA_EN_EQUIPO = 'ya_en_equipo';

    /** Solo dígitos para comparar cédulas. */
    public static function cedulaSoloDigitos(string $raw): string
    {
        return preg_replace('/\D/', '', trim($raw));
    }

    public static function normalizarNacionalidad(string $n): string
    {
        $n = strtoupper(trim($n));

        return in_array($n, ['V', 'E', 'J', 'P'], true) ? $n : 'V';
    }

    /**
     * Fila de inscritos del jugador en el torneo (si existe).
     *
     * @return array{id: int, codigo_equipo: ?string, estatus: mixed}|null
     */
    public static function inscritoTorneoPorUsuario(PDO $pdo, int $torneoId, int $idUsuario): ?array
    {
        $st = $pdo->prepare('SELECT id, codigo_equipo, estatus FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
        $st->execute([$torneoId, $idUsuario]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? [
            'id' => (int) ($row['id'] ?? 0),
            'codigo_equipo' => isset($row['codigo_equipo']) ? trim((string) $row['codigo_equipo']) : '',
            'estatus' => $row['estatus'] ?? null,
        ] : null;
    }

    /**
     * Busca en usuarios por cédula (mismas variantes que search_persona.php bloque 2 + normalizado).
     *
     * @return array<string, mixed>|null
     */
    public static function buscarUsuarioPorCedula(PDO $pdo, string $nacionalidad, string $cedulaDigitos): ?array
    {
        if ($cedulaDigitos === '') {
            return null;
        }
        $nac = self::normalizarNacionalidad($nacionalidad);
        $variantes = array_unique([
            $cedulaDigitos,
            $nac . $cedulaDigitos,
            'V' . $cedulaDigitos,
            'E' . $cedulaDigitos,
            'J' . $cedulaDigitos,
            'P' . $cedulaDigitos,
        ]);
        foreach ($variantes as $c) {
            if ($c === '') {
                continue;
            }
            $stmt = $pdo->prepare('SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, role, status, entidad FROM usuarios WHERE cedula = ? LIMIT 1');
            $stmt->execute([$c]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        $stmt = $pdo->prepare('
            SELECT id, username, nacionalidad, nombre, cedula, sexo, fechnac, celular, email, club_id, role, status, entidad
            FROM usuarios
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), \'-\', \'\'), \'.\', \'\'), \' \', \'\'), \'/\', \'\') = ?
            LIMIT 1
        ');
        $stmt->execute([$cedulaDigitos]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Persona en BD externa (misma API que search_persona bloque 3).
     *
     * @return array{persona: array<string, mixed>}|null
     */
    public static function buscarPersonaExternaPorCedula(string $nacionalidad, string $cedulaDigitos): ?array
    {
        $path = dirname(__DIR__) . '/config/persona_database.php';
        if (!is_file($path)) {
            return null;
        }
        require_once $path;
        if (!PersonaDatabase::isConfigured()) {
            return null;
        }
        $nac = self::normalizarNacionalidad($nacionalidad);
        try {
            $persona = PersonaDatabase::buscarPorCedula($nac, $cedulaDigitos);
            if ($persona !== null) {
                return ['persona' => $persona];
            }
        } catch (Throwable $e) {
            error_log('BusquedaJugadorInscripcionService externa: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Construye el array "jugador" esperado por inscribir_equipo_sitio (API JSON).
     *
     * @param array<string, mixed> $clubRow clubes join opcional
     */
    public static function mapearJugadorEquipoDesdeUsuario(PDO $pdo, array $u, ?array $inscrito, ?array $clubRow = null): array
    {
        $idUsuario = (int) ($u['id'] ?? 0);
        $clubNombre = $clubRow['nombre'] ?? null;
        if ($clubNombre === null && !empty($u['club_id'])) {
            $st = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
            $st->execute([(int) $u['club_id']]);
            $clubNombre = $st->fetchColumn() ?: null;
        }

        return [
            'id_usuario' => $idUsuario,
            'nombre' => $u['nombre'] ?? '',
            'cedula' => $u['cedula'] ?? '',
            'sexo' => $u['sexo'] ?? '',
            'club_id' => (int) ($u['club_id'] ?? 0),
            'club_nombre' => $clubNombre !== null ? (string) $clubNombre : '',
            'id_inscrito' => $inscrito ? (int) $inscrito['id'] : null,
            'codigo_equipo' => $inscrito && $inscrito['codigo_equipo'] !== '' ? $inscrito['codigo_equipo'] : null,
        ];
    }

    /**
     * Pseudo-jugador desde afiliación externa (sin id_usuario hasta guardar equipo).
     *
     * @param array<string, mixed> $p Persona externa
     */
    public static function mapearJugadorDesdePersonaExterna(array $p, string $cedulaDigitos, string $nacionalidad): array
    {
        $cel = $p['celular'] ?? $p['telefono'] ?? '';

        return [
            'id_usuario' => 0,
            'nombre' => $p['nombre'] ?? '',
            'cedula' => $p['cedula'] ?? $cedulaDigitos,
            'sexo' => $p['sexo'] ?? '',
            'club_id' => 0,
            'club_nombre' => '',
            'id_inscrito' => null,
            'codigo_equipo' => null,
            'fuente' => 'externa',
            'nacionalidad' => $p['nacionalidad'] ?? $nacionalidad,
            'fechnac' => $p['fechnac'] ?? '',
            'telefono' => $cel,
            'email' => $p['email'] ?? '',
        ];
    }

    /**
     * Flujo equipos: usuarios → externa → no encontrado.
     *
     * @return array{success: bool, resultado: string, message?: string, jugador?: array<string, mixed>|null}
     */
    public static function buscarParaInscripcionEquipo(PDO $pdo, int $torneoId, string $nacionalidad, string $cedulaRaw): array
    {
        $dig = self::cedulaSoloDigitos($cedulaRaw);
        if ($dig === '') {
            return ['success' => false, 'resultado' => 'error', 'message' => 'Cédula inválida'];
        }
        $nac = self::normalizarNacionalidad($nacionalidad);

        $u = self::buscarUsuarioPorCedula($pdo, $nac, $dig);
        if ($u !== null) {
            $uid = (int) ($u['id'] ?? 0);
            $ins = self::inscritoTorneoPorUsuario($pdo, $torneoId, $uid);
            $codigo = $ins['codigo_equipo'] ?? '';
            if ($codigo !== '') {
                $clubRow = null;
                if (!empty($u['club_id'])) {
                    $st = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
                    $st->execute([(int) $u['club_id']]);
                    $clubRow = ['nombre' => $st->fetchColumn()];
                }
                $jugador = self::mapearJugadorEquipoDesdeUsuario($pdo, $u, $ins, $clubRow);

                return [
                    'success' => false,
                    'resultado' => self::RESULTADO_YA_EN_EQUIPO,
                    'message' => 'Este jugador ya está asignado (código: ' . $codigo . ')',
                    'jugador' => $jugador,
                ];
            }
            $stClub = null;
            if (!empty($u['club_id'])) {
                $st = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
                $st->execute([(int) $u['club_id']]);
                $stClub = ['nombre' => $st->fetchColumn()];
            }
            $jugador = self::mapearJugadorEquipoDesdeUsuario($pdo, $u, $ins, $stClub);

            return [
                'success' => true,
                'resultado' => self::RESULTADO_USUARIO,
                'jugador' => $jugador,
            ];
        }

        $ext = self::buscarPersonaExternaPorCedula($nac, $dig);
        if ($ext !== null) {
            $p = $ext['persona'];
            $jugador = self::mapearJugadorDesdePersonaExterna($p, $dig, $nac);

            return [
                'success' => true,
                'resultado' => self::RESULTADO_PERSONA_EXTERNA,
                'message' => 'Datos obtenidos de la base de afiliados. Revise antes de guardar.',
                'jugador' => $jugador,
            ];
        }

        return [
            'success' => true,
            'resultado' => self::RESULTADO_NO_ENCONTRADO,
            'message' => 'No consta en la plataforma ni en afiliados. Escriba el nombre manualmente en la fila.',
            'jugador' => null,
        ];
    }
}
