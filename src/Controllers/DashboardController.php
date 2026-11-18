<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Submissions\InquiryFilter;
use App\Http\Response;
use App\Support\Time;

final class DashboardController
{
    public const PERIODS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'custom' => 'Custom'];

    /**
     * Resolve a period preset (or custom from/to) to local dates in the viewer's zone.
     *
     * @return array{0:string,1:string,2:string} [period, from, to]
     */
    public static function period(Context $ctx): array
    {
        $tz = $ctx->timezone();
        $period = $ctx->request->query('period', '30d');
        $from = $ctx->request->query('from');
        $to = $ctx->request->query('to');
        if ($period === 'custom' || ($from !== '' && !isset($ctx->request->query['period']))) {
            $from = Time::isLocalDate($from) ? $from : Time::daysAgo($tz, 29);
            $to = Time::isLocalDate($to) ? $to : Time::today($tz);
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            // Keep charts bounded.
            if (count(Time::dateSeries($from, $to, 367)) > 366) {
                $from = (new \DateTimeImmutable($to))->modify('-365 days')->format('Y-m-d');
            }
            return ['custom', $from, $to];
        }
        $days = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89][$period] ?? 29;
        $period = array_key_exists($period, self::PERIODS) ? $period : '30d';
        return [$period, Time::daysAgo($tz, $days), Time::today($tz)];
    }

    public function index(Context $ctx): Response
    {
        [$period, $from, $to] = self::period($ctx);
        $filter = new InquiryFilter(from: $from, to: $to);
        $q = $ctx->inquiries();
        $websites = $ctx->scope()->websites($ctx->db);
        $byWebsiteType = $q->countsByWebsiteAndType($filter);
        $websiteRows = [];
        foreach ($websites as $w) {
            $types = array_fill_keys(\App\Domain\Submissions\SubmissionValidator::FORM_TYPES, 0);
            foreach ($byWebsiteType[(int) $w['id']] ?? [] as $type => $n) {
                $types[$type] = $n;
            }
            $websiteRows[] = $w + ['count' => array_sum($types), 'types' => $types];
        }
        usort($websiteRows, static fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return $ctx->render('dashboard', [
            'title' => 'Dashboard',
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'filter' => $filter,
            'total' => $q->count($filter),
            'byType' => $q->countsByType($filter),
            'websiteRows' => $websiteRows,
            'daily' => $q->dailyCountsByType($filter),
            'byStatus' => $q->countsByStatus($filter),
            'recent' => $q->page($filter, 1, 8),
            'noScope' => $ctx->scope()->isEmpty(),
        ]);
    }
}
