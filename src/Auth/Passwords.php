<?php

declare(strict_types=1);

namespace App\Auth;

final class Passwords
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 72; // bcrypt input limit

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** @return string|null error message, or null when acceptable */
    public static function validate(string $password, string $confirm): ?string
    {
        if ($password !== $confirm) {
            return 'The two passwords do not match.';
        }
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'Use at most ' . self::MAX_BYTES . ' bytes (about 72 Latin characters).';
        }
        if (preg_match('/^(.)\1+$/u', $password)) {
            return 'Choose a less predictable password.';
        }
        return null;
    }

    /** Readable random temporary password, e.g. "kq7m-r2xz-p9tw-h4cd". */
    public static function temporary(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $s;
        }
        return implode('-', $groups);
    }
}
