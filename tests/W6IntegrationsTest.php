<?php

declare(strict_types=1);

/*
 * Workflow 6 + end-to-end checks over real HTTP (PHP built-in server):
 * plain PHP connector, WordPress plugin ZIP in a WordPress stand-in, Shopify app proxy relay,
 * Next.js route handler (Node), and Apache .htaccess protection when XAMPP is running.
 */

$G = 'Workflow 6 / E2E over HTTP: connectors, WordPress ZIP, Shopify proxy, Next.js';

final class HttpServer
{
    /** @var resource */
    private $proc;

    public function __construct(public int $port, string $docroot, ?string $router, array $env)
    {
        $cmd = [PHP_BINARY, ...php_ext_args('pdo_mysql'), '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, '-t', $docroot];
        if ($router !== null) {
            $cmd[] = $router;
        }
        $log = sys_get_temp_dir() . '/aiq-server-' . $port . '.log';
        $this->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $docroot, array_merge(getenv(), $env));
        if (!is_resource($this->proc)) {
            throw new RuntimeException('Could not start php -S');
        }
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException("Server on port {$port} did not start; see {$log}");
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string) $out, 'headers' => $respHeaders];
}

$w6 = new stdClass();

test($G, 'start the application on PHP built-in server against the test database', function () use ($w6) {
    $port = 8765;
    $w6->server = new HttpServer($port, T::$root . '/public', T::$root . '/tests/router.php', [
        'DB_NAME' => $GLOBALS['TEST_DB_NAME'],
        'APP_URL' => 'http://127.0.0.1:' . $port,
        'APP_DEBUG' => 'false',
        'API_REQUIRE_HTTPS' => 'false',
    ]);
    $w6->endpoint = $w6->server->url('/api/v1/submissions');
    $r = http_request('GET', $w6->server->url('/login'));
    eq(200, $r['status']);
    contains('Sign in', $r['body']);
    contains("frame-ancestors 'none'", $r['headers']['content-security-policy'] ?? '');
    contains('HttpOnly', $r['headers']['set-cookie'] ?? '', 'session cookie HttpOnly');
    contains('SameSite=Lax', $r['headers']['set-cookie'] ?? '');
    eq(200, http_request('GET', $w6->server->url('/assets/app.css'))['status']);
    $site = make_website('E2E PHP Site', 'e2e-php.example');
    $w6->phpSite = $site;
    $w6->phpKey = make_key((int) $site['id']);
});

test($G, 'plain PHP connector: send, timeout-style retry replays, validation errors, offline outbox', function () use ($w6) {
    require_once T::$root . '/integrations/php/InquiryClient.php';
    $client = new InquiryClient($w6->endpoint, $w6->phpKey, 10, 3);
    $payload = ['submission_id' => InquiryClient::newSubmissionId('e2e'), 'form_type' => 'quote', 'full_name' => 'HTTP Client',
        'products' => [['name' => 'Valve', 'quantity' => 2]], 'message' => 'Over real HTTP'];
    $first = $client->send($payload);
    eq(201, $first->status, (string) $first->error);
    $again = $client->send($payload);
    eq(200, $again->status);
    eq($first->inquiryId(), $again->inquiryId());
    eq(true, $again->body['replayed']);
    $bad = $client->send(['submission_id' => 'e2e-bad-1', 'form_type' => 'quote', 'email' => 'nope']);
    eq(422, $bad->status);
    eq(false, $bad->retryable);
    ok(isset($bad->fieldErrors()['email']));
    $unauth = (new InquiryClient($w6->endpoint, 'aiq_xxxxxxxxxxxxxxxx_' . str_repeat('x', 43)))->send($payload);
    eq(401, $unauth->status);
    // Offline: network failure is retryable; the outbox later delivers it exactly once.
    $offline = (new InquiryClient('http://127.0.0.1:9/api/v1/submissions', $w6->phpKey, 2, 2))->send(['submission_id' => 'e2e-outbox-1', 'form_type' => 'contact', 'full_name' => 'Outbox']);
    eq(true, $offline->retryable);
    $dir = sys_get_temp_dir() . '/aiq-outbox-' . bin2hex(random_bytes(4));
    $box = new InquiryOutbox($dir);
    $box->save(['submission_id' => 'e2e-outbox-1', 'form_type' => 'contact', 'full_name' => 'Outbox'], $offline);
    foreach (glob($dir . '/*.json') as $f) {
        $rec = json_decode(file_get_contents($f), true);
        $rec['next_attempt'] = 0;
        file_put_contents($f, json_encode($rec));
    }
    eq(['sent' => 1, 'failed' => 0, 'pending' => 0], $box->retryDue($client));
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE submission_uid = "e2e-outbox-1"'));
    // Authorization header survived the real HTTP server.
    ok((int) T::db()->value('SELECT COUNT(*) FROM api_request_log WHERE http_status = 201 AND website_id = ?', [$w6->phpSite['id']]) >= 2);
});

