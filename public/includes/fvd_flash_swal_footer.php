<?php
/**
 * Script cliente para mensajes flash → SweetAlert2.
 * Definir $fvd_flash_messages (array) antes de incluir, o se llama a FvdFlashSwal::collect().
 *
 * @var array<string, string>|null $fvd_flash_messages
 * @var callable|null $fvd_flash_asset_href function(string $rel): string
 */
if (!class_exists('FvdFlashSwal', false)) {
    require_once __DIR__ . '/../../lib/FvdFlashSwal.php';
}

$messages = isset($fvd_flash_messages) ? $fvd_flash_messages : FvdFlashSwal::collect();
echo FvdFlashSwal::renderInitScript($messages);

$flashJs = 'assets/fvd-flash-swal.js';
if (isset($fvd_flash_asset_href) && is_callable($fvd_flash_asset_href)) {
    $flashJs = $fvd_flash_asset_href($flashJs);
} elseif (class_exists('AppHelpers', false)) {
    $flashJs = AppHelpers::assetHref($flashJs);
}
?>
<script src="<?= htmlspecialchars($flashJs, ENT_QUOTES, 'UTF-8') ?>" defer></script>
