<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Exceptions;

use RuntimeException;

final class ApiTokenIsMissing extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'A Mail Gazelle API token is required. Set MAILGAZELLE_API_TOKEN in config/services.php or on the mailgazelle mailer.',
        );
    }
}
