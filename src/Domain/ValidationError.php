<?php

declare(strict_types=1);

namespace App\Domain;

/** Input failed validation; $errors maps field => message. */
final class ValidationError extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }
}
