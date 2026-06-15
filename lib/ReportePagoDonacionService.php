<?php

declare(strict_types=1);

require_once __DIR__ . '/RankingAtletasPdfAccesoHelper.php';
require_once __DIR__ . '/UserActivationHelper.php';
require_once __DIR__ . '/NotificationManager.php';
require_once __DIR__ . '/../config/auth.php';

/**
 * Reportes de pago por donación → habilitar reportes personales del atleta.
 */
final class ReportePagoDonacionService
{
  public const TIPOS_PAGO = ['transferencia', 'pagomovil', 'efectivo', 'zelle', 'otro'];

  public static function tablaDisponible(PDO $pdo): bool
  {
    try {
      return (bool) $pdo->query("SHOW TABLES LIKE 'reportes_pago_donacion'")->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }

  /**
   * @return array{id:int, nombre:string, numfvd:int, cedula:string}|null
   */
  public static function resolverUsuario(PDO $pdo, ?int $idUsuario, ?int $numfvd): ?array
  {
    if ($idUsuario > 0) {
      $st = $pdo->prepare(
        'SELECT id, nombre, COALESCE(numfvd, 0) AS numfvd, COALESCE(cedula, \'\') AS cedula
         FROM usuarios WHERE id = ? LIMIT 1'
      );
      $st->execute([$idUsuario]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      return $row ?: null;
    }

    if ($numfvd > 0) {
      $st = $pdo->prepare(
        'SELECT id, nombre, COALESCE(numfvd, 0) AS numfvd, COALESCE(cedula, \'\') AS cedula
         FROM usuarios WHERE numfvd = ? ORDER BY id ASC LIMIT 1'
      );
      $st->execute([$numfvd]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      return $row ?: null;
    }

    return null;
  }

  /**
   * @param array<string, mixed> $data
   * @return array{ok:bool, message:string, id?:int}
   */
  public static function crearReporte(PDO $pdo, array $data): array
  {
    if (! self::tablaDisponible($pdo)) {
      return ['ok' => false, 'message' => 'Tabla reportes_pago_donacion no existe. Ejecute el SQL de migración.'];
    }

    $idUsuario = (int) ($data['id_usuario'] ?? 0);
    $numfvd = (int) ($data['numfvd_reportado'] ?? 0);
    $fecha = trim((string) ($data['fecha'] ?? ''));
    $hora = trim((string) ($data['hora'] ?? ''));
    $tipoPago = trim((string) ($data['tipo_pago'] ?? ''));
    $monto = (float) ($data['monto'] ?? 0);
    $banco = trim((string) ($data['banco'] ?? ''));
    $referencia = trim((string) ($data['referencia'] ?? ''));
    $comentarios = trim((string) ($data['comentarios'] ?? ''));

    if ($idUsuario <= 0 && $numfvd <= 0) {
      return ['ok' => false, 'message' => 'Indique ID de usuario o NUMFVD del atleta.'];
    }
    if ($fecha === '' || $hora === '') {
      return ['ok' => false, 'message' => 'Fecha y hora del pago son obligatorias.'];
    }
    if (! in_array($tipoPago, self::TIPOS_PAGO, true)) {
      return ['ok' => false, 'message' => 'Tipo de pago no válido.'];
    }
    if ($monto <= 0) {
      return ['ok' => false, 'message' => 'El monto debe ser mayor a cero.'];
    }
    if (in_array($tipoPago, ['transferencia', 'pagomovil', 'zelle'], true) && $referencia === '') {
      return ['ok' => false, 'message' => 'La referencia es obligatoria para este tipo de pago.'];
    }

    $resuelto = self::resolverUsuario($pdo, $idUsuario > 0 ? $idUsuario : null, $numfvd > 0 ? $numfvd : null);
    $idResuelto = $resuelto ? (int) $resuelto['id'] : null;
    if ($idUsuario <= 0 && $idResuelto !== null) {
      $idUsuario = $idResuelto;
    }

    $st = $pdo->prepare(
      'INSERT INTO reportes_pago_donacion (
         id_usuario, numfvd_reportado, id_usuario_resuelto,
         fecha, hora, tipo_pago, banco, monto, referencia, comentarios, estatus
       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\')'
    );
    $st->execute([
      $idUsuario > 0 ? $idUsuario : null,
      $numfvd > 0 ? $numfvd : null,
      $idResuelto,
      $fecha,
      $hora,
      $tipoPago,
      $banco !== '' ? $banco : null,
      $monto,
      $referencia !== '' ? $referencia : null,
      $comentarios !== '' ? $comentarios : null,
    ]);

    return [
      'ok' => true,
      'message' => 'Reporte de donación registrado. Será revisado por el administrador.',
      'id' => (int) $pdo->lastInsertId(),
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  public static function cargarReporte(PDO $pdo, int $reporteId): ?array
  {
    if ($reporteId <= 0 || ! self::tablaDisponible($pdo)) {
      return null;
    }

    $hasEntidad = self::tablaExiste($pdo, 'entidad');
    $entidadJoin = $hasEntidad ? 'LEFT JOIN entidad e ON e.id = COALESCE(ur.entidad, u.entidad)' : '';
    $entidadSelect = $hasEntidad
      ? ', e.nombre AS entidad_nombre, u.entidad AS entidad_id'
      : ', u.entidad AS entidad_id, NULL AS entidad_nombre';

    $sql = "
      SELECT
        rpd.*,
        COALESCE(ur.id, u.id) AS usuario_id,
        COALESCE(ur.nombre, u.nombre) AS usuario_nombre,
        COALESCE(ur.cedula, u.cedula) AS usuario_cedula,
        COALESCE(ur.username, u.username) AS usuario_username,
        COALESCE(ur.celular, u.celular) AS usuario_celular,
        COALESCE(ur.email, u.email) AS usuario_email,
        COALESCE(NULLIF(ur.numfvd, 0), NULLIF(u.numfvd, 0), rpd.numfvd_reportado, 0) AS usuario_numfvd,
        COALESCE(ur.permite_reportes_personales, u.permite_reportes_personales, 0) AS ya_habilitado_reportes,
        COALESCE(ur.telegram_chat_id, u.telegram_chat_id) AS telegram_chat_id,
        adm.nombre AS activado_por_nombre
        {$entidadSelect}
      FROM reportes_pago_donacion rpd
      LEFT JOIN usuarios u ON rpd.id_usuario = u.id
      LEFT JOIN usuarios ur ON rpd.id_usuario_resuelto = ur.id
      LEFT JOIN usuarios adm ON rpd.activado_por = adm.id
      {$entidadJoin}
      WHERE rpd.id = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$reporteId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
  }

  /**
   * @return array{ok:bool, message:string, recibo?:array<string,mixed>, usuario_activado?:int}
   */
  public static function establecerConfirmado(
    PDO $pdo,
    int $reporteId,
    bool $confirmado,
    bool $notificar = true,
    ?int $idAdmin = null
  ): array {
    $reporte = self::cargarReporte($pdo, $reporteId);
    if ($reporte === null) {
      return ['ok' => false, 'message' => 'Reporte no encontrado.'];
    }

    if ($confirmado) {
      $usuario = self::resolverUsuarioDesdeReporte($pdo, $reporte);
      if ($usuario === null) {
        return [
          'ok' => false,
          'message' => 'No se encontró usuario con el ID o NUMFVD indicado. Verifique el registro.',
        ];
      }

      $userId = (int) $usuario['id'];
      $resActivacion = self::activarReportesPersonales($pdo, $userId, $idAdmin);

      $st = $pdo->prepare(
        "UPDATE reportes_pago_donacion
         SET estatus = 'confirmado',
             id_usuario_resuelto = ?,
             activado_en = NOW(),
             activado_por = ?,
             updated_at = NOW()
         WHERE id = ?"
      );
      $st->execute([$userId, $idAdmin > 0 ? $idAdmin : null, $reporteId]);

      $reporte = self::cargarReporte($pdo, $reporteId) ?? $reporte;
      $reporte['estatus'] = 'confirmado';

      if ($notificar) {
        self::notificarActivacion($pdo, $reporte, $userId);
      }

      return [
        'ok' => true,
        'message' => $resActivacion['message'],
        'recibo' => self::buildReciboData($reporte),
        'usuario_activado' => $userId,
      ];
    }

    $st = $pdo->prepare(
      "UPDATE reportes_pago_donacion
       SET estatus = 'pendiente', activado_en = NULL, activado_por = NULL, updated_at = NOW()
       WHERE id = ?"
    );
    $st->execute([$reporteId]);

    if (RankingAtletasPdfAccesoHelper::columnaPermiteReportesDisponible($pdo)) {
      $usuario = self::resolverUsuarioDesdeReporte($pdo, $reporte);
      if ($usuario !== null) {
        $stOff = $pdo->prepare('UPDATE usuarios SET permite_reportes_personales = 0 WHERE id = ?');
        $stOff->execute([(int) $usuario['id']]);
      }
    }

    return ['ok' => true, 'message' => 'Reporte marcado como pendiente.'];
  }

  /**
   * @return array{ok:bool, message:string}
   */
  public static function rechazar(PDO $pdo, int $reporteId, ?string $notas = null): array
  {
    $reporte = self::cargarReporte($pdo, $reporteId);
    if ($reporte === null) {
      return ['ok' => false, 'message' => 'Reporte no encontrado.'];
    }

    $st = $pdo->prepare(
      "UPDATE reportes_pago_donacion
       SET estatus = 'rechazado', notas_admin = ?, updated_at = NOW()
       WHERE id = ?"
    );
    $st->execute([$notas !== null && $notas !== '' ? $notas : null, $reporteId]);

    return ['ok' => true, 'message' => 'Reporte rechazado.'];
  }

  /**
   * @param array<string, mixed> $reporte
   * @return array{id:int, nombre:string, numfvd:int, cedula:string}|null
   */
  private static function resolverUsuarioDesdeReporte(PDO $pdo, array $reporte): ?array
  {
    $idResuelto = (int) ($reporte['id_usuario_resuelto'] ?? 0);
    if ($idResuelto > 0) {
      return self::resolverUsuario($pdo, $idResuelto, null);
    }

    $idUsuario = (int) ($reporte['id_usuario'] ?? 0);
    $numfvd = (int) ($reporte['numfvd_reportado'] ?? 0);

    return self::resolverUsuario($pdo, $idUsuario > 0 ? $idUsuario : null, $numfvd > 0 ? $numfvd : null);
  }

  /**
   * @return array{ok:bool, message:string}
   */
  private static function activarReportesPersonales(PDO $pdo, int $userId, ?int $idAdmin): array
  {
    UserActivationHelper::activateUser($pdo, $userId);

    if (! RankingAtletasPdfAccesoHelper::columnaPermiteReportesDisponible($pdo)) {
      return [
        'ok' => true,
        'message' => 'Usuario activado en el sistema. Falta columna permite_reportes_personales (ejecute migración SQL).',
      ];
    }

    $st = $pdo->prepare('UPDATE usuarios SET permite_reportes_personales = 1 WHERE id = ?');
    $st->execute([$userId]);

    return [
      'ok' => true,
      'message' => 'Pago verificado: reportes personales habilitados para el atleta.',
    ];
  }

  /**
   * @param array<string, mixed> $reporte
   */
  private static function notificarActivacion(PDO $pdo, array $reporte, int $userId): void
  {
    try {
      $urlDestino = class_exists('AppHelpers')
        ? AppHelpers::url('user_portal.php', ['section' => 'reportes_personales'])
        : 'user_portal.php?section=reportes_personales';

      $mensaje = 'Su donación fue verificada. Ya puede acceder a sus reportes personales en PDF desde el portal.';
      $item = [
        'id' => $userId,
        'mensaje' => $mensaje,
        'url_destino' => $urlDestino,
        'datos_json' => [
          'tipo' => 'donacion_reportes_activado',
          'reporte_id' => (int) ($reporte['id'] ?? 0),
          'monto' => (float) ($reporte['monto'] ?? 0),
        ],
        'telegram_chat_id' => trim((string) ($reporte['telegram_chat_id'] ?? '')) ?: null,
      ];

      $nm = new NotificationManager($pdo);
      $nm->programarMasivoPersonalizado([$item]);
    } catch (Throwable $e) {
      error_log('ReportePagoDonacionService notify: ' . $e->getMessage());
    }
  }

  /**
   * @param array<string, mixed> $reporte
   * @return array<string, mixed>
   */
  public static function buildReciboData(array $reporte): array
  {
    return [
      'reporte_id' => (int) ($reporte['id'] ?? 0),
      'atleta_nombre' => (string) ($reporte['usuario_nombre'] ?? '—'),
      'cedula' => (string) ($reporte['usuario_cedula'] ?? '—'),
      'user_id' => (int) ($reporte['usuario_id'] ?? $reporte['id_usuario_resuelto'] ?? 0),
      'numfvd' => (int) ($reporte['usuario_numfvd'] ?? $reporte['numfvd_reportado'] ?? 0),
      'entidad_nombre' => (string) ($reporte['entidad_nombre'] ?? '—'),
      'monto' => number_format((float) ($reporte['monto'] ?? 0), 2, '.', ''),
      'tipo_pago' => ucfirst((string) ($reporte['tipo_pago'] ?? '')),
      'banco' => (string) ($reporte['banco'] ?? ''),
      'referencia' => (string) ($reporte['referencia'] ?? ''),
      'fecha_pago' => ! empty($reporte['fecha']) ? date('d/m/Y', strtotime((string) $reporte['fecha'])) : '—',
      'hora_pago' => substr((string) ($reporte['hora'] ?? ''), 0, 5),
      'estatus' => (string) ($reporte['estatus'] ?? ''),
      'fecha_reporte' => ! empty($reporte['created_at'])
        ? date('d/m/Y H:i', strtotime((string) $reporte['created_at']))
        : '—',
      'activado_en' => ! empty($reporte['activado_en'])
        ? date('d/m/Y H:i', strtotime((string) $reporte['activado_en']))
        : null,
    ];
  }

  /**
   * @param array<string, mixed> $recibo
   */
  public static function renderReciboHtml(array $recibo): string
  {
    $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $banco = $recibo['banco'] !== '' ? $esc($recibo['banco']) : '—';
    $ref = $recibo['referencia'] !== '' ? $esc($recibo['referencia']) : '—';
    $activado = $recibo['activado_en'] ? '<p class="mb-0 text-success"><strong>Activado:</strong> ' . $esc($recibo['activado_en']) . '</p>' : '';

    return <<<HTML
<div class="border rounded overflow-hidden" id="recibo-donacion-print">
  <div class="bg-success text-white text-center py-3">
    <div class="small text-uppercase opacity-75">Federación Venezolana de Dominó</div>
    <h5 class="mb-0 fw-bold">Recibo de donación — Reportes personales</h5>
  </div>
  <div class="p-4">
    <p class="mb-1"><strong>Atleta:</strong> {$esc($recibo['atleta_nombre'])}</p>
    <p class="mb-1"><strong>Cédula:</strong> {$esc($recibo['cedula'])}</p>
    <p class="mb-1"><strong>ID usuario:</strong> {$esc((string)$recibo['user_id'])} · <strong>NUMFVD:</strong> {$esc((string)$recibo['numfvd'])}</p>
    <p class="mb-3"><strong>Entidad:</strong> {$esc($recibo['entidad_nombre'])}</p>
    <hr>
    <p class="mb-1"><strong>Monto:</strong> <span class="text-success fs-5">\${$esc($recibo['monto'])}</span></p>
    <p class="mb-1"><strong>Tipo:</strong> {$esc($recibo['tipo_pago'])} · <strong>Banco:</strong> {$banco}</p>
    <p class="mb-1"><strong>Referencia:</strong> {$ref}</p>
    <p class="mb-1"><strong>Fecha pago:</strong> {$esc($recibo['fecha_pago'])} {$esc($recibo['hora_pago'])}</p>
    <p class="mb-1"><strong>Reporte #</strong>{$esc((string)$recibo['reporte_id'])} · {$esc($recibo['fecha_reporte'])}</p>
    {$activado}
  </div>
  <div class="bg-light text-center py-2 small text-muted">
    Comprobante de donación verificada · FVD
  </div>
</div>
HTML;
  }

  private static function tablaExiste(PDO $pdo, string $tabla): bool
  {
    try {
      $st = $pdo->prepare('SHOW TABLES LIKE ?');
      $st->execute([$tabla]);

      return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}
