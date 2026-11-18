<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free test harness (no PHPUnit download needed, works offline).
 */

use App\Http\Request;
use App\Http\Response;
use App\Support\ArraySession;

final class TestFailure extends RuntimeException
{
}

final class T
{
    /** @var list<array{string,string,callable}> */
    public static array $tests = [];
    public static \App\App $app;
    public static string $root;

    public static function test(string $group, string $name, callable $fn): void
    {
        self::$tests[] = [$group, $name, $fn];
    }

    public static function db(): App\Support\Database
    {
        return self::$app->db();
    }
}

function test(string $group, string $name, callable $fn): void
{
    T::test($group, $name, $fn);
}

function ok(bool $cond, string $message = 'assertion failed'): void
{
    if (!$cond) {
        throw new TestFailure($message);
    }
}

function eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'expected to find ' . var_export($needle, true));
    }
}

function not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'did not expect to find ' . var_export($needle, true));
    }
}

/**
 * Browser-like client with its own session. POSTs automatically include the CSRF token.
 */
final class Client
{
    public ArraySession $session;
    public ?Response $last = null;
    /** @var array<string,string> persistent cookies (e.g. remember-me) */
    public array $cookies = [];

    public function __construct()
    {
        $this->session = new ArraySession();
    }

    /** Simulate closing and reopening the browser: the session is gone, persistent cookies stay. */
    public function restartBrowser(): void
    {
        $this->session = new ArraySession();
    }

    private function send(Request $request): Response
    {
        $res = T::$app->handle($request, $this->session);
        foreach ($res->cookies as $name => $c) {
            if ($c['value'] === '' || $c['expires'] < time()) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $c['value'];
            }
        }
        return $this->last = $res;
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $query, [], [], null, ['REMOTE_ADDR' => '127.0.0.10'], null, $this->cookies));
    }

    public function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $token = $this->session->get('_csrf');
            if (!is_string($token)) {
                $token = bin2hex(random_bytes(32));
                $this->session->set('_csrf', $token);
            }
            $data['_csrf'] ??= $token;
        }
        return $this->send(new Request('POST', $path, [], $data, [], null, ['REMOTE_ADDR' => '127.0.0.10'], null, $this->cookies));
    }

    public function login(string $email, string $password, bool $remember = false): Response
    {
        $this->get('/login');
        return $this->post('/login', ['email' => $email, 'password' => $password] + ($remember ? ['remember' => '1'] : []));
    }
}

function location(Response $r): string
{
    return $r->headers['Location'] ?? '';
}

/** Call the API in-process. */
function api(?string $token, array|string $payload, array $headers = [], string $ip = '198.51.100.7'): array
{
    $body = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $h = $headers + ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($body)];
    if ($token !== null) {
        $h['Authorization'] = 'Bearer ' . $token;
    }
    $res = T::$app->handle(new Request('POST', '/api/v1/submissions', [], [], $h, $body, ['REMOTE_ADDR' => $ip, 'HTTPS' => 'on']));
    return ['status' => $res->status, 'body' => json_decode($res->body, true), 'headers' => $res->headers];
}

/** Create a user directly (fixture helper). */
function make_user(string $role, string $email, string $password = 'correct horse battery', array $extra = []): array
{
    $db = T::db();
    $now = utc_now();
    $id = $db->insert(
        'INSERT INTO users (name, email, password_hash, role, status, timezone, must_change_password, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$extra['name'] ?? ucfirst($role) . ' ' . strtok($email, '@'), $email, App\Auth\Passwords::hash($password), $role,
            $extra['status'] ?? 'active', $extra['timezone'] ?? 'Asia/Dubai', $extra['must_change'] ?? 0, $now, $now]
    );
    return (array) $db->one('SELECT * FROM users WHERE id = ?', [$id]);
}

function make_website(string $name, string $domain, string $platform = 'php'): array
{
    $db = T::db();
    $dev = $db->one('SELECT * FROM users WHERE role = "developer" ORDER BY id LIMIT 1') ?? make_user('developer', 'fixture-dev@example.test');
    $svc = new App\Domain\WebsiteService($db, new App\Domain\AuditLog($db), new App\Domain\CredentialService($db, new App\Domain\AuditLog($db)));
    return $svc->create($dev, ['name' => $name, 'domain' => $domain, 'platform' => $platform, 'rate_limit_per_minute' => '600']);
}

function make_key(int $websiteId): string
{
    $db = T::db();
    $dev = $db->one('SELECT * FROM users WHERE role = "developer" ORDER BY id LIMIT 1');
    return (new App\Domain\CredentialService($db, new App\Domain\AuditLog($db)))->issue($dev, $websiteId)['token'];
}

function sample_payload(string $uid, array $overrides = []): array
{
    return array_replace([
        'submission_id' => $uid,
        'form_type' => 'contact',
        'full_name' => 'Test Person',
        'email' => 'person@example.test',
        'message' => 'Hello',
    ], $overrides);
}

/** Avoid straddling a fixed rate-limit window boundary in throttle tests. */
function fresh_window(): void
{
    if ((int) gmdate('s') > 52) {
        sleep(61 - (int) gmdate('s'));
    }
}

/**
 * "-d extension=…" arguments for child PHP processes, only for extensions php.ini does not
 * already load (passing them twice prints a startup warning).
 *
 * @return list<string>
 */
function php_ext_args(string ...$extensions): array
{
    static $loaded = null;
    $loaded ??= array_map('strtolower', array_map('trim', explode("\n", (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -m'))));
    $args = [];
    foreach ($extensions as $ext) {
        if (!in_array(strtolower($ext), $loaded, true)) {
            array_push($args, '-d', 'extension=' . $ext);
        }
    }
    return $args;
}