test($G, 'plain PHP send-test.php script confirms the connection from the command line', function () use ($w6) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(T::$root . '/integrations/php/send-test.php');
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['INQUIRY_API_URL' => $w6->endpoint, 'INQUIRY_API_KEY' => $w6->phpKey]));
    $out = stream_get_contents($pipes[1]);
    $code = proc_close($proc);
    eq(0, $code, (string) $out);
    contains('HTTP 201', (string) $out);
    contains('HTTP 200', (string) $out);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE is_test = 1 AND form_id IN (SELECT id FROM forms WHERE form_key = "connection-test")'));
});

test($G, 'WordPress plugin ZIP: structure, lint and documented path to receipt (WordPress stand-in)', function () use ($w6) {
    $zipFile = T::$root . '/dist/atlantic-inquiry-connector.zip';
    build_wordpress_plugin_zip(T::$root . '/integrations/wordpress/atlantic-inquiry-connector', $zipFile);
    $zip = new ZipArchive();
    eq(true, $zip->open($zipFile));
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    foreach ($names as $n) {
        ok(str_starts_with($n, 'atlantic-inquiry-connector/'), "single top-level folder: {$n}");
    }
    ok(in_array('atlantic-inquiry-connector/atlantic-inquiry-connector.php', $names, true));
    ok(in_array('atlantic-inquiry-connector/uninstall.php', $names, true));

    $site = make_website('E2E WordPress', 'e2e-wp.example', 'wordpress');
    $key = make_key((int) $site['id']);
    $work = sys_get_temp_dir() . '/aiq-wp-' . bin2hex(random_bytes(4));
    mkdir($work);
    $cmd = [PHP_BINARY, ...php_ext_args('pdo_mysql', 'zip'), T::$root . '/tests/wordpress/harness.php', $w6->endpoint, $key, $GLOBALS['TEST_DB_NAME'], $work];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($proc);
    $r = json_decode((string) $stdout, true);
    ok(is_array($r), 'harness output: ' . $stdout . $stderr);
    $w6->wp = $r;

    foreach (['zip_top_folder', 'plugin_header', 'outbox_table_created', 'cron_scheduled', 'duplicate_rule_rejected', 'blank_key_keeps_stored_key',
                 'invalid_key_rejected', 'masked_key_hides_secret', 'http_endpoint_rejected', 'cf7_outbox_empty_after_success',
                 'cf7_sent_idempotency_header', 'unconfigured_form_ignored', 'offline_row_pending', 'outbox_empty_after_cron', 'invalid_marked_failed'] as $flag) {
        eq(true, $r[$flag] ?? null, $flag);
    }
    eq(4, count($r['hooks']), 'CF7 + Elementor hooks registered');
    eq(true, $r['cf7']['ok']);
    eq(true, $r['elementor']['ok']);
    eq(true, $r['helper']['ok']);
    eq(true, $r['offline_queued']);
    eq(1, $r['cron_processed']);
    eq(422, $r['invalid_status']);
    eq(0, $r['double_forward_guard_requests'] - 1, 'plugin forwards once, snippet skips the same form');
    eq(1, $r['snippet_requests'], 'snippet forwards when the plugin rule is off');
    eq(201, $r['snippet_status']);

    // Verify what arrived in the Inquiry Manager.
    $cf7 = T::db()->one('SELECT * FROM submissions WHERE id = ?', [$r['cf7']['inquiry_id']]);
    eq('CF7 Visitor', $cf7['full_name']);
    eq((int) $site['id'], (int) $cf7['website_id']);
    contains('مرحبا', $cf7['message']);
    eq(0, (int) $cf7['source_email_sent'], 'CF7 mail_failed recorded as not sent');
    eq('203.0.113.50', $cf7['source_ip']);
    $fields = json_decode($cf7['custom_fields'], true);
    eq('Callback', $fields['Subject']['value']);
    eq(['Morning', 'Evening'], $fields['preferred-time']['value']);
    ok(!isset($fields['your-consent']) && !isset($fields['g-recaptcha-response']) && !isset($fields['_wpcf7_unit_tag']), 'ignored/internal fields dropped');
    $el = T::db()->one('SELECT * FROM submissions WHERE id = ?', [$r['elementor']['inquiry_id']]);
    eq('quote', $el['form_type']);
    $products = json_decode($el['products'], true);
    eq([['name' => 'Gate valve DN80', 'quantity' => 25], ['name' => 'Flange kit', 'quantity' => 1.5]], $products);
    eq(1, (int) $el['source_email_sent'], 'Elementor mail_sent recorded');
    eq('Sharjah', json_decode($el['custom_fields'], true)['Delivery city']['value']);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE submission_uid = "wp-outbox-test-1"'));
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE website_id = ? AND full_name = "Guard Test"', [$site['id']]));
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE website_id = ? AND full_name = "Snippet Path"', [$site['id']]));
    T::db()->run('DROP TABLE IF EXISTS wptest_aiq_outbox');
});

