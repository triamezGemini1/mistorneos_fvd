<?php

declare(strict_types=1);

/**
 * Bloque HTML del QR personal del atleta (torneo_qr_jugador.php) para recibos de pago.
 */
final class ReciboPagoQrHelper
{
    /**
     * Imagen QR + leyenda para insertar en recibo impreso / modal.
     */
    public static function bloqueHtmlQrPersonal(int $torneoId, int $idUsuario): string
    {
        if ($torneoId < 1 || $idUsuario < 1) {
            return '';
        }

        require_once __DIR__ . '/TorneoJugadorQrToken.php';
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        try {
            $token = TorneoJugadorQrToken::encode($torneoId, $idUsuario);
        } catch (Throwable $e) {
            error_log('ReciboPagoQrHelper: ' . $e->getMessage());

            return '';
        }

        $publicBase = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
        if ($publicBase === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $publicBase = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        }

        $url = class_exists('AppHelpers')
            ? AppHelpers::url('torneo_qr_jugador.php', ['t' => $token])
            : $publicBase . '/torneo_qr_jugador.php?t=' . rawurlencode($token);
        $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'size' => '180x180',
            'margin' => 8,
            'data' => $url,
            'format' => 'png',
        ]);
        $qrEsc = htmlspecialchars($qrSrc, ENT_QUOTES, 'UTF-8');

        return <<<HTML
  <div class="recibo-qr-personal border-top pt-3 mt-3 text-center">
    <p class="small fw-semibold text-success mb-2"><i class="fas fa-qrcode me-1"></i>QR personal del atleta</p>
    <img src="{$qrEsc}" alt="QR consulta mesa y resultados" width="180" height="180" class="d-inline-block border rounded bg-white p-1" />
    <p class="small text-muted mt-2 mb-0">Escanee para ver mesa, resultados y clasificación del torneo.</p>
  </div>
HTML;
    }
}
