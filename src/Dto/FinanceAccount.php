<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A money account of the tenant — cash, a card, a bank account (BO-FIN-007). `balance` is in the currency of the
 * account, `baseCost` is what it cost in the currency of the tenant, `averageRate` is the one over the other (12
 * digits, null for an empty account). Also the `data` of the webhooks `finance.account.created` and `.updated`.
 */
final class FinanceAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $kind,
        public readonly string $currency,
        public readonly string $balance,
        public readonly string $baseCost,
        public readonly ?string $averageRate,
        public readonly ?string $comment,
        public readonly bool $archived,
        public readonly ?string $archivedAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['kind'],
            $data['currency'],
            $data['balance'],
            $data['base_cost'],
            $data['average_rate'] ?? null,
            $data['comment'] ?? null,
            (bool) ($data['archived'] ?? false),
            $data['archived_at'] ?? null,
            $data['updated_at'],
        );
    }
}
