<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CredentialService;
use App\Domain\Submissions\SubmissionIntake;
use App\Domain\Submissions\SubmissionValidator;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Time;

/**
 * POST /api/v1/submissions — server-to-server intake. See docs/api.md for the contract.
 */
final class ApiController
{
    private const FAILED_AUTH_LIMIT_PER_MINUTE = 20;

    public function __construct(
        private Database $db,
        private Config $config,
        private CredentialService $credentials,
        private RateLimiter $limiter,
        private Logger $logger,
    ) {
    }

    public function submit(Request $request): Response
    {
        $started = microtime(true);
        $proxies = (array) $this->config->get('trusted_proxies', []);
        $ip = $request->ip($proxies);
        $log = ['website_id' => null, 'credential_id' => null, 'key_id' => null, 'submission_uid' => null,
            'submission_id' => null, 'payload_bytes' => 0, 'error_fields' => null];

        $respond = function (int $status, array $body, array $headers = []) use (&$log, $started, $ip, $request): Response {
            $this->logRequest($log, $status, (string) ($body['error']['code'] ?? $body['status'] ?? 'ok'), $ip, $request, $started);
            return Response::json($body, $status, $headers + ['Cache-Control' => 'no-store']);
        };
        $error = static fn (string $code, string $message, array $extra = []): array => ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra];

        if ($this->config->get('api.require_https') && !$request->isSecure($proxies)) {
            return $respond(403, $error('https_required', 'Send submissions over HTTPS.'));
        }

        $ipLimit = $this->limiter->hit('api-ip:' . $ip, (int) $this->config->get('api.ip_limit_per_minute'), 60);
        if (!$ipLimit['allowed']) {
            return $respond(429, $error('rate_limited', 'Too many requests from this address. Retry after the delay.'), ['Retry-After' => (string) $ipLimit['retry_after']]);
        }
        if ($this->limiter->tooMany('api-authfail:' . $ip, self::FAILED_AUTH_LIMIT_PER_MINUTE, 60)) {
            return $respond(429, $error('rate_limited', 'Too many failed authentication attempts. Retry after the delay.'), ['Retry-After' => '60']);
        }

        // Authenticate before reading the body; the key decides the website.
        $auth = $this->credentials->authenticate($request->header('Authorization'));
        $log['key_id'] = $auth['key_id'] ?? null;
        if (!$auth['ok']) {
            $this->limiter->hit('api-authfail:' . $ip, self::FAILED_AUTH_LIMIT_PER_MINUTE, 60);
            // Attribute the failure to a website when the public key id matched (helps diagnose wrong secrets).
            $log['website_id'] = isset($auth['credential']['website_id']) ? (int) $auth['credential']['website_id'] : null;
            $log['credential_id'] = isset($auth['credential']['id']) ? (int) $auth['credential']['id'] : null;
            $messages = [
                'missing_credentials' => 'Send the website key as "Authorization: Bearer <key>".',
                'malformed_credentials' => 'The key format is not recognised.',
                'unknown_key' => 'The key is not valid.',
                'revoked_key' => 'The key has been revoked.',
                'expired_key' => 'The key has expired after rotation. Use the new key.',
            ];
            return $respond(401, $error('invalid_credentials', $messages[$auth['reason']] ?? 'Invalid credentials.', ['reason' => $auth['reason']]), ['WWW-Authenticate' => 'Bearer realm="inquiries"']);
        }
        $cred = $auth['credential'];
        $websiteId = (int) $cred['website_id'];
        $log['website_id'] = $websiteId;
        $log['credential_id'] = (int) $cred['id'];

        if ($cred['website_status'] !== 'active') {
            return $respond(403, $error('website_inactive', 'This website is not accepting submissions.'));
        }
        $siteLimit = $this->limiter->hit('api-site:' . $websiteId, max(1, (int) $cred['rate_limit_per_minute']), 60);
        if (!$siteLimit['allowed']) {
            return $respond(429, $error('rate_limited', 'Rate limit for this website exceeded. Retry after the delay.'), ['Retry-After' => (string) $siteLimit['retry_after']]);
        }

