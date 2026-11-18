<?php

declare(strict_types=1);

namespace App\Domain\Submissions;

use App\Domain\Scope;
use App\Support\Database;
use App\Support\Time;

/**
 * The single place that turns (scope, filter, time zone) into SQL. Inbox, dashboard,
 * reports and CSV exports all use it, so their numbers always agree.
 */
final class SubmissionQuery
{
    public function __construct(private Database $db, private Scope $scope, private string $timezone)
    {
    }

    /** @return array{0:string,1:list<mixed>} WHERE clause (without "WHERE") and parameters */
    public function where(InquiryFilter $f, string $alias = 's'): array
    {
        [$scopeSql, $params] = $this->scope->sql("{$alias}.website_id");
        $clauses = [$scopeSql];

        if ($f->websiteId !== null) {
            $clauses[] = "{$alias}.website_id = ?";
            $params[] = $f->websiteId;
        }
        if ($f->formType !== null) {
            $clauses[] = "{$alias}.form_type = ?";
            $params[] = $f->formType;
        }
        if ($f->status !== null) {
            $clauses[] = "{$alias}.status = ?";
            $params[] = $f->status;
        }
        if (!$f->includeTests) {
            $clauses[] = "{$alias}.is_test = 0";
        }
        [$start, $end] = Time::utcRange($f->from, $f->to, $this->timezone);
        if ($start !== null) {
            $clauses[] = "{$alias}.received_at >= ?";
            $params[] = $start;
        }
        if ($end !== null) {
            $clauses[] = "{$alias}.received_at < ?";
            $params[] = $end;
        }
        if ($f->q !== '') {
            $like = '%' . addcslashes($f->q, '%_\\') . '%';
            $cols = ['full_name', 'company', 'email', 'phone', 'message', 'city', 'country'];
            $parts = array_map(static fn (string $c): string => "{$alias}.{$c} LIKE ?", $cols);
            // JSON columns are utf8mb4_bin on MariaDB; cast to the connection collation for case-insensitive search.
            foreach (['products', 'custom_fields'] as $jsonCol) {
                $parts[] = "CAST({$alias}.{$jsonCol} AS CHAR) LIKE ?";
                $cols[] = $jsonCol;
            }
            array_push($params, ...array_fill(0, count($cols), $like));
            if (preg_match(SubmissionValidator::UID_PATTERN, $f->q)) {
                $parts[] = "{$alias}.submission_uid = ?";
                $params[] = $f->q;
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [implode(' AND ', $clauses), $params];
    }

    public function count(InquiryFilter $f): int
    {
        [$where, $params] = $this->where($f);
        return (int) $this->db->value("SELECT COUNT(*) FROM submissions s WHERE {$where}", $params);
    }

    /** @return list<array<string,mixed>> */
    public function page(InquiryFilter $f, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($f);
        $offset = max(0, ($page - 1) * $perPage);
        $params[] = $perPage;
        $params[] = $offset;
        return $this->db->all(
            "SELECT s.id, s.website_id, s.form_type, s.received_at, s.full_name, s.company, s.email, s.phone,
                    LEFT(s.message, 400) AS message_excerpt, s.products, s.product_count, s.custom_fields, s.status, s.is_test,
                    s.locale, w.name AS website_name, f.label AS form_label, f.form_key
             FROM submissions s
             JOIN websites w ON w.id = s.website_id
             LEFT JOIN forms f ON f.id = s.form_id
             WHERE {$where}
             ORDER BY s.received_at DESC, s.id DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /** Full record for the detail page, or null when missing or outside scope. */
    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->sql('s.website_id');
        array_unshift($params, $id);
        return $this->db->one(
            "SELECT s.*, w.name AS website_name, w.domain AS website_domain, f.label AS form_label, f.form_key,
                    u.name AS status_changed_by_name
             FROM submissions s
             JOIN websites w ON w.id = s.website_id
             LEFT JOIN forms f ON f.id = s.form_id
             LEFT JOIN users u ON u.id = s.status_changed_by
             WHERE s.id = ? AND {$scopeSql}",
            $params
        );
    }

    /**
     * Iterate all matching rows for CSV export (unbuffered batches).
     *
     * @return \Generator<array<string,mixed>>
     */
    public function each(InquiryFilter $f, int $limit = 50000): \Generator
    {
        $batch = 500;
        $lastReceived = null;
        $lastId = null;
        $seen = 0;
        while ($seen < $limit) {
            [$where, $params] = $this->where($f);
            if ($lastId !== null) {
                $where .= ' AND (s.received_at < ? OR (s.received_at = ? AND s.id < ?))';
                array_push($params, $lastReceived, $lastReceived, $lastId);
            }
            $params[] = $batch;
            $rows = $this->db->all(
                "SELECT s.*, w.name AS website_name, w.domain AS website_domain, f.form_key
                 FROM submissions s JOIN websites w ON w.id = s.website_id LEFT JOIN forms f ON f.id = s.form_id
                 WHERE {$where} ORDER BY s.received_at DESC, s.id DESC LIMIT ?",
                $params
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                yield $row;
                $seen++;
            }
            $last = end($rows);
            $lastReceived = $last['received_at'];
            $lastId = (int) $last['id'];
        }
    }

    /** @return array<int,int> website_id => count */
    public function countsByWebsite(InquiryFilter $f): array
    {
        [$where, $params] = $this->where($f);
        $out = [];
        foreach ($this->db->all("SELECT s.website_id, COUNT(*) AS n FROM submissions s WHERE {$where} GROUP BY s.website_id", $params) as $r) {
            $out[(int) $r['website_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string,int> form_type => count (all four types present) */
    public function countsByType(InquiryFilter $f): array
    {
        [$where, $params] = $this->where($f);
        $out = array_fill_keys(SubmissionValidator::FORM_TYPES, 0);
        foreach ($this->db->all("SELECT s.form_type, COUNT(*) AS n FROM submissions s WHERE {$where} GROUP BY s.form_type", $params) as $r) {
            $out[$r['form_type']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int,array<string,int>> website_id => [form_type => count] */
    public function countsByWebsiteAndType(InquiryFilter $f): array
    {
        [$where, $params] = $this->where($f);
        $out = [];
        foreach ($this->db->all(
            "SELECT s.website_id, s.form_type, COUNT(*) AS n FROM submissions s WHERE {$where} GROUP BY s.website_id, s.form_type",
            $params
        ) as $r) {
            $out[(int) $r['website_id']][$r['form_type']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Daily totals in the viewer's zone, for every date in the filter range (zero-filled).
     * Rows are grouped by 15-minute UTC buckets in SQL, then assigned to local days in PHP.
     * This is exact for every IANA zone (all offsets are multiples of 15 minutes) and needs
     * no MariaDB time-zone tables, which shared hosting often lacks.
     *
     * @return array<string,int> Y-m-d => count
     */
    public function dailyCounts(InquiryFilter $f): array
    {
        $from = $f->from ?? Time::daysAgo($this->timezone, 29);
        $to = $f->to ?? Time::today($this->timezone);
        $series = array_fill_keys(Time::dateSeries($from, $to), 0);
        [$where, $params] = $this->where($f->with(['from' => $from, 'to' => $to]));
        $rows = $this->db->all(
            "SELECT FLOOR(UNIX_TIMESTAMP(s.received_at) / 900) AS bucket, COUNT(*) AS n
             FROM submissions s WHERE {$where} GROUP BY bucket",
            $params
        );
        $zone = Time::zone($this->timezone);
        foreach ($rows as $r) {
            $local = (new \DateTimeImmutable('@' . ((int) $r['bucket'] * 900)))->setTimezone($zone)->format('Y-m-d');
            if (isset($series[$local])) {
                $series[$local] += (int) $r['n'];
            }
        }
        return $series;
    }

    /**
     * Same as dailyCounts(), split by form type (same bucketing, so totals match exactly).
     *
     * @return array<string,array<string,int>> Y-m-d => [contact, quote, sample, other => count]
     */
    public function dailyCountsByType(InquiryFilter $f): array
    {
        $from = $f->from ?? Time::daysAgo($this->timezone, 29);
        $to = $f->to ?? Time::today($this->timezone);
        $empty = array_fill_keys(SubmissionValidator::FORM_TYPES, 0);
        $series = array_fill_keys(Time::dateSeries($from, $to), $empty);
        [$where, $params] = $this->where($f->with(['from' => $from, 'to' => $to]));
        $rows = $this->db->all(
            "SELECT FLOOR(UNIX_TIMESTAMP(s.received_at) / 900) AS bucket, s.form_type, COUNT(*) AS n
             FROM submissions s WHERE {$where} GROUP BY bucket, s.form_type",
            $params
        );
        $zone = Time::zone($this->timezone);
        foreach ($rows as $r) {
            $local = (new \DateTimeImmutable('@' . ((int) $r['bucket'] * 900)))->setTimezone($zone)->format('Y-m-d');
            if (isset($series[$local][$r['form_type']])) {
                $series[$local][$r['form_type']] += (int) $r['n'];
            }
        }
        return $series;
    }

    /** @return array<string,int> status => count (all four statuses present) */
    public function countsByStatus(InquiryFilter $f): array
    {
        [$where, $params] = $this->where($f);
        $out = array_fill_keys(InquiryFilter::STATUSES, 0);
        foreach ($this->db->all("SELECT s.status, COUNT(*) AS n FROM submissions s WHERE {$where} GROUP BY s.status", $params) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
