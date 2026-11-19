<?php

declare(strict_types=1);

/**
 * Escape a value for HTML text and attribute contexts.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Current UTC time as a MariaDB DATETIME string.
 */
function utc_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/**
 * Cache-busting version for a file in public/assets: changes whenever the file changes,
 * so browsers fetch new CSS/JS/icons right after a deploy despite the long cache lifetime.
 */
function asset_version(string $file): string
{
    static $cache = [];
    if (!isset($cache[$file])) {
        $mtime = @filemtime(dirname(__DIR__) . '/public/assets/' . basename($file));
        $cache[$file] = $mtime ? \App\App::VERSION . '.' . $mtime : \App\App::VERSION;
    }
    return $cache[$file];
}
