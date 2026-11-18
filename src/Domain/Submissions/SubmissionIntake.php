<?php

declare(strict_types=1);

namespace App\Domain\Submissions;

use App\Support\Database;
use PDOException;

/**
 * Stores a validated submission exactly once per (website, submission_uid).
 * A retry with the same payload returns the original record; a different payload is a conflict.
 */
final class SubmissionIntake
{
    public function __construct(private Database $db)
    {
    }

    /** Hash of the request body with object keys sorted, so key order does not matter. */
    public static function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map([self::class, 'canonical'], $value);
    }

    /**
     * @return array{result:string,submission:array} result: created | replayed | conflict
     */
    public function store(int $websiteId, int $credentialId, array $data, string $payloadHash, string $senderIp): array
    {
        $existing = $this->findByUid($websiteId, $data['submission_uid']);
        if ($existing !== null) {
            return $this->compare($existing, $payloadHash);
        }
        try {
            return $this->db->transaction(function () use ($websiteId, $credentialId, $data, $payloadHash, $senderIp): array {
                $now = utc_now();
                $this->db->run(
                    'INSERT INTO forms (website_id, form_key, form_type, label, first_seen_at, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), form_type = VALUES(form_type),
                        label = COALESCE(VALUES(label), label), last_seen_at = VALUES(last_seen_at)',
                    [$websiteId, $data['form_key'], $data['form_type'], $data['form_name'], $now, $now]
                );
                $formId = (int) $this->db->pdo()->lastInsertId();
                $json = static fn (array $v): ?string => $v === [] ? null
                    : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

                $id = $this->db->insert(
                    'INSERT INTO submissions (website_id, form_id, credential_id, submission_uid, payload_hash, form_type,
                        received_at, source_created_at, locale, full_name, company, email, phone, country, address, city,
                        postcode, message, products, product_count, custom_fields, source_ip, source_page_url,
                        source_dedupe_key, source_email_sent, source_email_sent_at, source_email_attempts,
                        source_email_error, is_test, status, sender_ip)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", ?)',
                    [
                        $websiteId, $formId, $credentialId, $data['submission_uid'], $payloadHash, $data['form_type'],
                        $now, $data['source_created_at'], $data['locale'], $data['full_name'], $data['company'],
                        $data['email'], $data['phone'], $data['country'], $data['address'], $data['city'],
                        $data['postcode'], $data['message'], $json($data['products']), count($data['products']),
                        $json($data['fields']), $data['source_ip'], $data['source_page_url'], $data['source_dedupe_key'],
                        $data['source_email_sent'], $data['source_email_sent_at'], $data['source_email_attempts'],
                        $data['source_email_error'], $data['is_test'] ? 1 : 0, $senderIp,
                    ]
                );
                $this->db->run('UPDATE websites SET last_submission_at = ? WHERE id = ?', [$now, $websiteId]);
                return ['result' => 'created', 'submission' => (array) $this->db->one('SELECT * FROM submissions WHERE id = ?', [$id])];
            });
        } catch (PDOException $e) {
            // A concurrent retry inserted the same submission_uid first.
            if (Database::isDuplicateKey($e)) {
                $existing = $this->findByUid($websiteId, $data['submission_uid']);
                if ($existing !== null) {
                    return $this->compare($existing, $payloadHash);
                }
            }
            throw $e;
        }
    }

    private function findByUid(int $websiteId, string $uid): ?array
    {
        return $this->db->one('SELECT * FROM submissions WHERE website_id = ? AND submission_uid = ?', [$websiteId, $uid]);
    }

    private function compare(array $existing, string $payloadHash): array
    {
        return [
            'result' => hash_equals($existing['payload_hash'], $payloadHash) ? 'replayed' : 'conflict',
            'submission' => $existing,
        ];
    }
}
