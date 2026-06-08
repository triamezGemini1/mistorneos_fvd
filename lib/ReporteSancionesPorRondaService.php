<?php

declare(strict_types=1);

require_once __DIR__ . '/NumfvdHelper.php';
require_once __DIR__ . '/PartiresulEstatusSql.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/InscritosReporteStatsHelper.php';
require_once __DIR__ . '/ResultadosReporteData.php';

/**
 * Tarjetas disciplinarias agrupadas por NUMFVD: vector [mesa, ronda, tarjeta] en orden cronológico.
 * Solo filas donde la tarjeta corresponde al jugador (no propagación errónea en mesas FF/TR).
 */
final class ReporteSancionesPorRondaService
{
    /**
     * Tarjeta atribuible al jugador en reporte (excluye ganadores con tarjeta copiada en mesa FF/TR).
     */
    public static function sqlWhereTarjetaAtribuibleJugador(string $prAlias = 'pr'): string
    {
        if ($prAlias === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prAlias)) {
            throw new InvalidArgumentException('Alias inválido: ' . $prAlias);
        }
        $t = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd($prAlias . '.tarjeta');
        $ef = InscritosHelper::sqlExprColumnaNumerica($prAlias . '.efectividad');
        $sanc = InscritosHelper::sqlExprColumnaNumerica($prAlias . '.sancion');
        $wRegFf = PartiresulEstatusSql::whereRegistradoUno('pr_ff');
        $wFf = PartiresulEstatusSql::whereFfUno('pr_ff');
        $wRegTg = PartiresulEstatusSql::whereRegistradoUno('pr_tg');
        $tTg = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr_tg.tarjeta');

