<?php

declare(strict_types=1);

$G = 'Workflow 2: User scoped to one website; revocation ends access';

$w2 = new stdClass();

test($G, 'setup: two websites with distinctive inquiries, one User assigned to the first', function () use ($w2) {
    $w2->a = make_website('Scope A', 'scope-a.example');
    $w2->b = make_website('Scope B', 'scope-b.example');
    $ka = make_key((int) $w2->a['id']);
    $kb = make_key((int) $w2->b['id']);
    $w2->inqA = api($ka, sample_payload('s-a-1', ['full_name' => 'Zebedee Alpha', 'message' => 'needle-alpha']))['body']['inquiry_id'];
    $w2->inqB = api($kb, sample_payload('s-b-1', ['full_name' => 'Quincy Bravo', 'message' => 'needle-bravo', 'form_type' => 'quote']))['body']['inquiry_id'];
    api($kb, sample_payload('s-b-2', ['message' => 'needle-bravo second']));
    ok($w2->inqA > 0 && $w2->inqB > 0);

    $director = make_user('director', 'dir-w2@example.test');
    $w2->user = make_user('user', 'user-w2@example.test');
    $w2->mgr = new Client();
    $w2->mgr->login('dir-w2@example.test', 'correct horse battery');
    $w2->client = new Client();
    $w2->client->login('user-w2@example.test', 'correct horse battery');
});

test($G, 'a new User sees no inquiries until assigned', function () use ($w2) {
    $r = $w2->client->get('/inbox');
    eq(200, $r->status);
    contains('No websites assigned yet', $r->body);
    not_contains('Zebedee', $r->body);
    contains('No websites assigned yet', $w2->client->get('/')->body);
    eq(404, $w2->client->get('/inbox/' . $w2->inqA)->status);
});

test($G, 'Director assigns website A to the User', function () use ($w2) {
    $r = $w2->mgr->post('/users/' . $w2->user['id'] . '/websites', ['websites' => [(string) $w2->a['id']]]);
    eq(303, $r->status);
    eq([(int) $w2->a['id']], array_map('intval', array_column(T::db()->all('SELECT website_id FROM website_user WHERE user_id = ?', [$w2->user['id']]), 'website_id')));
});

test($G, 'User reads website A inquiries', function () use ($w2) {
    $r = $w2->client->get('/inbox');
    contains('Zebedee Alpha', $r->body);
    eq(200, $w2->client->get('/inbox/' . $w2->inqA)->status);
});

test($G, 'website B is inaccessible by direct URL, search, filters, counts, reports and CSV', function () use ($w2) {
    $c = $w2->client;
    eq(404, $c->get('/inbox/' . $w2->inqB)->status, 'detail URL');
    eq(404, $c->post('/inbox/' . $w2->inqB . '/status', ['status' => 'closed'])->status, 'status change');
    $search = $c->get('/inbox', ['q' => 'needle-bravo']);
    not_contains('Quincy', $search->body, 'search');
    contains('<strong>0</strong> inquir', $search->body);
    $filtered = $c->get('/inbox', ['website' => (string) $w2->b['id']]);
    contains('<strong>0</strong> inquir', $filtered->body, 'website filter');
    not_contains('Scope B', $c->get('/inbox')->body, 'filter options');
    $dash = $c->get('/');
    not_contains('Scope B', $dash->body, 'dashboard website list');
    not_contains('Quincy', $dash->body);
    $report = $c->get('/reports');
    not_contains('Scope B', $report->body, 'report rows');
    $report = $c->get('/reports', ['website' => (string) $w2->b['id']]);
    not_contains('Scope B', $report->body);
    $csv = $c->get('/inbox/export');
    eq(200, $csv->status);
    contains('Zebedee Alpha', $csv->body);
    not_contains('Quincy', $csv->body, 'CSV export');
    not_contains('needle-bravo', $c->get('/reports/export')->body);
    // Counts: dashboard total equals the scoped inbox total.
    preg_match('#kpi-label">Inquiries</span>\s*<span class="kpi-value">(\d+)#', $dash->body, $m);
    eq('1', $m[1] ?? null, 'dashboard total for scoped user');
});

test($G, 'User cannot reach management pages', function () use ($w2) {
    foreach (['/websites', '/users', '/audit', '/diagnostics', '/websites/' . $w2->a['id'], '/websites/' . $w2->a['id'] . '/connect', '/downloads/wordpress-plugin'] as $path) {
        eq(403, $w2->client->get($path)->status, $path);
    }
    eq(403, $w2->client->post('/users/' . $w2->user['id'] . '/websites', ['websites' => [(string) $w2->b['id']]])->status, 'self-assign');
    not_contains('href="/users"', $w2->client->get('/inbox')->body, 'nav hides Users');
});

test($G, 'revoking the assignment ends access on the next request', function () use ($w2) {
    eq(200, $w2->client->get('/inbox/' . $w2->inqA)->status);
    $w2->mgr->post('/users/' . $w2->user['id'] . '/websites', ['websites' => []]);
    eq(404, $w2->client->get('/inbox/' . $w2->inqA)->status, 'detail after revoke');
    contains('No websites assigned yet', $w2->client->get('/inbox')->body);
    not_contains('Zebedee', $w2->client->get('/inbox/export')->body);
    $audit = T::db()->one('SELECT * FROM audit_log WHERE action = "user.assignments" AND target_id = ? ORDER BY id DESC LIMIT 1', [(string) $w2->user['id']]);
    contains((string) $w2->a['id'], (string) $audit['details']);
});
