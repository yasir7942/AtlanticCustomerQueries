<?php

declare(strict_types=1);

$G = 'Workflow 3: Director powers and Developer-only boundaries';

$w3 = new stdClass();

test($G, 'setup: Director signs in', function () use ($w3) {
    $w3->director = make_user('director', 'dir-w3@example.test');
    $w3->dev = T::db()->one('SELECT * FROM users WHERE role = "developer" AND status = "active" ORDER BY id LIMIT 1');
    $w3->site = make_website('Roles Site', 'roles.example');
    $w3->c = new Client();
    eq(303, $w3->c->login('dir-w3@example.test', 'correct horse battery')->status);
});

test($G, 'Director creates another Director and a User', function () use ($w3) {
    $r = $w3->c->post('/users', ['name' => 'Second Director', 'email' => 'dir2-w3@example.test', 'role' => 'director', 'timezone' => 'Asia/Karachi', 'password_mode' => 'temporary']);
    eq(303, $r->status);
    eq('director', T::db()->value('SELECT role FROM users WHERE email = ?', ['dir2-w3@example.test']));
    $r = $w3->c->post('/users', ['name' => 'Plain User', 'email' => 'user-w3@example.test', 'role' => 'user', 'timezone' => 'Asia/Dubai', 'password_mode' => 'temporary']);
    eq(303, $r->status);
    $w3->userId = (int) T::db()->value('SELECT id FROM users WHERE email = ?', ['user-w3@example.test']);
    contains('Temporary password', $w3->c->get('/users/' . $w3->userId)->body, 'temp password shown once');
    not_contains('Temporary password</h2>', $w3->c->get('/users/' . $w3->userId)->body, 'not shown twice');
});

test($G, 'Director manages User assignments', function () use ($w3) {
    eq(303, $w3->c->post('/users/' . $w3->userId . '/websites', ['websites' => [(string) $w3->site['id']]])->status);
    eq(1, (int) T::db()->value('SELECT COUNT(*) FROM website_user WHERE user_id = ?', [$w3->userId]));
});

test($G, 'Director edits business details but not connection settings', function () use ($w3) {
    $id = $w3->site['id'];
    eq(303, $w3->c->post("/websites/{$id}/business", ['name' => 'Roles Site Renamed', 'business_contact' => 'Ops', 'business_email' => 'ops@roles.example', 'notes' => ''])->status);
    eq('Roles Site Renamed', T::db()->value('SELECT name FROM websites WHERE id = ?', [$id]));
    eq(403, $w3->c->post("/websites/{$id}/connection", ['domain' => 'evil.example', 'platform' => 'php', 'rate_limit_per_minute' => '60'])->status);
    eq('roles.example', T::db()->value('SELECT domain FROM websites WHERE id = ?', [$id]));
    $page = $w3->c->get("/websites/{$id}");
    eq(200, $page->status);
    not_contains('Connection settings', $page->body, 'connection form hidden');
    not_contains('API Connect', $page->body);
});

test($G, 'direct requests to Developer-only actions are denied', function () use ($w3) {
    $id = $w3->site['id'];
    $c = $w3->c;
    $checks = [
        ['GET', '/websites/new', []],
        ['POST', '/websites', ['name' => 'X', 'domain' => 'x.example', 'platform' => 'php']],
        ['POST', "/websites/{$id}/status", ['status' => 'inactive']],
        ['POST', "/websites/{$id}/delete", []],
        ['GET', "/websites/{$id}/connect", []],
        ['POST', "/websites/{$id}/keys", ['mode' => 'new']],
        ['POST', "/websites/{$id}/keys/1/revoke", []],
        ['GET', '/downloads/wordpress-plugin', []],
        ['GET', '/diagnostics', []],
    ];
    foreach ($checks as [$m, $path, $data]) {
        $r = $m === 'GET' ? $c->get($path) : $c->post($path, $data);
        eq(403, $r->status, "{$m} {$path}");
    }
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM websites WHERE domain = "x.example"'));
    eq('active', T::db()->value('SELECT status FROM websites WHERE id = ?', [$id]));
    ok((int) T::db()->value('SELECT COUNT(*) FROM audit_log WHERE outcome = "denied" AND actor_user_id = ?', [$w3->director['id']]) >= count($checks));
    $nav = $c->get('/')->body;
    not_contains('>Diagnostics<', $nav);
    contains('>Audit log<', $nav);
    eq(200, $c->get('/audit')->status, 'Director reads the business audit log');
});

