<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A payment of an obligation: `payment` adds to what is paid, `refund` takes from it, `reversal` undoes one record.
 * The amount is always positive. Also the `data` of the webhooks `finance.payment.created` and
 * `finance.payment.reversed`: then, and in the answer of a creation or a reversal, `obligationTotals` and
 * `obligationPaymentStatus` are what its obligation is now; in a list they are empty. `movement` — the money of a
 * payment written with an account (of a reversal — the reversal of that movement); null without one.
 */
final class FinancePayment
{
    /**
     * @param  array<string, string|bool>  $obligationTotals
     */
    public function __construct(
        public readonly string $id,
        public readonly string $obligationId,
        public readonly string $direction,
        public readonly string $kind,
        public readonly string $method,
        public readonly string $amount,
        public readonly string $paidOn,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?string $reversalOf,
        public readonly ?string $comment,
        public readonly Actor $actor,
        public readonly string $createdAt,
        public readonly array $obligationTotals = [],
        public readonly ?string $obligationPaymentStatus = null,
        public readonly ?FinanceMoneyMovement $movement = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['obligation_id'],
            $data['direction'],
            $data['kind'],
            $data['method'] ?? 'money',
            $data['amount'],
            $data['paid_on'],
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            $data['reversal_of'] ?? null,
            $data['comment'] ?? null,
            new Actor($data['actor']['id'] ?? null, $data['actor']['name'] ?? null),
            $data['created_at'],
            $data['obligation']['totals'] ?? [],
            $data['obligation']['payment_status'] ?? null,
            isset($data['movement']) ? FinanceMoneyMovement::fromArray($data['movement']) : null
        );
    }

    public function isReversal(): bool
    {
        return $this->kind === 'reversal';
    }
}
