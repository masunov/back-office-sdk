<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Laravel;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Laravel\Events\OutboxMessageFailed;
use Mnd\BackOffice\Laravel\Events\OutboxMessageSent;
use Mnd\BackOffice\Laravel\Facades\BackOffice;
use Mnd\BackOffice\Laravel\Outbox\OutboxMessage;
use Mnd\BackOffice\Requests\FinanceRequests;
use Psr\Http\Message\RequestInterface;

/**
 * The outbox (back-office ROADMAP.md, 2.7, 6 stage 2): a mark of payment reaches the service after it is back.
 */
class OutboxTest extends TestCase
{
    private function pushPayment(string $sourceId = '7'): OutboxMessage
    {
        return DB::transaction(
            fn () => BackOffice::outbox()->push(
                FinanceRequests::createOperation('entry_fee', '1500.00', '2026-10-10', 'stage_entry_fee', $sourceId),
                tenant: 'org-1',
                actor: new Actor('5', 'Иван')
            )
        );
    }

    private function pushUnmark(string $sourceId = '7'): OutboxMessage
    {
        return DB::transaction(
            fn () => BackOffice::outbox()->push(FinanceRequests::reverseBySource('stage_entry_fee', $sourceId), 'org-1')
        );
    }

    public function testMessageIsSentAfterCommit(): void
    {
        Event::fake([OutboxMessageSent::class]);
        $this->mock->append(self::jsonResponse(201, ['data' => self::operationData()]));

        $message = $this->pushPayment()->refresh();

        $this->assertTrue($message->isSent());
        $this->assertSame(1, $message->attempts);
        $this->assertSame('org-1', $this->sentRequest()->getHeaderLine('X-Tenant'));
        $this->assertSame('Иван', $this->sentRequest()->getHeaderLine('X-Actor-Name'));
        $this->assertSame($message->request['idempotency_key'], $this->sentRequest()->getHeaderLine('Idempotency-Key'));
        Event::assertDispatched(
            OutboxMessageSent::class,
            fn (OutboxMessageSent $e): bool => $e->response['data']['id'] === self::operationData()['id']
        );
    }