test($G, 'Director cannot create, modify, reset or promote to Developer', function () use ($w3) {
    $c = $w3->c;
    eq(403, $c->post('/users', ['name' => 'Sneaky', 'email' => 'sneaky@example.test', 'role' => 'developer', 'password_mode' => 'temporary'])->status);
    eq(0, (int) T::db()->value('SELECT COUNT(*) FROM users WHERE email = "sneaky@example.test"'));
    $devId = $w3->dev['id'];
    eq(403, $c->post("/users/{$devId}", ['name' => 'Hacked', 'email' => $w3->dev['email'], 'role' => 'user', 'status' => 'inactive'])->status);
    eq(403, $c->post("/users/{$devId}/password", ['password_mode' => 'temporary'])->status);
    eq($w3->dev['password_hash'], T::db()->value('SELECT password_hash FROM users WHERE id = ?', [$devId]));
    eq('developer', T::db()->value('SELECT role FROM users WHERE id = ?', [$devId]));
    eq(403, $c->post("/users/{$w3->userId}", ['name' => 'Plain User', 'email' => 'user-w3@example.test', 'role' => 'developer', 'status' => 'active'])->status, 'promote user');
    eq('user', T::db()->value('SELECT role FROM users WHERE id = ?', [$w3->userId]));
    $page = $c->get("/users/{$devId}")->body;
    contains('cannot modify Developer accounts', $page);
});

test($G, 'the last active Developer cannot be demoted or deactivated', function () use ($w3) {
    // Make this Developer the only active one.
    T::db()->run('UPDATE users SET status = "inactive" WHERE role = "developer" AND id <> ?', [$w3->dev['id']]);
    $dev = T::db()->one('SELECT * FROM users WHERE id = ?', [$w3->dev['id']]);
    $svc = new App\Domain\UserService(T::db(), new App\Domain\AuditLog(T::db()));
    try {
        $svc->update($dev, (int) $dev['id'], ['role' => 'director']);
        ok(false, 'demotion should fail');
    } catch (App\Domain\ValidationError $e) {
        contains('last active Developer', $e->errors['role']);
    }
    $other = make_user('developer', 'dev2-w3@example.test');
    $svc->update($other, (int) $other['id'], ['role' => 'developer']);
    // With a second active Developer, demoting the first is allowed.
    $svc->update($other, (int) $dev['id'], ['role' => 'director']);
    eq('director', T::db()->value('SELECT role FROM users WHERE id = ?', [$dev['id']]));
    try {
        $svc->update(['id' => 0, 'role' => 'developer', 'status' => 'active', 'email' => 'x'], (int) $other['id'], ['status' => 'inactive']);
        ok(false, 'deactivating the last developer should fail');
    } catch (App\Domain\ValidationError $e) {
        ok(true);
    }
    // Restore for later tests.
    T::db()->run('UPDATE users SET role = "developer" WHERE id = ?', [$dev['id']]);
});

test($G, 'deactivating an account ends its sessions immediately', function () use ($w3) {
    $u = make_user('user', 'deact-w3@example.test');
    $uc = new Client();
    $uc->login('deact-w3@example.test', 'correct horse battery');
    eq(200, $uc->get('/inbox')->status);
    eq(303, $w3->c->post('/users/' . $u['id'], ['name' => $u['name'], 'email' => $u['email'], 'role' => 'user', 'status' => 'inactive'])->status);
    $r = $uc->get('/inbox');
    eq(303, $r->status);
    contains('/login', location($r));
    $r = (new Client())->login('deact-w3@example.test', 'correct horse battery');
    eq(422, $r->status, 'inactive account cannot sign in');
});
