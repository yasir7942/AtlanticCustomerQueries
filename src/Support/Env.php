<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env loader (KEY=value, # comments, optional single/double quotes).
 * Real environment variables take precedence over the file.
 */
final class Env
{
    /** @return array<string,string> */
    public static function load(string $file): array
    {
        $values = [];
        if (is_file($file) && is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quote = $value[0];
                    $end = strrpos($value, $quote);
                    $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                } elseif (($hash = strpos($value, ' #')) !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
                $values[$key] = $value;
            }
        }
        foreach (array_keys($values) as $key) {
            $real = getenv($key);
            if ($real !== false) {
                $values[$key] = $real;
            }
        }
        return $values;
    }
}
