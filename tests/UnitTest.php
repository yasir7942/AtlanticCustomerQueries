<?php

declare(strict_types=1);

use App\Domain\Submissions\Presenter;
use App\Domain\Submissions\SubmissionIntake;
use App\Domain\Submissions\SubmissionValidator;
use App\Support\Markdown;
use App\Support\Time;

$G = 'Unit: time zones, validation, rendering helpers';

test($G, '19:30 UTC shows as 23:30 same day in Dubai and 00:30 next day in Karachi', function () {
    eq('2026-11-18 23:30', Time::local('2026-11-18 19:30:00', 'Asia/Dubai', 'Y-m-d H:i'));
    eq('2026-11-19 00:30', Time::local('2026-11-18 19:30:00', 'Asia/Karachi', 'Y-m-d H:i'));
});

test($G, 'local date boundaries convert to UTC before querying', function () {
    eq(['2026-11-17 20:00:00', '2026-11-18 20:00:00'], Time::utcRange('2026-11-18', '2026-11-18', 'Asia/Dubai'));
    eq(['2026-11-18 19:00:00', '2026-11-19 19:00:00'], Time::utcRange('2026-11-19', '2026-11-19', 'Asia/Karachi'));
    // 19:30 UTC lies inside Dubai's 18 Nov and Karachi's 19 Nov.
    [$s, $e] = Time::utcRange('2026-11-18', '2026-11-18', 'Asia/Dubai');
    ok('2026-11-18 19:30:00' >= $s && '2026-11-18 19:30:00' < $e);
    [$s, $e] = Time::utcRange('2026-11-18', '2026-11-18', 'Asia/Karachi');
    ok(!('2026-11-18 19:30:00' >= $s && '2026-11-18 19:30:00' < $e), 'must not be in Karachi 18 Nov');
});

test($G, 'DST zones get correct boundaries (Europe/London, 29 Mar 2026)', function () {
    eq(['2026-03-29 00:00:00', '2026-03-29 23:00:00'], Time::utcRange('2026-03-29', '2026-03-29', 'Europe/London'));
});

test($G, 'source timestamps parse to UTC', function () {
    eq('2026-11-18 19:30:00', Time::parseToUtc('2026-11-18T19:30:00Z'));
    eq('2026-11-18 19:30:00', Time::parseToUtc('2026-11-18T23:30:00+04:00'));
    eq('2026-11-18 19:30:00', Time::parseToUtc('2026-11-18 19:30:00'));
    eq(null, Time::parseToUtc('18/11/2026'));
});

test($G, 'validator keeps phone/postcode as strings and rejects numbers', function () {
    $v = (new SubmissionValidator())->validate(['submission_id' => 'a1', 'form_type' => 'contact', 'phone' => 971500000000, 'postcode' => 1234], null);
    ok(!$v['ok']);
    ok(isset($v['errors']['phone'], $v['errors']['postcode']));
    $v = (new SubmissionValidator())->validate(['submission_id' => 'a1', 'form_type' => 'contact', 'phone' => '+971 050 000', 'postcode' => '00123'], null);
    ok($v['ok']);
    eq('00123', $v['data']['postcode']);
    eq('+971 050 000', $v['data']['phone']);
});

test($G, 'validator preserves Arabic and allows omitted email/company/address', function () {
    $msg = "مرحبا، أريد عرض سعر لـ 50 قطعة.\nشكراً";
    $v = (new SubmissionValidator())->validate(['submission_id' => 'ar-1', 'form_type' => 'quote', 'locale' => 'ar', 'full_name' => 'أحمد علي', 'message' => $msg], null);
    ok($v['ok'], json_encode($v['errors']));
    eq($msg, $v['data']['message']);
    eq('', $v['data']['email']);
    eq('', $v['data']['company']);
});

test($G, 'validator rejects unknown top-level fields (website_id cannot be supplied)', function () {
    $v = (new SubmissionValidator())->validate(['submission_id' => 'x', 'form_type' => 'contact', 'email' => 'a@b.co', 'website_id' => 2], null);
    ok(!$v['ok']);
    ok(isset($v['errors']['website_id']));
});

test($G, 'validator requires submission_id or Idempotency-Key and they must match', function () {
    $v = (new SubmissionValidator())->validate(['form_type' => 'contact', 'email' => 'a@b.co'], null);
    ok(isset($v['errors']['submission_id']));
    $v = (new SubmissionValidator())->validate(['form_type' => 'contact', 'email' => 'a@b.co'], 'hdr-123');
    ok($v['ok']);
    eq('hdr-123', $v['data']['submission_uid']);
    $v = (new SubmissionValidator())->validate(['submission_id' => 'body-1', 'form_type' => 'contact', 'email' => 'a@b.co'], 'hdr-2');
    ok(isset($v['errors']['submission_id']));
});

test($G, 'validator checks products and custom fields shapes', function () {
    $v = (new SubmissionValidator())->validate([
        'submission_id' => 'q1', 'form_type' => 'quote',
        'products' => [['name' => 'Valve', 'quantity' => '12'], ['sku' => 'X-1', 'quantity' => 2.5, 'unit' => 'm'], ['quantity' => 3]],
        'fields' => ['finish' => 'Matte', 'colours' => ['Red', 'Blue'], 'nested' => ['a' => ['b' => 1]]],
    ], null);
    ok(!$v['ok']);
    ok(isset($v['errors']['products[2].name']), 'product without name/sku');
    ok(isset($v['errors']['fields.nested']), 'nested objects rejected');
    eq(12, $v['data']['products'][0]['quantity']);
});

test($G, 'payload hash ignores key order but not values', function () {
    $a = SubmissionIntake::payloadHash(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
    $b = SubmissionIntake::payloadHash(['a' => ['x' => 1, 'y' => 2], 'b' => 1]);
    $c = SubmissionIntake::payloadHash(['a' => ['x' => 1, 'y' => 3], 'b' => 1]);
    eq($a, $b);
    ok($a !== $c);
});

test($G, 'clean() strips control and bidi-override characters but keeps RTL marks', function () {
    eq("a\nb", SubmissionValidator::clean("a\r\nb\x07", true));
    eq('x y', SubmissionValidator::clean("x\ny", false));
    eq("ab\u{200F}", SubmissionValidator::clean("a\u{202E}b\u{200F}"));
});

test($G, 'presenter summary covers products, message and custom fields', function () {
    $s = Presenter::summary(['products' => json_encode([['name' => 'Pump', 'quantity' => 4], ['name' => 'Seal', 'quantity' => 100]]), 'message' => 'Urgent']);
    contains('2 products: Pump × 4, Seal × 100', $s);
    contains('Urgent', $s);
    $s = Presenter::summary(['custom_fields' => json_encode(['sample_size' => ['value' => 'A4'], 'colour' => ['label' => 'Colour', 'value' => 'Grey']])]);
    contains('Sample size: A4', $s);
});

test($G, 'markdown renderer escapes HTML and only links http(s)', function () {
    $html = (new Markdown(T::$root))->render("# Title <b>\n\nText <script>x</script> [a](javascript:alert(1)) [ok](https://example.com)\n\n```\n<?php echo '<x>'; ?>\n```");
    not_contains('<script>', $html);
    not_contains('href="javascript', $html);
    contains('href="https://example.com"', $html);
    contains('&lt;?php echo', $html);
});

test($G, 'CSV cells starting with formula characters are neutralized', function () {
    eq("'=HYPERLINK(1)", App\Controllers\InboxController::csvSafe('=HYPERLINK(1)'));
    eq('Normal', App\Controllers\InboxController::csvSafe('Normal'));
});
