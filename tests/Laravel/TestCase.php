<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Laravel;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Laravel\BackOfficeServiceProvider;
use Mnd\BackOffice\Tests\Unit\MocksHttp;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use MocksHttp;

    protected const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(BackOfficeServiceProvider::HTTP_CLIENT, $this->mockHttp());
        $this->app->forgetInstance(BackOfficeClient::class);
    }

    protected function getPackageProviders($app): array
    {
        return [BackOfficeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // sqlite by default; DB_CONNECTION=pgsql (and DB_HOST, DB_DATABASE…) runs the suite on PostgreSQL, where the
        // migration of v1.0 failed.
        $app['config']->set('database.default', getenv('DB_CONNECTION') === 'pgsql' ? 'pgsql' : 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('back-office.url', 'https://bo.test');
        $app['config']->set('back-office.key', 'bo_key');
        $app['config']->set('back-office.webhook.secret', self::SECRET);
        $app['config']->set('back-office.read_retry_ms', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
