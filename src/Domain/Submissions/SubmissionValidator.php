<?php

declare(strict_types=1);

namespace App\Domain\Submissions;

use App\Support\Time;

/**
 * Validates and normalizes the v1 JSON contract (see docs/api.md).
 */
final class SubmissionValidator
{
    public const FORM_TYPES = ['contact', 'quote', 'sample', 'other'];
    public const UID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,127}$/';

    private const TOP_LEVEL = [
        'submission_id', 'form_type', 'form_id', 'form_name', 'submitted_at', 'locale',
        'full_name', 'company', 'email', 'phone', 'country', 'address', 'city', 'postcode', 'message',
        'products', 'fields', 'source', 'test',
    ];

    private const STRING_LIMITS = [
        'full_name' => 190, 'company' => 190, 'email' => 190, 'phone' => 50, 'country' => 100,
        'address' => 500, 'city' => 120, 'postcode' => 32, 'message' => 20000,
    ];

    private const MAX_PRODUCTS = 200;
    private const MAX_FIELDS = 100;

    /** @var array<string,string> */
    private array $errors = [];

    /**
     * @return array{ok:bool,errors:array<string,string>,data:array}
     */
    public function validate(array $in, ?string $idempotencyHeader): array
    {
        $this->errors = [];
        $out = [];

        foreach (array_keys($in) as $key) {
            if (!in_array($key, self::TOP_LEVEL, true)) {
                $this->errors[(string) $key] = 'Unknown field. Put form-specific answers inside "fields".';
            }
        }

        // Submission identity (API retry handling).
        $bodyId = $in['submission_id'] ?? null;
        $header = $idempotencyHeader !== null ? trim($idempotencyHeader) : null;
        if ($bodyId !== null && !is_string($bodyId)) {
            $this->errors['submission_id'] = 'Must be a string.';
        } elseif (is_string($bodyId) && $header !== null && $header !== '' && $bodyId !== $header) {
            $this->errors['submission_id'] = 'Does not match the Idempotency-Key header.';
        }
        $uid = is_string($bodyId) && $bodyId !== '' ? $bodyId : ($header ?? '');
        if (!isset($this->errors['submission_id'])) {
            if ($uid === '') {
                $this->errors['submission_id'] = 'Required: send a stable submission_id in the body or an Idempotency-Key header.';
            } elseif (!preg_match(self::UID_PATTERN, $uid)) {
                $this->errors['submission_id'] = 'Use 1-128 characters: letters, digits, dot, underscore, colon or hyphen.';
            }
        }
        $out['submission_uid'] = $uid;

        $type = $in['form_type'] ?? null;
        if (!is_string($type) || !in_array($type, self::FORM_TYPES, true)) {
            $this->errors['form_type'] = 'Required: one of ' . implode(', ', self::FORM_TYPES) . '.';
        }
        $out['form_type'] = is_string($type) ? $type : '';

        $formId = $in['form_id'] ?? null;
        if ($formId !== null && (!is_string($formId) || !preg_match('/^[\p{L}\p{N} _.:\-]{1,100}$/u', $formId))) {
            $this->errors['form_id'] = 'Use 1-100 letters, digits, spaces, dot, underscore, colon or hyphen.';
        }
        $out['form_key'] = is_string($formId) && $formId !== '' ? trim($formId) : $out['form_type'];
        $out['form_name'] = $this->optionalString($in, 'form_name', 150);

        $out['source_created_at'] = null;
        if (isset($in['submitted_at'])) {
            $parsed = is_string($in['submitted_at']) ? Time::parseToUtc($in['submitted_at']) : null;
            if ($parsed === null) {
                $this->errors['submitted_at'] = 'Use ISO 8601, e.g. 2026-11-18T19:30:00Z.';
            } elseif ($parsed > gmdate('Y-m-d H:i:s', time() + 86400)) {
                $this->errors['submitted_at'] = 'Cannot be in the future.';
            } else {
                $out['source_created_at'] = $parsed;
            }
        }

        $locale = $in['locale'] ?? 'en';
        if (!is_string($locale) || !preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale) || strlen($locale) > 16) {
            $this->errors['locale'] = 'Use a language tag such as en, ar or en-GB.';
            $locale = 'en';
        }
        $out['locale'] = $locale;

        foreach (self::STRING_LIMITS as $field => $max) {
            $out[$field] = $this->optionalString($in, $field, $max, $field === 'message' || $field === 'address') ?? '';
        }
        if ($out['email'] !== '' && !filter_var($out['email'], FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            $this->errors['email'] = 'Must be a valid email address (or omit it).';
        }

        $out['products'] = $this->products($in['products'] ?? null);
        $out['fields'] = $this->fields($in['fields'] ?? null);
        $out = array_merge($out, $this->source($in['source'] ?? null));

        if (isset($in['test']) && !is_bool($in['test'])) {
            $this->errors['test'] = 'Must be true or false.';
        }
        $out['is_test'] = ($in['test'] ?? false) === true;

        $hasContent = false;
        foreach (['full_name', 'company', 'email', 'phone', 'message'] as $f) {
            $hasContent = $hasContent || $out[$f] !== '';
        }
        if (!$hasContent && !$out['products'] && !$out['fields'] && !$this->errors) {
            $this->errors['submission'] = 'The submission is empty: send at least a name, contact detail, message, product or field.';
        }

        return ['ok' => $this->errors === [], 'errors' => $this->errors, 'data' => $out];
    }

    private function optionalString(array $in, string $field, int $max, bool $multiline = false): ?string
    {
        if (!array_key_exists($field, $in) || $in[$field] === null) {
            return null;
        }
        if (!is_string($in[$field])) {
            $this->errors[$field] = 'Must be a string' . (in_array($field, ['phone', 'postcode'], true) ? ' (send numbers as text to keep leading zeros and +).' : '.');
            return null;
        }
        $value = self::clean($in[$field], $multiline);
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "Must be at most {$max} characters.";
            return null;
        }
        return $value;
    }

    /**
     * Trim, normalize newlines and strip control characters. Unicode text (Arabic, emoji, RTL/LTR marks)
     * is preserved; only bidi override/embedding characters (U+202A-U+202E) are removed because they
     * can visually reorder the surrounding interface.
     */
    public static function clean(string $value, bool $multiline = false): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (!$multiline) {
            $value = str_replace(["\n", "\t"], ' ', $value);
        }
        $value = preg_replace('/(?![\n\t])[\p{Cc}\x{202A}-\x{202E}]/u', '', $value) ?? '';
        return trim($value);
    }

    private function products(mixed $products): array
    {
        if ($products === null) {
            return [];
        }
        if (!is_array($products) || !array_is_list($products)) {
            $this->errors['products'] = 'Must be an array of product objects.';
            return [];
        }
        if (count($products) > self::MAX_PRODUCTS) {
            $this->errors['products'] = 'At most ' . self::MAX_PRODUCTS . ' products per submission.';
            return [];
        }
        $out = [];
        foreach ($products as $i => $p) {
            $path = "products[{$i}]";
            if (!is_array($p) || array_is_list($p)) {
                $this->errors[$path] = 'Must be an object with name and/or sku.';
                continue;
            }
            $item = [];
            foreach (['name' => 200, 'sku' => 100, 'unit' => 30, 'notes' => 1000, 'variant' => 200] as $k => $max) {
                if (isset($p[$k])) {
                    if (!is_string($p[$k]) || mb_strlen($p[$k]) > $max) {
                        $this->errors["{$path}.{$k}"] = "Must be a string of at most {$max} characters.";
                        continue;
                    }
                    $v = self::clean($p[$k], $k === 'notes');
                    if ($v !== '') {
                        $item[$k] = $v;
                    }
                }
            }
            if (isset($p['quantity'])) {
                $q = $p['quantity'];
                if (is_string($q) && is_numeric(trim($q))) {
                    $q = trim($q) + 0;
                }
                if ((!is_int($q) && !is_float($q)) || $q < 0 || $q > 1_000_000_000) {
                    $this->errors["{$path}.quantity"] = 'Must be a number between 0 and 1,000,000,000.';
                } else {
                    $item['quantity'] = $q;
                }
            }
            $unknown = array_diff(array_keys($p), ['name', 'sku', 'unit', 'notes', 'variant', 'quantity']);
            if ($unknown) {
                $this->errors["{$path}"] = 'Unknown keys: ' . implode(', ', array_slice($unknown, 0, 5)) . '. Allowed: name, sku, variant, quantity, unit, notes.';
            }
            if (!isset($item['name']) && !isset($item['sku'])) {
                $this->errors["{$path}.name"] = 'Each product needs a name or sku.';
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Custom answers: { "key": value } or { "key": { "label": "...", "value": value } }.
     * Values: string, number, boolean, null, or a list of scalars.
     */
    private function fields(mixed $fields): array
    {
        if ($fields === null) {
            return [];
        }
        if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            $this->errors['fields'] = 'Must be an object of field name => value.';
            return [];
        }
        if (count($fields) > self::MAX_FIELDS) {
            $this->errors['fields'] = 'At most ' . self::MAX_FIELDS . ' custom fields.';
            return [];
        }
        $out = [];
        foreach ($fields as $key => $raw) {
            $key = self::clean((string) $key);
            $path = 'fields.' . mb_substr($key, 0, 60);
            if ($key === '' || mb_strlen($key) > 100) {
                $this->errors['fields'] = 'Field names must be 1-100 characters.';
                continue;
            }
            $label = null;
            if (is_array($raw) && !array_is_list($raw) && array_key_exists('value', $raw)) {
                if (isset($raw['label']) && (!is_string($raw['label']) || mb_strlen($raw['label']) > 150)) {
                    $this->errors[$path . '.label'] = 'Must be a string of at most 150 characters.';
                }
                $label = isset($raw['label']) && is_string($raw['label']) ? self::clean($raw['label']) : null;
                $raw = $raw['value'];
            }
            $value = $this->fieldValue($raw, $path);
            if ($value === false) {
                continue;
            }
            $out[$key] = $label !== null && $label !== '' ? ['label' => $label, 'value' => $value] : ['value' => $value];
        }
        return $out;
    }

    private function fieldValue(mixed $raw, string $path): mixed
    {
        if ($raw === null || is_bool($raw) || is_int($raw) || is_float($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            if (mb_strlen($raw) > 5000) {
                $this->errors[$path] = 'Must be at most 5000 characters.';
                return false;
            }
            return self::clean($raw, true);
        }
        if (is_array($raw) && array_is_list($raw)) {
            if (count($raw) > 50) {
                $this->errors[$path] = 'Lists can contain at most 50 values.';
                return false;
            }
            $list = [];
            foreach ($raw as $v) {
                if (!is_scalar($v) && $v !== null) {
                    $this->errors[$path] = 'Lists can only contain text, numbers or booleans.';
                    return false;
                }
                if (is_string($v) && mb_strlen($v) > 1000) {
                    $this->errors[$path] = 'List values must be at most 1000 characters.';
                    return false;
                }
                $list[] = is_string($v) ? self::clean($v) : $v;
            }
            return $list;
        }
        $this->errors[$path] = 'Use text, a number, true/false, or a list of those.';
        return false;
    }

    private function source(mixed $source): array
    {
        $out = [
            'source_ip' => '', 'source_page_url' => null, 'source_dedupe_key' => null,
            'source_email_sent' => null, 'source_email_sent_at' => null, 'source_email_attempts' => null, 'source_email_error' => null,
        ];
        if ($source === null) {
            return $out;
        }
        if (!is_array($source) || array_is_list($source)) {
            $this->errors['source'] = 'Must be an object.';
            return $out;
        }
        $unknown = array_diff(array_keys($source), ['ip', 'page_url', 'dedupe_key', 'email']);
        if ($unknown) {
            $this->errors['source'] = 'Unknown keys: ' . implode(', ', array_slice($unknown, 0, 5)) . '.';
        }
        if (isset($source['ip'])) {
            if (!is_string($source['ip']) || !filter_var($source['ip'], FILTER_VALIDATE_IP)) {
                $this->errors['source.ip'] = 'Must be an IPv4 or IPv6 address.';
            } else {
                $out['source_ip'] = $source['ip'];
            }
        }
        if (isset($source['page_url'])) {
            $u = $source['page_url'];
            if (!is_string($u) || strlen($u) > 2048 || !preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) {
                $this->errors['source.page_url'] = 'Must be an http(s) URL up to 2048 characters.';
            } else {
                $out['source_page_url'] = $u;
            }
        }
        if (isset($source['dedupe_key'])) {
            if (!is_string($source['dedupe_key']) || mb_strlen($source['dedupe_key']) > 191) {
                $this->errors['source.dedupe_key'] = 'Must be a string of at most 191 characters.';
            } else {
                $out['source_dedupe_key'] = self::clean($source['dedupe_key']);
            }
        }
        if (isset($source['email'])) {
            $em = $source['email'];
            if (!is_array($em) || array_is_list($em)) {
                $this->errors['source.email'] = 'Must be an object with sent, sent_at, attempts, error.';
                return $out;
            }
            if (isset($em['sent'])) {
                if (is_bool($em['sent'])) {
                    $out['source_email_sent'] = $em['sent'] ? 1 : 0;
                } else {
                    $this->errors['source.email.sent'] = 'Must be true or false.';
                }
            }
            if (isset($em['sent_at'])) {
                $p = is_string($em['sent_at']) ? Time::parseToUtc($em['sent_at']) : null;
                if ($p === null) {
                    $this->errors['source.email.sent_at'] = 'Use ISO 8601.';
                } else {
                    $out['source_email_sent_at'] = $p;
                }
            }
            if (isset($em['attempts'])) {
                if (is_int($em['attempts']) && $em['attempts'] >= 0 && $em['attempts'] < 65535) {
                    $out['source_email_attempts'] = $em['attempts'];
                } else {
                    $this->errors['source.email.attempts'] = 'Must be a non-negative integer.';
                }
            }
            if (isset($em['error'])) {
                if (is_string($em['error'])) {
                    $out['source_email_error'] = mb_substr(self::clean($em['error'], true), 0, 1000);
                } else {
                    $this->errors['source.email.error'] = 'Must be a string.';
                }
            }
        }
        return $out;
    }
}
