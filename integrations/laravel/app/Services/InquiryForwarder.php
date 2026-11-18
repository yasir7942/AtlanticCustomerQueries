<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Forwards inquiries to the Inquiry Manager (server-to-server).
 * Configure in config/services.php ('inquiry' => [...]) and .env (INQUIRY_API_URL, INQUIRY_API_KEY).
 */
class InquiryForwarder
{
    public static function newSubmissionId(): string
    {
        return 'laravel-' . Str::uuid()->toString();
    }

    /**
     * One HTTP attempt. Returns ['ok', 'retryable', 'status', 'inquiry_id', 'code', 'fields'].
     * Retries are handled by the queued job (same submission_id every time).
     */
    public function send(array $payload): array
    {
        try {
            $response = Http::withToken(config('services.inquiry.key'))
                ->withHeaders(['Idempotency-Key' => $payload['submission_id']])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->post(config('services.inquiry.url'), $payload);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'retryable' => true, 'status' => 0, 'inquiry_id' => null, 'code' => 'network_error', 'fields' => []];
        }

        $status = $response->status();
        return [
            'ok' => in_array($status, [200, 201], true),
            'retryable' => $status === 429 || $status >= 500,
            'status' => $status,
            'inquiry_id' => $response->json('inquiry_id'),
            'code' => $response->json('error.code', $response->successful() ? 'created' : 'http_' . $status),
            'fields' => (array) $response->json('error.fields', []),
            'retry_after' => (int) $response->header('Retry-After') ?: null,
        ];
    }
}