test($G, 'Shopify app proxy relay: verifies Shopify signature, forwards once, rejects tampering', function () use ($w6) {
    $site = make_website('E2E Shopify', 'e2e-shop.example', 'shopify');
    $key = make_key((int) $site['id']);
    $tmp = sys_get_temp_dir() . '/aiq-shopify-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $secret = 'test-client-secret';
    file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
        'shopify_client_secret' => $secret,
        'shop_domain' => 'atlantic-test.myshopify.com',
        'inquiry_api_url' => $w6->endpoint,
        'inquiry_api_key' => $key,
        'outbox_dir' => $tmp . '/outbox',
        'client_file' => T::$root . '/integrations/php/InquiryClient.php',
    ], true) . ';');
    $proxy = new HttpServer(8766, T::$root . '/integrations/shopify/app-proxy', null, ['SHOPIFY_PROXY_CONFIG' => $tmp . '/config.php']);

    $sign = function (array $params) use ($secret): string {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        sort($parts);
        $params['signature'] = hash_hmac('sha256', implode('', $parts), $secret);
        return http_build_query($params);
    };
    $params = ['shop' => 'atlantic-test.myshopify.com', 'logged_in_customer_id' => '', 'path_prefix' => '/apps/inquiries', 'timestamp' => (string) time()];
    $form = http_build_query([
        'inquiry_type' => 'quote', 'locale' => 'en', 'inquiry_token' => '6f1c2a4e-8b7d-4e21-9a3c-5d6e7f8091ab',
        'contact' => ['name' => 'Shop Visitor', 'email' => 'shop@example.test', 'phone' => '+971 50 111 2222', 'body' => 'Bulk order please', 'website' => ''],
        'products' => [['title' => 'Copper Tap', 'sku' => 'CT-01', 'variant' => 'Matte', 'quantity' => '12']],
        'fields' => ['Needed by' => '2026-12-20'],
    ]);
    $post = fn (string $qs, string $body) => http_request('POST', $proxy->url('/submit?' . $qs), ['Content-Type' => 'application/x-www-form-urlencoded'], $body);

    $ok = $post($sign($params), $form);
    eq(200, $ok['status'], $ok['body']);
    contains('application/liquid', $ok['headers']['content-type'] ?? '');
    contains('Thank you', $ok['body']);
    not_contains('Shop Visitor', $ok['body'], 'no visitor input echoed into Liquid');
    $again = $post($sign($params), $form);
    eq(200, $again['status']);
    $rows = T::db()->all('SELECT * FROM submissions WHERE website_id = ?', [$site['id']]);
    eq(1, count($rows), 'double submit stored once');
    eq('quote', $rows[0]['form_type']);
    eq('CT-01', json_decode($rows[0]['products'], true)[0]['sku']);
    eq('+971 50 111 2222', $rows[0]['phone']);

    $tampered = $sign($params) . '&extra=1';
    eq(401, $post($tampered, $form)['status'], 'unsigned extra parameter');
    $badSig = $sign(['shop' => 'atlantic-test.myshopify.com'] + $params);
    eq(401, $post(str_replace('signature=', 'signature=0', $badSig), $form)['status'], 'bad signature');
    $old = $params;
    $old['timestamp'] = (string) (time() - 3600);
    eq(401, $post($sign($old), $form)['status'], 'stale timestamp');
    $otherShop = $params;
    $otherShop['shop'] = 'someone-else.myshopify.com';
    eq(403, $post($sign($otherShop), $form)['status'], 'other shop');
    eq(405, http_request('GET', $proxy->url('/submit?' . $sign($params)))['status']);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE website_id = ?', [$site['id']]));
    $proxy->stop();
});

