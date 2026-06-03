<?php
/**
 * Mensajes flash de sesión / query → SweetAlert2 en el cliente.
 */
class FvdFlashSwal
{
    /**
     * Lee y limpia mensajes flash (sesión y parámetros GET success/error/warning/info).
     *
     * @return array{success?: string, error?: string, warning?: string, info?: string}
     */
    public static function collect(): array
    {
        $out = [];
        $sessionMap = [
            'success' => ['success', 'success_message'],
            'error'   => ['error', 'error_message'],
            'warning' => ['warning', 'warning_message'],
            'info'    => ['info', 'info_message'],
        ];

        foreach ($sessionMap as $key => $keys) {
            foreach ($keys as $sk) {
                if (!empty($_SESSION[$sk])) {
                    $out[$key] = trim((string) $_SESSION[$sk]);
                    unset($_SESSION[$sk]);
                    break;
                }
            }
        }

        $getMap = [
            'success' => 'success',
            'error'   => 'error',
            'warning' => 'warning',
            'info'    => 'info',
        ];
        foreach ($getMap as $key => $param) {
            if (!isset($out[$key]) && isset($_GET[$param]) && $_GET[$param] !== '') {
                $out[$key] = trim((string) $_GET[$param]);
            }
        }

        return array_filter($out, static fn ($v) => $v !== '');
    }

    /**
     * @param array<string, string>|null $messages Si es null, llama a collect().
     */
    public static function renderInitScript(?array $messages = null): string
    {
        if ($messages === null) {
            $messages = self::collect();
        }
        if ($messages === []) {
            return '';
        }

        $json = json_encode(
            $messages,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return '<script>window.FVD_FLASH=' . $json . ';</script>' . "\n";
    }
}
