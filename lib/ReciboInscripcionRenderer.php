<?php

declare(strict_types=1);

require_once __DIR__ . '/ReciboPagoQrHelper.php';
require_once __DIR__ . '/ClubHelper.php';

/**
 * Recibo de pago al confirmar inscripción (datos completos torneo + atleta + QR).
 */
final class ReciboInscripcionRenderer
{
    /**
     * @return array{html: string}|null
     */
    public static function htmlDesdeInscripcion(PDO $pdo, int $inscripcionId, int $torneoId): ?array
    {
        $data = self::cargarDatos($pdo, $inscripcionId, $torneoId);
        if ($data === null) {
            return null;
        }

        return ['html' => self::renderHtml($data)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function cargarDatos(PDO $pdo, int $inscripcionId, int $torneoId): ?array
    {
        $hasNumfvd = false;
        try {
            $hasNumfvd = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetchColumn();
        } catch (Throwable $e) {
        }
        $cols = 'i.id, i.id_usuario, i.torneo_id, i.estatus, i.id_club,
                   u.nombre, u.cedula, u.username, u.celular, u.id AS user_id';
        if ($hasNumfvd) {
            $cols .= ', u.numfvd';
        }

        $st = $pdo->prepare("
            SELECT {$cols}
            FROM inscritos i
            INNER JOIN usuarios u ON u.id = i.id_usuario
            WHERE i.id = ? AND i.torneo_id = ?
            LIMIT 1
        ");
        $st->execute([$inscripcionId, $torneoId]);
        $ins = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ins) {
            return null;
        }
        if (!$hasNumfvd) {
            $ins['numfvd'] = 0;
        }

        return self::buildData($pdo, $ins, $torneoId);
    }

    /**
     * @param array<string, mixed> $ins
     * @return array<string, mixed>
     */
    public static function buildData(PDO $pdo, array $ins, int $torneoId): array
    {
        $st = $pdo->prepare('
            SELECT nombre, fechator, costo, lugar, modalidad, rondas, puntos, tiempo, entidad
            FROM tournaments WHERE id = ? LIMIT 1
        ');
        $st->execute([$torneoId]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $fechaTor = !empty($t['fechator']) ? date('d/m/Y', strtotime((string) $t['fechator'])) : '—';
        $horaTor = '—';
        if (!empty($t['fechator']) && strlen((string) $t['fechator']) > 10) {
            $horaTor = date('H:i', strtotime((string) $t['fechator']));
        }

        $modalidadMap = [0 => 'No definido', 1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];
        $mod = (int) ($t['modalidad'] ?? 0);

        $idClub = (int) ($ins['id_club'] ?? 0);
        $clubNombre = '—';
        if ($idClub > 0) {
            $stC = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
            $stC->execute([$idClub]);
            $clubNombre = ClubHelper::etiquetaAsociacion($idClub, (string) ($stC->fetchColumn() ?: ''));
        }

        $costo = (float) ($t['costo'] ?? 0);

        return [
            'inscripcion_id' => (int) ($ins['id'] ?? 0),
            'torneo_id' => $torneoId,
            'torneo_nombre' => (string) ($t['nombre'] ?? ''),
            'fecha_torneo' => $fechaTor,
            'hora_torneo' => $horaTor,
            'lugar' => trim((string) ($t['lugar'] ?? '')),
            'modalidad' => $modalidadMap[$mod] ?? '—',
            'rondas' => (int) ($t['rondas'] ?? 0),
            'puntos' => (int) ($t['puntos'] ?? 0),
            'tiempo' => (int) ($t['tiempo'] ?? 0),
            'entidad_torneo' => (int) ($t['entidad'] ?? 0),
            'atleta_nombre' => (string) ($ins['nombre'] ?? ''),
            'cedula' => (string) ($ins['cedula'] ?? ''),
            'username' => (string) ($ins['username'] ?? ''),
            'celular' => trim((string) ($ins['celular'] ?? '')) ?: '—',
            'numfvd' => (int) ($ins['numfvd'] ?? 0),
            'user_id' => (int) ($ins['id_usuario'] ?? $ins['user_id'] ?? 0),
            'entidad_nombre' => $clubNombre,
            'entidad_id' => $idClub,
            'monto' => number_format($costo, 2),
            'monto_raw' => $costo,
            'validado_en' => date('d/m/Y H:i'),
        ];
    }

    /**
     * @param array<string, mixed> $recibo
     */
    public static function renderHtml(array $recibo): string
    {
        $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $lugar = ($recibo['lugar'] ?? '') !== '' ? $esc($recibo['lugar']) : '—';
        $qrPersonal = ReciboPagoQrHelper::bloqueHtmlQrPersonal(
            (int) ($recibo['torneo_id'] ?? 0),
            (int) ($recibo['user_id'] ?? 0)
        );
        $numfvd = (int) ($recibo['numfvd'] ?? 0);
        $numfvdTxt = $numfvd > 0 ? (string) $numfvd : '—';

        return <<<HTML
<div class="recibo-pago-tarjeta border border-success rounded-3 overflow-hidden bg-white" id="recibo-pago-print">
  <div class="bg-success text-white text-center py-4 px-3">
    <div class="mb-2"><i class="fas fa-receipt fa-2x"></i></div>
    <h4 class="mb-1 fw-bold">Recibo de pago de inscripción</h4>
    <p class="mb-0 small opacity-90">Inscripción #{$esc($recibo['inscripcion_id'])} · {$esc($recibo['validado_en'])}</p>
  </div>
  <div class="p-4">
    <h5 class="text-center fw-bold text-success mb-3">{$esc($recibo['torneo_nombre'])}</h5>
    <p class="text-center text-muted small mb-3">
      {$esc($recibo['fecha_torneo'])} · {$lugar} · {$esc($recibo['hora_torneo'])}
    </p>
    <p class="text-center small mb-4">
      {$esc($recibo['modalidad'])} · {$esc((string) $recibo['rondas'])} rondas · {$esc((string) $recibo['tiempo'])} min · {$esc((string) $recibo['puntos'])} pts
    </p>
    <hr>
    <p class="mb-1"><strong>Atleta:</strong> {$esc($recibo['atleta_nombre'])}</p>
    <p class="mb-1"><strong>Cédula:</strong> {$esc($recibo['cedula'])}</p>
    <p class="mb-1"><strong>Nº FVD:</strong> {$esc($numfvdTxt)}</p>
    <p class="mb-1"><strong>ID usuario:</strong> {$esc((string) $recibo['user_id'])}</p>
    <p class="mb-1"><strong>Celular:</strong> {$esc($recibo['celular'])}</p>
    <p class="mb-1"><strong>Asociación:</strong> {$esc($recibo['entidad_nombre'])}</p>
    <hr>
    <p class="mb-0"><strong>Costo inscripción:</strong> <span class="text-success fs-5">\${$esc($recibo['monto'])}</span></p>
    {$qrPersonal}
  </div>
  <div class="bg-light text-center py-2 small text-muted">
    Comprobante de inscripción confirmada · FVD
  </div>
</div>
HTML;
    }
}
