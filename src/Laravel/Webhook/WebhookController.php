<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Webhook;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mnd\BackOffice\Exceptions\BackOfficeException;
use Mnd\BackOffice\Laravel\Events\WebhookReceived;
use Mnd\BackOffice\Webhook\WebhookVerifier;

/**
 * Receives the events of back-office: checks the signature, drops an event already received (by `event_id`) and
 * dispatches WebhookReceived. The id is written in the same transaction as the listeners run: if a listener fails,
 * the event is not marked received, the service gets 500 and sends it again.
 */
class WebhookController
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $event = $this->verifier->verify($request->header(WebhookVerifier::HEADER), $request->getContent());
        } catch (BackOfficeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 401);
        }

        $isNew = $this->db->transaction(
            function () use ($event): bool {
                $inserted = $this->db->table('back_office_webhook_events')->insertOrIgnore(
                    ['event_id' => $event->eventId, 'type' => $event->type, 'received_at' => Carbon::now()]
                );

                if ($inserted === 0) {
                    return false;
                }

                $this->events->dispatch(new WebhookReceived($event));

                return true;
            }
        );

        return new JsonResponse(['received' => true, 'duplicate' => ! $isNew]);
    }
}
