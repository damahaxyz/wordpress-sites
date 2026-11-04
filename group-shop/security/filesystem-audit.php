<?php
/** Filesystem-only checks: run as www-data for permissions, root for manifest. */
declare(strict_types=1);
$mode = $argv[1] ?? 'check';
$root = '/var/www/html';
$files = [];
$directories = [$root, $root . '/wp-content'];
foreach ([$root, $root . '/wp-content'] as $directory) {
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isFile()) {
            $files[] = $entry->getPathname();
        }
    }
}
foreach (['wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes', 'wp-content/mu-plugins', 'wp-content/languages'] as $relative) {
    $directory = $root . '/' . $relative;
    if (! is_dir($directory)) {
        continue;
    }
    $directories[] = $directory;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        if ($entry->isLink()) {
            fwrite(STDERR, 'Unexpected code symlink: ' . $entry->getPathname() . "\n");
            exit(1);
        }
        if ($entry->isDir()) {
            $directories[] = $entry->getPathname();
        } elseif ($entry->isFile()) {
            $files[] = $entry->getPathname();
        }
    }
}
sort($files, SORT_STRING);
if ($mode === 'manifest') {
    foreach ($files as $file) {
        echo hash_file('sha256', $file) . '  ' . $file . "\n";
    }
    exit;
}
$failures = [];
foreach (array_merge($directories, $files) as $path) {
    if (fileowner($path) !== 0 || is_writable($path) || (fileperms($path) & 0022)) {
        $failures[] = $path;
    }
}
foreach (['wp-content/uploads', 'wp-content/cache'] as $relative) {
    if (! is_writable($root . '/' . $relative)) {
        $failures[] = 'Runtime not writable: ' . $relative;
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/wp-content/uploads', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (preg_match('/\.(?:php\d*|phtml|phar)$/i', $file->getFilename())) {
        $failures[] = 'Executable upload: ' . $file->getPathname();
    }
}
if ($failures) {
    fwrite(STDERR, implode("\n", array_slice($failures, 0, 25)) . "\n");
    exit(1);
}
echo 'Code root-owned and non-writable to web user; uploads/cache writable; no executable uploads.' . "\n";
