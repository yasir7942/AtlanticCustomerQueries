<?php

declare(strict_types=1);

namespace App\Domain;

use App\Auth\Gate;
use App\Support\Database;

/**
 * Website visibility for one viewer, resolved fresh on every request (no caching),
 * so revoking an assignment takes effect on the next request.
 */
final class Scope
{
    /** @param list<int>|null $websiteIds null = every website */
    private function __construct(private ?array $websiteIds)
    {
    }

    public static function for(array $user, Database $db): self
    {
        if (Gate::seesAllWebsites($user)) {
            return new self(null);
        }
        $ids = array_map('intval', array_column(
            $db->all('SELECT website_id FROM website_user WHERE user_id = ?', [(int) $user['id']]),
            'website_id'
        ));
        return new self($ids);
    }

    public function isUnrestricted(): bool
    {
        return $this->websiteIds === null;
    }

    public function allows(int $websiteId): bool
    {
        return $this->websiteIds === null || in_array($websiteId, $this->websiteIds, true);
    }

    public function isEmpty(): bool
    {
        return $this->websiteIds === [];
    }

    /**
     * SQL fragment restricting a website_id column. Always returns a valid condition.
     *
     * @return array{0:string,1:list<int>}
     */
    public function sql(string $column): array
    {
        if ($this->websiteIds === null) {
            return ['1=1', []];
        }
        if ($this->websiteIds === []) {
            return ['1=0', []];
        }
        return [$column . ' IN (' . Database::placeholders(count($this->websiteIds)) . ')', $this->websiteIds];
    }

    /** Websites the viewer may see, for filter dropdowns and report rows. */
    public function websites(Database $db): array
    {
        [$where, $params] = $this->sql('id');
        return $db->all("SELECT id, name, domain, status FROM websites WHERE {$where} ORDER BY name", $params);
    }
}
