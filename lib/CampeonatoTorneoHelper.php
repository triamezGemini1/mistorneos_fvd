<?php
/**
 * Campeonatos: variantes simultáneas (género / categoría SUB) y elegibilidad de inscripción.
 */
declare(strict_types=1);

final class CampeonatoTorneoHelper
{
    public const TIPO_GENERO = 'genero';
    public const TIPO_CATEGORIA_SUB = 'categoria_sub';

    /** @return list<array{suffix: string, genero_requerido: ?string, edad_maxima: ?int, rondas: ?int, campeonato_grupo: string}>> */
    public static function variantes(int $clase, string $campeonatoTipo, int $modalidad, int $rondasBase): ?array
    {
        if ($clase !== 2 || $campeonatoTipo === '') {
            return null;
        }
        if ($campeonatoTipo === self::TIPO_GENERO) {
            return [
                [
                    'suffix' => 'EQUIPOS MASCULINO',
                    'genero_requerido' => 'M',
                    'edad_maxima' => null,
                    'rondas' => null,
                    'campeonato_grupo' => 'MASCULINO',
                ],
                [
                    'suffix' => 'EQUIPOS FEMENINO',
                    'genero_requerido' => 'F',
                    'edad_maxima' => null,
                    'rondas' => null,
                    'campeonato_grupo' => 'FEMENINO',
                ],
            ];
        }
        if ($campeonatoTipo === self::TIPO_CATEGORIA_SUB) {
            return [
                [
                    'suffix' => 'SUB 12',
                    'genero_requerido' => null,
                    'edad_maxima' => 12,
                    'rondas' => 5,
                    'campeonato_grupo' => 'SUB 12',
                ],
                [
                    'suffix' => 'SUB 15',
                    'genero_requerido' => null,
                    'edad_maxima' => 15,
                    'rondas' => null,
                    'campeonato_grupo' => 'SUB 15',
                ],
                [
                    'suffix' => 'SUB 18',
                    'genero_requerido' => null,
                    'edad_maxima' => 18,
                    'rondas' => null,
                    'campeonato_grupo' => 'SUB 18',
                ],
            ];
        }

        return null;
    }

    public static function nombreConSufijo(string $nombreBase, string $suffix): string
    {
        $base = trim($nombreBase);
        $suf = trim($suffix);

        return $suf === '' ? $base : $base . ' - ' . $suf;
    }

