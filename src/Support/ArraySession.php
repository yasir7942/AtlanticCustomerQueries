<?php

declare(strict_types=1);

namespace App\Support;

/** In-memory session used by the test suite. */
final class ArraySession implements Session
{
    private array $data = [];
    private string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function id(): string
    {
        return $this->id;
    }
}
