<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A line of the schedule of an obligation, with what is paid of it: payments go to the lines by due date, the
 * undated last. `dueOn` null — the date is not known yet; `externalRef` — the line's own object (a stage of a
 * sponsor contract), null — the obligation's.
 */
final class ObligationLine
{
    public function __construct(
        public readonly string $key,
        public readonly string $amount,
        public readonly ?string $dueOn,
        public readonly ?string $label,
        public readonly string $paid,
        public readonly string $remaining,
        public readonly bool $overdue,
        public readonly ?ExternalRef $externalRef = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['amount'],
            $data['due_on'] ?? null,
            $data['label'] ?? null,
            $data['paid'] ?? '0.00',
            $data['remaining'] ?? $data['amount'],
            (bool) ($data['overdue'] ?? false),
            isset($data['external_ref']) ? ExternalRef::fromArray($data['external_ref']) : null
        );
    }
}
