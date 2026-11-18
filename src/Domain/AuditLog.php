<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Database;

/**
 * Business audit trail: actor, action, target, outcome, UTC time.
 * Details must never contain passwords, secrets or inquiry bodies; known sensitive keys are dropped.
 */
final class AuditLog
{
    private const FORBIDDEN_KEYS = ['password', 'password_hash', 'secret', 'token', 'api_key', 'key', 'message', 'body'];

    public function __construct(private Database $db, private string $ip = '')
    {
    }

    public function withIp(string $ip): self
    {
        return new self($this->db, $ip);
    }

    public function record(
        ?array $actor,
        string $action,
        string $targetType = '',
        string|int|null $targetId = null,
        string $targetLabel = '',
        string $outcome = 'success',
        array $details = [],
    ): void {
        foreach (array_keys($details) as $k) {
            if (in_array(strtolower((string) $k), self::FORBIDDEN_KEYS, true)) {
                unset($details[$k]);
            }
        }
        $this->db->run(
            'INSERT INTO audit_log (occurred_at, actor_user_id, actor_label, action, target_type, target_id, target_label, outcome, ip, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                utc_now(),
                $actor['id'] ?? null,
                $actor !== null ? mb_substr((string) $actor['email'], 0, 190) : '',
                $action,
                $targetType,
                $targetId === null ? null : (string) $targetId,
                mb_substr($targetLabel, 0, 190),
                $outcome,
                $this->ip,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }
}
