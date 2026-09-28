<?php

declare(strict_types=1);

namespace MailGazelle\Laravel\Tests;

final class ExistingMailerConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.mailers.mailgazelle', [
            'transport' => 'log',
            'token' => 'tes_keep',
        ]);
    }

    public function test_an_existing_mailer_definition_is_left_in_place(): void
    {
        $this->assertSame('log', config('mail.mailers.mailgazelle.transport'));
        $this->assertSame('tes_keep', config('mail.mailers.mailgazelle.token'));
    }
}
