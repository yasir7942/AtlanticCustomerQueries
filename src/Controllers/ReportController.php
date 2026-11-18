<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Submissions\InquiryFilter;
use App\Domain\Submissions\SubmissionValidator;
use App\Http\Response;

final class ReportController
{
    private function build(Context $ctx): array
    {
        [$period, $from, $to] = DashboardController::period($ctx);
        $website = $ctx->request->query('website');
        $filter = new InquiryFilter(websiteId: ctype_digit($website) ? (int) $website : null, from: $from, to: $to);
        $q = $ctx->inquiries();
        $matrix = $q->countsByWebsiteAndType($filter);
        $rows = [];
        foreach ($ctx->scope()->websites($ctx->db) as $w) {
            if ($filter->websiteId !== null && (int) $w['id'] !== $filter->websiteId) {
                continue;
            }
            $counts = array_fill_keys(SubmissionValidator::FORM_TYPES, 0);
            foreach ($matrix[(int) $w['id']] ?? [] as $type => $n) {
                $counts[$type] = $n;
            }
            $rows[] = $w + ['counts' => $counts, 'total' => array_sum($counts)];
        }
        $totals = $q->countsByType($filter);
        return [
            'period' => $period, 'from' => $from, 'to' => $to, 'filter' => $filter,
            'rows' => $rows, 'totals' => $totals, 'grand' => array_sum($totals),
            'daily' => $q->dailyCounts($filter),
            'websites' => $ctx->scope()->websites($ctx->db),
        ];
    }

    public function index(Context $ctx): Response
    {
        return $ctx->render('reports/index', ['title' => 'Reports'] + $this->build($ctx));
    }

    public function export(Context $ctx): Response
    {
        $data = $this->build($ctx);
        $ctx->audit->record($ctx->me(), 'report.export', 'report', null, 'Report CSV', 'success', ['from' => $data['from'], 'to' => $data['to']]);
        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Report period (' . $ctx->timezone() . ')', $data['from'], $data['to']], ',', '"', '\\');
        fputcsv($out, [], ',', '"', '\\');
        fputcsv($out, array_merge(['Website', 'Domain'], array_map('ucfirst', SubmissionValidator::FORM_TYPES), ['Total']), ',', '"', '\\');
        foreach ($data['rows'] as $r) {
            fputcsv($out, array_map([InboxController::class, 'csvSafe'], array_merge([$r['name'], $r['domain']], array_values($r['counts']), [$r['total']])), ',', '"', '\\');
        }
        fputcsv($out, array_merge(['All websites', ''], array_values($data['totals']), [$data['grand']]), ',', '"', '\\');
        fputcsv($out, [], ',', '"', '\\');
        fputcsv($out, ['Date (' . $ctx->timezone() . ')', 'Inquiries'], ',', '"', '\\');
        foreach ($data['daily'] as $date => $n) {
            fputcsv($out, [$date, $n], ',', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return Response::download($csv, 'inquiry-report-' . $data['from'] . '-to-' . $data['to'] . '.csv', 'text/csv; charset=utf-8');
    }
}
