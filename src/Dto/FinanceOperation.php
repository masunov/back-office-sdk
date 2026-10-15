<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A fact of income or expense. Also the `data` of the webhooks `finance.operation.created` and
 * `finance.operation.reversed`. Amounts are strings with two decimals, a reversal's is negative.
 */
final class FinanceOperation
{
    public function __construct(
        public readonly string $id,
        public readonly string $direction,
        public readonly string $kind,
        public readonly string $category,
        public readonly string $amount,
        public readonly string $operationDate,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?ExternalRef $externalRef,
        public readonly ?string $counterparty,
        public readonly ?ExternalRef $counterpartyRef,
        public readonly ?string $comment,
        public readonly Actor $actor,
        public readonly ?string $reversalOf,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['direction'],
            $data['kind'],
            $data['category'],
            $data['amount'],
            $data['operation_date'],
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            isset($data['external_ref']) ? ExternalRef::fromArray($data['external_ref']) : null,
            $data['counterparty'] ?? null,
            isset($data['counterparty_ref']) ? ExternalRef::fromArray($data['counterparty_ref']) : null,
            $data['comment'] ?? null,
            new Actor($data['actor']['id'] ?? null, $data['actor']['name'] ?? null),
            $data['reversal_of'] ?? null,
            $data['created_at']
        );
    }

    public function isReversal(): bool
    {
        return $this->kind === 'reversal';
    }
}
