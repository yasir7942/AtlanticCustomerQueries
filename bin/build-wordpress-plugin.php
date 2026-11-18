<?php

declare(strict_types=1);

/**
 * Build dist/atlantic-inquiry-connector.zip from integrations/wordpress/atlantic-inquiry-connector.
 *   php bin/build-wordpress-plugin.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib/plugin-builder.php';

$root = dirname(__DIR__);
$src = $root . '/integrations/wordpress/atlantic-inquiry-connector';
$out = $root . '/dist/atlantic-inquiry-connector.zip';

// Syntax-check every PHP file before packaging.
$php = PHP_BINARY;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $lines, $code);
        if ($code !== 0) {
            fwrite(STDERR, implode("\n", $lines) . "\n");
            exit(1);
        }
    }
}
$count = build_wordpress_plugin_zip($src, $out);
echo "Built {$out} ({$count} files, " . number_format((int) filesize($out)) . " bytes)\n";
