<?php

declare(strict_types=1);

$G = 'Workflow 5: custom form, multi-product quote, safe rendering, consistent totals';

$w5 = new stdClass();

test($G, 'setup: website, key and a Director session', function () use ($w5) {
    $w5->site = make_website('Render Site', 'render.example', 'laravel');
    $w5->key = make_key((int) $w5->site['id']);
    make_user('director', 'dir-w5@example.test');
    $w5->c = new Client();
    $w5->c->login('dir-w5@example.test', 'correct horse battery');
});

test($G, 'sample request with custom fields is stored and readable with labels', function () use ($w5) {
    $r = api($w5->key, [
        'submission_id' => 'sample-req-1001',
        'form_type' => 'sample',
        'form_id' => 'swatch-request',
        'form_name' => 'Swatch request',
        'full_name' => 'Layla Haddad',
        'country' => 'Jordan',
        'fields' => [
            'swatch_colours' => ['label' => 'Swatch colours', 'value' => ['Sand', 'Charcoal']],
            'delivery_by' => '2026-12-01',
            'needs_invoice' => true,
            'project_size_m2' => 420,
        ],
    ]);
    eq(201, $r['status'], json_encode($r['body']));
    $html = $w5->c->get('/inbox/' . $r['body']['inquiry_id'])->body;
    contains('Form answers', $html);
    contains('Swatch colours', $html);
    contains('<li>Sand</li>', $html);
    contains('Delivery by', $html, 'humanized key');
    contains('Needs invoice', $html);
    contains('>Yes<', (string) preg_replace('/\s+/', '', $html), 'boolean shown as Yes');
    contains('420', $html);
    contains('Swatch request', $html, 'form label');
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM forms WHERE website_id = ? AND form_key = "swatch-request" AND form_type = "sample"', [$w5->site['id']]));
});

test($G, 'multi-product quote shows each product with quantity and unit', function () use ($w5) {
    $r = api($w5->key, [
        'submission_id' => 'quote-2001',
        'form_type' => 'quote',
        'company' => 'Gulf Build Co.',
        'email' => 'buyer@gulfbuild.example',
        'message' => 'Please quote delivery to Jebel Ali.',
        'products' => [
            ['name' => 'Ball valve DN50', 'sku' => 'BV-50', 'quantity' => 120, 'unit' => 'pcs'],
            ['name' => 'PVC pipe 110mm', 'sku' => 'PVC-110', 'quantity' => 2.5, 'unit' => 'km', 'notes' => 'Grey'],
            ['name' => 'Gasket kit', 'variant' => 'EPDM', 'quantity' => '40'],
        ],
    ]);
    eq(201, $r['status'], json_encode($r['body']));
    $id = $r['body']['inquiry_id'];
    eq(3, (int) T::db()->value('SELECT product_count FROM submissions WHERE id = ?', [$id]));
    $html = $w5->c->get('/inbox/' . $id)->body;
    contains('Products (3)', $html);
    contains('Ball valve DN50', $html);
    contains('120 pcs', $html);
    contains('2.5 km', $html);
    contains('EPDM', $html);
    $inbox = $w5->c->get('/inbox', ['website' => (string) $w5->site['id']])->body;
    contains('3 products: Ball valve DN50 × 120, PVC pipe 110mm × 2.5, Gasket kit × 40', $inbox);
});

test($G, 'HTML/script in messages, names, custom fields and products is escaped', function () use ($w5) {
    $evil = '<script>alert(1)</script>';
    $img = '"><img src=x onerror=alert(2)>';
    $r = api($w5->key, [
        'submission_id' => 'xss-1',
        'form_type' => 'other',
        'full_name' => $img,
        'message' => "Line one\n" . $evil,
        'fields' => [$evil => $img, 'note' => '<b>bold</b>'],
        'products' => [['name' => $evil, 'quantity' => 1]],
        'source' => ['page_url' => 'https://render.example/x?a=<script>'],
    ]);
    eq(201, $r['status'], json_encode($r['body']));
    foreach (['/inbox/' . $r['body']['inquiry_id'], '/inbox', '/'] as $path) {
        $html = $w5->c->get($path)->body;
        not_contains('<script>alert', $html, $path);
        not_contains('<img src=x', $html, $path);
        not_contains('<b>bold</b>', $html, $path);
    }
    $detail = $w5->c->get('/inbox/' . $r['body']['inquiry_id'])->body;
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $detail);
    contains('Line one<br />', $detail, 'newlines preserved');
    $res = $w5->c->get('/inbox/' . $r['body']['inquiry_id']);
    contains("script-src 'self'", $res->headers['Content-Security-Policy'] ?? '', 'CSP header');
});

