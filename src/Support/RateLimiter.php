<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fixed-window rate limiter stored in MariaDB, so it works on shared hosting without Redis or a worker.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Count one hit and report whether the caller is still within the limit.
     *
     * @return array{allowed:bool,hits:int,retry_after:int}
     */
    public function hit(string $key, int $limit, int $windowSeconds): array
    {
        $now = time();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $bucket = hash('sha256', $key . '|' . $windowSeconds);
        $this->db->run(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                                     window_start = VALUES(window_start)',
            [$bucket, $window]
        );
        $hits = (int) $this->db->value('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        if (random_int(1, 200) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }
        return ['allowed' => $hits <= $limit, 'hits' => $hits, 'retry_after' => max(1, $window + $windowSeconds - $now)];
    }

    /** Check the current count without adding a hit. */
    public function tooMany(string $key, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $bucket = hash('sha256', $key . '|' . $windowSeconds);
        $hits = (int) $this->db->value('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $window]);
        return $hits >= $limit;
    }

    public function clear(string $key, int $windowSeconds): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE bucket = ?', [hash('sha256', $key . '|' . $windowSeconds)]);
    }
}
