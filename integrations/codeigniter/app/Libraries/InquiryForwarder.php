<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;

/**
 * CodeIgniter 4 connector. Settings come from .env:
 *   inquiry.apiUrl = https://inquiries.example.com/api/v1/submissions
 *   inquiry.apiKey = aiq_...
 * (read through app/Config/Inquiry.php). Failed transient deliveries are written to
 * WRITEPATH/inquiry-outbox and retried by `php spark inquiry:retry` from cron.
 */
class InquiryForwarder
{
    private string $url;
    private string $key;

    public function __construct()
    {
        $config = config('Inquiry');
        $this->url = (string) $config->apiUrl;
        $this->key = (string) $config->apiKey;
    }

    public static function newSubmissionId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return 'ci4-' . vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Send with up to $attempts tries (same submission_id). Returns ['ok','retryable','status','inquiry_id','fields'].
     */
    public function send(array $payload, int $attempts = 3): array
    {
        $result = ['ok' => false, 'retryable' => true, 'status' => 0, 'inquiry_id' => null, 'fields' => []];
        for ($i = 1; $i <= $attempts; $i++) {
            $client = Services::curlrequest(['timeout' => 10, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false], null, null, false);
            try {
                $response = $client->post($this->url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Idempotency-Key' => $payload['submission_id'],
                    ],
                    'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $status = $response->getStatusCode();
                $body = json_decode($response->getBody(), true) ?: [];
                $result = [
                    'ok' => in_array($status, [200, 201], true),
                    'retryable' => $status === 429 || $status >= 500,
                    'status' => $status,
                    'inquiry_id' => $body['inquiry_id'] ?? null,
                    'fields' => $body['error']['fields'] ?? [],
                ];
            } catch (HTTPException $e) {
                $result = ['ok' => false, 'retryable' => true, 'status' => 0, 'inquiry_id' => null, 'fields' => []];
            }
            if ($result['ok'] || !$result['retryable']) {
                return $result;
            }
            if ($i < $attempts) {
                sleep(min(4, 2 ** ($i - 1)));
            }
        }
        return $result;
    }

    /** Persist for a later retry (same payload, same submission_id). */
    public function queue(array $payload): void
    {
        $dir = WRITEPATH . 'inquiry-outbox';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $payload['submission_id']) . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
