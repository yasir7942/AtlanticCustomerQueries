<?php

declare(strict_types=1);

namespace App\Domain;

/** The record does not exist or is outside the viewer's scope. */
final class NotFound extends \RuntimeException
{
}