test($G, 'Next.js App Router route handler (run in Node with type stripping)', function () use ($w6) {
    $node = trim((string) shell_exec('node -v 2>&1'));
    if (!preg_match('/^v(\d+)/', $node, $m) || (int) $m[1] < 22) {
        throw new TestFailure('Node.js 22+ not available (got ' . $node . ')');
    }
    $site = make_website('E2E Next', 'e2e-next.example', 'nextjs');
    $key = make_key((int) $site['id']);
    $cmd = ['node', '--no-warnings', '--import', './tests/node/register.mjs', './tests/node/nextjs-route.test.mjs'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, T::$root, array_merge(getenv(), ['INQUIRY_API_URL' => $w6->endpoint, 'INQUIRY_API_KEY' => $key]));
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $r = json_decode(trim((string) $out), true);
    ok(is_array($r), 'node output: ' . $out . $err);
    eq(200, $r['first']['status']);
    eq(true, $r['first']['json']['ok']);
    eq($r['first']['json']['inquiryId'] ?? 'missing', $r['retry']['json']['inquiryId'] ?? json_encode($r['retry']), 'retry returns the same inquiry');
    eq(422, $r['invalid']['status']);
    ok(isset($r['invalid']['json']['fields']['email']));
    eq(400, $r['noToken']['status']);
    eq(true, $r['honeypot']['json']['ok']);
    eq(400, $r['badJson']['status']);
    $row = T::db()->one('SELECT * FROM submissions WHERE submission_uid = ?', [$r['submissionId']]);
    eq('Next Visitor', $row['full_name']);
    eq('quote', $row['form_type']);
    eq('2026-12-15', json_decode($row['custom_fields'], true)['needed_by']['value']);
    eq('198.51.100.23', $row['source_ip']);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE website_id = ?', [$site['id']]), 'honeypot and invalid not stored');
});

test($G, 'XAMPP Apache: root .htaccess hides .env, source, storage and the SQLite reference', function () {
    $base = 'http://localhost/AtlanticCustomerQueries';
    $probe = http_request('GET', $base . '/assets/app.css');
    if ($probe['status'] === 0) {
        throw new TestFailure('Apache is not reachable at ' . $base . ' (start Apache in XAMPP to run this check)');
    }
    eq(200, $probe['status'], 'public asset served through rewrite');
    foreach (['/.env', '/.env.example', '/composer.json', '/src/App.php', '/vendor/autoload.php', '/storage/logs/', '/reference/inquiries.sqlite', '/database/migrations/001_initial_schema.sql', '/bin/migrate.php', '/public/.htaccess'] as $path) {
        $r = http_request('GET', $base . $path);
        not_contains('DB_PASS', $r['body'], $path);
        not_contains('CREATE TABLE', $r['body'], $path);
        not_contains('SQLite format', $r['body'], $path);
        not_contains('namespace App', $r['body'], $path);
        ok($r['status'] !== 200 || str_contains($r['body'], '<!doctype html>'), "{$path} returned raw content (HTTP {$r['status']})");
    }
});

test($G, 'stop servers', function () use ($w6) {
    $w6->server->stop();
    ok(true);
});