test($G, 'Arabic content is preserved and rendered right-to-left', function () use ($w5) {
    $msg = "السلام عليكم،\nنرغب في الحصول على عرض سعر لعدد ٢٠٠ قطعة.";
    $r = api($w5->key, ['submission_id' => 'ar-3001', 'form_type' => 'quote', 'locale' => 'ar', 'full_name' => 'سارة الأحمد', 'message' => $msg, 'phone' => '+971 4 000 0000']);
    eq(201, $r['status']);
    eq($msg, T::db()->value('SELECT message FROM submissions WHERE id = ?', [$r['body']['inquiry_id']]));
    $html = $w5->c->get('/inbox/' . $r['body']['inquiry_id'])->body;
    contains('dir="rtl" lang="ar"', $html);
    contains('سارة الأحمد', $html);
    contains('سارة الأحمد', $w5->c->get('/inbox', ['q' => 'سارة'])->body, 'Arabic search');
    $csv = $w5->c->get('/inbox/export', ['website' => (string) $w5->site['id']])->body;
    ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV has UTF-8 BOM');
    contains('سارة الأحمد', $csv);
});

test($G, 'search matches custom field and product text (case-insensitive)', function () use ($w5) {
    contains('Layla Haddad', $w5->c->get('/inbox', ['q' => 'charcoal'])->body);
    contains('Gulf Build', $w5->c->get('/inbox', ['q' => 'pvc-110'])->body);
    $r = $w5->c->get('/inbox', ['q' => '100%_\\']);
    eq(200, $r->status, 'LIKE wildcards are escaped');
    contains('<strong>0</strong> inquir', $r->body);
});

test($G, 'dashboard, inbox and report totals agree for the same filters', function () use ($w5) {
    $c = $w5->c;
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
    $from = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->modify('-29 days')->format('Y-m-d');
    $dash = $c->get('/', ['period' => '30d'])->body;
    preg_match('#kpi-label">Inquiries</span>\s*<span class="kpi-value">([\d,]+)#', $dash, $m);
    $dashTotal = (int) str_replace(',', '', $m[1]);
    $inbox = $c->get('/inbox', ['from' => $from, 'to' => $today])->body;
    preg_match('#<strong>([\d,]+)</strong> inquir#', $inbox, $m2);
    eq($dashTotal, (int) str_replace(',', '', $m2[1]), 'dashboard vs inbox');
    $csv = $c->get('/reports/export', ['period' => '30d'])->body;
    preg_match('/^"?All websites"?,,(\d+),(\d+),(\d+),(\d+),(\d+)/m', $csv, $m3);
    eq($dashTotal, (int) $m3[5], 'dashboard vs report');
    // Per type: dashboard count links to inbox with the same filters.
    preg_match('#href="([^"]*type=quote[^"]*)"[^>]*>\s*<span class="kpi-label">Quote</span>\s*<span class="kpi-value">(\d+)#', $dash, $qm);
    parse_str((string) parse_url(html_entity_decode($qm[1]), PHP_URL_QUERY), $qs);
    $inboxQuote = $c->get('/inbox', $qs)->body;
    preg_match('#<strong>([\d,]+)</strong> inquir#', $inboxQuote, $m4);
    eq((int) $qm[2], (int) $m4[1], 'quote count link');
    eq((int) $m3[2], (int) $qm[2], 'report quote total');
    // Daily trend sums to the total.
    preg_match('#<span class="muted small">([\d,]+) total#', $dash, $m5);
    eq($dashTotal, (int) str_replace(',', '', $m5[1]));
});

