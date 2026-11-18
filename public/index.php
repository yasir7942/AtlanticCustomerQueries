<?php

declare(strict_types=1);

/*
 * Single public entry point.
 *
 * Locally (XAMPP) the application root is the parent folder of public/.
 * On Hostinger, public_html/ holds only this file, .htaccess and assets/, and the
 * application lives in a sibling folder (default: ../inquiry-app). Adjust the list
 * below if you chose a different folder name.
 */
$candidates = [
    dirname(__DIR__),
    dirname(__DIR__) . '/inquiry-app',
];
$root = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/src/App.php')) {
        $root = $candidate;
        break;
    }
}
if ($root === null || !is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Application not installed: upload the application folder and vendor/ (see README, \"Hostinger deployment\").\n";
    exit;
}

require $root . '/vendor/autoload.php';

$app = App\App::boot($root);
if (!$app->config->get('debug')) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');

$request = App\Http\Request::fromGlobals((string) $app->config->get('base_path'));
$app->handle($request)->send();
