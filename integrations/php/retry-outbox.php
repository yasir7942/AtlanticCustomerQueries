<?php

declare(strict_types=1);

/**
 * Cron script: deliver inquiries saved in the outbox (same submission_id, so no duplicates).
 * Cron (every 10 minutes): php /home/USER/private/retry-outbox.php
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/InquiryClient.php';
$config = is_file(__DIR__ . '/inquiry-config.php') ? require __DIR__ . '/inquiry-config.php' : [];
$client = new InquiryClient(
    $config['api_url'] ?? (string) getenv('INQUIRY_API_URL'),
    $config['api_key'] ?? (string) getenv('INQUIRY_API_KEY'),
);
$stats = (new InquiryOutbox($config['outbox_dir'] ?? __DIR__ . '/inquiry-outbox'))->retryDue($client);
echo json_encode($stats), "\n";
