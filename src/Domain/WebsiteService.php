<?php

declare(strict_types=1);

namespace App\Domain;

use App\Auth\Gate;
use App\Support\Database;

final class WebsiteService
{
    public const PLATFORMS = [
        'php' => 'Plain PHP',
        'laravel' => 'Laravel',
        'codeigniter' => 'CodeIgniter 4',
        'nextjs' => 'Next.js (App Router)',
        'wordpress' => 'WordPress',
        'shopify' => 'Shopify',
    ];

    public function __construct(private Database $db, private AuditLog $audit, private CredentialService $credentials)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM websites WHERE id = ?', [$id]);
    }

    public function all(): array
    {
        return $this->db->all(
            'SELECT w.*,
                    (SELECT COUNT(*) FROM submissions s WHERE s.website_id = w.id) AS submission_count,
                    (SELECT COUNT(*) FROM api_credentials c WHERE c.website_id = w.id AND c.status = "active"
                        AND (c.expires_at IS NULL OR c.expires_at > UTC_TIMESTAMP())) AS active_keys
             FROM websites w ORDER BY FIELD(w.status, "active", "inactive", "archived"), w.name'
        );
    }

    public static function normalizeDomain(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = preg_replace('#^[a-z]+://#', '', $d) ?? $d;
        $d = explode('/', $d)[0];
        $d = explode('?', $d)[0];
        return rtrim($d, '.');
    }

    /** Developer only: register a website. */
    public function create(array $actor, array $input): array
    {
        $this->require($actor, 'websites.lifecycle', 'website.create');
        $data = $this->validate($input, null, true, true);
        $now = utc_now();
        $id = $this->db->insert(
            'INSERT INTO websites (name, domain, platform, status, business_contact, business_email, notes, rate_limit_per_minute, created_by, created_at, updated_at)
             VALUES (?, ?, ?, "active", ?, ?, ?, ?, ?, ?, ?)',
            [$data['name'], $data['domain'], $data['platform'], $data['business_contact'], $data['business_email'],
                $data['notes'], $data['rate_limit_per_minute'], (int) $actor['id'], $now, $now]
        );
        $this->audit->record($actor, 'website.create', 'website', $id, $data['name'], 'success', [
            'domain' => $data['domain'], 'platform' => $data['platform'],
        ]);
        return (array) $this->find($id);
    }

    /** Developer or Director: display name and other business details. */
    public function updateBusiness(array $actor, int $id, array $input): array
    {
        $site = $this->find($id) ?? throw new NotFound('Website not found.');
        $this->require($actor, 'websites.edit_business', 'website.update_business', $site);
        $data = $this->validate($input, $id, false, false);
        $this->db->run(
            'UPDATE websites SET name = ?, business_contact = ?, business_email = ?, notes = ?, updated_at = ? WHERE id = ?',
            [$data['name'], $data['business_contact'], $data['business_email'], $data['notes'], utc_now(), $id]
        );
        $changed = array_keys(array_filter([
            'name' => $data['name'] !== $site['name'],
            'business_contact' => $data['business_contact'] !== $site['business_contact'],
            'business_email' => $data['business_email'] !== $site['business_email'],
            'notes' => $data['notes'] !== $site['notes'],
        ]));
        if ($changed) {
            $this->audit->record($actor, 'website.update_business', 'website', $id, $data['name'], 'success', ['changed' => $changed]);
        }
        return (array) $this->find($id);
    }

    /** Developer only: domain, platform, throttle. */
    public function updateConnection(array $actor, int $id, array $input): array
    {
        $site = $this->find($id) ?? throw new NotFound('Website not found.');
        $this->require($actor, 'websites.connection', 'website.update_connection', $site);
        $data = $this->validate($input + ['name' => $site['name']], $id, true, false);
        $this->db->run(
            'UPDATE websites SET domain = ?, platform = ?, rate_limit_per_minute = ?, updated_at = ? WHERE id = ?',
            [$data['domain'], $data['platform'], $data['rate_limit_per_minute'], utc_now(), $id]
        );
        $this->audit->record($actor, 'website.update_connection', 'website', $id, $site['name'], 'success', [
            'domain' => $data['domain'], 'platform' => $data['platform'], 'rate_limit_per_minute' => $data['rate_limit_per_minute'],
        ]);
        return (array) $this->find($id);
    }

    /** Developer only: activate or deactivate. Inactive websites keep their keys but the API rejects them. */
    public function setActive(array $actor, int $id, bool $active): void
    {
        $site = $this->find($id) ?? throw new NotFound('Website not found.');
        $this->require($actor, 'websites.lifecycle', $active ? 'website.activate' : 'website.deactivate', $site);
        if ($site['status'] === 'archived') {
            throw new ValidationError(['status' => 'Archived websites cannot be reactivated. Register the website again.']);
        }
        $this->db->run('UPDATE websites SET status = ?, updated_at = ? WHERE id = ?', [$active ? 'active' : 'inactive', utc_now(), $id]);
        $this->audit->record($actor, $active ? 'website.activate' : 'website.deactivate', 'website', $id, $site['name']);
    }

    /**
     * Developer only: remove a website.
     * - No inquiries: the website, its forms, assignments and keys are deleted.
     * - Has inquiries: archived (history stays readable, credentials revoked, API rejects it),
     *   unless $purgeConfirmation equals the domain, which permanently deletes the history too.
     *
     * @return string 'deleted' | 'archived' | 'purged'
     */
    public function remove(array $actor, int $id, string $purgeConfirmation = ''): string
    {
        $site = $this->find($id) ?? throw new NotFound('Website not found.');
        $this->require($actor, 'websites.lifecycle', 'website.delete', $site);
        $count = (int) $this->db->value('SELECT COUNT(*) FROM submissions WHERE website_id = ?', [$id]);

        return $this->db->transaction(function () use ($actor, $id, $site, $count, $purgeConfirmation): string {
            if ($count === 0 || ($purgeConfirmation !== '' && hash_equals($site['domain'], strtolower(trim($purgeConfirmation))))) {
                $this->db->run('DELETE FROM submissions WHERE website_id = ?', [$id]);
                $this->db->run('DELETE FROM websites WHERE id = ?', [$id]);
                $result = $count === 0 ? 'deleted' : 'purged';
                $this->audit->record($actor, 'website.' . ($count === 0 ? 'delete' : 'purge'), 'website', $id, $site['name'], 'success', [
                    'domain' => $site['domain'], 'inquiries_deleted' => $count,
                ]);
                return $result;
            }
            if ($purgeConfirmation !== '') {
                throw new ValidationError(['confirm_domain' => 'The domain you typed does not match. Nothing was deleted.']);
            }
            $revoked = $this->credentials->revokeAllForWebsite($actor, $id, 'website archived');
            $this->db->run(
                'UPDATE websites SET status = "archived", archived_at = ?, updated_at = ? WHERE id = ?',
                [utc_now(), utc_now(), $id]
            );
            $this->audit->record($actor, 'website.archive', 'website', $id, $site['name'], 'success', [
                'inquiries_kept' => $count, 'keys_revoked' => $revoked,
            ]);
            return 'archived';
        });
    }

    private function require(array $actor, string $ability, string $action, ?array $site = null): void
    {
        if (!Gate::allows($actor, $ability)) {
            $this->audit->record($actor, $action, 'website', $site['id'] ?? null, $site['name'] ?? '', 'denied');
            throw new AccessDenied('This action is limited to Developers.');
        }
    }

    private function validate(array $input, ?int $id, bool $connection, bool $creating): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a display name (up to 120 characters).';
        }
        $out = ['name' => $name];

        $contact = trim((string) ($input['business_contact'] ?? ''));
        $email = strtolower(trim((string) ($input['business_email'] ?? '')));
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($contact) > 120) {
            $errors['business_contact'] = 'Use up to 120 characters.';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
            $errors['business_email'] = 'Enter a valid email address or leave it empty.';
        }
        if (mb_strlen($notes) > 5000) {
            $errors['notes'] = 'Use up to 5000 characters.';
        }
        $out += ['business_contact' => $contact ?: null, 'business_email' => $email ?: null, 'notes' => $notes ?: null];

        if ($connection) {
            $domain = self::normalizeDomain((string) ($input['domain'] ?? ''));
            if (!preg_match('/^(?=.{1,190}$)([a-z0-9](-*[a-z0-9])*)(\.[a-z0-9](-*[a-z0-9])*)*(:\d{1,5})?$/', $domain)) {
                $errors['domain'] = 'Enter a domain such as example.com (no path).';
            } elseif ($this->db->value('SELECT id FROM websites WHERE domain = ? AND id <> ?', [$domain, $id ?? 0]) !== null) {
                $errors['domain'] = 'Another website already uses this domain.';
            }
            $platform = (string) ($input['platform'] ?? '');
            if (!array_key_exists($platform, self::PLATFORMS)) {
                $errors['platform'] = 'Choose a platform.';
            }
            $rate = (int) ($input['rate_limit_per_minute'] ?? 60);
            if ($rate < 1 || $rate > 6000) {
                $errors['rate_limit_per_minute'] = 'Use a value between 1 and 6000.';
            }
            $out += ['domain' => $domain, 'platform' => $platform, 'rate_limit_per_minute' => $rate];
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        return $out;
    }
}
