<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    /** @param array<string,mixed> $values */
    private function __construct(private array $values)
    {
    }

    public static function fromEnvFile(string $root, ?string $envFile = null, array $overrides = []): self
    {
        $env = Env::load($envFile ?? $root . '/.env');
        $get = static fn (string $k, string $d = ''): string => (string) ($overrides[$k] ?? $env[$k] ?? $d);
        $bool = static fn (string $k, bool $d): bool => filter_var($get($k, $d ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);

        $appUrl = rtrim($get('APP_URL', 'http://localhost'), '/');
        $basePath = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?? ''), '/');

        return new self([
            'root' => $root,
            'env' => $get('APP_ENV', 'production'),
            'debug' => $bool('APP_DEBUG', false),
            'name' => $get('APP_NAME', 'Inquiry Manager'),
            'url' => $appUrl,
            'base_path' => $basePath,
            'db' => [
                'host' => $get('DB_HOST', '127.0.0.1'),
                'port' => (int) $get('DB_PORT', '3306'),
                'name' => $get('DB_NAME', 'atlantic_inquiries'),
                'user' => $get('DB_USER', 'root'),
                'pass' => $get('DB_PASS', ''),
                'test_name' => $get('DB_TEST_NAME', ''),
            ],
            'session' => [
                'secure' => $bool('SESSION_SECURE', str_starts_with($appUrl, 'https://')),
                'idle_minutes' => max(5, (int) $get('SESSION_IDLE_MINUTES', '480')),
                // "Remember me" duration in days; 0 hides the checkbox and disables remembered sign-ins.
                'remember_days' => max(0, min(365, (int) $get('REMEMBER_DAYS', '30'))),
                'path' => $root . '/storage/sessions',
            ],
            'api' => [
                'require_https' => $bool('API_REQUIRE_HTTPS', true),
                'max_body_bytes' => max(1024, (int) $get('API_MAX_BODY_BYTES', '65536')),
                'ip_limit_per_minute' => max(10, (int) $get('API_IP_LIMIT_PER_MINUTE', '300')),
            ],
            'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $get('TRUSTED_PROXIES', ''))))),
            'diagnostics_retention_days' => max(1, (int) $get('DIAGNOSTICS_RETENTION_DAYS', '30')),
        ]);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function with(string $key, mixed $value): self
    {
        $copy = $this->values;
        $copy[$key] = $value;
        return new self($copy);
    }
}
