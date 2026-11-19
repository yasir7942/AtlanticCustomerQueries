<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotFound;
use App\Domain\ValidationError;
use App\Domain\WebsiteService;
use App\Http\Response;
use App\Support\Markdown;

/**
 * Developer-only API Connect page: endpoint, keys, diagnostics, guides and connector downloads.
 */
final class ConnectController
{
    public function show(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $site = $ctx->websites->find($id) ?? throw new NotFound();
        $platform = $ctx->request->query('platform', $site['platform']);
        if (!array_key_exists($platform, WebsiteService::PLATFORMS)) {
            $platform = $site['platform'];
        }
        $endpoint = $ctx->absoluteUrl('/api/v1/submissions');
        $root = (string) $ctx->config->get('root');
        $guideFile = $root . '/docs/integrations/' . $platform . '.md';
        $md = new Markdown($root, [
            '{{ENDPOINT}}' => $endpoint,
            '{{WEBSITE_NAME}}' => $site['name'],
            '{{WEBSITE_DOMAIN}}' => $site['domain'],
            '{{APP_URL}}' => (string) $ctx->config->get('url'),
        ]);
        $guide = is_file($guideFile) ? $md->render((string) file_get_contents($guideFile)) : '<p>No guide available.</p>';
        $lastSuccess = $ctx->db->one(
            'SELECT occurred_at, http_status, submission_id FROM api_request_log
             WHERE website_id = ? AND http_status IN (200, 201) ORDER BY occurred_at DESC, id DESC LIMIT 1',
            [$id]
        );
        $recent = $ctx->db->all(
            'SELECT l.*, c.key_id AS cred_key_id FROM api_request_log l LEFT JOIN api_credentials c ON c.id = l.credential_id
             WHERE l.website_id = ? ORDER BY l.occurred_at DESC, l.id DESC LIMIT 15',
            [$id]
        );
        $hasPluginZip = is_file($root . '/dist/atlantic-inquiry-connector.zip');
        $keys = $ctx->credentials->forWebsite($id);
        $activeKey = null;
        foreach ($keys as $k) {
            if ($k['status'] === 'active' && ($k['expires_at'] === null || $k['expires_at'] > utc_now())) {
                $activeKey = $k;
                break;
            }
        }
        $aiPrompt = \App\Domain\IntegrationPrompt::build(
            $site,
            $platform,
            $endpoint,
            $ctx->absoluteUrl('/websites/' . $id . '/connect'),
            (int) $ctx->config->get('api.max_body_bytes'),
            $activeKey ? 'aiq_' . $activeKey['key_id'] . '_…' . $activeKey['last_four'] : null,
        );

        return $ctx->render('websites/connect', [
            'title' => 'API Connect · ' . $site['name'],
            'site' => $site,
            'platform' => $platform,
            'endpoint' => $endpoint,
            'keys' => $keys,
            'aiPrompt' => $aiPrompt,
            'newToken' => $ctx->takeSecret('token:' . $id),
            'lastSuccess' => $lastSuccess,
            'recent' => $recent,
            'guide' => $guide,
            'hasPluginZip' => $hasPluginZip,
            'sample' => self::sampleRequest(),
            'sampleResponse' => self::sampleResponse(),
        ]);
    }

    public function issueKey(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $mode = $ctx->request->input('mode', 'new');
        $grace = match ($mode) {
            'rotate_now' => 0,
            'rotate_24h' => 24,
            default => null,
        };
        try {
            $issued = $ctx->credentials->issue($ctx->me(), $id, $ctx->request->input('label'), $grace);
        } catch (ValidationError $e) {
            $ctx->flash('error', implode(' ', $e->errors));
            return $ctx->redirect('/websites/' . $id . '/connect');
        }
        // Shown exactly once on the next page load, then discarded from the session.
        $ctx->flashSecret('token:' . $id, $issued['token']);
        $ctx->flash('success', match ($mode) {
            'rotate_now' => 'New key created. All previous keys were revoked.',
            'rotate_24h' => 'New key created. Previous keys keep working for 24 hours.',
            default => 'New key created.',
        });
        return $ctx->redirect('/websites/' . $id . '/connect');
    }

    public function revokeKey(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $ctx->credentials->revoke($ctx->me(), $id, (int) $ctx->request->params['key']);
        $ctx->flash('success', 'Key revoked. Requests using it are rejected immediately.');
        return $ctx->redirect('/websites/' . $id . '/connect');
    }

    public function downloadPlugin(Context $ctx): Response
    {
        $root = (string) $ctx->config->get('root');
        $file = $root . '/dist/atlantic-inquiry-connector.zip';
        if (!is_file($file)) {
            require_once $root . '/bin/lib/plugin-builder.php';
            try {
                \build_wordpress_plugin_zip($root . '/integrations/wordpress/atlantic-inquiry-connector', $file);
            } catch (\Throwable $e) {
                $ctx->flash('error', 'The plugin ZIP is missing and could not be built on this server. Upload dist/atlantic-inquiry-connector.zip.');
                return $ctx->redirect('/websites');
            }
        }
        $ctx->audit->record($ctx->me(), 'connector.download', 'connector', null, 'wordpress-plugin');
        return Response::download((string) file_get_contents($file), 'atlantic-inquiry-connector.zip', 'application/zip');
    }

    public static function sampleRequest(): string
    {
        return json_encode([
            'submission_id' => 'contact-2026-11-18-7f3c9a1e',
            'form_type' => 'contact',
            'form_id' => 'contact-page',
            'submitted_at' => '2026-11-18T19:30:00Z',
            'locale' => 'en',
            'full_name' => 'Sara Ahmed',
            'company' => 'Example Trading LLC',
            'email' => 'sara@example.com',
            'phone' => '+971 50 000 0000',
            'country' => 'United Arab Emirates',
            'message' => 'Please call me about bulk pricing.',
            'source' => ['page_url' => 'https://example.com/contact', 'ip' => '203.0.113.10'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function sampleResponse(): string
    {
        return json_encode([
            'ok' => true, 'status' => 'created', 'replayed' => false, 'inquiry_id' => 1042,
            'submission_id' => 'contact-2026-11-18-7f3c9a1e', 'received_at' => '2026-11-18T19:30:02Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
