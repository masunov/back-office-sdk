<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Laravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Mnd\BackOffice\Laravel\Events\WebhookReceived;
use Mnd\BackOffice\Tests\Unit\WebhookVerifierTest;

class WebhookTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::backOfficeWebhook('webhooks/back-office');
    }

    private function deliver(string $body, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/webhooks/back-office',
            server: [
                'HTTP_X_SIGNATURE' => $signature ?? WebhookVerifierTest::sign($body, time(), self::SECRET),
                'CONTENT_TYPE'     => 'application/json',
            ],
            content: $body
        );
    }

    public function testSignedEventIsDispatchedOnce(): void
    {
        Event::fake([WebhookReceived::class]);
        $body = WebhookVerifierTest::body();

        $this->deliver($body)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
        $this->deliver($body)->assertOk()->assertJson(['duplicate' => true]);

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatched(
            WebhookReceived::class,
            fn (WebhookReceived $e): bool => $e->event->type === 'finance.operation.created'
                && $e->event->tenantExternalId === 'org-1'
        );
    }

    public function testUnsignedEventIsRefused(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->deliver(WebhookVerifierTest::body(), 't=1,v1=00')->assertUnauthorized();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testFailedListenerLetsServiceRetry(): void
    {
        $calls = 0;
        Event::listen(
            WebhookReceived::class,
            function () use (&$calls): void {
                if (++$calls === 1) {
                    throw new \RuntimeException('the project failed to handle it');
                }
            }
        );
        $this->withoutExceptionHandling();
        $body = WebhookVerifierTest::body();

        // The service gets 500 and sends the event again.
        $this->assertThrows(fn () => $this->deliver($body), \RuntimeException::class);

        $this->assertSame(0, DB::table('back_office_webhook_events')->count());

        $this->deliver($body)->assertOk()->assertJson(['duplicate' => false]);
        $this->assertSame(2, $calls);
    }
}
