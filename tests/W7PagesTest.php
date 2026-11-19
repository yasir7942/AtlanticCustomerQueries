<?php

declare(strict_types=1);

$G = 'Pages: every screen renders for each role (warnings fail the run)';

test($G, 'Developer, Director and User can open every page their role allows', function () {
    $site = make_website('Pages Site', 'pages.example', 'shopify');
    $key = make_key((int) $site['id']);
    $inq = api($key, sample_payload('pages-1', ['form_type' => 'quote', 'products' => [['name' => 'P', 'quantity' => 1]], 'fields' => ['a' => 'b']]))['body']['inquiry_id'];
    T::db()->run('UPDATE users SET password_hash = ? WHERE role = "developer"', [App\Auth\Passwords::hash('developer passphrase')]);
    $dev = T::db()->one('SELECT * FROM users WHERE role = "developer" AND status = "active" ORDER BY id LIMIT 1');
    make_user('director', 'pages-dir@example.test');
    $user = make_user('user', 'pages-user@example.test');
    T::db()->run('INSERT INTO website_user (user_id, website_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$user['id'], $site['id']]);

    $common = ['/', '/?period=today', '/?period=7d', '/?period=custom&from=2026-01-01&to=2026-02-01', '/inbox', '/inbox?q=x&type=quote&status=new&from=2026-01-01&to=2030-01-01&tests=hide&page=2',
        "/inbox/{$inq}", '/reports', '/reports?period=90d', '/profile', '/password/change'];
    $roles = [
        'developer' => [$dev['email'], 'developer passphrase', array_merge($common, ['/websites', '/websites/new', "/websites/{$site['id']}", '/users', '/users/new', "/users/{$user['id']}", "/users/{$dev['id']}", '/audit', '/audit?action=user.', '/diagnostics', '/diagnostics?errors=1&website=' . $site['id']])],
        'director' => ['pages-dir@example.test', 'correct horse battery', array_merge($common, ['/websites', "/websites/{$site['id']}", '/users', '/users/new', "/users/{$user['id']}", "/users/{$dev['id']}", '/audit'])],
        'user' => ['pages-user@example.test', 'correct horse battery', $common],
    ];
    foreach ($roles as $role => [$email, $pw, $paths]) {
        $c = new Client();
        eq(303, $c->login($email, $pw)->status, "{$role} login");
        foreach ($paths as $p) {
            [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($qs, $q);
            $r = $c->get($path, $q);
            eq(200, $r->status, "{$role} GET {$p}");
            contains('</html>', $r->body, "{$role} GET {$p} complete");
        }
        if ($role === 'developer') {
            foreach (array_keys(App\Domain\WebsiteService::PLATFORMS) as $platform) {
                $r = $c->get("/websites/{$site['id']}/connect", ['platform' => $platform]);
                eq(200, $r->status, "guide {$platform}");
                not_contains('(missing file:', $r->body, "guide {$platform} includes resolve");
                contains('http://inquiries.test/api/v1/submissions', $r->body, "guide {$platform} endpoint placeholder");
                not_contains('{{ENDPOINT}}', $r->body);
            }
            $zip = $c->get('/downloads/wordpress-plugin');
            eq(200, $zip->status);
            eq('application/zip', $zip->headers['Content-Type']);
            ok(str_starts_with($zip->body, "PK"), 'zip bytes');
        }
    }
    // Unknown pages and methods.
    eq(404, (new Client())->get('/nope')->status);
    eq(303, (new Client())->get('/inbox')->status, 'guests are sent to sign in');
});

test($G, 'after sign-in the user returns to the page they asked for (same-app paths only)', function () {
    make_user('director', 'intended@example.test');
    $c = new Client();
    eq(303, $c->get('/inbox', ['q' => 'two words', 'type' => 'quote'])->status);
    $r = $c->login('intended@example.test', 'correct horse battery');
    eq('/inbox?q=two+words&type=quote', location($r));
    $c2 = new Client();
    $c2->session->set('intended', '//evil.example/phish');
    $c2->get('/login');
    eq('/', location($c2->post('/login', ['email' => 'intended@example.test', 'password' => 'correct horse battery'])), 'no open redirect');
});

test($G, 'the whole application is hidden from search engines', function () {
    $robots = (string) file_get_contents(T::$root . '/public/robots.txt');
    contains("User-agent: *\nDisallow: /", str_replace("\r", '', $robots), 'robots.txt blocks every crawler');
    $guest = new Client();
    $login = $guest->get('/login');
    eq(App\App::ROBOTS, $login->headers['X-Robots-Tag'] ?? null, 'sign-in page header');
    contains('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">', $login->body);
    eq(App\App::ROBOTS, $guest->get('/nope')->headers['X-Robots-Tag'] ?? null, 'error pages too');
    make_user('user', 'robots@example.test');
    $c = new Client();
    $c->login('robots@example.test', 'correct horse battery');
    $home = $c->get('/');
    eq(App\App::ROBOTS, $home->headers['X-Robots-Tag'] ?? null, 'signed-in pages');
    contains('<meta name="googlebot" content="noindex', $home->body);
    eq(App\App::ROBOTS, api(null, [])['headers']['X-Robots-Tag'] ?? null, 'API responses');
    contains('X-Robots-Tag "' . App\App::ROBOTS . '"', (string) file_get_contents(T::$root . '/public/.htaccess'), 'static files via .htaccess');
});
