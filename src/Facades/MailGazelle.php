<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use MailGazelle\Client;

/**
 * @method static \MailGazelle\Emails\EmailsResource emails()
 * @method static \MailGazelle\Domains\DomainsResource domains()
 *
 * @see Client
 */
final class MailGazelle extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
