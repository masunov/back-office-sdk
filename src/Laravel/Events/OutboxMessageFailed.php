<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Events;

use Mnd\BackOffice\Exceptions\BackOfficeException;
use Mnd\BackOffice\Laravel\Outbox\OutboxMessage;

/**
 * The service refused a message for good, or a retriable refusal lasted too long: it is stuck and holds its stream
 * until a person retries it (`back-office:outbox-retry`).
 */
class OutboxMessageFailed
{
    public function __construct(
        public readonly OutboxMessage $message,
        public readonly BackOfficeException $exception,
    ) {
    }
}
