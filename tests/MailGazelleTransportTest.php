<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use MailGazelle\Client;
use MailGazelle\Exceptions\QuotaExceededException;
use MailGazelle\Http\TransportInterface;
use MailGazelle\Laravel\Transport\MailGazelleTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class MailGazelleTransportTest extends TestCase
{
    private FakeHttpTransport $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeHttpTransport;
        $this->app->instance(TransportInterface::class, $this->http);
    }

    public function test_laravel_mail_sends_text_with_the_global_from_address(): void
    {
        Mail::raw('Thanks for signing up.', function ($message): void {
            $message->to('user@example.com', 'Ada')->subject('Welcome');
        });

        $payload = $this->http->lastPayload();
        $request = $this->http->lastRequest();

        $this->assertSame('POST', $request->method);
        $this->assertSame('https://mailgazelle.test/api/v1/emails', $request->url);
        $this->assertSame('Bearer tes_test', $request->headers['Authorization']);
        $this->assertStringContainsString('mailgazelle-laravel/1.0.0', $request->headers['User-Agent']);
        $this->assertSame([
            ['email' => 'user@example.com', 'name' => 'Ada'],
        ], $payload['to']);
        $this->assertSame('Welcome', $payload['subject']);
        $this->assertSame('Thanks for signing up.', $payload['text']);
        $this->assertArrayNotHasKey('html', $payload);
        $this->assertSame([
            'email' => 'hello@example.com',
            'name' => 'Example',
        ], $payload['from']);
        $this->assertArrayNotHasKey('reply_to', $payload);
    }

    public function test_it_maps_headers_tags_attachments_and_idempotency(): void
    {
        $email = (new Email)
            ->from(new Address('notif@example.com', 'App'))
            ->to(new Address('user@example.com', 'Ada'))
            ->addTo(new Address('other@example.com'))
            ->cc(new Address('billing@example.com'))
            ->bcc(new Address('audit@example.com', 'Audit'))
            ->replyTo(new Address('support@example.com', 'Support'))
            ->subject('Invoice')
            ->html('<p>Your invoice is attached.</p>')
            ->text('Your invoice is attached.');

        $email->getHeaders()->addIdHeader('Message-ID', 'welcome-42@example.com');
        $email->getHeaders()->addTextHeader('X-Custom', 'yes');
        $email->getHeaders()->addTextHeader('MailGazelle-Idempotency-Key', 'welcome-user-42');
        $email->getHeaders()->add(new TagHeader('welcome'));
        $email->getHeaders()->add(new MetadataHeader('campaign', 'welcome'));
        $email->attach('pdf-bytes', 'invoice.pdf', 'application/pdf');
        $email->addPart(
            (new DataPart('png-bytes', 'logo.png', 'image/png'))
                ->asInline()
                ->setContentId('logo@example.com'),
        );

        $sent = $this->transport()->send($email);
        $payload = $this->http->lastPayload();

        $this->assertSame('01JTEST', $sent?->getMessageId());
        $this->assertInstanceOf(Email::class, $sent?->getOriginalMessage());
        $this->assertSame(
            '01JTEST',
            $sent->getOriginalMessage()->getHeaders()->get('X-MailGazelle-Message-Id')?->getBodyAsString(),
        );
        $this->assertSame([
            ['email' => 'user@example.com', 'name' => 'Ada'],
            ['email' => 'other@example.com'],
        ], $payload['to']);
        $this->assertSame([
            ['email' => 'billing@example.com'],
        ], $payload['cc']);
        $this->assertSame([
            ['email' => 'audit@example.com', 'name' => 'Audit'],
        ], $payload['bcc']);
        $this->assertSame([
            ['email' => 'support@example.com', 'name' => 'Support'],
        ], $payload['reply_to']);
        $this->assertSame('<p>Your invoice is attached.</p>', $payload['html']);
        $this->assertSame('Your invoice is attached.', $payload['text']);
        $this->assertSame('welcome-user-42', $payload['idempotency_key']);
        $this->assertSame([
            'welcome' => 'welcome',
            'campaign' => 'welcome',
        ], $payload['tags']);
        $this->assertSame('yes', $payload['headers']['X-Custom']);
        $this->assertStringContainsString('welcome-42@example.com', $payload['headers']['Message-ID']);
        $this->assertArrayNotHasKey('MailGazelle-Idempotency-Key', $payload['headers']);
        $this->assertArrayNotHasKey('Date', $payload['headers']);
        $this->assertSame('invoice.pdf', $payload['attachments'][0]['filename']);
        $this->assertSame('application/pdf', $payload['attachments'][0]['content_type']);
        $this->assertSame(base64_encode('pdf-bytes'), $payload['attachments'][0]['content']);
        $this->assertArrayNotHasKey('content_id', $payload['attachments'][0]);
        $this->assertSame('logo.png', $payload['attachments'][1]['filename']);
        $this->assertSame('image/png', $payload['attachments'][1]['content_type']);
        $this->assertSame('logo@example.com', $payload['attachments'][1]['content_id']);
    }

    public function test_from_is_omitted_when_the_message_has_none(): void
    {
        $email = (new Email)
            ->to('user@example.com')
            ->subject('Welcome')
            ->text('Hi');

        $this->transport()->send($email);

        $payload = $this->http->lastPayload();

        $this->assertArrayNotHasKey('from', $payload);
        $this->assertStringNotContainsString(
            'mailgazelle-placeholder@example.com',
            (string) $this->http->lastRequest()->body,
        );
    }

    public function test_api_errors_become_transport_exceptions(): void
    {
        $this->app->instance(TransportInterface::class, new FakeHttpTransport(
            statusCode: 422,
            body: '{"message":"Monthly email quota exceeded.","code":"quota_exceeded"}',
        ));
        Mail::purge('mailgazelle');

        try {
            Mail::raw('Hello', function ($message): void {
                $message->to('user@example.com')->subject('Hello');
            });
            $this->fail('An API error should fail the send.');
        } catch (TransportException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertStringContainsString('Monthly email quota exceeded.', $exception->getMessage());
            $this->assertInstanceOf(QuotaExceededException::class, $exception->getPrevious());
        }
    }

    public function test_a_mailer_token_wins_over_package_and_services_config(): void
    {
        config([
            'mailgazelle.token' => 'tes_package',
            'services.mailgazelle.token' => 'tes_services',
            'mail.mailers.mailgazelle.token' => 'tes_mailer',
        ]);
        $this->app->forgetInstance(Client::class);
        Mail::purge('mailgazelle');

        Mail::raw('Hello', function ($message): void {
            $message->to('user@example.com')->subject('Hello');
        });

        $this->assertSame('Bearer tes_mailer', $this->http->lastRequest()->headers['Authorization']);
    }

    public function test_the_package_token_wins_over_services_config(): void
    {
        config([
            'mailgazelle.token' => 'tes_package',
            'services.mailgazelle.token' => 'tes_services',
        ]);
        $this->app->forgetInstance(Client::class);
        Mail::purge('mailgazelle');

        Mail::raw('Hello', function ($message): void {
            $message->to('user@example.com')->subject('Hello');
        });

        $this->assertSame('Bearer tes_package', $this->http->lastRequest()->headers['Authorization']);
    }

    private function transport(): MailGazelleTransport
    {
        $transport = Mail::mailer('mailgazelle')->getSymfonyTransport();
        $this->assertInstanceOf(MailGazelleTransport::class, $transport);

        return $transport;
    }
}
