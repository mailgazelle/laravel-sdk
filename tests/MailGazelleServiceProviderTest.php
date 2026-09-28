<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use MailGazelle\Client;
use MailGazelle\Laravel\Exceptions\ApiTokenIsMissing;
use MailGazelle\Laravel\Facades\MailGazelle;
use MailGazelle\Laravel\MailGazelleServiceProvider;
use MailGazelle\Laravel\Transport\MailGazelleTransport;

final class MailGazelleServiceProviderTest extends TestCase
{
    public function test_it_registers_the_mailgazelle_mailer_when_missing(): void
    {
        $this->assertSame('mailgazelle', config('mail.mailers.mailgazelle.transport'));
    }

    public function test_the_mailer_transport_is_mailgazelle(): void
    {
        $transport = Mail::mailer('mailgazelle')->getSymfonyTransport();

        $this->assertInstanceOf(MailGazelleTransport::class, $transport);
        $this->assertSame('mailgazelle', (string) $transport);
    }

    public function test_the_facade_resolves_the_client(): void
    {
        $this->assertInstanceOf(Client::class, MailGazelle::getFacadeRoot());
    }

    public function test_resolving_the_client_without_a_token_fails(): void
    {
        config([
            'mailgazelle.token' => null,
            'services.mailgazelle.token' => null,
        ]);
        $this->app->forgetInstance(Client::class);

        $this->expectException(ApiTokenIsMissing::class);

        $this->app->make(Client::class);
    }

    public function test_the_config_file_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            MailGazelleServiceProvider::class,
            'mailgazelle-config',
        );

        $source = array_key_first($paths);

        $this->assertIsString($source);
        $this->assertFileExists($source);
        $this->assertStringEndsWith('/config/mailgazelle.php', $source);
        $this->assertSame(config_path('mailgazelle.php'), $paths[$source]);
    }
}
