<?php

namespace App\Commands;

use App\Libraries\InquiryForwarder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark inquiry:retry — deliver queued inquiries (run every 10 minutes from cron).
 */
class InquiryRetry extends BaseCommand
{
    protected $group = 'Inquiry';
    protected $name = 'inquiry:retry';
    protected $description = 'Retry inquiries that could not be delivered.';

    public function run(array $params)
    {
        $forwarder = new InquiryForwarder();
        foreach (glob(WRITEPATH . 'inquiry-outbox/*.json') ?: [] as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (!is_array($payload)) {
                continue;
            }
            $result = $forwarder->send($payload, 1);
            if ($result['ok']) {
                unlink($file);
                CLI::write('Delivered ' . $payload['submission_id']);
            } elseif (!$result['retryable']) {
                rename($file, $file . '.failed');
                CLI::error('Rejected ' . $payload['submission_id'] . ' (HTTP ' . $result['status'] . ')');
            }
        }
    }
}
