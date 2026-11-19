<?php

declare(strict_types=1);

$G = 'Workflow 1: two websites, separate keys, idempotent retries';

$ctx1 = new stdClass();

test($G, 'register two websites through the UI as Developer and create a key for each', function () use ($ctx1) {
    $dev = make_user('developer', 'dev1@example.test');
    $c = new Client();
    ok(location($c->login('dev1@example.test', 'correct horse battery')) !== '', 'login redirects');
    foreach ([['Alpha Valves', 'alpha.example', 'php'], ['Beta Pumps', 'https://www.beta.example/contact', 'wordpress']] as [$name, $domain, $platform]) {
        $r = $c->post('/websites', ['name' => $name, 'domain' => $domain, 'platform' => $platform, 'rate_limit_per_minute' => '600']);
        eq(303, $r->status, 'website created');
        preg_match('#/websites/(\d+)/connect#', location($r), $m);
        $id = (int) $m[1];
        $r = $c->post("/websites/{$id}/keys", ['mode' => 'new', 'label' => 'prod']);
        eq(303, $r->status);
        $page = $c->get("/websites/{$id}/connect");
        eq(200, $page->status);
        ok((bool) preg_match('/value="(aiq_[a-z0-9]{16}_[A-Za-z0-9_-]{43})"/', $page->body, $km), 'new key shown once');
        $again = $c->get("/websites/{$id}/connect");
        not_contains($km[1], $again->body, 'key is not shown a second time');
        $ctx1->sites[$name] = ['id' => $id, 'key' => $km[1]];
    }
    eq('www.beta.example', T::db()->value('SELECT domain FROM websites WHERE id = ?', [$ctx1->sites['Beta Pumps']['id']]), 'domain normalized');
    // Only the verifier is stored.
    $secret = explode('_', $ctx1->sites['Alpha Valves']['key'], 3)[2];
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM api_credentials WHERE secret_hash = ?', [$secret]));
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM api_credentials WHERE secret_hash = ?', [hash('sha256', $secret)]));
    $ctx1->dev = $dev;
});

test($G, 'Connect page offers a Claude Code prompt for every platform, never containing the key', function () use ($ctx1) {
    $c = new Client();
    $c->login('dev1@example.test', 'correct horse battery');
    $site = $ctx1->sites['Alpha Valves'];
    [$keyId] = array_slice(explode('_', $site['key'], 3), 1, 1);
    foreach (array_keys(App\Domain\WebsiteService::PLATFORMS) as $platform) {
        $page = $c->get("/websites/{$site['id']}/connect", ['platform' => $platform]);
        eq(200, $page->status, $platform);
        preg_match('#<code id="ai-prompt-text">(.*?)</code>#s', $page->body, $m);
        ok(isset($m[1]), "prompt rendered for {$platform}");
        $prompt = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        contains('POST http://inquiries.test/api/v1/submissions', $prompt, $platform);
        contains('Alpha Valves', $prompt);
        contains('`aiq_' . $keyId . '_…', $prompt, 'masked key hint');
        contains('Platform guidance: ' . App\Domain\WebsiteService::PLATFORMS[$platform], $prompt);
        contains('http://inquiries.test/websites/' . $site['id'] . '/connect', $prompt);
        contains("send in the background, never in the customer's request", $prompt, 'background rule');
        contains('must **never see** whether the Inquiry Manager call succeeded or failed', $prompt);
        not_contains($site['key'], $page->body, 'full key never on the page again');
        not_contains(explode('_', $site['key'], 3)[2], $prompt, 'secret never in the prompt');
    }
    // WordPress guidance points at the plugin and wp-config constants.
    $wp = $c->get("/websites/{$site['id']}/connect", ['platform' => 'wordpress'])->body;
    contains('aiq_forward_inquiry', $wp);
    contains('AIQ_API_KEY', $wp);
});

