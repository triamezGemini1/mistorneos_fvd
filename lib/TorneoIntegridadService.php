<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/NumfvdHelper.php';
require_once __DIR__ . '/PartiresulJugadorHelper.php';
require_once __DIR__ . '/ReporteParejasRepetidasService.php';
require_once __DIR__ . '/Core/TorneoMesaAsignacionResolver.php';

/**
 * Validación de inscritos antes de generar rondas y auditoría de parejas repetidas.
 */
final class TorneoIntegridadService
{
    public static function esModalidadIndividual(int $modalidad): bool
    {
        return $modalidad !== TorneoMesaAsignacionResolver::MODALIDAD_EQUIPOS
            && !in_array($modalidad, TorneoMesaAsignacionResolver::MODALIDAD_PAREJAS_FIJAS, true);
    }

    /**
     * @return array{
     *   ok: bool,
     *   errores: list<string>,
     *   advertencias: list<string>,
     *   numfvd_corregidos: int,
     *   confirmados: int
     * }
     */
    public static function validarAntesGenerarRonda(PDO $pdo, int $torneoId, int $modalidad): array
    {
        PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);

        $errores = [];
        $advertencias = [];
        $numfvdCorregidos = 0;

        $whereConfirmado = InscritosHelper::sqlWhereSoloConfirmadoConAlias('i');
        $st = $pdo->prepare(
            "SELECT i.id_usuario, i.codigo_equipo
             FROM inscritos i
             WHERE i.torneo_id = ? AND {$whereConfirmado}"
        );
        $st->execute([$torneoId]);
        $confirmados = $st->fetchAll(PDO::FETCH_ASSOC);
        $totalConfirmados = count($confirmados);

        if (in_array($modalidad, TorneoMesaAsignacionResolver::MODALIDAD_PAREJAS_FIJAS, true)) {
            $equipos = [];
            foreach ($confirmados as $row) {
                $cod = trim((string) ($row['codigo_equipo'] ?? ''));
                if ($cod === '') {
                    continue;
                }
                $equipos[$cod] = ($equipos[$cod] ?? 0) + 1;
            }
            $parejasCompletas = 0;
            foreach ($equipos as $cnt) {
                if ($cnt >= 2) {
                    $parejasCompletas++;
                }
            }
            if ($parejasCompletas < 2) {
                $errores[] = 'Se requieren al menos 2 parejas (código de equipo) con 2 jugadores confirmados cada una.';
            }
        } elseif ($modalidad === TorneoMesaAsignacionResolver::MODALIDAD_EQUIPOS) {
            if ($totalConfirmados < 4) {
                $errores[] = "Solo hay {$totalConfirmados} inscrito(s) confirmado(s); se requieren al menos 4 para equipos.";
            }
        } elseif ($totalConfirmados < 4) {
            $errores[] = "Solo hay {$totalConfirmados} inscrito(s) confirmado(s); se requieren al menos 4 para generar ronda.";
        }

        $sinNumfvd = [];
        foreach ($confirmados as $row) {
            $uid = (int) ($row['id_usuario'] ?? 0);
            if ($uid <= 0) {
                $errores[] = 'Hay inscritos confirmados sin id_usuario válido.';
                continue;
            }
            $nf = InscritosHelper::asegurarNumfvdInscrito($pdo, $torneoId, $uid);
            if ($nf > 0) {
                $numfvdCorregidos++;
            } else {
                $resuelto = NumfvdHelper::numfvdInscrito($pdo, $torneoId, $uid);
                if ($resuelto <= 0) {
                    $sinNumfvd[] = $uid;
                }
            }
        }

        if ($sinNumfvd !== []) {
            $muestra = implode(', ', array_slice($sinNumfvd, 0, 8));
            $extra = count($sinNumfvd) > 8 ? '…' : '';
            $errores[] = 'Inscritos confirmados sin NUMFVD resoluble (id_usuario: ' . $muestra . $extra . '). '
                . 'Complete el NUMFVD en usuarios/inscritos antes de generar la ronda.';
        }

        if ($numfvdCorregidos > 0 && $sinNumfvd === []) {
            $advertencias[] = "Se completó NUMFVD en {$numfvdCorregidos} inscripción(es) desde usuarios.";
        }

        return [
            'ok' => $errores === [],
            'errores' => $errores,
            'advertencias' => $advertencias,
            'numfvd_corregidos' => $numfvdCorregidos,
            'confirmados' => $totalConfirmados,
        ];
    }

    /**
     * @param list<list<array<string, mixed>>> $mesas
     * @param array<int, array<int, true>> $matrizCompañeros
     * @return list<array{j1: int, j2: int, mesa: int}>
     */
    public static function conflictosParejaRepetidaEnMesas(
        PDO $pdo,
        int $torneoId,
        array $mesas,
        array $matrizCompañeros
    ): array {
        if ($torneoId <= 0 || $matrizCompañeros === []) {
            return [];
        }

        $conflictos = [];
        $numeroMesa = 1;
        foreach ($mesas as $mesa) {
            if (count($mesa) < 4) {
                $numeroMesa++;
                continue;
            }
            $pares = [
                [(int) ($mesa[0]['id_usuario'] ?? 0), (int) ($mesa[1]['id_usuario'] ?? 0)],
                [(int) ($mesa[2]['id_usuario'] ?? 0), (int) ($mesa[3]['id_usuario'] ?? 0)],
            ];
            foreach ($pares as [$a, $b]) {
                if ($a <= 0 || $b <= 0 || $a === $b) {
                    continue;
                }
                $uidA = self::resolverUid($pdo, $torneoId, $a);
                $uidB = self::resolverUid($pdo, $torneoId, $b);
                if (!empty($matrizCompañeros[$uidA][$uidB]) || !empty($matrizCompañeros[$uidB][$uidA])) {
                    $conflictos[] = ['j1' => min($uidA, $uidB), 'j2' => max($uidA, $uidB), 'mesa' => $numeroMesa];
                }
            }
            $numeroMesa++;
        }

        return $conflictos;
    }

    private static function resolverUid(PDO $pdo, int $torneoId, int $identificador): int
    {
        if ($identificador <= 0) {
            return 0;
        }
        $uid = NumfvdHelper::resolverIdUsuarioInscrito($pdo, $torneoId, $identificador);

        return ($uid !== null && $uid > 0) ? $uid : $identificador;
    }

    /**
     * @return array{
     *   sin_repeticiones: bool,
     *   total_grupos: int,
     *   url_reporte: string,
     *   mensaje: string
     * }
     */
    public static function auditarParejasRepetidasPostRonda(PDO $pdo, int $torneoId, int $minVeces = 2): array
    {
        $reporte = (new ReporteParejasRepetidasService())->construirReporte($torneoId, $pdo, $minVeces);
        $url = 'index.php?page=torneo_gestion&action=reporte_parejas_repetidas&torneo_id=' . $torneoId;

        return [
            'sin_repeticiones' => (bool) ($reporte['sin_repeticiones'] ?? true),
            'total_grupos' => (int) ($reporte['total_grupos'] ?? 0),
            'url_reporte' => $url,
            'mensaje' => (string) ($reporte['mensaje'] ?? ''),
        ];
    }
}
