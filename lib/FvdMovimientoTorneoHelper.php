<?php

declare(strict_types=1);

require_once __DIR__ . '/FinanzasAsociacionData.php';

/**
 * Utilidades movimiento_torneo (modelo admin_fvd adaptado a mistorneos_fvd).
 */
final class FvdMovimientoTorneoHelper
{
    public const STATUS_USUARIO_PENDIENTE_ANUALIDAD = 9;

    /** Prefijo en grupo_nombre para club destino en traspasos. */
    public const DESTINO_GRUPO_PREFIX = '__DEST_CLUB=';

    public const SQL_AFILI_PEND = 'm.afiliacion = 1 AND COALESCE(m.numfvd, 0) < 1';

    public const SQL_AFILI_NO_BLOQUEA = '(m.afiliacion <> 1 OR COALESCE(m.numfvd, 0) > 0)';

    public static function clubColumn(PDO $pdo): string
    {
        return FinanzasAsociacionData::movimientoClubColumn($pdo) ?? 'id_club';
    }

    public static function tablaDisponible(PDO $pdo): bool
    {
        return FinanzasAsociacionData::tablaExiste($pdo, 'movimiento_torneo');
    }

    public static function torneoActivoId(PDO $pdo): ?int
    {
        if (!FinanzasAsociacionData::tablaExiste($pdo, 'tournaments')) {
            return null;
        }
        $hasFin = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'finalizado'")->fetch(PDO::FETCH_ASSOC);
        $where = $hasFin ? '(COALESCE(finalizado, 0) = 0)' : '1=1';
        $st = $pdo->query(
            "SELECT id FROM tournaments WHERE {$where} ORDER BY fechator DESC, id DESC LIMIT 1"
        );
        if ($st === false) {
            return null;
        }
        $id = (int) $st->fetchColumn();

        return $id > 0 ? $id : null;
    }

    public static function assertTorneoEditable(PDO $pdo, int $torneoId): void
    {
        if ($torneoId < 1) {
            throw new InvalidArgumentException('Torneo no indicado.');
        }
        $hasFin = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'finalizado'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasFin) {
            return;
        }
        $st = $pdo->prepare('SELECT COALESCE(finalizado, 0) FROM tournaments WHERE id = ? LIMIT 1');
        $st->execute([$torneoId]);
        if ((int) ($st->fetchColumn() ?: 0) === 1) {
            throw new InvalidArgumentException('El torneo está finalizado; no se pueden registrar movimientos.');
        }
    }

    public static function normalizarCedula(string $c): string
    {
        return preg_replace('/\s+/', '', trim($c)) ?? '';
    }

    public static function empaquetarGrupoConDestino(int $clubDestinoId, string $nota = ''): ?string
    {
        if ($clubDestinoId < 1) {
            return $nota !== '' ? $nota : null;
        }
        $base = self::DESTINO_GRUPO_PREFIX . $clubDestinoId;
        $nota = trim($nota);

        return $nota !== '' ? $base . '|' . $nota : $base;
    }

    public static function parsearDestinoClubDesdeGrupo(?string $grupo): int
    {
        $grupo = trim((string) $grupo);
        if ($grupo === '' || !str_starts_with($grupo, self::DESTINO_GRUPO_PREFIX)) {
            return 0;
        }
        $rest = substr($grupo, strlen(self::DESTINO_GRUPO_PREFIX));
        $pipe = strpos($rest, '|');
        if ($pipe !== false) {
            $rest = substr($rest, 0, $pipe);
        }

        return max(0, (int) $rest);
    }

    public static function notaHumanaGrupo(?string $grupo): string
    {
        $grupo = trim((string) $grupo);
        if ($grupo === '') {
            return '';
        }
        if (!str_starts_with($grupo, self::DESTINO_GRUPO_PREFIX)) {
            return $grupo;
        }
        $pipe = strpos($grupo, '|');

        return $pipe !== false ? trim(substr($grupo, $pipe + 1)) : '';
    }

    /**
     * @return array<string, bool>
     */
    public static function columnasUsuarioImagen(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = ['photo_path' => false, 'urlimgfoto' => false, 'urlimgcedula' => false, 'foto_cedula' => false];
        foreach (array_keys($cache) as $col) {
            $st = $pdo->query("SHOW COLUMNS FROM usuarios LIKE " . $pdo->quote($col));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                $cache[$col] = true;
            }
        }

        return $cache;
    }
}