test($G, 'each key stores submissions under its own website', function () use ($ctx1) {
    $a = api($ctx1->sites['Alpha Valves']['key'], sample_payload('w1-alpha-1', ['full_name' => 'Alpha Customer']));
    $b = api($ctx1->sites['Beta Pumps']['key'], sample_payload('w1-beta-1', ['full_name' => 'Beta Customer']));
    eq(201, $a['status'], json_encode($a['body']));
    eq(201, $b['status']);
    eq($ctx1->sites['Alpha Valves']['id'], (int) T::db()->value('SELECT website_id FROM submissions WHERE id = ?', [$a['body']['inquiry_id']]));
    eq($ctx1->sites['Beta Pumps']['id'], (int) T::db()->value('SELECT website_id FROM submissions WHERE id = ?', [$b['body']['inquiry_id']]));
    $ctx1->alphaInquiry = $a['body']['inquiry_id'];
});

test($G, 'retrying the same request returns the original result and creates one record', function () use ($ctx1) {
    $payload = sample_payload('w1-retry-1', ['message' => 'Timeout retry test']);
    $first = api($ctx1->sites['Alpha Valves']['key'], $payload);
    $second = api($ctx1->sites['Alpha Valves']['key'], $payload);
    // Same content, different key order: still a replay.
    $third = api($ctx1->sites['Alpha Valves']['key'], array_reverse($payload, true));
    eq(201, $first['status']);
    eq(200, $second['status']);
    eq(200, $third['status']);
    eq(true, $second['body']['replayed']);
    eq($first['body']['inquiry_id'], $second['body']['inquiry_id']);
    eq($first['body']['received_at'], $second['body']['received_at']);
    eq('true', $second['headers']['Idempotent-Replayed'] ?? null);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE submission_uid = ?', ['w1-retry-1']));
});

test($G, 'Idempotency-Key header works when the body has no submission_id', function () use ($ctx1) {
    $p = ['form_type' => 'contact', 'email' => 'hdr@example.test'];
    $r1 = api($ctx1->sites['Alpha Valves']['key'], $p, ['Idempotency-Key' => 'hdr-key-1']);
    $r2 = api($ctx1->sites['Alpha Valves']['key'], $p, ['Idempotency-Key' => 'hdr-key-1']);
    eq(201, $r1['status']);
    eq(200, $r2['status']);
    eq($r1['body']['inquiry_id'], $r2['body']['inquiry_id']);
});

test($G, 'reusing a submission ID with a different payload is rejected with 409', function () use ($ctx1) {
    $r = api($ctx1->sites['Alpha Valves']['key'], sample_payload('w1-retry-1', ['message' => 'Different text']));
    eq(409, $r['status']);
    eq('idempotency_conflict', $r['body']['error']['code']);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE submission_uid = ?', ['w1-retry-1']));
});

