<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Outbox;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Exceptions\CircuitOpenException;
use Mnd\BackOffice\Exceptions\ServiceUnavailableException;
use Mnd\BackOffice\Laravel\Events\OutboxMessageFailed;
use Mnd\BackOffice\Laravel\Events\OutboxMessageSent;

/**
 * Sends the due messages of the outbox (back-office ROADMAP.md, 2.7). Nothing is lost:
 *  - the service is unavailable, the key is refused (401/403) — retried without limit, the change waits for the
 *    service or for the configuration to be fixed;
 *  - a refusal that may pass (`409 operation_not_found` of a reversal) — retried up to `max_attempts`;
 *  - any other refusal (422, `409 idempotency_conflict`) — the message fails and waits for a person.
 * A message of a stream waits until the earlier ones of its stream are sent.
 */
class OutboxSender
{
    /**
     * @param  array<string, int>  $config  back-office.outbox
     */
    public function __construct(
        private readonly BackOfficeClient $client,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {
    }

    /**
     * @return array{sent: int, retried: int, failed: int, waiting: int}
     */
    public function sendDue(?int $limit = null): array
    {
        $result = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'waiting' => 0];

        foreach (OutboxMessage::dueIds($limit ?? $this->config['batch']) as $id) {
            $message = OutboxMessage::claim($id, $this->config['lease']);

            if ($message === null) {
                continue;
            }

            if ($message->hasUnsentPredecessor()) {
                $message->release(Carbon::now()->addSeconds($this->config['backoff_base']));
                $result['waiting']++;

                continue;
            }

            try {
                $response = $this->client->send($message->outgoingRequest());
            } catch (CircuitOpenException) {
                // The service is down for everyone: the rest of the batch waits too.
                $message->release();
                $result['waiting']++;

                break;
            } catch (ServiceUnavailableException $e) {
                $message->scheduleRetry($this->retryAt($message), null, $e->getMessage());
                $result['retried']++;

                continue;
            } catch (ApiErrorException $e) {
                $result[$this->refused($message, $e)]++;

                continue;
            }

            $message->markSent($response);
            $this->events->dispatch(new OutboxMessageSent($message, $response));
            $result['sent']++;
        }

        return $result;
    }

    /**
     * @return 'retried'|'failed'
     */
    private function refused(OutboxMessage $message, ApiErrorException $e): string
    {
        $error = $e->errorCode() . ': ' . $e->getMessage();

        if (\in_array($e->status(), [401, 403], true)) {
            $message->scheduleRetry($this->retryAt($message), $e->status(), $error);

            return 'retried';
        }

        if ($e->isRetriable() && $message->attempts + 1 < $this->config['max_attempts']) {
            $message->scheduleRetry($this->retryAt($message), $e->status(), $error);

            return 'retried';
        }

        $message->markFailed($e->status(), $error);
        $this->events->dispatch(new OutboxMessageFailed($message, $e));

        return 'failed';
    }

    private function retryAt(OutboxMessage $message): Carbon
    {
        $delay = min($this->config['backoff_base'] * 2 ** $message->attempts, $this->config['backoff_max']);

        return Carbon::now()->addSeconds((int) $delay);
    }
}
