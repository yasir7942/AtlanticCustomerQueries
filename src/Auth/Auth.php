<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\AuditLog;
use App\Support\Database;
use App\Support\RateLimiter;
use App\Support\Session;

/**
 * Session authentication. On every request the user row is reloaded, so deactivation,
 * password resets (session_version bump) and role changes apply immediately.
 */
final class Auth
{
    private ?array $user = null;
    private bool $resolved = false;
    private ?string $endedReason = null;

    private const LOGIN_WINDOW = 900; // 15 minutes
    private const MAX_FAILURES_PER_ACCOUNT = 5;
    private const MAX_FAILURES_PER_IP = 30;

    public function __construct(
        private Database $db,
        private Session $session,
        private RateLimiter $limiter,
        private AuditLog $audit,
        private int $idleMinutes,
        private ?RememberMe $remember = null,
    ) {
    }

    public function rememberDays(): int
    {
        return $this->remember?->days() ?? 0;
    }

    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        $id = $this->session->get('uid');
        if (!is_int($id)) {
            return $this->fromRememberedDevice();
        }
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        $last = (int) $this->session->get('last_activity', 0);
        if ($row === null || $row['status'] !== 'active') {
            $this->endSession('Your account is no longer active.');
            return $this->fromRememberedDevice();
        }
        if ((int) $row['session_version'] !== (int) $this->session->get('sv')) {
            $this->endSession('Your session ended because the account password or access changed. Please sign in again.');
            return $this->fromRememberedDevice();
        }
        if ($last > 0 && time() - $last > $this->idleMinutes * 60) {
            $this->endSession('You were signed out after a period of inactivity.');
            return $this->fromRememberedDevice();
        }
        $this->session->set('last_activity', time());
        $this->user = $row;
        return $row;
    }

    /**
     * Start a new session from a valid "remember me" token. The token itself re-checks that the
     * account is active and its session_version is unchanged (password reset / deactivation).
     */
    private function fromRememberedDevice(): ?array
    {
        $row = $this->remember?->user();
        if ($row === null) {
            return null;
        }
        $this->login($row);
        $this->audit->record($row, 'auth.login', 'user', $row['id'], $row['email'], 'success', ['via' => 'remember_me']);
        return $row;
    }

    public function endedReason(): ?string
    {
        return $this->endedReason;
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function attempt(string $email, string $password, string $ip, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $generic = 'The email or password is incorrect, or the account is inactive.';
        $ipKey = 'login-ip:' . $ip;
        $acctKey = 'login-acct:' . $email;

        // Only failures count, so many colleagues signing in from one office IP are never blocked.
        if ($this->limiter->tooMany($ipKey, self::MAX_FAILURES_PER_IP, self::LOGIN_WINDOW)
            || $this->limiter->tooMany($acctKey, self::MAX_FAILURES_PER_ACCOUNT, self::LOGIN_WINDOW)) {
            $this->audit->record(null, 'auth.login', 'user', null, $email, 'denied', ['reason' => 'throttled']);
            return ['ok' => false, 'error' => 'Too many sign-in attempts. Wait 15 minutes and try again.'];
        }

        $row = $email !== '' ? $this->db->one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        // Verify against a dummy hash when the account does not exist to keep timing similar.
        $hash = $row['password_hash'] ?? '$2y$12$mOGbzd0zYHdxkwmAKU/yZe0obPZNWbsixEFmKplKJf.xRaTSJfAdG';
        $valid = password_verify($password, $hash);

        if ($row === null || !$valid || $row['status'] !== 'active') {
            $this->limiter->hit($acctKey, self::MAX_FAILURES_PER_ACCOUNT, self::LOGIN_WINDOW);
            $this->limiter->hit($ipKey, self::MAX_FAILURES_PER_IP, self::LOGIN_WINDOW);
            $this->audit->record(
                $row,
                'auth.login',
                'user',
                $row['id'] ?? null,
                $email,
                $row !== null && $valid ? 'denied' : 'failure',
                ['reason' => $row === null ? 'unknown_account' : (!$valid ? 'bad_password' : 'inactive')]
            );
            return ['ok' => false, 'error' => $generic];
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [Passwords::hash($password), $row['id']]);
        }
        $this->limiter->clear($acctKey, self::LOGIN_WINDOW);
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [utc_now(), $row['id']]);
        $this->login($row);
        if ($remember && $this->remember !== null) {
            $this->remember->issue($row);
        }
        $this->audit->record($row, 'auth.login', 'user', $row['id'], $row['email'], 'success', ['remember_me' => $remember && $this->remember !== null]);
        return ['ok' => true];
    }

    /** Establish a fresh session for the user (new session ID, current session_version). */
    public function login(array $row): void
    {
        $this->session->invalidate();
        $this->session->set('uid', (int) $row['id']);
        $this->session->set('sv', (int) $row['session_version']);
        $this->session->set('last_activity', time());
        $this->user = $row;
        $this->resolved = true;
    }

    public function logout(): void
    {
        if ($this->user !== null) {
            $this->audit->record($this->user, 'auth.logout', 'user', $this->user['id'], $this->user['email']);
        }
        // Signing out also forgets this device.
        $this->remember?->forgetCurrent();
        $this->session->invalidate();
        $this->user = null;
    }

    /** Re-read the current user after their own row changed (e.g. own password change). */
    public function refresh(): void
    {
        if ($this->user === null) {
            return;
        }
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) $this->user['id']]);
        if ($row !== null) {
            $this->session->regenerate();
            $this->session->set('sv', (int) $row['session_version']);
            $this->user = $row;
            // Old remembered tokens are now invalid; keep this device remembered with a fresh one.
            if ($this->remember !== null && $this->remember->hasCookie()) {
                $this->remember->forgetCurrent();
                $this->remember->issue($row);
            }
        }
    }

    private function endSession(string $reason): void
    {
        $this->session->invalidate();
        $this->session->set('flash', ['type' => 'info', 'message' => $reason]);
        $this->endedReason = $reason;
        $this->user = null;
    }
}
