<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string,string> lower-cased header names */
    private array $headers;

    /** @var array<string,string> route parameters */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers
     * @param array<string,mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        array $headers = [],
        private readonly ?string $rawBody = null,
        public readonly array $server = [],
        private readonly mixed $bodyStream = null,
        /** @var array<string,string> */
        public readonly array $cookies = [],
    ) {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);
        if ($basePath !== '' && (str_starts_with($path, $basePath . '/') || $path === $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        // Direct access through /public/ when the app sits in a subfolder (XAMPP).
        if (str_starts_with($path, '/public/') || $path === '/public') {
            $path = substr($path, 7);
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, 10);
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        // Authorization can be stripped by CGI/FastCGI; .htaccess copies it into these variables.
        if (!isset($headers['AUTHORIZATION'])) {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_AUTHORIZATION'] as $key) {
                if (!empty($_SERVER[$key])) {
                    $headers['Authorization'] = (string) $_SERVER[$key];
                    break;
                }
            }
        }
        if (!isset($headers['AUTHORIZATION']) && !isset($headers['Authorization']) && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $headers['Authorization'] = (string) $value;
                }
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $headers,
            null,
            $_SERVER,
            'php://input',
            array_filter($_COOKIE, 'is_string'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $v = $this->post[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map('strval', array_filter($v, 'is_scalar')));
    }

    /**
     * Read at most $limit bytes of the raw body. Returns null when the body exceeds the limit.
     */
    public function body(int $limit): ?string
    {
        if ($this->rawBody !== null) {
            return strlen($this->rawBody) > $limit ? null : $this->rawBody;
        }
        if (!is_string($this->bodyStream)) {
            return '';
        }
        $fh = fopen($this->bodyStream, 'rb');
        if ($fh === false) {
            return '';
        }
        $data = stream_get_contents($fh, $limit + 1);
        fclose($fh);
        $data = $data === false ? '' : $data;
        return strlen($data) > $limit ? null : $data;
    }

    public function isSecure(array $trustedProxies = []): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if ($this->fromTrustedProxy($trustedProxies)) {
            return strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
        }
        return false;
    }

    public function ip(array $trustedProxies = []): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        if ($this->fromTrustedProxy($trustedProxies)) {
            $forwarded = (string) $this->header('X-Forwarded-For');
            $parts = array_map('trim', explode(',', $forwarded));
            $candidate = $parts[0] ?? '';
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('User-Agent'), 0, 255);
    }

    private function fromTrustedProxy(array $trustedProxies): bool
    {
        return $trustedProxies !== [] && in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), $trustedProxies, true);
    }
}
