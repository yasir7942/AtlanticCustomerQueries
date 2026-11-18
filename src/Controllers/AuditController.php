<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;

final class AuditController
{
    private const PER_PAGE = 50;

    /** Business audit log: Developers and Directors. */
    public function index(Context $ctx): Response
    {
        $action = $ctx->request->query('action');
        $where = '1=1';
        $params = [];
        if ($action !== '' && preg_match('/^[a-z_.]{1,64}$/', $action)) {
            $where = 'a.action LIKE ?';
            $params[] = addcslashes($action, '%_') . '%';
        }
        $total = (int) $ctx->db->value("SELECT COUNT(*) FROM audit_log a WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $ctx->request->query('page', '1')));
        $rows = $ctx->db->all(
            "SELECT a.* FROM audit_log a WHERE {$where} ORDER BY a.occurred_at DESC, a.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        $actions = array_column($ctx->db->all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
        return $ctx->render('audit/index', [
            'title' => 'Audit log', 'rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'action' => $action, 'actions' => $actions,
        ]);
    }

    /** Technical connection diagnostics across all websites: Developers only. */
    public function diagnostics(Context $ctx): Response
    {
        $website = $ctx->request->query('website');
        $where = '1=1';
        $params = [];
        if (ctype_digit($website)) {
            $where = 'l.website_id = ?';
            $params[] = (int) $website;
        }
        if ($ctx->request->query('errors') === '1') {
            $where .= ' AND l.http_status >= 400';
        }
        $rows = $ctx->db->all(
            "SELECT l.*, w.name AS website_name FROM api_request_log l LEFT JOIN websites w ON w.id = l.website_id
             WHERE {$where} ORDER BY l.occurred_at DESC, l.id DESC LIMIT 200",
            $params
        );
        $summary = $ctx->db->all(
            'SELECT result_code, http_status, COUNT(*) AS n FROM api_request_log WHERE occurred_at >= ? GROUP BY result_code, http_status ORDER BY n DESC',
            [gmdate('Y-m-d H:i:s', time() - 86400)]
        );
        return $ctx->render('audit/diagnostics', [
            'title' => 'Connection diagnostics', 'rows' => $rows, 'summary' => $summary,
            'websites' => $ctx->db->all('SELECT id, name FROM websites ORDER BY name'),
            'website' => $website, 'errorsOnly' => $ctx->request->query('errors') === '1',
        ]);
    }
}
