<?php

declare(strict_types=1);

/**
 * Build an installable WordPress plugin ZIP whose top-level folder is the plugin slug.
 * Uses ZipArchive when available, otherwise PharData (bundled with PHP).
 */
function build_wordpress_plugin_zip(string $sourceDir, string $zipFile): int
{
    $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
    if (!is_dir($sourceDir)) {
        throw new RuntimeException("Plugin source not found: {$sourceDir}");
    }
    $slug = basename($sourceDir);
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile()) {
            $path = str_replace('\\', '/', $f->getPathname());
            $rel = substr($path, strlen($sourceDir) + 1);
            if (preg_match('#(^|/)\.(git|DS_Store)|~$#', $rel)) {
                continue;
            }
            $files[$slug . '/' . $rel] = $path;
        }
    }
    ksort($files);
    if (!is_dir(dirname($zipFile))) {
        mkdir(dirname($zipFile), 0775, true);
    }
    if (is_file($zipFile)) {
        unlink($zipFile);
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$zipFile}");
        }
        $zip->addEmptyDir($slug);
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();
    } else {
        $tmp = $zipFile . '.tmp.zip';
        $phar = new PharData($tmp, 0, null, Phar::ZIP);
        foreach ($files as $name => $path) {
            $phar->addFile($path, $name);
        }
        unset($phar);
        rename($tmp, $zipFile);
    }
    return count($files);
}
