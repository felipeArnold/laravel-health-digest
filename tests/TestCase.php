<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Tests;

use FelipeArnold\HealthDigest\HealthDigest;
use FelipeArnold\HealthDigest\HealthDigestServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        HealthDigest::resolveTenantUsing(null);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'America/Sao_Paulo');
        date_default_timezone_set('America/Sao_Paulo');
    }

    protected function getPackageProviders($app): array
    {
        return [HealthDigestServiceProvider::class];
    }
}
