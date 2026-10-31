<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * An obligation to pay a counterparty (`direction` expense) or to be paid by one (income), with its schedule.
 * Not an operation: it changes no fact. `status` is the fulfilment, `paymentStatus` is computed from payments.
 * `totals`: agreed, recognized, paid, remaining, debt, unrecognized, overpaid, due (strings), over_recognized (bool).
 */
final class FinanceObligation
{
    /**
     * @param  array<string, string|bool>  $totals
     * @param  list<ObligationLine>  $schedule
     * @param  list<array{title: string|null, url: string}>  $documents
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $direction,
        public readonly ExternalRef $counterparty,
        public readonly ?ExternalRef $externalRef,
        public readonly string $amount,
        public readonly string $agreedOn,
        public readonly string $status,
        public readonly ?string $fulfilledAmount,
        public readonly string $paymentStatus,
        public readonly bool $overdue,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly array $totals,
        public readonly array $schedule,
        public readonly ?string $comment,
        public readonly array $documents,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['category'],
            $data['direction'],
            ExternalRef::fromArray($data['counterparty']),
            isset($data['external_ref']) ? ExternalRef::fromArray($data['external_ref']) : null,
            $data['amount'],
            $data['agreed_on'],
            $data['status'],
            $data['fulfilled_amount'] ?? null,
            $data['payment_status'],
            (bool) $data['overdue'],
            $data['source_type'],
            $data['source_id'],
            $data['totals'] ?? [],
            array_map(fn (array $line): ObligationLine => ObligationLine::fromArray($line), $data['schedule'] ?? []),
            $data['comment'] ?? null,
            array_values($data['documents'] ?? [])
        );
    }

    /**
     * What is left to pay: the agreed amount less what is paid; of a cancelled one — the unpaid recognized part.
     */
    public function remaining(): string
    {
        return (string) ($this->totals['remaining'] ?? $this->amount);
    }

    /**
     * What should have been paid by today and is not: the unpaid part of the lines due today or earlier.
     */
    public function due(): string
    {
        return (string) ($this->totals['due'] ?? '0.00');
    }

    /**
     * The key the outbox addresses it by, before it knows the id.
     */
    public function sourceKey(): string
    {
        return $this->sourceType . ':' . $this->sourceId;
    }
}
