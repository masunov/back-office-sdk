<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Events;

use Mnd\BackOffice\Laravel\Outbox\OutboxMessage;

/**
 * The service accepted a message; `response` is its answer, e.g. the operation with the id the service gave it.
 */
class OutboxMessageSent
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly OutboxMessage $message,
        public readonly array $response,
    ) {
    }
}
