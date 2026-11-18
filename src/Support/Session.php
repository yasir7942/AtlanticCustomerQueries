<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session storage abstraction. NativeSession is used in production; tests use an in-memory array.
 */
interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Issue a new session ID (keeps data). Call on login and privilege changes. */
    public function regenerate(): void;

    /** Remove all data and issue a new ID. */
    public function invalidate(): void;

    public function id(): string;
}
