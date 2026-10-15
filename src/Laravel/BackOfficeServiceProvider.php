<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Http\CircuitBreaker;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Laravel\Commands\InstallCommand;
use Mnd\BackOffice\Laravel\Commands\OutboxRetryCommand;
use Mnd\BackOffice\Laravel\Commands\OutboxSendCommand;
use Mnd\BackOffice\Laravel\Commands\OutboxStuckCommand;
use Mnd\BackOffice\Laravel\Outbox\OutboxSender;
use Mnd\BackOffice\Laravel\Webhook\WebhookController;
use Mnd\BackOffice\Webhook\WebhookVerifier;

/**
 * Connects the SDK to a Laravel project: `composer require mnd/back-office-sdk` and `php artisan back-office:install`.
 */
class BackOfficeServiceProvider extends ServiceProvider
{
    public const HTTP_CLIENT = 'back-office.http';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/back-office.php', 'back-office');

        $this->app->bind(self::HTTP_CLIENT, fn (): ClientInterface => new Client());

        $this->app->singleton(
            BackOfficeClient::class,
            function (Application $app): BackOfficeClient {
                $config = $app['config']['back-office'];

                return new BackOfficeClient(
                    new Transport(
                        $app->make(self::HTTP_CLIENT),
                        (string) $config['url'],
                        (string) $config['key'],
                        new CircuitBreaker(
                            Cache::store($config['circuit_breaker']['cache_store']),
                            (int) $config['circuit_breaker']['threshold'],
                            (int) $config['circuit_breaker']['cooldown']
                        ),
                        $config['read_retry_ms'],
                        (float) $config['timeout'],
                        (float) $config['connect_timeout']
                    )
                );
            }
        );

        $this->app->singleton(
            WebhookVerifier::class,
            fn (Application $app): WebhookVerifier => new WebhookVerifier(
                (string) $app['config']['back-office.webhook.secret'],
                (int) $app['config']['back-office.webhook.tolerance']
            )
        );

        $this->app->bind(
            OutboxSender::class,
            fn (Application $app): OutboxSender => new OutboxSender(
                $app->make(BackOfficeClient::class),
                $app['events'],
                $app['config']['back-office.outbox']
            )
        );

        $this->app->singleton(BackOfficeManager::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/back-office.php' => config_path('back-office.php')], 'back-office-config');
        $this->publishes(
            [__DIR__ . '/../../database/migrations' => database_path('migrations')],
            'back-office-migrations'
        );

        Route::macro(
            'backOfficeWebhook',
            function (string $uri = 'webhooks/back-office'): RouteDefinition {
                // The service signs its requests; a CSRF token it cannot have.
                $csrf = array_filter(
                    [
                        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
                        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                        'App\Http\Middleware\VerifyCsrfToken',
                    ],
                    'class_exists'
                );

                return Route::post($uri, WebhookController::class)
                    ->withoutMiddleware(array_values($csrf))
                    ->name('back-office.webhook');
            }
        );

        if ($this->app->runningInConsole()) {
            $this->commands(
                [InstallCommand::class, OutboxSendCommand::class, OutboxStuckCommand::class, OutboxRetryCommand::class]
            );
        }

        // After the Schedule is resolved, not on boot: Laravel 10 creates the Schedule again after the providers boot.
        $this->callAfterResolving(
            Schedule::class,
            function (Schedule $schedule): void {
                if (config('back-office.outbox.schedule')) {
                    $schedule->command('back-office:outbox-send')->everyMinute()->withoutOverlapping();
                }
            }
        );
    }
}
