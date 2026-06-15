<?php
/**
 * Tabla de desglose de podios por torneo (una asociación).
 * Requiere: $torneos (list), $totales (oro, plata, bronce, total_puntos).
 * Opcional: $fmtFecha (callable). Debe ir dentro de .fvd-podios.
 */
declare(strict_types=1);

$torneos = $torneos ?? [];
$totales = $totales ?? ['oro' => 0, 'plata' => 0, 'bronce' => 0, 'total_puntos' => 0];
if (! isset($fmtFecha) || ! is_callable($fmtFecha)) {
    $fmtFecha = static function (?string $f): string {
        if ($f === null || $f === '') {
            return '—';
        }
        $t = strtotime($f);

        return $t ? date('d/m/Y', $t) : '—';
    };
}
?>
<div class="fvd-podios-desglose overflow-x-auto">
    <table>
        <thead>
            <tr>
                <th>Torneo</th>
                <th class="text-center">Fecha</th>
                <th class="text-center"><i class="fas fa-medal" aria-hidden="true"></i> Oro</th>
                <th class="text-center">Plata</th>
                <th class="text-center">Bronce</th>
                <th class="text-right">Puntos</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($torneos === []): ?>
                <tr>
                    <td colspan="6" class="text-center">Sin podios en torneos con ranking.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($torneos as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($t['torneo_nombre'] ?? '')) ?></td>
                        <td class="text-center whitespace-nowrap"><?= htmlspecialchars($fmtFecha((string) ($t['fechator'] ?? ''))) ?></td>
                        <td class="text-center"><?= (int) ($t['oro'] ?? 0) ?></td>
                        <td class="text-center"><?= (int) ($t['plata'] ?? 0) ?></td>
                        <td class="text-center"><?= (int) ($t['bronce'] ?? 0) ?></td>
                        <td class="text-right"><?= (int) ($t['total_puntos'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total asociación</td>
                <td class="text-center"><?= (int) ($totales['oro'] ?? 0) ?></td>
                <td class="text-center"><?= (int) ($totales['plata'] ?? 0) ?></td>
                <td class="text-center"><?= (int) ($totales['bronce'] ?? 0) ?></td>
                <td class="text-right"><?= (int) ($totales['total_puntos'] ?? 0) ?></td>
            </tr>
        </tfoot>
    </table>
</div>
