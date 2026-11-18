<?php

declare(strict_types=1);

/**
 * Shared CLI bootstrap. CLI scripts refuse to run from a web server.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php missing. Run: composer install\n");
    exit(1);
}
require $root . '/vendor/autoload.php';

if (!extension_loaded('pdo_mysql') && !in_array('--print-sql', $argv, true)) {
    fwrite(STDERR, "The pdo_mysql extension is not loaded.\n"
        . "XAMPP: enable 'extension=pdo_mysql' in php.ini, or run: php -d extension=pdo_mysql " . implode(' ', $argv) . "\n");
    exit(1);
}

/** @return array<string,string|bool> --key=value and --flag options */
function cli_options(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        }
    }
    return $opts;
}

function cli_prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        shell_exec('stty -echo');
        $value = (string) fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
        return rtrim($value, "\r\n");
    }
    return rtrim((string) fgets(STDIN), "\r\n");
}

require __DIR__ . '/migrations.php';
