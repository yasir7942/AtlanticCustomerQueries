<?php

declare(strict_types=1);

/**
 * Synthetic demo data for local development (never real customers).
 *   php -d extension=pdo_mysql database/fixtures/seed.php
 * Creates 3 websites (with keys), a Director and a User (password: "demo passphrase 1"),
 * and ~60 inquiries over the last 30 days including Arabic text, multi-product quotes and
 * sample requests with custom fields. Refuses to run when APP_ENV=production.
 */
require dirname(__DIR__, 2) . '/bin/lib/bootstrap.php';

use App\Domain\AuditLog;
use App\Domain\CredentialService;
use App\Domain\Submissions\SubmissionIntake;
use App\Domain\Submissions\SubmissionValidator;
use App\Domain\WebsiteService;

$config = App\Support\Config::fromEnvFile($root);
if ($config->get('env') === 'production') {
    fwrite(STDERR, "Refusing to seed demo data into a production environment.\n");
    exit(1);
}
$db = App\Support\Database::connect($config->get('db'));
$dev = $db->one('SELECT * FROM users WHERE role = "developer" AND status = "active" ORDER BY id LIMIT 1');
if ($dev === null) {
    fwrite(STDERR, "Create the first Developer first: php bin/create-developer.php --name=... --email=...\n");
    exit(1);
}
mt_srand(20261118);
$audit = new AuditLog($db, 'seed');
$creds = new CredentialService($db, $audit);
$sites = new WebsiteService($db, $audit, $creds);

$defs = [
    ['Atlantic Valves', 'demo-valves.example', 'wordpress'],
    ['Gulf Pipe Supply', 'demo-pipes.example', 'laravel'],
    ['Coastal Finishes', 'demo-finishes.example', 'shopify'],
];
$websiteIds = [];
foreach ($defs as [$name, $domain, $platform]) {
    $existing = $db->one('SELECT * FROM websites WHERE domain = ?', [$domain]);
    $site = $existing ?? $sites->create($dev, ['name' => $name, 'domain' => $domain, 'platform' => $platform, 'rate_limit_per_minute' => '120']);
    if (!$existing) {
        $creds->issue($dev, (int) $site['id'], 'demo');
    }
    $websiteIds[] = (int) $site['id'];
}

$now = utc_now();
$hash = App\Auth\Passwords::hash('demo passphrase 1');
foreach ([['Demo Director', 'director@demo.example', 'director', 'Asia/Dubai'], ['Demo User', 'user@demo.example', 'user', 'Asia/Karachi']] as [$n, $e, $r, $tz]) {
    if ($db->value('SELECT id FROM users WHERE email = ?', [$e]) === null) {
        $id = $db->insert('INSERT INTO users (name, email, password_hash, role, status, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, "active", ?, ?, ?)', [$n, $e, $hash, $r, $tz, $now, $now]);
        if ($r === 'user') {
            $db->run('INSERT INTO website_user (user_id, website_id, created_at) VALUES (?, ?, ?)', [$id, $websiteIds[0], $now]);
        }
    }
}

$people = [
    ['Sara Ahmed', 'Example Trading LLC', 'sara@example.com', '+971 50 000 1001', 'United Arab Emirates', 'Dubai'],
    ['أحمد علي', 'مؤسسة النخيل', '', '+966 55 000 2002', 'Saudi Arabia', 'Riyadh'],
    ['James Carter', '', 'james.carter@example.net', '+44 20 0000 3003', 'United Kingdom', 'London'],
    ['Fatima Khan', 'Khan Builders', 'fatima@example.org', '+92 300 0000004', 'Pakistan', 'Karachi'],
    ['Layla Haddad', 'Haddad Interiors', 'layla@example.com', '', 'Jordan', 'Amman'],
    ['Omar Nasser', 'Nasser Contracting', 'omar@example.com', '+968 9000 0006', 'Oman', 'Muscat'],
];
$messages = [
    'Please send pricing and lead time for delivery to Jebel Ali.',
    "السلام عليكم،\nنرغب في الحصول على عرض سعر مع مدة التوريد.",
    'Can someone call me tomorrow morning about a replacement part?',
    'We are tendering for a hospital project and need datasheets and certificates.',
    '',
];
$products = [['Ball valve DN50', 'BV-50'], ['Gate valve DN80', 'GV-80'], ['PVC pipe 110mm', 'PVC-110'], ['Flange kit', 'FK-1'], ['Copper tap', 'CT-01']];
$intake = new SubmissionIntake($db);
$validator = new SubmissionValidator();
$created = 0;
for ($i = 1; $i <= 60; $i++) {
    $p = $people[mt_rand(0, count($people) - 1)];
    $type = ['contact', 'contact', 'quote', 'quote', 'sample', 'other'][mt_rand(0, 5)];
    $payload = [
        'submission_id' => 'demo-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'form_type' => $type,
        'form_id' => $type . '-form',
        'locale' => preg_match('/\p{Arabic}/u', $p[0]) ? 'ar' : 'en',
        'full_name' => $p[0], 'company' => $p[1], 'email' => $p[2], 'phone' => $p[3], 'country' => $p[4], 'city' => $p[5],
        'message' => $messages[mt_rand(0, count($messages) - 1)],
        'source' => ['page_url' => 'https://' . $defs[$i % 3][1] . '/' . $type, 'ip' => '203.0.113.' . mt_rand(1, 250), 'email' => ['sent' => mt_rand(0, 9) > 0, 'attempts' => 1]],
    ];
    if ($type === 'quote') {
        foreach (array_slice($products, mt_rand(0, 2), mt_rand(1, 3)) as [$name, $sku]) {
            $payload['products'][] = ['name' => $name, 'sku' => $sku, 'quantity' => mt_rand(1, 200), 'unit' => 'pcs'];
        }
    }
    if ($type === 'sample') {
        $payload['fields'] = [
            'swatches' => ['label' => 'Swatches', 'value' => ['Sand', 'Charcoal', 'Oak'][mt_rand(0, 2)] === 'Oak' ? ['Oak', 'Walnut'] : ['Sand', 'Charcoal']],
            'delivery_address' => ['label' => 'Delivery address', 'value' => 'Warehouse 4, Al Quoz'],
            'project_size_m2' => mt_rand(50, 900),
        ];
    }
    $payload = array_filter($payload, static fn ($v) => $v !== '' && $v !== []);
    $v = $validator->validate($payload, null);
    if (!$v['ok']) {
        fwrite(STDERR, 'Invalid fixture: ' . json_encode($v['errors']) . "\n");
        continue;
    }
    $res = $intake->store($websiteIds[$i % 3], (int) $db->value('SELECT id FROM api_credentials WHERE website_id = ? LIMIT 1', [$websiteIds[$i % 3]]), $v['data'], SubmissionIntake::payloadHash($payload), '127.0.0.1');
    if ($res['result'] === 'created') {
        $created++;
        $ago = mt_rand(0, 29 * 86400);
        $db->run('UPDATE submissions SET received_at = ?, status = ? WHERE id = ?', [
            gmdate('Y-m-d H:i:s', time() - $ago), ['new', 'new', 'open', 'closed'][mt_rand(0, 3)], $res['submission']['id'],
        ]);
    }
}
echo "Seeded {$created} inquiries across " . count($websiteIds) . " websites.\n";
echo "Demo accounts: director@demo.example / user@demo.example — password: demo passphrase 1\n";
