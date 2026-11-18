<?php

declare(strict_types=1);

namespace App\Support;

final class NativeSession implements Session
{
    public function __construct(Config $config, bool $secureRequest)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = (string) $config->get('session.path');
        if ($path !== '' && is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) ((int) $config->get('session.idle_minutes') * 60));
        session_name('aiq_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => ($config->get('base_path') ?: '') . '/',
            'secure' => (bool) $config->get('session.secure') || $secureRequest,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function id(): string
    {
        return (string) session_id();
    }
}