    public static function calcularEdad(?string $fechnac, ?string $fechaReferencia): ?int
    {
        if ($fechnac === null || $fechnac === '' || $fechaReferencia === null || $fechaReferencia === '') {
            return null;
        }
        try {
            $nac = new DateTime(substr($fechnac, 0, 10));
            $ref = new DateTime(substr($fechaReferencia, 0, 10));

            return (int) $nac->diff($ref)->y;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $torneo
     * @param array<string, mixed> $usuario
     */
    public static function validarElegibilidadInscripcion(array $torneo, array $usuario): ?string
    {
        $generoReq = strtoupper(trim((string) ($torneo['genero_requerido'] ?? '')));
        if ($generoReq === 'M' || $generoReq === 'F') {
            $sexo = strtoupper(trim((string) ($usuario['sexo'] ?? '')));
            if ($sexo === '') {
                return 'El jugador debe tener sexo registrado para inscribirse en este torneo.';
            }
            if ($sexo !== $generoReq) {
                $etiq = $generoReq === 'M' ? 'masculino' : 'femenino';

                return 'Este torneo solo admite participantes de sexo ' . $etiq . '.';
            }
        }

        $edadMax = (int) ($torneo['edad_maxima'] ?? 0);
        if ($edadMax > 0) {
            $fechnac = $usuario['fechnac'] ?? null;
            $fechator = $torneo['fechator'] ?? date('Y-m-d');
            $edad = self::calcularEdad(is_string($fechnac) ? $fechnac : null, is_string($fechator) ? $fechator : null);
            if ($edad === null) {
                return 'El jugador debe tener fecha de nacimiento registrada para la categoría ' . ($torneo['campeonato_grupo'] ?? 'SUB') . '.';
            }
            if ($edad > $edadMax) {
                $grupo = trim((string) ($torneo['campeonato_grupo'] ?? ('SUB ' . $edadMax)));

                return 'El jugador no cumple la edad máxima de ' . $grupo . ' (edad al día del torneo: ' . $edad . ' años).';
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function cargarTorneo(PDO $pdo, int $torneoId): ?array
    {
        if ($torneoId <= 0) {
            return null;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM tournaments')->fetchAll(PDO::FETCH_COLUMN);
        $sel = 'id, nombre, fechator, modalidad, clase';
        if (in_array('genero_requerido', $cols, true)) {
            $sel .= ', genero_requerido';
        }
        if (in_array('edad_maxima', $cols, true)) {
            $sel .= ', edad_maxima';
        }
        if (in_array('campeonato_grupo', $cols, true)) {
            $sel .= ', campeonato_grupo';
        }
        $st = $pdo->prepare("SELECT {$sel} FROM tournaments WHERE id = ? LIMIT 1");
        $st->execute([$torneoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function cargarUsuario(PDO $pdo, int $idUsuario): ?array
    {
        $st = $pdo->prepare('SELECT id, sexo, fechnac, nombre FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$idUsuario]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function mensajeErrorElegibilidad(PDO $pdo, int $torneoId, int $idUsuario): ?string
    {
        $torneo = self::cargarTorneo($pdo, $torneoId);
        $usuario = self::cargarUsuario($pdo, $idUsuario);
        if (!$torneo || !$usuario) {
            return null;
        }

        return self::validarElegibilidadInscripcion($torneo, $usuario);
    }

    /** Slot de planilla Access: 1 = masculino, 2 = femenino. */
    public static function slotTorneoDesdeCelda(string $raw): int
    {
        $n = (int) preg_replace('/\D/', '', trim($raw));

        return ($n === 1 || $n === 2) ? $n : 0;
    }

    public static function sexoNormalizado(string $sexo): string
    {
        $s = strtoupper(trim($sexo));
        if ($s === '1' || $s === 'H' || $s === 'MASCULINO' || $s === 'HOMBRE') {
            return 'M';
        }
        if ($s === '2' || $s === 'F' || $s === 'FEMENINO' || $s === 'MUJER') {
            return 'F';
        }

        return in_array($s, ['M', 'F', 'O'], true) ? $s : '';
    }

    /**
     * Torneos del mismo evento (parent_event_id).
     *
     * @return list<array<string, mixed>>
     */
    public static function torneosGrupoEvento(PDO $pdo, int $torneoId): array
    {
        if ($torneoId <= 0) {
            return [];
        }
        $cols = $pdo->query('SHOW COLUMNS FROM tournaments')->fetchAll(PDO::FETCH_COLUMN);
        $sel = 'id, nombre, modalidad, clase';
        if (in_array('parent_event_id', $cols, true)) {
            $sel .= ', parent_event_id';
        }
        if (in_array('genero_requerido', $cols, true)) {
            $sel .= ', genero_requerido';
        }
        if (in_array('campeonato_grupo', $cols, true)) {
            $sel .= ', campeonato_grupo';
        }
        $st = $pdo->prepare("SELECT {$sel} FROM tournaments WHERE id = ? LIMIT 1");
        $st->execute([$torneoId]);
        $actual = $st->fetch(PDO::FETCH_ASSOC);
        if (!$actual) {
            return [];
        }
        if (!in_array('parent_event_id', $cols, true)) {
            return [$actual];
        }
        $parentCol = (int) ($actual['parent_event_id'] ?? 0);
        $rootId = $parentCol > 0 ? $parentCol : $torneoId;
        $stG = $pdo->prepare("SELECT {$sel} FROM tournaments WHERE id = ? OR parent_event_id = ? ORDER BY id ASC");
        $stG->execute([$rootId, $rootId]);
        $rows = $stG->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [$actual];
    }

    /**
     * Campeonato por género: columna «torneo» 1 = hombres (M), 2 = mujeres (F).
     *
     * @return array{
     *   activo: int,
     *   slots: array<int, array{id: int, genero: string, nombre: string, campeonato_grupo: string}>,
     *   torneo_ids: list<int>
     * }|null
     */
    public static function mapaImportacionCampeonatoGenero(PDO $pdo, int $torneoIdPanel): ?array
    {
        $grupo = self::torneosGrupoEvento($pdo, $torneoIdPanel);
        if (count($grupo) < 2) {
            return null;
        }
        $porGenero = ['M' => null, 'F' => null];
        foreach ($grupo as $t) {
            $g = strtoupper(trim((string) ($t['genero_requerido'] ?? '')));
            if ($g !== 'M' && $g !== 'F') {
                continue;
            }
            if ($porGenero[$g] === null) {
                $porGenero[$g] = $t;
            }
        }
        if ($porGenero['M'] === null || $porGenero['F'] === null) {
            return null;
        }

        $slots = [
            1 => [
                'id' => (int) $porGenero['M']['id'],
                'genero' => 'M',
                'nombre' => (string) ($porGenero['M']['nombre'] ?? ''),
                'campeonato_grupo' => (string) ($porGenero['M']['campeonato_grupo'] ?? 'MASCULINO'),
            ],
            2 => [
                'id' => (int) $porGenero['F']['id'],
                'genero' => 'F',
                'nombre' => (string) ($porGenero['F']['nombre'] ?? ''),
                'campeonato_grupo' => (string) ($porGenero['F']['campeonato_grupo'] ?? 'FEMENINO'),
            ],
        ];

        return [
            'activo' => $torneoIdPanel,
            'slots' => $slots,
            'torneo_ids' => [(int) $slots[1]['id'], (int) $slots[2]['id']],
        ];
    }

    /** @param array<int, array{id: int, genero: string}> $slots */
    public static function torneoIdDesdeSlot(array $slots, int $slot): int
    {
        return (int) ($slots[$slot]['id'] ?? 0);
    }
}
