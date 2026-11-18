<?php

// Router for PHP's built-in server: serve real files from public/, everything else via index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
