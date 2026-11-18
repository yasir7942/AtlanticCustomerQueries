<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\CookieJar;
use App\Support\Database;

/**
 * "Remember me" sign-in tokens (selector/validator pattern).
 *
 * Cookie: aiq_remember = <24-hex selector>.<64-hex validator>. The database stores only
 * SHA-256(validator). A token is accepted only while the account is active and its
 * session_version is unchanged, so a password reset, own password change or deactivation
 * ends remembered sign-ins too. Signing out deletes the token.
 */
final class RememberMe
{
    public const COOKIE = 'aiq_remember';

    public function __construct(
        private Database $db,
        private CookieJar $cookies,
        private array $requestCookies,
        private int $days,
    ) {
    }

    public function days(): int
    {
        return $this->days;
    }

    public function hasCookie(): bool
    {
        return is_string($this->requestCookies[self::COOKIE] ?? null) && $this->requestCookies[self::COOKIE] !== '';
    }

    public function issue(array $user): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + $this->days * 86400;
        $this->db->run(
            'INSERT INTO remember_tokens (user_id, selector, validator_hash, session_version, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $user['id'], $selector, hash('sha256', $validator), (int) $user['session_version'], gmdate('Y-m-d H:i:s', $expires), utc_now()]
        );
        $this->cookies->set(self::COOKIE, $selector . '.' . $validator, $expires);
        // The cookie now in effect for the rest of this request.
        $this->requestCookies[self::COOKIE] = $selector . '.' . $validator;
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM remember_tokens WHERE expires_at < ?', [utc_now()]);
        }
    }

    /**
     * Validate the request's remember cookie and return the user it signs in, or null.
     * Invalid, expired or outdated tokens are deleted and the cookie is cleared.
     */
    public function user(): ?array
    {
        if (!$this->hasCookie()) {
            return null;
        }
        if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', (string) $this->requestCookies[self::COOKIE], $m)) {
            $this->clearCookie();
            return null;
        }
        $token = $this->db->one('SELECT * FROM remember_tokens WHERE selector = ?', [$m[1]]);
        if ($token === null) {
            $this->clearCookie();
            return null;
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) $token['user_id']]);
        $valid = hash_equals($token['validator_hash'], hash('sha256', $m[2]))
            && $token['expires_at'] > utc_now()
            && $user !== null
            && $user['status'] === 'active'
            && (int) $user['session_version'] === (int) $token['session_version'];
        if (!$valid) {
            $this->db->run('DELETE FROM remember_tokens WHERE id = ?', [(int) $token['id']]);
            $this->clearCookie();
            return null;
        }
        $this->db->run('UPDATE remember_tokens SET last_used_at = ? WHERE id = ?', [utc_now(), (int) $token['id']]);
        return $user;
    }

    /** Delete this device's token (sign-out) and clear the cookie. */
    public function forgetCurrent(): void
    {
        if (!$this->hasCookie()) {
            return;
        }
        $selector = explode('.', (string) $this->requestCookies[self::COOKIE])[0];
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            $this->db->run('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        }
        $this->clearCookie();
    }

    private function clearCookie(): void
    {
        $this->cookies->forget(self::COOKIE);
        unset($this->requestCookies[self::COOKIE]);
    }
}
