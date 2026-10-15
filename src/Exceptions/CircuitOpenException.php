<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Exceptions;

/**
 * Too many failures in a row: requests are not sent for a while, so a dead service does not slow the project down.
 */
class CircuitOpenException extends ServiceUnavailableException
{
}
