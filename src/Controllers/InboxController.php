<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotFound;
use App\Domain\Submissions\InquiryFilter;
use App\Domain\Submissions\Presenter;
use App\Http\Response;
use App\Support\Time;

final class InboxController
{
    private const PER_PAGE = 25;

    public function index(Context $ctx): Response
    {
        $filter = InquiryFilter::fromQuery($ctx->request->query);
        $q = $ctx->inquiries();
        $total = $q->count($filter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $ctx->request->query('page', '1')));
        $websites = $ctx->scope()->websites($ctx->db);
        $websiteOutOfScope = $filter->websiteId !== null && !$ctx->scope()->allows($filter->websiteId);
        $canDelete = $ctx->can('inquiries.delete');
        $ctx->session->set('inbox_back', $filter->toQuery() + ($page > 1 ? ['page' => $page] : []));

        return $ctx->render('inbox/index', [
            'title' => 'Inbox',
            'filter' => $filter,
            'rows' => $q->page($filter, $page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'websites' => $websites,
            'websiteOutOfScope' => $websiteOutOfScope,
            'noScope' => $ctx->scope()->isEmpty(),
            'canDelete' => $canDelete,
            'testCount' => $canDelete ? count($q->testIds($filter->websiteId)) : 0,
        ]);
    }

    public function show(Context $ctx): Response
    {
        $row = $ctx->inquiries()->find((int) $ctx->request->params['id']) ?? throw new NotFound();
        return $ctx->render('inbox/show', [
            'title' => 'Inquiry #' . $row['id'],
            'row' => $row,
            'products' => Presenter::products($row['products']),
            'fields' => Presenter::fields($row['custom_fields']),
            'back' => $ctx->session->get('inbox_back'),
        ]);
    }

    public function status(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $row = $ctx->inquiries()->find($id) ?? throw new NotFound();
        $status = $ctx->request->input('status');
        if (!in_array($status, InquiryFilter::STATUSES, true)) {
            $ctx->flash('error', 'Choose a valid status.');
            return $ctx->redirect('/inbox/' . $id);
        }
        if ($status !== $row['status']) {
            $ctx->db->run(
                'UPDATE submissions SET status = ?, status_changed_at = ?, status_changed_by = ? WHERE id = ?',
                [$status, utc_now(), (int) $ctx->me()['id'], $id]
            );
            $ctx->audit->record($ctx->me(), 'inquiry.status', 'inquiry', $id, '#' . $id, 'success', ['from' => $row['status'], 'to' => $status]);
        }
        $ctx->flash('success', 'Status set to ' . Presenter::STATUS_LABELS[$status] . '.');
        return $ctx->redirect('/inbox/' . $id);
    }

    /** Developer only: permanently delete every test inquiry ("test": true), optionally for one website. */
    public function deleteTests(Context $ctx): Response
    {
        $website = $ctx->request->input('website');
        $websiteId = ctype_digit($website) ? (int) $website : null;
        $q = $ctx->inquiries();
        $deleted = $q->deleteIds($q->testIds($websiteId));
        if ($deleted !== []) {
            $ctx->audit->record($ctx->me(), 'inquiry.delete', 'inquiry', null, 'Test inquiries', 'success', [
                'count' => count($deleted), 'tests_only' => true, 'website_id' => $websiteId,
            ]);
        }
        $ctx->flash('success', $deleted === [] ? 'There were no test inquiries to delete.' : self::deletedMessage(count($deleted), 'test '));
        return $ctx->redirect('/inbox', $this->backQuery($ctx));
    }

    /** Developer only: permanently delete the inquiries ticked in the inbox list. */
    public function deleteSelected(Context $ctx): Response
    {
        $ids = array_slice($ctx->request->inputList('ids'), 0, 500);
        $deleted = $ctx->inquiries()->deleteIds(array_map('intval', $ids));
        if ($deleted === []) {
            $ctx->flash('error', 'Tick at least one inquiry to delete.');
        } else {
            $ctx->audit->record($ctx->me(), 'inquiry.delete', 'inquiry', count($deleted) === 1 ? $deleted[0] : null,
                count($deleted) === 1 ? '#' . $deleted[0] : count($deleted) . ' inquiries', 'success', ['count' => count($deleted), 'ids' => $deleted]);
            $ctx->flash('success', self::deletedMessage(count($deleted)));
        }
        return $ctx->redirect('/inbox', $this->backQuery($ctx));
    }

    /** Developer only: permanently delete one inquiry from its detail page. */
    public function destroy(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $ctx->inquiries()->find($id) ?? throw new NotFound();
        $ctx->inquiries()->deleteIds([$id]);
        $ctx->audit->record($ctx->me(), 'inquiry.delete', 'inquiry', $id, '#' . $id, 'success', ['count' => 1, 'ids' => [$id]]);
        $ctx->flash('success', 'Inquiry #' . $id . ' was deleted.');
        return $ctx->redirect('/inbox', $this->backQuery($ctx));
    }

    private static function deletedMessage(int $n, string $kind = ''): string
    {
        return number_format($n) . ' ' . $kind . 'inquir' . ($n === 1 ? 'y was' : 'ies were') . ' deleted.';
    }

    /** Return to the inbox with the filters the user was looking at. */
    private function backQuery(Context $ctx): array
    {
        $back = $ctx->session->get('inbox_back');
        if (!is_array($back)) {
            return [];
        }
        unset($back['page']);
        return array_filter($back, static fn ($v): bool => is_scalar($v));
    }

    /** CSV of the current filter, limited to the viewer's websites. */
    public function export(Context $ctx): Response
    {
        $filter = InquiryFilter::fromQuery($ctx->request->query);
        $q = $ctx->inquiries();
        $tz = $ctx->timezone();
        $ctx->audit->record($ctx->me(), 'inquiry.export', 'inquiry', null, 'CSV export', 'success', ['filter' => $filter->toQuery()]);

        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
        fputcsv($out, ['Inquiry ID', 'Received (' . $tz . ')', 'Received (UTC)', 'Website', 'Form type', 'Form', 'Status', 'Name', 'Company',
            'Email', 'Phone', 'Country', 'City', 'Address', 'Postcode', 'Message', 'Products', 'Custom fields', 'Locale', 'Submission ID', 'Test'], ',', '"', '\\');
        foreach ($q->each($filter) as $r) {
            $products = implode('; ', array_map(static fn (array $p): string => trim(($p['name'] ?? $p['sku'] ?? '') . (isset($p['quantity']) ? ' x ' . Presenter::number($p['quantity']) : '') . (isset($p['unit']) ? ' ' . $p['unit'] : '')), Presenter::products($r['products'])));
            $fields = implode('; ', array_map(static fn (array $f): string => $f['label'] . ': ' . $f['display'], Presenter::fields($r['custom_fields'])));
            fputcsv($out, array_map([self::class, 'csvSafe'], [
                $r['id'], Time::local($r['received_at'], $tz, 'Y-m-d H:i'), $r['received_at'], $r['website_name'], $r['form_type'],
                $r['form_key'], $r['status'], $r['full_name'], $r['company'], $r['email'], $r['phone'], $r['country'], $r['city'],
                $r['address'], $r['postcode'], $r['message'], $products, $fields, $r['locale'], $r['submission_uid'], $r['is_test'] ? 'yes' : '',
            ]), ',', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return Response::download($csv, 'inquiries-' . Time::today($tz) . '.csv', 'text/csv; charset=utf-8');
    }

    /** Neutralize spreadsheet formula injection (=, +, -, @ at the start of a cell). */
    public static function csvSafe(mixed $v): string
    {
        $s = (string) $v;
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $s : $s;
    }
}
