<?php

declare(strict_types=1);

$G = 'Workflow 4: temporary passwords, key revocation, per-user date boundaries';

$w4 = new stdClass();

test($G, 'temporary password forces a change before any other protected action', function () use ($w4) {
    $admin = make_user('director', 'dir-w4@example.test');
    $ac = new Client();
    $ac->login('dir-w4@example.test', 'correct horse battery');
    $ac->post('/users', ['name' => 'Temp Person', 'email' => 'temp-w4@example.test', 'role' => 'user', 'timezone' => 'Asia/Dubai', 'password_mode' => 'temporary']);
    $id = (int) T::db()->value('SELECT id FROM users WHERE email = "temp-w4@example.test"');
    $page = $ac->get('/users/' . $id)->body;
    ok((bool) preg_match('/value="([a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})"/', $page, $m), 'temporary password displayed');
    $temp = $m[1];
    eq(1, (int) T::db()->value('SELECT must_change_password FROM users WHERE id = ?', [$id]));
    $hash = (string) T::db()->value('SELECT password_hash FROM users WHERE id = ?', [$id]);
    ok(str_starts_with($hash, '$2y$') && !str_contains($hash, $temp), 'only a hash is stored');

    $uc = new Client();
    $r = $uc->login('temp-w4@example.test', $temp);
    eq('/password/change', parse_url(location($r), PHP_URL_PATH));
    foreach (['/', '/inbox', '/reports', '/profile'] as $p) {
        $r = $uc->get($p);
        eq(303, $r->status, $p);
        eq('/password/change', parse_url(location($r), PHP_URL_PATH), $p);
    }
    eq(303, $uc->post('/profile', ['name' => 'X', 'timezone' => 'UTC'])->status, 'POST also blocked');
    $r = $uc->post('/password/change', ['current_password' => $temp, 'password' => 'short', 'password_confirm' => 'short']);
    eq(422, $r->status);
    $r = $uc->post('/password/change', ['current_password' => $temp, 'password' => 'a brand new passphrase', 'password_confirm' => 'a brand new passphrase']);
    eq(303, $r->status);
    eq(0, (int) T::db()->value('SELECT must_change_password FROM users WHERE id = ?', [$id]));
    eq(200, $uc->get('/inbox')->status, 'normal access after change');
    $w4->tempUserId = $id;
    $w4->admin = $ac;
});

test($G, 'password reset invalidates existing sessions', function () use ($w4) {
    $u = make_user('user', 'reset-w4@example.test');
    $s1 = new Client();
    $s1->login('reset-w4@example.test', 'correct horse battery');
    eq(200, $s1->get('/inbox')->status);
    eq(303, $w4->admin->post('/users/' . $u['id'] . '/password', ['password_mode' => 'set', 'password' => 'replacement passphrase', 'password_confirm' => 'replacement passphrase'])->status);
    $r = $s1->get('/inbox');
    eq(303, $r->status, 'old session ended');
    eq(303, (new Client())->login('reset-w4@example.test', 'replacement passphrase')->status);
});

test($G, 'changing your own password signs out your other sessions but keeps the current one', function () {
    make_user('user', 'own-w4@example.test');
    $a = new Client();
    $b = new Client();
    $a->login('own-w4@example.test', 'correct horse battery');
    $b->login('own-w4@example.test', 'correct horse battery');
    eq(303, $a->post('/profile/password', ['current_password' => 'correct horse battery', 'password' => 'another long passphrase', 'password_confirm' => 'another long passphrase'])->status);
    eq(200, $a->get('/profile')->status);
    eq(303, $b->get('/profile')->status);
});

test($G, 'key revocation is immediate; rotation honours the grace period', function () use ($w4) {
    $dev = T::db()->one('SELECT * FROM users WHERE role = "developer" AND status = "active" ORDER BY id LIMIT 1');
    T::db()->run('UPDATE users SET password_hash = ? WHERE id = ?', [App\Auth\Passwords::hash('developer passphrase'), $dev['id']]);
    $dc = new Client();
    $dc->login($dev['email'], 'developer passphrase');
    $site = make_website('Keys Site', 'keys.example');
    $id = (int) $site['id'];

    $k1 = make_key($id);
    eq(201, api($k1, sample_payload('k-1'))['status']);
    // Rotate with 24h grace: old and new both work.
    $dc->post("/websites/{$id}/keys", ['mode' => 'rotate_24h']);
    preg_match('/value="(aiq_[^"]+)"/', $dc->get("/websites/{$id}/connect")->body, $m);
    $k2 = $m[1];
    eq(201, api($k1, sample_payload('k-2'))['status'], 'old key within grace');
    eq(201, api($k2, sample_payload('k-3'))['status'], 'new key');
    // Rotate immediately: previous keys revoked.
    $dc->post("/websites/{$id}/keys", ['mode' => 'rotate_now']);
    preg_match('/value="(aiq_[^"]+)"/', $dc->get("/websites/{$id}/connect")->body, $m);
    $k3 = $m[1];
    $r = api($k2, sample_payload('k-4'));
    eq(401, $r['status']);
    eq('revoked_key', $r['body']['error']['reason']);
    eq(201, api($k3, sample_payload('k-5'))['status']);
    // Explicit revoke.
    $credId = (int) T::db()->value('SELECT id FROM api_credentials WHERE key_id = ?', [substr($k3, 4, 16)]);
    eq(303, $dc->post("/websites/{$id}/keys/{$credId}/revoke")->status);
    $r = api($k3, sample_payload('k-6'));
    eq(401, $r['status']);
    eq('revoked_key', $r['body']['error']['reason']);
    // Expired grace key.
    // A key whose rotation grace period has ended.
    $k4 = make_key($id);
    T::db()->run('UPDATE api_credentials SET expires_at = ? WHERE key_id = ?', [gmdate('Y-m-d H:i:s', time() - 5), substr($k4, 4, 16)]);
    eq('expired_key', api($k4, sample_payload('k-7'))['body']['error']['reason']);
    $actions = array_column(T::db()->all('SELECT action FROM audit_log WHERE target_type = "website" AND target_id = ? ORDER BY id', [(string) $id]), 'action');
    ok(in_array('credential.rotate', $actions, true) && in_array('credential.revoke', $actions, true), 'credential lifecycle audited');
    $audit = json_encode(T::db()->all('SELECT * FROM audit_log'));
    not_contains(explode('_', $k3, 3)[2], $audit, 'no secrets in audit log');
    $w4->dc = $dc;
});

