<?php

declare(strict_types=1);

/**
 * Inquiry Manager connector for plain PHP (PHP 8.0+, no dependencies).
 *
 * Copy this file to a folder that is NOT web-accessible (for example ../private/InquiryClient.php)
 * and keep the API key in an environment variable or a config file outside the web root.
 *
 *   $client = new InquiryClient(getenv('INQUIRY_API_URL'), getenv('INQUIRY_API_KEY'));
 *   $result = $client->send($payload);            // retries transient failures with the same ID
 *   if ($result->retryable) { (new InquiryOutbox('/path/outside/webroot/outbox'))->save($payload); }
 */
final class InquiryClient
{
    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private int $timeoutSeconds = 10,
        private int $maxAttempts = 3,
    ) {
        if ($endpoint === '' || $apiKey === '') {
            throw new InvalidArgumentException('Inquiry API URL and key are required.');
        }
    }

    /** Stable, unique ID for one inquiry. Generate it once and reuse it for every retry. */
    public static function newSubmissionId(string $prefix = 'web'): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
        return preg_replace('/[^A-Za-z0-9._:-]/', '', $prefix) . '-' . $uuid;
    }

    /**
     * Send one inquiry. $payload must contain "submission_id".
     * Retries network errors, 429 and 5xx with backoff, always with the same submission_id.
     */
    public function send(array $payload): InquiryResult
    {
        if (empty($payload['submission_id']) || !is_string($payload['submission_id'])) {
            throw new InvalidArgumentException('payload.submission_id is required (use InquiryClient::newSubmissionId()).');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return new InquiryResult(false, false, 0, null, 'Payload could not be encoded as JSON: ' . json_last_error_msg());
        }
        $result = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $result = $this->post($body, $payload['submission_id']);
            if ($result->ok || !$result->retryable || $attempt === $this->maxAttempts) {
                return $result;
            }
            // Exponential backoff with jitter; honour Retry-After up to 10 s while the visitor waits.
            $delay = $result->retryAfter !== null ? min(10, $result->retryAfter) : min(8, 2 ** ($attempt - 1));
            usleep((int) (($delay + random_int(0, 300) / 1000) * 1_000_000));
        }
        return $result;
    }

    private function post(string $body, string $submissionId): InquiryResult
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'Idempotency-Key: ' . $submissionId,
            'User-Agent: InquiryClient-PHP/1.0',
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $raw === false ? curl_error($ch) : null;
            curl_close($ch);
            if ($raw === false) {
                return new InquiryResult(false, true, 0, null, 'Network error: ' . $error);
            }
            return InquiryResult::fromResponse($status, (string) $raw, $responseHeaders['retry-after'] ?? null);
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => $this->timeoutSeconds, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($this->endpoint, false, $ctx);
        if ($raw === false || !isset($http_response_header[0])) {
            return new InquiryResult(false, true, 0, null, 'Network error');
        }
        preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m);
        $retryAfter = null;
        foreach ($http_response_header as $h) {
            if (stripos($h, 'Retry-After:') === 0) {
                $retryAfter = trim(substr($h, 12));
            }
        }
        return InquiryResult::fromResponse((int) ($m[1] ?? 0), $raw, $retryAfter);
    }
}

final class InquiryResult
{
    public function __construct(
        public bool $ok,
        public bool $retryable,
        public int $status,
        public ?array $body,
        public ?string $error = null,
        public ?int $retryAfter = null,
    ) {
    }

    public static function fromResponse(int $status, string $raw, ?string $retryAfter): self
    {
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : null;
        $ok = $status === 200 || $status === 201;
        // 429 and 5xx are transient; 4xx means the request itself must change, so never retry it.
        $retryable = $status === 429 || $status >= 500;
        $error = $ok ? null : (string) ($body['error']['message'] ?? ('HTTP ' . $status));
        return new self($ok, $retryable, $status, $body, $error, $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null);
    }

    /** Field => message map for 422 validation failures. */
    public function fieldErrors(): array
    {
        return (array) ($this->body['error']['fields'] ?? []);
    }

    public function inquiryId(): ?int
    {
        return isset($this->body['inquiry_id']) ? (int) $this->body['inquiry_id'] : null;
    }
}

/**
 * File-based outbox for inquiries that could not be delivered yet.
 * Put the directory outside the web root and run retry-outbox.php from cron.
 */
final class InquiryOutbox
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create outbox directory {$dir}");
        }
    }

    public function save(array $payload, ?InquiryResult $lastResult = null): void
    {
        $id = preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $payload['submission_id']);
        $file = $this->dir . '/' . str_replace(':', '_', $id) . '.json';
        $record = ['payload' => $payload, 'attempts' => 0, 'next_attempt' => time() + 300, 'last_status' => $lastResult?->status];
        if (is_file($file)) {
            $existing = json_decode((string) file_get_contents($file), true);
            $record['attempts'] = (int) ($existing['attempts'] ?? 0);
        }
        file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($file, 0600);
    }

    /**
     * Retry due items with their original submission_id. Gives up after $maxAttempts.
     *
     * @return array{sent:int,failed:int,pending:int}
     */
    public function retryDue(InquiryClient $client, int $maxAttempts = 10): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'pending' => 0];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            if (!is_array($record) || !isset($record['payload'])) {
                continue;
            }
            if ((int) $record['next_attempt'] > time()) {
                $stats['pending']++;
                continue;
            }
            $result = $client->send($record['payload']);
            if ($result->ok) {
                unlink($file);
                $stats['sent']++;
                continue;
            }
            $record['attempts'] = (int) $record['attempts'] + 1;
            $record['last_status'] = $result->status;
            if (!$result->retryable || $record['attempts'] >= $maxAttempts) {
                rename($file, $file . '.failed');
                $stats['failed']++;
                continue;
            }
            $record['next_attempt'] = time() + min(86400, 300 * 2 ** $record['attempts']);
            file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX);
            $stats['pending']++;
        }
        return $stats;
    }
}
