<?php

declare(strict_types=1);

/**
 * One-time creation of the first Developer account. There is no web signup or setup page.
 *
 * Mode A — direct (local, or Hostinger with SSH):
 *   php bin/create-developer.php --name="Jane Doe" --email=jane@example.com
 *   Prompts for a password; add --temporary to generate one that must be changed at first sign-in.
 *
 * Mode B — SQL for phpMyAdmin (Hostinger without SSH), run on your own computer:
 *   php bin/create-developer.php --sql --name="Jane Doe" --email=jane@example.com
 *   Prints a temporary password and an INSERT statement. Paste the SQL into phpMyAdmin.
 *   The INSERT only takes effect while no Developer exists, so it cannot be replayed later.
 *
 * Both modes refuse to create a second Developer; further accounts are created in the app.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$name = trim((string) ($opts['name'] ?? ''));
$email = strtolower(trim((string) ($opts['email'] ?? '')));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-developer.php --name=\"Full Name\" --email=you@example.com [--temporary] [--sql]\n");
    exit(1);
}

if (isset($opts['sql'])) {
    $temp = App\Auth\Passwords::temporary();
    $hash = App\Auth\Passwords::hash($temp);
    $q = static fn (string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
    echo "-- First Developer for the Inquiry Manager. Run once in phpMyAdmin (SQL tab) on the application database.\n";
    echo "-- Temporary password (shown only here; you must change it at first sign-in):\n--   {$temp}\n\n";
    echo "INSERT INTO users (name, email, password_hash, role, status, timezone, must_change_password, created_at, updated_at, password_changed_at)\n";
    echo "SELECT {$q($name)}, {$q($email)}, {$q($hash)}, 'developer', 'active', 'Asia/Dubai', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()\n";
    echo "FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'developer');\n";
    echo "INSERT INTO audit_log (occurred_at, actor_label, action, target_type, target_label, outcome, details)\n";
    echo "SELECT UTC_TIMESTAMP(), '', 'user.bootstrap_developer', 'user', {$q($email)}, 'success', '{\"via\":\"phpmyadmin\"}'\n";
    echo "FROM DUAL WHERE EXISTS (SELECT 1 FROM users WHERE role = 'developer' AND email = {$q($email)})\n";
    echo "  AND NOT EXISTS (SELECT 1 FROM audit_log WHERE action = 'user.bootstrap_developer');\n";
    fwrite(STDERR, "\nSQL printed above. If phpMyAdmin reports \"0 rows affected\", a Developer already exists.\n");
    exit(0);
}

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql is not loaded. Run: php -d extension=pdo_mysql bin/create-developer.php ...\n");
    exit(1);
}

$config = App\Support\Config::fromEnvFile($root);
$db = App\Support\Database::connect($config->get('db'));
$users = new App\Domain\UserService($db, new App\Domain\AuditLog($db, 'cli'));

if (isset($opts['temporary'])) {
    $password = App\Auth\Passwords::temporary();
    $mustChange = true;
} else {
    fwrite(STDOUT, 'Password (12-72 characters): ');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    fwrite(STDOUT, 'Repeat password: ');
    $confirm = rtrim((string) fgets(STDIN), "\r\n");
    if (($err = App\Auth\Passwords::validate($password, $confirm)) !== null) {
        fwrite(STDERR, $err . "\n");
        exit(1);
    }
    $mustChange = false;
}

try {
    $id = $users->createFirstDeveloper($name, $email, App\Auth\Passwords::hash($password), $mustChange);
} catch (App\Domain\AccessDenied $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
echo "Developer account #{$id} created for {$email}.\n";
if ($mustChange) {
    echo "Temporary password: {$password}\nIt must be changed at first sign-in.\n";
}
