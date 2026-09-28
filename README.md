# Mail Gazelle for Laravel

Official Laravel SDK for [Mail Gazelle](https://mailgazelle.com). It registers a `mailgazelle` mail transport on top of [`mailgazelle/php-sdk`](https://packagist.org/packages/mailgazelle/php-sdk), so Laravel Mail, Mailables, notifications, and queued mail send through the Mail Gazelle API.

The HTTP contract is documented in [docs/api.md](docs/api.md).

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12, or 13
- `ext-curl` and `ext-json`
- A Mail Gazelle API token for a product that is ready to send (`tes_…`)

## Install

```bash
composer require mailgazelle/laravel-sdk
```

Laravel discovers the service provider. No manual registration is required.

## Configure

Add the token and switch the default mailer in `.env`:

```dotenv
MAIL_MAILER=mailgazelle
MAILGAZELLE_API_TOKEN=tes_your_token
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

Register the token in `config/services.php`. This file is part of the application, so the token is kept when you run `php artisan config:cache`:

```php
'mailgazelle' => [
    'token' => env('MAILGAZELLE_API_TOKEN'),
],
```

`MAIL_MAILER=mailgazelle` selects the mailer. The service provider registers that mailer when `config/mail.php` does not already define one:

```php
'mailgazelle' => [
    'transport' => 'mailgazelle',
],
```

An existing `mailgazelle` entry is left as you wrote it. Add the entry yourself when you want a per-mailer token, API root, or timeout:

```php
'mailgazelle' => [
    'transport' => 'mailgazelle',
    'token' => env('MAILGAZELLE_API_TOKEN'),
    'base_url' => env('MAILGAZELLE_BASE_URL'),
    'timeout' => 30,
    'connect_timeout' => 10,
],
```

Token lookup order:

1. `token` on the `mailgazelle` mailer
2. `config('mailgazelle.token')`
3. `config('services.mailgazelle.token')`

A missing token throws `MailGazelle\Laravel\Exceptions\ApiTokenIsMissing` when the client is resolved.

`MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` are applied by Laravel before the message reaches Mail Gazelle. A Mailable `from()` replaces that global sender. Reply-To addresses on a Mailable are sent as well as any global `reply_to` entry in `config/mail.php`. When a message has no From address, the transport omits `from` and the product default is used. Omit Reply-To to keep the product Reply-To.

`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_SCHEME` belong to the SMTP mailer and are not used.

## Send mail

Mailables, `Mail::raw()`, notifications that use the `mail` channel, and queued mailables all use the default mailer.

```php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your invoice',
            metadata: [
                'campaign' => 'invoice',
            ],
            tags: ['invoice'],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Your invoice is attached.</p>',
            text: 'Your invoice is attached.',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: 'invoice-42@example.com',
            text: [
                'X-Entity-Ref-ID' => '42',
                'MailGazelle-Idempotency-Key' => 'invoice-42',
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath(storage_path('app/invoice.pdf')),
        ];
    }
}
```

```php
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;

Mail::to('user@example.com')->send(new InvoiceMail);
```

Plain text:

```php
Mail::raw('Thanks for signing up.', function ($message) {
    $message->to('user@example.com')->subject('Welcome');
});
```

Implement `Illuminate\Contracts\Queue\ShouldQueue` on a Mailable to queue it. The HTTP call runs when the worker sends the message.

`Mail::send()` returns after Mail Gazelle accepts the message (`202`, status `queued`). Delivery is asynchronous. The Mail Gazelle message id is the Symfony sent-message id, and the original message gains an `X-MailGazelle-Message-Id` header:

```php
use Illuminate\Mail\Events\MessageSent;

