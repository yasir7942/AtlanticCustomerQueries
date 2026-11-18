<?php

declare(strict_types=1);

$G = 'Sign-in page: remember me and show-password button';

test($G, 'sign-in page has "Remember me" checked by default and an accessible show-password button', function () {
    $html = (new Client())->get('/login')->body;
    ok((bool) preg_match('/<input type="checkbox" id="remember" name="remember" value="1" checked>/', $html), 'checked by default');
    contains('stay signed in on this device for 30 days', $html);
    contains('data-password-toggle="password"', $html);
    contains('aria-label="Show password"', $html);
    contains('assets/app.js', $html, 'script loaded on the bare layout');
    // A failed sign-in with the box unticked keeps it unticked.
    $c = new Client();
    $c->get('/login');
    $r = $c->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever passphrase']);
    eq(422, $r->status);
    not_contains('value="1" checked>', $r->body);
});

test($G, 'with "Remember me" the device stays signed in after the browser is closed', function () {
    $u = make_user('user', 'remember-1@example.test');
    $c = new Client();
    eq(303, $c->login('remember-1@example.test', 'correct horse battery', true)->status);
    ok(isset($c->cookies['aiq_remember']), 'remember cookie set');
    ok((bool) preg_match('/^[a-f0-9]{24}\.[a-f0-9]{64}$/', $c->cookies['aiq_remember']));
    [$selector, $validator] = explode('.', $c->cookies['aiq_remember']);
    $row = T::db()->one('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
    eq(hash('sha256', $validator), $row['validator_hash'], 'only the hash is stored');
    ok($row['expires_at'] > gmdate('Y-m-d H:i:s', time() + 29 * 86400), '30-day expiry');

    $c->restartBrowser();
    $r = $c->get('/inbox');
    eq(200, $r->status, 'signed in again from the remember cookie');
    ok((int) T::db()->value('SELECT COUNT(*) FROM audit_log WHERE action = "auth.login" AND target_id = ? AND details LIKE ?', [(string) $u['id'], '%remember_me%']) >= 1);
});

test($G, 'without "Remember me" closing the browser signs you out', function () {
    make_user('user', 'remember-2@example.test');
    $c = new Client();
    $c->login('remember-2@example.test', 'correct horse battery', false);
    ok(!isset($c->cookies['aiq_remember']));
    $c->restartBrowser();
    eq(303, $c->get('/inbox')->status);
});

test($G, 'signing out forgets the device', function () {
    make_user('user', 'remember-3@example.test');
    $c = new Client();
    $c->login('remember-3@example.test', 'correct horse battery', true);
    $selector = explode('.', $c->cookies['aiq_remember'])[0];
    $c->post('/logout');
    ok(!isset($c->cookies['aiq_remember']), 'cookie cleared');
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM remember_tokens WHERE selector = ?', [$selector]));
});

test($G, 'password reset, deactivation and a tampered cookie all end remembered sign-ins', function () {
    $admin = make_user('director', 'remember-admin@example.test');
    $ac = new Client();
    $ac->login('remember-admin@example.test', 'correct horse battery');

    $u = make_user('user', 'remember-4@example.test');
    $c = new Client();
    $c->login('remember-4@example.test', 'correct horse battery', true);
    $ac->post('/users/' . $u['id'] . '/password', ['password_mode' => 'temporary']);
    $c->restartBrowser();
    eq(303, $c->get('/inbox')->status, 'reset ends the remembered device');
    ok(!isset($c->cookies['aiq_remember']), 'stale cookie cleared');

    $u5 = make_user('user', 'remember-5@example.test');
    $c5 = new Client();
    $c5->login('remember-5@example.test', 'correct horse battery', true);
    $ac->post('/users/' . $u5['id'], ['name' => $u5['name'], 'email' => $u5['email'], 'role' => 'user', 'status' => 'inactive']);
    $c5->restartBrowser();
    eq(303, $c5->get('/inbox')->status, 'deactivation ends the remembered device');

    make_user('user', 'remember-6@example.test');
    $c6 = new Client();
    $c6->login('remember-6@example.test', 'correct horse battery', true);
    $c6->cookies['aiq_remember'] = explode('.', $c6->cookies['aiq_remember'])[0] . '.' . str_repeat('0', 64);
    $c6->restartBrowser();
    eq(303, $c6->get('/inbox')->status, 'wrong validator rejected');
});

test($G, 'changing your own password keeps this device remembered but signs out the others', function () {
    make_user('user', 'remember-7@example.test');
    $a = new Client();
    $b = new Client();
    $a->login('remember-7@example.test', 'correct horse battery', true);
    $b->login('remember-7@example.test', 'correct horse battery', true);
    eq(303, $a->post('/profile/password', ['current_password' => 'correct horse battery', 'password' => 'a new long passphrase', 'password_confirm' => 'a new long passphrase'])->status);
    $a->restartBrowser();
    $b->restartBrowser();
    eq(200, $a->get('/inbox')->status, 'this device still remembered');
    eq(303, $b->get('/inbox')->status, 'other device signed out');
});
