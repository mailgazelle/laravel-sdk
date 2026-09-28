<?php

declare(strict_types=1);

namespace MailGazelle\Laravel;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use MailGazelle\Client;
use MailGazelle\Http\TransportInterface;
use MailGazelle\Laravel\Exceptions\ApiTokenIsMissing;
use MailGazelle\Laravel\Transport\MailGazelleTransport;

final class MailGazelleServiceProvider extends ServiceProvider
{
    /**
     * Package release used as the User-Agent suffix.
     */
    public const VERSION = '1.0.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailgazelle.php', 'mailgazelle');

        $this->app->singleton(Client::class, function (): Client {
            return $this->makeClient();
        });

        $this->app->alias(Client::class, 'mailgazelle');
    }

    public function boot(): void
    {
        $this->ensureMailerConfig();

        Mail::extend('mailgazelle', function (array $config = []): MailGazelleTransport {
            return new MailGazelleTransport($this->clientForMailer($config));
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mailgazelle.php' => $this->app->configPath('mailgazelle.php'),
            ], 'mailgazelle-config');
        }
    }

    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    private function clientForMailer(array $mailerConfig): Client
    {
        foreach (['token', 'base_url', 'timeout', 'connect_timeout'] as $key) {
            if (! array_key_exists($key, $mailerConfig)) {
                continue;
            }

            $value = $mailerConfig[$key];

            if ($value === null || $value === '') {
                continue;
            }

            return $this->makeClient($mailerConfig);
        }

        return $this->app->make(Client::class);
    }

    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    private function makeClient(array $mailerConfig = []): Client
    {
        $token = $this->stringConfig(
            $mailerConfig['token'] ?? null,
            config('mailgazelle.token'),
            config('services.mailgazelle.token'),
        );

        if ($token === null) {
            throw ApiTokenIsMissing::create();
        }

        return new Client(
            apiToken: $token,
            baseUrl: $this->stringConfig(
                $mailerConfig['base_url'] ?? null,
                config('mailgazelle.base_url'),
            ),
            transport: $this->app->bound(TransportInterface::class)
                ? $this->app->make(TransportInterface::class)
                : null,
            timeout: $this->floatConfig(
                $mailerConfig['timeout'] ?? null,
                config('mailgazelle.timeout'),
                30.0,
            ),
            connectTimeout: $this->floatConfig(
                $mailerConfig['connect_timeout'] ?? null,
                config('mailgazelle.connect_timeout'),
                10.0,
            ),
            userAgentSuffix: 'mailgazelle-laravel/'.self::VERSION,
        );
    }

    private function ensureMailerConfig(): void
    {
        /** @var mixed $mailers */
        $mailers = config('mail.mailers', []);

        if (! is_array($mailers)) {
            $mailers = [];
        }

        if (isset($mailers['mailgazelle'])) {
            return;
        }

        $mailers['mailgazelle'] = [
            'transport' => 'mailgazelle',
        ];

        config(['mail.mailers' => $mailers]);
    }

    private function stringConfig(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function floatConfig(mixed $mailerValue, mixed $packageValue, float $default): float
    {
        foreach ([$mailerValue, $packageValue] as $value) {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if (is_string($value) && is_numeric($value)) {
                return (float) $value;
            }
        }

        return $default;
    }
}
