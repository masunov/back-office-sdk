<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Exceptions;

use Throwable;

/**
 * The service refused the request: `{"message", "error": {"code", "message", ...details}}` (docs/openapi.yaml).
 */
class ApiErrorException extends BackOfficeException
{
    /**
     * Refusals that may be gone on a retry: the operation of a reversal or the issue of a return has not arrived yet,
     * the same key is being processed right now, the ref was opened again.
     */
    private const RETRIABLE_CODES = [
        'operation_not_found',
        'issue_not_found',
        'idempotency_in_progress',
        'too_many_requests',
    ];

    /**
     * Retriable only as a `409`: the reservation of an issue or of a cancel by source has not arrived yet. The same
     * code with `404` is an unknown id, it never comes.
     */
    private const RETRIABLE_CONFLICTS = [
        'reservation_not_found',
    ];

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Whether repeating the same request later may succeed. A `409 operation_not_found` of a reversal is,
     * a `409 idempotency_conflict` or a `422` never are.
     */
    public function isRetriable(): bool
    {
        return \in_array($this->errorCode, self::RETRIABLE_CODES, true)
            || ($this->status === 409 && \in_array($this->errorCode, self::RETRIABLE_CONFLICTS, true))
            || $this->status === 429;
    }
}
