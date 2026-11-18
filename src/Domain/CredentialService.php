<?php

declare(strict_types=1);

namespace App\Domain;

use App\Auth\Gate;
use App\Support\Database;

/**
 * Site-scoped API keys: aiq_<16-char key id>_<43-char secret>.
 * Only SHA-256(secret) is stored; the full key is shown once at creation.
 */
final class CredentialService
{
    public const TOKEN_PATTERN = '/^aiq_([a-z0-9]{16})_([A-Za-z0-9_-]{43})$/';

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function forWebsite(int $websiteId): array
    {
        return $this->db->all(
            'SELECT c.*, u.name AS created_by_name FROM api_credentials c
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.website_id = ? ORDER BY c.status = "active" DESC, c.created_at DESC',
            [$websiteId]
        );
    }

    /**
     * Issue a new key. With $rotateGraceHours !== null, existing active keys expire after the grace
     * period (0 = immediately), which is how rotation works.
     *
     * @return array{token:string,credential:array}
     */
    public function issue(array $actor, int $websiteId, string $label = '', ?int $rotateGraceHours = null): array
    {
        if (!Gate::allows($actor, 'websites.connection')) {
            $this->audit->record($actor, 'credential.create', 'website', $websiteId, '', 'denied');
            throw new AccessDenied('Only Developers can manage API keys.');
        }
        $site = $this->db->one('SELECT * FROM websites WHERE id = ?', [$websiteId]) ?? throw new NotFound('Website not found.');
        if ($site['status'] === 'archived') {
            throw new ValidationError(['key' => 'Archived websites cannot receive new keys.']);
        }
        $keyId = self::randomAlnum(16);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = 'aiq_' . $keyId . '_' . $secret;
        $label = mb_substr(trim($label), 0, 80);

        return $this->db->transaction(function () use ($actor, $site, $websiteId, $keyId, $secret, $token, $label, $rotateGraceHours): array {
            $rotated = 0;
            if ($rotateGraceHours !== null) {
                $expires = gmdate('Y-m-d H:i:s', time() + max(0, $rotateGraceHours) * 3600);
                $stmt = $this->db->run(
                    'UPDATE api_credentials SET expires_at = ?
                     WHERE website_id = ? AND status = "active" AND (expires_at IS NULL OR expires_at > ?)',
                    [$expires, $websiteId, $expires]
                );
                $rotated = $stmt->rowCount();
                if ($rotateGraceHours === 0) {
                    $this->db->run(
                        'UPDATE api_credentials SET status = "revoked", revoked_at = ?, revoked_by = ?
                         WHERE website_id = ? AND status = "active"',
                        [utc_now(), (int) $actor['id'], $websiteId]
                    );
                }
            }
            $id = $this->db->insert(
                'INSERT INTO api_credentials (website_id, key_id, secret_hash, last_four, label, status, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, "active", ?, ?)',
                [$websiteId, $keyId, hash('sha256', $secret), substr($secret, -4), $label ?: null, (int) $actor['id'], utc_now()]
            );
            $this->audit->record($actor, $rotateGraceHours !== null ? 'credential.rotate' : 'credential.create', 'website', $websiteId, $site['name'], 'success', [
                'key_id' => $keyId,
                'previous_keys_affected' => $rotated,
                'grace_hours' => $rotateGraceHours,
            ]);
            return ['token' => $token, 'credential' => (array) $this->db->one('SELECT * FROM api_credentials WHERE id = ?', [$id])];
        });
    }

    public function revoke(array $actor, int $websiteId, int $credentialId): void
    {
        if (!Gate::allows($actor, 'websites.connection')) {
            $this->audit->record($actor, 'credential.revoke', 'website', $websiteId, '', 'denied', ['credential_id' => $credentialId]);
            throw new AccessDenied('Only Developers can manage API keys.');
        }
        $cred = $this->db->one('SELECT c.*, w.name AS website_name FROM api_credentials c JOIN websites w ON w.id = c.website_id WHERE c.id = ? AND c.website_id = ?', [$credentialId, $websiteId])
            ?? throw new NotFound('Key not found.');
        if ($cred['status'] === 'revoked') {
            return;
        }
        $this->db->run(
            'UPDATE api_credentials SET status = "revoked", revoked_at = ?, revoked_by = ? WHERE id = ?',
            [utc_now(), (int) $actor['id'], $credentialId]
        );
        $this->audit->record($actor, 'credential.revoke', 'website', $websiteId, $cred['website_name'], 'success', ['key_id' => $cred['key_id']]);
    }

    public function revokeAllForWebsite(array $actor, int $websiteId, string $reason): int
    {
        $stmt = $this->db->run(
            'UPDATE api_credentials SET status = "revoked", revoked_at = ?, revoked_by = ? WHERE website_id = ? AND status = "active"',
            [utc_now(), (int) $actor['id'], $websiteId]
        );
        return $stmt->rowCount();
    }

    /**
     * Resolve a bearer token. Returns the credential joined with its website, or a failure reason.
     *
     * @return array{ok:bool,reason?:string,key_id?:?string,credential?:array}
     */
    public function authenticate(?string $authorization): array
    {
        if ($authorization === null || !preg_match('/^Bearer\s+(\S+)\s*$/i', $authorization, $m)) {
            return ['ok' => false, 'reason' => 'missing_credentials', 'key_id' => null];
        }
        if (!preg_match(self::TOKEN_PATTERN, $m[1], $parts)) {
            return ['ok' => false, 'reason' => 'malformed_credentials', 'key_id' => null];
        }
        [, $keyId, $secret] = $parts;
        $row = $this->db->one(
            'SELECT c.*, w.status AS website_status, w.name AS website_name, w.rate_limit_per_minute
             FROM api_credentials c JOIN websites w ON w.id = c.website_id WHERE c.key_id = ?',
            [$keyId]
        );
        $expected = $row['secret_hash'] ?? str_repeat('0', 64);
        $matches = hash_equals($expected, hash('sha256', $secret));
        if ($row === null || !$matches) {
            return ['ok' => false, 'reason' => 'unknown_key', 'key_id' => $keyId, 'credential' => $row !== null ? ['id' => null, 'website_id' => $row['website_id']] : null];
        }
        if ($row['status'] !== 'active') {
            return ['ok' => false, 'reason' => 'revoked_key', 'key_id' => $keyId, 'credential' => $row];
        }
        if ($row['expires_at'] !== null && $row['expires_at'] <= utc_now()) {
            return ['ok' => false, 'reason' => 'expired_key', 'key_id' => $keyId, 'credential' => $row];
        }
        return ['ok' => true, 'key_id' => $keyId, 'credential' => $row];
    }

    public function touch(int $credentialId, string $ip): void
    {
        $this->db->run('UPDATE api_credentials SET last_used_at = ?, last_used_ip = ? WHERE id = ?', [utc_now(), $ip, $credentialId]);
    }

    private static function randomAlnum(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= $alphabet[random_int(0, 35)];
        }
        return $s;
    }
}