Event::listen(MessageSent::class, function (MessageSent $event) {
    $event->sent->getMessageId();
});
```

### Attachments

Laravel attachments are sent as Mail Gazelle attachments. Inline parts (`$message->embed()` / `embedData()`) include the Symfony content id. Reference that id from HTML as `cid:{content_id}`.

Filenames must be a basename. The team's plan limits how many files a message may include and their decoded size. The platform ceiling is 10 attachments and 7 MB decoded, and the assembled message must be 10 MB or smaller. See [docs/api.md](docs/api.md).

### Tags, headers, and idempotency

Envelope `metadata` is sent as Mail Gazelle tags (an object of names and values). Envelope `tags` are sent the same way, using the tag string as both the name and the value. Names and values must match `[A-Za-z0-9_-]`. Names are at most 64 characters, values at most 256, and a message may include at most 48 tags. `team_id` and `product_id` are reserved. Invalid tags are rejected.

Custom headers on the Symfony message are forwarded, except `From`, `To`, `Cc`, `Bcc`, `Reply-To`, `Sender`, `Subject`, `Content-Type`, `Return-Path`, `MIME-Version`, and `Date`. `Message-ID` is forwarded. Header names may contain letters, numbers, and hyphens. Values cannot contain line breaks and must be at most 8192 characters. A message may include at most 50 headers.

`MailGazelle-Idempotency-Key` is not sent as a header. It becomes `idempotency_key`. The same key for a team returns the original message and does not send again.

## Use the client directly

Inject `MailGazelle\Client`, or use the facade, when you need the API without the mailer. That includes fetching a message and listing domains.

```php
use MailGazelle\Emails\Email;
use MailGazelle\Laravel\Facades\MailGazelle;

$queued = MailGazelle::emails()->send(
    Email::to('user@example.com', 'Ada')
        ->subject('Welcome')
        ->text('Thanks for signing up.'),
);

$record = MailGazelle::emails()->get($queued->id());

foreach (MailGazelle::domains()->list() as $domain) {
    $domain->name();
    $domain->verificationStatuses();
}
```

The client is a singleton for the configured token. Reuse it. Do not construct a new client for every send.

## Errors

API failures thrown while sending through the mailer are wrapped in `Symfony\Component\Mailer\Exception\TransportException`. The previous exception is a `MailGazelle\Exceptions\MailGazelleException` subclass, such as `QuotaExceededException` or `RecipientSuppressedException`.

```php
use MailGazelle\Exceptions\MailGazelleException;
use Symfony\Component\Mailer\Exception\TransportException;

try {
    Mail::to('user@example.com')->send(new InvoiceMail);
} catch (TransportException $exception) {
    $previous = $exception->getPrevious();

    if ($previous instanceof MailGazelleException) {
        $previous->code();
        $previous->httpStatus();
    }
}
```

Calls made through the facade throw `MailGazelleException` directly. The SDK does not retry. Use an idempotency key when a caller may repeat a send.

## Testing

`Mail::fake()` stops Laravel before the transport runs, so application tests do not need a token or a network call.

## Optional settings

Publish the package config to change the API root or timeouts:

```bash
php artisan vendor:publish --tag=mailgazelle-config
```

| Setting | Environment variable | Default |
|---|---|---|
| `base_url` | `MAILGAZELLE_BASE_URL` | `https://mailgazelle.com/api/v1` |
| `timeout` | `MAILGAZELLE_TIMEOUT` | `30` seconds |
| `connect_timeout` | `MAILGAZELLE_CONNECT_TIMEOUT` | `10` seconds |

`env()` inside an unpublished package config file does not see `.env` after `php artisan config:cache`. Put a custom API root or timeout on the `mailgazelle` mailer in `config/mail.php`, or publish this config before caching, so the value is stored in the cache.

To replace the HTTP client, bind `MailGazelle\Http\TransportInterface` in a service provider. The binding is passed to `MailGazelle\Client`. Leave it unbound to use the SDK's cURL transport.

The User-Agent sent with each request is `mailgazelle-php-sdk/{version} mailgazelle-laravel/1.0.0`.
