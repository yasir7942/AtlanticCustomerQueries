<?php

namespace App\Jobs;

use App\Services\InquiryForwarder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queued delivery. The payload (including submission_id) is serialized with the job,
 * so every retry sends the identical request and the Inquiry Manager stores it once.
 */
class ForwardInquiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public array $payload)
    {
    }

    /** Seconds between attempts: 1 min, 5 min, 15 min, 1 h, 3 h, ... */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800, 21600, 43200];
    }

    public function handle(InquiryForwarder $forwarder): void
    {
        $result = $forwarder->send($this->payload);
        if ($result['ok']) {
            return;
        }
        if ($result['status'] === 409) {
            // The same form token was already stored with other content (edited resubmission). Nothing to retry.
            Log::info('Inquiry already stored under this submission_id', ['submission_id' => $this->payload['submission_id']]);
            return;
        }
        if (!$result['retryable']) {
            // 4xx: validation or key problem. Do not retry; log field names only (no personal data).
            Log::warning('Inquiry rejected', ['status' => $result['status'], 'code' => $result['code'], 'fields' => array_keys($result['fields'])]);
            $this->fail(new RuntimeException('Inquiry rejected: ' . $result['code']));
            return;
        }
        if (!empty($result['retry_after'])) {
            $this->release($result['retry_after']);
            return;
        }
        throw new RuntimeException('Inquiry delivery failed with HTTP ' . $result['status'] . '; will retry.');
    }
}
