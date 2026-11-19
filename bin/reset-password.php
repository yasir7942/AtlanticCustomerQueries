<?php

declare(strict_types=1);

/**
 * Reset the password of any account from the command line (e.g. a Developer who forgot theirs).
 * Needs shell access to the server; there is no web "forgot password" page by design.
 *
 *   php bin/reset-password.php --email=you@example.com              prompts for a new password
 *   php bin/reset-password.php --email=you@example.com --temporary  prints a one-time password
 *                                                                    that must be changed at sign-in
 *
 * Also signs the account out everywhere (sessions and "Remember me" devices), clears the
 * sign-in throttle for the account and records the reset in the audit log.
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

$email = strtolower(trim((string) ($opts['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/reset-password.php --email=you@example.com [--temporary]\n");
    exit(1);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql is not loaded. Run: php -d extension=pdo_mysql bin/reset-password.php ...\n");
    exit(1);
}

$config = App\Support\Config::fromEnvFile($root);
$db = App\Support\Database::connect($config->get('db'));

$user = $db->one('SELECT id, name, email, role, status FROM users WHERE email = ?', [$email]);
if ($user === null) {
    fwrite(STDERR, "No account with the email {$email}. Existing accounts:\n");
    foreach ($db->all('SELECT email, role, status FROM users ORDER BY role, email') as $u) {
        fwrite(STDERR, "  {$u['email']} ({$u['role']}, {$u['status']})\n");
    }
    exit(2);
}

/** Read a line without echoing it where the terminal supports that. */
$prompt = static function (string $label): string {
    fwrite(STDOUT, $label);
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @shell_exec('stty -echo 2>/dev/null; echo ok') !== null;
    $line = rtrim((string) fgets(STDIN), "\r\n");
    if ($hide) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return $line;
};

if (isset($opts['temporary'])) {
    $password = App\Auth\Passwords::temporary();
    $mustChange = true;
} else {
    $password = $prompt('New password (12-72 characters): ');
    $confirm = $prompt('Repeat password: ');
    if (($err = App\Auth\Passwords::validate($password, $confirm)) !== null) {
        fwrite(STDERR, $err . "\n");
        exit(1);
    }
    $mustChange = false;
}

$now = gmdate('Y-m-d H:i:s');
$db->run(
    'UPDATE users SET password_hash = ?, must_change_password = ?, session_version = session_version + 1,
            password_changed_at = ?, updated_at = ? WHERE id = ?',
    [App\Auth\Passwords::hash($password), $mustChange ? 1 : 0, $now, $now, (int) $user['id']]
);
$db->run('DELETE FROM remember_tokens WHERE user_id = ?', [(int) $user['id']]);
(new App\Support\RateLimiter($db))->clear('login-acct:' . $email, 900);
(new App\Domain\AuditLog($db, 'cli'))->record(null, 'user.password_reset', 'user', (int) $user['id'], $email, 'success', [
    'via' => 'cli',
    'temporary_password' => $mustChange,
]);

echo "Password reset for {$user['name']} <{$email}> ({$user['role']}).\n";
if ($mustChange) {
    echo "Temporary password: {$password}\nIt must be changed at the next sign-in.\n";
}
if ($user['status'] !== 'active') {
    echo "Note: this account is inactive and still cannot sign in. Ask a Developer or Director to reactivate it.\n";
}
