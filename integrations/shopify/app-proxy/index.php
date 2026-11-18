<?php

declare(strict_types=1);

/**
 * Shopify App Proxy relay: storefront form -> Shopify (/apps/inquiries/...) -> this script -> Inquiry Manager API.
 *
 * Shopify signs every proxied request with the app's client secret (query parameter "signature").
 * This script verifies it, maps the form fields and forwards them server-to-server with the
 * website's bearer key. Neither secret ever appears in Liquid, HTML or browser JavaScript.
 *
 * Deploy (e.g. on the same Hostinger account): public_html/shopify-proxy/index.php (this file)
 * plus, OUTSIDE public_html, the folder named by SHOPIFY_PROXY_CONFIG (default ../../shopify-proxy-private/)
 * containing config.php, InquiryClient.php and a writable outbox/ directory.
 */

$configFile = getenv('SHOPIFY_PROXY_CONFIG') ?: dirname(__DIR__, 2) . '/shopify-proxy-private/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Proxy not configured.');
}
/** @var array{shopify_client_secret:string,shop_domain:string,inquiry_api_url:string,inquiry_api_key:string,outbox_dir:string,client_file?:string} $config */
$config = require $configFile;
require $config['client_file'] ?? dirname($configFile) . '/InquiryClient.php';

/** Reply with Liquid that Shopify renders inside the store theme. Never echo visitor input here. */
function liquid_reply(int $status, string $title, string $text): never
{
    http_response_code($status);
    header('Content-Type: application/liquid; charset=utf-8');
    header('Cache-Control: no-store');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $p = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    echo "<div class=\"page-width\" style=\"padding:3rem 0;max-width:640px\">\n<h1>{$t}</h1>\n<p>{$p}</p>\n"
        . "<p><a href=\"{{ routes.root_url }}\">Continue shopping</a></p>\n</div>\n";
    exit;
}

/**
 * Verify Shopify's app proxy signature: HMAC-SHA256 (hex) over the sorted "key=value" pairs of every
 * query parameter except "signature", concatenated with no separator; repeated keys join values with ",".
 */
function shopify_proxy_signature_valid(string $queryString, string $secret): bool
{
    $grouped = [];
    foreach (explode('&', $queryString) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $k = urldecode($k);
        $grouped[$k][] = urldecode($v);
    }
    $provided = $grouped['signature'][0] ?? '';
    unset($grouped['signature']);
    $parts = [];
    foreach ($grouped as $k => $values) {
        $parts[] = $k . '=' . implode(',', $values);
    }
    sort($parts, SORT_STRING);
    $expected = hash_hmac('sha256', implode('', $parts), $secret);
    return $provided !== '' && hash_equals($expected, $provided);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    liquid_reply(405, 'Inquiry form', 'Please use the form on our website to send an inquiry.');
}
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
if (!shopify_proxy_signature_valid($query, $config['shopify_client_secret'])) {
    liquid_reply(401, 'Request not accepted', 'This request could not be verified. Please submit the form again from our store.');
}
$timestamp = (int) ($_GET['timestamp'] ?? 0);
if (abs(time() - $timestamp) > 600) {
    liquid_reply(401, 'Request expired', 'The form took too long to submit. Please reload the page and try again.');
}
if (strcasecmp((string) ($_GET['shop'] ?? ''), $config['shop_domain']) !== 0) {
    liquid_reply(403, 'Request not accepted', 'This store is not configured for inquiries.');
}
// Honeypot: real visitors never fill this hidden field.
if (trim((string) ($_POST['contact']['website'] ?? '')) !== '') {
    liquid_reply(200, 'Thank you', 'Your inquiry has been received.');
}

$contact = is_array($_POST['contact'] ?? null) ? $_POST['contact'] : [];
$c = static fn (string $k, int $max = 500): string => mb_substr(trim((string) ($contact[$k] ?? '')), 0, $max);
$type = in_array($_POST['inquiry_type'] ?? '', ['contact', 'quote', 'sample'], true) ? $_POST['inquiry_type'] : 'contact';

// Stable ID: the browser generates inquiry_token once per form view, so a double submit is not duplicated.
$token = (string) ($_POST['inquiry_token'] ?? '');
$submissionId = preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)
    ? 'shopify-' . substr(hash('sha256', $config['shop_domain'] . '|' . $token), 0, 40)
    : InquiryClient::newSubmissionId('shopify');

$payload = [
    'submission_id' => $submissionId,
    'form_type' => $type,
    'form_id' => 'shopify-' . $type,
    'form_name' => 'Storefront ' . $type . ' form',
    'locale' => preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', (string) ($_POST['locale'] ?? '')) ? $_POST['locale'] : 'en',
    'full_name' => $c('name', 190),
    'company' => $c('company', 190),
    'email' => $c('email', 190),
    'phone' => $c('phone', 50),
    'country' => $c('country', 100),
    'message' => mb_substr(trim((string) ($contact['body'] ?? '')), 0, 20000),
];
foreach (array_slice(is_array($_POST['products'] ?? null) ? $_POST['products'] : [], 0, 50) as $p) {
    if (!is_array($p) || trim((string) ($p['title'] ?? '')) === '') {
        continue;
    }
    $line = ['name' => mb_substr(trim((string) $p['title']), 0, 200)];
    if (!empty($p['sku'])) {
        $line['sku'] = mb_substr(trim((string) $p['sku']), 0, 100);
    }
    if (!empty($p['variant'])) {
        $line['variant'] = mb_substr(trim((string) $p['variant']), 0, 200);
    }
    if (isset($p['quantity']) && is_numeric($p['quantity'])) {
        $line['quantity'] = max(0, 0 + $p['quantity']);
    }
    $payload['products'][] = $line;
}
foreach (array_slice(is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [], 0, 30, true) as $k => $v) {
    $label = mb_substr(trim((string) $k), 0, 100);
    if ($label !== '' && is_scalar($v) && trim((string) $v) !== '') {
        $payload['fields'][$label] = ['label' => $label, 'value' => mb_substr(trim((string) $v), 0, 5000)];
    }
}
$customerId = (string) ($_GET['logged_in_customer_id'] ?? '');
if ($customerId !== '' && ctype_digit($customerId)) {
    $payload['fields']['shopify_customer_id'] = ['label' => 'Shopify customer ID', 'value' => $customerId];
}
$pageUrl = 'https://' . $config['shop_domain'] . (string) ($_GET['path_prefix'] ?? '');
$payload['source'] = ['page_url' => $pageUrl];
$payload = array_filter($payload, static fn ($v) => $v !== '' && $v !== []);

$result = (new InquiryClient($config['inquiry_api_url'], $config['inquiry_api_key'], 8, 2))->send($payload);
if ($result->ok) {
    liquid_reply(200, 'Thank you', 'Your inquiry has been received. Our team will reply shortly.');
}
if ($result->retryable) {
    (new InquiryOutbox($config['outbox_dir']))->save($payload, $result);
    liquid_reply(200, 'Thank you', 'Your inquiry has been received. Our team will reply shortly.');
}
error_log('Shopify inquiry rejected: HTTP ' . $result->status . ' ' . implode(',', array_keys($result->fieldErrors())));
liquid_reply(422, 'Please check the form', 'Some details could not be accepted (for example the email address). Please go back and correct them.');
