<?php

declare(strict_types=1);

/**
 * Send a test inquiry from the command line to confirm the connection.
 *   INQUIRY_API_URL=https://inquiries.example.com/api/v1/submissions INQUIRY_API_KEY=aiq_... php send-test.php
 * The inquiry appears in the inbox with a "Test" tag.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/InquiryClient.php';
$client = new InquiryClient((string) getenv('INQUIRY_API_URL'), (string) getenv('INQUIRY_API_KEY'));
$id = InquiryClient::newSubmissionId('test');
$payload = [
    'submission_id' => $id,
    'form_type' => 'contact',
    'form_id' => 'connection-test',
    'full_name' => 'Connection test',
    'message' => 'Test inquiry sent at ' . gmdate('c'),
    'test' => true,
];
$first = $client->send($payload);
echo "First send:  HTTP {$first->status} " . json_encode($first->body) . "\n";
$retry = $client->send($payload);
echo "Retry (same submission_id): HTTP {$retry->status} " . json_encode($retry->body) . "\n";
exit($first->ok && $retry->ok && $first->inquiryId() === $retry->inquiryId() ? 0 : 1);
