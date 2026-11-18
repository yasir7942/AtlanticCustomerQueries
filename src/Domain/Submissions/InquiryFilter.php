<?php

declare(strict_types=1);

namespace App\Domain\Submissions;

use App\Support\Time;

/**
 * Inbox / dashboard / report filter. Dates are local calendar dates in the viewer's zone.
 */
final class InquiryFilter
{
    public const STATUSES = ['new', 'open', 'closed', 'spam'];

    public function __construct(
        public readonly string $q = '',
        public readonly ?int $websiteId = null,
        public readonly ?string $formType = null,
        public readonly ?string $status = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly bool $includeTests = true,
    ) {
    }

    public static function fromQuery(array $query): self
    {
        $s = static fn (string $k): string => isset($query[$k]) && is_scalar($query[$k]) ? trim((string) $query[$k]) : '';
        $website = $s('website');
        $type = $s('type');
        $status = $s('status');
        $from = $s('from');
        $to = $s('to');
        if ($from !== '' && $to !== '' && Time::isLocalDate($from) && Time::isLocalDate($to) && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return new self(
            mb_substr($s('q'), 0, 200),
            ctype_digit($website) ? (int) $website : null,
            in_array($type, SubmissionValidator::FORM_TYPES, true) ? $type : null,
            in_array($status, self::STATUSES, true) ? $status : null,
            Time::isLocalDate($from) ? $from : null,
            Time::isLocalDate($to) ? $to : null,
            $s('tests') !== 'hide',
        );
    }

    public function with(array $changes): self
    {
        $v = get_object_vars($this);
        foreach ($changes as $k => $val) {
            $v[$k] = $val;
        }
        return new self(...$v);
    }

    /** Query-string parameters for links (e.g. dashboard count -> inbox). */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'website' => $this->websiteId,
            'type' => $this->formType,
            'status' => $this->status,
            'from' => $this->from,
            'to' => $this->to,
            'tests' => $this->includeTests ? null : 'hide',
        ], static fn ($v) => $v !== null && $v !== '');
    }

    public function isActive(): bool
    {
        return $this->q !== '' || $this->websiteId !== null || $this->formType !== null || $this->status !== null
            || $this->from !== null || $this->to !== null || !$this->includeTests;
    }
}