    public function testRolledBackChangeSendsNothing(): void
    {
        $this->assertThrows(
            fn () => DB::transaction(
                function (): void {
                    BackOffice::outbox()->push(FinanceRequests::createOperation('entry_fee', '1', '2026-10-10'), 'org-1');

                    throw new \RuntimeException('the change failed');
                }
            ),
            \RuntimeException::class
        );

        // The mark and its message are gone together.

        $this->assertCount(0, $this->history);
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function testMessageWaitsForServiceAndGoesWithSameKey(): void
    {
        $this->mock->append(new ConnectException('Connection refused', new Request('POST', '/')));

        $message = $this->pushPayment()->refresh();

        $this->assertSame(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('Connection refused', (string) $message->last_error);
        $this->assertTrue($message->next_attempt_at->isFuture());

        // The service is back; the scheduler runs after the delay.
        $this->mock->append(self::jsonResponse(201, ['data' => self::operationData()]));
        $this->travelTo($message->next_attempt_at->copy()->addSecond());
        $this->artisan('back-office:outbox-send')->assertSuccessful();

        $this->assertTrue($message->refresh()->isSent());
        $this->assertSame(
            $this->sentRequest(0)->getHeaderLine('Idempotency-Key'),
            $this->sentRequest(1)->getHeaderLine('Idempotency-Key')
        );
    }

    public function testReversalNeverOvertakesItsOperation(): void
    {
        $this->mock->append(self::jsonResponse(503, null));
        $payment = $this->pushPayment()->refresh();
        $unmark  = $this->pushUnmark()->refresh();

        // The operation failed to go; its reversal waited without trying.
        $this->assertCount(1, $this->history);
        $this->assertSame(0, $unmark->attempts);

        $this->travelTo(Carbon::now()->addHour());
        $this->mock->append(
            self::jsonResponse(201, ['data' => self::operationData()]),
            self::jsonResponse(201, ['data' => self::operationData(['kind' => 'reversal', 'amount' => '-1500.00'])])
        );
        $this->artisan('back-office:outbox-send')->assertSuccessful();

        $this->assertTrue($payment->refresh()->isSent());
        $this->assertTrue($unmark->refresh()->isSent());
        $this->assertSame('/v1/finance/operations', $this->sentRequest(1)->getUri()->getPath());
        $this->assertSame('/v1/finance/operations/reverse', $this->sentRequest(2)->getUri()->getPath());
    }

    public function testOtherStreamsDoNotWait(): void
    {
        $this->mock->append(self::jsonResponse(503, null), self::jsonResponse(201, ['data' => self::operationData()]));

        $blocked = $this->pushPayment('7')->refresh();
        $other   = $this->pushPayment('8')->refresh();

        $this->assertFalse($blocked->isSent());
        $this->assertTrue($other->isSent());
    }

    public function testRefusalFailsMessageAndHoldsItsStreamUntilRetried(): void
    {
        Event::fake([OutboxMessageFailed::class]);
        $this->mock->append(self::apiError(422, 'unknown_category'));

        $payment = $this->pushPayment()->refresh();
        $unmark  = $this->pushUnmark()->refresh();

        $this->assertSame(OutboxMessage::STATUS_FAILED, $payment->status);
        $this->assertSame(422, $payment->last_status);
        $this->assertStringStartsWith('unknown_category', (string) $payment->last_error);
        $this->assertSame(0, $unmark->attempts);
        Event::assertDispatched(OutboxMessageFailed::class);

        $this->artisan('back-office:outbox-stuck')->expectsOutputToContain('unknown_category')->assertSuccessful();

        // The category was added in the service; a person sends the message again.
        $this->mock->append(
            self::jsonResponse(201, ['data' => self::operationData()]),
            self::jsonResponse(201, ['data' => self::operationData(['kind' => 'reversal'])])
        );
        $this->artisan('back-office:outbox-retry', ['id' => [$payment->id]])->assertSuccessful();
        $this->travelTo(Carbon::now()->addHour());
        $this->artisan('back-office:outbox-send')->assertSuccessful();

        $this->assertTrue($payment->refresh()->isSent());
        $this->assertTrue($unmark->refresh()->isSent());
        $this->artisan('back-office:outbox-stuck')->expectsOutput('Nothing is stuck');
    }

    /**
     * The service keeps its answer to an Idempotency-Key (back-office EnsureIdempotency): a retry with the same key gets
     * the same refusal back, whatever was fixed since. A manual retry must come with a new key.
     */
    public function testManualRetryIsNotAnsweredWithStoredRefusal(): void
    {
        $answers  = [];
        $category = false;
        $service  = function (RequestInterface $request) use (&$answers, &$category): Response {
            $key = $request->getHeaderLine('Idempotency-Key');

            return $answers[$key] ??= $category
                ? self::jsonResponse(201, ['data' => self::operationData()])
                : self::apiError(422, 'unknown_category');
        };
        $this->mock->append($service, $service);

        $payment = $this->pushPayment()->refresh();
        $this->assertSame(OutboxMessage::STATUS_FAILED, $payment->status);

        // The category is added in the service, a person retries the message.
        $category = true;
        $this->artisan('back-office:outbox-retry', ['id' => [$payment->id]])->assertSuccessful();
        $this->artisan('back-office:outbox-send')->assertSuccessful();

        $this->assertTrue($payment->refresh()->isSent());
        $this->assertNotSame(
            $this->sentRequest(0)->getHeaderLine('Idempotency-Key'),
            $this->sentRequest(1)->getHeaderLine('Idempotency-Key')
        );
    }

    public function testManualRetryGivesRetriableRefusalNewAttempts(): void
    {
        config(['back-office.outbox.max_attempts' => 2]);
        $this->mock->append(
            self::apiError(409, 'operation_not_found'),
            self::apiError(409, 'operation_not_found'),
            self::apiError(409, 'operation_not_found')
        );

        $unmark = $this->pushUnmark()->refresh();
        $this->travelTo(Carbon::now()->addHour());
        $this->artisan('back-office:outbox-send');
        $this->assertSame(OutboxMessage::STATUS_FAILED, $unmark->refresh()->status);

        $this->artisan('back-office:outbox-retry', ['--all' => true])->assertSuccessful();
        $this->artisan('back-office:outbox-send');

        // One failure after the retry is a retry later again, not the end.
        $this->assertSame(OutboxMessage::STATUS_PENDING, $unmark->refresh()->status);
        $this->assertSame(1, $unmark->attempts);
    }

    public function testRetriableRefusalIsRetriedThenFails(): void
    {
        config(['back-office.outbox.max_attempts' => 2]);
        $this->mock->append(self::apiError(409, 'operation_not_found'), self::apiError(409, 'operation_not_found'));

        $unmark = $this->pushUnmark()->refresh();
        $this->assertSame(OutboxMessage::STATUS_PENDING, $unmark->status);

        $this->travelTo(Carbon::now()->addHour());
        $this->artisan('back-office:outbox-send');

        $this->assertSame(OutboxMessage::STATUS_FAILED, $unmark->refresh()->status);
        $this->assertSame(2, $unmark->attempts);
    }

    public function testRefusedKeyIsRetriedWithoutLimit(): void
    {
        config(['back-office.outbox.max_attempts' => 1]);
        $this->mock->append(self::apiError(401, 'unauthenticated'));

        $message = $this->pushPayment()->refresh();

        $this->assertSame(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertSame(401, $message->last_status);
    }

    public function testBackoffGrowsAndIsCapped(): void
    {
        config(['back-office.outbox.backoff_base' => 10, 'back-office.outbox.backoff_max' => 25]);
        $this->mock->append(self::jsonResponse(503, null), self::jsonResponse(503, null), self::jsonResponse(503, null));

        $message = $this->pushPayment()->refresh();
        $this->assertEqualsWithDelta(10, Carbon::now()->diffInSeconds($message->next_attempt_at), 1);

        $this->travelTo($message->next_attempt_at);
        $this->artisan('back-office:outbox-send');
        $this->assertEqualsWithDelta(20, Carbon::now()->diffInSeconds($message->refresh()->next_attempt_at), 1);

        $this->travelTo($message->next_attempt_at);
        $this->artisan('back-office:outbox-send');
        $this->assertEqualsWithDelta(25, Carbon::now()->diffInSeconds($message->refresh()->next_attempt_at), 1);
    }

    public function testOpenCircuitStopsTheBatch(): void
    {
        config(['back-office.outbox.send_after_commit' => false, 'back-office.circuit_breaker.threshold' => 1]);
        $this->app->forgetInstance(\Mnd\BackOffice\BackOfficeClient::class);
        $this->mock->append(self::jsonResponse(503, null));

        $this->pushPayment('1');
        $this->pushPayment('2');
        $this->pushPayment('3');
        $this->artisan('back-office:outbox-send')->expectsOutput('Sent: 0, retry later: 1, failed: 0, waiting: 1');

        $this->assertCount(1, $this->history);
    }

    public function testSenderIsScheduledEveryMinute(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'back-office:outbox-send'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }
}
