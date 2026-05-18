<?php
/**
 * Diagnóstico rápido FTP (local). No subir credenciales a Git.
 *
 * Uso:
 *   php scripts/diagnose_ftp.php
 *   php scripts/diagnose_ftp.php --host=ftp.laestaciondeldominohoy.com --user=github_fvd@laestaciondeldominohoy.com
 *
 * Variables de entorno opcionales: FTP_HOST, FTP_USER, FTP_PASS, FTP_PORT
 */
declare(strict_types=1);

function arg(string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($GLOBALS['argv'] ?? [] as $a) {
        if (str_starts_with($a, $prefix)) {
            return substr($a, strlen($prefix));
        }
    }
    return $default;
}

function prompt(string $label, bool $secret = false): string
{
    if ($secret && DIRECTORY_SEPARATOR === '\\' && function_exists('readline')) {
        // readline no oculta en Windows; fallback abajo
    }
    if ($secret && DIRECTORY_SEPARATOR === '\\') {
        fwrite(STDOUT, $label);
        $line = shell_exec('powershell -NoProfile -Command "$p = Read-Host -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"');
        return trim((string) $line);
    }
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return trim($line === false ? '' : $line);
}

$host = arg('host', getenv('FTP_HOST') ?: 'ftp.laestaciondeldominohoy.com');
$port = (int) (arg('port', getenv('FTP_PORT') ?: '21') ?: '21');
$user = arg('user', getenv('FTP_USER') ?: '') ?: prompt('Usuario FTP: ');
$pass = arg('pass', getenv('FTP_PASS') ?: '') ?: prompt('Contraseña FTP: ', true);

echo "\n=== Diagnóstico FTP ===\n";
echo "Host: {$host}:{$port}\n";
echo "Usuario: {$user}\n\n";

if (!function_exists('ftp_connect')) {
    fwrite(STDERR, "ERROR: La extensión php_ftp no está habilitada en php.ini (extension=ftp).\n");
    exit(2);
}

$timeout = 20;
echo "[1/4] Conectando...\n";
$conn = @ftp_connect($host, $port, $timeout);
if ($conn === false) {
    fwrite(STDERR, "FALLO: No se pudo conectar (firewall, host incorrecto o puerto {$port}).\n");
    exit(1);
}
echo "      OK — socket abierto.\n";

echo "[2/4] Iniciando sesión (USER/PASS)...\n";
$login = @ftp_login($conn, $user, $pass);
if (!$login) {
    @ftp_close($conn);
    fwrite(STDERR, "FALLO: Login rechazado (típico código 530).\n");
    fwrite(STDERR, "      Revisa usuario (formato user@dominio), contraseña y que la cuenta FTP esté activa en cPanel.\n");
    exit(3);
}
echo "      OK — autenticación correcta.\n";

echo "[3/4] Modo pasivo...\n";
@ftp_pasv($conn, true);

echo "[4/4] Directorio actual (PWD)...\n";
$pwd = @ftp_pwd($conn);
if ($pwd === false) {
    fwrite(STDERR, "AVISO: Login OK pero no se pudo leer PWD.\n");
} else {
    echo "      PWD: {$pwd}\n";
}

$list = @ftp_nlist($conn, '.');
if ($list === false) {
    echo "      AVISO: No se pudo listar el directorio raíz (permisos o modo pasivo).\n";
} else {
    $sample = array_slice($list, 0, 8);
    echo "      Muestra de archivos/carpetas (" . count($list) . " entradas): " . implode(', ', $sample);
    if (count($list) > 8) {
        echo ', ...';
    }
    echo "\n";
}

@ftp_close($conn);
echo "\nRESULTADO: Credenciales válidas. Puedes usar estos valores en GitHub Secrets.\n";
exit(0);