test($G, '19:30 UTC appears on 18 Nov (23:30) in Dubai and 19 Nov (00:30) in Karachi, including filters', function () use ($w4) {
    $site = make_website('Dates Site', 'dates.example');
    $key = make_key((int) $site['id']);
    $r = api($key, sample_payload('tz-1', ['full_name' => 'Boundary Case']));
    $inq = $r['body']['inquiry_id'];
    T::db()->run('UPDATE submissions SET received_at = "2026-11-18 19:30:00" WHERE id = ?', [$inq]);

    make_user('director', 'dubai-w4@example.test', 'correct horse battery', ['timezone' => 'Asia/Dubai']);
    make_user('director', 'karachi-w4@example.test', 'correct horse battery', ['timezone' => 'Asia/Karachi']);
    $dubai = new Client();
    $dubai->login('dubai-w4@example.test', 'correct horse battery');
    $karachi = new Client();
    $karachi->login('karachi-w4@example.test', 'correct horse battery');
    $w = (string) $site['id'];

    contains('18 Nov 2026, 23:30', $dubai->get('/inbox', ['website' => $w])->body);
    contains('19 Nov 2026, 00:30', $karachi->get('/inbox', ['website' => $w])->body);
    contains('Boundary Case', $dubai->get('/inbox', ['website' => $w, 'from' => '2026-11-18', 'to' => '2026-11-18'])->body);
    not_contains('Boundary Case', $dubai->get('/inbox', ['website' => $w, 'from' => '2026-11-19', 'to' => '2026-11-19'])->body);
    contains('Boundary Case', $karachi->get('/inbox', ['website' => $w, 'from' => '2026-11-19', 'to' => '2026-11-19'])->body);
    not_contains('Boundary Case', $karachi->get('/inbox', ['website' => $w, 'from' => '2026-11-18', 'to' => '2026-11-18'])->body);

    // Daily report totals use the viewer's zone.
    $q = fn (string $tz) => new App\Domain\Submissions\SubmissionQuery(T::db(), App\Domain\Scope::for(['role' => 'director', 'id' => 0], T::db()), $tz);
    $f = new App\Domain\Submissions\InquiryFilter(websiteId: (int) $site['id'], from: '2026-11-17', to: '2026-11-20');
    $d = $q('Asia/Dubai')->dailyCounts($f);
    $k = $q('Asia/Karachi')->dailyCounts($f);
    eq(1, $d['2026-11-18']);
    eq(0, $d['2026-11-19']);
    eq(0, $k['2026-11-18']);
    eq(1, $k['2026-11-19']);
    // Detail page shows local time.
    contains('Wed 18 Nov 2026, 23:30', $dubai->get('/inbox/' . $inq)->body);
    contains('Thu 19 Nov 2026, 00:30', $karachi->get('/inbox/' . $inq)->body);
});

test($G, 'new profiles default to Asia/Dubai; users can switch to Asia/Karachi', function () use ($w4) {
    eq('Asia/Dubai', T::db()->value('SELECT timezone FROM users WHERE id = ?', [$w4->tempUserId]));
    $c = new Client();
    make_user('user', 'tzswitch-w4@example.test');
    $c->login('tzswitch-w4@example.test', 'correct horse battery');
    eq(303, $c->post('/profile', ['name' => 'Tz Switch', 'timezone' => 'Asia/Karachi'])->status);
    eq('Asia/Karachi', T::db()->value('SELECT timezone FROM users WHERE email = "tzswitch-w4@example.test"'));
    eq(422, $c->post('/profile', ['name' => 'Tz Switch', 'timezone' => 'Mars/Olympus'])->status);
    contains('Asia/Karachi', $c->get('/profile')->body);
});
