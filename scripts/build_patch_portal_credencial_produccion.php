<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$zipPath = $distDir . DIRECTORY_SEPARATOR . 'mistorneos_fvd_patch_portal_credencial_' . date('Y-m-d_His') . '.zip';

$files = [
    'config/deploy_build.php',
    'public/user_portal.php',
    'public/entrar_credencial.php',
    'public/generate_credential.php',
];

if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach ($files as $rel) {
    $full = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($full)) {
        $zip->addFile($full, $rel);
    }
}
$zip->addFromString('LEEME_PARCHE_PORTAL_CREDENCIAL.txt', "Extraer en public_html/mistorneos_fvd/\n\n- QR credencial abre entrar_credencial.php?id=...\n- Ranking oficial -> landing-spa.php#ranking-oficial\n- Tras login QR -> user_portal.php?section=perfil\n");
$zip->close();

echo "ZIP: {$zipPath}\n";