        $max = (int) $this->config->get('api.max_body_bytes');
        $declared = $request->header('Content-Length');
        if ($declared !== null && ctype_digit($declared) && (int) $declared > $max) {
            $log['payload_bytes'] = (int) $declared;
            return $respond(413, $error('payload_too_large', "The JSON body must be at most {$max} bytes.", ['max_bytes' => $max]));
        }
        $contentType = strtolower((string) $request->header('Content-Type'));
        if (!str_starts_with($contentType, 'application/json')) {
            return $respond(415, $error('unsupported_media_type', 'Send the body as application/json.'));
        }
        $raw = $request->body($max);
        if ($raw === null) {
            $log['payload_bytes'] = $max + 1;
            return $respond(413, $error('payload_too_large', "The JSON body must be at most {$max} bytes.", ['max_bytes' => $max]));
        }
        $log['payload_bytes'] = strlen($raw);

        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $respond(400, $error('invalid_json', 'The body is not valid UTF-8 JSON.'));
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return $respond(400, $error('invalid_json', 'The body must be a JSON object.'));
        }

        $validation = (new SubmissionValidator())->validate($payload, $request->header('Idempotency-Key'));
        $uid = $validation['data']['submission_uid'];
        $log['submission_uid'] = preg_match(SubmissionValidator::UID_PATTERN, $uid) ? $uid : null;
        if (!$validation['ok']) {
            $log['error_fields'] = mb_substr(implode(',', array_keys($validation['errors'])), 0, 500);
            return $respond(422, $error('validation_failed', 'One or more fields are invalid.', ['fields' => $validation['errors']]));
        }

        try {
            $outcome = (new SubmissionIntake($this->db))->store(
                $websiteId,
                (int) $cred['id'],
                $validation['data'],
                SubmissionIntake::payloadHash($payload),
                $ip
            );
        } catch (\Throwable $e) {
            $this->logger->error('Submission storage failed', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
            return $respond(500, $error('server_error', 'The submission could not be stored. Retry with the same submission_id.'));
        }
        $this->credentials->touch((int) $cred['id'], $ip);
        $row = $outcome['submission'];
        $log['submission_id'] = (int) $row['id'];

        if ($outcome['result'] === 'conflict') {
            return $respond(409, $error('idempotency_conflict', 'This submission_id was already used with a different payload. Generate a new ID for a new inquiry.', [
                'submission_id' => $uid, 'inquiry_id' => (int) $row['id'],
            ]));
        }
        $replayed = $outcome['result'] === 'replayed';
        $body = [
            'ok' => true,
            'status' => $replayed ? 'duplicate' : 'created',
            'replayed' => $replayed,
            'inquiry_id' => (int) $row['id'],
            'submission_id' => $row['submission_uid'],
            'received_at' => Time::iso($row['received_at']),
        ];
        return $respond($replayed ? 200 : 201, $body, $replayed ? ['Idempotent-Replayed' => 'true'] : []);
    }

    private function logRequest(array $log, int $status, string $code, string $ip, Request $request, float $started): void
    {
        try {
            $this->db->run(
                'INSERT INTO api_request_log (occurred_at, website_id, credential_id, key_id, http_status, result_code, submission_uid,
                    submission_id, ip, user_agent, payload_bytes, duration_ms, error_fields)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [utc_now(), $log['website_id'], $log['credential_id'], $log['key_id'], $status, mb_substr($code, 0, 40),
                    $log['submission_uid'], $log['submission_id'], $ip, $request->userAgent() ?: null,
                    min((int) $log['payload_bytes'], 4294967295), (int) round((microtime(true) - $started) * 1000), $log['error_fields']]
            );
            if (random_int(1, 500) === 1) {
                $days = (int) $this->config->get('diagnostics_retention_days', 30);
                $this->db->run('DELETE FROM api_request_log WHERE occurred_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not write API diagnostics', ['error' => $e->getMessage()]);
        }
    }
}
