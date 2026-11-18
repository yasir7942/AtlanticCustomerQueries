<?php

declare(strict_types=1);

namespace App\Domain\Submissions;

/**
 * Turns stored rows into display-ready values. Returns plain text; templates escape it.
 */
final class Presenter
{
    public const TYPE_LABELS = ['contact' => 'Contact', 'quote' => 'Quote', 'sample' => 'Sample', 'other' => 'Other'];
    public const STATUS_LABELS = ['new' => 'New', 'open' => 'Open', 'closed' => 'Closed', 'spam' => 'Spam'];

    public const CONTACT_LABELS = [
        'full_name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone',
        'country' => 'Country', 'address' => 'Address', 'city' => 'City', 'postcode' => 'Postcode',
    ];

    public static function customer(array $row): string
    {
        $name = trim((string) ($row['full_name'] ?? ''));
        $company = trim((string) ($row['company'] ?? ''));
        if ($name !== '' && $company !== '') {
            return $name . ' · ' . $company;
        }
        return $name ?: $company ?: (string) ($row['email'] ?? '') ?: (string) ($row['phone'] ?? '') ?: '(no name)';
    }

    /** One-line request summary for inbox rows. */
    public static function summary(array $row, int $max = 140): string
    {
        $parts = [];
        $products = self::products($row['products'] ?? null);
        if ($products) {
            $items = array_map(static function (array $p): string {
                $label = $p['name'] ?? $p['sku'] ?? 'Item';
                return isset($p['quantity']) ? $label . ' × ' . self::number($p['quantity']) : $label;
            }, array_slice($products, 0, 3));
            $more = count($products) > 3 ? ' +' . (count($products) - 3) . ' more' : '';
            $parts[] = count($products) . ' product' . (count($products) === 1 ? '' : 's') . ': ' . implode(', ', $items) . $more;
        }
        $message = trim(preg_replace('/\s+/u', ' ', (string) ($row['message_excerpt'] ?? $row['message'] ?? '')) ?? '');
        if ($message !== '') {
            $parts[] = $message;
        }
        if ($parts === []) {
            $fields = self::fields($row['custom_fields'] ?? null);
            foreach (array_slice($fields, 0, 2) as $f) {
                $parts[] = $f['label'] . ': ' . $f['display'];
            }
        }
        $text = implode(' — ', $parts);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /** @return list<array<string,mixed>> */
    public static function products(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @return list<array{key:string,label:string,display:string,is_list:bool,values:list<string>}>
     */
    public static function fields(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $entry) {
            $value = is_array($entry) && array_key_exists('value', $entry) ? $entry['value'] : $entry;
            $label = is_array($entry) && isset($entry['label']) && is_string($entry['label']) && $entry['label'] !== ''
                ? $entry['label'] : self::humanize((string) $key);
            $values = is_array($value) ? array_map([self::class, 'scalar'], $value) : [self::scalar($value)];
            $out[] = [
                'key' => (string) $key,
                'label' => $label,
                'display' => implode(', ', $values),
                'is_list' => is_array($value),
                'values' => $values,
            ];
        }
        return $out;
    }

    public static function humanize(string $key): string
    {
        $s = trim(preg_replace('/[_\-]+/', ' ', $key) ?? $key);
        return $s === '' ? $key : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    public static function scalar(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            $v === true => 'Yes',
            $v === false => 'No',
            is_float($v), is_int($v) => self::number($v),
            default => (string) $v,
        };
    }

    public static function number(mixed $n): string
    {
        if (is_int($n)) {
            return number_format($n);
        }
        if (is_float($n)) {
            return rtrim(rtrim(number_format($n, 4, '.', ','), '0'), '.');
        }
        return (string) $n;
    }

    /** "rtl" for Arabic/Hebrew/Persian-script text, otherwise "auto". */
    public static function dir(string $text): string
    {
        return preg_match('/\p{Arabic}|\p{Hebrew}/u', $text) ? 'rtl' : 'auto';
    }
}
