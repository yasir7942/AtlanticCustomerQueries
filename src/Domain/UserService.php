<?php

declare(strict_types=1);

namespace App\Domain;

use App\Auth\Gate;
use App\Auth\Passwords;
use App\Support\Database;
use App\Support\Time;

/**
 * Account management rules. Every method re-checks permissions against the acting user,
 * so controllers cannot bypass them.
 */
final class UserService
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all(
            'SELECT u.*, (SELECT COUNT(*) FROM website_user wu WHERE wu.user_id = u.id) AS website_count
             FROM users u ORDER BY FIELD(u.role, "developer", "director", "user"), u.name'
        );
    }

    /** @return list<int> */
    public function assignedWebsiteIds(int $userId): array
    {
        return array_map('intval', array_column(
            $this->db->all('SELECT website_id FROM website_user WHERE user_id = ?', [$userId]),
            'website_id'
        ));
    }

    /**
     * Create an account. Returns the new user and, when generated, the one-time temporary password.
     *
     * @return array{user:array,temporary_password:?string}
     */
    public function create(array $actor, array $input): array
    {
        $role = (string) ($input['role'] ?? 'user');
        if (!Gate::allows($actor, 'users.manage') || !in_array($role, Gate::grantableRoles($actor), true)) {
            $this->audit->record($actor, 'user.create', 'user', null, (string) ($input['email'] ?? ''), 'denied', ['role' => $role]);
            throw new AccessDenied('You cannot create an account with this role.');
        }
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $timezone = (string) ($input['timezone'] ?? Time::DEFAULT_ZONE);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name (up to 120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->db->value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            $errors['email'] = 'An account with this email already exists.';
        }
        if (!Time::isValidZone($timezone)) {
            $errors['timezone'] = 'Choose a valid time zone.';
        }
        [$hash, $temporary, $mustChange, $pwError] = $this->passwordFromInput($input);
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        $now = utc_now();
        $id = $this->db->insert(
            'INSERT INTO users (name, email, password_hash, role, status, timezone, must_change_password, created_by, created_at, updated_at, password_changed_at)
             VALUES (?, ?, ?, ?, "active", ?, ?, ?, ?, ?, ?)',
            [$name, $email, $hash, $role, $timezone, $mustChange ? 1 : 0, (int) $actor['id'], $now, $now, $now]
        );
        $this->audit->record($actor, 'user.create', 'user', $id, $email, 'success', [
            'role' => $role,
            'temporary_password' => $temporary !== null || $mustChange,
        ]);
        return ['user' => (array) $this->find($id), 'temporary_password' => $temporary];
    }

    /** Update name, email, role and status of another account (or one's own name/email). */
    public function update(array $actor, int $targetId, array $input): array
    {
        $target = $this->find($targetId) ?? throw new NotFound('Account not found.');
        $self = (int) $actor['id'] === $targetId;
        if (!Gate::canManageUser($actor, $target)) {
            $this->audit->record($actor, 'user.update', 'user', $targetId, $target['email'], 'denied');
            throw new AccessDenied('You cannot modify this account.');
        }
        $name = trim((string) ($input['name'] ?? $target['name']));
        $email = strtolower(trim((string) ($input['email'] ?? $target['email'])));
        $role = (string) ($input['role'] ?? $target['role']);
        $status = (string) ($input['status'] ?? $target['status']);

        if (!in_array($role, Gate::grantableRoles($actor), true)) {
            $this->audit->record($actor, 'user.role_change', 'user', $targetId, $target['email'], 'denied', ['to' => $role]);
            throw new AccessDenied('You cannot grant this role.');
        }
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name (up to 120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->db->value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $targetId]) !== null) {
            $errors['email'] = 'Another account already uses this email.';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Choose a valid status.';
        }
        if ($self && $status !== $target['status']) {
            $errors['status'] = 'You cannot change the status of your own account.';
        }
        if ($errors) {
            throw new ValidationError($errors);
        }

        return $this->db->transaction(function () use ($actor, $target, $targetId, $name, $email, $role, $status): array {
            $losesDeveloper = $target['role'] === 'developer' && $target['status'] === 'active'
                && ($role !== 'developer' || $status !== 'active');
            if ($losesDeveloper) {
                $this->assertAnotherActiveDeveloper($targetId);
            }
            $deactivated = $target['status'] === 'active' && $status === 'inactive';
            $this->db->run(
                'UPDATE users SET name = ?, email = ?, role = ?, status = ?, updated_at = ?,
                        session_version = session_version + ? WHERE id = ?',
                [$name, $email, $role, $status, utc_now(), $deactivated ? 1 : 0, $targetId]
            );
            if ($deactivated) {
                $this->db->run('DELETE FROM remember_tokens WHERE user_id = ?', [$targetId]);
            }
            if ($role !== 'user') {
                // Directors and Developers read every website; stale assignments would only confuse.
                $this->db->run('DELETE FROM website_user WHERE user_id = ?', [$targetId]);
            }
            if ($role !== $target['role']) {
                $this->audit->record($actor, 'user.role_change', 'user', $targetId, $email, 'success', ['from' => $target['role'], 'to' => $role]);
            }
            if ($status !== $target['status']) {
                $this->audit->record($actor, $status === 'inactive' ? 'user.deactivate' : 'user.activate', 'user', $targetId, $email);
            }
            if ($name !== $target['name'] || $email !== $target['email']) {
                $this->audit->record($actor, 'user.update', 'user', $targetId, $email, 'success', [
                    'changed' => array_values(array_filter([
                        $name !== $target['name'] ? 'name' : null,
                        $email !== $target['email'] ? 'email' : null,
                    ])),
                ]);
            }
            return (array) $this->find($targetId);
        });
    }

    /**
     * Reset another account's password. Invalidates all of that account's sessions.
     *
     * @return ?string generated temporary password (shown once)
     */
    public function resetPassword(array $actor, int $targetId, array $input): ?string
    {
        $target = $this->find($targetId) ?? throw new NotFound('Account not found.');
        if (!Gate::canManageUser($actor, $target)) {
            $this->audit->record($actor, 'user.password_reset', 'user', $targetId, $target['email'], 'denied');
            throw new AccessDenied('You cannot reset the password of this account.');
        }
        [$hash, $temporary, $mustChange, $error] = $this->passwordFromInput($input);
        if ($error !== null) {
            throw new ValidationError(['password' => $error]);
        }
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = ?, session_version = session_version + 1,
                    password_changed_at = ?, updated_at = ? WHERE id = ?',
            [$hash, $mustChange ? 1 : 0, utc_now(), utc_now(), $targetId]
        );
        $this->db->run('DELETE FROM remember_tokens WHERE user_id = ?', [$targetId]);
        $this->audit->record($actor, 'user.password_reset', 'user', $targetId, $target['email'], 'success', [
            'temporary_password' => $temporary !== null || $mustChange,
        ]);
        return $temporary;
    }

    /**
     * Replace the website assignments of a User account.
     *
     * @param list<int> $websiteIds
     */
    public function setAssignments(array $actor, int $targetId, array $websiteIds): void
    {
        $target = $this->find($targetId) ?? throw new NotFound('Account not found.');
        if (!Gate::allows($actor, 'websites.assign') || !Gate::canManageUser($actor, $target)) {
            $this->audit->record($actor, 'user.assignments', 'user', $targetId, $target['email'], 'denied');
            throw new AccessDenied('You cannot change website assignments for this account.');
        }
        if ($target['role'] !== 'user') {
            throw new ValidationError(['websites' => 'Directors and Developers already see every website.']);
        }
        $websiteIds = array_values(array_unique(array_map('intval', $websiteIds)));
        $valid = $websiteIds === [] ? [] : array_map('intval', array_column($this->db->all(
            'SELECT id FROM websites WHERE id IN (' . Database::placeholders(count($websiteIds)) . ')',
            $websiteIds
        ), 'id'));
        $current = $this->assignedWebsiteIds($targetId);
        $add = array_values(array_diff($valid, $current));
        $remove = array_values(array_diff($current, $valid));

        $this->db->transaction(function () use ($actor, $targetId, $target, $add, $remove): void {
            foreach ($add as $wid) {
                $this->db->run(
                    'INSERT IGNORE INTO website_user (user_id, website_id, assigned_by, created_at) VALUES (?, ?, ?, ?)',
                    [$targetId, $wid, (int) $actor['id'], utc_now()]
                );
            }
            if ($remove) {
                $this->db->run(
                    'DELETE FROM website_user WHERE user_id = ? AND website_id IN (' . Database::placeholders(count($remove)) . ')',
                    array_merge([$targetId], $remove)
                );
            }
            if ($add || $remove) {
                $this->audit->record($actor, 'user.assignments', 'user', $targetId, $target['email'], 'success', [
                    'added_website_ids' => $add,
                    'removed_website_ids' => $remove,
                ]);
            }
        });
    }

    /** Change one's own password (also completes a forced temporary-password change). */
    public function changeOwnPassword(array $user, string $current, string $new, string $confirm): void
    {
        $row = $this->find((int) $user['id']) ?? throw new NotFound('Account not found.');
        if (!password_verify($current, $row['password_hash'])) {
            $this->audit->record($user, 'user.password_change', 'user', $user['id'], $user['email'], 'failure', ['reason' => 'bad_current_password']);
            throw new ValidationError(['current_password' => 'The current password is incorrect.']);
        }
        if (($error = Passwords::validate($new, $confirm)) !== null) {
            throw new ValidationError(['password' => $error]);
        }
        if (password_verify($new, $row['password_hash'])) {
            throw new ValidationError(['password' => 'Choose a password different from the current one.']);
        }
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1,
                    password_changed_at = ?, updated_at = ? WHERE id = ?',
            [Passwords::hash($new), utc_now(), utc_now(), (int) $user['id']]
        );
        // Every remembered device is signed out; the current one is re-remembered by Auth::refresh().
        $this->db->run('DELETE FROM remember_tokens WHERE user_id = ?', [(int) $user['id']]);
        $this->audit->record($user, 'user.password_change', 'user', $user['id'], $user['email']);
    }

    public function updateProfile(array $user, string $name, string $timezone): void
    {
        $errors = [];
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name (up to 120 characters).';
        }
        if (!Time::isValidZone($timezone)) {
            $errors['timezone'] = 'Choose a valid time zone.';
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        $this->db->run('UPDATE users SET name = ?, timezone = ?, updated_at = ? WHERE id = ?', [$name, $timezone, utc_now(), (int) $user['id']]);
        if ($timezone !== $user['timezone'] || $name !== $user['name']) {
            $this->audit->record($user, 'profile.update', 'user', $user['id'], $user['email'], 'success', ['timezone' => $timezone]);
        }
    }

    /**
     * First Developer bootstrap used by bin/create-developer.php (CLI only).
     */
    public function createFirstDeveloper(string $name, string $email, string $passwordHash, bool $mustChange): int
    {
        return $this->db->transaction(function () use ($name, $email, $passwordHash, $mustChange): int {
            $existing = (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role = "developer" FOR UPDATE');
            if ($existing > 0) {
                throw new AccessDenied('A Developer account already exists. Create further accounts in the application.');
            }
            $now = utc_now();
            $id = $this->db->insert(
                'INSERT INTO users (name, email, password_hash, role, status, timezone, must_change_password, created_at, updated_at, password_changed_at)
                 VALUES (?, ?, ?, "developer", "active", ?, ?, ?, ?, ?)',
                [$name, strtolower($email), $passwordHash, Time::DEFAULT_ZONE, $mustChange ? 1 : 0, $now, $now, $now]
            );
            $this->audit->record(null, 'user.bootstrap_developer', 'user', $id, strtolower($email), 'success', ['via' => 'cli']);
            return $id;
        });
    }

    private function assertAnotherActiveDeveloper(int $excludingId): void
    {
        $others = (int) $this->db->value(
            'SELECT COUNT(*) FROM users WHERE role = "developer" AND status = "active" AND id <> ? FOR UPDATE',
            [$excludingId]
        );
        if ($others === 0) {
            throw new ValidationError(['role' => 'This is the last active Developer. Create or activate another Developer first.']);
        }
    }

    /**
     * Interpret the password section of create/reset forms.
     * mode=temporary: generate a password, force change at next sign-in.
     * mode=set: use the supplied password; optional require_change flag.
     *
     * @return array{0:string,1:?string,2:bool,3:?string} [hash, temporary, mustChange, error]
     */
    private function passwordFromInput(array $input): array
    {
        $mode = (string) ($input['password_mode'] ?? 'temporary');
        if ($mode === 'temporary') {
            $temp = Passwords::temporary();
            return [Passwords::hash($temp), $temp, true, null];
        }
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        if (($error = Passwords::validate($password, $confirm)) !== null) {
            return ['', null, false, $error];
        }
        return [Passwords::hash($password), null, !empty($input['require_change']), null];
    }
}
