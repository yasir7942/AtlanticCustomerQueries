<?php

declare(strict_types=1);

namespace App\Domain;

/** The actor is not permitted to perform the action. */
final class AccessDenied extends \RuntimeException
{
}
