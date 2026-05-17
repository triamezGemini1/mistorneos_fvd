<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/InscripcionTorneoNotifier.php';

/**
 * Pago de inscripciones: validar, marcar pendiente, recordatorio.
 */
final class InscripcionPagoService
{
    /**
     * @return array{ok:bool, message:string}
     */
    public static function validarPagoInscripcion(PDO $pdo, int $inscripcionId, int $torneoId): array
    {
        return self::establecerEstatusPago($pdo, $inscripcionId, $torneoId, InscritosHelper::ESTATUS_PAGADO_NUM, true);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function marcarPendienteInscripcion(PDO $pdo, int $inscripcionId, int $torneoId): array
    {
        return self::establecerEstatusPago($pdo, $inscripcionId, $torneoId, InscritosHelper::ESTATUS_PENDIENTE_NUM, false);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function marcarRetiradoInscripcion(PDO $pdo, int $inscripcionId, int $torneoId): array
    {
        return self::establecerEstatusDirecto($pdo, $inscripcionId, $torneoId, InscritosHelper::ESTATUS_RETIRADO_NUM);
    }

    /**
     * Cambia estatus desde la UI (pendiente / confirmado / retirado), incl. reactivar desde retirado.
     *
     * @return array{ok:bool, message:string, recibo?:array}
     */
    public static function establecerEstatusInscripcion(PDO $pdo, int $inscripcionId, int $torneoId, string $estado): array
    {
        $estado = strtolower(trim($estado));
        if ($estado === 'retirado') {
            return self::marcarRetiradoInscripcion($pdo, $inscripcionId, $torneoId);
        }
        if ($estado === 'confirmado') {
            return self::validarPagoInscripcion($pdo, $inscripcionId, $torneoId);
        }
        if ($estado === 'pendiente') {
            return self::establecerEstatusDirecto($pdo, $inscripcionId, $torneoId, InscritosHelper::ESTATUS_PENDIENTE_NUM);
        }

        return ['ok' => false, 'message' => 'Estatus no válido.'];
    }

    /**
     * @return array{ok:bool, message:string, whatsapp_url?:string}
     */
    public static function enviarRecordatorioPago(PDO $pdo, int $inscripcionId, int $torneoId): array
    {
        $row = self::cargarInscripcion($pdo, $inscripcionId, $torneoId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Inscripción no encontrada.'];
        }
        if (InscritosHelper::esRetirado($row['estatus'] ?? 0)) {
            return ['ok' => false, 'message' => 'El inscrito está retirado.'];
        }
        if (InscritosHelper::esConfirmado($row['estatus'] ?? 0)) {
            return ['ok' => false, 'message' => 'El atleta ya tiene el pago validado.'];
        }

        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        $idClub = (int) ($row['id_club'] ?? 0);
        $celular = trim((string) ($row['celular'] ?? ''));

        try {
            InscripcionTorneoNotifier::notificarRecordatorioPago(
                $pdo,
                $idUsuario,
                $torneoId,
                $idClub,
                $inscripcionId
            );
        } catch (Throwable $e) {
            error_log('InscripcionPagoService recordatorio notify: ' . $e->getMessage());
        }

        $waUrl = self::urlWhatsAppRecordatorio($pdo, $row, $torneoId);
        if ($waUrl === null) {
            return [
                'ok' => true,
                'message' => 'Recordatorio enviado por web y Telegram. El atleta no tiene celular para WhatsApp.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Recordatorio programado. Se abrirá WhatsApp para enviar el mensaje.',
            'whatsapp_url' => $waUrl,
        ];
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function establecerEstatusPago(
        PDO $pdo,
        int $inscripcionId,
        int $torneoId,
        int $estatusPago,
        bool $notificarSiPagado
    ): array {
        if ($inscripcionId <= 0 || $torneoId <= 0) {
            return ['ok' => false, 'message' => 'Parámetros inválidos.'];
        }
        if (!in_array($estatusPago, [InscritosHelper::ESTATUS_PENDIENTE_NUM, InscritosHelper::ESTATUS_PAGADO_NUM], true)) {
            return ['ok' => false, 'message' => 'Estatus de pago no válido.'];
        }

        $row = self::cargarInscripcion($pdo, $inscripcionId, $torneoId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Inscripción no encontrada.'];
        }
        $estatusActual = is_numeric($row['estatus'] ?? '') ? (int) $row['estatus'] : InscritosHelper::getEstatusNumero((string) $row['estatus']);
        $yaPagado = InscritosHelper::esConfirmado($estatusActual);
        $quierePagado = $estatusPago === InscritosHelper::ESTATUS_PAGADO_NUM;

        if ($quierePagado && $yaPagado) {
            return ['ok' => false, 'message' => 'El pago ya estaba marcado como pagado.'];
        }
        if (!$quierePagado && ! $yaPagado && $estatusActual === InscritosHelper::ESTATUS_PENDIENTE_NUM) {
            return ['ok' => true, 'message' => 'Ya estaba pendiente de pago.'];
        }

        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        $idClub = (int) ($row['id_club'] ?? 0);

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('UPDATE inscritos SET estatus = ? WHERE id = ? AND torneo_id = ?');
            $upd->execute([$estatusPago, $inscripcionId, $torneoId]);

            if ($quierePagado) {
                if (self::tablaExiste($pdo, 'reportes_pago_usuarios')) {
                    $stR = $pdo->prepare("
                        UPDATE reportes_pago_usuarios
                        SET estatus = 'confirmado', updated_at = NOW()
                        WHERE torneo_id = ? AND id_usuario = ?
                          AND estatus NOT IN ('confirmado', 'rechazado')
                    ");
                    $stR->execute([$torneoId, $idUsuario]);
                }
                if (self::tablaExiste($pdo, 'payments')) {
                    $stP = $pdo->prepare("
                        UPDATE payments
                        SET status = 'pagado', updated_at = NOW()
                        WHERE torneo_id = ? AND club_id = ?
                          AND status IN ('pendiente', 'pending')
                    ");
                    $stP->execute([$torneoId, $idClub > 0 ? $idClub : null]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('InscripcionPagoService: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Error al actualizar el estatus de pago.'];
        }

        if ($quierePagado && $notificarSiPagado) {
            try {
                InscripcionTorneoNotifier::notificarPagoValidado($pdo, $idUsuario, $torneoId, $idClub, $inscripcionId);
            } catch (Throwable $e) {
                error_log('InscripcionPagoService notify: ' . $e->getMessage());
            }

            return ['ok' => true, 'message' => 'Pago marcado como pagado. Se notificó al atleta.'];
        }

        return [
            'ok' => true,
            'message' => $quierePagado ? 'Pago marcado como pagado.' : 'Marcado como pendiente de pago.',
        ];
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private static function establecerEstatusDirecto(PDO $pdo, int $inscripcionId, int $torneoId, int $estatusNum): array
    {
        if ($inscripcionId <= 0 || $torneoId <= 0) {
            return ['ok' => false, 'message' => 'Parámetros inválidos.'];
        }
        if (!InscritosHelper::isValidEstatus($estatusNum) && $estatusNum !== InscritosHelper::ESTATUS_RETIRADO_NUM_LEGACY) {
            return ['ok' => false, 'message' => 'Estatus no válido.'];
        }
        if ($estatusNum === InscritosHelper::ESTATUS_RETIRADO_NUM_LEGACY) {
            $estatusNum = InscritosHelper::ESTATUS_RETIRADO_NUM;
        }

        $row = self::cargarInscripcion($pdo, $inscripcionId, $torneoId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Inscripción no encontrada.'];
        }

        $pdo->prepare('UPDATE inscritos SET estatus = ? WHERE id = ? AND torneo_id = ?')
            ->execute([$estatusNum, $inscripcionId, $torneoId]);

        if ($estatusNum === InscritosHelper::ESTATUS_RETIRADO_NUM) {
            return ['ok' => true, 'message' => 'Jugador marcado como retirado del torneo.'];
        }
        if ($estatusNum === InscritosHelper::ESTATUS_PENDIENTE_NUM) {
            return ['ok' => true, 'message' => 'Marcado como pendiente de pago.'];
        }

        return ['ok' => true, 'message' => 'Estatus actualizado.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cargarInscripcion(PDO $pdo, int $inscripcionId, int $torneoId): ?array
    {
        $st = $pdo->prepare('
            SELECT i.id, i.id_usuario, i.torneo_id, i.id_club, i.estatus,
                   u.nombre, u.username, u.celular
            FROM inscritos i
            INNER JOIN usuarios u ON u.id = i.id_usuario
            WHERE i.id = ? AND i.torneo_id = ?
            LIMIT 1
        ');
        $st->execute([$inscripcionId, $torneoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function urlWhatsAppRecordatorio(PDO $pdo, array $row, int $torneoId): ?string
    {
        $celular = preg_replace('/\D/', '', (string) ($row['celular'] ?? ''));
        if (strlen($celular) < 10) {
            return null;
        }
        if (strlen($celular) === 10 && $celular[0] === '0') {
            $celular = '58' . substr($celular, 1);
        } elseif (strlen($celular) === 10) {
            $celular = '58' . $celular;
        }

        $payload = InscripcionTorneoNotifier::construirDatosRecordatorioPago(
            $pdo,
            (int) ($row['id_usuario'] ?? 0),
            $torneoId,
            (int) ($row['id_club'] ?? 0),
            (int) ($row['id'] ?? 0)
        );
        if ($payload === null) {
            return null;
        }

        return 'https://wa.me/' . $celular . '?text=' . rawurlencode($payload['mensaje_plano']);
    }

    private static function tablaExiste(PDO $pdo, string $tabla): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$tabla]);

        return (bool) $st->fetchColumn();
    }
}