        $mesaConForfait = "EXISTS (
            SELECT 1 FROM partiresul pr_ff
            WHERE pr_ff.id_torneo = {$prAlias}.id_torneo
              AND pr_ff.partida = {$prAlias}.partida
              AND pr_ff.mesa = {$prAlias}.mesa
              AND {$wRegFf}
              AND {$wFf}
        )";

        $mesaConTarjetaGrave = "EXISTS (
            SELECT 1 FROM partiresul pr_tg
            WHERE pr_tg.id_torneo = {$prAlias}.id_torneo
              AND pr_tg.partida = {$prAlias}.partida
              AND pr_tg.mesa = {$prAlias}.mesa
              AND {$wRegTg}
              AND {$tTg} IN (3, 4)
        )";

        return "(
            ({$t} IN (3, 4) AND {$ef} < 0)
            OR ({$t} = 1 AND {$sanc} >= 80)
            OR (
                {$t} = 1
                AND {$sanc} = 0
                AND NOT ({$mesaConForfait})
                AND NOT ({$mesaConTarjetaGrave})
            )
        )";
    }

    private static function letraDesdeCodigoNormalizado(int $cod): string
    {
        switch ($cod) {
            case 1:
                return 'A';
            case 3:
                return 'R';
            case 4:
                return 'N';
            default:
                return '';
        }
    }

    /**
     * @return array{
     *   torneo: array<string, mixed>,
     *   ronda_filtro: int,
     *   rondas_disponibles: list<int>,
     *   filas: list<array<string, mixed>>,
     *   total: int,
     *   sin_tarjetas: bool
     * }
     */
    public function construirReporte(int $torneoId, PDO $pdo, int $rondaFiltro = 0): array
    {
        $torneo = $this->cargarTorneo($torneoId, $pdo);
        if ($torneo === []) {
            return [
                'torneo' => [],
                'ronda_filtro' => 0,
                'rondas_disponibles' => [],
                'filas' => [],
                'total' => 0,
                'sin_tarjetas' => true,
            ];
        }

        $rondasDisponibles = $this->rondasConTarjetas($torneoId, $pdo);
        if ($rondaFiltro > 0 && ! in_array($rondaFiltro, $rondasDisponibles, true)) {
            $rondaFiltro = 0;
        }

        $wReg = PartiresulEstatusSql::whereRegistradoUno('pr');
        $tExpr = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr.tarjeta');
        $wTarjetaReal = self::sqlWhereTarjetaAtribuibleJugador('pr');
        $exprNf = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo);

        $params = [$torneoId];
        $rondaWhere = '';
        if ($rondaFiltro > 0) {
            $rondaWhere = ' AND pr.partida = ?';
            $params[] = $rondaFiltro;
        }

        $sql = '
            SELECT
                pr.partida AS ronda,
                pr.mesa,
                pr.id_usuario,
                u.nombre,
                ' . $exprNf . ' AS numfvd,
                ' . $tExpr . ' AS tarjeta_cod
            FROM partiresul pr
            INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
            INNER JOIN usuarios u ON u.id = i.id_usuario
            WHERE pr.id_torneo = ?
              AND ' . $wReg . '
              AND ' . $tExpr . ' IN (1, 3, 4)
              AND ' . $wTarjetaReal . '
              ' . $rondaWhere . '
            ORDER BY pr.partida ASC, pr.mesa ASC, ' . $exprNf . ' ASC, u.nombre ASC
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $raw = NumfvdHelper::enriquecerFilas($stmt->fetchAll(PDO::FETCH_ASSOC));

        /** @var array<int, array<string, mixed>> $porJugador */
        $porJugador = [];
        foreach ($raw as $row) {
            $cod = (int) round((float) ($row['tarjeta_cod'] ?? 0));
            $letra = self::letraDesdeCodigoNormalizado($cod);
            if ($letra === '') {
                continue;
            }
            $idUsuario = (int) ($row['id_usuario'] ?? 0);
            if ($idUsuario <= 0) {
                continue;
            }
            if (! isset($porJugador[$idUsuario])) {
                $porJugador[$idUsuario] = [
                    'id_usuario' => $idUsuario,
                    'nombre' => trim((string) ($row['nombre'] ?? '')),
                    'numfvd' => NumfvdHelper::textoMostrar($row, false),
                    'numfvd_sort' => NumfvdHelper::desdeFila($row),
                    'sanciones' => [],
                ];
            }
            $porJugador[$idUsuario]['sanciones'][] = [
                'mesa' => (int) ($row['mesa'] ?? 0),
                'ronda' => (int) ($row['ronda'] ?? 0),
                'tarjeta_letra' => $letra,
                'tarjeta_cod' => $cod,
            ];
        }

        $filas = array_values($porJugador);
        usort($filas, static function (array $a, array $b): int {
            $na = (int) ($a['numfvd_sort'] ?? 0);
            $nb = (int) ($b['numfvd_sort'] ?? 0);
            if ($na !== $nb) {
                return $na <=> $nb;
            }

            return strcasecmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
        });

        foreach ($filas as &$fila) {
            $partes = [];
            foreach ($fila['sanciones'] as $s) {
                $partes[] = (int) $s['mesa'] . '-' . (int) $s['ronda'] . '-' . (string) $s['tarjeta_letra'];
            }
            $fila['sanciones_texto'] = implode(', ', $partes);
            unset($fila['numfvd_sort']);
        }
        unset($fila);

        return [
            'torneo' => $torneo,
            'ronda_filtro' => $rondaFiltro,
            'rondas_disponibles' => $rondasDisponibles,
            'filas' => $filas,
            'total' => count($filas),
            'sin_tarjetas' => $filas === [],
        ];
    }

    /** @return array<string, mixed> */
    private function cargarTorneo(int $torneoId, PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT id, nombre, modalidad, rondas FROM tournaments WHERE id = ?');
        $stmt->execute([$torneoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @return list<int> */
    private function rondasConTarjetas(int $torneoId, PDO $pdo): array
    {
        $wReg = PartiresulEstatusSql::whereRegistradoUno('pr');
        $tExpr = InscritosReporteStatsHelper::sqlExprTarjetaCodigoFvd('pr.tarjeta');
        $wTarjetaReal = self::sqlWhereTarjetaAtribuibleJugador('pr');
        $sql = '
            SELECT DISTINCT pr.partida
            FROM partiresul pr
            WHERE pr.id_torneo = ?
              AND ' . $wReg . '
              AND ' . $tExpr . ' IN (1, 3, 4)
              AND ' . $wTarjetaReal . '
            ORDER BY pr.partida ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
