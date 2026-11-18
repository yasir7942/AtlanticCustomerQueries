<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cookies queued during a request; the kernel attaches them to the response.
 * All cookies are HttpOnly, SameSite=Lax, scoped to the application path, Secure on HTTPS.
 */
final class CookieJar
{
    /** @var array<string,array{value:string,expires:int,path:string,secure:bool}> */
    private array $queued = [];

    public function __construct(private string $path, private bool $secure)
    {
    }

    public function set(string $name, string $value, int $expires): void
    {
        $this->queued[$name] = ['value' => $value, 'expires' => $expires, 'path' => $this->path, 'secure' => $this->secure];
    }

    public function forget(string $name): void
    {
        $this->set($name, '', time() - 86400);
    }

    /** @return array<string,array{value:string,expires:int,path:string,secure:bool}> */
    public function all(): array
    {
        return $this->queued;
    }
}
