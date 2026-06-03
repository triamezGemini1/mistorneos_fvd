<?php
/**
 * Utilidades compartidas: deploy FTP local y diagnose_ftp.
 */
declare(strict_types=1);

function loadFtpEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }
    return $vars;
}

function parseDeployCliOptions(array $argv): array
{
    $opts = ['dry_run' => false, 'build' => false, 'yes' => false, 'only' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $opts['dry_run'] = true;
        } elseif ($arg === '--build') {
            $opts['build'] = true;
        } elseif ($arg === '--yes' || $arg === '-y') {
            $opts['yes'] = true;
        } elseif (str_starts_with($arg, '--only=')) {
            $opts['only'] = trim(substr($arg, 7), '/');
        }
    }
    return $opts;
}

function normalizeFtpRemoteDir(string $dir): string
{
    $dir = trim(str_replace('\\', '/', $dir));
    if ($dir === '' || $dir === '.') {
        return './';
    }
    if (!str_ends_with($dir, '/')) {
        $dir .= '/';
    }
    return $dir;
}

function formatDeployBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

/** Mismas exclusiones que .github/workflows/deploy-production.yml + ZIP de producción */
function shouldExcludeDeployPath(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);

    if (str_starts_with($rel, '.git/') || $rel === '.git') {
        return true;
    }
    if (str_starts_with($rel, '.github/')) {
        return true;
    }
    if (str_contains($rel, '/node_modules/') || str_starts_with($rel, 'node_modules/')) {
        return true;
    }
    // Solo /dist/ en la raíz del repo (ZIPs locales), NO public/assets/dist/output.css
    if (preg_match('#^dist(/|$)#', $rel)) {
        return true;
    }

    $base = basename($rel);
    if (preg_match('/\.zip$/i', $base)) {
        return true;
    }
    if (preg_match('/^\.env(\.|$)/', $base) || $base === '.env') {
        return true;
    }
    if (stripos($base, 'config') !== false && str_ends_with(strtolower($base), '.php')) {
        return true;
    }

    $excludeDirs = ['.idea', '.vscode', 'coverage', 'tests'];
    foreach (explode('/', $rel) as $part) {
        if (in_array($part, $excludeDirs, true)) {
            return true;
        }
    }

    if (preg_match('#(^|/)database/.+\.sql$#i', $rel)) {
        return true;
    }
    if (preg_match('#(^|/)storage/cache/.+\.json$#i', $rel)) {
        return true;
    }
    if (preg_match('#(^|/)upload/.+#', $rel) && !in_array($rel, ['upload/.gitkeep'], true)) {
        return true;
    }
    if (preg_match('#(^|/)uploads/.+#', $rel) && !in_array($rel, ['uploads/.gitkeep'], true)) {
        return true;
    }

    if ($rel === '.env.ftp.local' || $rel === 'scripts/deploy_ftp_local.php') {
        return true;
    }

    return false;
}

/**
 * @return list<array{rel: string, abs: string, size: int}>
 */
function collectDeployFiles(string $root, ?string $onlyPrefix): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $abs = $fileInfo->getPathname();
        $rel = substr(str_replace('\\', '/', $abs), strlen($root) + 1);
        if ($onlyPrefix !== null && $onlyPrefix !== '') {
            $prefix = str_replace('\\', '/', $onlyPrefix);
            if ($rel !== $prefix && !str_starts_with($rel, $prefix . '/')) {
                continue;
            }
        }
        if (shouldExcludeDeployPath($rel)) {
            continue;
        }
        $files[] = ['rel' => $rel, 'abs' => $abs, 'size' => (int) $fileInfo->getSize()];
    }

    usort($files, static fn (array $a, array $b): int => strcmp($a['rel'], $b['rel']));
    return $files;
}

/**
 * Tras login: crear/navegar a la carpeta remota y devolver el PWD de despliegue.
 */
function ftpResolveDeployRoot($conn, string $remoteDir): ?string
{
    $home = @ftp_pwd($conn);
    if ($home === false) {
        return null;
    }

    $remoteDir = trim(str_replace('\\', '/', $remoteDir), '/');
    if ($remoteDir === '' || $remoteDir === '.') {
        return $home;
    }

    if (!@ftp_chdir($conn, $home)) {
        return null;
    }

    $parts = array_values(array_filter(explode('/', $remoteDir), static fn (string $p): bool => $p !== ''));
    foreach ($parts as $part) {
        if (@ftp_chdir($conn, $part)) {
            continue;
        }
        if (!@ftp_mkdir($conn, $part) || !@ftp_chdir($conn, $part)) {
            return null;
        }
    }

    $pwd = @ftp_pwd($conn);
    return $pwd !== false ? $pwd : null;
}

function ftpUploadFile($conn, string $deployRoot, string $rel, string $localFile): bool
{
    if (!@ftp_chdir($conn, $deployRoot)) {
        return false;
    }

    $rel = str_replace('\\', '/', $rel);
    $dir = dirname($rel);
    if ($dir !== '.' && $dir !== '') {
        foreach (explode('/', $dir) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (@ftp_chdir($conn, $segment)) {
                continue;
            }
            if (!@ftp_mkdir($conn, $segment) || !@ftp_chdir($conn, $segment)) {
                return false;
            }
        }
    }

    return @ftp_put($conn, basename($rel), $localFile, FTP_BINARY);
}
