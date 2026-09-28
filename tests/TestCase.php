<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Tests;

use MailGazelle\Laravel\Facades\MailGazelle;
use MailGazelle\Laravel\MailGazelleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MailGazelleServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'MailGazelle' => MailGazelle::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'mailgazelle');
        $app['config']->set('mail.from', [
            'address' => 'hello@example.com',
            'name' => 'Example',
        ]);
        $app['config']->set('services.mailgazelle.token', 'tes_test');
        $app['config']->set('mailgazelle', [
            'token' => null,
            'base_url' => 'https://mailgazelle.test/api/v1',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }
}
