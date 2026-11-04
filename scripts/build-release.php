<?php

declare(strict_types=1);

/**
 * Build cPanel ZIP into release/ (outside the packaged tree).
 * Never packages: release/, storage/, config/local.php, logs, or previous ZIPs.
 */

$root = dirname(__DIR__);
$outDir = $root . DIRECTORY_SEPARATOR . 'release';
$zipPath = $outDir . DIRECTORY_SEPARATOR . 'rondino-simple-cpanel.zip';
$stagingDir = $outDir . DIRECTORY_SEPARATOR . '.staging-cpanel';

@mkdir($outDir, 0755, true);

// Remove previous staging leftovers only (not the current ZIP until new one is ready)
if (is_dir($stagingDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($stagingDir);
}

$include = [
    'app',
    'views',
    'public',
    'database/rondino-install.sql',
    'config/local.example.php',
    'README-FA.md',
    'start.bat',
    'scripts/check-prereqs.php',
    'scripts/first-run.php',
    'scripts/pick-port.php',
    'scripts/build-release.php',
    '.gitignore',
];

// Hard deny even if include list is later expanded
$denyDirNames = ['release', 'storage', '.git', '.staging-cpanel'];
$denyFileNames = ['local.php', 'app.log'];

function path_denied(string $abs, string $root, array $denyDirNames, array $denyFileNames): bool
{
    $base = basename($abs);
    if (in_array($base, $denyFileNames, true)) {
        return true;
    }
    $rel = substr($abs, strlen($root) + 1);
    $rel = str_replace('\\', '/', (string) $rel);
    $first = explode('/', $rel)[0] ?? '';
    if (in_array($first, $denyDirNames, true)) {
        return true;
    }
    if (str_ends_with(strtolower($base), '.zip')) {
        return true;
    }
    if (str_ends_with(strtolower($base), '.log')) {
        return true;
    }
    return false;
}

function add_to_zip(ZipArchive $zip, string $abs, string $local, string $root, array $denyDirNames, array $denyFileNames): void
{
    if (path_denied($abs, $root, $denyDirNames, $denyFileNames)) {
        return;
    }
    if (is_dir($abs)) {
        $zip->addEmptyDir($local);
        foreach (scandir($abs) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            add_to_zip(
                $zip,
                $abs . DIRECTORY_SEPARATOR . $item,
                $local . '/' . $item,
                $root,
                $denyDirNames,
                $denyFileNames
            );
        }
        return;
    }
    $zip->addFile($abs, $local);
}

$tmpZip = $outDir . DIRECTORY_SEPARATOR . '.rondino-simple-cpanel.build.zip';
@unlink($tmpZip);

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create temp zip\n");
    exit(1);
}

foreach ($include as $rel) {
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!file_exists($abs)) {
        fwrite(STDERR, "Missing: $rel\n");
        @unlink($tmpZip);
        exit(1);
    }
    if (path_denied($abs, $root, $denyDirNames, $denyFileNames)) {
        fwrite(STDERR, "Refusing to package denied path: $rel\n");
        @unlink($tmpZip);
        exit(1);
    }
    $local = 'rondino-simple/' . str_replace('\\', '/', $rel);
    add_to_zip($zip, $abs, rtrim($local, '/'), $root, $denyDirNames, $denyFileNames);
}

$zip->close();

// Verify required entries before replacing authoritative ZIP
$check = new ZipArchive();
if ($check->open($tmpZip) !== true) {
    fwrite(STDERR, "Cannot reopen temp zip\n");
    exit(1);
}
$required = [
    'rondino-simple/public/index.php',
    'rondino-simple/public/css/app.css',
    'rondino-simple/public/js/app.js',
    'rondino-simple/app/bootstrap.php',
    'rondino-simple/app/adapters/Registry.php',
    'rondino-simple/database/rondino-install.sql',
    'rondino-simple/README-FA.md',
    'rondino-simple/start.bat',
    'rondino-simple/config/local.example.php',
];
foreach ($required as $entry) {
    if ($check->locateName($entry) === false) {
        fwrite(STDERR, "Missing required zip entry: $entry\n");
        $check->close();
        @unlink($tmpZip);
        exit(1);
    }
}
// Ensure no accidental packaging of private/local artifacts
for ($i = 0; $i < $check->numFiles; $i++) {
    $name = $check->getNameIndex($i);
    if ($name === false) {
        continue;
    }
    if (str_contains($name, '/config/local.php') || str_contains($name, '/storage/') || str_contains($name, '/release/')) {
        fwrite(STDERR, "Zip contains forbidden path: $name\n");
        $check->close();
        @unlink($tmpZip);
        exit(1);
    }
}
$check->close();

// Replace authoritative ZIP only after verification
if (is_file($zipPath)) {
    @unlink($zipPath);
}
if (!rename($tmpZip, $zipPath)) {
    fwrite(STDERR, "Failed to promote verified zip\n");
    exit(1);
}

$size = filesize($zipPath);
echo $zipPath . PHP_EOL;
echo 'bytes=' . $size . PHP_EOL;
echo 'kb=' . round($size / 1024, 1) . PHP_EOL;
