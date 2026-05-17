<?php
/**
 * Envía notificación de datos de acceso al usuario (WhatsApp / web / Telegram).
 * GET: page=users&action=send_access_notification&user_id=&canal=
 */

require_once __DIR__ . '/../../lib/UserAccessNotifier.php';

$userId = (int) ($_GET['user_id'] ?? 0);
$canal = trim((string) ($_GET['canal'] ?? ''));
$returnUrl = trim((string) ($_GET['return'] ?? ''));
if ($returnUrl === '' || preg_match('#^(javascript:|data:)#i', $returnUrl)) {
    $returnUrl = 'index.php?page=users&action=list';
}

if ($userId <= 0) {
    $_SESSION['errors'] = ['Usuario no válido para notificación'];
    header('Location: ' . $returnUrl);
    exit;
}

try {
    $pdo = DB::pdo();
    $actor = Auth::user() ?: [];
    $result = UserAccessNotifier::dispatch($pdo, $userId, $canal, $actor);

    if (!empty($result['redirect'])) {
        header('Location: ' . $result['redirect']);
        exit;
    }

    if (!empty($result['ok'])) {
        $labels = [
            'whatsapp' => 'WhatsApp',
            'web' => 'notificación web (campanita)',
            'telegram' => 'Telegram',
        ];
        $label = $labels[$canal] ?? $canal;
        $_SESSION['success_message'] = "Notificación enviada por {$label} correctamente.";
    } else {
        $_SESSION['errors'] = [$result['error'] ?? 'No se pudo enviar la notificación'];
    }
} catch (Throwable $e) {
    error_log('send_access_notification: ' . $e->getMessage());
    $_SESSION['errors'] = ['Error al enviar la notificación: ' . $e->getMessage()];
}

header('Location: ' . $returnUrl);
exit;