test($G, 'CSRF: state-changing browser requests without a valid token are rejected', function () use ($w5) {
    $r = $w5->c->post('/profile', ['name' => 'Changed', 'timezone' => 'UTC', '_csrf' => 'forged'], true);
    eq(419, $r->status);
    $r = $w5->c->post('/profile', ['name' => 'Changed', 'timezone' => 'UTC'], false);
    eq(419, $r->status);
    $r = (new Client())->post('/login', ['email' => 'dir-w5@example.test', 'password' => 'correct horse battery'], false);
    eq(419, $r->status, 'login form also protected');
});

test($G, 'login is throttled after repeated failures and audited without passwords', function () {
    make_user('user', 'brute-w5@example.test');
    $c = new Client();
    for ($i = 0; $i < 5; $i++) {
        eq(422, $c->login('brute-w5@example.test', 'wrong password ' . $i)->status);
    }
    $r = $c->login('brute-w5@example.test', 'correct horse battery');
    eq(422, $r->status);
    contains('Too many sign-in attempts', $r->body);
    $audit = json_encode(T::db()->all('SELECT * FROM audit_log WHERE target_label = "brute-w5@example.test"'));
    not_contains('wrong password', $audit);
    contains('bad_password', $audit);
});

test($G, 'archiving a website with history keeps inquiries readable and revokes keys', function () use ($w5) {
    $dev = T::db()->one('SELECT * FROM users WHERE role = "developer" AND status = "active" ORDER BY id LIMIT 1');
    $svc = new App\Domain\WebsiteService(T::db(), new App\Domain\AuditLog(T::db()), new App\Domain\CredentialService(T::db(), new App\Domain\AuditLog(T::db())));
    eq('archived', $svc->remove($dev, (int) $w5->site['id']));
    eq('archived', T::db()->value('SELECT status FROM websites WHERE id = ?', [$w5->site['id']]));
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM api_credentials WHERE website_id = ? AND status = "active"', [$w5->site['id']]));
    eq(401, api($w5->key, sample_payload('after-archive'))['status']);
    contains('Gulf Build', $w5->c->get('/inbox', ['website' => (string) $w5->site['id']])->body);
    // An empty website is deleted outright.
    $empty = make_website('Empty Site', 'empty.example');
    eq('deleted', $svc->remove($dev, (int) $empty['id']));
    eq(null, T::db()->value('SELECT id FROM websites WHERE id = ?', [$empty['id']]));
    // Purge needs the exact domain.
    try {
        $svc->remove($dev, (int) $w5->site['id'], 'wrong.example');
        ok(false);
    } catch (App\Domain\ValidationError $e) {
        ok(true);
    }
});

test($G, 'first-Developer SQL bootstrap only inserts when no Developer exists', function () {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(T::$root . '/bin/create-developer.php') . ' --sql --name="Boot O\'Strap" --email=boot@example.test 2>&1');
    contains('INSERT INTO users', (string) $out);
    preg_match('/^--\s+([a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})$/m', (string) $out, $m);
    ok(isset($m[1]), 'temporary password printed');
    $sql = implode("\n", array_filter(explode("\n", (string) $out), fn ($l) => !str_starts_with($l, '--') && !str_starts_with($l, 'SQL printed')));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        T::db()->pdo()->exec($stmt);
    }
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM users WHERE email = "boot@example.test"'), 'developers already exist, so nothing inserted');
    // Fresh database: works exactly once.
    T::db()->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    $backup = T::db()->all('SELECT * FROM users WHERE role = "developer"');
    T::db()->run('UPDATE users SET role = "director" WHERE role = "developer"');
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        T::db()->pdo()->exec($stmt);
    }
    $row = T::db()->one('SELECT * FROM users WHERE email = "boot@example.test"');
    ok($row !== null && $row['role'] === 'developer' && (int) $row['must_change_password'] === 1);
    eq("Boot O'Strap", $row['name']);
    ok(password_verify($m[1], $row['password_hash']));
    T::db()->run('DELETE FROM users WHERE email = "boot@example.test"');
    foreach ($backup as $b) {
        T::db()->run('UPDATE users SET role = "developer" WHERE id = ?', [$b['id']]);
    }
    T::db()->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
});
