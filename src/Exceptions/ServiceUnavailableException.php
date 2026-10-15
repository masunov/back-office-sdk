<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Exceptions;

/**
 * The service did not answer, answered 5xx, or the circuit breaker is open. Nothing is known about the request:
 * a write must be retried with the same Idempotency-Key, which is what the outbox does.
 */
class ServiceUnavailableException extends BackOfficeException
{
}
