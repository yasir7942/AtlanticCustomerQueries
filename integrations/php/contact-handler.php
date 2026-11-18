<?php

declare(strict_types=1);

/**
 * Example form handler for a plain PHP website (place in the site's web root, e.g. /contact-submit.php).
 * The HTML form posts here; this script forwards the inquiry server-to-server.
 *
 * Configuration lives OUTSIDE the web root:
 *   ../private/inquiry-config.php  returns ['api_url' => 'https://inquiries.example.com/api/v1/submissions',
 *                                           'api_key' => 'aiq_...', 'outbox_dir' => __DIR__ . '/inquiry-outbox']
 * or use environment variables INQUIRY_API_URL / INQUIRY_API_KEY.
 */
require __DIR__ . '/../private/InquiryClient.php';
$config = is_file(__DIR__ . '/../private/inquiry-config.php') ? require __DIR__ . '/../private/inquiry-config.php' : [];
$apiUrl = $config['api_url'] ?? getenv('INQUIRY_API_URL') ?: '';
$apiKey = $config['api_key'] ?? getenv('INQUIRY_API_KEY') ?: '';
$outboxDir = $config['outbox_dir'] ?? (__DIR__ . '/../private/inquiry-outbox');

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
// Your own form's CSRF token (generated when rendering the form).
if (!hash_equals($_SESSION['form_token'] ?? '', (string) ($_POST['form_token'] ?? ''))) {
    http_response_code(400);
    exit('Please reload the form and try again.');
}
// Honeypot: real visitors leave this hidden field empty.
if (($_POST['website'] ?? '') !== '') {
    exit('Thank you.');
}

$s = static fn (string $k): string => trim((string) ($_POST[$k] ?? ''));

// One submission ID per rendered form: a double-click or browser resubmit reuses it.
$submissionId = $_SESSION['inquiry_submission_id'] ??= InquiryClient::newSubmissionId('site');

// Map your form fields to the Inquiry Manager contract.
$payload = [
    'submission_id' => $submissionId,
    'form_type' => $s('form_type') === 'quote' ? 'quote' : 'contact',
    'form_id' => 'contact-page',
    // No submitted_at here: a browser resubmission must produce the identical payload (receipt time is recorded by the API).
    'locale' => 'en',
    'full_name' => $s('name'),
    'company' => $s('company'),
    'email' => $s('email'),
    'phone' => $s('phone'),          // always a string
    'country' => $s('country'),
    'message' => $s('message'),
    'source' => [
        'page_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ],
];
// Optional product lines: product[] and qty[] arrays.
foreach ((array) ($_POST['product'] ?? []) as $i => $name) {
    if (trim((string) $name) !== '') {
        $payload['products'][] = ['name' => trim((string) $name), 'quantity' => (float) ($_POST['qty'][$i] ?? 1)];
    }
}
// Anything else becomes a custom field.
if ($s('preferred_contact') !== '') {
    $payload['fields']['preferred_contact'] = ['label' => 'Preferred contact method', 'value' => $s('preferred_contact')];
}
$payload = array_filter($payload, static fn ($v) => $v !== '' && $v !== []);

$client = new InquiryClient($apiUrl, $apiKey);
$result = $client->send($payload);

if ($result->ok) {
    unset($_SESSION['inquiry_submission_id']);
    // Keep sending your existing notification email here if you have one.
    header('Location: /thank-you.html', true, 303);
    exit;
}
if ($result->retryable) {
    // Saved with the same submission_id; retry-outbox.php delivers it later without duplicates.
    (new InquiryOutbox($outboxDir))->save($payload, $result);
    unset($_SESSION['inquiry_submission_id']);
    header('Location: /thank-you.html', true, 303);
    exit;
}
// 4xx: the data needs fixing (e.g. invalid email). Nothing was stored, so the corrected form gets a new ID.
unset($_SESSION['inquiry_submission_id']);
http_response_code(422);
error_log('Inquiry rejected: HTTP ' . $result->status . ' ' . implode(',', array_keys($result->fieldErrors())));
echo 'Please check: ' . htmlspecialchars(implode(' ', $result->fieldErrors()) ?: (string) $result->error, ENT_QUOTES, 'UTF-8');
