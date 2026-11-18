<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time-zone helpers. The database stores UTC; every viewer sees their own IANA zone.
 */
final class Time
{
    public const DEFAULT_ZONE = 'Asia/Dubai';

    public static function isValidZone(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public static function zone(?string $zone): DateTimeZone
    {
        return new DateTimeZone($zone !== null && self::isValidZone($zone) ? $zone : self::DEFAULT_ZONE);
    }

    /** Format a UTC DATETIME string in the viewer's zone. */
    public static function local(?string $utc, string $zone, string $format = 'd M Y, H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(self::zone($zone))->format($format);
    }

    public static function iso(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public static function isLocalDate(string $date): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    /**
     * Convert a local calendar date to the UTC instant at which that local day starts.
     */
    public static function localDayStartUtc(string $date, string $zone): string
    {
        $dt = new DateTimeImmutable($date . ' 00:00:00', self::zone($zone));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Half-open UTC range [start, end) covering local dates $from..$to inclusive.
     *
     * @return array{0:?string,1:?string}
     */
    public static function utcRange(?string $from, ?string $to, string $zone): array
    {
        $start = $from !== null && self::isLocalDate($from) ? self::localDayStartUtc($from, $zone) : null;
        $end = null;
        if ($to !== null && self::isLocalDate($to)) {
            $next = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $end = self::localDayStartUtc($next, $zone);
        }
        return [$start, $end];
    }

    public static function today(string $zone): string
    {
        return (new DateTimeImmutable('now', self::zone($zone)))->format('Y-m-d');
    }

    public static function daysAgo(string $zone, int $days): string
    {
        return (new DateTimeImmutable('now', self::zone($zone)))->modify('-' . $days . ' days')->format('Y-m-d');
    }

    /** @return list<string> inclusive list of local dates */
    public static function dateSeries(string $from, string $to, int $max = 400): array
    {
        $out = [];
        $d = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($d <= $end && count($out) < $max) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        return $out;
    }

    /**
     * Parse an incoming source timestamp. Accepts ISO 8601 with offset or "Y-m-d H:i:s" (assumed UTC).
     */
    public static function parseToUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 40) {
            return null;
        }
        $formats = [DATE_ATOM, 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.u\Z', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /** @return array<string,list<string>> zones grouped by region for <optgroup> */
    public static function groupedZones(): array
    {
        $groups = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $region = str_contains($id, '/') ? strstr($id, '/', true) : 'Other';
            $groups[$region][] = $id;
        }
        return $groups;
    }
}