test($G, 'uniqueness is per website: the same ID on another website is a new inquiry', function () use ($ctx1) {
    $r = api($ctx1->sites['Beta Pumps']['key'], sample_payload('w1-retry-1', ['message' => 'Timeout retry test']));
    eq(201, $r['status']);
    eq(2, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE submission_uid = ?', ['w1-retry-1']));
});

test($G, 'a submitted website_id cannot redirect the inquiry to another website', function () use ($ctx1) {
    $r = api($ctx1->sites['Alpha Valves']['key'], sample_payload('w1-hijack', ['website_id' => $ctx1->sites['Beta Pumps']['id']]));
    eq(422, $r['status']);
    ok(isset($r['body']['error']['fields']['website_id']));
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM submissions WHERE submission_uid = "w1-hijack"'));
});

test($G, 'clear errors for missing/invalid credentials, bad JSON, media type, size, validation', function () use ($ctx1) {
    $k = $ctx1->sites['Alpha Valves']['key'];
    $r = api(null, sample_payload('e1'));
    eq(401, $r['status']);
    eq('missing_credentials', $r['body']['error']['reason']);
    ok(isset($r['headers']['WWW-Authenticate']));
    $r = api('aiq_aaaaaaaaaaaaaaaa_' . str_repeat('A', 43), sample_payload('e1'));
    eq(401, $r['status']);
    eq('unknown_key', $r['body']['error']['reason']);
    // Right key id, wrong secret.
    $r = api(substr($k, 0, 21) . str_repeat('B', 43), sample_payload('e1'));
    eq(401, $r['status']);
    $r = api($k, '{"form_type": "contact",');
    eq(400, $r['status']);
    eq('invalid_json', $r['body']['error']['code']);
    $r = api($k, '[1,2]');
    eq(400, $r['status']);
    $r = api($k, sample_payload('e2'), ['Content-Type' => 'text/plain']);
    eq(415, $r['status']);
    $r = api($k, sample_payload('e3', ['message' => str_repeat('x', 70000)]));
    eq(413, $r['status']);
    eq('payload_too_large', $r['body']['error']['code']);
    $r = api($k, sample_payload('e4', ['form_type' => 'complaint', 'email' => 'not-an-email']));
    eq(422, $r['status']);
    eq('validation_failed', $r['body']['error']['code']);
    ok(isset($r['body']['error']['fields']['form_type'], $r['body']['error']['fields']['email']));
});

test($G, 'plain HTTP is refused when API_REQUIRE_HTTPS is on', function () use ($ctx1) {
    $body = json_encode(sample_payload('http-1'));
    $res = T::$app->handle(new App\Http\Request('POST', '/api/v1/submissions', [], [], [
        'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $ctx1->sites['Alpha Valves']['key'],
    ], $body, ['REMOTE_ADDR' => '198.51.100.9']));
    eq(403, $res->status);
    contains('https_required', $res->body);
});

test($G, 'throttling returns 429 with Retry-After per website', function () use ($ctx1) {
    $site = make_website('Throttle Site', 'throttle.example');
    T::db()->run('UPDATE websites SET rate_limit_per_minute = 3 WHERE id = ?', [$site['id']]);
    $key = make_key((int) $site['id']);
    fresh_window();
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $codes[] = api($key, sample_payload('thr-' . $i))['status'];
    }
    $last = api($key, sample_payload('thr-x'));
    eq(429, $last['status']);
    ok((int) ($last['headers']['Retry-After'] ?? 0) >= 1);
    eq([201, 201, 201, 429, 429], $codes);
});

test($G, 'repeated bad credentials from one IP are throttled', function () {
    fresh_window();
    $codes = [];
    // 45 attempts: even if a one-minute window boundary falls inside the loop, one window sees > 20 failures.
    for ($i = 0; $i < 45; $i++) {
        $codes[] = api('aiq_bbbbbbbbbbbbbbbb_' . str_repeat('C', 43), sample_payload('bf'), [], '203.0.113.99')['status'];
    }
    ok(in_array(429, $codes, true), 'throttled after repeated failures');
    eq(401, $codes[0]);
    // Other IPs are unaffected.
    eq(401, api('aiq_bbbbbbbbbbbbbbbb_' . str_repeat('C', 43), sample_payload('bf'), [], '203.0.113.100')['status']);
});

test($G, 'deactivated website is rejected with 403 website_inactive', function () use ($ctx1) {
    $site = make_website('Paused Site', 'paused.example');
    $key = make_key((int) $site['id']);
    T::db()->run('UPDATE websites SET status = "inactive" WHERE id = ?', [$site['id']]);
    $r = api($key, sample_payload('p-1'));
    eq(403, $r['status']);
    eq('website_inactive', $r['body']['error']['code']);
});

test($G, 'diagnostics record outcomes without bodies or keys', function () use ($ctx1) {
    $rows = T::db()->all('SELECT * FROM api_request_log');
    ok(count($rows) > 10);
    $dump = json_encode($rows);
    not_contains('Timeout retry test', $dump, 'no inquiry bodies');
    not_contains(explode('_', $ctx1->sites['Alpha Valves']['key'], 3)[2], $dump, 'no secrets');
    ok((int) T::db()->value('SELECT COUNT(*) FROM api_request_log WHERE result_code = "idempotency_conflict"') >= 1);
});

test($G, 'GET and unknown API paths return JSON errors', function () {
    $r = T::$app->handle(new App\Http\Request('GET', '/api/v1/submissions', [], [], [], null, []));
    eq(405, $r->status);
    $r = T::$app->handle(new App\Http\Request('POST', '/api/v2/whatever', [], [], [], '{}', []));
    eq(404, $r->status);
});
